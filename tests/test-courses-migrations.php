<?php
/**
 * Anchor Courses - schema install, versioning and idempotency (brief sections 7, 28).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Database\Migrations;

/** @group courses */
class Test_Courses_Migrations extends Anchor_Courses_TestCase {

	private function columns( string $table ): array {
		global $wpdb;
		$rows = $wpdb->get_col( 'DESCRIBE ' . Migrations::table( $table ) ); // phpcs:ignore WordPress.DB
		return array_map( 'strval', (array) $rows );
	}

	private function index_names( string $table ): array {
		global $wpdb;
		$rows = $wpdb->get_results( 'SHOW INDEX FROM ' . Migrations::table( $table ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_unique( array_column( (array) $rows, 'Key_name' ) );
	}

	/** '0' means UNIQUE, '1' means a plain (non-unique) KEY - MySQL's SHOW INDEX convention. */
	private function is_unique_index( string $table, string $key_name ): bool {
		global $wpdb;
		$rows = $wpdb->get_results( 'SHOW INDEX FROM ' . Migrations::table( $table ), ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( (array) $rows as $row ) {
			if ( $row['Key_name'] === $key_name ) {
				return '0' === (string) $row['Non_unique'];
			}
		}
		return false;
	}

	public function test_all_five_tables_exist_after_bootstrap() {
		global $wpdb;
		foreach ( Migrations::TABLES as $name ) {
			$table = Migrations::table( $name );
			$this->assertSame(
				$table,
				$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ),
				"Missing table {$table}"
			);
		}
	}

	public function test_table_names_use_the_documented_prefix() {
		global $wpdb;
		$this->assertSame( $wpdb->prefix . 'anchor_courses_enrollments', Migrations::table( 'enrollments' ) );
	}

	public function test_enrollments_columns_match_the_brief() {
		$this->assertSame(
			[ 'id', 'user_id', 'course_id', 'status', 'enrolled_at', 'started_at', 'completed_at',
			  'expires_at', 'source', 'source_id', 'metadata', 'created_at', 'updated_at' ],
			$this->columns( 'enrollments' )
		);
	}

	public function test_progress_columns_match_the_brief() {
		$this->assertSame(
			[ 'id', 'user_id', 'course_id', 'item_id', 'item_type', 'status', 'progress_percent',
			  'started_at', 'completed_at', 'last_viewed_at', 'time_spent_seconds', 'metadata',
			  'created_at', 'updated_at' ],
			$this->columns( 'progress' )
		);
	}

	/**
	 * metadata (1.1.0, Task 25 ruling R3) pins time_limit_seconds/on_timer_expiry
	 * at attempt start. It lands after updated_at because dbDelta's ALTER TABLE
	 * ADD COLUMN has no position control - it always appends; from_row() reads
	 * every column by name, so this is cosmetic only.
	 */
	public function test_quiz_attempts_columns_match_the_brief() {
		$this->assertSame(
			[ 'id', 'user_id', 'course_id', 'quiz_id', 'attempt_number', 'status', 'score',
			  'points_earned', 'points_possible', 'passed', 'started_at', 'submitted_at',
			  'duration_seconds', 'answers', 'grading_data', 'created_at', 'updated_at', 'metadata' ],
			$this->columns( 'quiz_attempts' )
		);
	}

	public function test_ce_credits_and_certificates_columns_match_the_brief() {
		$this->assertSame(
			[ 'id', 'user_id', 'course_id', 'credits', 'credit_type', 'awarded_at', 'expires_at',
			  'certificate_id', 'metadata', 'created_at' ],
			$this->columns( 'ce_credits' )
		);
		$this->assertSame(
			[ 'id', 'user_id', 'course_id', 'certificate_number', 'issued_at', 'expires_at',
			  'file_path', 'verification_token', 'metadata', 'created_at' ],
			$this->columns( 'certificates' )
		);
	}

	/** Brief section 26 - uniqueness is the idempotency mechanism, not application code. */
	public function test_uniqueness_constraints_exist() {
		$this->assertContains( 'user_course', $this->index_names( 'enrollments' ) );
		$this->assertContains( 'user_course_item', $this->index_names( 'progress' ) );
		$this->assertContains( 'user_quiz_attempt', $this->index_names( 'quiz_attempts' ) );
		$this->assertContains( 'user_course', $this->index_names( 'ce_credits' ) );
		$this->assertContains( 'user_course', $this->index_names( 'certificates' ) );
		$this->assertContains( 'certificate_number', $this->index_names( 'certificates' ) );
	}

	/**
	 * 1.2.0 (pre-gate cleanup round): verification_token was a plain KEY, so
	 * two certificates could in principle share a token. dbDelta ALTERs the
	 * existing index in place when the full CREATE TABLE is re-issued with a
	 * different key type (verified here against the real test DB, not
	 * assumed).
	 */
	public function test_verification_token_is_a_unique_index() {
		$this->assertTrue(
			$this->is_unique_index( 'certificates', 'verification_token' ),
			'verification_token must be a UNIQUE key, not a plain KEY.'
		);
	}

	public function test_duplicate_verification_token_insert_is_rejected_by_the_database() {
		global $wpdb;
		$row = [
			'user_id' => 1, 'course_id' => 1, 'certificate_number' => 'AC-2026-00000001',
			'issued_at' => '2026-01-01 00:00:00', 'verification_token' => 'dupe-token',
			'created_at' => '2026-01-01 00:00:00',
		];
		$this->assertSame( 1, $wpdb->insert( Migrations::table( 'certificates' ), $row ) );

		$row['user_id']             = 2;
		$row['certificate_number']  = 'AC-2026-00000002';
		$wpdb->suppress_errors( true );
		$this->assertFalse( $wpdb->insert( Migrations::table( 'certificates' ), $row ) );
		$wpdb->suppress_errors( false );
	}

	public function test_duplicate_enrollment_insert_is_rejected_by_the_database() {
		global $wpdb;
		$row = [
			'user_id' => 7, 'course_id' => 9, 'status' => 'enrolled',
			'enrolled_at' => '2026-01-01 00:00:00', 'created_at' => '2026-01-01 00:00:00',
			'updated_at' => '2026-01-01 00:00:00',
		];
		$this->assertSame( 1, $wpdb->insert( Migrations::table( 'enrollments' ), $row ) );
		$wpdb->suppress_errors( true );
		$this->assertFalse( $wpdb->insert( Migrations::table( 'enrollments' ), $row ) );
		$wpdb->suppress_errors( false );
	}

	public function test_version_option_is_recorded_and_not_autoloaded() {
		global $wpdb;
		$this->assertSame( Migrations::DB_VERSION, Migrations::installed_version() );
		$autoload = $wpdb->get_var(
			$wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Migrations::OPTION )
		);
		$this->assertContains( (string) $autoload, [ 'no', 'off' ], 'The DB version option must not autoload.' );
	}

	public function test_run_is_idempotent() {
		Migrations::run();
		Migrations::run();
		$this->assertSame( Migrations::DB_VERSION, Migrations::installed_version() );
		$this->assertCount( 13, $this->columns( 'enrollments' ) );
	}

	/**
	 * Replay safety (found while adding 1.2.0): the version option and the
	 * physical tables can fall out of sync - deliberately in
	 * tests/test-courses-uninstall.php's tear_down(), or on a real site if the
	 * option is ever lost while the tables survive. run() always re-executes
	 * every step below the recorded version, including migrate_1_0_0(), so
	 * that CREATE TABLE must not conflict with a table already carrying a
	 * later migration's changes. Before the fix, replaying migrate_1_0_0()'s
	 * originally-plain `KEY verification_token` against an already-UNIQUE
	 * table failed with a MySQL "Duplicate key name" error.
	 */
	public function test_run_replays_cleanly_when_the_version_option_is_lost() {
		global $wpdb;
		Migrations::run();
		$this->assertTrue( $this->is_unique_index( 'certificates', 'verification_token' ), 'Precondition.' );

		delete_option( Migrations::OPTION );
		$wpdb->suppress_errors( true );
		// wpdb::query() calls flush() before every query, which resets
		// last_error to '' - so a "Duplicate key name" from migrate_1_0_0()'s
		// replay would be cleared by the several queries run() makes AFTER it
		// (Capabilities::sync(), migrate_1_1_0(), migrate_1_2_0(), the
		// update_option() call) before this could ever read it back
		// (CodeRabbit PR #29). wpdb::print_error() appends every error to the
		// global $EZSQL_ERROR before it checks suppress_errors, so that is
		// what must be counted across the whole run instead.
		global $EZSQL_ERROR;
		$before = \is_array( $EZSQL_ERROR ) ? \count( $EZSQL_ERROR ) : 0;
		Migrations::run();
		$errors = \array_slice( (array) $EZSQL_ERROR, $before );
		$wpdb->suppress_errors( false );

		$this->assertSame( [], $errors, 'Replaying every migration step must raise no database error.' );
		$this->assertSame( Migrations::DB_VERSION, Migrations::installed_version() );
		$this->assertTrue( $this->is_unique_index( 'certificates', 'verification_token' ) );
	}
}
