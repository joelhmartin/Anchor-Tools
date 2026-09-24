<?php
/**
 * Anchor Courses - item progress table access (brief 7.2, 26).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Database\Migrations;
use Anchor\Courses\Database\ProgressRepository;
use Anchor\Courses\Domain\Progress;

/** @group courses */
class Test_Courses_Progress_Repo extends Anchor_Courses_TestCase {

	public function test_upsert_creates_then_updates_one_row() {
		$user   = $this->make_learner();
		$course = $this->make_course();
		$lesson = $this->make_lesson();

		$created = ProgressRepository::upsert(
			[ 'user_id' => $user, 'course_id' => $course, 'item_id' => $lesson,
			  'item_type' => 'lesson', 'status' => 'in_progress', 'progress_percent' => 25.0 ]
		);
		$this->assertInstanceOf( Progress::class, $created );
		$this->assertSame( 25.0, $created->progress_percent );

		$updated = ProgressRepository::upsert(
			[ 'user_id' => $user, 'course_id' => $course, 'item_id' => $lesson,
			  'item_type' => 'lesson', 'status' => 'completed', 'progress_percent' => 100.0,
			  'completed_at' => '2026-03-03 09:00:00' ]
		);

		$this->assertSame( $created->id, $updated->id, 'A second upsert must not create a second row.' );
		$this->assertSame( 'completed', $updated->status );
		$this->assertSame( '2026-03-03 09:00:00', $updated->completed_at );
		$this->assertCount( 1, ProgressRepository::for_course( $user, $course ) );
	}

	public function test_a_lesson_and_a_quiz_with_the_same_id_are_separate_rows() {
		$user   = $this->make_learner();
		$course = $this->make_course();

		ProgressRepository::upsert( [ 'user_id' => $user, 'course_id' => $course, 'item_id' => 77, 'item_type' => 'lesson', 'status' => 'completed' ] );
		ProgressRepository::upsert( [ 'user_id' => $user, 'course_id' => $course, 'item_id' => 77, 'item_type' => 'quiz', 'status' => 'failed' ] );

		$rows = ProgressRepository::for_course( $user, $course );
		$this->assertCount( 2, $rows );
		$this->assertSame( 'completed', $rows['lesson:77']->status );
		$this->assertSame( 'failed', $rows['quiz:77']->status );
	}

	public function test_completed_keys_and_count_ignore_unfinished_items() {
		$user   = $this->make_learner();
		$course = $this->make_course();

		ProgressRepository::upsert( [ 'user_id' => $user, 'course_id' => $course, 'item_id' => 1, 'item_type' => 'lesson', 'status' => 'completed' ] );
		ProgressRepository::upsert( [ 'user_id' => $user, 'course_id' => $course, 'item_id' => 2, 'item_type' => 'lesson', 'status' => 'in_progress' ] );
		ProgressRepository::upsert( [ 'user_id' => $user, 'course_id' => $course, 'item_id' => 3, 'item_type' => 'quiz', 'status' => 'failed' ] );

		$this->assertSame( [ 'lesson:1' ], ProgressRepository::completed_keys( $user, $course ) );
		$this->assertSame( 1, ProgressRepository::count_completed( $user, $course ) );
	}

	public function test_find_returns_null_when_absent() {
		$this->assertNull( ProgressRepository::find( 999998, 999997, 5, 'lesson' ) );
	}

	public function test_metadata_round_trips_as_an_array() {
		$user   = $this->make_learner();
		$course = $this->make_course();

		$row = ProgressRepository::upsert(
			[ 'user_id' => $user, 'course_id' => $course, 'item_id' => 9, 'item_type' => 'lesson',
			  'status' => 'completed', 'metadata' => [ 'attempt_id' => 12 ] ]
		);

		$this->assertSame( [ 'attempt_id' => 12 ], $row->metadata );
	}

	public function test_last_activity_returns_the_latest_update() {
		$user   = $this->make_learner();
		$course = $this->make_course();
		$this->assertSame( '', ProgressRepository::last_activity( $user, $course ) );

		ProgressRepository::upsert( [ 'user_id' => $user, 'course_id' => $course, 'item_id' => 1, 'item_type' => 'lesson', 'status' => 'completed' ] );

		$this->assertNotSame( '', ProgressRepository::last_activity( $user, $course ) );
	}

	public function test_delete_for_course_removes_only_that_users_rows() {
		$mine   = $this->make_learner();
		$theirs = $this->make_learner();
		$course = $this->make_course();
		ProgressRepository::upsert( [ 'user_id' => $mine, 'course_id' => $course, 'item_id' => 1, 'item_type' => 'lesson', 'status' => 'completed' ] );
		ProgressRepository::upsert( [ 'user_id' => $theirs, 'course_id' => $course, 'item_id' => 1, 'item_type' => 'lesson', 'status' => 'completed' ] );

		$this->assertSame( 1, ProgressRepository::delete_for_course( $mine, $course ) );
		$this->assertCount( 0, ProgressRepository::for_course( $mine, $course ) );
		$this->assertCount( 1, ProgressRepository::for_course( $theirs, $course ) );
	}

	/** Review ruling carried into T15: enum-ish fields are validated against the value object's constants. */
	public function test_upsert_rejects_an_unknown_status() {
		$this->expectException( InvalidArgumentException::class );
		ProgressRepository::upsert(
			[ 'user_id' => $this->make_learner(), 'course_id' => $this->make_course(), 'item_id' => 1,
			  'item_type' => 'lesson', 'status' => 'bogus' ]
		);
	}

	/** Same ruling, applied to item_type since Progress::ITEM_TYPES is the other enum this table stores. */
	public function test_upsert_rejects_an_unknown_item_type() {
		$this->expectException( InvalidArgumentException::class );
		ProgressRepository::upsert(
			[ 'user_id' => $this->make_learner(), 'course_id' => $this->make_course(), 'item_id' => 1,
			  'item_type' => 'assignment', 'status' => 'completed' ]
		);
	}
	private function is_null_in_db( int $id, string $column ): bool {
		global $wpdb;
		return '1' === (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT {$column} IS NULL FROM " . Migrations::table( 'progress' ) . ' WHERE id = %d', $id ) // phpcs:ignore WordPress.DB.PreparedSQL
		);
	}

	/** Task 14 review (HIGH), same defect as the enrollment table: null must be SQL NULL. */
	public function test_unset_datetimes_are_stored_as_sql_null() {
		$p = ProgressRepository::upsert(
			[ 'user_id' => $this->make_learner(), 'course_id' => $this->make_course(), 'item_id' => 4,
			  'item_type' => 'lesson', 'status' => 'not_started' ]
		);

		foreach ( [ 'started_at', 'completed_at', 'last_viewed_at' ] as $column ) {
			$this->assertTrue( $this->is_null_in_db( $p->id, $column ), "{$column} must be SQL NULL." );
			$this->assertNull( $p->{$column}, "{$column} must read back as null." );
		}
	}

	/** The UPDATE half of the upsert writes an explicit null as SQL NULL too. */
	public function test_upsert_can_clear_a_datetime_back_to_null() {
		$base = [ 'user_id' => $this->make_learner(), 'course_id' => $this->make_course(), 'item_id' => 5, 'item_type' => 'lesson' ];
		ProgressRepository::upsert( $base + [ 'status' => 'completed', 'completed_at' => '2026-03-03 09:00:00' ] );

		$reset = ProgressRepository::upsert( $base + [ 'status' => 'in_progress', 'completed_at' => null ] );

		$this->assertTrue( $this->is_null_in_db( $reset->id, 'completed_at' ) );
		$this->assertNull( $reset->completed_at );
	}

	public function test_a_legacy_zero_date_row_reads_as_null() {
		global $wpdb;
		$p = ProgressRepository::upsert(
			[ 'user_id' => $this->make_learner(), 'course_id' => $this->make_course(), 'item_id' => 6,
			  'item_type' => 'lesson', 'status' => 'not_started' ]
		);
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . Migrations::table( 'progress' ) . " SET started_at = '0000-00-00 00:00:00', completed_at = '0000-00-00 00:00:00', last_viewed_at = '0000-00-00 00:00:00' WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$p->id
			)
		);

		$found = ProgressRepository::find( $p->user_id, $p->course_id, 6, 'lesson' );
		$this->assertNull( $found->started_at );
		$this->assertNull( $found->completed_at );
		$this->assertNull( $found->last_viewed_at );
	}

	/** Task 22 review: an integral float must not come back as an int (JSON_PRESERVE_ZERO_FRACTION). */
	public function test_float_metadata_survives_a_round_trip() {
		$p = ProgressRepository::upsert(
			[ 'user_id' => $this->make_learner(), 'course_id' => $this->make_course(), 'item_id' => 7,
			  'item_type' => 'quiz', 'status' => 'completed', 'metadata' => [ 'best_score' => 1.0 ] ]
		);

		$this->assertSame( 1.0, $p->metadata['best_score'] );
	}
}
