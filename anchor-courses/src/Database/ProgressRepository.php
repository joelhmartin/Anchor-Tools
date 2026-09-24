<?php
declare(strict_types=1);

namespace Anchor\Courses\Database;

use Anchor\Courses\Domain\Progress;
use Anchor\Courses\Support\Clock;
use InvalidArgumentException;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * All SQL for wp_anchor_courses_progress.
 *
 * upsert() is ON DUPLICATE KEY UPDATE against
 * UNIQUE (user_id, course_id, item_id, item_type), so two simultaneous
 * "complete lesson" calls still leave exactly one row (brief 26).
 *
 * Per the Task 13 review ruling applied here from the start: the UPDATE half of
 * upsert() only ever assigns an allowlist of non-identity columns (never
 * user_id/course_id/item_id/item_type), and the enum-ish `status` and
 * `item_type` columns are validated against Progress's own constants before
 * anything is written.
 */
final class ProgressRepository {

	private static function table(): string {
		return Migrations::table( 'progress' );
	}

	public static function find( int $user_id, int $course_id, int $item_id, string $item_type ): ?Progress {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE user_id = %d AND course_id = %d AND item_id = %d AND item_type = %s',
				$user_id,
				$course_id,
				$item_id,
				$item_type
			),
			ARRAY_A
		);
		return \is_array( $row ) ? Progress::from_row( $row ) : null;
	}

	/**
	 * Insert or update the single row for (user, course, item, type).
	 *
	 * Columns absent from $data keep their stored value on update; only the keys
	 * actually supplied are overwritten. `status` and `item_type` must be one of
	 * Progress::STATUSES / Progress::ITEM_TYPES.
	 *
	 * @throws InvalidArgumentException When status or item_type is not a known value.
	 */
	public static function upsert( array $data ): ?Progress {
		global $wpdb;

		$now       = Clock::now();
		$user_id   = (int) ( $data['user_id'] ?? 0 );
		$course_id = (int) ( $data['course_id'] ?? 0 );
		$item_id   = (int) ( $data['item_id'] ?? 0 );
		$item_type = (string) ( $data['item_type'] ?? 'lesson' );
		$status    = (string) ( $data['status'] ?? 'not_started' );

		if ( ! \in_array( $item_type, Progress::ITEM_TYPES, true ) ) {
			throw new InvalidArgumentException( "Unknown item_type '{$item_type}'." );
		}
		if ( ! \in_array( $status, Progress::STATUSES, true ) ) {
			throw new InvalidArgumentException( "Unknown status '{$status}'." );
		}

		$row = [
			'user_id'            => $user_id,
			'course_id'          => $course_id,
			'item_id'            => $item_id,
			'item_type'          => $item_type,
			'status'             => $status,
			'progress_percent'   => (float) ( $data['progress_percent'] ?? 0 ),
			'started_at'         => $data['started_at'] ?? null,
			'completed_at'       => $data['completed_at'] ?? null,
			'last_viewed_at'     => $data['last_viewed_at'] ?? null,
			'time_spent_seconds' => (int) ( $data['time_spent_seconds'] ?? 0 ),
			'metadata'           => (string) \wp_json_encode( (array) ( $data['metadata'] ?? [] ) ),
			'created_at'         => $now,
			'updated_at'         => $now,
		];

		// Allowlist: only non-identity columns the caller actually supplied are
		// what the UPDATE half overwrites. user_id/course_id/item_id/item_type
		// are the unique key itself and must never appear here.
		$updatable = \array_values(
			\array_intersect(
				[ 'status', 'progress_percent', 'started_at', 'completed_at', 'last_viewed_at', 'time_spent_seconds', 'metadata' ],
				\array_keys( $data )
			)
		);
		$updatable[] = 'updated_at';

		$columns      = \implode( ', ', \array_keys( $row ) );
		$placeholders = \implode( ', ', \array_fill( 0, \count( $row ), '%s' ) );
		$assignments  = \implode( ', ', \array_map( static fn( string $c ): string => "{$c} = VALUES({$c})", $updatable ) );

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO " . self::table() . " ({$columns}) VALUES ({$placeholders}) ON DUPLICATE KEY UPDATE {$assignments}", // phpcs:ignore WordPress.DB.PreparedSQL
				\array_values( $row )
			)
		);

		return self::find( $user_id, $course_id, $item_id, $item_type );
	}

	/** @return array<string,Progress> Keyed "{item_type}:{item_id}". */
	public static function for_course( int $user_id, int $course_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE user_id = %d AND course_id = %d',
				$user_id,
				$course_id
			),
			ARRAY_A
		);

		$out = [];
		foreach ( (array) $rows as $row ) {
			$progress                = Progress::from_row( $row );
			$out[ $progress->key() ] = $progress;
		}
		return $out;
	}

	/** @return string[] "{item_type}:{item_id}" for completed items only. */
	public static function completed_keys( int $user_id, int $course_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT item_type, item_id FROM ' . self::table() . "
				 WHERE user_id = %d AND course_id = %d AND status = 'completed'
				 ORDER BY item_type ASC, item_id ASC",
				$user_id,
				$course_id
			),
			ARRAY_A
		);

		return \array_map(
			static fn( array $r ): string => $r['item_type'] . ':' . (int) $r['item_id'],
			(array) $rows
		);
	}

	public static function count_completed( int $user_id, int $course_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . self::table() . " WHERE user_id = %d AND course_id = %d AND status = 'completed'",
				$user_id,
				$course_id
			)
		);
	}

	public static function delete_for_course( int $user_id, int $course_id ): int {
		global $wpdb;
		return (int) $wpdb->delete( self::table(), [ 'user_id' => $user_id, 'course_id' => $course_id ], [ '%d', '%d' ] );
	}

	/** Latest updated_at for this learner in this course, or ''. */
	public static function last_activity( int $user_id, int $course_id ): string {
		global $wpdb;
		return (string) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT MAX(updated_at) FROM ' . self::table() . ' WHERE user_id = %d AND course_id = %d',
				$user_id,
				$course_id
			)
		);
	}
}
