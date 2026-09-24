<?php
declare(strict_types=1);

namespace Anchor\Courses\Database;

use Anchor\Courses\Domain\QuizAttempt;
use Anchor\Courses\Support\Clock;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * All SQL for wp_anchor_courses_quiz_attempts.
 *
 * create() allocates attempt_number with a single INSERT ... SELECT MAX()+1
 * against UNIQUE (user_id, quiz_id, attempt_number). Two simultaneous starts
 * therefore produce one row and one rejected duplicate rather than two
 * attempts numbered the same (brief 26).
 */
final class QuizAttemptRepository {

	/** Attempts that count toward the max_attempts allowance ("every non-abandoned attempt"). */
	private const COUNTED_STATUSES = [ 'in_progress', 'submitted', 'graded', 'expired' ];

	/** Columns a caller may change after insert; identity columns are never among them. */
	private const UPDATABLE = [
		'status', 'score', 'points_earned', 'points_possible', 'passed',
		'submitted_at', 'duration_seconds', 'answers', 'grading_data',
	];

	/**
	 * The status column has no CHECK constraint, so the repository is the last
	 * place an unknown value can be refused before it becomes a corrupt row
	 * (same rule EnrollmentRepository::assert_status() and
	 * ProgressRepository::upsert() apply to their own enum columns).
	 *
	 * @throws \InvalidArgumentException
	 */
	private static function assert_status( string $status ): void {
		if ( ! \in_array( $status, QuizAttempt::STATUSES, true ) ) {
			throw new \InvalidArgumentException( 'Unknown quiz attempt status: ' . $status );
		}
	}

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

	/** @param array $data user_id, course_id, quiz_id, points_possible. */
	public static function create( array $data ): ?QuizAttempt {
		global $wpdb;

		$now       = Clock::now();
		$user_id   = (int) ( $data['user_id'] ?? 0 );
		$course_id = (int) ( $data['course_id'] ?? 0 );
		$quiz_id   = (int) ( $data['quiz_id'] ?? 0 );
		$table     = self::table();

		// One statement: the next number is computed inside the INSERT, so two
		// concurrent starts cannot both read the same MAX().
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table}
				 (user_id, course_id, quiz_id, attempt_number, status, points_possible, started_at, answers, grading_data, created_at, updated_at)
				 SELECT %d, %d, %d, COALESCE(MAX(a.attempt_number), 0) + 1, 'in_progress', %f, %s, '[]', '[]', %s, %s
				 FROM {$table} a WHERE a.user_id = %d AND a.quiz_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$user_id,
				$course_id,
				$quiz_id,
				(float) ( $data['points_possible'] ?? 0 ),
				$now,
				$now,
				$now,
				$user_id,
				$quiz_id
			)
		);

		$id = (int) $wpdb->insert_id;
		return $id > 0 ? self::find( $id ) : self::open_attempt( $user_id, $quiz_id );
	}

	/**
	 * @param array $data Column => value, limited to self::UPDATABLE (anything else
	 *                    is dropped). `answers` / `grading_data` may be arrays.
	 * @throws \InvalidArgumentException When `status` is not one of QuizAttempt::STATUSES.
	 */
	public static function update( int $id, array $data ): ?QuizAttempt {
		global $wpdb;

		$data = \array_intersect_key( $data, \array_flip( self::UPDATABLE ) );

		if ( isset( $data['status'] ) ) {
			self::assert_status( (string) $data['status'] );
		}

		foreach ( [ 'answers', 'grading_data' ] as $json_column ) {
			if ( isset( $data[ $json_column ] ) && \is_array( $data[ $json_column ] ) ) {
				// JSON_PRESERVE_ZERO_FRACTION: grading_data stores point values (e.g. score
				// components) as floats. Without this flag an integral float like 1.0
				// encodes as "1" and decodes back as int, breaking the float type contract
				// from_row()/to_array() promise callers.
				$data[ $json_column ] = (string) \wp_json_encode( $data[ $json_column ], JSON_PRESERVE_ZERO_FRACTION );
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
