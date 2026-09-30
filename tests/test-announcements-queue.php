<?php
use Anchor\Announcements\Content\AnnouncementPostType as PT;
use Anchor\Announcements\Database\Migrations;
use Anchor\Announcements\Sending\Queue;
use Anchor\Announcements\Support\Settings;
use Anchor\Announcements\Suppression\Suppressions;

class Test_Announcements_Queue extends Anchor_Announcements_TestCase {

	/** @var array<int,array> captured wp_mail calls */
	private array $mails = [];
	private bool $fail = false;

	public function set_up() {
		parent::set_up();
		$this->mails = [];
		$this->fail  = false;
		Settings::save( [ 'footer_address' => '1 Main St', 'batch_size' => 2 ] );
		add_filter( 'pre_wp_mail', [ $this, 'capture' ], 10, 2 );
	}

	public function tear_down() {
		remove_filter( 'pre_wp_mail', [ $this, 'capture' ], 10 );
		parent::tear_down();
	}

	public function capture( $return, $atts ) {
		if ( $this->fail ) {
			return false;
		}
		$this->mails[] = $atts;
		return true;
	}

	private function audience( array $emails ): string {
		return wp_json_encode( [ 'groups' => [ [ 'conditions' => [ [ 'type' => 'specific_people', 'negate' => false, 'params' => [ 'emails' => implode( ',', $emails ) ] ] ] ] ] ] );
	}

	private function rows( int $id ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Migrations::table( 'sends' ) . ' WHERE announcement_id = %d ORDER BY email', $id ) );
	}

	public function test_start_snapshots_and_skips_suppressed() {
		Suppressions::add( 'b@x.com', 'unsubscribed' );
		$id = $this->make_announcement( [ PT::META_AUDIENCE => $this->audience( [ 'a@x.com', 'b@x.com', 'c@x.com' ] ) ] );
		$this->assertSame( 2, Queue::start( $id ) );
		$rows = $this->rows( $id );
		$this->assertSame( [ 'queued', 'skipped', 'queued' ], array_column( $rows, 'status' ) );
		$this->assertSame( 'suppressed', $rows[1]->skip_reason );
		$this->assertSame( PT::STATE_SENDING, PT::state( $id ) );
		$this->assertSame( [ 'https://example.com/page?a=1&b=2' ], get_post_meta( $id, PT::META_LINKS, true ) );
	}

	public function test_start_refuses_empty_audience_after_suppression() {
		Suppressions::add( 'only@x.com', 'unsubscribed' );
		$id  = $this->make_announcement( [ PT::META_AUDIENCE => $this->audience( [ 'only@x.com' ] ) ] );
		$res = Queue::start( $id );
		$this->assertWPError( $res );
		$this->assertSame( 'empty_audience', $res->get_error_code() );
		$this->assertSame( PT::STATE_DRAFT, PT::state( $id ) );
		$this->assertSame( [], $this->rows( $id ) );
	}

	public function test_start_requires_a_footer_address() {
		Settings::save( [ 'footer_address' => '' ] );
		$id = $this->make_announcement( [ PT::META_AUDIENCE => $this->audience( [ 'a@x.com' ] ) ] );
		$this->assertSame( 'missing_address', Queue::start( $id )->get_error_code() );
	}

	public function test_tick_sends_in_batches_then_marks_sent() {
		$id = $this->make_announcement( [ PT::META_AUDIENCE => $this->audience( [ 'a@x.com', 'b@x.com', 'c@x.com' ] ) ] );
		Queue::start( $id );
		Queue::tick();
		$this->assertCount( 2, $this->mails );
		Queue::tick();
		$this->assertCount( 3, $this->mails );
		$this->assertSame( [ 'sent', 'sent', 'sent' ], array_column( $this->rows( $id ), 'status' ) );
		$this->assertSame( PT::STATE_SENT, PT::state( $id ) );
		$this->assertNotEmpty( get_post_meta( $id, PT::META_SENT_AT, true ) );
		$this->assertNotEmpty( get_option( Queue::LAST_RUN_OPTION ) );
	}

	public function test_each_mail_is_personal_tracked_and_has_unsubscribe_headers() {
		$id = $this->make_announcement( [ PT::META_AUDIENCE => $this->audience( [ 'a@x.com' ] ) ] );
		Queue::start( $id );
		Queue::tick();
		$mail    = $this->mails[0];
		$headers = implode( "\n", (array) $mail['headers'] );
		$this->assertSame( 'a@x.com', $mail['to'] );
		$this->assertStringContainsString( 'anchor_aa=o', $mail['message'] );
		$this->assertStringContainsString( 'List-Unsubscribe: <', $headers );
		$this->assertStringContainsString( 'List-Unsubscribe-Post: List-Unsubscribe=One-Click', $headers );
		$this->assertStringContainsString( 'X-Mailgun-Track: no', $headers );
		$this->assertStringContainsString( 'Content-Type: text/html; charset=UTF-8', $headers );
	}

	public function test_a_claimed_row_cannot_be_claimed_again() {
		$id = $this->make_announcement( [ PT::META_AUDIENCE => $this->audience( [ 'a@x.com' ] ) ] );
		Queue::start( $id );
		$row = $this->rows( $id )[0];
		$this->assertTrue( Queue::claim( (int) $row->id ) );
		$this->assertFalse( Queue::claim( (int) $row->id ) );
	}

	public function test_failure_retries_then_fails() {
		$id = $this->make_announcement( [ PT::META_AUDIENCE => $this->audience( [ 'a@x.com' ] ) ] );
		Queue::start( $id );
		$this->fail = true;
		Queue::tick();
		$this->assertSame( 'queued', $this->rows( $id )[0]->status );
		Queue::tick();
		Queue::tick();
		$row = $this->rows( $id )[0];
		$this->assertSame( 'failed', $row->status );
		$this->assertSame( '3', (string) $row->attempts );
		$this->assertSame( PT::STATE_SENT, PT::state( $id ) );
	}

	public function test_pause_resume_cancel() {
		$id = $this->make_announcement( [ PT::META_AUDIENCE => $this->audience( [ 'a@x.com', 'b@x.com', 'c@x.com' ] ) ] );
		Queue::start( $id );
		Queue::pause( $id );
		Queue::tick();
		$this->assertCount( 0, $this->mails );
		Queue::resume( $id );
		Queue::tick();
		$this->assertCount( 2, $this->mails );
		Queue::cancel( $id );
		Queue::tick();
		$this->assertCount( 2, $this->mails );
		$this->assertSame( PT::STATE_CANCELLED, PT::state( $id ) );
		$this->assertContains( 'cancelled', array_column( $this->rows( $id ), 'skip_reason' ) );
	}

	public function test_schedule_releases_when_due() {
		$id = $this->make_announcement( [ PT::META_AUDIENCE => $this->audience( [ 'a@x.com' ] ) ] );
		$this->assertWPError( Queue::schedule( $id, time() - 60 ) );
		$this->assertTrue( Queue::schedule( $id, time() + 3600 ) );
		Queue::tick();
		$this->assertSame( PT::STATE_SCHEDULED, PT::state( $id ) );
		update_post_meta( $id, PT::META_SCHEDULED, time() - 1 );
		Queue::tick();
		$this->assertCount( 1, $this->mails );
	}

	public function test_tick_is_scheduled_only_while_work_exists() {
		wp_clear_scheduled_hook( Queue::HOOK );
		$this->assertFalse( wp_next_scheduled( Queue::HOOK ) );
		$id = $this->make_announcement( [ PT::META_AUDIENCE => $this->audience( [ 'a@x.com' ] ) ] );
		Queue::start( $id );
		$this->assertNotFalse( wp_next_scheduled( Queue::HOOK ), 'start() schedules the tick' );
		Queue::tick(); // sends the only row and finishes.
		$this->assertSame( PT::STATE_SENT, PT::state( $id ) );
		$this->assertFalse( wp_next_scheduled( Queue::HOOK ), 'idle tick is cleared' );
	}

	public function test_schedule_and_resume_arm_the_tick_and_cancel_clears_it() {
		wp_clear_scheduled_hook( Queue::HOOK );
		$id = $this->make_announcement( [ PT::META_AUDIENCE => $this->audience( [ 'a@x.com' ] ) ] );
		$this->assertTrue( Queue::schedule( $id, time() + 3600 ) );
		$this->assertNotFalse( wp_next_scheduled( Queue::HOOK ) );
		Queue::cancel( $id );
		$this->assertFalse( wp_next_scheduled( Queue::HOOK ) );
		$id2 = $this->make_announcement( [ PT::META_AUDIENCE => $this->audience( [ 'a@x.com' ] ) ] );
		Queue::start( $id2 );
		Queue::pause( $id2 );
		wp_clear_scheduled_hook( Queue::HOOK );
		Queue::resume( $id2 );
		$this->assertNotFalse( wp_next_scheduled( Queue::HOOK ) );
	}

	public function test_schedule_runs_the_same_checks_as_send() {
		Settings::save( [ 'footer_address' => '' ] );
		$id  = $this->make_announcement( [ PT::META_AUDIENCE => $this->audience( [ 'a@x.com' ] ) ] );
		$res = Queue::schedule( $id, time() + 3600 );
		$this->assertWPError( $res );
		$this->assertSame( 'missing_address', $res->get_error_code() );
		$this->assertSame( PT::STATE_DRAFT, PT::state( $id ) );
	}

	public function test_scheduled_send_that_fails_at_release_reverts_and_emails_the_author() {
		$author = self::factory()->user->create( [ 'role' => 'administrator', 'user_email' => 'author@x.com' ] );
		$id     = $this->make_announcement( [ PT::META_AUDIENCE => $this->audience( [ 'a@x.com' ] ) ] );
		wp_update_post( [ 'ID' => $id, 'post_author' => $author ] );
		$this->assertTrue( Queue::schedule( $id, time() + 3600 ) );
		Suppressions::add( 'a@x.com', 'unsubscribed' ); // audience empties before release.
		update_post_meta( $id, PT::META_SCHEDULED, time() - 1 );
		Queue::tick();
		$this->assertSame( PT::STATE_DRAFT, PT::state( $id ) );
		$this->assertNotSame( '', (string) get_post_meta( $id, '_aa_last_error', true ) );
		$this->assertCount( 1, $this->mails );
		$this->assertSame( 'author@x.com', $this->mails[0]['to'] );
		$this->assertStringContainsString( 'Nobody matches', $this->mails[0]['message'] );
		$this->assertStringContainsString( 'post.php', $this->mails[0]['message'] );
	}

	public function test_time_budget_leaves_remaining_rows_queued() {
		$id = $this->make_announcement( [ PT::META_AUDIENCE => $this->audience( [ 'a@x.com', 'b@x.com' ] ) ] );
		Queue::start( $id );
		$m = new ReflectionMethod( Queue::class, 'send_batch' );
		$m->setAccessible( true );
		$m->invoke( null, microtime( true ) - 100 );
		$this->assertCount( 0, $this->mails );
		$this->assertSame( [ 'queued', 'queued' ], array_column( $this->rows( $id ), 'status' ) );
	}

	public function test_test_send_is_untracked_and_prefixed() {
		$id = $this->make_announcement();
		$this->assertTrue( Queue::test_send( $id, 'me@x.com', null ) );
		$this->assertSame( 'me@x.com', $this->mails[0]['to'] );
		$this->assertStringStartsWith( '[Test] ', $this->mails[0]['subject'] );
		$this->assertStringNotContainsString( 'anchor_aa=o', $this->mails[0]['message'] );
		$this->assertSame( [], $this->rows( $id ) );
	}

	public function test_address_suppressed_after_start_is_skipped_at_send_time() {
		$id = $this->make_announcement( [ PT::META_AUDIENCE => $this->audience( [ 'a@x.com', 'b@x.com' ] ) ] );
		Queue::start( $id );
		Suppressions::add( 'a@x.com', 'unsubscribed' );
		Queue::tick();
		$this->assertCount( 1, $this->mails );
		$this->assertSame( 'b@x.com', $this->mails[0]['to'] );
		$row = $this->rows( $id )[0];
		$this->assertSame( 'skipped', $row->status );
		$this->assertSame( 'suppressed', $row->skip_reason );
	}

	public function test_interrupted_claim_fails_instead_of_resending() {
		global $wpdb;
		$id = $this->make_announcement( [ PT::META_AUDIENCE => $this->audience( [ 'a@x.com' ] ) ] );
		Queue::start( $id );
		$row = $this->rows( $id )[0];
		Queue::claim( (int) $row->id );
		$wpdb->update( Migrations::table( 'sends' ), [ 'claimed_at' => gmdate( 'Y-m-d H:i:s', time() - 31 * 60 ) ], [ 'id' => (int) $row->id ] );
		Queue::tick();
		$row = $this->rows( $id )[0];
		$this->assertSame( 'failed', $row->status );
		$this->assertNotEmpty( $row->error );
		$this->assertCount( 0, $this->mails );
	}

	private function rules_with_missing_condition(): string {
		return wp_json_encode( [ 'groups' => [
			[ 'conditions' => [
				[ 'type' => 'specific_people', 'negate' => false, 'params' => [ 'emails' => 'all@x.com,subs@x.com' ] ],
				[ 'type' => 'wc_gone_away', 'negate' => false, 'params' => [ 'products' => [ 9 ] ] ],
			] ],
			[ 'conditions' => [ [ 'type' => 'specific_people', 'negate' => false, 'params' => [ 'emails' => 'valid@x.com' ] ] ] ],
		] ] );
	}

	public function test_unavailable_condition_fails_closed_on_send_and_schedule() {
		$id = $this->make_announcement( [ PT::META_AUDIENCE => $this->rules_with_missing_condition() ] );
		$res = Queue::start( $id );
		$this->assertWPError( $res );
		$this->assertSame( 'audience_problem', $res->get_error_code() );
		$this->assertStringContainsString( 'Group 1: "wc_gone_away" is not available on this site.', $res->get_error_message() );
		$this->assertSame( [], $this->rows( $id ) );
		$this->assertSame( PT::STATE_DRAFT, PT::state( $id ) );
		$sched = Queue::schedule( $id, time() + 3600 );
		$this->assertWPError( $sched );
		$this->assertSame( 'audience_problem', $sched->get_error_code() );
		$this->assertSame( PT::STATE_DRAFT, PT::state( $id ) );
		$this->assertSame( 'audience_problem', Queue::preflight( $id )->get_error_code() );
	}

	public function test_scheduled_release_with_a_problem_reverts_to_draft_and_sends_nothing_to_the_group() {
		$id = $this->make_announcement( [ PT::META_AUDIENCE => $this->audience( [ 'a@x.com' ] ) ] );
		$this->assertTrue( Queue::schedule( $id, time() + 3600 ) );
		update_post_meta( $id, PT::META_AUDIENCE, $this->rules_with_missing_condition() ); // e.g. WooCommerce switched off since.
		update_post_meta( $id, PT::META_SCHEDULED, time() - 1 );
		Queue::tick();
		$this->assertSame( PT::STATE_DRAFT, PT::state( $id ) );
		$this->assertSame( [], $this->rows( $id ) );
		$this->assertStringContainsString( 'not available', (string) get_post_meta( $id, '_aa_last_error', true ) );
	}

	public function test_trashing_a_sending_announcement_cancels_its_queue() {
		$id = $this->make_announcement( [ PT::META_AUDIENCE => $this->audience( [ 'a@x.com', 'b@x.com', 'c@x.com' ] ) ] );
		Queue::start( $id );
		wp_trash_post( $id );
		$this->assertSame( PT::STATE_CANCELLED, PT::state( $id ) );
		$this->assertNotContains( 'queued', array_column( $this->rows( $id ), 'status' ) );
		Queue::tick();
		$this->assertCount( 0, $this->mails );
	}

	public function test_trashing_a_scheduled_announcement_cancels_it() {
		$id = $this->make_announcement( [ PT::META_AUDIENCE => $this->audience( [ 'a@x.com' ] ) ] );
		$this->assertTrue( Queue::schedule( $id, time() + 3600 ) );
		wp_trash_post( $id );
		$this->assertSame( PT::STATE_CANCELLED, PT::state( $id ) );
		$this->assertFalse( wp_next_scheduled( Queue::HOOK ) );
	}

	public function test_force_deleting_removes_send_and_event_rows() {
		global $wpdb;
		$id    = $this->make_announcement( [ PT::META_AUDIENCE => $this->audience( [ 'a@x.com', 'b@x.com' ] ) ] );
		$other = $this->make_announcement( [ PT::META_AUDIENCE => $this->audience( [ 'z@x.com' ] ) ] );
		Queue::start( $id );
		Queue::start( $other );
		$send_id = (int) $this->rows( $id )[0]->id;
		$keep_id = (int) $this->rows( $other )[0]->id;
		foreach ( [ $send_id, $keep_id ] as $sid ) {
			$wpdb->insert( Migrations::table( 'events' ), [ 'send_id' => $sid, 'type' => 'open', 'created_at' => current_time( 'mysql', true ) ] );
		}
		wp_delete_post( $id, true );
		$this->assertSame( [], $this->rows( $id ) );
		$events = Migrations::table( 'events' );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$events} WHERE send_id = %d", $send_id ) ) );
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$events} WHERE send_id = %d", $keep_id ) ) );
		$this->assertCount( 1, $this->rows( $other ), 'other announcements are untouched' );
		Queue::tick();
		$this->assertCount( 1, $this->mails );
		$this->assertSame( 'z@x.com', $this->mails[0]['to'] );
	}

	public function test_suppressed_among_handles_more_than_one_chunk() {
		$emails = [];
		for ( $i = 0; $i < 1203; $i++ ) {
			$emails[] = "user{$i}@x.com";
		}
		Suppressions::add( 'user3@x.com', 'unsubscribed' );
		Suppressions::add( 'user999@x.com', 'bounced' );
		Suppressions::add( 'user1202@x.com', 'manual' );
		Suppressions::add( 'other@x.com', 'manual' );
		$emails[] = ' USER3@X.com ';
		$got = Suppressions::suppressed_among( $emails );
		$keys = array_keys( $got );
		sort( $keys );
		$this->assertSame( [ 'user1202@x.com', 'user3@x.com', 'user999@x.com' ], $keys );
		$this->assertSame( [], Suppressions::suppressed_among( [] ) );
	}
}
