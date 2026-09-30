<?php
use Anchor\Announcements\Database\Migrations;
use Anchor\Announcements\Privacy\Privacy;
use Anchor\Announcements\Suppression\Suppressions;
use Anchor\Announcements\Tracking\Endpoints;

class Test_Announcements_Privacy extends Anchor_Announcements_TestCase {

	private function send( string $email ): object {
		global $wpdb;
		$wpdb->insert( Migrations::table( 'sends' ), [ 'announcement_id' => $this->make_announcement(), 'email' => $email, 'name' => 'P', 'token' => bin2hex( random_bytes( 16 ) ), 'status' => 'sent', 'queued_at' => '2026-01-01 00:00:00', 'sent_at' => '2026-01-01 00:00:00' ] );
		return $wpdb->get_row( 'SELECT * FROM ' . Migrations::table( 'sends' ) . ' WHERE id = ' . (int) $wpdb->insert_id );
	}

	public function test_registered() {
		$this->assertArrayHasKey( 'anchor-announcements', apply_filters( 'wp_privacy_personal_data_exporters', [] ) );
		$this->assertArrayHasKey( 'anchor-announcements', apply_filters( 'wp_privacy_personal_data_erasers', [] ) );
	}

	public function test_export_lists_sends_and_suppression() {
		$s = $this->send( 'p@x.com' );
		Endpoints::record( $s, 'open' );
		Suppressions::add( 'p@x.com', 'unsubscribed' );
		$out = Privacy::export( 'P@x.com' );
		$this->assertTrue( $out['done'] );
		$this->assertCount( 2, $out['data'] );
	}

	public function test_erase_removes_history_but_keeps_the_opt_out() {
		$s = $this->send( 'p@x.com' );
		Endpoints::record( $s, 'open' );
		Suppressions::add( 'p@x.com', 'unsubscribed' );
		$out = Privacy::erase( 'p@x.com' );
		$this->assertTrue( $out['items_removed'] );
		$this->assertTrue( $out['items_retained'] );
		$this->assertNotEmpty( $out['messages'] );
		global $wpdb;
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Migrations::table( 'sends' ) . ' WHERE email = %s', 'p@x.com' ) ) );
		$this->assertSame( '0', $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Migrations::table( 'events' ) ) );
		$this->assertTrue( Suppressions::is_suppressed( 'p@x.com' ) );
	}
}
