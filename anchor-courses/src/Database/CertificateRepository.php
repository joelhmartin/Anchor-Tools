<?php
declare(strict_types=1);

namespace Anchor\Courses\Database;

use Anchor\Courses\Domain\Certificate;
use Anchor\Courses\Support\Clock;
use Anchor\Courses\Support\Json;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * All SQL for wp_anchor_courses_certificates.
 *
 * `certificate_number` is `NOT NULL UNIQUE` but the documented format embeds
 * the row's own AUTO_INCREMENT id, which does not exist until after the
 * insert. insert_ignore() writes a collision-proof `PENDING-{uniqid}`
 * placeholder and set_number() rewrites it once the service knows the id
 * (design spec 4 / deviation D14) - two writes to one row, not two
 * concurrent writers: the real concurrency guard is the UNIQUE (user_id,
 * course_id) key insert_ignore() leans on, exactly as EnrollmentRepository
 * does (brief 26) - only one process's insert can ever create the row for a
 * given learner/course pair, so the number it gets is never contested.
 */
final class CertificateRepository {

	use RepositoryGuards;

	private static function table(): string {
		return Migrations::table( 'certificates' );
	}

	public static function find( int $user_id, int $course_id ): ?Certificate {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE user_id = %d AND course_id = %d',
				$user_id,
				$course_id
			),
			ARRAY_A
		);
		return \is_array( $row ) ? Certificate::from_row( $row ) : null;
	}

	public static function find_by_id( int $id ): ?Certificate {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A );
		return \is_array( $row ) ? Certificate::from_row( $row ) : null;
	}

	public static function find_by_token( string $token ): ?Certificate {
		if ( '' === $token ) {
			return null;
		}
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE verification_token = %s', $token ),
			ARRAY_A
		);
		return \is_array( $row ) ? Certificate::from_row( $row ) : null;
	}

	/**
	 * Create the row unless (user_id, course_id) already exists; either way,
	 * return the row that is now in the table. certificate_number is always
	 * written as a PENDING placeholder here - the caller (CertificateService)
	 * assigns the real number via set_number() once it has the id.
	 *
	 * @param array $data user_id, course_id, verification_token, and optionally
	 *                    issued_at, expires_at, metadata.
	 */
	public static function insert_ignore( array $data ): ?Certificate {
		global $wpdb;

		$now = Clock::now();

		$values = [
			'user_id'            => (int) ( $data['user_id'] ?? 0 ),
			'course_id'          => (int) ( $data['course_id'] ?? 0 ),
			// Unique by construction (uniqid is not repeatable); replaced with the
			// real number immediately by the service that just created this row.
			'certificate_number' => 'PENDING-' . \uniqid( '', true ),
			'issued_at'          => (string) ( $data['issued_at'] ?? $now ),
			'expires_at'         => $data['expires_at'] ?? null,
			'file_path'          => '',
			'verification_token' => (string) ( $data['verification_token'] ?? '' ),
			'metadata'           => Json::encode( (array) ( $data['metadata'] ?? [] ) ),
			'created_at'         => $now,
		];

		// INSERT IGNORE, not $wpdb->insert(): UNIQUE (user_id, course_id) is the
		// concurrency guard, and a duplicate must be a no-op, not a warning.
		$wpdb->query( self::insert_sql( self::table(), $values, true ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		return self::find( $values['user_id'], $values['course_id'] );
	}

	/** Rewrite the placeholder certificate_number once the row's id is known. */
	public static function set_number( int $id, string $number ): ?Certificate {
		global $wpdb;
		$wpdb->update( self::table(), [ 'certificate_number' => $number ], [ 'id' => $id ], [ '%s' ], [ '%d' ] );
		return self::find_by_id( $id );
	}

	/** @return Certificate[] */
	public static function for_user( int $user_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE user_id = %d ORDER BY issued_at DESC', $user_id ),
			ARRAY_A
		);
		return \array_map( [ Certificate::class, 'from_row' ], (array) $rows );
	}

	/**
	 * The certificate rows for a page of learners in one course, keyed by
	 * user_id - one query for the whole page rather than one find() per row
	 * (Task 31 N+1 ruling). A user id with no certificate is simply absent.
	 *
	 * @param int[] $user_ids
	 * @return array<int,Certificate>
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
			$certificate                  = Certificate::from_row( $row );
			$out[ $certificate->user_id ] = $certificate;
		}
		return $out;
	}
}
