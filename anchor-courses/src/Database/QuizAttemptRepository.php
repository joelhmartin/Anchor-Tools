<?php
declare(strict_types=1);

namespace Anchor\Courses\Database;

use Anchor\Courses\Domain\QuizAttempt;
use Anchor\Courses\Support\Clock;
use Anchor\Courses\Support\Json;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * All SQL for wp_anchor_courses_quiz_attempts.
 *
 * Every lifecycle read is scoped to (user, quiz, COURSE) (audit F05): a quiz
 * may be shared by several courses, and an attempt belongs to the course it
 * was started in - its open/closed state, its number, the allowance it uses,
 * its retry delay and its best score never leak into another course.
 *
 * create() allocates attempt_number with a single INSERT ... SELECT MAX()+1
 * against UNIQUE (user_id, course_id, quiz_id, attempt_number) (Migrations
 * 1.3.0). Two simultaneous starts therefore produce one row and one rejected
 * duplicate rather than two attempts numbered the same (brief 26) - create()
 * returns null on that collision (Task 24 review, ruling R1) rather than
 * silently resolving it; the caller (QuizService::start_attempt()) is the one
 * place that decides what a null means, by calling open_attempt() for the
 * winner's row.
 */
final class QuizAttemptRepository {

	use RepositoryGuards;

	/** Attempts that count toward the max_attempts allowance ("every non-abandoned attempt"). */
	private const COUNTED_STATUSES = [ 'in_progress', 'submitted', 'graded', 'expired' ];

	/** Columns a caller may change after insert; identity columns are never among them. */
	private const UPDATABLE = [
		'status', 'score', 'points_earned', 'points_possible', 'passed',
		'submitted_at', 'duration_seconds', 'answers', 'grading_data',
	];

	private static function table(): string {
		return Migrations::table( 'quiz_attempts' );
	}

	public static function find( int $id ): ?QuizAttempt {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ),
			ARRAY_A
		);
		return \is_array( $row ) ? QuizAttempt::from_row( $row ) : null;
	}

	/**
	 * @param array $data user_id, course_id, quiz_id, points_possible, metadata
	 *                    (the pinned time_limit_seconds/on_timer_expiry, brief
	 *                    T24 ruling R3 - stored as-is, this repository does not
	 *                    interpret it), and max_attempts (0 = unlimited).
	 * @return QuizAttempt|null Null when nothing was inserted: a unique-key
	 *                          collision (ruling R1), or - the database-level
	 *                          guard (audit F07) - an attempt is already open
	 *                          for this (user, course, quiz), or max_attempts
	 *                          counted attempts already exist. The caller
	 *                          resolves it via open_attempt(), never this method.
	 */
	public static function create( array $data ): ?QuizAttempt {
		global $wpdb;

		$now       = Clock::now();
		$user_id   = (int) ( $data['user_id'] ?? 0 );
		$course_id = (int) ( $data['course_id'] ?? 0 );
		$quiz_id   = (int) ( $data['quiz_id'] ?? 0 );
		$metadata  = Json::encode( (array) ( $data['metadata'] ?? [] ) );
		$table     = self::table();

		$max          = \max( 0, (int) ( $data['max_attempts'] ?? 0 ) );
		$placeholders = \implode( ', ', \array_fill( 0, \count( self::COUNTED_STATUSES ), '%s' ) );

		// One statement: the next number is computed inside the INSERT, so two
		// concurrent starts cannot both read the same MAX(). IGNORE turns the
		// expected unique-key collision (ruling R1) into a silent no-op instead
		// of a raised wpdb error - insert_id then stays 0 and create() returns
		// null exactly as it would for any other rejected duplicate.
		//
		// The HAVING clause is the database-level half of audit F07: the row is
		// only inserted while no attempt is open for this (user, course, quiz)
		// and fewer than max_attempts counted attempts exist - re-checked by
		// the INSERT itself, not by a read some time before it. A unique number
		// alone never stopped a second open attempt (it just got number 2).
		// QuizService::start_attempt() also serialises the whole start under
		// a named lock; this holds even for a caller that bypasses it.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table}
				 (user_id, course_id, quiz_id, attempt_number, status, points_possible, started_at, answers, grading_data, metadata, created_at, updated_at)
				 SELECT %d, %d, %d, COALESCE(MAX(a.attempt_number), 0) + 1, 'in_progress', %f, %s, '[]', '[]', %s, %s, %s
				 FROM {$table} a WHERE a.user_id = %d AND a.course_id = %d AND a.quiz_id = %d
				 HAVING NOT EXISTS (
				     SELECT 1 FROM {$table} o WHERE o.user_id = %d AND o.course_id = %d AND o.quiz_id = %d AND o.status = 'in_progress'
				 )
				 AND ( %d = 0 OR (
				     SELECT COUNT(*) FROM {$table} c WHERE c.user_id = %d AND c.course_id = %d AND c.quiz_id = %d AND c.status IN ({$placeholders})
				 ) < %d )", // phpcs:ignore WordPress.DB.PreparedSQL
				\array_merge(
					[
						$user_id,
						$course_id,
						$quiz_id,
						(float) ( $data['points_possible'] ?? 0 ),
						$now,
						$metadata,
						$now,
						$now,
						$user_id,
						$course_id,
						$quiz_id,
						$user_id,
						$course_id,
						$quiz_id,
						$max,
						$user_id,
						$course_id,
						$quiz_id,
					],
					self::COUNTED_STATUSES,
					[ $max ]
				)
			)
		);

		$id = (int) $wpdb->insert_id;
		return $id > 0 ? self::find( $id ) : null;
	}

	/**
	 * @param array $data Column => value, limited to self::UPDATABLE (anything else
	 *                    is dropped). `answers` / `grading_data` may be arrays.
	 * @return QuizAttempt|null The row as it now stands, or NULL when the
	 *                          database reported an error (audit F03):
	 *                          `$wpdb->update()` returning false is a failed
	 *                          write, and handing back a fresh read of the
	 *                          unchanged row would make it look like success.
	 *                          A 0-row "nothing changed" update is a success.
	 * @throws \InvalidArgumentException When `status` is not one of QuizAttempt::STATUSES.
	 */
	public static function update( int $id, array $data ): ?QuizAttempt {
		global $wpdb;

		$data = self::filter_updatable( $data, self::UPDATABLE );

		if ( isset( $data['status'] ) ) {
			self::assert_enum( (string) $data['status'], QuizAttempt::STATUSES, 'quiz attempt status' );
		}

		foreach ( [ 'answers', 'grading_data' ] as $json_column ) {
			if ( isset( $data[ $json_column ] ) && \is_array( $data[ $json_column ] ) ) {
				$data[ $json_column ] = Json::encode( $data[ $json_column ] );
			}
		}
		$data['updated_at'] = Clock::now();

		if ( false === $wpdb->update( self::table(), $data, [ 'id' => $id ] ) ) {
			return null;
		}

		return self::find( $id );
	}

	/**
	 * Write an open attempt's answer map IF nobody else wrote it since it was
	 * read (audit F06) - a compare-and-swap on `revision`, conditional on the
	 * attempt still being `in_progress`, in one statement:
	 *
	 *   UPDATE ... SET answers = %s, revision = revision + 1, updated_at = %s
	 *   WHERE id = %d AND status = 'in_progress' AND revision = %d
	 *
	 * The status condition is ON THE WRITE, so a save that passed its own
	 * open check before a submit claimed the attempt can no longer land after
	 * the grade.
	 *
	 * @param array $answers           The whole map to store.
	 * @param int   $expected_revision The revision the caller read.
	 * @return bool|null True = written; false = lost the race (revision moved
	 *                   or the attempt closed) - re-read and decide; null = a
	 *                   database error.
	 */
	public static function save_answers( int $id, array $answers, int $expected_revision ): ?bool {
		global $wpdb;

		$result = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . " SET answers = %s, revision = revision + 1, updated_at = %s WHERE id = %d AND status = 'in_progress' AND revision = %d", // phpcs:ignore WordPress.DB.PreparedSQL
				Json::encode( $answers ),
				Clock::now(),
				$id,
				$expected_revision
			)
		);

		if ( false === $result ) {
			return null;
		}
		return 1 === (int) $wpdb->rows_affected;
	}

	/**
	 * Re-open `submitted` claims older than $cutoff (audit F03).
	 *
	 * `submitted` only ever exists between QuizService::submit()'s atomic
	 * claim and its grading write; a row still there long after was orphaned
	 * by a process that died in between. Re-opening puts it back where the
	 * learner (or the timer sweep) can finish it - the stored answers are
	 * untouched. The claim time is `updated_at`, which transition() stamps.
	 *
	 * @param int $user_id 0 = every learner (the sweep); otherwise only theirs.
	 * @param int $quiz_id   0 = every quiz.
	 * @param int $course_id 0 = every course.
	 * @return int Attempts re-opened.
	 */
	public static function reopen_stale_submitted( string $cutoff, int $user_id = 0, int $quiz_id = 0, int $course_id = 0 ): int {
		global $wpdb;

		$sql    = 'UPDATE ' . self::table() . " SET status = 'in_progress', updated_at = %s WHERE status = 'submitted' AND updated_at < %s";
		$params = [ Clock::now(), $cutoff ];
		if ( $user_id > 0 ) {
			$sql     .= ' AND user_id = %d';
			$params[] = $user_id;
		}
		if ( $quiz_id > 0 ) {
			$sql     .= ' AND quiz_id = %d';
			$params[] = $quiz_id;
		}
		if ( $course_id > 0 ) {
			$sql     .= ' AND course_id = %d';
			$params[] = $course_id;
		}

		return (int) $wpdb->query( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Atomically move ONE attempt from $from to $to (final review: atomic
	 * submit). The guard is the WHERE clause, checked by the database via
	 * rows_affected - the same shape as EnrollmentRepository::complete():
	 * of two concurrent callers exactly one matches the row, so exactly one
	 * goes on to grade (or expire) it.
	 *
	 * @return bool True only for the caller whose UPDATE made the transition.
	 */
	public static function transition( int $id, string $from, string $to ): bool {
		global $wpdb;

		self::assert_enum( $to, QuizAttempt::STATUSES, 'quiz attempt status' );

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . ' SET status = %s, updated_at = %s WHERE id = %d AND status = %s', // phpcs:ignore WordPress.DB.PreparedSQL
				$to,
				Clock::now(),
				$id,
				$from
			)
		);

		return 1 === (int) $wpdb->rows_affected;
	}

	public static function open_attempt( int $user_id, int $quiz_id, int $course_id ): ?QuizAttempt {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . " WHERE user_id = %d AND course_id = %d AND quiz_id = %d AND status = 'in_progress'
				 ORDER BY attempt_number DESC LIMIT 1",
				$user_id,
				$course_id,
				$quiz_id
			),
			ARRAY_A
		);
		return \is_array( $row ) ? QuizAttempt::from_row( $row ) : null;
	}

	/** Attempts that count against max_attempts (abandoned rows do not). */
	public static function count_for_quiz( int $user_id, int $quiz_id, int $course_id ): int {
		global $wpdb;

		$placeholders = \implode( ', ', \array_fill( 0, \count( self::COUNTED_STATUSES ), '%s' ) );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . self::table() . " WHERE user_id = %d AND course_id = %d AND quiz_id = %d AND status IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL
				\array_merge( [ $user_id, $course_id, $quiz_id ], self::COUNTED_STATUSES )
			)
		);
	}

	/**
	 * Void every counted attempt a learner has in one course (admin reset,
	 * final review I6). `abandoned` is not in COUNTED_STATUSES, so the
	 * max_attempts allowance is fully restored; the rows stay as history.
	 *
	 * @return int|false Attempts voided, or `false` when the database
	 *                    reported a real error (Round 10, CodeRabbit Major,
	 *                    PR #32 finding 1) - mirrors `update()`'s F03
	 *                    null-on-error convention: `$wpdb->query()` returning
	 *                    `false` is a failed write, and casting it to `(int)`
	 *                    (the old shape here) would make it indistinguishable
	 *                    from a real "nothing to void" outcome. Zero attempts
	 *                    voided - the learner had none counted in this course -
	 *                    is a genuine success, never a failure.
	 */
	public static function abandon_for_course( int $user_id, int $course_id ): int|false {
		global $wpdb;

		$placeholders = \implode( ', ', \array_fill( 0, \count( self::COUNTED_STATUSES ), '%s' ) );

		$result = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . " SET status = 'abandoned', updated_at = %s WHERE user_id = %d AND course_id = %d AND status IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL
				\array_merge( [ Clock::now(), $user_id, $course_id ], self::COUNTED_STATUSES )
			)
		);
		return false === $result ? false : (int) $result;
	}

	public static function last_for_quiz( int $user_id, int $quiz_id, int $course_id ): ?QuizAttempt {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE user_id = %d AND course_id = %d AND quiz_id = %d ORDER BY attempt_number DESC LIMIT 1',
				$user_id,
				$course_id,
				$quiz_id
			),
			ARRAY_A
		);
		return \is_array( $row ) ? QuizAttempt::from_row( $row ) : null;
	}

	public static function best_for_quiz( int $user_id, int $quiz_id, int $course_id ): ?QuizAttempt {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . " WHERE user_id = %d AND course_id = %d AND quiz_id = %d AND status = 'graded'
				 ORDER BY score DESC, attempt_number DESC LIMIT 1",
				$user_id,
				$course_id,
				$quiz_id
			),
			ARRAY_A
		);
		return \is_array( $row ) ? QuizAttempt::from_row( $row ) : null;
	}

	/** @return QuizAttempt[] */
	public static function for_user_course( int $user_id, int $course_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE user_id = %d AND course_id = %d ORDER BY quiz_id ASC, attempt_number ASC',
				$user_id,
				$course_id
			),
			ARRAY_A
		);
		return \array_map( [ QuizAttempt::class, 'from_row' ], (array) $rows );
	}

	/**
	 * The highest recorded score per user across every quiz in one course, for
	 * a page of learners, keyed by user_id - one query for the whole page
	 * rather than scanning for_user_course() per row (Task 31 N+1 ruling).
	 * Same "any attempt with a recorded score" rule for_user_course() callers
	 * already use - no GRADED filter, since an attempt can carry a score
	 * before it is marked 'graded'. `abandoned` IS excluded (CodeRabbit PR
	 * #29): abandon_for_course() voids an attempt on an admin reset without
	 * clearing its score column, and a voided attempt must not still win
	 * "best score". A user id with no (non-abandoned) scored attempt is
	 * simply absent from the result.
	 *
	 * @param int[] $user_ids
	 * @return array<int,float>
	 */
	public static function best_scores_for_users_in_course( array $user_ids, int $course_id ): array {
		$user_ids = \array_values( \array_unique( \array_map( 'intval', $user_ids ) ) );
		if ( [] === $user_ids ) {
			return [];
		}

		global $wpdb;
		$placeholders = \implode( ', ', \array_fill( 0, \count( $user_ids ), '%d' ) );
		$rows         = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT user_id, MAX(score) AS best_score FROM ' . self::table()
				. " WHERE course_id = %d AND score IS NOT NULL AND status <> 'abandoned' AND user_id IN ({$placeholders}) GROUP BY user_id", // phpcs:ignore WordPress.DB.PreparedSQL
				\array_merge( [ $course_id ], $user_ids )
			),
			ARRAY_A
		);

		$out = [];
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['user_id'] ] = (float) $row['best_score'];
		}
		return $out;
	}
}
