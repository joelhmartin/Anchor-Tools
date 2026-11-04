<?php
/**
 * Library file manager server side: batch move, link/video move, writer guards and data-path fixes.
 *
 * @package Anchor\Library\Tests
 * @group library
 */

use Anchor\Library\Module;
use Anchor\Library\Schema;
use Anchor\Library\Storage;

class Anchor_Library_Moves_Test extends WP_Ajax_UnitTestCase {
	/** @var mixed */
	private $saved_styles = null;

	/** Same table bootstrap as Anchor_Library_TestCase (this class needs the Ajax base instead). */
	public function set_up() {
		parent::set_up();
		require_once ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-library/anchor-library.php';
		remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
		remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );
		global $wpdb;
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Schema::table( 'folders' ) ) ) !== Schema::table( 'folders' ) ) {
			Schema::create_tables();
		}
		add_filter( 'anchor_library_legacy_active', '__return_false' );
		\Anchor\Library\Portal::reset();
		Module::boot();
		add_filter( 'pre_wp_mail', '__return_true' );
		$this->saved_styles = $GLOBALS['wp_styles'] ?? null;
		$GLOBALS['wp_scripts'] = new WP_Scripts();
		$GLOBALS['wp_styles']  = new WP_Styles(); // the portal enqueues once per process, like per request
	}

	public function tear_down() {
		$GLOBALS['wp_styles'] = $this->saved_styles; // do not leak a bare style registry into later suites
		wp_clear_scheduled_hook( Schema::CRON_PRUNE_RESUME );
		wp_clear_scheduled_hook( Schema::CRON_STORAGE_CHECK );
		$_FILES = [];
		parent::tear_down();
		// Booting installs the schema (DDL), and DDL commits: the Product Docs folder row it seeds would otherwise
		// outlive every test. The deletes, like the option deletes below, are committed by the next test's START.
		global $wpdb;
		foreach ( Schema::TABLES as $name ) {
			$wpdb->query( 'DELETE FROM ' . Schema::table( $name ) ); // phpcs:ignore
		}
		foreach ( [ 'anchor_fm_db_version', 'anchor_fm_pd_folder_id', 'anchor_fm_storage_check', 'anchor_fm_portal_roles' ] as $option ) {
			delete_option( $option );
		}
	}

	// ---- helpers --------------------------------------------------------------------------------------------

	/**
	 * The recursive folder delete wraps its writes in START TRANSACTION / COMMIT, which would commit the test's own
	 * transaction and leak rows into later tests. While a handler runs they are rewritten as savepoints.
	 */
	public function savepoints( $sql ) {
		return preg_replace(
			[ '/^\s*START TRANSACTION\s*$/i', '/^\s*COMMIT\s*$/i', '/^\s*ROLLBACK\s*$/i' ],
			[ 'SAVEPOINT afm_test', 'RELEASE SAVEPOINT afm_test', 'ROLLBACK TO SAVEPOINT afm_test' ],
			$sql
		);
	}

	private function call( string $action, array $post = [], string $role = 'administrator' ): array {
		if ( $role ) {
			$this->_setRole( $role );
		}
		$_POST    = array_merge( [ 'nonce' => wp_create_nonce( 'anchor_fm_nonce' ) ], $post );
		$_REQUEST = $_POST;
		$this->_last_response = '';
		add_filter( 'query', [ $this, 'savepoints' ] );
		try {
			$this->_handleAjax( $action );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		} catch ( WPAjaxDieStopException $e ) {
			unset( $e );
		}
		remove_filter( 'query', [ $this, 'savepoints' ] );
		return (array) json_decode( $this->_last_response, true );
	}

	private function folder( string $name = 'Docs', int $parent = 0 ): int {
		return (int) $this->call( 'anchor_fm_create_folder', [ 'name' => $name, 'parent_id' => $parent ] )['data']['folderId'];
	}

	/** A file row plus real bytes in the private store. */
	private function stored_file( int $folder_id, string $bytes = 'hello world' ): int {
		global $wpdb;
		$dir = Storage::ensure_folder_dir( $folder_id );
		file_put_contents( $dir . '/a.txt', $bytes );
		$wpdb->insert( Schema::table( 'files' ), [
			'folder_id' => $folder_id, 'original_name' => 'a.txt', 'stored_name' => 'a.txt', 'mime_type' => 'text/plain',
			'size' => strlen( $bytes ), 'sha1' => sha1( $bytes ), 'uploader_user_id' => 1, 'created_at' => current_time( 'mysql' ),
		] );
		return (int) $wpdb->insert_id;
	}

	private function link( int $folder, string $title = 'Site' ): int {
		return (int) $this->call( 'anchor_fm_create_link', [ 'folder_id' => $folder, 'title' => $title, 'url' => 'https://example.org' ] )['data']['linkId'];
	}

	private function video( int $folder, string $title = 'Clip' ): int {
		global $wpdb;
		$wpdb->insert( Schema::table( 'videos' ), [
			'folder_id' => $folder, 'vimeo_id' => '123456', 'title' => $title, 'created_by' => 1,
			'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		return (int) $wpdb->insert_id;
	}

	private function named_file( int $folder_id, string $name, string $bytes = 'data' ): int {
		$id = $this->stored_file( $folder_id, $bytes );
		global $wpdb;
		$wpdb->update( Schema::table( 'files' ), [ 'original_name' => $name ], [ 'id' => $id ] );
		return $id;
	}

	private function grant_user( string $type, int $id, int $user_id ): void {
		global $wpdb;
		$wpdb->insert( Schema::table( 'permissions' ), [
			'entity_type' => $type, 'entity_id' => $id, 'subject_type' => 'user', 'subject_key' => (string) $user_id,
			'capability' => 'view', 'created_by' => 1, 'created_at' => current_time( 'mysql' ),
		] );
	}

	private function col( string $table, string $col, int $id ) {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SELECT ' . $col . ' FROM ' . Schema::table( $table ) . ' WHERE id = %d', $id ) );
	}

	private function move_items( array $items, int $target ): array {
		return $this->call( 'anchor_fm_move_items', [ 'target_folder_id' => $target, 'items' => wp_json_encode( $items ) ] );
	}

	// ---- batch move -----------------------------------------------------------------------------------------

	public function test_move_items_moves_every_kind_and_reports_from_folder() {
		$a = $this->folder( 'A' );
		$b = $this->folder( 'B' );
		$sub = $this->folder( 'Sub', $a );
		$file = $this->stored_file( $a );
		$link = $this->link( $a );
		$video = $this->video( $a );

		$res = $this->move_items( [
			[ 'kind' => 'file', 'id' => $file ], [ 'kind' => 'link', 'id' => $link ],
			[ 'kind' => 'video', 'id' => $video ], [ 'kind' => 'folder', 'id' => $sub ],
		], $b );
		$this->assertTrue( $res['success'], wp_json_encode( $res ) );
		$this->assertSame( 4, $res['data']['moved'] );
		$this->assertSame( 0, $res['data']['errors'] );
		$this->assertSame( $b, $res['data']['targetFolderId'] );
		foreach ( $res['data']['items'] as $item ) {
			$this->assertSame( 'moved', $item['status'], wp_json_encode( $item ) );
			$this->assertSame( $a, $item['fromFolderId'] );
			$this->assertNotSame( '', $item['name'] );
		}
		$this->assertSame( $b, (int) $this->col( 'files', 'folder_id', $file ) );
		$this->assertSame( $b, (int) $this->col( 'links', 'folder_id', $link ) );
		$this->assertSame( $b, (int) $this->col( 'videos', 'folder_id', $video ) );
		$this->assertSame( $b, (int) $this->col( 'folders', 'parent_id', $sub ) );
		$stored = $this->col( 'files', 'stored_name', $file );
		$this->assertFileExists( Storage::base_dir() . '/' . $b . '/' . $stored );
		$this->assertFileDoesNotExist( Storage::base_dir() . '/' . $a . '/a.txt' );
	}

	public function test_move_items_to_top_level_moves_folders_only() {
		$a = $this->folder( 'A' );
		$sub = $this->folder( 'Sub', $a );
		$file = $this->stored_file( $a );
		$res = $this->move_items( [ [ 'kind' => 'folder', 'id' => $sub ], [ 'kind' => 'file', 'id' => $file ] ], 0 );
		$this->assertTrue( $res['success'] );
		$this->assertSame( 1, $res['data']['moved'] );
		$this->assertSame( 1, $res['data']['errors'] );
		$this->assertSame( 0, (int) $this->col( 'folders', 'parent_id', $sub ) );
		$this->assertSame( 'error', $res['data']['items'][1]['status'] );
		$this->assertSame( 'Only folders can go at the top level', $res['data']['items'][1]['message'] );
		$this->assertSame( $a, (int) $this->col( 'files', 'folder_id', $file ) );
	}

	public function test_move_items_refuses_into_self_or_descendant() {
		$a = $this->folder( 'A' );
		$b = $this->folder( 'B', $a );
		$self = $this->move_items( [ [ 'kind' => 'folder', 'id' => $a ] ], $a );
		$this->assertSame( 'error', $self['data']['items'][0]['status'] );
		$child = $this->move_items( [ [ 'kind' => 'folder', 'id' => $a ] ], $b );
		$this->assertSame( 'error', $child['data']['items'][0]['status'] );
		$this->assertSame( 'Cannot move into a child folder', $child['data']['items'][0]['message'] );
		$this->assertSame( 0, (int) $this->col( 'folders', 'parent_id', $a ) );
	}

	public function test_move_items_dedupes_names() {
		$a = $this->folder( 'A' );
		$b = $this->folder( 'B' );
		$moving = $this->named_file( $a, 'Guide.pdf', 'one' );
		$this->named_file( $b, 'Guide.pdf', 'two' );
		$expected = Anchor_FM_Copy_Namer::resolve_unique( 'Guide.pdf', [ 'Guide.pdf' ], true, false );
		$res = $this->move_items( [ [ 'kind' => 'file', 'id' => $moving ] ], $b );
		$this->assertSame( $expected, $res['data']['items'][0]['name'] );
		$this->assertSame( $expected, $this->col( 'files', 'original_name', $moving ) );
		$this->assertNotSame( 'Guide.pdf', $expected );
	}

	public function test_move_items_skips_duplicates_and_reports_unchanged() {
		$a = $this->folder( 'A' );
		$b = $this->folder( 'B' );
		$file = $this->stored_file( $a );
		$here = $this->link( $b );
		$res = $this->move_items( [
			[ 'kind' => 'file', 'id' => $file ], [ 'kind' => 'file', 'id' => $file ], [ 'kind' => 'link', 'id' => $here ],
		], $b );
		$this->assertSame( 1, $res['data']['moved'] );
		$this->assertSame( 0, $res['data']['errors'] );
		$this->assertCount( 2, $res['data']['items'], 'the repeated file is one entry' );
		$this->assertSame( 'moved', $res['data']['items'][0]['status'] );
		$this->assertSame( 'unchanged', $res['data']['items'][1]['status'] );
	}

	public function test_move_items_caps_the_batch() {
		$b = $this->folder( 'B' );
		$items = [];
		for ( $i = 1; $i <= 501; $i++ ) {
			$items[] = [ 'kind' => 'file', 'id' => $i ];
		}
		$res = $this->move_items( $items, $b );
		$this->assertFalse( $res['success'] );
		$this->assertSame( 'Too many items', $res['data']['message'] );
	}

	public function test_move_items_refuses_missing_target() {
		$a = $this->folder( 'A' );
		$file = $this->stored_file( $a );
		$res = $this->move_items( [ [ 'kind' => 'file', 'id' => $file ] ], 987654 );
		$this->assertFalse( $res['success'] );
		$this->assertSame( 'Target not found', $res['data']['message'] );
	}

	public function test_move_items_is_admin_only() {
		$a = $this->folder( 'A' );
		$b = $this->folder( 'B' );
		$file = $this->stored_file( $a );
		$res = $this->call( 'anchor_fm_move_items', [ 'target_folder_id' => $b, 'items' => wp_json_encode( [ [ 'kind' => 'file', 'id' => $file ] ] ) ], 'subscriber' );
		$this->assertFalse( $res['success'] );
		$this->assertSame( $a, (int) $this->col( 'files', 'folder_id', $file ) );
	}

	public function test_writers_refuse_deleted_folder() {
		global $wpdb;
		$gone = $this->folder( 'Gone' );
		$live = $this->folder( 'Live' );
		$file = $this->stored_file( $live );
		$wpdb->delete( Schema::table( 'folders' ), [ 'id' => $gone ] );

		$_FILES = [ 'files' => [ 'name' => [ 'x.txt' ], 'tmp_name' => [ '/nonexistent' ], 'size' => [ 1 ], 'error' => [ UPLOAD_ERR_OK ], 'type' => [ 'text/plain' ] ] ];
		$this->assertFalse( $this->call( 'anchor_fm_upload', [ 'folder_id' => $gone ] )['success'] );
		$_FILES = [];
		$this->assertFalse( $this->call( 'anchor_fm_create_folder', [ 'name' => 'Orphan', 'parent_id' => $gone ] )['success'] );
		$this->assertFalse( $this->call( 'anchor_fm_move_file', [ 'file_id' => $file, 'folder_id' => $gone ] )['success'] );
		$this->assertFalse( $this->call( 'anchor_fm_set_permissions', [ 'entity_type' => 'folder', 'entity_id' => $gone ] )['success'] );
		$this->assertFalse( $this->call( 'anchor_fm_rename_folder', [ 'folder_id' => $gone, 'name' => 'Nope' ] )['success'] );
		$this->assertFalse( $this->call( 'anchor_fm_create_link', [ 'folder_id' => $gone, 'title' => 'T', 'url' => 'https://example.org' ] )['success'] );
		$this->assertSame( $live, (int) $this->col( 'files', 'folder_id', $file ) );
	}

	public function test_single_move_handlers_delegate_and_keep_their_shape() {
		$a = $this->folder( 'A' );
		$b = $this->folder( 'B' );
		$sub = $this->folder( 'Sub', $a );
		$file = $this->stored_file( $a );
		$this->assertTrue( $this->call( 'anchor_fm_move_file', [ 'file_id' => $file, 'folder_id' => $b ] )['data']['moved'] );
		$this->assertTrue( $this->call( 'anchor_fm_move_folder', [ 'folder_id' => $sub, 'target_folder_id' => $b ] )['data']['moved'] );
		$self = $this->call( 'anchor_fm_move_folder', [ 'folder_id' => $a, 'target_folder_id' => $a ] );
		$this->assertFalse( $self['success'] );
		$this->assertSame( 'Cannot move into itself', $self['data']['message'] );
		$this->assertSame( $b, (int) $this->col( 'files', 'folder_id', $file ) );
	}

	// ---- permissions ----------------------------------------------------------------------------------------

	public function test_move_file_does_not_snapshot_destination_grants() {
		global $wpdb;
		$a = $this->folder( 'A' );
		$b = $this->folder( 'B' );
		$member = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->grant_user( 'folder', $b, $member );
		$file = $this->stored_file( $a );
		$this->assertTrue( $this->call( 'anchor_fm_move_file', [ 'file_id' => $file, 'folder_id' => $b ] )['success'] );
		$rows = (int) $wpdb->get_var( 'SELECT COUNT(1) FROM ' . Schema::table( 'permissions' ) . " WHERE entity_type = 'file' AND entity_id = $file" );
		$this->assertSame( 0, $rows );
		$policies = (int) $wpdb->get_var( 'SELECT COUNT(1) FROM ' . Schema::table( 'permission_policies' ) . " WHERE entity_type = 'file' AND entity_id = $file" );
		$this->assertSame( 0, $policies );
		// It still inherits the destination at read time.
		$this->assertTrue( \Anchor\Library\Permissions::with_memo( fn() => \Anchor\Library\Permissions::can_view_file( $file, $member ) ) );
	}

	public function test_non_admin_manager_cannot_delete_file_or_set_permissions() {
		$a = $this->folder( 'A' );
		$file = $this->stored_file( $a );
		$owner = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		global $wpdb;
		$wpdb->update( Schema::table( 'folders' ), [ 'owner_user_id' => $owner ], [ 'id' => $a ] );
		wp_set_current_user( $owner );
		$this->assertTrue( \Anchor\Library\Permissions::can_manage_file( $file, $owner ), 'precondition: the owner manages the file' );
		$this->assertFalse( $this->call( 'anchor_fm_delete_file', [ 'file_id' => $file ], '' )['success'] );
		$this->assertFalse( $this->call( 'anchor_fm_get_permissions', [ 'entity_type' => 'folder', 'entity_id' => $a ], '' )['success'] );
		$this->assertFalse( $this->call( 'anchor_fm_set_permissions', [ 'entity_type' => 'folder', 'entity_id' => $a ], '' )['success'] );
		$this->assertNotNull( $this->col( 'files', 'id', $file ) );
	}

	public function test_set_permissions_rejects_unknown_user_id() {
		global $wpdb;
		$a = $this->folder( 'A' );
		$res = $this->call( 'anchor_fm_set_permissions', [ 'entity_type' => 'folder', 'entity_id' => $a, 'users' => [ [ 'id' => 99999999, 'name' => 'Ghost' ] ] ] );
		$this->assertFalse( $res['success'] );
		$this->assertSame( 0, (int) $wpdb->get_var( 'SELECT COUNT(1) FROM ' . Schema::table( 'permissions' ) . ' WHERE subject_key = \'99999999\'' ) );
	}

	public function test_product_docs_folder_is_protected() {
		$this->_setRole( 'administrator' );
		$pd = Schema::ensure_product_docs_folder();
		$other = $this->folder( 'Other' );
		$msg = 'The Product Docs folder is managed automatically';
		foreach ( [
			[ 'anchor_fm_delete_folder', [ 'folder_id' => $pd ] ],
			[ 'anchor_fm_rename_folder', [ 'folder_id' => $pd, 'name' => 'X' ] ],
			[ 'anchor_fm_move_folder', [ 'folder_id' => $pd, 'target_folder_id' => $other ] ],
			[ 'anchor_fm_create_folder', [ 'parent_id' => $pd, 'name' => 'Inside' ] ],
			[ 'anchor_fm_set_permissions', [ 'entity_type' => 'folder', 'entity_id' => $pd ] ],
		] as [ $action, $post ] ) {
			$res = $this->call( $action, $post );
			$this->assertFalse( $res['success'], $action );
			$this->assertSame( $msg, $res['data']['message'], $action );
		}
		$this->assertNotNull( $this->col( 'folders', 'id', $pd ) );
	}

	public function test_list_guard_does_not_block_root_when_pd_option_is_missing() {
		delete_option( Schema::OPT_PD_FOLDER_ID );
		$this->assertTrue( $this->call( 'anchor_fm_list', [ 'folder_id' => 0 ] )['success'] );
	}

	// ---- delete ---------------------------------------------------------------------------------------------

	public function test_delete_folder_removes_policies_dirs_and_pd_refs() {
		global $wpdb;
		$a = $this->folder( 'A' );
		$b = $this->folder( 'B', $a );
		$file = $this->stored_file( $b );
		$policy = wp_json_encode( [ 'rules' => [ [ 'conditions' => [ [ 'type' => 'role', 'role' => 'subscriber' ] ] ] ] ] );
		foreach ( [ [ 'folder', $a ], [ 'file', $file ] ] as [ $type, $id ] ) {
			$wpdb->insert( Schema::table( 'permission_policies' ), [
				'entity_type' => $type, 'entity_id' => $id, 'capability' => 'view', 'policy' => $policy,
				'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_by' => 1, 'updated_at' => current_time( 'mysql' ),
			] );
		}
		$product = self::factory()->post->create( [ 'post_type' => 'product' ] );
		update_post_meta( $product, '_anchor_pd_docs', [ [ 'fileId' => $file, 'title' => 'Doc' ], [ 'fileId' => $file + 1000, 'title' => 'Keep' ] ] );
		$dir_b = Storage::base_dir() . '/' . $b;
		$this->assertDirectoryExists( $dir_b );
		// Folder ids repeat across rolled-back tests while the store persists: start A's directory from guards only.
		$dir_a = Storage::base_dir() . '/' . $a;
		array_map( 'unlink', array_filter( (array) glob( $dir_a . '/{,.}*', GLOB_BRACE ), 'is_file' ) );
		@rmdir( $dir_a );
		Storage::ensure_folder_dir( $a );
		$this->assertFileExists( $dir_a . '/.htaccess' );

		$res = $this->call( 'anchor_fm_delete_folder', [ 'folder_id' => $a ] );
		$this->assertTrue( $res['success'], wp_json_encode( $res ) );

		$this->assertSame( 0, (int) $wpdb->get_var( 'SELECT COUNT(1) FROM ' . Schema::table( 'permission_policies' ) ) );
		$this->assertDirectoryDoesNotExist( $dir_b );
		$this->assertDirectoryDoesNotExist( $dir_a );
		$left = get_post_meta( $product, '_anchor_pd_docs', true );
		$this->assertCount( 1, $left );
		$this->assertSame( $file + 1000, (int) $left[0]['fileId'] );
		$this->assertNull( $this->col( 'files', 'id', $file ) );
	}

	public function test_delete_file_removes_policy_and_pd_refs() {
		global $wpdb;
		$a = $this->folder( 'A' );
		$file = $this->stored_file( $a );
		$wpdb->insert( Schema::table( 'permission_policies' ), [
			'entity_type' => 'file', 'entity_id' => $file, 'capability' => 'view', 'policy' => '{}',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_by' => 1, 'updated_at' => current_time( 'mysql' ),
		] );
		$product = self::factory()->post->create( [ 'post_type' => 'product' ] );
		update_post_meta( $product, '_anchor_pd_docs', [ [ 'fileId' => $file, 'title' => 'Doc' ] ] );
		$this->assertTrue( $this->call( 'anchor_fm_delete_file', [ 'file_id' => $file ] )['success'] );
		$this->assertSame( 0, (int) $wpdb->get_var( 'SELECT COUNT(1) FROM ' . Schema::table( 'permission_policies' ) ) );
		$this->assertSame( '', get_post_meta( $product, '_anchor_pd_docs', true ) );
	}

	// ---- upload ---------------------------------------------------------------------------------------------

	public function test_upload_reports_oversize_as_rejected() {
		$a = $this->folder( 'A' );
		$_FILES = [ 'files' => [
			'name' => [ 'big.pdf', 'broken.pdf' ], 'tmp_name' => [ '', '' ], 'size' => [ 0, 0 ],
			'error' => [ UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_PARTIAL ], 'type' => [ '', '' ],
		] ];
		$res = $this->call( 'anchor_fm_upload', [ 'folder_id' => $a ] );
		$this->assertTrue( $res['success'] );
		$this->assertSame( [], $res['data']['uploaded'] );
		$this->assertSame( [ 'name' => 'big.pdf', 'reason' => 'too_large' ], $res['data']['rejected'][0] );
		$this->assertSame( [ 'name' => 'broken.pdf', 'reason' => 'server' ], $res['data']['rejected'][1] );
	}

	// ---- download -------------------------------------------------------------------------------------------

	public function test_download_folder_check_mode() {
		$a = $this->folder( 'A' );
		$empty = $this->folder( 'Empty' );
		$this->stored_file( $a );
		$ok = $this->call( 'anchor_fm_download_folder', [ 'folder_id' => $a, 'check' => 1 ] );
		$this->assertTrue( $ok['success'] );
		$this->assertSame( 1, $ok['data']['files'] );
		$none = $this->call( 'anchor_fm_download_folder', [ 'folder_id' => $empty, 'check' => 1 ] );
		$this->assertFalse( $none['success'] );
		$this->assertSame( 'No files to download', $none['data']['message'] );
	}

	public function test_zip_paths_are_relative_to_folder() {
		$a = $this->folder( 'A' );
		$b = $this->folder( 'B', $a );
		$c = $this->folder( 'Child', $b );
		$this->named_file( $b, 'x.txt' );
		$this->named_file( $c, 'y.txt' );
		$zip = \Anchor\Library\Permissions::with_memo( fn() => \Anchor\Library\Ajax\Folders::zip_entries( $b, get_current_user_id() ) );
		$entries = array_column( $zip['files'], 'entry' );
		sort( $entries );
		$this->assertSame( [ 'child/y.txt', 'x.txt' ], $entries );
		$this->assertSame( 'b', $zip['name'] );
	}

	// ---- C1 home --------------------------------------------------------------------------------------------

	public function test_home_lists_topmost_viewable_folders_for_nested_grant() {
		$a = $this->folder( 'A' );
		$b = $this->folder( 'B', $a );
		$member = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->grant_user( 'folder', $b, $member );
		wp_set_current_user( $member );

		$list = $this->call( 'anchor_fm_list', [ 'folder_id' => 0 ], '' );
		$this->assertSame( [ $b ], array_column( $list['data']['folders'], 'id' ) );

		$boot = $this->call( 'anchor_fm_bootstrap', [], '' );
		$this->assertSame( [ $b ], array_column( $boot['data']['tree'], 'id' ), 'the tree is rooted at B; A is never named' );
		$this->assertStringNotContainsString( '"A"', wp_json_encode( $boot['data']['tree'] ) );

		$_GET = [];
		$req = new WP_REST_Request( 'GET', '/' . \Anchor\Library\Rest\Routes::NS . '/folders/0' );
		$res = rest_do_request( $req );
		$ids = array_map( fn( $f ) => (int) $f['id'], $res->get_data()['folders'] ?? [] );
		$this->assertSame( [ $b ], $ids );

		// Ruling 5: no trail names A anywhere the member can see.
		$file = $this->named_file( $b, 'inside.txt' );
		$crumbs = $this->call( 'anchor_fm_list', [ 'folder_id' => $b ], '' )['data']['breadcrumbs'];
		$this->assertSame( [ $b ], array_column( $crumbs, 'id' ) );
		$rest = rest_do_request( new WP_REST_Request( 'GET', '/' . \Anchor\Library\Rest\Routes::NS . '/folders/' . $b ) )->get_data();
		$this->assertSame( [ $b ], array_map( fn( $c ) => (int) $c['id'], $rest['breadcrumbs'] ) );
		$hit = \Anchor\Library\Repository::search( 'inside', $member )['results'][0];
		$this->assertSame( 'B', $hit['path'] );
		$admin_hit = \Anchor\Library\Repository::search( 'inside', 1 )['results'][0];
		$this->assertSame( 'A › B', $admin_hit['path'], 'an admin still sees the full path' );
	}

	public function test_admin_home_and_tree_are_unchanged() {
		$a = $this->folder( 'A' );
		$b = $this->folder( 'B', $a );
		$list = $this->call( 'anchor_fm_list', [ 'folder_id' => 0 ] );
		$this->assertSame( [ $a ], array_column( $list['data']['folders'], 'id' ) );
		$tree = $this->call( 'anchor_fm_bootstrap' )['data']['tree'];
		$this->assertSame( [ $a ], array_column( $tree, 'id' ) );
		$this->assertSame( [ $b ], array_column( $tree[0]['children'], 'id' ) );
	}

	// ---- D7c search -----------------------------------------------------------------------------------------

	public function test_search_applies_permissions_before_limit() {
		global $wpdb;
		$hidden = $this->folder( 'Hidden' );
		$shown = $this->folder( 'Shown' );
		$member = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->grant_user( 'folder', $shown, $member );
		$rows = [];
		for ( $i = 0; $i < 210; $i++ ) {
			$rows[] = $wpdb->prepare( '(%d, %s, %s, %s, 1, 1, %s)', $hidden, sprintf( 'aaa-report-%03d.txt', $i ), sprintf( 'h%03d.txt', $i ), 'text/plain', current_time( 'mysql' ) );
		}
		$wpdb->query( 'INSERT INTO ' . Schema::table( 'files' ) . ' (folder_id, original_name, stored_name, mime_type, size, uploader_user_id, created_at) VALUES ' . implode( ',', $rows ) ); // phpcs:ignore
		$wpdb->insert( Schema::table( 'files' ), [
			'folder_id' => $shown, 'original_name' => 'zzz-report.txt', 'stored_name' => 'z.txt', 'mime_type' => 'text/plain',
			'size' => 1, 'uploader_user_id' => 1, 'created_at' => current_time( 'mysql' ),
		] );
		$res = \Anchor\Library\Repository::search( 'report', $member );
		$this->assertSame( [ 'zzz-report.txt' ], array_column( $res['results'], 'name' ) );
		$this->assertFalse( $res['truncated'] );
	}

	public function test_search_pages_past_unviewable_candidates_and_flags_ceiling() {
		global $wpdb;
		$hidden = $this->folder( 'Hidden' );
		$shown = $this->folder( 'Shown' );
		$member = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->grant_user( 'folder', $shown, $member );
		// 1,100 unviewable matches sort first (more than 5 x the 200 cap), the viewable one last.
		for ( $c = 0; $c < 11; $c++ ) {
			$rows = [];
			for ( $i = 0; $i < 100; $i++ ) {
				$rows[] = $wpdb->prepare( '(%d, %s, %s, %s, 1, 1, %s)', $hidden, sprintf( 'aaa-page-%04d.txt', $c * 100 + $i ), sprintf( 'p%04d.txt', $c * 100 + $i ), 'text/plain', current_time( 'mysql' ) );
			}
			$wpdb->query( 'INSERT INTO ' . Schema::table( 'files' ) . ' (folder_id, original_name, stored_name, mime_type, size, uploader_user_id, created_at) VALUES ' . implode( ',', $rows ) ); // phpcs:ignore
		}
		$wpdb->insert( Schema::table( 'files' ), [
			'folder_id' => $shown, 'original_name' => 'zzz-page.txt', 'stored_name' => 'zp.txt', 'mime_type' => 'text/plain',
			'size' => 1, 'uploader_user_id' => 1, 'created_at' => current_time( 'mysql' ),
		] );
		$res = \Anchor\Library\Repository::search( 'page', $member );
		$this->assertSame( [ 'zzz-page.txt' ], array_column( $res['results'], 'name' ) );
		$this->assertFalse( $res['truncated'] );
	}

	public function test_search_flags_truncated_when_scan_ceiling_stops_it() {
		global $wpdb;
		$hidden = $this->folder( 'Hidden' );
		$member = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		for ( $c = 0; $c < 51; $c++ ) {
			$rows = [];
			for ( $i = 0; $i < 100; $i++ ) {
				$rows[] = $wpdb->prepare( '(%d, %s, %s, %s, 1, 1, %s)', $hidden, sprintf( 'ceil-%04d.txt', $c * 100 + $i ), sprintf( 'c%04d.txt', $c * 100 + $i ), 'text/plain', current_time( 'mysql' ) );
			}
			$wpdb->query( 'INSERT INTO ' . Schema::table( 'files' ) . ' (folder_id, original_name, stored_name, mime_type, size, uploader_user_id, created_at) VALUES ' . implode( ',', $rows ) ); // phpcs:ignore
		}
		$res = \Anchor\Library\Repository::search( 'ceil', $member );
		$this->assertSame( [], $res['results'] );
		$this->assertTrue( $res['truncated'] );
	}

	// ---- copy / rename --------------------------------------------------------------------------------------

	public function test_copy_folder_tree_counts_child_failures() {
		global $wpdb;
		$a = $this->folder( 'A' );
		$wpdb->insert( Schema::table( 'files' ), [
			'folder_id' => $a, 'original_name' => 'ghost.txt', 'stored_name' => 'ghost.txt', 'mime_type' => 'text/plain',
			'size' => 1, 'uploader_user_id' => 1, 'created_at' => current_time( 'mysql' ),
		] );
		$dest = $this->folder( 'Dest' );
		$res = $this->call( 'anchor_fm_copy_items', [ 'target_folder_id' => $dest, 'items' => wp_json_encode( [ [ 'kind' => 'folder', 'id' => $a ] ] ) ] );
		$this->assertTrue( $res['success'] );
		$this->assertGreaterThan( 0, $res['data']['errors'] );
		$this->assertSame( 'error', $res['data']['items'][0]['status'] );
	}

	public function test_rename_file_keeps_display_name() {
		$a = $this->folder( 'A' );
		$file = $this->named_file( $a, 'Intake Form.pdf' );
		$res = $this->call( 'anchor_fm_rename_file', [ 'file_id' => $file, 'name' => 'Intake Form v2.pdf' ] );
		$this->assertSame( 'Intake Form v2.pdf', $res['data']['name'] );
		$this->assertSame( 'Intake Form v2.pdf', $this->col( 'files', 'original_name', $file ) );
		$this->assertSame( 'a.txt', $this->col( 'files', 'stored_name', $file ), 'the stored disk name is unchanged' );
		$res = $this->call( 'anchor_fm_rename_file', [ 'file_id' => $file, 'name' => 'New name' ] );
		$this->assertSame( 'New name.pdf', $res['data']['name'], 'a dropped extension is kept' );
		$res = $this->call( 'anchor_fm_rename_file', [ 'file_id' => $file, 'name' => 'a/b\\c.pdf' ] );
		$this->assertSame( 'abc.pdf', $res['data']['name'] );
	}

	public function test_folder_rename_refuses_same_name_sibling() {
		$a = $this->folder( 'Alpha' );
		$b = $this->folder( 'Beta' );
		$dup = $this->call( 'anchor_fm_create_folder', [ 'name' => 'Alpha', 'parent_id' => 0 ] );
		$this->assertFalse( $dup['success'] );
		$this->assertSame( 'A folder with that name already exists here', $dup['data']['message'] );
		$ren = $this->call( 'anchor_fm_rename_folder', [ 'folder_id' => $b, 'name' => 'Alpha' ] );
		$this->assertFalse( $ren['success'] );
		$this->assertSame( 'A folder with that name already exists here', $ren['data']['message'] );
		$this->assertTrue( $this->call( 'anchor_fm_rename_folder', [ 'folder_id' => $a, 'name' => 'Alpha' ] )['success'], 'its own name is not a clash' );
		$this->assertTrue( $this->folder( 'Alpha', $a ) > 0, 'same name under a different parent is fine' );
	}

	// ---- final review fixes ---------------------------------------------------------------------------------

	public function test_move_releases_legacy_snapshot_grants_and_inherits_destination() {
		global $wpdb;
		$a = $this->folder( 'A' );
		$b = $this->folder( 'B' );
		$member = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->grant_user( 'folder', $a, $member );
		$file = $this->stored_file( $a );
		$this->grant_user( 'file', $file, $member ); // what an older move left behind: a copy of A's direct grants
		$res = $this->move_items( [ [ 'kind' => 'file', 'id' => $file ] ], $b );
		$this->assertSame( 'moved', $res['data']['items'][0]['status'] );
		$this->assertArrayNotHasKey( 'keptPermissions', $res['data']['items'][0] );
		$rows = (int) $wpdb->get_var( 'SELECT COUNT(1) FROM ' . Schema::table( 'permissions' ) . " WHERE entity_type = 'file' AND entity_id = $file" );
		$this->assertSame( 0, $rows );
		$this->assertFalse( \Anchor\Library\Permissions::with_memo( fn() => \Anchor\Library\Permissions::can_view_file( $file, $member ) ), 'now inherits B, which does not grant the member' );
	}

	public function test_move_keeps_custom_file_grants_and_flags_them() {
		global $wpdb;
		$a = $this->folder( 'A' );
		$b = $this->folder( 'B' );
		$member = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$file = $this->stored_file( $a );
		$this->grant_user( 'file', $file, $member ); // A grants nobody directly, so this differs
		$res = $this->move_items( [ [ 'kind' => 'file', 'id' => $file ] ], $b );
		$this->assertTrue( $res['data']['items'][0]['keptPermissions'] );
		$rows = (int) $wpdb->get_var( 'SELECT COUNT(1) FROM ' . Schema::table( 'permissions' ) . " WHERE entity_type = 'file' AND entity_id = $file" );
		$this->assertSame( 1, $rows );
	}

	public function test_move_items_restores_requested_name_when_free_else_dedupes() {
		$a = $this->folder( 'A' );
		$b = $this->folder( 'B' );
		$file = $this->named_file( $a, 'Guide.pdf' );
		$this->named_file( $b, 'Guide.pdf' );
		$out = $this->move_items( [ [ 'kind' => 'file', 'id' => $file ] ], $b )['data']['items'][0];
		$this->assertSame( 'Guide.pdf', $out['fromName'] );
		$this->assertNotSame( 'Guide.pdf', $out['name'] );
		$back = $this->move_items( [ [ 'kind' => 'file', 'id' => $file, 'name' => $out['fromName'] ] ], $a )['data']['items'][0];
		$this->assertSame( 'Guide.pdf', $back['name'] );
		$this->assertSame( 'Guide.pdf', $this->col( 'files', 'original_name', $file ) );
		// Taken in the target: the normal de-dup applies.
		$other = $this->named_file( $b, 'Guide.pdf' );
		$again = $this->move_items( [ [ 'kind' => 'file', 'id' => $file, 'name' => 'Guide.pdf' ] ], $b )['data']['items'][0];
		$this->assertNotSame( 'Guide.pdf', $again['name'] );
		$this->assertNotNull( $other );
	}

	public function test_product_docs_is_not_a_copy_target_or_a_move_or_copy_source() {
		$this->_setRole( 'administrator' );
		global $wpdb;
		$pd = Schema::ensure_product_docs_folder();
		$other = $this->folder( 'Other' );
		$file = $this->stored_file( $pd );
		$msg = 'The Product Docs folder is managed automatically';
		$mv = $this->move_items( [ [ 'kind' => 'file', 'id' => $file ] ], $other )['data']['items'][0];
		$this->assertSame( 'error', $mv['status'] );
		$this->assertSame( $msg, $mv['message'] );
		$cp = $this->call( 'anchor_fm_copy_items', [ 'target_folder_id' => $other, 'items' => wp_json_encode( [ [ 'kind' => 'file', 'id' => $file ] ] ) ] );
		$this->assertSame( $msg, $cp['data']['items'][0]['message'] );
		$mine = $this->stored_file( $other );
		$into = $this->call( 'anchor_fm_copy_items', [ 'target_folder_id' => $pd, 'items' => wp_json_encode( [ [ 'kind' => 'file', 'id' => $mine ] ] ) ] );
		$this->assertFalse( $into['success'] );
		$this->assertSame( $msg, $into['data']['message'] );
		$this->assertSame( 1, (int) $wpdb->get_var( 'SELECT COUNT(1) FROM ' . Schema::table( 'files' ) . " WHERE folder_id = $pd" ) );
	}

	public function test_product_docs_folder_itself_cannot_be_copied() {
		$this->_setRole( 'administrator' );
		$pd = Schema::ensure_product_docs_folder();
		$other = $this->folder( 'Other' );
		$cp = $this->call( 'anchor_fm_copy_items', [ 'target_folder_id' => $other, 'items' => wp_json_encode( [ [ 'kind' => 'folder', 'id' => $pd ] ] ) ] );
		$this->assertSame( 'The Product Docs folder is managed automatically', $cp['data']['items'][0]['message'] );
		$this->assertSame( 'error', $cp['data']['items'][0]['status'] );
	}
}
