<?php
use Anchor\Announcements\Content\AnnouncementPostType as PT;
use Anchor\Announcements\Database\Migrations;
use Anchor\Announcements\Module;
use Anchor\Announcements\Support\Settings;

class Test_Announcements_Module extends Anchor_Announcements_TestCase {

	public function test_module_boots() {
		$this->assertInstanceOf( Module::class, $this->module() );
	}

	public function test_tables_exist() {
		global $wpdb;
		foreach ( [ 'sends', 'events', 'suppressions' ] as $t ) {
			$name = Migrations::table( $t );
			$this->assertSame( $name, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ) );
		}
	}

	public function test_sends_table_has_unique_announcement_email() {
		global $wpdb;
		$t = Migrations::table( 'sends' );
		$wpdb->insert( $t, [ 'announcement_id' => 1, 'email' => 'a@x.com', 'token' => str_repeat( 'a', 32 ), 'status' => 'queued', 'queued_at' => current_time( 'mysql', true ) ] );
		$wpdb->suppress_errors( true );
		$ok = $wpdb->insert( $t, [ 'announcement_id' => 1, 'email' => 'a@x.com', 'token' => str_repeat( 'b', 32 ), 'status' => 'queued', 'queued_at' => current_time( 'mysql', true ) ] );
		$wpdb->suppress_errors( false );
		$this->assertFalse( $ok );
	}

	public function test_administrator_has_the_capability_and_editor_does_not() {
		$this->assertTrue( get_role( 'administrator' )->has_cap( Module::CAP ) );
		$this->assertFalse( get_role( 'editor' )->has_cap( Module::CAP ) );
	}

	public function test_cpt_is_registered_private_with_the_capability() {
		$pt = get_post_type_object( PT::CPT );
		$this->assertNotNull( $pt );
		$this->assertFalse( $pt->public );
		$this->assertSame( Module::CAP, $pt->cap->edit_posts );
	}

	public function test_state_defaults_to_draft() {
		$id = self::factory()->post->create( [ 'post_type' => PT::CPT ] );
		$this->assertSame( PT::STATE_DRAFT, PT::state( $id ) );
	}

	public function test_settings_defaults_and_clamping() {
		delete_option( Settings::OPTION );
		$s = Settings::get();
		$this->assertSame( 50, $s['batch_size'] );
		$this->assertSame( get_bloginfo( 'name' ), $s['from_name'] );
		$saved = Settings::save( [ 'batch_size' => '99999', 'from_email' => 'not an email', 'brand_color' => 'blue', 'footer_address' => "1 Main St\nTown" ] );
		$this->assertSame( 500, $saved['batch_size'] );
		$this->assertSame( '', $saved['from_email'] );
		$this->assertSame( '#1a4f48', $saved['brand_color'] );
		$this->assertSame( "1 Main St\nTown", $saved['footer_address'] );
		global $wpdb;
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Settings::OPTION ) );
		$this->assertContains( $autoload, [ 'no', 'off' ] ); // 'off' on WP 6.6+
	}
}
