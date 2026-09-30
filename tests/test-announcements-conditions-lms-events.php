<?php
use Anchor\Announcements\Audience\Conditions\CourseCompleted;
use Anchor\Announcements\Audience\Conditions\CourseEnrolled;
use Anchor\Announcements\Audience\Conditions\EventRegistered;

class Test_Announcements_Conditions_Lms_Events extends Anchor_Announcements_TestCase {

	private function enrol( int $user, int $course, string $status, string $enrolled, ?string $completed = null ): void {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->insert( \Anchor\Courses\Database\Migrations::table( 'enrollments' ), [
			'user_id' => $user, 'course_id' => $course, 'status' => $status, 'enrolled_at' => $enrolled,
			'completed_at' => $completed, 'created_at' => $now, 'updated_at' => $now,
		] );
	}

	private function seat( int $event, string $email, string $status, string $date, int $user = 0 ): int {
		$id = self::factory()->post->create( [ 'post_type' => 'anchor_event_reg', 'post_status' => 'publish', 'post_date_gmt' => $date, 'post_date' => $date ] );
		update_post_meta( $id, '_anchor_event_id', $event );
		update_post_meta( $id, '_anchor_event_email', $email );
		update_post_meta( $id, '_anchor_event_name', 'Seat Person' );
		update_post_meta( $id, '_anchor_event_reg_status', $status );
		update_post_meta( $id, '_anchor_event_user_id', $user );
		return $id;
	}

	public function test_course_enrolled_status_and_range() {
		if ( ! class_exists( '\Anchor\Courses\Module' ) ) { $this->markTestSkipped( 'courses module off' ); }
		$a = $this->make_user( 'active@x.com' );
		$b = $this->make_user( 'done@x.com' );
		$c = $this->make_user( 'lapsed@x.com' );
		$this->enrol( $a, 100, 'in_progress', '2026-02-01 00:00:00' );
		$this->enrol( $b, 100, 'completed', '2026-02-01 00:00:00', '2026-02-05 00:00:00' );
		$this->enrol( $c, 100, 'expired', '2026-02-01 00:00:00' );
		$cond = new CourseEnrolled();
		$this->assertSame( [ 'active@x.com' ], array_column( $cond->match( [ 'courses' => [ 100 ], 'status' => 'active' ] )->all(), 'email' ) );
		$this->assertSame( 3, $cond->match( [ 'courses' => [ 100 ], 'status' => 'any' ] )->count() );
		$this->assertSame( 0, $cond->match( [ 'courses' => [ 100 ], 'status' => 'any', 'from' => '2026-03-01' ] )->count() );
	}

	public function test_course_completed_range() {
		if ( ! class_exists( '\Anchor\Courses\Module' ) ) { $this->markTestSkipped( 'courses module off' ); }
		$b = $this->make_user( 'done@x.com' );
		$this->enrol( $b, 100, 'completed', '2026-02-01 00:00:00', '2026-02-05 12:00:00' );
		$this->assertTrue( ( new CourseCompleted() )->match( [ 'courses' => [], 'from' => '2026-02-05', 'to' => '2026-02-05' ] )->has( 'done@x.com' ) );
		$this->assertSame( 0, ( new CourseCompleted() )->match( [ 'courses' => [ 999 ] ] )->count() );
	}

	public function test_event_registered_includes_guests_and_filters_status() {
		if ( ! class_exists( '\Anchor\Events\Module' ) ) { $this->markTestSkipped( 'events module off' ); }
		$this->seat( 7, 'Guest@X.com', 'confirmed', '2026-02-01 10:00:00' );
		$this->seat( 7, 'gone@x.com', 'cancelled', '2026-02-01 10:00:00' );
		$this->seat( 8, 'other@x.com', 'confirmed', '2026-02-01 10:00:00' );
		$set = ( new EventRegistered() )->match( [ 'events' => [ 7 ] ] );
		$this->assertSame( [ 'guest@x.com' ], array_column( $set->all(), 'email' ) );
		$this->assertSame( 'Seat Person', $set->get( 'guest@x.com' )['name'] );
		$this->assertSame( 2, ( new EventRegistered() )->match( [ 'events' => [ 7 ], 'statuses' => [ 'confirmed', 'cancelled' ] ] )->count() );
	}

	public function test_event_seat_with_a_user_uses_the_account() {
		if ( ! class_exists( '\Anchor\Events\Module' ) ) { $this->markTestSkipped( 'events module off' ); }
		$u = $this->make_user( 'member@x.com', [ 'display_name' => 'Mem Ber' ] );
		$this->seat( 7, 'typed-differently@x.com', 'confirmed', '2026-02-01 10:00:00', $u );
		$this->assertSame( $u, ( new EventRegistered() )->match( [ 'events' => [ 7 ] ] )->get( 'member@x.com' )['user_id'] );
	}
}
