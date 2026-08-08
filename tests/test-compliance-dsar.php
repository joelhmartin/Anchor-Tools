<?php
/**
 * Anchor Compliance — privacy (DSAR) requests.
 */
class Test_Compliance_Dsar extends WP_UnitTestCase {

	private $dsar;

	public function set_up() {
		parent::set_up();
		Anchor_Compliance_Dsar::install();
		$this->dsar = new Anchor_Compliance_Dsar();
		$GLOBALS['wpdb']->query( 'TRUNCATE TABLE ' . Anchor_Compliance_Dsar::table() );
	}

	public function tear_down() {
		delete_option( Anchor_Compliance_Module::OPTION_KEY );
		parent::tear_down();
	}

	public function test_table_is_created() {
		global $wpdb;
		$table = Anchor_Compliance_Dsar::table();

		// NOTE: WP_UnitTestCase::start_transaction() rewrites every
		// `CREATE TABLE` into `CREATE TEMPORARY TABLE` for test isolation
		// (wordpress-tests-lib/includes/abstract-testcase.php), and MySQL's
		// `SHOW TABLES` never lists temporary tables — even within the
		// session that created them. So `SHOW TABLES LIKE` is a permanent
		// false negative here regardless of whether install() worked.
		// `DESCRIBE` does see temp tables (it looks the name up directly),
		// so it is the reliable existence + shape check in this harness.
		$columns = wp_list_pluck( $wpdb->get_results( "DESCRIBE {$table}" ), 'Field' );
		$this->assertContains( 'ip_hash', $columns );
		$this->assertContains( 'deadline', $columns );
		$this->assertContains( 'status', $columns );
	}

	public function test_create_stores_a_request_with_a_deadline() {
		update_option( Anchor_Compliance_Module::OPTION_KEY, [ 'dsar' => [ 'enabled' => true, 'response_days' => 45 ] ], false );

		$id = $this->dsar->create( [
			'type' => 'delete', 'email' => 'user@example.com', 'name' => 'A User', 'details' => 'Please delete my data.',
		] );
		$this->assertIsInt( $id );

		$row = $this->dsar->get( $id );
		$this->assertSame( 'delete', $row->type );
		$this->assertSame( 'user@example.com', $row->email );
		$this->assertSame( 'new', $row->status );
		$this->assertSame(
			gmdate( 'Y-m-d', strtotime( '+45 days' ) ),
			gmdate( 'Y-m-d', strtotime( $row->deadline ) )
		);
	}

	public function test_invalid_type_is_rejected() {
		$res = $this->dsar->create( [ 'type' => 'nonsense', 'email' => 'a@b.com' ] );
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	public function test_invalid_email_is_rejected() {
		$res = $this->dsar->create( [ 'type' => 'access', 'email' => 'not-an-email' ] );
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	public function test_details_are_stored_as_plain_text() {
		$id  = $this->dsar->create( [ 'type' => 'access', 'email' => 'a@b.com', 'details' => '<script>alert(1)</script>hi' ] );
		$row = $this->dsar->get( $id );
		$this->assertStringNotContainsString( '<script>', $row->details );
		$this->assertStringContainsString( 'hi', $row->details );
	}

	public function test_rate_limit_blocks_a_rapid_second_request() {
		$this->dsar->create( [ 'type' => 'access', 'email' => 'a@b.com' ] );
		$res = $this->dsar->create( [ 'type' => 'access', 'email' => 'a@b.com' ] );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'rate_limited', $res->get_error_code() );
	}

	public function test_shortcode_renders_the_form_with_a_nonce_and_honeypot() {
		$out = do_shortcode( '[anchor_privacy_request]' );
		$this->assertStringContainsString( 'anchor_compliance_dsar', $out );
		$this->assertStringContainsString( '_wpnonce', $out );
		$this->assertStringContainsString( 'anchor_cmp_hp', $out, 'A honeypot field is required.' );
		foreach ( [ 'access', 'delete', 'correct', 'optout' ] as $type ) {
			$this->assertStringContainsString( 'value="' . $type . '"', $out );
		}
	}

	public function test_shortcode_reports_when_disabled() {
		update_option( Anchor_Compliance_Module::OPTION_KEY, [ 'dsar' => [ 'enabled' => false ] ], false );
		$this->assertStringNotContainsString( '<form', do_shortcode( '[anchor_privacy_request]' ) );
	}

	public function test_status_update_is_validated() {
		$id = $this->dsar->create( [ 'type' => 'access', 'email' => 'a@b.com' ] );
		$this->assertTrue( $this->dsar->set_status( $id, 'completed' ) );
		$this->assertSame( 'completed', $this->dsar->get( $id )->status );
		$this->assertFalse( $this->dsar->set_status( $id, 'bogus' ) );
	}

	/**
	 * Review fix 1: sanitize_email() trims but does not lowercase, so the old
	 * rate-limit key ( md5( $email . '|' . $ip ) ) let a public, unauthenticated
	 * requester bypass the limiter — and double the wp_mail() sends in
	 * notify() — just by varying case. The key must be built from a
	 * lowercased, trimmed email so "a@b.com" and "A@B.com" collide.
	 */
	public function test_rate_limit_blocks_a_case_varied_email() {
		$this->dsar->create( [ 'type' => 'access', 'email' => 'a@b.com' ] );
		$res = $this->dsar->create( [ 'type' => 'access', 'email' => 'A@B.com' ] );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'rate_limited', $res->get_error_code() );
	}

	/**
	 * Review fix 2: is_email() allows '%' in the local part (RFC 5322 atext),
	 * so a genuine address can carry one. where() used to return an
	 * already-$wpdb->prepare()'d fragment that query() then fed into a SECOND
	 * prepare() as part of its format string — a stray '%' in that fragment
	 * became an unmatched conversion specifier, which is a fatal format error
	 * on WordPress 6.2+. where() must return [ $sql, $args ] and query() must
	 * do exactly one prepare().
	 */
	public function test_query_and_export_handle_a_percent_sign_in_the_email() {
		$email = 'quoted%40name@example.com';
		$this->assertNotFalse( is_email( $email ), 'Test assumes is_email() accepts a % in the local part.' );

		$id = $this->dsar->create( [ 'type' => 'access', 'email' => $email ] );
		$this->assertIsInt( $id );

		$rows = $this->dsar->query( [ 'email' => $email ] );
		$this->assertCount( 1, $rows );
		$this->assertSame( $email, $rows[0]->email );

		$export = $this->dsar->privacy_export( $email );
		$this->assertCount( 1, $export['data'] );
	}

	/**
	 * Review fix 4: process_submission() is handle_submit() minus the
	 * wp_safe_redirect()+exit that makes the real admin-post entry point
	 * unreachable from PHPUnit (same constraint documented in
	 * tests/test-event-manager-save.php). A filled honeypot must report the
	 * exact same outcome as a genuine success ('ok') while creating zero rows
	 * and sending no mail — proving a bot learns nothing from the response.
	 */
	public function test_honeypot_reports_ok_but_creates_no_row() {
		$nonce = wp_create_nonce( Anchor_Compliance_Dsar::ACTION );

		$status = $this->dsar->process_submission( [
			'_wpnonce'         => $nonce,
			'anchor_cmp_hp'    => 'i-am-a-bot',
			'anchor_cmp_type'  => 'access',
			'anchor_cmp_email' => 'bot@example.com',
		] );

		$this->assertSame( 'ok', $status );
		$this->assertCount( 0, $this->dsar->query( [ 'email' => 'bot@example.com' ] ) );
	}

	public function test_genuine_submission_reports_ok_and_creates_a_row() {
		$nonce = wp_create_nonce( Anchor_Compliance_Dsar::ACTION );

		$status = $this->dsar->process_submission( [
			'_wpnonce'         => $nonce,
			'anchor_cmp_hp'    => '',
			'anchor_cmp_type'  => 'access',
			'anchor_cmp_email' => 'real@example.com',
		] );

		$this->assertSame( 'ok', $status );
		$this->assertCount( 1, $this->dsar->query( [ 'email' => 'real@example.com' ] ) );
	}

	public function test_process_submission_rejects_a_bad_nonce() {
		$status = $this->dsar->process_submission( [
			'_wpnonce'         => 'not-a-real-nonce',
			'anchor_cmp_type'  => 'access',
			'anchor_cmp_email' => 'nope@example.com',
		] );

		$this->assertSame( 'error', $status );
		$this->assertCount( 0, $this->dsar->query( [ 'email' => 'nope@example.com' ] ) );
	}

	/**
	 * Review fix 4: privacy_export()/privacy_erase() must follow core's exact
	 * return shapes, and one requester's export must never leak another
	 * requester's rows.
	 */
	public function test_privacy_export_and_erase_shapes_and_isolation() {
		$alice_id = $this->dsar->create( [ 'type' => 'access', 'email' => 'alice@test.com', 'details' => 'about alice' ] );
		$bob_id   = $this->dsar->create( [ 'type' => 'delete', 'email' => 'bob@test.com', 'details' => 'about bob' ] );

		$export = $this->dsar->privacy_export( 'alice@test.com' );
		$this->assertSame( [ 'data', 'done' ], array_keys( $export ) );
		$this->assertTrue( $export['done'] );
		$this->assertNotEmpty( $export['data'] );

		$encoded = wp_json_encode( $export );
		$this->assertStringNotContainsString( 'bob@test.com', $encoded );
		$this->assertStringNotContainsString( 'about bob', $encoded );

		foreach ( $export['data'] as $item ) {
			$this->assertArrayHasKey( 'group_id', $item );
			$this->assertArrayHasKey( 'group_label', $item );
			$this->assertArrayHasKey( 'item_id', $item );
			$this->assertArrayHasKey( 'data', $item );
		}

		$erase = $this->dsar->privacy_erase( 'alice@test.com' );
		$this->assertSame( [ 'items_removed', 'items_retained', 'messages', 'done' ], array_keys( $erase ) );
		$this->assertTrue( $erase['items_removed'] );
		$this->assertFalse( $erase['items_retained'] );
		$this->assertSame( [], $erase['messages'] );
		$this->assertTrue( $erase['done'] );

		$this->assertNull( $this->dsar->get( $alice_id ) );
		$this->assertNotNull( $this->dsar->get( $bob_id ), "Erasing alice's rows must not touch bob's." );
	}

	/**
	 * Review fix 4: a wp_mail() outage must never lose an already-persisted
	 * DSAR record. pre_wp_mail short-circuits wp_mail() to return false
	 * without actually sending, standing in for a mail-transport failure.
	 */
	public function test_mail_failure_does_not_lose_the_record() {
		add_filter( 'pre_wp_mail', '__return_false' );
		$id = $this->dsar->create( [ 'type' => 'access', 'email' => 'mailfail@test.com' ] );
		remove_filter( 'pre_wp_mail', '__return_false' );

		$this->assertIsInt( $id );
		$this->assertNotNull( $this->dsar->get( $id ) );
	}

	/**
	 * Review fix 4: deadline is computed once, from the response_days setting
	 * in force at creation — it must not silently move if the setting is
	 * edited afterward.
	 */
	public function test_deadline_is_unaffected_by_a_later_settings_change() {
		update_option( Anchor_Compliance_Module::OPTION_KEY, [ 'dsar' => [ 'enabled' => true, 'response_days' => 10 ] ], false );
		$id = $this->dsar->create( [ 'type' => 'access', 'email' => 'deadline@test.com' ] );
		$original_deadline = $this->dsar->get( $id )->deadline;

		update_option( Anchor_Compliance_Module::OPTION_KEY, [ 'dsar' => [ 'enabled' => true, 'response_days' => 80 ] ], false );

		$this->assertSame( $original_deadline, $this->dsar->get( $id )->deadline );
	}

	/**
	 * IPv6 truncation must zero the low 8 bytes (the /64 host identifier) via
	 * inet_pton()/inet_ntop(), not string-split on ':' — so a compressed and
	 * an expanded form of the same /64 collide, and a different /64 does not.
	 * Same algorithm as Anchor_Compliance_Consent_Log::hash_ip(), duplicated
	 * here; exercised indirectly through create() since hash_ip() is private.
	 */
	public function test_ipv6_truncation_collides_within_a_64_and_differs_across_64() {
		$original_ip = $_SERVER['REMOTE_ADDR'] ?? null;

		$_SERVER['REMOTE_ADDR'] = '2001:db8::1';
		$same_a = $this->dsar->create( [ 'type' => 'access', 'email' => 'ipv6-a@test.com' ] );

		$_SERVER['REMOTE_ADDR'] = '2001:0db8:0000:0000:0000:0000:0000:0002';
		$same_b = $this->dsar->create( [ 'type' => 'access', 'email' => 'ipv6-b@test.com' ] );

		$_SERVER['REMOTE_ADDR'] = '2001:db8:0:1::1';
		$different = $this->dsar->create( [ 'type' => 'access', 'email' => 'ipv6-c@test.com' ] );

		if ( null === $original_ip ) {
			unset( $_SERVER['REMOTE_ADDR'] );
		} else {
			$_SERVER['REMOTE_ADDR'] = $original_ip;
		}

		$hash_a = $this->dsar->get( $same_a )->ip_hash;
		$hash_b = $this->dsar->get( $same_b )->ip_hash;
		$hash_c = $this->dsar->get( $different )->ip_hash;

		$this->assertNotSame( '', $hash_a );
		$this->assertSame( $hash_a, $hash_b, 'Compressed and expanded forms of the same /64 must hash identically.' );
		$this->assertNotSame( $hash_a, $hash_c, 'A different /64 must not collide.' );
	}
}
