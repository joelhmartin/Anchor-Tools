<?php
declare(strict_types=1);

namespace Anchor\Courses\Database;

use Anchor\Courses\Domain\Enrollment;
use Anchor\Courses\Support\Clock;
use Anchor\Courses\Support\Json;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * All SQL for wp_anchor_courses_enrollments.
 *
 * Nothing outside this class writes the table. insert_ignore() leans on the
 * UNIQUE (user_id, course_id) key rather than a read-then-write race (brief 26).
 */
final class EnrollmentRepository {

	use RepositoryGuards;

	private static function table(): string {
		return Migrations::table( 'enrollments' );
	}

	public static function find( int $user_id, int $course_id ): ?Enrollment {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE user_id = %d AND course_id = %d',
				$user_id,
				$course_id
			),
			ARRAY_A
		);
		return \is_array( $row ) ? Enrollment::from_row( $row ) : null;
	}

	public static function find_by_id( int $id ): ?Enrollment {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ),
			ARRAY_A
		);
		return \is_array( $row ) ? Enrollment::from_row( $row ) : null;
	}

	/**
	 * Create the row unless (user_id, course_id) already exists; either way,
	 * return the row that is now in the table.
	 *
	 * @param array $data user_id, course_id, status, enrolled_at, and optionally
	 *                    started_at, completed_at, expires_at, source, source_id, metadata.
	 */
	public static function insert_ignore( array $data ): ?Enrollment {
		global $wpdb;

		$now    = Clock::now();
		$status = (string) ( $data['status'] ?? 'enrolled' );
		self::assert_enum( $status, Enrollment::STATUSES, 'enrollment status' );

		$values = [
			'user_id'      => (int) ( $data['user_id'] ?? 0 ),
			'course_id'    => (int) ( $data['course_id'] ?? 0 ),
			'status'       => $status,
			'enrolled_at'  => (string) ( $data['enrolled_at'] ?? $now ),
			'started_at'   => $data['started_at'] ?? null,
			'completed_at' => $data['completed_at'] ?? null,
			'expires_at'   => $data['expires_at'] ?? null,
			'source'       => (string) ( $data['source'] ?? '' ),
			'source_id'    => (string) ( $data['source_id'] ?? '' ),
			'metadata'     => Json::encode( (array) ( $data['metadata'] ?? [] ) ),
			'created_at'   => $now,
			'updated_at'   => $now,
		];

		// INSERT IGNORE, not $wpdb->insert(): the unique key is the concurrency
		// guard, and a duplicate must be a no-op rather than a warning.
		$wpdb->query( self::insert_sql( self::table(), $values, true ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		return self::find( $values['user_id'], $values['course_id'] );
	}

	/** Columns a caller may change after insert; identity columns are never among them. */
	private const UPDATABLE = [ 'status', 'started_at', 'completed_at', 'expires_at', 'source', 'source_id', 'metadata' ];

	/**
	 * @param array $data Column => value, limited to self::UPDATABLE (anything else
	 *                    is dropped). `metadata` may be an array.
	 * @throws \InvalidArgumentException When `status` is not one of Enrollment::STATUSES.
	 */
	public static function update( int $id, array $data ): ?Enrollment {
		global $wpdb;

		$data = self::filter_updatable( $data, self::UPDATABLE );
		if ( isset( $data['status'] ) ) {
			self::assert_enum( (string) $data['status'], Enrollment::STATUSES, 'enrollment status' );
		}
		if ( isset( $data['metadata'] ) && \is_array( $data['metadata'] ) ) {
			$data['metadata'] = Json::encode( $data['metadata'] );
		}
		$data['updated_at'] = Clock::now();

		$wpdb->update( self::table(), $data, [ 'id' => $id ] );

		return self::find_by_id( $id );
	}

	/** @return Enrollment[] */
	public static function for_user( int $user_id, array $statuses = [] ): array {
		global $wpdb;

		$sql    = 'SELECT * FROM ' . self::table() . ' WHERE user_id = %d';
		$params = [ $user_id ];

		if ( [] !== $statuses ) {
			$sql   .= ' AND status IN (' . \implode( ', ', \array_fill( 0, \count( $statuses ), '%s' ) ) . ')';
			$params = \array_merge( $params, \array_values( $statuses ) );
		}

		$rows = $wpdb->get_results( $wpdb->prepare( $sql . ' ORDER BY enrolled_at DESC', $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

		return \array_map( [ Enrollment::class, 'from_row' ], (array) $rows );
	}

	/** @return Enrollment[] */
	public static function for_course( int $course_id, array $statuses = [], int $limit = 100, int $offset = 0 ): array {
		global $wpdb;

		$sql    = 'SELECT * FROM ' . self::table() . ' WHERE course_id = %d';
		$params = [ $course_id ];

		if ( [] !== $statuses ) {
			$sql   .= ' AND status IN (' . \implode( ', ', \array_fill( 0, \count( $statuses ), '%s' ) ) . ')';
			$params = \array_merge( $params, \array_values( $statuses ) );
		}

		$sql     .= ' ORDER BY enrolled_at DESC LIMIT %d OFFSET %d';
		$params[] = \max( 1, $limit );
		$params[] = \max( 0, $offset );

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

		return \array_map( [ Enrollment::class, 'from_row' ], (array) $rows );
	}

	public static function count_for_course( int $course_id, array $statuses = [] ): int {
		global $wpdb;

		$sql    = 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE course_id = %d';
		$params = [ $course_id ];

		if ( [] !== $statuses ) {
			$sql   .= ' AND status IN (' . \implode( ', ', \array_fill( 0, \count( $statuses ), '%s' ) ) . ')';
			$params = \array_merge( $params, \array_values( $statuses ) );
		}

		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Flip active enrolments whose expires_at has passed. @return int rows changed.
	 *
	 * The zero-date exclusion covers rows written before nulls were stored as
	 * SQL NULL: those meant "never expires", and the zero-date sorts before
	 * every real datetime.
	 */
	public static function expire_due( string $now ): int {
		global $wpdb;

		return (int) $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . "
				 SET status = 'expired', updated_at = %s
				 WHERE status IN ('enrolled', 'in_progress')
				   AND expires_at IS NOT NULL
				   AND expires_at <> %s
				   AND expires_at <= %s",
				$now,
				Clock::ZERO_DATE,
				$now
			)
		);
	}
}
