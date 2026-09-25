<?php
declare(strict_types=1);

namespace Anchor\Courses\Database;

use Anchor\Courses\Domain\Credit;
use Anchor\Courses\Support\Clock;
use Anchor\Courses\Support\Json;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * All SQL for wp_anchor_courses_ce_credits.
 *
 * `credit_type` is free text (no CHECK/enum on the column, and nothing here
 * asserts one) - unlike the enrollment/progress/quiz-attempt tables, this
 * schema has no enum-ish column to guard.
 *
 * UNIQUE (user_id, course_id) is the no-duplicate-credits guarantee (brief
 * rule 7 / D13); insert_ignore() leans on it rather than on a read-then-write
 * race (brief 26).
 */
final class CreditRepository {

	use RepositoryGuards;

	/** Non-identity columns a caller may change after insert. */
	private const UPDATABLE = [ 'certificate_id', 'metadata' ];

	private static function table(): string {
		return Migrations::table( 'ce_credits' );
	}

	public static function find( int $user_id, int $course_id ): ?Credit {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE user_id = %d AND course_id = %d',
				$user_id,
				$course_id
			),
			ARRAY_A
		);
		return \is_array( $row ) ? Credit::from_row( $row ) : null;
	}

	public static function find_by_id( int $id ): ?Credit {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A );
		return \is_array( $row ) ? Credit::from_row( $row ) : null;
	}

	/**
	 * Create the row unless (user_id, course_id) already exists; either way,
	 * return the row that is now in the table.
	 *
	 * @param array $data user_id, course_id, credits, credit_type, awarded_at,
	 *                    and optionally expires_at, certificate_id, metadata.
	 */
	public static function insert_ignore( array $data ): ?Credit {
		global $wpdb;

		$now    = Clock::now();
		$values = [
			'user_id'        => (int) ( $data['user_id'] ?? 0 ),
			'course_id'      => (int) ( $data['course_id'] ?? 0 ),
			'credits'        => (float) ( $data['credits'] ?? 0 ),
			'credit_type'    => (string) ( $data['credit_type'] ?? '' ),
			'awarded_at'     => (string) ( $data['awarded_at'] ?? $now ),
			'expires_at'     => $data['expires_at'] ?? null,
			'certificate_id' => (int) ( $data['certificate_id'] ?? 0 ),
			'metadata'       => Json::encode( (array) ( $data['metadata'] ?? [] ) ),
			'created_at'     => $now,
		];

		// INSERT IGNORE, not $wpdb->insert(): the unique key is the concurrency
		// guard, and a duplicate must be a no-op rather than a warning.
		$wpdb->query( self::insert_sql( self::table(), $values, true ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		return self::find( $values['user_id'], $values['course_id'] );
	}

	/**
	 * @param array $data Column => value, limited to self::UPDATABLE (anything
	 *                    else is dropped). `metadata` may be an array.
	 */
	public static function update( int $id, array $data ): ?Credit {
		global $wpdb;

		$data = self::filter_updatable( $data, self::UPDATABLE );
		if ( isset( $data['metadata'] ) && \is_array( $data['metadata'] ) ) {
			$data['metadata'] = Json::encode( $data['metadata'] );
		}
		if ( [] === $data ) {
			return self::find_by_id( $id );
		}

		$wpdb->update( self::table(), $data, [ 'id' => $id ] );

		return self::find_by_id( $id );
	}

	public static function attach_certificate( int $credit_id, int $certificate_id ): ?Credit {
		return self::update( $credit_id, [ 'certificate_id' => $certificate_id ] );
	}

	/** @return Credit[] */
	public static function for_user( int $user_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE user_id = %d ORDER BY awarded_at DESC', $user_id ),
			ARRAY_A
		);
		return \array_map( [ Credit::class, 'from_row' ], (array) $rows );
	}

	/** @return Credit[] */
	public static function for_course( int $course_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE course_id = %d ORDER BY awarded_at DESC', $course_id ),
			ARRAY_A
		);
		return \array_map( [ Credit::class, 'from_row' ], (array) $rows );
	}

	public static function total_for_user( int $user_id ): float {
		global $wpdb;
		return (float) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COALESCE(SUM(credits), 0) FROM ' . self::table() . ' WHERE user_id = %d', $user_id )
		);
	}
}
