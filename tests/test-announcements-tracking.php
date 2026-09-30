<?php
use Anchor\Announcements\Content\AnnouncementPostType as PT;
use Anchor\Announcements\Database\Migrations;
use Anchor\Announcements\Suppression\Suppressions;
use Anchor\Announcements\Tracking\Endpoints;

class Test_Announcements_Tracking extends Anchor_Announcements_TestCase {

	private function send_row( int $announcement, string $email = 'r@x.com', ?string $sent_at = '2026-01-01 00:00:00', int $user_id = 0 ): object {
		global $wpdb;
		$token = bin2hex( random_bytes( 16 ) );
		$wpdb->insert( Migrations::table( 'sends' ), [ 'announcement_id' => $announcement, 'email' => $email, 'user_id' => $user_id ?: null, 'name' => 'R', 'token' => $token, 'status' => 'sent', 'queued_at' => '2026-01-01 00:00:00', 'sent_at' => $sent_at ] );
		return Endpoints::find_send( $token );
	}

	public function test_find_send_rejects_bad_tokens() {
		$this->assertNull( Endpoints::find_send( 'nope' ) );
		$this->assertNull( Endpoints::find_send( str_repeat( 'z', 32 ) ) );
	}

	public function test_open_counts_every_hit_and_first_time_once() {
		$send = $this->send_row( $this->make_announcement() );
		Endpoints::record( $send, 'open' );
		$first = Endpoints::find_send( $send->token )->first_opened_at;
		Endpoints::record( Endpoints::find_send( $send->token ), 'open' );
		$again = Endpoints::find_send( $send->token );
		$this->assertSame( 2, (int) $again->open_count );
		$this->assertSame( $first, $again->first_opened_at );
	}

	public function test_click_target_only_from_the_stored_list_and_expands_tokens() {
		$id = $this->make_announcement();
		update_post_meta( $id, PT::META_LINKS, [ 'https://example.com/page?a=1&b=2', '{account_url}', 'javascript:alert(1)' ] );
		$send = $this->send_row( $id );
		$this->assertSame( 'https://example.com/page?a=1&b=2', Endpoints::click_target( $send, 0 ) );
		$this->assertStringStartsWith( 'http', Endpoints::click_target( $send, 1 ) );
		$this->assertSame( '', Endpoints::click_target( $send, 2 ) );
		$this->assertSame( '', Endpoints::click_target( $send, 99 ) );
	}

	public function test_scanner_click_is_kept_but_not_counted() {
		$send = $this->send_row( $this->make_announcement(), 'r@x.com', gmdate( 'Y-m-d H:i:s' ) );
		$this->assertTrue( Endpoints::is_scanner_click( $send ) );
		Endpoints::record( $send, 'click', 0, true );
		$this->assertSame( 0, (int) Endpoints::find_send( $send->token )->click_count );
		global $wpdb;
		$this->assertSame( '1', $wpdb->get_var( $wpdb->prepare( 'SELECT scanner FROM ' . Migrations::table( 'events' ) . ' WHERE send_id = %d', $send->id ) ) );
	}

	public function test_real_click_counts() {
		$send = $this->send_row( $this->make_announcement() );
		$this->assertFalse( Endpoints::is_scanner_click( $send ) );
		Endpoints::record( $send, 'click', 0 );
		$this->assertSame( 1, (int) Endpoints::find_send( $send->token )->click_count );
		$this->assertNotNull( Endpoints::find_send( $send->token )->first_clicked_at );
	}

	public function test_unsubscribe_suppresses_and_records() {
		$send = $this->send_row( $this->make_announcement(), 'leave@x.com' );
		Endpoints::unsubscribe( $send );
		$this->assertTrue( Suppressions::is_suppressed( 'leave@x.com' ) );
		global $wpdb;
		$this->assertSame( 'unsubscribe', $wpdb->get_var( $wpdb->prepare( 'SELECT type FROM ' . Migrations::table( 'events' ) . ' WHERE send_id = %d', $send->id ) ) );
	}
}
