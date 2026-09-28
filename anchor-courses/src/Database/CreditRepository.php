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

	/**
	 * The credit rows for a page of learners in one course, keyed by user_id -
	 * one query for the whole page rather than one find() per row (Task 31 N+1
	 * ruling). A user id with no credit row is simply absent from the result.
	 *
	 * @param int[] $user_ids
	 * @return array<int,Credit>
	 */
	public static function for_users_in_course( array $user_ids, int $course_id ): array {
		$user_ids = \array_values( \array_unique( \array_map( 'intval', $user_ids ) ) );
		if ( [] === $user_ids ) {
			return [];
		}

		global $wpdb;
		$placeholders = \implode( ', ', \array_fill( 0, \count( $user_ids ), '%d' ) );
		$rows         = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . " WHERE course_id = %d AND user_id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL
				\array_merge( [ $course_id ], $user_ids )
			),
			ARRAY_A
		);

		$out = [];
		foreach ( (array) $rows as $row ) {
			$credit                  = Credit::from_row( $row );
			$out[ $credit->user_id ] = $credit;
		}
		return $out;
	}

	/**
	 * Every credit row, across every course or scoped to one, optionally
	 * bounded by `awarded_at` date - the query behind `GET
	 * /admin/reports/credits` (Task 34). Mirrors
	 * EnrollmentRepository::completions()'s shape: same reason (this class
	 * owns this table's SQL), same `$limit` used as the report's only bound
	 * since there is no page param.
	 *
	 * @return Credit[]
	 */
	public static function report( int $course_id = 0, string $from = '', string $to = '', int $limit = 1000 ): array {
		global $wpdb;

		[ $where, $params ] = self::report_where( $course_id, $from, $to );

		$sql      = 'SELECT * FROM ' . self::table() . $where . ' ORDER BY awarded_at DESC LIMIT %d';
		$params[] = \max( 1, $limit );

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

		return \array_map( [ Credit::class, 'from_row' ], (array) $rows );
	}

	/**
	 * Count and credit sum over EVERY row report() would match with no limit
	 * - same filters, one query - so a truncated report still states true
	 * totals.
	 *
	 * @return array{count:int,credits:float}
	 */
	public static function report_totals( int $course_id = 0, string $from = '', string $to = '' ): array {
		global $wpdb;

		[ $where, $params ] = self::report_where( $course_id, $from, $to );
		$sql = 'SELECT COUNT(*) AS n, COALESCE(SUM(credits), 0) AS total FROM ' . self::table() . $where;

		$row = [] === $params ? $wpdb->get_row( $sql, ARRAY_A ) : $wpdb->get_row( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

		return [
			'count'   => (int) ( $row['n'] ?? 0 ),
			'credits' => (float) ( $row['total'] ?? 0 ),
		];
	}

	/**
	 * The one WHERE clause report() and report_totals() share.
	 *
	 * @return array{0:string,1:array<int,int|string>}
	 */
	private static function report_where( int $course_id, string $from, string $to ): array {
		$conditions = [];
		$params     = [];

		if ( $course_id > 0 ) {
			$conditions[] = 'course_id = %d';
			$params[]     = $course_id;
		}
		if ( '' !== $from ) {
			$conditions[] = 'awarded_at >= %s';
			$params[]     = $from . ' 00:00:00';
		}
		if ( '' !== $to ) {
			$conditions[] = 'awarded_at <= %s';
			$params[]     = $to . ' 23:59:59';
		}

		return [ [] === $conditions ? '' : ' WHERE ' . \implode( ' AND ', $conditions ), $params ];
	}

	public static function total_for_user( int $user_id ): float {
		global $wpdb;
		return (float) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COALESCE(SUM(credits), 0) FROM ' . self::table() . ' WHERE user_id = %d', $user_id )
		);
	}
}
