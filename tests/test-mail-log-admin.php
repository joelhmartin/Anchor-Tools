<?php
/**
 * Mail log admin: view, resend and the reusable panel.
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

	public function test_resend_creates_linked_row() {
		$old = $this->seed(
			[
				'headers' => wp_json_encode(
					[
						'Content-Type: text/html; charset=UTF-8',
						'Bcc: staff@x.com',
						'X-Mailgun-Variables: {"anchor_mail_id":999}',
					]
				),
			]
		);

		$res = Anchor_Mail_Log_Admin::resend( $old, get_current_user_id() );

		$this->assertTrue( $res['ok'] );
		$new = Anchor_Mail_Log::get( $res['id'] );
		$this->assertSame( 'resend', $new['source'] );
		$this->assertSame( $old, (int) $new['resend_of'] );
		$this->assertSame( 'pat@example.com', $new['recipient'] );
		$this->assertSame( 22, (int) $new['seat_id'] );
		$this->assertSame( get_current_user_id(), (int) $new['sent_by'] );

		$blob = $this->sent_header_blob();
		$this->assertStringContainsString( 'Bcc: staff@x.com', $blob );
		$this->assertSame( 1, substr_count( $blob, 'X-Mailgun-Variables' ) );
		$this->assertStringContainsString( '"anchor_mail_id":' . $res['id'], $blob );
	}

	public function test_resend_of_resend_points_at_clicked_row() {
		$old   = $this->seed();
		$first = Anchor_Mail_Log_Admin::resend( $old, 1 );
		$again = Anchor_Mail_Log_Admin::resend( $first['id'], 1 );
		$this->assertTrue( $again['ok'] );
		$this->assertSame( $first['id'], (int) Anchor_Mail_Log::get( $again['id'] )['resend_of'] );
	}

	public function test_resend_of_imported_row() {
		$old = $this->seed( [ 'source' => 'import', 'headers' => '[]' ] );
		$res = Anchor_Mail_Log_Admin::resend( $old, 1 );
		$this->assertTrue( $res['ok'] );
		$mock = tests_retrieve_phpmailer_instance();
		$to   = $mock->get_recipient( 'to' );
		$this->assertSame( 'pat@example.com', $to->address );
	}

	public function test_resend_failure_is_logged_and_context_released() {
		$old = $this->seed();
		add_filter( 'pre_wp_mail', '__return_false' );
		$res = Anchor_Mail_Log_Admin::resend( $old, 1 );
		remove_filter( 'pre_wp_mail', '__return_false' );
		$this->assertFalse( $res['ok'] );
		$this->assertSame( 'failed', Anchor_Mail_Log::get( $res['id'] )['status'] );

		// Context must be disarmed: an unrelated mail is not logged.
		global $wpdb;
		$before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Anchor_Mail_Log::table() ); // phpcs:ignore
		wp_mail( 'x@example.com', 's', 'm' );
		$this->assertSame( $before, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Anchor_Mail_Log::table() ) ); // phpcs:ignore
	}

	public function test_resend_unknown_row() {
		$res = Anchor_Mail_Log_Admin::resend( 424242, 1 );
		$this->assertFalse( $res['ok'] );
	}

	public function test_resend_ajax_works_for_admin() {
		$old                = $this->seed();
		$_POST['id']        = $old;
		$_POST['nonce']     = wp_create_nonce( 'anchor_mail_log' );
		$out                = json_decode( $this->ajax( 'anchor_mail_log_resend' ), true );
		$this->assertTrue( $out['success'] );
		$this->assertStringContainsString( 'data-resend', $out['data']['row_html'] );
	}

	public function test_resend_ajax_requires_capability() {
		$old            = $this->seed();
		$this->_setRole( 'subscriber' );
		$_POST['id']    = $old;
		$_POST['nonce'] = wp_create_nonce( 'anchor_mail_log' );
		$out            = json_decode( $this->ajax( 'anchor_mail_log_resend' ), true );
		$this->assertFalse( $out['success'] );
		$this->assertSame( 0, (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . Anchor_Mail_Log::table() . " WHERE source='resend'" ) ); // phpcs:ignore
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

	public function test_filter_can_grant_manage() {
		$this->_setRole( 'subscriber' );
		$this->assertFalse( Anchor_Mail_Log_Admin::can_manage( [] ) );
		add_filter( 'anchor_mail_log_can_manage', '__return_true' );
		$this->assertTrue( Anchor_Mail_Log_Admin::can_manage( [] ) );
		remove_filter( 'anchor_mail_log_can_manage', '__return_true' );
	}

	public function test_panel_renders_states() {
		$a = $this->seed( [ 'kind' => 'confirmation', 'status' => 'delivered', 'delivered_at' => '2026-10-20 20:00:00', 'opened_at' => '2026-10-20 20:04:00', 'open_count' => 2 ] );
		$b = $this->seed( [ 'status' => 'bounced', 'status_detail' => 'mailbox "full" <550>' ] );
		$c = $this->seed( [ 'source' => 'import', 'subject' => 'Old FunnelKit mail', 'kind' => '' ] );
		$d = $this->seed( [ 'source' => 'resend', 'resend_of' => $a, 'sent_by' => 1, 'status' => 'failed', 'status_detail' => 'SMTP down' ] );

		$rows = array_map( [ 'Anchor_Mail_Log', 'get' ], [ $a, $b, $c, $d ] );
		$html = Anchor_Mail_Log_Admin::render_panel( $rows, [ 'confirmation' => 'Registration confirmation' ] );

		$this->assertStringContainsString( 'Emails', $html );
		$this->assertStringContainsString( 'Delivered', $html );
		$this->assertStringContainsString( '×2', $html );
		$this->assertStringContainsString( 'Registration confirmation', $html );
		$this->assertStringContainsString( 'Bounced', $html );
		$this->assertStringContainsString( 'title="' . esc_attr( 'mailbox "full" <550>' ) . '"', $html );
		$this->assertStringContainsString( 'Sent (before tracking)', $html );
		$this->assertStringContainsString( 'not tracked', $html );
		$this->assertStringContainsString( 'Old FunnelKit mail', $html );
		$this->assertStringContainsString( 'Resent by', $html );
		$this->assertStringContainsString( 'SMTP down', $html );
		$this->assertSame( 4, substr_count( $html, 'data-resend=' ) );
	}

	public function test_panel_empty_state() {
		$html = Anchor_Mail_Log_Admin::render_panel( [] );
		$this->assertStringContainsString( 'No emails logged for this registrant yet.', $html );
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
