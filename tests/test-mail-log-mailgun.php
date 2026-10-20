<?php
/**
 * Mailgun webhook receiver for the mail log.
 *
 * @package Anchor_Tools\Tests
 */

/**
 * @group email
 * @group mail-log
 */
class Test_Mail_Log_Mailgun extends WP_UnitTestCase {

	const KEY = 'key-test';

	private $id;

	public function set_up() {
		parent::set_up();
		Anchor_Mail_Log::install();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Anchor_Mail_Log::table() ); // phpcs:ignore
		Anchor_Mail_Log_Mailgun::save_signing_key( self::KEY );
		$this->id = Anchor_Mail_Log::insert(
			[
				'recipient' => 'attendee@example.com',
				'subject'   => 'S',
			]
		);
	}

	public function tear_down() {
		delete_option( Anchor_Mail_Log_Mailgun::OPTION );
		parent::tear_down();
	}

	public function test_replayed_token_is_acknowledged_but_not_applied() {
		$first  = $this->post( [ 'event' => 'delivered' ], [], 0, self::KEY, 'replay-tok' );
		$second = $this->post( [ 'event' => 'opened' ], [], 0, self::KEY, 'replay-tok' );
		$this->assertSame( 200, $first->get_status() );
		$this->assertSame( 200, $second->get_status() );
		$this->assertFalse( $second->get_data()['applied'] );
		$row = Anchor_Mail_Log::get( $this->id );
		$this->assertSame( 'delivered', $row['status'] );
		$this->assertSame( 0, (int) ( $row['open_count'] ?? 0 ) );
	}

	public function test_token_claim_db_error_is_retryable() {
		$break = static function ( $q ) {
			return ( false !== strpos( $q, 'INSERT IGNORE' ) && false !== strpos( $q, 'anchor_mg_tok_' ) ) ? 'INSERT INTO anchor_no_such_table VALUES (1)' : $q;
		};
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		add_filter( 'query', $break );
		$res = $this->post( [ 'event' => 'delivered' ] );
		remove_filter( 'query', $break );
		$wpdb->suppress_errors( $suppress );
		$this->assertSame( 503, $res->get_status() );
		$this->assertSame( 'sent', Anchor_Mail_Log::get( $this->id )['status'] );
	}

	public function test_apply_db_error_releases_token_for_retry() {
		$table = Anchor_Mail_Log::table();
		$break = static function ( $q ) use ( $table ) {
			return ( 0 === strpos( ltrim( $q ), 'UPDATE' ) && false !== strpos( $q, $table ) ) ? 'UPDATE anchor_no_such_table SET x = 1' : $q;
		};
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		add_filter( 'query', $break );
		$first = $this->post( [ 'event' => 'delivered' ], [], 0, self::KEY, 'retry-tok' );
		remove_filter( 'query', $break );
		$wpdb->suppress_errors( $suppress );
		$this->assertSame( 503, $first->get_status() );
		$retry = $this->post( [ 'event' => 'delivered' ], [], 0, self::KEY, 'retry-tok' );
		$this->assertSame( 200, $retry->get_status() );
		$this->assertTrue( $retry->get_data()['applied'] );
		$this->assertSame( 'delivered', Anchor_Mail_Log::get( $this->id )['status'] );
	}

	private function post( array $event, array $sig_over = [], $ts_offset = 0, $key = self::KEY, $token = '' ) {
		$ts    = (string) ( time() + $ts_offset );
		$token = '' !== $token ? $token : uniqid( 'tok', true );
		$sig   = array_merge(
			[
				'timestamp' => $ts,
				'token'     => $token,
				'signature' => hash_hmac( 'sha256', $ts . $token, $key ),
			],
			$sig_over
		);
		$event += [
			'recipient'      => 'attendee@example.com',
			'timestamp'      => time(),
			'user-variables' => [
				'anchor_mail_id' => $this->id,
				'anchor_site'    => Anchor_Mail_Log::site_tag(),
			],
		];
		$req = new WP_REST_Request( 'POST', '/anchor-tools/v1/mail-log/mailgun' );
		$req->set_header( 'content-type', 'application/json' );
		$req->set_body( wp_json_encode( [ 'signature' => $sig, 'event-data' => $event ] ) );
		return rest_do_request( $req );
	}

	public function test_valid_delivered_updates_row() {
		$res = $this->post( [ 'event' => 'delivered' ] );
		$this->assertSame( 200, $res->get_status() );
		$this->assertTrue( $res->get_data()['applied'] );
		$row = Anchor_Mail_Log::get( $this->id );
		$this->assertSame( 'delivered', $row['status'] );
		$this->assertNotEmpty( $row['delivered_at'] );
	}

	public function test_string_mail_id_accepted() {
		$res = $this->post(
			[
				'event'          => 'opened',
				'user-variables' => [ 'anchor_mail_id' => (string) $this->id, 'anchor_site' => Anchor_Mail_Log::site_tag() ],
			]
		);
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 1, (int) Anchor_Mail_Log::get( $this->id )['open_count'] );
	}

	public function test_bad_signature_401() {
		$res = $this->post( [ 'event' => 'delivered' ], [ 'signature' => 'nope' ] );
		$this->assertSame( 401, $res->get_status() );
		$this->assertSame( 'sent', Anchor_Mail_Log::get( $this->id )['status'] );
	}

	public function test_stale_timestamp_401() {
		$res = $this->post( [ 'event' => 'delivered' ], [], -1000 );
		$this->assertSame( 401, $res->get_status() );
	}

	public function test_no_key_configured_401() {
		delete_option( Anchor_Mail_Log_Mailgun::OPTION );
		$res = $this->post( [ 'event' => 'delivered' ], [], 0, '' );
		$this->assertSame( 401, $res->get_status() );
	}

	public function test_foreign_site_is_ignored() {
		$res = $this->post(
			[
				'event'          => 'delivered',
				'user-variables' => [ 'anchor_mail_id' => $this->id, 'anchor_site' => 'staging.example.com' ],
			]
		);
		$this->assertSame( 200, $res->get_status() );
		$this->assertFalse( $res->get_data()['applied'] );
		$this->assertSame( 'sent', Anchor_Mail_Log::get( $this->id )['status'] );
	}

	public function test_no_mail_id_is_ignored() {
		$res = $this->post( [ 'event' => 'delivered', 'user-variables' => [] ] );
		$this->assertSame( 200, $res->get_status() );
		$this->assertFalse( $res->get_data()['applied'] );
	}

	public function test_event_for_other_recipient_is_ignored() {
		$res = $this->post( [ 'event' => 'opened', 'recipient' => 'education@x.com' ] );
		$this->assertSame( 200, $res->get_status() );
		$this->assertFalse( $res->get_data()['applied'] );
		$this->assertSame( 0, (int) Anchor_Mail_Log::get( $this->id )['open_count'] );
	}

	public function test_recipient_match_is_case_insensitive() {
		$this->post( [ 'event' => 'opened', 'recipient' => 'Attendee@Example.com' ] );
		$this->assertSame( 1, (int) Anchor_Mail_Log::get( $this->id )['open_count'] );
	}

	public function test_missing_row_is_ignored() {
		$res = $this->post(
			[
				'event'          => 'delivered',
				'user-variables' => [ 'anchor_mail_id' => 999999, 'anchor_site' => Anchor_Mail_Log::site_tag() ],
			]
		);
		$this->assertSame( 200, $res->get_status() );
		$this->assertFalse( $res->get_data()['applied'] );
	}

	public function test_failed_permanent_is_bounced_temporary_is_deferred() {
		$this->post( [ 'event' => 'failed', 'severity' => 'temporary', 'delivery-status' => [ 'message' => 'try later', 'code' => 451 ] ] );
		$row = Anchor_Mail_Log::get( $this->id );
		$this->assertSame( 'deferred', $row['status'] );
		$this->assertStringContainsString( 'try later', $row['status_detail'] );
		$this->post( [ 'event' => 'failed', 'severity' => 'permanent', 'delivery-status' => [ 'description' => 'no mailbox' ] ] );
		$this->assertSame( 'bounced', Anchor_Mail_Log::get( $this->id )['status'] );
	}

	public function test_status_never_downgrades() {
		$this->post( [ 'event' => 'opened' ] );
		$this->post( [ 'event' => 'delivered' ] );
		$this->post( [ 'event' => 'failed', 'severity' => 'temporary' ] );
		$row = Anchor_Mail_Log::get( $this->id );
		$this->assertSame( 'delivered', $row['status'] );
		$this->assertSame( 1, (int) $row['open_count'] );
	}

	public function test_unknown_event_is_ignored_200() {
		$res = $this->post( [ 'event' => 'accepted' ] );
		$this->assertSame( 200, $res->get_status() );
		$this->assertFalse( $res->get_data()['applied'] );
	}

	public function test_key_helpers() {
		$this->assertSame( self::KEY, Anchor_Mail_Log_Mailgun::signing_key() );
		$this->assertStringContainsString( 'anchor-tools/v1/mail-log/mailgun', Anchor_Mail_Log_Mailgun::webhook_url() );
	}
}
