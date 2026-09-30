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

	public function test_test_send_is_untracked_and_prefixed() {
		$id = $this->make_announcement();
		$this->assertTrue( Queue::test_send( $id, 'me@x.com', null ) );
		$this->assertSame( 'me@x.com', $this->mails[0]['to'] );
		$this->assertStringStartsWith( '[Test] ', $this->mails[0]['subject'] );
		$this->assertStringNotContainsString( 'anchor_aa=o', $this->mails[0]['message'] );
		$this->assertSame( [], $this->rows( $id ) );
	}
}
