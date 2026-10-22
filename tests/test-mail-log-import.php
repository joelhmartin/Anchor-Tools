<?php
/**
 * WP Mail Logging backfill into the mail log.
 *
 * @package Anchor\Events\Tests
 */

require_once dirname( __DIR__ ) . '/anchor-events-manager/class-email-log-import.php';

/**
 * @group email
 * @group mail-log
 */
class Test_Mail_Log_Import extends Anchor_Events_TestCase {

	private $wpml;

	public function set_up() {
		parent::set_up();
		global $wpdb;
		$this->wpml = $wpdb->prefix . 'wpml_mails';
		Anchor_Mail_Log::install();
		$wpdb->query( 'DELETE FROM ' . Anchor_Mail_Log::table() ); // phpcs:ignore
		$wpdb->query( "DROP TABLE IF EXISTS {$this->wpml}" ); // phpcs:ignore
		$wpdb->query( // phpcs:ignore
			"CREATE TABLE {$this->wpml} (
				mail_id INT NOT NULL AUTO_INCREMENT,
				timestamp DATETIME NOT NULL,
				receiver VARCHAR(255) NOT NULL DEFAULT '',
				subject VARCHAR(255) NOT NULL DEFAULT '',
				message TEXT NULL,
				headers TEXT NULL,
				error VARCHAR(255) NULL,
				PRIMARY KEY (mail_id)
			)"
		);
		update_option( 'timezone_string', 'America/Los_Angeles' );
	}

	public function tear_down() {
		global $wpdb;
		$wpdb->query( "DROP TABLE IF EXISTS {$this->wpml}" ); // phpcs:ignore
		delete_option( 'timezone_string' );
		parent::tear_down();
	}

	private function wpml_insert( $local_time, $receiver, $subject, $message = '<p>x</p>', $headers = '' ) {
		global $wpdb;
		$wpdb->insert(
			$this->wpml,
			[
				'timestamp' => $local_time,
				'receiver'  => $receiver,
				'subject'   => $subject,
				'message'   => $message,
				'headers'   => $headers,
				'error'     => '',
			]
		);
		return (int) $wpdb->insert_id;
	}

	private function import_count() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Anchor_Mail_Log::table() . " WHERE source = 'import'" ); // phpcs:ignore
	}

	public function test_clean_html_unwraps_bwfan_links() {
		$html = '<a href="https://tmjtherapycentre.com?bwfan-track-action=click&amp;bwfan-track-id=T&amp;bwfan-uid=U&amp;l_hash=H&amp;bwfan-link=https%3A%2F%2Fwww.marriott.com%2Fx%3Fa%3D1%26b%3D2">Book</a>'
			. '<img src="https://tmjtherapycentre.com?bwfan-track-action=open&amp;bwfan-track-id=T" width="1" height="1">'
			. '<p>keep <a href="https://tmjtherapycentre.com/?bwfan-action=unsubscribe&amp;uid=U&amp;automation_id=4&amp;sid=90">Unsubscribe</a> end</p>'
			. '<a href="https://tmjtherapycentre.com/">Home</a>'
			. '<img src="https://tmjtherapycentre.com/wp-content/uploads/logo.png"><a href="mailto:a@b.test">m</a>';
		$out  = Anchor_Mail_Log_Import::clean_html( $html );
		$this->assertStringContainsString( '<a href="https://www.marriott.com/x?a=1&b=2">Book</a>', $out );
		$this->assertStringNotContainsString( 'bwfan', $out );
		$this->assertStringNotContainsString( 'Unsubscribe', $out );
		$this->assertStringContainsString( '<p>keep  end</p>', $out );
		$this->assertStringContainsString( '<a href="https://tmjtherapycentre.com/">Home</a><img src="https://tmjtherapycentre.com/wp-content/uploads/logo.png"><a href="mailto:a@b.test">m</a>', $out );
	}

	public function test_clean_html_decodes_proofpoint() {
		$pp   = 'https://urldefense.proofpoint.com/v2/url?u=https-3A__app.marriott.com_reslink-3Fid-3D1773688776069-26key-3DGRP-26app-3Dresvlink&d=DwMFAg&c=abc';
		$out  = Anchor_Mail_Log_Import::clean_html( '<a href="' . esc_attr( $pp ) . '">x</a>' );
		$this->assertSame( '<a href="https://app.marriott.com/reslink?id=1773688776069&key=GRP&app=resvlink">x</a>', $out );
	}

	public function test_clean_html_handles_real_double_wrapped_link() {
		$href = 'https://tmjtherapycentre.com?bwfan-track-action=click&amp;bwfan-track-id=T&amp;bwfan-uid=U&amp;l_hash=H&amp;bwfan-link=https%3A%2F%2Furldefense.proofpoint.com%2Fv2%2Furl%3Fu%3Dhttps-3A__app.marriott.com_reslink-3Fid-3D1773688776069-26key-3DGRP-26app-3Dresvlink%26d%3DDwMFAg%26c%3Dabc%26r%3Dr%26m%3Dm%26s%3Ds%26e%3De';
		$out  = Anchor_Mail_Log_Import::clean_html( "<a class=\"b\" href=\"{$href}\" target=\"_blank\">Go</a>" );
		$this->assertSame( '<a class="b" href="https://app.marriott.com/reslink?id=1773688776069&key=GRP&app=resvlink" target="_blank">Go</a>', $out );
	}

	public function test_clean_html_leaves_plain_html_byte_identical() {
		$html = "<p>A &amp; B</p>\n<a href=\"https://x.test/?a=1&amp;b=2\">l</a>";
		$this->assertSame( $html, Anchor_Mail_Log_Import::clean_html( $html ) );
	}

	public function test_split_headers_drops_dead_headers_on_literal_and_real_newlines() {
		$raw = 'MIME-Version: 1.0,\\nContent-type:text/html;charset=UTF-8,\\nFrom: TMJ & Sleep <a@b.test>,\\nReply-To: a@b.test,\\nList-Unsubscribe: <https://x.test/?bwfan-action=unsubscribe>,\\nList-Unsubscribe-Post: List-Unsubscribe=One-Click';
		$exp = [ 'Content-type:text/html;charset=UTF-8', 'From: TMJ & Sleep <a@b.test>', 'Reply-To: a@b.test' ];
		$this->assertSame( $exp, Anchor_Mail_Log_Import::split_headers( $raw ) );
		$this->assertSame( $exp, Anchor_Mail_Log_Import::split_headers( str_replace( '\\n', "\n", $raw ) ) );
		$this->assertSame( $exp, Anchor_Mail_Log_Import::split_headers( str_replace( '\\n', ",\n", str_replace( ',\\n', '\\n', $raw ) ) ) );
	}

	public function test_import_is_idempotent() {
		$id   = $this->wpml_insert( '2026-09-01 10:00:00', 'a@example.test', 'Mini Residency Course', '<p>x</p>', 'From: x@y.test,\\nList-Unsubscribe: <u>' );
		$rows = Anchor_Mail_Log_Import::wpml_rows( 'A@Example.test', '2026-09-01 00:00:00', '2026-09-02 00:00:00' );
		$this->assertCount( 1, $rows );
		$ctx = [ 'event_id' => 5, 'seat_id' => 7, 'order_id' => 9, 'recipient' => 'a@example.test' ];
		$a   = Anchor_Mail_Log_Import::import_row( $rows[0], $ctx );
		$b   = Anchor_Mail_Log_Import::import_row( $rows[0], $ctx );
		$this->assertGreaterThan( 0, $a );
		$this->assertNull( $b );
		$this->assertSame( 1, $this->import_count() );

		$row = Anchor_Mail_Log::get( $a );
		$this->assertSame( 'import', $row['source'] );
		$this->assertSame( (string) $id, $row['source_ref'] );
		$this->assertSame( 'sent', $row['status'] );
		$this->assertSame( 'legacy.funnelkit', $row['kind'] );
		$this->assertSame( '2026-09-01 17:00:00', $row['created_at'], 'Site-local PT time is stored as GMT.' );
		$this->assertSame( [ 'From: x@y.test' ], json_decode( $row['headers'], true ) );
	}

	public function test_wpml_rows_window_is_utc_and_receiver_contains() {
		$this->wpml_insert( '2026-09-01 10:00:00', 'Name <A@Example.test>, b@example.test', 'S' );  // 17:00 UTC.
		$this->wpml_insert( '2026-09-01 10:00:00', 'other@example.test', 'S' );
		$this->assertCount( 1, Anchor_Mail_Log_Import::wpml_rows( 'a@example.test', '2026-09-01 16:59:00', '2026-09-01 17:01:00' ) );
		$this->assertCount( 0, Anchor_Mail_Log_Import::wpml_rows( 'a@example.test', '2026-09-01 10:59:00', '2026-09-01 11:01:00' ) );
	}

	public function test_receiver_match_is_exact() {
		$this->wpml_insert( '2026-09-01 10:00:00', 'joann@x.com', 'a' );
		$this->wpml_insert( '2026-09-01 10:00:00', 'ann@x.com.au', 'b' );
		$this->wpml_insert( '2026-09-01 10:00:00', 'Ann <ANN@x.com>', 'c' );
		$this->wpml_insert( '2026-09-01 10:00:00', 'bob@x.com, ann@x.com', 'd' );
		$rows = Anchor_Mail_Log_Import::wpml_rows( 'ann@x.com', '2026-09-01 16:00:00', '2026-09-01 18:00:00' );
		$this->assertSame( [ 'c', 'd' ], wp_list_pluck( $rows, 'subject' ) );
	}

	public function test_receiver_match_handles_stored_formats() {
		$this->wpml_insert( '2026-09-01 10:00:00', 'staff@x.com,\n ann@x.com', 'lit' );
		$this->wpml_insert( '2026-09-01 10:00:00', 'Ann &lt;ann@x.com&gt;', 'ent' );
		$this->wpml_insert( '2026-09-01 10:00:00', "bob@x.com;\r\nann@x.com", 'real' );
		$this->wpml_insert( '2026-09-01 10:00:00', 'staff@x.com,\n joann@x.com', 'no' );
		$rows = Anchor_Mail_Log_Import::wpml_rows( 'ann@x.com', '2026-09-01 16:00:00', '2026-09-01 18:00:00' );
		$this->assertSame( [ 'lit', 'ent', 'real' ], wp_list_pluck( $rows, 'subject' ) );
	}

	public function test_kind_for_subject() {
		$this->assertSame( 'wc.customer_processing_order', Anchor_Mail_Log_Import::kind_for_subject( 'Thank You For Registering!' ) );
		$this->assertSame( 'legacy.funnelkit', Anchor_Mail_Log_Import::kind_for_subject( 'Mini Residency Course Details' ) );
		$this->assertSame( 'events.cancellation', Anchor_Mail_Log_Import::kind_for_subject( 'Your registration for Adjunct Mini Residency – Toronto — Nov 13, 2026 has been cancelled' ) );
		$this->assertSame( 'events.cancellation', Anchor_Mail_Log_Import::kind_for_subject( 'Your registration for X has been refunded' ) );
		$this->assertSame( 'events.wc_confirmation', Anchor_Mail_Log_Import::kind_for_subject( "You\u{2019}re registered: X" ) );
		$this->assertSame( 'events.wc_confirmation', Anchor_Mail_Log_Import::kind_for_subject( "You're registered" ) );
		$this->assertSame( 'imported', Anchor_Mail_Log_Import::kind_for_subject( 'Something else' ) );
		foreach ( [ 'Your account has been created', 'Reset your Password', 'Document Viewed', 'Your doc has been signed', '[TMJ] x', 'New order #5', 'Your login confirmation code', 'Verification code: 123456', 'Your one-time code', 'Security code for TMJ' ] as $s ) {
			$this->assertTrue( Anchor_Mail_Log_Import::is_excluded( $s ), $s );
		}
		$this->assertFalse( Anchor_Mail_Log_Import::is_excluded( 'Thank You For Registering!' ) );
	}

	public function test_cli_matches_order_window_and_skips_excluded() {
		$this->require_wc();
		$event_id = $this->make_event( [ 'title' => 'Mini Residency Toronto' ] );
		$order    = wc_create_order();
		$order->set_billing_email( 'buyer@example.test' );
		$order->set_date_created( strtotime( '2026-09-01 17:00:00 UTC' ) );
		$order->save();
		$seat = $this->make_seat( $event_id, [ 'order_id' => $order->get_id(), 'email' => 'attendee@example.test' ] );
		// Cutover-style seat: created long after the order.
		wp_update_post( [ 'ID' => $seat, 'post_date' => '2026-10-08 09:00:00', 'post_date_gmt' => '2026-10-08 16:00:00' ] );

		$this->wpml_insert( '2026-09-01 10:00:30', 'buyer@example.test', 'Thank You For Registering!' );          // order-level.
		$this->wpml_insert( '2026-09-01 10:02:00', 'attendee@example.test', 'Mini Residency Course', '<p>x</p>' ); // seat.
		$this->wpml_insert( '2026-09-01 10:03:00', 'buyer@example.test', 'Your account has been created' );
		$this->wpml_insert( '2026-08-29 10:00:00', 'buyer@example.test', 'Unrelated newsletter' );

		$importer = new \Anchor\Events\Email_Log_Import();
		$dry      = $importer->run( [ 'event' => $event_id, 'before' => '2026-10-08 00:00:00' ], false );
		$this->assertSame( 0, $this->import_count(), 'Dry run writes nothing.' );
		$actions = wp_list_pluck( $dry['rows'], 'action' );
		$this->assertSame( [ 'CREATE', 'CREATE', 'SKIP-EXCLUDED' ], $actions );

		$res = $importer->run( [ 'event' => $event_id, 'before' => '2026-10-08 00:00:00' ], true );
		$this->assertSame( 2, $res['created'] );
		$rows = array_reverse( Anchor_Mail_Log::query( [] ) );
		$this->assertCount( 2, $rows );
		$by = [];
		foreach ( $rows as $r ) {
			$by[ $r['kind'] ] = $r;
		}
		$this->assertSame( 0, (int) $by['wc.customer_processing_order']['seat_id'], 'Billing-email mail is order-level.' );
		$this->assertSame( 'buyer@example.test', $by['wc.customer_processing_order']['recipient'] );
		$this->assertSame( $seat, (int) $by['legacy.funnelkit']['seat_id'] );
		foreach ( $by as $r ) {
			$this->assertSame( $order->get_id(), (int) $r['order_id'] );
			$this->assertSame( $event_id, (int) $r['event_id'] );
		}

		$again = $importer->run( [ 'event' => $event_id, 'before' => '2026-10-08 00:00:00' ], true );
		$this->assertSame( 0, $again['created'] );
		$this->assertSame( 2, $this->import_count() );
	}

	public function test_window_b_requires_event_mention() {
		$this->require_wc();
		$event_id = $this->make_event( [ 'title' => 'Alpha Course' ] );
		$seat     = $this->make_seat( $event_id, [ 'email' => 'p@example.test' ] );
		wp_update_post( [ 'ID' => $seat, 'post_date' => '2026-09-01 00:00:00', 'post_date_gmt' => '2026-09-01 07:00:00' ] );
		$this->wpml_insert( '2026-09-05 10:00:00', 'p@example.test', 'Hello', '<p>Join Alpha Course soon</p>' );
		$this->wpml_insert( '2026-09-06 10:00:00', 'p@example.test', 'Hello', '<p>Nothing relevant</p>' );
		$this->wpml_insert( '2026-09-07 10:00:00', 'p@example.test', 'Details: Alpha Course', '<p>x</p>' );
		$res = ( new \Anchor\Events\Email_Log_Import() )->run( [ 'event' => $event_id, 'before' => '2026-10-01 00:00:00' ], true );
		$this->assertSame( 2, $res['created'] );
		foreach ( Anchor_Mail_Log::query( [] ) as $r ) {
			$this->assertSame( $seat, (int) $r['seat_id'] );
			$this->assertSame( 'imported', $r['kind'] );
		}
	}
}
