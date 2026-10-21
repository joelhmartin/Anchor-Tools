<?php
/**
 * Mail log admin: view endpoint, cell helpers and assets.
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

	private function ajax( $action ) {
		$this->_last_response = '';
		try {
			$this->_handleAjax( $action );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		} catch ( WPAjaxDieStopException $e ) {
			return $e->getMessage();
		}
		return $this->_last_response;
	}

	public function test_view_requires_capability() {
		$old               = $this->seed();
		$this->_setRole( 'subscriber' );
		$_GET['id']        = $old;
		$_GET['_wpnonce']  = wp_create_nonce( 'anchor_mail_log' );
		$out               = $this->ajax( 'anchor_mail_log_view' );
		$this->assertStringNotContainsString( 'Hello Pat', $out );
	}

	public function test_view_returns_html_for_admin() {
		$old               = $this->seed();
		$_GET['id']        = $old;
		$_GET['_wpnonce']  = wp_create_nonce( 'anchor_mail_log' );
		$out               = $this->ajax( 'anchor_mail_log_view' );
		$this->assertStringContainsString( 'Hello Pat', $out );
	}

	public function test_view_strips_sign_in_token_but_not_stored_row() {
		$html              = '<a href="https://x.test/live/?aek=abc&amp;x=1">Join</a> <a href=\'https://x.test/live/?aek=zzz\'>J2</a>';
		$old               = $this->seed( [ 'html' => $html ] );
		$_GET['id']        = $old;
		$_GET['_wpnonce']  = wp_create_nonce( 'anchor_mail_log' );
		$out               = $this->ajax( 'anchor_mail_log_view' );
		$this->assertStringNotContainsString( 'aek', $out );
		$this->assertStringContainsString( 'https://x.test/live/?x=1', $out );
		$this->assertStringContainsString( "href='https://x.test/live/'", $out );
		$this->assertSame( $html, Anchor_Mail_Log::get( $old )['html'], 'Stored row is untouched.' );
	}

	public function test_filter_can_grant_manage() {
		$this->_setRole( 'subscriber' );
		$this->assertFalse( Anchor_Mail_Log_Admin::can_manage( [] ) );
		add_filter( 'anchor_mail_log_can_manage', '__return_true' );
		$this->assertTrue( Anchor_Mail_Log_Admin::can_manage( [] ) );
		remove_filter( 'anchor_mail_log_can_manage', '__return_true' );
	}

	public function test_cell_helpers() {
		$delivered = Anchor_Mail_Log::get( $this->seed( [ 'status' => 'delivered', 'delivered_at' => '2026-10-20 20:00:00' ] ) );
		$this->assertStringStartsWith( 'Yes · ', Anchor_Mail_Log_Admin::delivery( $delivered )[0] );
		$this->assertSame( [ 'Bounced', 'red' ], Anchor_Mail_Log_Admin::delivery( [ 'status' => 'bounced' ] ) );
		$this->assertSame( [ '—', '' ], Anchor_Mail_Log_Admin::delivery( [ 'status' => 'sent' ] ) );
		$this->assertSame( '—', Anchor_Mail_Log_Admin::engagement( null, 0 ) );
		$this->assertStringContainsString( '(×2)', Anchor_Mail_Log_Admin::engagement( '2026-10-20 20:04:00', 2 ) );
		$this->assertStringContainsString( 'action=anchor_mail_log_view', Anchor_Mail_Log_Admin::view_url( 5 ) );
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
