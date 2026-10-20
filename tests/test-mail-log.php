<?php
/**
 * Mail log capture, tagging and status precedence.
 *
 * @package Anchor_Tools\Tests
 */

/**
 * @group email
 * @group mail-log
 */
class Test_Mail_Log extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Anchor_Mail_Log::install();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Anchor_Mail_Log::table() ); // phpcs:ignore
		reset_phpmailer_instance();
	}

	public function tear_down() {
		Anchor_Mail_Log::finish( true );
		reset_phpmailer_instance();
		parent::tear_down();
	}

	private function count_rows() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Anchor_Mail_Log::table() ); // phpcs:ignore
	}

	private function sent_headers() {
		$mock = tests_retrieve_phpmailer_instance();
		// PHPMailer RFC2047-encodes the JSON header in the raw string; decode for assertions.
		return $mock->get_sent() ? $this->decode_header( $mock->get_sent()->header ) : '';
	}

	private function decode_header( $raw ) {
		$raw = preg_replace( '/\?=\r\n =\?us-ascii\?Q\?/', '', $raw ); // unfold encoded words.
		return preg_replace_callback(
			'/=\?us-ascii\?Q\?(.*?)\?=/',
			static function ( $m ) {
				return quoted_printable_decode( str_replace( '_', ' ', $m[1] ) );
			},
			$raw
		);
	}

	public function test_install_creates_table() {
		global $wpdb;
		$this->assertSame( Anchor_Mail_Log::table(), $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Anchor_Mail_Log::table() ) ) );
	}

	public function test_unarmed_mail_is_not_logged() {
		wp_mail( 'a@example.com', 'S', 'body' );
		$this->assertSame( 0, $this->count_rows() );
		$this->assertStringNotContainsString( 'X-Mailgun', $this->sent_headers() );
	}

	public function test_armed_mail_is_logged_and_tagged() {
		Anchor_Mail_Log::begin( [ 'context' => 'test', 'kind' => 'k', 'event_id' => 5, 'seat_id' => 7 ] );
		wp_mail( 'A@Example.com', 'S', '<p>x</p>', [ 'Content-Type: text/html; charset=UTF-8' ] );
		$id = Anchor_Mail_Log::finish( true );

		$this->assertSame( 1, $this->count_rows() );
		$row = Anchor_Mail_Log::get( $id );
		$this->assertSame( 'a@example.com', $row['recipient'] );
		$this->assertSame( 'sent', $row['status'] );
		$this->assertSame( '<p>x</p>', $row['html'] );
		$this->assertSame( '5', $row['event_id'] );
		$this->assertSame( '7', $row['seat_id'] );

		$headers = $this->sent_headers();
		$this->assertStringContainsString( '"anchor_mail_id":' . $id, $headers );
		$this->assertStringContainsString( 'X-Mailgun-Track-Opens: yes', $headers );
	}

	public function test_existing_tracking_headers_are_replaced() {
		Anchor_Mail_Log::begin( [ 'context' => 'test' ] );
		wp_mail( 'a@example.com', 'S', 'x', "X-Mailgun-Track: no\nX-Mailgun-Variables: {\"a\":1}" );
		Anchor_Mail_Log::finish( true );

		$headers = $this->sent_headers();
		$this->assertSame( 1, substr_count( $headers, 'X-Mailgun-Track: yes' ) );
		$this->assertStringNotContainsString( 'X-Mailgun-Track: no', $headers );
		$this->assertStringNotContainsString( '{"a":1}', $headers );
	}

	public function test_failed_send_marks_row_failed() {
		add_filter(
			'pre_wp_mail',
			static function () {
				do_action( 'wp_mail_failed', new WP_Error( 'x', 'boom' ) );
				return false;
			}
		);
		Anchor_Mail_Log::begin( [ 'context' => 'test' ] );
		$ok = wp_mail( 'a@example.com', 'S', 'x' );
		$id = Anchor_Mail_Log::finish( $ok );

		$this->assertFalse( $ok );
		$this->assertSame( 1, $this->count_rows() );
		$row = Anchor_Mail_Log::get( $id );
		$this->assertSame( 'failed', $row['status'] );
		$this->assertStringContainsString( 'boom', $row['status_detail'] );
	}

	public function test_finish_creates_row_when_wp_mail_never_ran() {
		Anchor_Mail_Log::begin( [ 'context' => 'test', 'seat_id' => 3 ] );
		$id = Anchor_Mail_Log::finish( false, 'early' );
		$row = Anchor_Mail_Log::get( $id );
		$this->assertSame( 'failed', $row['status'] );
		$this->assertSame( 'early', $row['status_detail'] );
	}

	public function test_status_precedence() {
		$id = Anchor_Mail_Log::insert( [ 'recipient' => 'a@example.com' ] );
		Anchor_Mail_Log::apply_event( $id, 'opened', '2026-01-01 10:00:00' );
		Anchor_Mail_Log::apply_event( $id, 'delivered', '2026-01-01 10:00:05' );
		Anchor_Mail_Log::apply_event( $id, 'temporary_fail', '2026-01-01 10:00:09' );
		$row = Anchor_Mail_Log::get( $id );
		$this->assertSame( 'delivered', $row['status'] );
		$this->assertSame( '1', $row['open_count'] );
		$this->assertSame( '2026-01-01 10:00:09', $row['last_event_at'] );

		Anchor_Mail_Log::apply_event( $id, 'permanent_fail', '2026-01-01 11:00:00' );
		$this->assertSame( 'bounced', Anchor_Mail_Log::get( $id )['status'] );
		Anchor_Mail_Log::apply_event( $id, 'delivered', '2026-01-01 11:00:01' );
		$this->assertSame( 'bounced', Anchor_Mail_Log::get( $id )['status'] );
	}

	public function test_query_seat_or_order() {
		$a = Anchor_Mail_Log::insert( [ 'seat_id' => 7 ] );
		$b = Anchor_Mail_Log::insert( [ 'order_id' => 9, 'event_id' => 5 ] );
		$c = Anchor_Mail_Log::insert( [ 'order_id' => 9, 'event_id' => 0 ] );
		Anchor_Mail_Log::insert( [ 'order_id' => 9, 'event_id' => 6 ] );
		Anchor_Mail_Log::insert( [ 'seat_id' => 8 ] );

		$ids = array_map( 'intval', wp_list_pluck( Anchor_Mail_Log::query( [ 'seat_or_order' => [ 7, 9, 5 ] ] ), 'id' ) );
		sort( $ids );
		$this->assertSame( [ $a, $b, $c ], $ids );
	}

	public function test_query_seat_without_order_ignores_orderless_seatless_rows() {
		$mine  = Anchor_Mail_Log::insert( [ 'seat_id' => 7, 'order_id' => 0 ] );
		$stray = Anchor_Mail_Log::insert( [ 'seat_id' => 0, 'order_id' => 0, 'event_id' => 0 ] );
		$this->assertNotSame( $mine, $stray );

		$ids = array_map( 'intval', wp_list_pluck( Anchor_Mail_Log::query( [ 'seat_or_order' => [ 7, 0, 5 ] ] ), 'id' ) );
		$this->assertSame( [ $mine ], $ids );
	}

	public function test_apply_event_is_single_statement_rank_guarded() {
		global $wpdb;
		$id = Anchor_Mail_Log::insert( [ 'status' => 'delivered', 'recipient' => 'a@example.com' ] );

		// Stale lower-rank event against a row we never read first.
		$this->assertTrue( Anchor_Mail_Log::apply_event( $id, 'temporary_fail', '2026-01-01 10:00:00', 'late' ) );
		$row = $wpdb->get_row( 'SELECT status, status_detail FROM ' . Anchor_Mail_Log::table() . ' WHERE id = ' . (int) $id, ARRAY_A ); // phpcs:ignore
		$this->assertSame( 'delivered', $row['status'] );
		$this->assertNotSame( 'late', $row['status_detail'] );

		Anchor_Mail_Log::apply_event( $id, 'opened', '2026-01-01 10:00:01' );
		Anchor_Mail_Log::apply_event( $id, 'opened', '2026-01-01 10:00:02' );
		$row = Anchor_Mail_Log::get( $id );
		$this->assertSame( '2', $row['open_count'] );
		$this->assertSame( '2026-01-01 10:00:01', $row['opened_at'] );

		$this->assertFalse( Anchor_Mail_Log::apply_event( 999999, 'opened', '2026-01-01 10:00:00' ) );
	}

	public function test_failed_send_without_message_gets_default_detail() {
		Anchor_Mail_Log::begin( [ 'context' => 'test' ] );
		$id = Anchor_Mail_Log::finish( false );
		$this->assertSame( 'wp_mail returned false', Anchor_Mail_Log::get( $id )['status_detail'] );
	}
}
