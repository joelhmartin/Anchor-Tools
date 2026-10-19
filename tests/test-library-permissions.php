<?php
/**
 * Library permissions, repository listings and activity log.
 *
 * Behaviour is the Anchor Private File Manager's at the 9e3f491 pin; these tests pin it so the port cannot drift.
 *
 * @package Anchor\Library\Tests
 * @group library
 */

use Anchor\Library\Activity;
use Anchor\Library\Permissions;
use Anchor\Library\Repository;
use Anchor\Library\Schema;

class Anchor_Library_Permissions_Test extends Anchor_Library_TestCase {

	private int $admin;
	private int $member;
	private int $outsider;

	public function set_up() {
		parent::set_up();
		Permissions::reset_memo();
		$this->admin    = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->member   = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->outsider = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( 0 );
	}

	public function tear_down() {
		Permissions::reset_memo();
		remove_all_filters( 'anchor_fm_contents_preload_max' );
		parent::tear_down();
	}

	// ---- fixtures -------------------------------------------------------------------------------------------

	private function folder( string $name, int $parent = 0, int $owner = 0, int $private = 0 ): int {
		global $wpdb;
		$wpdb->insert( Schema::table( 'folders' ), [
			'parent_id' => $parent, 'name' => $name, 'owner_user_id' => $owner, 'is_private' => $private,
			'created_by' => 0, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		return (int) $wpdb->insert_id;
	}

	private function file( int $folder, string $name = 'a.pdf', string $mime = 'application/pdf' ): int {
		global $wpdb;
		$wpdb->insert( Schema::table( 'files' ), [
			'folder_id' => $folder, 'original_name' => $name, 'stored_name' => wp_generate_password( 8, false ) . '.bin',
			'mime_type' => $mime, 'size' => 10, 'uploader_user_id' => 99, 'created_at' => current_time( 'mysql' ),
		] );
		return (int) $wpdb->insert_id;
	}

	private function link( int $folder, string $title = 'Site' ): int {
		global $wpdb;
		$wpdb->insert( Schema::table( 'links' ), [
			'folder_id' => $folder, 'title' => $title, 'url' => 'https://example.test/', 'created_by' => 99,
			'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		return (int) $wpdb->insert_id;
	}

	private function video( int $folder, string $title = 'Clip' ): int {
		global $wpdb;
		$wpdb->insert( Schema::table( 'videos' ), [
			'folder_id' => $folder, 'vimeo_id' => '123', 'vimeo_hash' => 'abc', 'title' => $title, 'thumbnail_url' => '',
			'created_by' => 99, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		return (int) $wpdb->insert_id;
	}

	private function grant( string $entity, int $id, string $subject_type, string $key, string $cap = 'view' ): void {
		global $wpdb;
		$wpdb->insert( Schema::table( 'permissions' ), [
			'entity_type' => $entity, 'entity_id' => $id, 'subject_type' => $subject_type, 'subject_key' => $key,
			'capability' => $cap, 'created_by' => 0, 'created_at' => current_time( 'mysql' ),
		] );
	}

	// ---- Permissions ----------------------------------------------------------------------------------------

	public function test_admin_sees_everything() {
		$f = $this->folder( 'Locked' );
		$file = $this->file( $f );
		$this->assertSame( 'manage', Permissions::folder_capability( $f, $this->admin ) );
		$this->assertTrue( Permissions::can_view_file( $file, $this->admin ) );
		$this->assertTrue( Permissions::can_manage_file( $file, $this->admin ) );
	}

	public function test_no_grant_means_none() {
		$f = $this->folder( 'Locked' );
		$this->assertSame( 'none', Permissions::folder_capability( $f, $this->member ) );
		$this->assertFalse( Permissions::can_view_file( $this->file( $f ), $this->member ) );
		$this->assertSame( 'none', Permissions::folder_capability( 999999, $this->member ), 'a missing folder is none' );
	}

	public function test_owner_can_manage() {
		$f = $this->folder( 'Mine', 0, $this->member );
		$this->assertSame( 'manage', Permissions::folder_capability( $f, $this->member ) );
		$this->assertSame( 'none', Permissions::folder_capability( $f, $this->outsider ) );
	}

	public function test_role_grant_is_inherited_two_levels_deep() {
		$a = $this->folder( 'A' );
		$b = $this->folder( 'B', $a );
		$c = $this->folder( 'C', $b );
		$this->grant( 'folder', $a, 'role', 'subscriber' );
		$this->assertSame( 'view', Permissions::folder_capability( $c, $this->member ) );
		$this->assertSame( 'none', Permissions::folder_capability( $c, $this->outsider ) );
		$this->assertTrue( Permissions::can_view_file( $this->file( $c ), $this->member ) );
	}

	public function test_user_grant_beats_no_grant_and_manage_outranks_view() {
		$f = $this->folder( 'Shared' );
		$this->assertSame( 'none', Permissions::folder_capability( $f, $this->outsider ) );
		$this->grant( 'folder', $f, 'user', (string) $this->outsider, 'view' );
		$this->assertSame( 'view', Permissions::folder_capability( $f, $this->outsider ) );
		$this->grant( 'folder', $f, 'user', (string) $this->outsider, 'manage' );
		$this->assertSame( 'manage', Permissions::folder_capability( $f, $this->outsider ) );
	}

	/**
	 * The plan calls this "a file-level row overrides its folder (deny and allow)". The plugin's resolver is
	 * allow-only: a direct file grant wins when present, and otherwise the folder's answer is used. There is no
	 * deny row. Ported byte-for-byte, so this pins the real behaviour.
	 */
	public function test_file_level_grant_allows_inside_a_closed_folder_and_never_denies() {
		$closed = $this->folder( 'Closed' );
		$open   = $this->folder( 'Open' );
		$this->grant( 'folder', $open, 'role', 'subscriber' );

		$shared = $this->file( $closed, 'shared.pdf' );
		$this->grant( 'file', $shared, 'role', 'subscriber' );
		$this->assertTrue( Permissions::can_view_file( $shared, $this->member ), 'file grant allows inside a closed folder' );
		$this->assertFalse( Permissions::can_view_file( $shared, $this->outsider ), 'the grant is per subject' );

		$other = $this->file( $open, 'other.pdf' );
		$this->grant( 'file', $other, 'role', 'editor' ); // a row for someone else must not shut the folder grant out
		$this->assertTrue( Permissions::can_view_file( $other, $this->member ), 'folder grant still applies' );
		$this->assertTrue( Permissions::can_view_file( $other, $this->outsider ), 'file grant allows' );
	}

	public function test_a_view_policy_grants_like_a_permission_row() {
		global $wpdb;
		$f = $this->folder( 'Policy' );
		$wpdb->insert( Schema::table( 'permission_policies' ), [
			'entity_type' => 'folder', 'entity_id' => $f, 'capability' => 'view',
			'policy' => wp_json_encode( [ 'operator' => 'any', 'rules' => [ [ 'operator' => 'all', 'conditions' => [ [ 'type' => 'role', 'role' => 'editor' ] ] ] ] ] ),
			'created_by' => 0, 'created_at' => current_time( 'mysql' ), 'updated_by' => 0, 'updated_at' => current_time( 'mysql' ),
		] );
		$this->assertSame( 'view', Permissions::folder_capability( $f, $this->outsider ) );
		$this->assertSame( 'none', Permissions::folder_capability( $f, $this->member ) );
	}

	public function test_depth_cap_holds_at_50() {
		$ids   = [];
		$ids[] = $this->folder( 'L1' );
		for ( $i = 2; $i <= 51; $i++ ) {
			$ids[] = $this->folder( 'L' . $i, end( $ids ) );
		}
		$this->grant( 'folder', $ids[0], 'role', 'subscriber' );
		$this->assertSame( 'view', Permissions::folder_capability( $ids[49], $this->member ), '50 levels reach the grant' );
		$this->assertSame( 'none', Permissions::folder_capability( $ids[50], $this->member ), '51 levels do not' );

		Permissions::with_memo( function () use ( $ids ) { // same answer through the preloaded index
			$this->assertSame( 'view', Permissions::folder_capability( $ids[49], $this->member ) );
			$this->assertSame( 'none', Permissions::folder_capability( $ids[50], $this->member ) );
		} );
	}

	public function test_a_parent_cycle_terminates() {
		global $wpdb;
		$a = $this->folder( 'A' );
		$b = $this->folder( 'B', $a );
		$wpdb->update( Schema::table( 'folders' ), [ 'parent_id' => $b ], [ 'id' => $a ] );
		$this->assertSame( 'none', Permissions::folder_capability( $b, $this->member ) );
	}

	public function test_null_user_means_the_current_user_and_an_explicit_id_wins() {
		$f = $this->folder( 'Shared' );
		$this->grant( 'folder', $f, 'role', 'subscriber' );
		wp_set_current_user( $this->member );
		$this->assertSame( 'view', Permissions::folder_capability( $f ) );
		$this->assertSame( 'none', Permissions::folder_capability( $f, $this->outsider ), 'explicit user, not the session' );
		wp_set_current_user( $this->outsider );
		$this->assertSame( 'none', Permissions::folder_capability( $f ) );
		$this->assertSame( 'view', Permissions::folder_capability( $f, $this->member ) );
		wp_set_current_user( 0 );
		$this->assertSame( 'none', Permissions::folder_capability( $f ), 'signed out is none' );
	}

	/** Review Focus 4: a warm memo for user A must never answer for user B. */
	public function test_memo_is_keyed_by_user() {
		global $wpdb;
		$root = $this->folder( 'Root' );
		$leaf = $this->folder( 'Leaf', $root );
		$file = $this->file( $leaf );
		$this->grant( 'folder', $root, 'user', (string) $this->member, 'view' );

		Permissions::with_memo( function () use ( $leaf, $file ) {
			global $wpdb;
			$this->assertSame( 'view', Permissions::folder_capability( $leaf, $this->member ) );
			$this->assertTrue( Permissions::can_view_file( $file, $this->member ) );

			$before = $wpdb->num_queries;
			$this->assertSame( 'view', Permissions::folder_capability( $leaf, $this->member ) );
			$this->assertSame( $before, $wpdb->num_queries, 'the memo is warm for the member: no queries' );

			$this->assertSame( 'none', Permissions::folder_capability( $leaf, $this->outsider ), 'warm memo for A does not answer for B' );
			$this->assertFalse( Permissions::can_view_file( $file, $this->outsider ) );
			$this->assertSame( 'manage', Permissions::folder_capability( $leaf, $this->admin ) );
			$this->assertSame( 'view', Permissions::folder_capability( $leaf, $this->member ), 'and A is still A' );
		} );

		// And the other way round, starting with the user who has nothing.
		Permissions::with_memo( function () use ( $leaf ) {
			$this->assertSame( 'none', Permissions::folder_capability( $leaf, $this->outsider ) );
			$this->assertSame( 'view', Permissions::folder_capability( $leaf, $this->member ) );
		} );
	}

	public function test_memo_is_not_live_outside_a_scope_so_writes_are_seen() {
		$f = $this->folder( 'F' );
		$this->assertSame( 'none', Permissions::folder_capability( $f, $this->member ) );
		$this->grant( 'folder', $f, 'role', 'subscriber' );
		$this->assertSame( 'view', Permissions::folder_capability( $f, $this->member ), 'no stale answer without a scope' );
	}

	public function test_reset_memo_closes_an_open_scope() {
		$f = $this->folder( 'F' );
		$begin = new ReflectionMethod( Permissions::class, 'begin_memo' );
		$begin->setAccessible( true );
		$begin->invoke( null );
		$this->assertTrue( Permissions::memo_active() );
		$this->assertSame( 'none', Permissions::folder_capability( $f, $this->member ) );
		Permissions::reset_memo();
		$this->assertFalse( Permissions::memo_active() );
		$this->grant( 'folder', $f, 'role', 'subscriber' );
		$this->assertSame( 'view', Permissions::folder_capability( $f, $this->member ) );
	}

	public function test_can_access_anything() {
		$this->assertTrue( Permissions::can_access_anything( $this->admin ) );
		$this->assertFalse( Permissions::can_access_anything( $this->member ) );
		$f = $this->folder( 'F' );
		$this->grant( 'folder', $f, 'role', 'subscriber' );
		$this->assertTrue( Permissions::can_access_anything( $this->member ) );
		$this->assertFalse( Permissions::can_access_anything( $this->outsider ) );
	}

	public function test_link_and_video_follow_their_folder() {
		$f = $this->folder( 'F' );
		$l = $this->link( $f );
		$v = $this->video( $f );
		$this->assertFalse( Permissions::can_view_link( $l, $this->member ) );
		$this->assertFalse( Permissions::can_view_video( $v, $this->member ) );
		$this->grant( 'folder', $f, 'role', 'subscriber' );
		$this->assertTrue( Permissions::can_view_link( $l, $this->member ) );
		$this->assertTrue( Permissions::can_view_video( $v, $this->member ) );
		$this->assertFalse( Permissions::can_manage_link( $l, $this->member ) );
		$this->assertFalse( Permissions::can_view_video( 999999, $this->member ), 'missing video' );
	}

	public function test_a_woo_order_grants_a_product_doc_file() {
		if ( ! function_exists( 'wc_get_products' ) || ! class_exists( 'WC_Order' ) ) {
			$this->markTestSkipped( 'WooCommerce is not loaded in this environment.' );
		}
		$pd   = Schema::ensure_product_docs_folder();
		$file = $this->file( $pd, 'manual.pdf' );
		$product = new WC_Product_Simple();
		$product->set_name( 'Course' );
		$product->set_status( 'publish' );
		$pid = $product->save();
		update_post_meta( $pid, '_anchor_pd_docs', [ [ 'fileId' => $file, 'title' => 'Manual', 'expires' => '' ] ] );

		$this->assertFalse( Permissions::can_view_file( $file, $this->member ) );
		Permissions::reset_memo();
		$order = wc_create_order( [ 'customer_id' => $this->member, 'status' => 'completed' ] );
		$order->add_product( $product, 1 );
		$order->set_status( 'completed' );
		$order->save();
		$this->assertTrue( Permissions::can_view_file( $file, $this->member ) );
		$this->assertFalse( Permissions::can_view_file( $file, $this->outsider ) );
	}

	public function test_product_doc_helpers_without_woo() {
		$this->assertTrue( Permissions::doc_is_expired( '2000-01-01' ) );
		$this->assertFalse( Permissions::doc_is_expired( '' ) );
		$this->assertFalse( Permissions::doc_is_expired( gmdate( 'Y-m-d', time() + 5 * DAY_IN_SECONDS ) ) );
		$this->assertSame( [ 'wc-completed', 'wc-processing' ], Permissions::entitled_order_statuses(), 'on-hold does not entitle (AFM-04)' );
		$pid = self::factory()->post->create();
		update_post_meta( $pid, '_anchor_pd_docs', [ [ 'fileId' => 7, 'title' => ' T ', 'expires' => '2030-01-01' ], [ 'fileId' => 0 ], 'junk' ] );
		$this->assertSame( [ [ 'fileId' => 7, 'title' => 'T', 'expires' => '2030-01-01' ] ], Permissions::product_docs( $pid ) );
	}

	// ---- Repository -----------------------------------------------------------------------------------------

	public function test_product_docs_folder_is_hidden_from_roots_and_children() {
		$pd = Schema::ensure_product_docs_folder();
		$open = $this->folder( 'Open' );
		$this->grant( 'folder', $open, 'role', 'subscriber' );
		$this->grant( 'folder', $pd, 'role', 'subscriber' ); // even when granted

		foreach ( [ $this->admin, $this->member ] as $uid ) {
			$ids = wp_list_pluck( Repository::roots( $uid ), 'id' );
			$this->assertNotContains( $pd, $ids, "roots hide product docs for $uid" );
		}
		$this->assertContains( $open, wp_list_pluck( Repository::roots( $this->member ), 'id' ) );
		$this->folder( 'Under', $pd );
		$this->assertSame( [], Repository::children( $pd, $this->admin )['folders'] );
		$this->assertSame( [], Repository::children( $pd, $this->admin )['files'], 'the container itself is not listable' );
	}

	public function test_children_lists_only_what_the_user_may_view_with_exact_shapes() {
		$f    = $this->folder( 'Docs' );
		$sub  = $this->folder( 'Sub', $f );
		$this->grant( 'folder', $f, 'role', 'subscriber' );
		$file = $this->file( $f, 'guide.pdf' );
		$link = $this->link( $f, 'Portal' );
		$vid  = $this->video( $f, 'Intro' );

		$out = Repository::children( $f, $this->member );
		$this->assertSame( [ 'folders', 'links', 'files', 'videos' ], array_keys( $out ) );
		$this->assertSame( [ [ 'id' => $sub, 'name' => 'Sub', 'isPrivate' => false, 'ownerUserId' => 0 ] ], $out['folders'] );
		$this->assertSame( [ 'id', 'name', 'mime', 'size', 'uploadedBy', 'createdAt' ], array_keys( $out['files'][0] ) );
		$this->assertSame( $file, $out['files'][0]['id'] );
		$this->assertSame( [ 'id', 'title', 'url', 'createdBy', 'createdAt' ], array_keys( $out['links'][0] ) );
		$this->assertSame( [ 'id', 'title', 'vimeoId', 'vimeoHash', 'thumbnailUrl', 'createdBy', 'createdAt' ], array_keys( $out['videos'][0] ) );
		$this->assertSame( $link, $out['links'][0]['id'] );
		$this->assertSame( $vid, $out['videos'][0]['id'] );

		$none = Repository::children( $f, $this->outsider );
		$this->assertSame( [ 'folders' => [], 'links' => [], 'files' => [], 'videos' => [] ], $none, 'no view right: nothing' );
	}

	public function test_root_children_are_folders_only_and_filtered() {
		$a = $this->folder( 'Alpha' );
		$b = $this->folder( 'Beta' );
		$this->grant( 'folder', $a, 'role', 'subscriber' );
		$root = Repository::children( 0, $this->member );
		$this->assertSame( [ $a ], wp_list_pluck( $root['folders'], 'id' ) );
		$this->assertSame( [], $root['files'] );
		$this->assertEqualsCanonicalizing( [ $a, $b ], wp_list_pluck( Repository::roots( $this->admin ), 'id' ) );
	}

	public function test_private_folders_are_not_listed() {
		$p = $this->folder( 'Private', 0, 0, 1 );
		$this->assertNotContains( $p, wp_list_pluck( Repository::roots( $this->admin ), 'id' ) );
	}

	public function test_watch_percent_is_the_requested_users_own() {
		global $wpdb;
		$f = $this->folder( 'F' );
		$this->grant( 'folder', $f, 'role', 'subscriber' );
		$this->grant( 'folder', $f, 'role', 'editor' );
		$vid = $this->video( $f );
		$wpdb->insert( Schema::table( 'video_views' ), [ 'user_id' => $this->member, 'video_id' => $vid, 'source' => Anchor_FM_Media_Progress::SOURCE_VIMEO, 'percent' => 42, 'first_viewed_at' => current_time( 'mysql' ), 'last_viewed_at' => current_time( 'mysql' ) ] );
		$this->assertSame( '', $wpdb->last_error );
		$this->assertSame( 42, Repository::children( $f, $this->member )['videos'][0]['watchPercent'] );
		$this->assertArrayNotHasKey( 'watchPercent', Repository::children( $f, $this->outsider )['videos'][0] );
	}

	public function test_search_filters_by_permission_and_caps_term_length() {
		$open   = $this->folder( 'Open Reports' );
		$closed = $this->folder( 'Closed Reports' );
		$this->grant( 'folder', $open, 'role', 'subscriber' );
		$this->file( $open, 'report-2026.pdf' );
		$this->file( $closed, 'report-secret.pdf' );
		$this->link( $open, 'Report portal' );
		$this->video( $closed, 'Report webinar' );

		$res = Repository::search( 'report', $this->member );
		$names = wp_list_pluck( $res['results'], 'name' );
		$this->assertEqualsCanonicalizing( [ 'Open Reports', 'report-2026.pdf', 'Report portal' ], $names );
		$this->assertFalse( $res['truncated'] );
		$file_hit = array_values( array_filter( $res['results'], fn( $r ) => 'file' === $r['kind'] ) )[0];
		$this->assertSame( 'Open Reports', $file_hit['path'] );
		$this->assertSame( $open, $file_hit['folderId'] );

		$this->assertSame( [ 'results' => [], 'truncated' => false ], Repository::search( 'r', $this->member ), 'one character is too short' );
		$this->assertCount( 6, Repository::search( 'report', $this->admin )['results'] );
	}

	public function test_search_treats_like_wildcards_literally() {
		$f = $this->folder( 'F' );
		$this->file( $f, '100%_done.pdf' );
		$this->file( $f, '100xxdone.pdf' );
		$names = wp_list_pluck( Repository::search( '0%_d', $this->admin )['results'], 'name' );
		$this->assertSame( [ '100%_done.pdf' ], $names );
	}

	public function test_breadcrumbs_run_root_to_leaf_and_survive_a_cycle() {
		global $wpdb;
		$a = $this->folder( 'A' );
		$b = $this->folder( 'B', $a );
		$c = $this->folder( 'C', $b );
		$this->assertSame( [ [ 'id' => $a, 'name' => 'A' ], [ 'id' => $b, 'name' => 'B' ], [ 'id' => $c, 'name' => 'C' ] ], Repository::breadcrumbs( $c ) );
		$this->assertSame( [], Repository::breadcrumbs( 0 ) );
		$wpdb->update( Schema::table( 'folders' ), [ 'parent_id' => $c ], [ 'id' => $a ] );
		$this->assertCount( 3, Repository::breadcrumbs( $c ), 'a parent cycle ends instead of looping' );
	}

	public function test_tree_hides_unviewable_branches_but_keeps_ancestors_of_visible_ones() {
		$a = $this->folder( 'A' );
		$b = $this->folder( 'B', $a );
		$x = $this->folder( 'X' );
		$this->grant( 'folder', $b, 'role', 'subscriber' );
		$tree = Repository::tree( $this->member );
		$this->assertSame( [ $a ], wp_list_pluck( $tree, 'id' ) );
		$this->assertSame( [ $b ], wp_list_pluck( $tree[0]['children'], 'id' ) );
		$this->assertNotContains( $x, wp_list_pluck( $tree, 'id' ) );
	}

	public function test_contents_map_matches_per_folder_listing_and_respects_the_ceiling() {
		$a = $this->folder( 'A' );
		$this->grant( 'folder', $a, 'role', 'subscriber' );
		$this->file( $a, 'one.pdf' );
		$closed = $this->folder( 'Closed' );
		$this->file( $closed, 'two.pdf' );

		$map = Repository::contents_map( $this->member );
		$this->assertSame( [ 0, $a ], array_keys( $map ) );
		$this->assertSame( Repository::children( $a, $this->member )['files'], $map[ $a ]['files'] );

		add_filter( 'anchor_fm_contents_preload_max', fn() => 1 );
		Permissions::reset_memo();
		$this->assertNull( Repository::contents_map( $this->member ), 'above the ceiling: no preload' );
	}

	// ---- Activity -------------------------------------------------------------------------------------------

	public function test_activity_log_records_actor_action_entity_and_meta() {
		global $wpdb;
		wp_set_current_user( $this->member );
		Activity::log( 'Upload File!', 'File', 12, [ 'name' => 'a.pdf' ] );
		Activity::log( 'delete', 'folder', 3, [], $this->admin );
		$rows = $wpdb->get_results( 'SELECT * FROM ' . Schema::table( 'activity' ) . ' ORDER BY id ASC' );
		$this->assertCount( 2, $rows );
		$this->assertSame( [ (string) $this->member, 'uploadfile', 'file', '12', '{"name":"a.pdf"}' ], [ $rows[0]->actor_user_id, $rows[0]->action, $rows[0]->entity_type, $rows[0]->entity_id, $rows[0]->meta ] );
		$this->assertSame( (string) $this->admin, $rows[1]->actor_user_id, 'explicit actor' );
		$this->assertNull( $rows[1]->meta );
	}

	// ---- review gaps: index fallbacks, policy via the index, product doc expiry ----------------------------------

	public function test_folder_created_after_the_index_loaded_falls_back_to_its_row() {
		$root = $this->folder( 'Root' );
		$this->grant( 'folder', $root, 'role', 'subscriber' );
		Permissions::with_memo( function () use ( $root ) {
			$late = $this->folder( 'Late', $root ); // not in the preloaded index: folder_ancestry() index miss
			$this->assertSame( 'view', Permissions::folder_capability( $late, $this->member ), 'inherits through the row fallback' );
			$this->assertSame( 'none', Permissions::folder_capability( $late, $this->outsider ) );
			$this->assertSame( 'none', Permissions::folder_capability( 999999, $this->member ), 'a folder that exists nowhere is still none' );
		} );
	}

	public function test_entity_folder_lookup_above_the_preload_ceiling_queries_the_row() {
		$f = $this->folder( 'F' );
		$this->grant( 'folder', $f, 'role', 'subscriber' );
		add_filter( 'anchor_fm_contents_preload_max', fn() => 1 );
		Permissions::reset_memo();
		$this->file( $f );
		$this->file( $f, 'b.pdf' );
		$this->assertTrue( Permissions::store_exceeds_preload_max() );
		Permissions::with_memo( function () use ( $f ) {
			$late = $this->file( $f, 'late.pdf' ); // would read as "no such file" if the index claimed to track files
			$this->assertTrue( Permissions::can_view_file( $late, $this->member ), 'untracked type: the row is queried' );
			$this->assertFalse( Permissions::can_view_file( $late, $this->outsider ) );
			$this->assertFalse( Permissions::can_view_file( 999999, $this->member ), 'a missing file is still denied' );
		} );
	}

	public function test_policy_grants_resolve_through_the_preloaded_index() {
		global $wpdb;
		$f = $this->folder( 'Policy' );
		$wpdb->insert( Schema::table( 'permission_policies' ), [
			'entity_type' => 'folder', 'entity_id' => $f, 'capability' => 'view',
			'policy' => wp_json_encode( [ 'operator' => 'any', 'rules' => [ [ 'operator' => 'all', 'conditions' => [ [ 'type' => 'role', 'role' => 'editor' ] ] ] ] ] ),
			'created_by' => 0, 'created_at' => current_time( 'mysql' ), 'updated_by' => 0, 'updated_at' => current_time( 'mysql' ),
		] );
		Permissions::with_memo( function () use ( $f, $wpdb ) {
			$before = $wpdb->num_queries;
			$this->assertSame( 'view', Permissions::folder_capability( $f, $this->outsider ) );
			$this->assertSame( 'none', Permissions::folder_capability( $f, $this->member ) );
			$this->assertNotEmpty( Permissions::policy( 'folder', $f )['rules'] ?? [], 'the stored policy reads back' );
			$this->assertSame( $before, $wpdb->num_queries, 'answered from the index, not per-entity queries' );
		} );
	}

	public function test_product_doc_expiry_runs_to_the_end_of_the_named_day() {
		$today = current_time( 'Y-m-d' );
		$this->assertFalse( Permissions::doc_is_expired( '' ), 'no date: never expires' );
		$this->assertFalse( Permissions::doc_is_expired( null ) );
		$this->assertFalse( Permissions::doc_is_expired( 'not a date' ), 'unparseable: not expired' );
		$this->assertTrue( Permissions::doc_is_expired( gmdate( 'Y-m-d', strtotime( $today . ' -1 day' ) ) ) );
		$this->assertFalse( Permissions::doc_is_expired( $today ), 'the day itself is still valid until 23:59:59' );
		$this->assertFalse( Permissions::doc_is_expired( gmdate( 'Y-m-d', strtotime( $today . ' +1 day' ) ) ) );
	}
}
