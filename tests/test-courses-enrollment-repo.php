<?php
/**
 * Anchor Courses - enrollment table access (brief 7.1, 26).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Database\EnrollmentRepository;
use Anchor\Courses\Domain\Enrollment;

/** @group courses */
class Test_Courses_Enrollment_Repo extends Anchor_Courses_TestCase {

	private function row( int $user_id, int $course_id, array $over = [] ): array {
		return array_merge(
			[
				'user_id'     => $user_id,
				'course_id'   => $course_id,
				'status'      => 'enrolled',
				'enrolled_at' => '2026-01-01 00:00:00',
				'source'      => 'manual',
				'source_id'   => '',
				'metadata'    => [],
			],
			$over
		);
	}

	public function test_insert_then_find_round_trips_a_value_object() {
		$user   = $this->make_learner();
		$course = $this->make_course();

		$created = EnrollmentRepository::insert_ignore( $this->row( $user, $course, [ 'metadata' => [ 'note' => 'hi' ] ] ) );

		$this->assertInstanceOf( Enrollment::class, $created );
		$this->assertGreaterThan( 0, $created->id );
		$this->assertSame( $user, $created->user_id );
		$this->assertSame( 'enrolled', $created->status );
		$this->assertSame( [ 'note' => 'hi' ], $created->metadata );

		$found = EnrollmentRepository::find( $user, $course );
		$this->assertSame( $created->id, $found->id );
	}

	/** Brief 26: a duplicate must return the existing row, not error, not duplicate. */
	public function test_insert_ignore_is_idempotent() {
		$user   = $this->make_learner();
		$course = $this->make_course();

		$first  = EnrollmentRepository::insert_ignore( $this->row( $user, $course ) );
		$second = EnrollmentRepository::insert_ignore( $this->row( $user, $course, [ 'source' => 'woocommerce' ] ) );

		$this->assertSame( $first->id, $second->id );
		$this->assertSame( 'manual', $second->source, 'The first write wins; a duplicate must not overwrite.' );
		$this->assertSame( 1, EnrollmentRepository::count_for_course( $course ) );
	}

	public function test_find_returns_null_when_absent() {
		$this->assertNull( EnrollmentRepository::find( 999998, 999999 ) );
	}

	public function test_update_changes_status_and_timestamps() {
		$user   = $this->make_learner();
		$course = $this->make_course();
		$row    = EnrollmentRepository::insert_ignore( $this->row( $user, $course ) );

		// Clock has second granularity; advance it so updated_at is deterministically
		// different rather than racing the wall clock within the same second.
		add_filter( 'anchor_courses_now', static fn() => time() + 3600 );
		$updated = EnrollmentRepository::update( $row->id, [ 'status' => 'completed', 'completed_at' => '2026-02-02 10:00:00' ] );
		remove_all_filters( 'anchor_courses_now' );

		$this->assertSame( 'completed', $updated->status );
		$this->assertSame( '2026-02-02 10:00:00', $updated->completed_at );
		$this->assertNotSame( $row->to_array()['updated_at'] ?? '', $updated->to_array()['updated_at'] ?? 'x' );
	}

	public function test_for_user_and_for_course_filter_by_status() {
		$user = $this->make_learner();
		$a    = $this->make_course( [], 'A' );
		$b    = $this->make_course( [], 'B' );
		EnrollmentRepository::insert_ignore( $this->row( $user, $a ) );
		EnrollmentRepository::insert_ignore( $this->row( $user, $b, [ 'status' => 'completed' ] ) );

		$this->assertCount( 2, EnrollmentRepository::for_user( $user ) );
		$this->assertCount( 1, EnrollmentRepository::for_user( $user, [ 'completed' ] ) );
		$this->assertCount( 1, EnrollmentRepository::for_course( $a ) );
		$this->assertSame( 0, EnrollmentRepository::count_for_course( $a, [ 'completed' ] ) );
	}

	public function test_expire_due_flips_only_past_active_rows() {
		$user = $this->make_learner();
		$past = $this->make_course( [], 'Past' );
		$soon = $this->make_course( [], 'Soon' );
		$done = $this->make_course( [], 'Done' );

		EnrollmentRepository::insert_ignore( $this->row( $user, $past, [ 'expires_at' => '2026-01-02 00:00:00' ] ) );
		EnrollmentRepository::insert_ignore( $this->row( $user, $soon, [ 'expires_at' => '2030-01-01 00:00:00' ] ) );
		EnrollmentRepository::insert_ignore( $this->row( $user, $done, [ 'status' => 'completed', 'expires_at' => '2026-01-02 00:00:00' ] ) );

		$flipped = EnrollmentRepository::expire_due( '2026-06-01 00:00:00' );

		$this->assertSame( 1, $flipped );
		$this->assertSame( 'expired', EnrollmentRepository::find( $user, $past )->status );
		$this->assertSame( 'enrolled', EnrollmentRepository::find( $user, $soon )->status );
		$this->assertSame( 'completed', EnrollmentRepository::find( $user, $done )->status, 'A completed enrolment never expires.' );
	}

	public function test_is_active_covers_enrolled_and_in_progress_only() {
		$user   = $this->make_learner();
		$course = $this->make_course();
		$row    = EnrollmentRepository::insert_ignore( $this->row( $user, $course ) );
		$this->assertTrue( $row->is_active() );
		$this->assertFalse( EnrollmentRepository::update( $row->id, [ 'status' => 'cancelled' ] )->is_active() );
	}
}
