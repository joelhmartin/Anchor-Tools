<?php
/**
 * Anchor Courses - certificate table access (brief 7.5, 13, deviation D14).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Database\CertificateRepository;
use Anchor\Courses\Database\Migrations;
use Anchor\Courses\Domain\Certificate;
use Anchor\Courses\Support\Uuid;

/** @group courses */
class Test_Courses_Certificate_Repo extends Anchor_Courses_TestCase {

	private int $user;
	private int $course;

	public function set_up() {
		parent::set_up();
		$this->user   = $this->make_learner();
		$this->course = $this->make_course();
	}

	private function insert( array $over = [] ): ?Certificate {
		return CertificateRepository::insert_ignore(
			array_merge(
				[ 'user_id' => $this->user, 'course_id' => $this->course, 'verification_token' => Uuid::v4() ],
				$over
			)
		);
	}

	private function is_null_in_db( int $id, string $column ): bool {
		global $wpdb;
		return '1' === (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT {$column} IS NULL FROM " . Migrations::table( 'certificates' ) . ' WHERE id = %d', $id ) // phpcs:ignore WordPress.DB.PreparedSQL
		);
	}

	public function test_insert_ignore_writes_a_pending_placeholder_number() {
		$certificate = $this->insert();

		$this->assertStringStartsWith( 'PENDING-', $certificate->certificate_number );
	}

	public function test_set_number_rewrites_the_placeholder_and_persists() {
		$certificate = $this->insert();

		$numbered = CertificateRepository::set_number( $certificate->id, 'AC-2026-00000042' );

		$this->assertSame( 'AC-2026-00000042', $numbered->certificate_number );
		$this->assertSame( 'AC-2026-00000042', CertificateRepository::find_by_id( $certificate->id )->certificate_number );
	}

	/**
	 * The UNIQUE (user_id, course_id) key - not a computed value like the quiz
	 * attempt table's MAX(attempt_number) - is what makes this race-free, so
	 * no `query` filter is needed to force the collision (contrast
	 * tests/test-courses-quiz-submit.php's start-collision test): calling
	 * insert_ignore() twice for the same pair IS the race, resolved to one row.
	 */
	public function test_a_second_insert_for_the_same_pair_resolves_to_the_first_row() {
		$winner = $this->insert( [ 'verification_token' => 'token-a' ] );
		$loser  = $this->insert( [ 'verification_token' => 'token-b' ] );

		$this->assertSame( $winner->id, $loser->id );
		$this->assertSame( $winner->certificate_number, $loser->certificate_number );
		$this->assertSame( 'token-a', $loser->verification_token, 'The loser must not overwrite the winner\'s token.' );
		$this->assertCount( 1, CertificateRepository::for_user( $this->user ) );
	}

	/**
	 * 1.2.0 (pre-gate cleanup round): verification_token is now UNIQUE.
	 * insert_ignore() uses INSERT IGNORE (see the class docblock), so a
	 * collision on ANY unique key - not only (user_id, course_id) - must be a
	 * clean no-op: find() afterwards is keyed on the pair that was never
	 * actually written, so it returns null instead of the colliding row.
	 * This is the exact primitive CertificateService::issue() calls; its
	 * `if ( ! $certificate instanceof Certificate ) { return null; }` guard
	 * (Services/CertificateService.php) turns this null into a plain failed
	 * issue, never a fatal.
	 */
	public function test_a_verification_token_collision_across_different_pairs_fails_cleanly() {
		$other_user   = $this->make_learner();
		$other_course = $this->make_course();

		$first  = $this->insert( [ 'verification_token' => 'shared-token' ] );
		$second = CertificateRepository::insert_ignore(
			[ 'user_id' => $other_user, 'course_id' => $other_course, 'verification_token' => 'shared-token' ]
		);

		$this->assertInstanceOf( Certificate::class, $first );
		$this->assertNull( $second, 'A token collision across different pairs must fail cleanly, not create a row.' );
		$this->assertNull( CertificateRepository::find( $other_user, $other_course ) );
	}

	public function test_expires_at_is_sql_null_when_omitted() {
		$certificate = $this->insert();

		$this->assertTrue( $this->is_null_in_db( $certificate->id, 'expires_at' ) );
		$this->assertNull( $certificate->expires_at );
	}

	public function test_expires_at_round_trips_a_real_value() {
		$certificate = $this->insert( [ 'expires_at' => '2030-01-01 00:00:00' ] );

		$this->assertFalse( $this->is_null_in_db( $certificate->id, 'expires_at' ) );
		$this->assertSame( '2030-01-01 00:00:00', CertificateRepository::find_by_id( $certificate->id )->expires_at );
	}

	public function test_a_legacy_zero_date_expires_at_reads_as_null() {
		global $wpdb;
		$certificate = $this->insert();
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . Migrations::table( 'certificates' ) . " SET expires_at = '0000-00-00 00:00:00' WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$certificate->id
			)
		);

		$this->assertNull( CertificateRepository::find_by_id( $certificate->id )->expires_at );
	}

	public function test_metadata_round_trips_as_an_array() {
		$certificate = $this->insert( [ 'metadata' => [ 'template' => 'gold' ] ] );

		$this->assertSame( [ 'template' => 'gold' ], CertificateRepository::find_by_id( $certificate->id )->metadata );
	}

	public function test_metadata_defaults_to_an_empty_array() {
		$certificate = $this->insert();

		$this->assertSame( [], $certificate->metadata );
	}

	public function test_ids_and_foreign_keys_round_trip_as_ints() {
		$certificate = $this->insert();

		$this->assertIsInt( $certificate->id );
		$this->assertGreaterThan( 0, $certificate->id );
		$this->assertSame( $this->user, $certificate->user_id );
		$this->assertSame( $this->course, $certificate->course_id );
	}

	public function test_find_by_id_and_find_by_token() {
		$certificate = $this->insert( [ 'verification_token' => 'find-me-token' ] );

		$this->assertSame( $certificate->id, CertificateRepository::find_by_id( $certificate->id )->id );
		$this->assertSame( $certificate->id, CertificateRepository::find_by_token( 'find-me-token' )->id );
		$this->assertNull( CertificateRepository::find_by_token( 'no-such-token' ) );
		$this->assertNull( CertificateRepository::find_by_token( '' ) );
	}

	public function test_find_by_id_returns_null_for_an_unknown_id() {
		$this->assertNull( CertificateRepository::find_by_id( 999999 ) );
	}

	public function test_find_returns_null_when_no_row_exists_for_the_pair() {
		$this->assertNull( CertificateRepository::find( $this->user, $this->make_course() ) );
	}

	/** Task 31 N+1 ruling: one query for a whole page of user ids, keyed by user_id. */
	public function test_for_users_in_course_batches_a_page_of_learners() {
		$withA   = $this->user;
		$withB   = $this->make_learner();
		$without = $this->make_learner();

		$a = $this->insert( [ 'user_id' => $withA, 'verification_token' => Uuid::v4() ] );
		$b = CertificateRepository::insert_ignore(
			[ 'user_id' => $withB, 'course_id' => $this->course, 'verification_token' => Uuid::v4() ]
		);
		// A certificate in a DIFFERENT course must not leak into this course's map.
		CertificateRepository::insert_ignore(
			[ 'user_id' => $withA, 'course_id' => $this->make_course(), 'verification_token' => Uuid::v4() ]
		);

		$map = CertificateRepository::for_users_in_course( [ $withA, $withB, $without ], $this->course );

		$this->assertCount( 2, $map );
		$this->assertSame( $a->certificate_number, $map[ $withA ]->certificate_number );
		$this->assertSame( $b->certificate_number, $map[ $withB ]->certificate_number );
		$this->assertArrayNotHasKey( $without, $map );
	}

	public function test_for_users_in_course_with_no_user_ids_makes_no_query() {
		$this->assertSame( [], CertificateRepository::for_users_in_course( [], $this->course ) );
	}
}
