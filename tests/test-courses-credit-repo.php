<?php
/**
 * Anchor Courses - CE credit table access (brief 7.4, 12, 26).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Database\CreditRepository;
use Anchor\Courses\Database\Migrations;
use Anchor\Courses\Domain\Credit;

/** @group courses */
class Test_Courses_Credit_Repo extends Anchor_Courses_TestCase {

	private function row( int $user_id, int $course_id, array $over = [] ): array {
		return array_merge(
			[
				'user_id'     => $user_id,
				'course_id'   => $course_id,
				'credits'     => 2.0,
				'credit_type' => 'Dental CE',
				'awarded_at'  => '2026-01-01 00:00:00',
				'metadata'    => [],
			],
			$over
		);
	}

	public function test_insert_then_find_round_trips_a_value_object() {
		$user   = $this->make_learner();
		$course = $this->make_course();

		$created = CreditRepository::insert_ignore( $this->row( $user, $course, [ 'metadata' => [ 'provider_name' => 'DEKA Academy' ] ] ) );

		$this->assertInstanceOf( Credit::class, $created );
		$this->assertGreaterThan( 0, $created->id );
		$this->assertSame( $user, $created->user_id );
		$this->assertSame( $course, $created->course_id );
		$this->assertSame( 2.0, $created->credits );
		$this->assertSame( 'Dental CE', $created->credit_type );
		$this->assertSame( [ 'provider_name' => 'DEKA Academy' ], $created->metadata );
		$this->assertSame( 0, $created->certificate_id );

		$found = CreditRepository::find( $user, $course );
		$this->assertSame( $created->id, $found->id );
		$this->assertEquals( $created, CreditRepository::find_by_id( $created->id ) );
	}

	/** Brief rule 7 / D13: a duplicate must return the existing row, not error, not duplicate. */
	public function test_insert_ignore_is_idempotent() {
		$user   = $this->make_learner();
		$course = $this->make_course();

		$first  = CreditRepository::insert_ignore( $this->row( $user, $course ) );
		$second = CreditRepository::insert_ignore( $this->row( $user, $course, [ 'credits' => 9.0 ] ) );

		$this->assertSame( $first->id, $second->id );
		$this->assertSame( 2.0, $second->credits, 'The first write wins; a duplicate must not overwrite.' );
		$this->assertCount( 1, CreditRepository::for_course( $course ) );
	}

	public function test_find_returns_null_when_absent() {
		$this->assertNull( CreditRepository::find( 999998, 999999 ) );
		$this->assertNull( CreditRepository::find_by_id( 999999 ) );
	}

	/** Raw column test: SQL NULL, not the '' -> '0000-00-00 00:00:00' coercion. */
	private function is_null_in_db( int $id, string $column ): bool {
		global $wpdb;
		return '1' === (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT {$column} IS NULL FROM " . Migrations::table( 'ce_credits' ) . ' WHERE id = %d', $id ) // phpcs:ignore WordPress.DB.PreparedSQL
		);
	}

	public function test_an_unset_expires_at_is_stored_as_sql_null() {
		$c = CreditRepository::insert_ignore( $this->row( $this->make_learner(), $this->make_course() ) );

		$this->assertTrue( $this->is_null_in_db( $c->id, 'expires_at' ), 'expires_at must be SQL NULL.' );
		$this->assertNull( $c->expires_at, 'expires_at must read back as null.' );
	}

	public function test_a_set_expires_at_round_trips() {
		$c = CreditRepository::insert_ignore(
			$this->row( $this->make_learner(), $this->make_course(), [ 'expires_at' => '2027-01-01 00:00:00' ] )
		);
		$this->assertFalse( $this->is_null_in_db( $c->id, 'expires_at' ) );
		$this->assertSame( '2027-01-01 00:00:00', $c->expires_at );
	}

	/** An integral float must not come back as an int (Support\Json JSON_PRESERVE_ZERO_FRACTION). */
	public function test_float_metadata_survives_a_round_trip() {
		$c = CreditRepository::insert_ignore(
			$this->row( $this->make_learner(), $this->make_course(), [ 'metadata' => [ 'amount' => 1.0 ] ] )
		);
		$this->assertSame( 1.0, $c->metadata['amount'] );
	}

	public function test_attach_certificate_updates_only_that_column() {
		$c = CreditRepository::insert_ignore( $this->row( $this->make_learner(), $this->make_course() ) );

		$after = CreditRepository::attach_certificate( $c->id, 42 );

		$this->assertSame( 42, $after->certificate_id );
		$this->assertSame( $c->credits, $after->credits, 'Only certificate_id may change.' );
		$this->assertSame( $c->credit_type, $after->credit_type );
	}

	public function test_update_is_limited_to_the_allowlist() {
		$c = CreditRepository::insert_ignore( $this->row( $this->make_learner(), $this->make_course() ) );

		// user_id/course_id/credits are identity/award-time facts, not updatable.
		$after = CreditRepository::update( $c->id, [ 'user_id' => 999999, 'credits' => 500.0, 'certificate_id' => 7 ] );

		$this->assertSame( $c->user_id, $after->user_id );
		$this->assertSame( $c->credits, $after->credits );
		$this->assertSame( 7, $after->certificate_id );
	}

	public function test_for_user_and_for_course_list_matching_rows() {
		$user    = $this->make_learner();
		$courseA = $this->make_course();
		$courseB = $this->make_course();

		CreditRepository::insert_ignore( $this->row( $user, $courseA ) );
		CreditRepository::insert_ignore( $this->row( $user, $courseB ) );
		CreditRepository::insert_ignore( $this->row( $this->make_learner(), $courseA ) );

		$this->assertCount( 2, CreditRepository::for_user( $user ) );
		$this->assertCount( 2, CreditRepository::for_course( $courseA ) );
	}

	public function test_total_for_user_sums_credits_and_defaults_to_zero() {
		$user    = $this->make_learner();
		$courseA = $this->make_course();
		$courseB = $this->make_course();

		CreditRepository::insert_ignore( $this->row( $user, $courseA, [ 'credits' => 2.0 ] ) );
		CreditRepository::insert_ignore( $this->row( $user, $courseB, [ 'credits' => 3.5 ] ) );

		$this->assertSame( 5.5, CreditRepository::total_for_user( $user ) );
		$this->assertSame( 0.0, CreditRepository::total_for_user( $this->make_learner() ) );
	}

	/** Task 31 N+1 ruling: one query for a whole page of user ids, keyed by user_id. */
	public function test_for_users_in_course_batches_a_page_of_learners() {
		$course  = $this->make_course();
		$withA   = $this->make_learner();
		$withB   = $this->make_learner();
		$without = $this->make_learner();

		CreditRepository::insert_ignore( $this->row( $withA, $course, [ 'credits' => 2.0 ] ) );
		CreditRepository::insert_ignore( $this->row( $withB, $course, [ 'credits' => 4.0 ] ) );
		// A credit in a DIFFERENT course must not leak into this course's map.
		CreditRepository::insert_ignore( $this->row( $withA, $this->make_course(), [ 'credits' => 99.0 ] ) );

		$map = CreditRepository::for_users_in_course( [ $withA, $withB, $without ], $course );

		$this->assertCount( 2, $map );
		$this->assertSame( 2.0, $map[ $withA ]->credits );
		$this->assertSame( 4.0, $map[ $withB ]->credits );
		$this->assertArrayNotHasKey( $without, $map );
	}

	public function test_for_users_in_course_with_no_user_ids_makes_no_query() {
		$this->assertSame( [], CreditRepository::for_users_in_course( [], $this->make_course() ) );
	}
}
