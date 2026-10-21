<?php
/**
 * Mail log admin: sign-in token stripping, cell helpers and assets.
 *
 * @package Anchor_Tools\Tests
 */

/**
 * @group email
 * @group mail-log
 */
class Test_Mail_Log_Admin extends WP_Ajax_UnitTestCase {

	public function set_up() {
		parent::set_up();
		remove_action( 'admin_init', '_maybe_update_core' );
		remove_action( 'admin_init', '_maybe_update_plugins' );
		remove_action( 'admin_init', '_maybe_update_themes' );
		Anchor_Mail_Log::install();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Anchor_Mail_Log::table() ); // phpcs:ignore
		reset_phpmailer_instance();
		$this->_setRole( 'administrator' );
		$_POST = [];
		$_GET  = [];
	}

	public function tear_down() {
		Anchor_Mail_Log::finish( true );
		reset_phpmailer_instance();
		$_POST = [];
		$_GET  = [];
		parent::tear_down();
	}

	private function seed( array $over = [] ) {
		return Anchor_Mail_Log::insert(
			array_merge(
				[
					'context'   => 'events',
					'kind'      => 'confirmation',
					'audience'  => 'attendee',
					'event_id'  => 11,
					'seat_id'   => 22,
					'order_id'  => 33,
					'recipient' => 'pat@example.com',
					'subject'   => 'You are registered',
					'html'      => '<p>Hello Pat</p>',
					'headers'   => wp_json_encode( [ 'Content-Type: text/html; charset=UTF-8', 'Bcc: staff@x.com' ] ),
				],
				$over
			)
		);
	}

	private function sent_header_blob() {
		$mock = tests_retrieve_phpmailer_instance();
		$sent = $mock->get_sent();
		$raw  = $sent ? $sent->header : '';
		$raw  = preg_replace( '/\?=\r\n =\?us-ascii\?Q\?/', '', $raw );
		return preg_replace_callback(
			'/=\?us-ascii\?Q\?(.*?)\?=/',
			static function ( $m ) {
				return quoted_printable_decode( str_replace( '_', ' ', $m[1] ) );
			},
			$raw
		);
	}

	public function test_strip_sign_in_tokens_removes_aek_only() {
		$html = '<a href="https://x.test/live/?aek=abc&amp;x=1">Join</a> <a href=\'https://x.test/live/?aek=zzz\'>J2</a> <a href="https://x.test/?a=1">Plain</a>';
		$out  = Anchor_Mail_Log_Admin::strip_sign_in_tokens( $html );
		$this->assertStringNotContainsString( 'aek', $out );
		$this->assertStringContainsString( 'https://x.test/live/?x=1', $out );
		$this->assertStringContainsString( "href='https://x.test/live/'", $out );
		$this->assertStringContainsString( 'href="https://x.test/?a=1"', $out );
	}

	public function test_cell_helpers() {
		$delivered = Anchor_Mail_Log::get( $this->seed( [ 'status' => 'delivered', 'delivered_at' => '2026-10-20 20:00:00' ] ) );
		$this->assertStringStartsWith( 'Yes · ', Anchor_Mail_Log_Admin::delivery( $delivered )[0] );
		$this->assertSame( [ 'Bounced', 'red' ], Anchor_Mail_Log_Admin::delivery( [ 'status' => 'bounced' ] ) );
		$this->assertSame( [ '—', '' ], Anchor_Mail_Log_Admin::delivery( [ 'status' => 'sent' ] ) );
		$this->assertSame( '—', Anchor_Mail_Log_Admin::engagement( null, 0 ) );
		$this->assertStringContainsString( '(×2)', Anchor_Mail_Log_Admin::engagement( '2026-10-20 20:04:00', 2 ) );
	}

	public function test_enqueue_registers_assets_and_localizes_url() {
		Anchor_Mail_Log_Admin::enqueue();
		$this->assertTrue( wp_script_is( 'anchor-mail-log', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'anchor-mail-log', 'enqueued' ) );
		$data = wp_scripts()->get_data( 'anchor-mail-log', 'data' );
		$this->assertStringContainsString( 'admin-ajax.php', $data );
		$this->assertStringContainsString( 'anchorMailLog', $data );
	}
}
