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
}
