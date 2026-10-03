<?php
// tests/test-agreements-migrations.php
use Anchor\Agreements\Database\Migrations;

class Test_Agreements_Migrations extends WP_UnitTestCase {
	public function test_tables_exist_after_module_boot() {
		global $wpdb;
		foreach ( [ 'versions', 'signatures' ] as $t ) {
			$name = Migrations::table( $t );
			$this->assertSame( $name, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ) );
		}
		$this->assertSame( Migrations::DB_VERSION, get_option( Migrations::OPTION ) );
	}

	public function test_signature_table_has_blob_and_unique_token() {
		global $wpdb;
		$cols = $wpdb->get_results( 'SHOW COLUMNS FROM ' . Migrations::table( 'signatures' ), OBJECT_K );
		$this->assertStringContainsStringIgnoringCase( 'mediumblob', $cols['image']->Type );
		$idx = $wpdb->get_results( 'SHOW INDEX FROM ' . Migrations::table( 'signatures' ) . " WHERE Key_name = 'token'" );
		$this->assertSame( '0', (string) $idx[0]->Non_unique );
	}
}
