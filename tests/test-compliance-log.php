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

	/**
	 * Regression (C016): the audit row must stamp the policy version the
	 * visitor actually consented under (the client payload's), not whatever
	 * the settings say at write time.
	 */
	public function test_policy_version_from_payload_wins_over_settings() {
		update_option( Anchor_Compliance_Module::OPTION_KEY, [ 'general' => [ 'policy_version' => 5 ] ], false );
		$this->log->record( [
			'consent_id'     => '10101010-0000-4000-8000-000000000000',
			'categories'     => [ 'necessary' ],
			'region'         => 'US', 'posture' => 'optout', 'method' => 'banner',
			'policy_version' => 3,
		] );
		$this->assertSame( '3', $this->log->query()[0]->policy_version );
	}

	public function test_invalid_payload_policy_version_falls_back_to_settings() {
		update_option( Anchor_Compliance_Module::OPTION_KEY, [ 'general' => [ 'policy_version' => 5 ] ], false );
		$this->log->record( [
			'consent_id'     => '20202020-0000-4000-8000-000000000000',
			'categories'     => [ 'necessary' ],
			'region'         => 'US', 'posture' => 'optout', 'method' => 'banner',
			'policy_version' => 0,
		] );
		$this->assertSame( '5', $this->log->query()[0]->policy_version );
	}

	/** Regression (LAW-5): the CPRA/placeholder methods are part of the vocabulary. */
	public function test_do_not_sell_and_placeholder_methods_are_recordable() {
		foreach ( [ 'do_not_sell', 'placeholder' ] as $i => $method ) {
			$this->log->record( [
				'consent_id' => "3030303{$i}-0000-4000-8000-000000000000",
				'categories' => [ 'necessary' ], 'region' => 'US', 'posture' => 'optout',
				'method'     => $method,
			] );
		}
		$this->assertCount( 1, $this->log->query( [ 'method' => 'do_not_sell' ] ) );
		$this->assertCount( 1, $this->log->query( [ 'method' => 'placeholder' ] ) );
	}

	public function test_unknown_method_collapses_to_banner() {
		$this->log->record( [
			'consent_id' => '40404040-0000-4000-8000-000000000000',
			'categories' => [ 'necessary' ], 'region' => 'US', 'posture' => 'optout',
			'method'     => 'totally_bogus',
		] );
		$this->assertSame( 'banner', $this->log->query()[0]->method );
	}

	/**
	 * Regression (C015): an unparseable 'since' used to coerce to
	 * created_at >= '1970-01-01' — silently matching everything while
	 * appearing applied. The clause is now dropped entirely on garbage; a
	 * parseable value still filters.
	 */
	public function test_since_filter_applies_when_parseable_and_is_dropped_on_garbage() {
		global $wpdb;

		$this->log->record( [
			'consent_id' => '50505050-0000-4000-8000-000000000000',
			'categories' => [ 'necessary' ], 'region' => 'US', 'posture' => 'optout', 'method' => 'banner',
		] );
		$wpdb->query( 'UPDATE ' . Anchor_Compliance_Consent_Log::table() . ' SET created_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 DAY)' );
		$this->log->record( [
			'consent_id' => '60606060-0000-4000-8000-000000000000',
			'categories' => [ 'necessary' ], 'region' => 'US', 'posture' => 'optout', 'method' => 'banner',
		] );

		$this->assertCount( 1, $this->log->query( [ 'since' => '-5 days' ] ), 'A parseable since filters.' );
		$this->assertSame( 1, $this->log->count( [ 'since' => '-5 days' ] ) );
		$this->assertCount( 2, $this->log->query( [ 'since' => 'garbage###' ] ), 'Unparseable since is dropped, not applied as 1970.' );
	}

	/**
	 * Regression (C008/F014): a consent write must not depend on an admin
	 * having visited wp-admin first — record() installs the missing table
	 * itself (cheap version-option compare on the happy path).
	 */
	public function test_record_installs_missing_table_before_writing() {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Anchor_Compliance_Consent_Log::table() );
		delete_option( Anchor_Compliance_Consent_Log::DB_VERSION_OPTION );

		$id = $this->log->record( [
			'consent_id' => '70707070-0000-4000-8000-000000000000',
			'categories' => [ 'necessary' ], 'region' => 'US', 'posture' => 'optout', 'method' => 'banner',
		] );

		$this->assertIsInt( $id, 'The write must succeed on a fresh, never-admin-visited site.' );
		$this->assertSame( 1, $this->log->count() );
	}

	/** Regression (C004/F002): turning the module off must not orphan the purge cron. */
	public function test_clear_scheduled_events_unschedules_the_purge_cron() {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', Anchor_Compliance_Consent_Log::CRON_HOOK );
		$this->assertNotFalse( wp_next_scheduled( Anchor_Compliance_Consent_Log::CRON_HOOK ) );

		Anchor_Compliance_Module::clear_scheduled_events();
		$this->assertFalse( wp_next_scheduled( Anchor_Compliance_Consent_Log::CRON_HOOK ) );
	}

	public function test_module_toggle_off_unschedules_and_toggle_on_does_not() {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', Anchor_Compliance_Consent_Log::CRON_HOOK );

		// Unrelated save with the module still on: cron stays.
		Anchor_Compliance_Module::maybe_unschedule_on_disable(
			[ 'modules' => [ 'compliance' => true ] ],
			[ 'modules' => [ 'compliance' => true, 'events_manager' => true ] ]
		);
		$this->assertNotFalse( wp_next_scheduled( Anchor_Compliance_Consent_Log::CRON_HOOK ) );

		// The save that turns the module off: cron cleared.
		Anchor_Compliance_Module::maybe_unschedule_on_disable(
			[ 'modules' => [ 'compliance' => true ] ],
			[ 'modules' => [ 'compliance' => false ] ]
		);
		$this->assertFalse( wp_next_scheduled( Anchor_Compliance_Consent_Log::CRON_HOOK ) );
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
