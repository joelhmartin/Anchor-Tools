<?php
/**
 * Anchor Compliance — consent log.
 */
class Test_Compliance_Log extends WP_UnitTestCase {

	private $log;

	public function set_up() {
		parent::set_up();
		Anchor_Compliance_Consent_Log::install();
		$this->log = new Anchor_Compliance_Consent_Log();
		$GLOBALS['wpdb']->query( 'TRUNCATE TABLE ' . Anchor_Compliance_Consent_Log::table() );
	}

	public function tear_down() {
		delete_option( Anchor_Compliance_Module::OPTION_KEY );
		parent::tear_down();
	}

	public function test_table_is_created() {
		global $wpdb;
		$table = Anchor_Compliance_Consent_Log::table();

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
		$this->assertContains( 'consent_id', $columns );
	}

	public function test_record_inserts_a_row() {
		$id = $this->log->record( [
			'consent_id' => 'aaaaaaaa-0000-4000-8000-000000000000',
			'categories' => [ 'necessary', 'analytics' ],
			'region'     => 'DE',
			'posture'    => 'strict',
			'method'     => 'banner',
		] );

		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );

		$rows = $this->log->query();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'DE', $rows[0]->region );
		$this->assertSame( 'banner', $rows[0]->method );
		$this->assertSame( [ 'necessary', 'analytics' ], json_decode( $rows[0]->categories, true ) );
	}

	public function test_raw_ip_is_never_stored() {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.42';
		$this->log->record( [
			'consent_id' => 'bbbbbbbb-0000-4000-8000-000000000000',
			'categories' => [ 'necessary' ],
			'region'     => 'US', 'posture' => 'optout', 'method' => 'banner',
		] );

		$row = $this->log->query()[0];
		$this->assertNotSame( '203.0.113.42', $row->ip_hash );
		$this->assertSame( 64, strlen( $row->ip_hash ), 'ip_hash must be a sha256 hex digest.' );
		$this->assertStringNotContainsString( '203.0.113', $row->ip_hash );
	}

	public function test_policy_version_is_captured_from_settings() {
		update_option( Anchor_Compliance_Module::OPTION_KEY, [ 'general' => [ 'policy_version' => 5 ] ], false );
		$this->log->record( [
			'consent_id' => 'cccccccc-0000-4000-8000-000000000000',
			'categories' => [ 'necessary' ], 'region' => 'US', 'posture' => 'optout', 'method' => 'gpc',
		] );
		$this->assertSame( '5', $this->log->query()[0]->policy_version );
	}

	public function test_record_is_skipped_when_logging_disabled() {
		update_option( Anchor_Compliance_Module::OPTION_KEY, [ 'log' => [ 'enabled' => false ] ], false );
		$this->assertFalse( $this->log->record( [
			'consent_id' => 'dddddddd-0000-4000-8000-000000000000',
			'categories' => [ 'necessary' ], 'region' => 'US', 'posture' => 'optout', 'method' => 'banner',
		] ) );
		$this->assertCount( 0, $this->log->query() );
	}

	public function test_query_filters_by_method_and_counts() {
		foreach ( [ 'banner', 'banner', 'gpc' ] as $i => $method ) {
			$this->log->record( [
				'consent_id' => "eeeeeee{$i}-0000-4000-8000-000000000000",
				'categories' => [ 'necessary' ], 'region' => 'US', 'posture' => 'optout', 'method' => $method,
			] );
		}
		$this->assertCount( 2, $this->log->query( [ 'method' => 'banner' ] ) );
		$this->assertSame( 3, $this->log->count() );
		$this->assertSame( 1, $this->log->count( [ 'method' => 'gpc' ] ) );
	}

	public function test_purge_removes_rows_past_retention() {
		global $wpdb;
		update_option( Anchor_Compliance_Module::OPTION_KEY, [ 'log' => [ 'enabled' => true, 'retention_days' => 30 ] ], false );

		$this->log->record( [
			'consent_id' => 'ffffffff-0000-4000-8000-000000000000',
			'categories' => [ 'necessary' ], 'region' => 'US', 'posture' => 'optout', 'method' => 'banner',
		] );
		// Backdate it past the window.
		$wpdb->query( 'UPDATE ' . Anchor_Compliance_Consent_Log::table() . ' SET created_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 31 DAY)' );

		$this->log->record( [
			'consent_id' => '99999999-0000-4000-8000-000000000000',
			'categories' => [ 'necessary' ], 'region' => 'US', 'posture' => 'optout', 'method' => 'banner',
		] );

		$this->assertSame( 1, $this->log->purge() );
		$this->assertSame( 1, $this->log->count() );
	}
}
