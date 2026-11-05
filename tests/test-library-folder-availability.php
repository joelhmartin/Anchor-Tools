<?php
/**
 * A folder's "available until" date: it closes the folder and everything beneath it for viewers, on the server.
 *
 * @package Anchor\Library\Tests
 * @group library
 */

use Anchor\Library\Permissions;
use Anchor\Library\Provisioning;
use Anchor\Library\Repository;
use Anchor\Library\Schema;

class Anchor_Library_Folder_Availability_Test extends Anchor_Library_TestCase {

	private int $admin;
	private int $member;

	public function set_up() {
		parent::set_up();
		Permissions::reset_memo();
		$this->admin  = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->member = self::factory()->user->create( [ 'role' => 'subscriber' ] );
	}

	private function folder( string $name, int $parent = 0, string $until = '' ): int {
		global $wpdb;
		$wpdb->insert( Schema::table( 'folders' ), [
			'parent_id' => $parent, 'name' => $name, 'owner_user_id' => 0, 'is_private' => 0,
			'available_until' => '' === $until ? null : $until,
			'created_by' => 0, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		return (int) $wpdb->insert_id;
	}

	private function video( int $folder, string $title = 'Clip' ): int {
		global $wpdb;
		$wpdb->insert( Schema::table( 'videos' ), [
			'folder_id' => $folder, 'vimeo_id' => '123', 'title' => $title, 'created_by' => 0,
			'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		return (int) $wpdb->insert_id;
	}

	private function file( int $folder ): int {
		global $wpdb;
		$wpdb->insert( Schema::table( 'files' ), [
			'folder_id' => $folder, 'original_name' => 'a.pdf', 'stored_name' => wp_generate_password( 8, false ) . '.bin',
			'mime_type' => 'application/pdf', 'size' => 10, 'uploader_user_id' => 0, 'created_at' => current_time( 'mysql' ),
		] );
		return (int) $wpdb->insert_id;
	}

	private function grant( string $entity, int $id, int $user, string $cap = 'view' ): void {
		global $wpdb;
		$wpdb->insert( Schema::table( 'permissions' ), [
			'entity_type' => $entity, 'entity_id' => $id, 'subject_type' => 'user', 'subject_key' => (string) $user,
			'capability' => $cap, 'created_by' => 0, 'created_at' => current_time( 'mysql' ),
		] );
	}

	private function day( string $modify ): string {
		return gmdate( 'Y-m-d', strtotime( $modify, current_time( 'timestamp' ) ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
	}

	public function test_a_lapsed_folder_closes_itself_and_what_is_inside_but_not_its_parent() {
		$course  = $this->folder( 'Course' );
		$session = $this->folder( 'Session', $course, $this->day( '-1 day' ) );
		$nested  = $this->folder( 'Nested', $session );
		$video   = $this->video( $session );
		$file    = $this->file( $nested );
		$this->grant( 'folder', $course, $this->member );

		$this->assertTrue( Permissions::can_view_folder( $course, $this->member ) );
		$this->assertFalse( Permissions::can_view_folder( $session, $this->member ) );
		$this->assertFalse( Permissions::can_view_folder( $nested, $this->member ) );
		$this->assertFalse( Permissions::can_view_video( $video, $this->member ) );
		$this->assertFalse( Permissions::can_view_file( $file, $this->member ) );
	}

	public function test_the_last_day_is_still_open() {
		$today    = $this->folder( 'Today', 0, $this->day( 'now' ) );
		$tomorrow = $this->folder( 'Tomorrow', 0, $this->day( '+1 day' ) );
		$this->grant( 'folder', $today, $this->member );
		$this->grant( 'folder', $tomorrow, $this->member );

		$this->assertTrue( Permissions::can_view_folder( $today, $this->member ) );
		$this->assertTrue( Permissions::can_view_folder( $tomorrow, $this->member ) );
	}

	public function test_admins_and_managers_keep_access_after_it_lapses() {
		$lapsed  = $this->folder( 'Old', 0, $this->day( '-1 day' ) );
		$manager = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->grant( 'folder', $lapsed, $manager, 'manage' );

		$this->assertTrue( Permissions::can_view_folder( $lapsed, $this->admin ) );
		$this->assertTrue( Permissions::can_view_folder( $lapsed, $manager ) );
	}

	public function test_a_grant_on_the_file_itself_does_not_outlive_the_folder() {
		$lapsed = $this->folder( 'Old', 0, $this->day( '-1 day' ) );
		$file   = $this->file( $lapsed );
		$this->grant( 'file', $file, $this->member );

		$this->assertFalse( Permissions::can_view_file( $file, $this->member ) );
	}

	public function test_listings_leave_out_a_lapsed_folder_and_carry_the_date_of_an_open_one() {
		$course = $this->folder( 'Course' );
		$old    = $this->folder( 'Old', $course, $this->day( '-1 day' ) );
		$until  = $this->day( '+5 days' );
		$open   = $this->folder( 'Open', $course, $until );
		$this->video( $old, 'Old clip' );
		$this->grant( 'folder', $course, $this->member );

		$kids = Repository::children( $course, $this->member );
		$this->assertSame( [ $open ], array_column( $kids['folders'], 'id' ) );
		$this->assertSame( $until, $kids['folders'][0]['availableUntil'] );

		$this->assertEmpty( Repository::children( $old, $this->member )['videos'] );

		$tree = Repository::tree( $this->member );
		$this->assertSame( [ $open ], array_column( $tree[0]['children'], 'id' ), 'The index path must honour the date too.' );

		$map = Repository::contents_map( $this->member );
		if ( null !== $map ) {
			$this->assertArrayNotHasKey( $old, $map );
		}

		$admin_kids = Repository::children( $course, $this->admin );
		$this->assertCount( 2, $admin_kids['folders'] );
		$this->assertTrue( array_column( $admin_kids['folders'], 'lapsed', 'id' )[ $old ] );
	}

	public function test_setting_the_date_rejects_a_bad_value_and_empty_clears_it() {
		$id = $this->folder( 'F', 0, '2030-01-01' );

		$this->assertFalse( Provisioning::set_available_until( $id, 'soon' ) );
		$this->assertFalse( Provisioning::set_available_until( $id, '2030-02-31' ) );
		$this->assertSame( '2030-01-01', Provisioning::available_until( $id ), 'A typo must not remove the deadline.' );

		$this->assertTrue( Provisioning::set_available_until( $id, '2031-03-04' ) );
		$this->assertSame( '2031-03-04', Provisioning::available_until( $id ) );

		$this->assertTrue( Provisioning::set_available_until( $id, '' ) );
		$this->assertSame( '', Provisioning::available_until( $id ) );
	}

	public function test_delete_if_empty_keeps_a_folder_with_something_in_it() {
		$empty = $this->folder( 'Empty' );
		$full  = $this->folder( 'Full' );
		$this->video( $full );
		$this->grant( 'folder', $empty, $this->member );

		$this->assertTrue( Provisioning::delete_if_empty( $empty ) );
		$this->assertFalse( Provisioning::folder_exists( $empty ) );
		$this->assertFalse( Provisioning::delete_if_empty( $full ) );
		$this->assertTrue( Provisioning::folder_exists( $full ) );
	}

	public function test_an_older_site_gets_the_column_on_upgrade() {
		global $wpdb;
		$folders = Schema::table( 'folders' );
		$wpdb->query( "ALTER TABLE {$folders} DROP COLUMN available_until" ); // phpcs:ignore
		update_option( Schema::OPT_DB_VERSION, '2.17.0' );

		Schema::maybe_upgrade();

		$this->assertNotEmpty( $wpdb->get_var( "SHOW COLUMNS FROM {$folders} LIKE 'available_until'" ) ); // phpcs:ignore
		$this->assertSame( Schema::DB_VERSION, get_option( Schema::OPT_DB_VERSION ) );
	}
}
