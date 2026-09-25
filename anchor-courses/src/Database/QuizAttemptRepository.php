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
 * create() allocates attempt_number with a single INSERT ... SELECT MAX()+1
 * against UNIQUE (user_id, quiz_id, attempt_number). Two simultaneous starts
 * therefore produce one row and one rejected duplicate rather than two
 * attempts numbered the same (brief 26) - create() returns null on that
 * collision (Task 24 review, ruling R1) rather than silently resolving it;
 * the caller (QuizService::start_attempt()) is the one place that decides
 * what a null means, by calling open_attempt() for the winner's row.
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
	 *                    interpret it).
	 * @return QuizAttempt|null Null on a genuine unique-key collision (ruling
	 *                          R1) - the caller resolves it via open_attempt(),
	 *                          never this method.
	 */
	public static function create( array $data ): ?QuizAttempt {
		global $wpdb;

		$now       = Clock::now();
		$user_id   = (int) ( $data['user_id'] ?? 0 );
		$course_id = (int) ( $data['course_id'] ?? 0 );
		$quiz_id   = (int) ( $data['quiz_id'] ?? 0 );
		$metadata  = Json::encode( (array) ( $data['metadata'] ?? [] ) );
		$table     = self::table();

		// One statement: the next number is computed inside the INSERT, so two
		// concurrent starts cannot both read the same MAX(). IGNORE turns the
		// expected unique-key collision (ruling R1) into a silent no-op instead
		// of a raised wpdb error - insert_id then stays 0 and create() returns
		// null exactly as it would for any other rejected duplicate.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table}
				 (user_id, course_id, quiz_id, attempt_number, status, points_possible, started_at, answers, grading_data, metadata, created_at, updated_at)
				 SELECT %d, %d, %d, COALESCE(MAX(a.attempt_number), 0) + 1, 'in_progress', %f, %s, '[]', '[]', %s, %s, %s
				 FROM {$table} a WHERE a.user_id = %d AND a.quiz_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$user_id,
				$course_id,
				$quiz_id,
				(float) ( $data['points_possible'] ?? 0 ),
				$now,
				$metadata,
				$now,
				$now,
				$user_id,
				$quiz_id
			)
		);

		$id = (int) $wpdb->insert_id;
		return $id > 0 ? self::find( $id ) : null;
	}

	/**
	 * @param array $data Column => value, limited to self::UPDATABLE (anything else
	 *                    is dropped). `answers` / `grading_data` may be arrays.
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

		$wpdb->update( self::table(), $data, [ 'id' => $id ] );

		return self::find( $id );
	}

	public static function open_attempt( int $user_id, int $quiz_id ): ?QuizAttempt {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . " WHERE user_id = %d AND quiz_id = %d AND status = 'in_progress'
				 ORDER BY attempt_number DESC LIMIT 1",
				$user_id,
				$quiz_id
			),
			ARRAY_A
		);
		return \is_array( $row ) ? QuizAttempt::from_row( $row ) : null;
	}

	/** Attempts that count against max_attempts (abandoned rows do not). */
	public static function count_for_quiz( int $user_id, int $quiz_id ): int {
		global $wpdb;

		$placeholders = \implode( ', ', \array_fill( 0, \count( self::COUNTED_STATUSES ), '%s' ) );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . self::table() . " WHERE user_id = %d AND quiz_id = %d AND status IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL
				\array_merge( [ $user_id, $quiz_id ], self::COUNTED_STATUSES )
			)
		);
	}

	public static function last_for_quiz( int $user_id, int $quiz_id ): ?QuizAttempt {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE user_id = %d AND quiz_id = %d ORDER BY attempt_number DESC LIMIT 1',
				$user_id,
				$quiz_id
			),
			ARRAY_A
		);
		return \is_array( $row ) ? QuizAttempt::from_row( $row ) : null;
	}

	public static function best_for_quiz( int $user_id, int $quiz_id ): ?QuizAttempt {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . " WHERE user_id = %d AND quiz_id = %d AND status = 'graded'
				 ORDER BY score DESC, attempt_number DESC LIMIT 1",
				$user_id,
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
}
