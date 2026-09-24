<?php
/**
 * Anchor Courses - enrollment table access (brief 7.1, 26).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Database\EnrollmentRepository;
use Anchor\Courses\Database\Migrations;
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

	public function test_insert_rejects_an_unknown_status() {
		$this->expectException( InvalidArgumentException::class );
		EnrollmentRepository::insert_ignore( $this->row( $this->make_learner(), $this->make_course(), [ 'status' => 'bogus' ] ) );
	}

	public function test_update_rejects_an_unknown_status() {
		$e = EnrollmentRepository::insert_ignore( $this->row( $this->make_learner(), $this->make_course() ) );
		$this->expectException( InvalidArgumentException::class );
		EnrollmentRepository::update( $e->id, [ 'status' => 'bogus' ] );
	}

	public function test_update_ignores_identity_columns() {
		$user   = $this->make_learner();
		$course = $this->make_course();
		$e      = EnrollmentRepository::insert_ignore( $this->row( $user, $course ) );

		$after = EnrollmentRepository::update( $e->id, [ 'user_id' => 999999, 'course_id' => 999998, 'source' => 'import' ] );

		$this->assertSame( $user, $after->user_id );
		$this->assertSame( $course, $after->course_id );
		$this->assertSame( 'import', $after->source );
	}
	/** Raw column test: SQL NULL, not the '' -> '0000-00-00 00:00:00' coercion. */
	private function is_null_in_db( int $id, string $column ): bool {
		global $wpdb;
		return '1' === (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT {$column} IS NULL FROM " . Migrations::table( 'enrollments' ) . ' WHERE id = %d', $id ) // phpcs:ignore WordPress.DB.PreparedSQL
		);
	}

	/**
	 * Review of Task 14 (HIGH): nullable datetimes went in through %s, so a PHP
	 * null became '' and MySQL stored the zero-date.
	 */
	public function test_unset_datetimes_are_stored_as_sql_null() {
		$e = EnrollmentRepository::insert_ignore( $this->row( $this->make_learner(), $this->make_course() ) );

		foreach ( [ 'started_at', 'completed_at', 'expires_at' ] as $column ) {
			$this->assertTrue( $this->is_null_in_db( $e->id, $column ), "{$column} must be SQL NULL." );
			$this->assertNull( $e->{$column}, "{$column} must read back as null." );
		}
	}

	public function test_update_to_null_stores_sql_null() {
		$e = EnrollmentRepository::insert_ignore( $this->row( $this->make_learner(), $this->make_course(), [ 'expires_at' => '2030-01-01 00:00:00' ] ) );

		$after = EnrollmentRepository::update( $e->id, [ 'expires_at' => null ] );

		$this->assertTrue( $this->is_null_in_db( $e->id, 'expires_at' ) );
		$this->assertNull( $after->expires_at );
	}

	public function test_expire_due_never_sweeps_a_row_without_expiry() {
		$user   = $this->make_learner();
		$course = $this->make_course();
		EnrollmentRepository::insert_ignore( $this->row( $user, $course ) );

		$this->assertSame( 0, EnrollmentRepository::expire_due( '2099-01-01 00:00:00' ) );
		$this->assertSame( 'enrolled', EnrollmentRepository::find( $user, $course )->status );
	}

	/** Rows written by the pre-fix code carry the zero-date; they must neither sweep nor lie. */
	public function test_a_legacy_zero_date_row_reads_as_null_and_is_never_swept() {
		global $wpdb;
		$user   = $this->make_learner();
		$course = $this->make_course();
		$e      = EnrollmentRepository::insert_ignore( $this->row( $user, $course ) );
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . Migrations::table( 'enrollments' ) . " SET started_at = '0000-00-00 00:00:00', completed_at = '0000-00-00 00:00:00', expires_at = '0000-00-00 00:00:00' WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$e->id
			)
		);
		$this->assertFalse( $this->is_null_in_db( $e->id, 'expires_at' ), 'Precondition: the legacy row really holds the zero-date.' );

		$this->assertSame( 0, EnrollmentRepository::expire_due( '2099-01-01 00:00:00' ) );

		$found = EnrollmentRepository::find( $user, $course );
		$this->assertSame( 'enrolled', $found->status );
		$this->assertNull( $found->started_at );
		$this->assertNull( $found->completed_at );
		$this->assertNull( $found->expires_at );
	}
}
