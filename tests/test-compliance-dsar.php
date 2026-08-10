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
		unset( $_GET['anchor_cmp'], $_GET['status_filter'], $_GET['orderby'], $_GET['order'], $_GET['paged'] );
		parent::tear_down();
	}

	/** @return int A logged-in administrator's user ID. */
	private function as_admin() {
		$id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $id );
		return $id;
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
		$this->assertContains( 'verified_at', $columns );
		$this->assertContains( 'verify_key', $columns );
		$this->assertContains( 'closed_at', $columns, 'Schema v3: retention is measured from closure.' );
	}

	/**
	 * D022: dbDelta's documented contract requires exactly two spaces between
	 * "PRIMARY KEY" and the column list — a single space parses today but can
	 * emit a duplicate-key ALTER on the next DB_VERSION bump. Pinned at the
	 * source level because dbDelta's misbehavior only appears on re-runs.
	 */
	public function test_schema_uses_dbdelta_two_space_primary_key() {
		$src = file_get_contents( ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-compliance/includes/class-dsar.php' );
		$this->assertStringContainsString( 'PRIMARY KEY  (id)', $src );
		$this->assertStringNotContainsString( 'PRIMARY KEY (id)', $src );
	}

	/**
	 * D005: install used to hook only admin_init while intake is
	 * admin_post_nopriv — on a fresh deploy every public submission failed
	 * until someone loaded wp-admin. create() must self-heal via the cheap
	 * schema-version gate.
	 */
	public function test_create_installs_schema_when_version_option_is_missing() {
		delete_option( Anchor_Compliance_Dsar::DB_VERSION_OPTION );

		$id = $this->dsar->create( [ 'type' => 'access', 'email' => 'fresh-deploy@example.com' ] );

		$this->assertIsInt( $id );
		$this->assertSame( Anchor_Compliance_Dsar::DB_VERSION, get_option( Anchor_Compliance_Dsar::DB_VERSION_OPTION ) );
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

	/**
	 * D012: GDPR Art. 12(3) requires a response within one month and this
	 * module's default posture treats the EEA/UK as strict — the shipped
	 * response_days default must be 30 (45 is CCPA-only), and closed requests
	 * must have a retention default so they age out (D001).
	 */
	public function test_dsar_defaults_are_gdpr_correct() {
		$d = Anchor_Compliance_Settings::defaults()['dsar'];
		$this->assertSame( 30, $d['response_days'] );
		$this->assertSame( 365, $d['retention_days'] );

		$clean = Anchor_Compliance_Settings::sanitize( [] );
		$this->assertSame( 30, $clean['dsar']['response_days'] );
		$this->assertSame( 365, $clean['dsar']['retention_days'] );

		$clean = Anchor_Compliance_Settings::sanitize( [ 'dsar' => [ 'retention_days' => -5 ] ] );
		$this->assertSame( 0, $clean['dsar']['retention_days'], 'retention_days 0 (keep forever) must be representable.' );

		$clean = Anchor_Compliance_Settings::sanitize( [ 'dsar' => [ 'retention_days' => 99999 ] ] );
		$this->assertSame( 3650, $clean['dsar']['retention_days'] );
	}

	/**
	 * D001: a table that exists to service privacy law must itself obey
	 * retention limits — closed (completed/rejected) requests age out after
	 * dsar.retention_days; open requests are never purged regardless of age.
	 */
	public function test_purge_expired_deletes_only_closed_rows_past_retention() {
		global $wpdb;
		update_option( Anchor_Compliance_Module::OPTION_KEY, [ 'dsar' => [ 'retention_days' => 365 ] ], false );

		$old_closed    = $this->dsar->create( [ 'type' => 'access', 'email' => 'old-closed@test.com' ] );
		$recent_closed = $this->dsar->create( [ 'type' => 'access', 'email' => 'recent-closed@test.com' ] );
		$old_open      = $this->dsar->create( [ 'type' => 'access', 'email' => 'old-open@test.com' ] );

		$this->dsar->set_status( $old_closed, 'completed' );
		$this->dsar->set_status( $recent_closed, 'rejected' );

		// Schema v3: the purge clock runs from closed_at, so an expired closed
		// row needs both its submission AND its closure backdated.
		$backdate = gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS );
		$wpdb->update( Anchor_Compliance_Dsar::table(), [ 'created_at' => $backdate, 'closed_at' => $backdate ], [ 'id' => $old_closed ] );
		$wpdb->update( Anchor_Compliance_Dsar::table(), [ 'created_at' => $backdate ], [ 'id' => $old_open ] );

		$this->assertSame( 1, Anchor_Compliance_Dsar::purge_expired() );
		$this->assertNull( $this->dsar->get( $old_closed ), 'Closed rows past retention must be deleted.' );
		$this->assertNotNull( $this->dsar->get( $recent_closed ), 'Closed rows inside the window must be kept.' );
		$this->assertNotNull( $this->dsar->get( $old_open ), 'Open rows must never be purged, however old.' );
	}

	/**
	 * Schema v3 (review finding 2): retention runs from CLOSURE, not
	 * submission. A request that stayed open longer than the whole retention
	 * window and was completed today must survive the nightly purge for
	 * retention_days after that closure — under the old created_at rule it
	 * was deleted by the very next cron run.
	 */
	public function test_purge_measures_retention_from_closure_not_creation() {
		global $wpdb;
		update_option( Anchor_Compliance_Module::OPTION_KEY, [ 'dsar' => [ 'retention_days' => 365 ] ], false );

		$id = $this->dsar->create( [ 'type' => 'access', 'email' => 'long-open@test.com' ] );
		// Submitted 400 days ago…
		$wpdb->update(
			Anchor_Compliance_Dsar::table(),
			[ 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS ) ],
			[ 'id' => $id ]
		);
		// …but only answered today.
		$this->assertSame( 'updated', $this->dsar->set_status( $id, 'completed' ) );

		$this->assertSame( 0, Anchor_Compliance_Dsar::purge_expired() );
		$this->assertNotNull( $this->dsar->get( $id ), 'A row closed today must survive for retention_days after closure, however old the submission.' );
	}

	/** Schema v3: closed_at is stamped on close, immovable on re-apply, kept across closed→closed, and cleared on reopen. */
	public function test_set_status_stamps_and_clears_closed_at() {
		global $wpdb;

		$id = $this->dsar->create( [ 'type' => 'access', 'email' => 'closed-at@test.com' ] );
		$this->assertNull( $this->dsar->get( $id )->closed_at, 'A new request has no closure time.' );

		$this->assertSame( 'updated', $this->dsar->set_status( $id, 'completed' ) );
		$this->assertNotEmpty( $this->dsar->get( $id )->closed_at, 'Closing must stamp closed_at.' );

		// Deterministic re-apply check: pin closed_at to a known value, then
		// re-apply the same status — the clock must not restart.
		$wpdb->update( Anchor_Compliance_Dsar::table(), [ 'closed_at' => '2020-01-01 00:00:00' ], [ 'id' => $id ] );
		$this->assertSame( 'unchanged', $this->dsar->set_status( $id, 'completed' ) );
		$this->assertSame( '2020-01-01 00:00:00', $this->dsar->get( $id )->closed_at, 'Re-applying a closed status must not move the retention clock.' );

		// completed -> rejected stays closed: the original closure stands.
		$this->assertSame( 'updated', $this->dsar->set_status( $id, 'rejected' ) );
		$this->assertSame( '2020-01-01 00:00:00', $this->dsar->get( $id )->closed_at, 'A closed→closed transition keeps the original closure time.' );

		// Reopening clears it — the row is an open request again.
		$this->assertSame( 'updated', $this->dsar->set_status( $id, 'in_progress' ) );
		$this->assertNull( $this->dsar->get( $id )->closed_at, 'Reopening must clear closed_at.' );
	}

	/**
	 * Schema v3: rows closed before the migration carry a closed status with a
	 * NULL closed_at — those fall back to created_at (a bounded imperfection:
	 * for legacy rows the clock may run early by however long the request sat
	 * open, documented at the purge query).
	 */
	public function test_purge_falls_back_to_created_at_for_legacy_closed_rows() {
		global $wpdb;
		update_option( Anchor_Compliance_Module::OPTION_KEY, [ 'dsar' => [ 'retention_days' => 365 ] ], false );

		$id = $this->dsar->create( [ 'type' => 'access', 'email' => 'legacy-closed@test.com' ] );
		$this->dsar->set_status( $id, 'completed' );
		// Simulate a pre-v3 row: old submission, closed status, no recorded closure.
		$wpdb->update(
			Anchor_Compliance_Dsar::table(),
			[ 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS ), 'closed_at' => null ],
			[ 'id' => $id ]
		);

		$this->assertSame( 1, Anchor_Compliance_Dsar::purge_expired() );
		$this->assertNull( $this->dsar->get( $id ), 'Legacy NULL-closed_at rows must still age out on created_at.' );
	}

	public function test_purge_expired_keeps_everything_when_retention_is_zero() {
		global $wpdb;
		update_option( Anchor_Compliance_Module::OPTION_KEY, [ 'dsar' => [ 'retention_days' => 0 ] ], false );

		$id = $this->dsar->create( [ 'type' => 'access', 'email' => 'keep-forever@test.com' ] );
		$this->dsar->set_status( $id, 'completed' );
		$wpdb->update(
			Anchor_Compliance_Dsar::table(),
			[ 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 4000 * DAY_IN_SECONDS ) ],
			[ 'id' => $id ]
		);

		$this->assertSame( 0, Anchor_Compliance_Dsar::purge_expired() );
		$this->assertNotNull( $this->dsar->get( $id ) );
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

	/** D004: a hand-rolled POST can send the TEXT column's full 64KB — cap it server-side. */
	public function test_details_are_truncated_to_max_length() {
		$long = str_repeat( 'a', Anchor_Compliance_Dsar::DETAILS_MAX_LENGTH + 500 );
		$id   = $this->dsar->create( [ 'type' => 'access', 'email' => 'long-details@test.com', 'details' => $long ] );
		$this->assertSame( Anchor_Compliance_Dsar::DETAILS_MAX_LENGTH, strlen( $this->dsar->get( $id )->details ) );
	}

	public function test_rate_limit_blocks_a_rapid_second_request() {
		$this->dsar->create( [ 'type' => 'access', 'email' => 'a@b.com' ] );
		$res = $this->dsar->create( [ 'type' => 'access', 'email' => 'a@b.com' ] );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'rate_limited', $res->get_error_code() );
	}

	/**
	 * D004: the pair (email+IP) key is trivially reset by varying the email
	 * (plus-addressing, throwaway local parts) — an IP-only hourly tier must
	 * catch that.
	 */
	public function test_ip_only_rate_limit_blocks_distinct_emails_from_one_ip() {
		add_filter( 'anchor_compliance_dsar_ip_hourly_limit', function () {
			return 2;
		} );

		$original_ip            = $_SERVER['REMOTE_ADDR'] ?? null;
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';

		$first  = $this->dsar->create( [ 'type' => 'access', 'email' => 'ip-tier-1@test.com' ] );
		$second = $this->dsar->create( [ 'type' => 'access', 'email' => 'ip-tier-2@test.com' ] );
		$third  = $this->dsar->create( [ 'type' => 'access', 'email' => 'ip-tier-3@test.com' ] );

		if ( null === $original_ip ) {
			unset( $_SERVER['REMOTE_ADDR'] );
		} else {
			$_SERVER['REMOTE_ADDR'] = $original_ip;
		}

		$this->assertIsInt( $first );
		$this->assertIsInt( $second );
		$this->assertInstanceOf( WP_Error::class, $third );
		$this->assertSame( 'rate_limited', $third->get_error_code() );
	}

	/** D004: IP rotation defeats both keyed tiers — the global hourly cap must not. */
	public function test_global_rate_limit_caps_total_submissions() {
		add_filter( 'anchor_compliance_dsar_global_hourly_limit', function () {
			return 2;
		} );

		$first  = $this->dsar->create( [ 'type' => 'access', 'email' => 'global-1@test.com' ] );
		$second = $this->dsar->create( [ 'type' => 'access', 'email' => 'global-2@test.com' ] );
		$third  = $this->dsar->create( [ 'type' => 'access', 'email' => 'global-3@test.com' ] );

		$this->assertIsInt( $first );
		$this->assertIsInt( $second );
		$this->assertInstanceOf( WP_Error::class, $third );
		$this->assertSame( 'rate_limited', $third->get_error_code() );
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

	/** D004: the details textarea must declare the same cap the server enforces. */
	public function test_shortcode_details_field_declares_maxlength() {
		$this->assertStringContainsString(
			'maxlength="' . Anchor_Compliance_Dsar::DETAILS_MAX_LENGTH . '"',
			do_shortcode( '[anchor_privacy_request]' )
		);
	}

	public function test_shortcode_reports_when_disabled() {
		update_option( Anchor_Compliance_Module::OPTION_KEY, [ 'dsar' => [ 'enabled' => false ] ], false );
		$this->assertStringNotContainsString( '<form', do_shortcode( '[anchor_privacy_request]' ) );
	}

	/**
	 * D023: the enabled toggle must gate the endpoint, not just the form's
	 * visibility — a nonce harvested while intake was on stays valid for up
	 * to ~24h after it's turned off.
	 */
	public function test_disabled_setting_gates_process_submission() {
		update_option( Anchor_Compliance_Module::OPTION_KEY, [ 'dsar' => [ 'enabled' => false ] ], false );
		$nonce = wp_create_nonce( Anchor_Compliance_Dsar::ACTION );

		$status = $this->dsar->process_submission( [
			'_wpnonce'         => $nonce,
			'anchor_cmp_hp'    => '',
			'anchor_cmp_type'  => 'access',
			'anchor_cmp_email' => 'while-off@test.com',
		] );

		$this->assertSame( 'disabled', $status );
		$this->assertCount( 0, $this->dsar->query( [ 'email' => 'while-off@test.com' ] ) );
	}

	/**
	 * D016: $wpdb->update() returning 0 rows used to map to "Status updated."
	 * — the return value must distinguish a real change from a no-op, a
	 * missing row, and an invalid status.
	 */
	public function test_status_update_is_validated() {
		$id = $this->dsar->create( [ 'type' => 'access', 'email' => 'a@b.com' ] );
		$this->assertSame( 'updated', $this->dsar->set_status( $id, 'completed' ) );
		$this->assertSame( 'completed', $this->dsar->get( $id )->status );
		$this->assertSame( 'unchanged', $this->dsar->set_status( $id, 'completed' ), 'Re-applying the same status is a no-op, not a success.' );
		$this->assertSame( 'not_found', $this->dsar->set_status( 999999, 'completed' ) );
		$this->assertSame( 'invalid', $this->dsar->set_status( $id, 'bogus' ) );
	}

	/** D019: the notes column must have a real writer and reader. */
	public function test_set_notes_round_trip() {
		$id = $this->dsar->create( [ 'type' => 'access', 'email' => 'notes@test.com' ] );
		$this->assertTrue( $this->dsar->set_notes( $id, "Called requester back.\n<script>x</script>" ) );

		$row = $this->dsar->get( $id );
		$this->assertStringContainsString( 'Called requester back.', $row->notes );
		$this->assertStringNotContainsString( '<script>', $row->notes );
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
	 * D003: a DSAR intake must verify the requester controls the email. Each
	 * new request stores verified_at NULL plus only the salted hash of a
	 * single-use token; the raw token exists solely in the confirmation email.
	 */
	public function test_create_stores_unverified_with_hashed_token() {
		$id  = $this->dsar->create( [ 'type' => 'access', 'email' => 'unverified@test.com' ] );
		$row = $this->dsar->get( $id );

		$this->assertNull( $row->verified_at );
		$this->assertSame( 64, strlen( (string) $row->verify_key ), 'verify_key must be a sha256 hash, never the raw token.' );
	}

	/** D003: clicking the emailed link flips verified_at; the token is single-use. */
	public function test_verify_flow_flips_verified_at_and_is_single_use() {
		$captured = [];
		add_filter( 'wp_mail', function ( $atts ) use ( &$captured ) {
			$captured[] = $atts;
			return $atts;
		} );

		$id = $this->dsar->create( [ 'type' => 'access', 'email' => 'verify-me@test.com' ] );
		$this->assertIsInt( $id );

		$token = '';
		foreach ( $captured as $mail ) {
			if ( 'verify-me@test.com' === $mail['to'] && preg_match( '/token=([A-Za-z0-9]+)/', $mail['message'], $m ) ) {
				$token = $m[1];
			}
		}
		$this->assertNotSame( '', $token, 'The requester confirmation email must carry the verification link.' );

		$this->assertSame( 'invalid', $this->dsar->verify( $id, 'wrong-token' ) );
		$this->assertNull( $this->dsar->get( $id )->verified_at, 'A wrong token must not verify.' );

		$this->assertSame( 'verified', $this->dsar->verify( $id, $token ) );
		$row = $this->dsar->get( $id );
		$this->assertNotEmpty( $row->verified_at );
		$this->assertSame( '', $row->verify_key, 'The token hash must be consumed on success (single-use).' );

		$this->assertSame( 'already', $this->dsar->verify( $id, $token ) );
	}

	/** D003: the verification link expires; a stale link must not verify. */
	public function test_verify_rejects_an_expired_link() {
		global $wpdb;

		$captured = [];
		add_filter( 'wp_mail', function ( $atts ) use ( &$captured ) {
			$captured[] = $atts;
			return $atts;
		} );

		$id = $this->dsar->create( [ 'type' => 'access', 'email' => 'stale-link@test.com' ] );

		$token = '';
		foreach ( $captured as $mail ) {
			if ( 'stale-link@test.com' === $mail['to'] && preg_match( '/token=([A-Za-z0-9]+)/', $mail['message'], $m ) ) {
				$token = $m[1];
			}
		}
		$this->assertNotSame( '', $token );

		$wpdb->update(
			Anchor_Compliance_Dsar::table(),
			[ 'created_at' => gmdate( 'Y-m-d H:i:s', time() - ( Anchor_Compliance_Dsar::VERIFY_TTL_DAYS + 3 ) * DAY_IN_SECONDS ) ],
			[ 'id' => $id ]
		);

		$this->assertSame( 'expired', $this->dsar->verify( $id, $token ) );
		$this->assertNull( $this->dsar->get( $id )->verified_at );
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

	/**
	 * D015: on any full-page-cached site the embedded nonce goes stale, after
	 * which every submission used to return the generic 'error' forever —
	 * nonce failure must carry its own code so the form can say "reload".
	 */
	public function test_process_submission_rejects_a_bad_nonce() {
		$status = $this->dsar->process_submission( [
			'_wpnonce'         => 'not-a-real-nonce',
			'anchor_cmp_type'  => 'access',
			'anchor_cmp_email' => 'nope@example.com',
		] );

		$this->assertSame( 'expired', $status );
		$this->assertCount( 0, $this->dsar->query( [ 'email' => 'nope@example.com' ] ) );
	}

	/**
	 * D013: create()'s four distinct WP_Errors used to collapse to a binary
	 * 'error' — the specific code must survive to the redirect.
	 */
	public function test_process_submission_reports_specific_error_codes() {
		$nonce = wp_create_nonce( Anchor_Compliance_Dsar::ACTION );

		$this->assertSame( 'invalid_email', $this->dsar->process_submission( [
			'_wpnonce'         => $nonce,
			'anchor_cmp_hp'    => '',
			'anchor_cmp_type'  => 'access',
			'anchor_cmp_email' => 'not-an-email',
		] ) );

		$ok = $this->dsar->process_submission( [
			'_wpnonce'         => $nonce,
			'anchor_cmp_hp'    => '',
			'anchor_cmp_type'  => 'access',
			'anchor_cmp_email' => 'codes@example.com',
		] );
		$this->assertSame( 'ok', $ok );

		$this->assertSame( 'rate_limited', $this->dsar->process_submission( [
			'_wpnonce'         => $nonce,
			'anchor_cmp_hp'    => '',
			'anchor_cmp_type'  => 'access',
			'anchor_cmp_email' => 'codes@example.com',
		] ) );
	}

	/** D013/D015: the shortcode maps each code to its own honest message. */
	public function test_shortcode_renders_the_message_for_a_specific_error_code() {
		$messages = Anchor_Compliance_Dsar::notice_messages();

		$_GET['anchor_cmp'] = 'rate_limited';
		$this->assertStringContainsString( esc_html( $messages['rate_limited'] ), do_shortcode( '[anchor_privacy_request]' ) );

		$_GET['anchor_cmp'] = 'expired';
		$out = do_shortcode( '[anchor_privacy_request]' );
		$this->assertStringContainsString( esc_html( $messages['expired'] ), $out );
		$this->assertStringContainsString( 'reload', $out );

		$_GET['anchor_cmp'] = 'unknowncode';
		$this->assertStringContainsString( esc_html( $messages['error'] ), do_shortcode( '[anchor_privacy_request]' ) );

		unset( $_GET['anchor_cmp'] );
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
	 * D017: erasure must not destroy the evidence of an honored opt-out.
	 * Opt-out rows are anonymized in place (all PII blanked) rather than
	 * deleted, with items_retained=true and an explanatory message.
	 */
	public function test_erase_anonymizes_optout_rows_and_reports_retention() {
		$original_ip = $_SERVER['REMOTE_ADDR'] ?? null;

		// Distinct IPs so the email+IP pair limiter doesn't block the second
		// request for the same address.
		$_SERVER['REMOTE_ADDR'] = '203.0.113.1';
		$optout_id = $this->dsar->create( [ 'type' => 'optout', 'email' => 'carol@test.com', 'name' => 'Carol', 'details' => 'do not sell' ] );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.2';
		$access_id = $this->dsar->create( [ 'type' => 'access', 'email' => 'carol@test.com', 'name' => 'Carol', 'details' => 'send me my data' ] );

		if ( null === $original_ip ) {
			unset( $_SERVER['REMOTE_ADDR'] );
		} else {
			$_SERVER['REMOTE_ADDR'] = $original_ip;
		}

		$erase = $this->dsar->privacy_erase( 'carol@test.com' );

		$this->assertTrue( $erase['items_removed'] );
		$this->assertTrue( $erase['items_retained'], 'An anonymized opt-out row is retained data and must be reported as such.' );
		$this->assertNotEmpty( $erase['messages'] );
		$this->assertTrue( $erase['done'] );

		$this->assertNull( $this->dsar->get( $access_id ), 'Non-optout rows are deleted outright.' );

		$row = $this->dsar->get( $optout_id );
		$this->assertNotNull( $row, 'The opt-out row must survive as evidence.' );
		$this->assertSame( 'optout', $row->type );
		$this->assertSame( '', $row->email );
		$this->assertSame( '', $row->name );
		$this->assertSame( '', $row->details );
		$this->assertSame( '', $row->ip_hash );
	}

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

	/* ---------------------------------------------------------------------
	 * Admin queue
	 * ------------------------------------------------------------------- */

	/** D011: query() gains a whitelisted deadline sort, and count() backs pagination. */
	public function test_query_supports_deadline_ordering_and_count() {
		update_option( Anchor_Compliance_Module::OPTION_KEY, [ 'dsar' => [ 'response_days' => 20 ] ], false );
		$this->dsar->create( [ 'type' => 'access', 'email' => 'order-mid@test.com' ] );

		update_option( Anchor_Compliance_Module::OPTION_KEY, [ 'dsar' => [ 'response_days' => 60 ] ], false );
		$this->dsar->create( [ 'type' => 'access', 'email' => 'order-late@test.com' ] );

		update_option( Anchor_Compliance_Module::OPTION_KEY, [ 'dsar' => [ 'response_days' => 3 ] ], false );
		$this->dsar->create( [ 'type' => 'access', 'email' => 'order-urgent@test.com' ] );

		$rows = $this->dsar->query( [ 'orderby' => 'deadline', 'order' => 'asc' ] );
		$this->assertSame( 'order-urgent@test.com', $rows[0]->email, 'Deadline-ascending must surface the most urgent request first.' );
		$this->assertSame( 'order-late@test.com', $rows[2]->email );

		$this->assertSame( 3, $this->dsar->count() );
		$this->assertSame( 3, $this->dsar->count( [ 'status' => 'new' ] ) );
		$this->assertSame( 0, $this->dsar->count( [ 'status' => 'completed' ] ) );
	}

	/**
	 * D002/D003/D019/D020/D021: the queue must show the details field (the
	 * only statement of what the requester wants), a verification badge, an
	 * editable notes field, human labels, and a keyboard-operable Apply
	 * button instead of an onchange auto-submit.
	 */
	public function test_queue_renders_details_notes_labels_and_verification_badge() {
		$this->as_admin();
		$this->dsar->create( [ 'type' => 'delete', 'email' => 'queue-render@test.com', 'details' => 'Remove my newsletter profile please' ] );

		ob_start();
		$this->dsar->render_admin_page();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Remove my newsletter profile please', $html, 'D002: details must be visible where the acting happens.' );
		$this->assertStringContainsString( 'Unverified', $html, 'D003: unverified rows must be badged.' );
		$this->assertStringContainsString( 'anchor_cmp_dsar_notes', $html, 'D019: notes must be editable from the queue.' );
		$this->assertStringContainsString( 'Delete my data', $html, 'D020: the type column speaks human labels, not slugs.' );
		$this->assertStringContainsString( 'In progress', $html, 'D020: status options speak human labels, not slugs.' );
		$this->assertStringContainsString( 'Apply', $html, 'D021: a real submit button is required.' );
		$this->assertStringNotContainsString( 'onchange', $html, 'D021: no surprise auto-submit on select change.' );
	}

	/** D011: the status filter must actually limit the rendered rows. */
	public function test_queue_status_filter_limits_rows() {
		$this->as_admin();
		$open_id = $this->dsar->create( [ 'type' => 'access', 'email' => 'filter-open@test.com' ] );
		$done_id = $this->dsar->create( [ 'type' => 'access', 'email' => 'filter-done@test.com' ] );
		$this->dsar->set_status( $done_id, 'completed' );

		$_GET['status_filter'] = 'completed';
		ob_start();
		$this->dsar->render_admin_page();
		$html = ob_get_clean();
		unset( $_GET['status_filter'] );

		$this->assertStringContainsString( 'filter-done@test.com', $html );
		$this->assertStringNotContainsString( 'filter-open@test.com', $html );
	}

	/** D014: open_count() feeds the menu bubble and counts only new/in_progress. */
	public function test_open_count_counts_only_open_requests() {
		$a = $this->dsar->create( [ 'type' => 'access', 'email' => 'open-a@test.com' ] );
		$b = $this->dsar->create( [ 'type' => 'access', 'email' => 'open-b@test.com' ] );
		$this->dsar->set_status( $a, 'completed' );
		$this->dsar->set_status( $b, 'in_progress' );

		$this->assertSame( 1, $this->dsar->open_count() );
	}

	/**
	 * D014: a statutory clock must be visible without navigating to it — an
	 * admin notice fires when any open request is within 5 days of its
	 * deadline or past it, and stays silent otherwise.
	 */
	public function test_admin_notice_warns_on_approaching_deadline() {
		global $wpdb;
		$this->as_admin();

		$safe_id = $this->dsar->create( [ 'type' => 'access', 'email' => 'plenty-of-time@test.com' ] );

		ob_start();
		$this->dsar->admin_notice_deadlines();
		$quiet = ob_get_clean();
		$this->assertSame( '', $quiet, 'No notice while every deadline is comfortably far out.' );

		$overdue_id = $this->dsar->create( [ 'type' => 'access', 'email' => 'overdue@test.com' ] );
		$wpdb->update(
			Anchor_Compliance_Dsar::table(),
			[ 'deadline' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ],
			[ 'id' => $overdue_id ]
		);

		ob_start();
		$this->dsar->admin_notice_deadlines();
		$warning = ob_get_clean();

		$this->assertStringContainsString( 'notice-warning', $warning );
		$this->assertStringContainsString( 'privacy request', $warning );
		$this->assertStringContainsString( Anchor_Compliance_Dsar::MENU_SLUG, $warning, 'The notice must link to the queue.' );
	}
}
