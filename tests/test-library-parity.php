<?php
/**
 * Library parity: everything the Anchor Private File Manager 2.17.0 registers or serves is present and behaves the same.
 *
 * @package Anchor\Library\Tests
 * @group library
 */

use Anchor\Library\Module;
use Anchor\Library\Schema;
use Anchor\Library\Storage;

class Anchor_Library_Parity_Test extends WP_Ajax_UnitTestCase {
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

	/** @return array{0:int[],1:string} status codes sent, and the output */
	private function stream( int $file_id, int $user_id ): array {
		wp_set_current_user( $user_id );
		$_GET = [ 'file_id' => $file_id, 'nonce' => wp_create_nonce( 'anchor_fm_stream_' . $file_id ), 'disposition' => 'inline' ];
		unset( $_SERVER['HTTP_RANGE'] );
		$codes = [];
		add_filter( 'status_header', static function ( $header, $code ) use ( &$codes ) {
			$codes[] = $code;
			return $header;
		}, 10, 2 );
		add_filter( 'anchor_library_stream_discard_buffers', '__return_false' );
		add_action( 'anchor_library_halt', static function () {
			throw new RuntimeException( 'halted' );
		} );
		// header() after PHPUnit has printed is a warning in the CLI; the headers are not what this asserts.
		set_error_handler( static fn() => true, E_WARNING ); // phpcs:ignore
		ob_start();
		try {
			do_action( 'wp_ajax_anchor_fm_stream' );
		} catch ( RuntimeException $e ) {
			unset( $e );
		}
		$out = ob_get_clean();
		restore_error_handler();
		return [ $codes, $out ];
	}

	// ---- registration ---------------------------------------------------------------------------------------

	public function test_every_plugin_ajax_action_is_registered() {
		$actions = array_filter( array_map( 'trim', file( ANCHOR_TOOLS_PLUGIN_DIR . 'tests/fixtures/library-ajax-actions.txt' ) ) );
		$this->assertCount( 51, $actions );
		foreach ( $actions as $hook ) {
			$this->assertNotFalse( has_action( $hook ), "$hook has no callback after boot()" );
		}
	}

	public function test_every_hook_in_the_inventory_is_wired() {
		$seen = 0;
		foreach ( file( ANCHOR_TOOLS_PLUGIN_DIR . 'tests/fixtures/library-hooks.txt' ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || '#' === $line[0] ) {
				continue;
			}
			[ $kind, $tag ] = explode( '|', $line );
			++$seen;
			switch ( $kind ) {
				case 'shortcode':
					$this->assertTrue( shortcode_exists( $tag ), "shortcode $tag" );
					break;
				case 'action':
					$this->assertNotFalse( has_action( $tag ), "action $tag" );
					break;
				case 'setting':
					do_action( 'admin_init' );
					$this->assertArrayHasKey( $tag, get_registered_settings(), "setting $tag" );
					break;
				case 'cron':
					$this->assertNotFalse( wp_next_scheduled( $tag ), "cron $tag" );
					break;
				case 'applies':
					$this->assertStringContainsString( "'$tag'", $this->module_source(), "extension point $tag" );
					break;
				case 'removed':
					$this->assertStringNotContainsString( 'PucFactory', $this->module_source() );
					break;
				default:
					$this->fail( "unknown kind $kind" );
			}
		}
		$this->assertGreaterThan( 20, $seen );
	}

	private function module_source(): string {
		$src = '';
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-library/src' ) ) as $f ) {
			if ( $f->isFile() && 'php' === $f->getExtension() ) {
				$src .= file_get_contents( $f->getPathname() );
			}
		}
		return $src;
	}

	public function test_shortcodes_registered() {
		foreach ( [ 'anchor_file_manager', 'anchor_account_portal', 'anchor_documents_portal' ] as $code ) {
			$this->assertTrue( shortcode_exists( $code ), $code );
		}
		wp_set_current_user( 0 );
		$this->assertSame( '<p>You must be logged in to access your documents.</p>', do_shortcode( '[anchor_file_manager]' ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$html = do_shortcode( '[anchor_documents_portal]' );
		$this->assertStringContainsString( 'data-apfm', $html );
		$this->assertSame( '', do_shortcode( '[anchor_account_portal]' ), 'the portal renders once per page' );
	}

	public function test_localized_globals_names() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		do_shortcode( '[anchor_documents_portal]' );
		$fm = wp_scripts()->get_data( 'anchor-file-manager', 'data' );
		$ap = wp_scripts()->get_data( 'anchor-documents-portal', 'data' );
		$this->assertStringContainsString( 'var AnchorFM = ', $fm );
		$this->assertStringContainsString( '"nonce":"' . wp_create_nonce( 'anchor_fm_nonce' ) . '"', $fm );
		$this->assertStringContainsString( 'var AnchorAP = ', $ap );
		$this->assertStringContainsString( '"nonce":"' . wp_create_nonce( 'anchor_fm_nonce' ) . '"', $ap );
		$this->assertStringContainsString( ANCHOR_TOOLS_PLUGIN_URL . 'anchor-library/assets/js/file-manager.js', wp_scripts()->registered['anchor-file-manager']->src );
		$this->assertFileExists( ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-library/assets/js/file-manager.js' );
		$this->assertFileExists( ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-library/assets/js/user-manager.js' );
		$this->assertFileExists( ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-library/assets/css/account-portal.css' );
	}

	public function test_portal_assets_load_through_the_shared_asset_loader() {
		// Release builds ship .min siblings; the loader prefers them unless SCRIPT_DEBUG is on.
		$dir = ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-library/assets/';
		$made = [];
		foreach ( [ 'js/file-manager', 'css/file-manager', 'js/account-documents', 'css/account-portal', 'js/user-manager' ] as $base ) {
			$ext = strpos( $base, 'css/' ) === 0 ? 'css' : 'js';
			$min = $dir . $base . '.min.' . $ext;
			if ( ! file_exists( $min ) ) { file_put_contents( $min, '/* min */' ); $made[] = $min; }
		}
		try {
			wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
			$_SERVER['REQUEST_URI'] = '/';
			do_shortcode( '[anchor_documents_portal]' );
			$reg = wp_scripts()->registered;
			$this->assertStringEndsWith( 'anchor-library/assets/js/file-manager.min.js', strtok( $reg['anchor-file-manager']->src, '?' ) );
			$this->assertStringEndsWith( 'anchor-library/assets/js/account-documents.min.js', strtok( $reg['anchor-documents-portal']->src, '?' ) );
			$this->assertStringEndsWith( 'anchor-library/assets/js/user-manager.min.js', strtok( $reg['anchor-fm-user-manager']->src, '?' ) );
			$this->assertStringEndsWith( 'anchor-library/assets/css/file-manager.min.css', strtok( wp_styles()->registered['anchor-file-manager']->src, '?' ) );
			$this->assertStringEndsWith( 'anchor-library/assets/css/account-portal.min.css', strtok( wp_styles()->registered['anchor-account-portal']->src, '?' ) );
			$this->assertSame( (string) filemtime( $dir . 'js/file-manager.min.js' ), $reg['anchor-file-manager']->ver );
		} finally {
			foreach ( $made as $f ) { unlink( $f ); }
		}
	}

	public function test_dead_account_portal_js_is_not_shipped() {
		$this->assertFileDoesNotExist( ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-library/assets/js/account-portal.js' );
	}

	// ---- settings -------------------------------------------------------------------------------------------

	public function test_settings_page_and_option_keys_unchanged() {
		do_action( 'admin_init' );
		$settings = get_registered_settings();
		foreach ( [ 'anchor_fm_email_on_upload', 'anchor_fm_request_access_email', 'anchor_fm_portal_logo' ] as $key ) {
			$this->assertArrayHasKey( $key, $settings );
			$this->assertSame( 'anchor_private_file_manager', $settings[ $key ]['group'] );
		}
		// The dead Vimeo token field is gone and unregistered, so options.php can never blank the stored value on save.
		$this->assertArrayNotHasKey( 'anchor_fm_vimeo_token', $settings );
		$this->assertSame( 1, call_user_func( $settings['anchor_fm_email_on_upload']['sanitize_callback'], 'on' ) );
		$this->assertSame( 'a@b.co', call_user_func( $settings['anchor_fm_request_access_email']['sanitize_callback'], ' a@b.co ' ) );

		global $submenu;
		$submenu = [];
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		do_action( 'admin_menu' );
		$slugs = wp_list_pluck( $submenu['options-general.php'], 2 );
		$this->assertContains( 'anchor-private-file-manager', $slugs );

		ob_start();
		\Anchor\Library\Settings::render_settings_page();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'name="anchor_fm_portal_logo"', $html );
		$this->assertStringContainsString( 'name="anchor_fm_request_access_email"', $html );
		$this->assertStringContainsString( 'name="anchor_fm_email_on_upload"', $html );
		$this->assertStringNotContainsString( 'anchor_fm_vimeo_token', $html );
		$this->assertStringNotContainsString( 'Vimeo access token', $html );
		$this->assertStringContainsString( 'Private storage', $html );
		$this->assertStringContainsString( 'deny all', $html ); // the nginx rule is inline: release zips strip *.md
		$this->assertStringContainsString( 'value="anchor_fm_storage_check"', $html );
	}

	public function test_request_access_email_and_logo_fall_back_like_the_plugin() {
		update_option( 'admin_email', 'admin@example.org' );
		$this->assertSame( 'admin@example.org', \Anchor\Library\Settings::request_access_email() );
		update_option( 'anchor_fm_request_access_email', 'help@example.org' );
		$this->assertSame( 'help@example.org', \Anchor\Library\Settings::request_access_email() );
		update_option( 'anchor_fm_portal_logo', 'https://x.test/l.png' );
		$this->assertSame( 'https://x.test/l.png', \Anchor\Library\Settings::portal_logo_url() );
	}

	// ---- storage check, upload pause, cron ------------------------------------------------------------------

	public function test_storage_check_callback_stores_a_result_and_the_notice_follows_it() {
		add_filter( 'pre_http_request', static fn() => [ 'headers' => [], 'body' => '', 'response' => [ 'code' => 403, 'message' => '' ], 'cookies' => [], 'filename' => null ] );
		do_action( 'anchor_fm_storage_check' );
		$result = get_option( 'anchor_fm_storage_check' );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'status', $result );

		$this->_setRole( 'administrator' );
		update_option( 'anchor_fm_storage_check', [ 'status' => 'exposed', 'details' => [] ] );
		ob_start();
		do_action( 'admin_notices' );
		$this->assertStringContainsString( 'uploads are paused', ob_get_clean() );

		update_option( 'anchor_fm_storage_check', [ 'status' => 'unknown' ] );
		ob_start();
		do_action( 'admin_notices' );
		$this->assertStringContainsString( 'could not confirm', ob_get_clean() );

		$this->_setRole( 'subscriber' );
		ob_start();
		do_action( 'admin_notices' );
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_uploads_are_refused_while_the_store_is_exposed() {
		$folder = $this->folder();
		update_option( 'anchor_fm_storage_check', [ 'status' => 'exposed' ] );
		$_FILES = [ 'files' => [ 'name' => [ 'a.txt' ], 'tmp_name' => [ '/nope' ], 'size' => [ 1 ], 'error' => [ 0 ] ] ];
		$res = $this->call( 'anchor_fm_upload', [ 'folder_id' => $folder ] );
		$this->assertFalse( $res['success'] );
		$this->assertStringContainsString( 'Uploads are paused', $res['data']['message'] );
	}

	public function test_prune_resume_cron_callback_zeroes_stale_positions() {
		global $wpdb;
		$views = Schema::table( 'video_views' );
		$wpdb->insert( $views, [ 'source' => 'vimeo', 'video_id' => 1, 'user_id' => 1, 'resume_seconds' => 90, 'last_viewed_at' => '2000-01-01 00:00:00' ] );
		$wpdb->insert( $views, [ 'source' => 'vimeo', 'video_id' => 2, 'user_id' => 1, 'resume_seconds' => 90, 'last_viewed_at' => current_time( 'mysql' ) ] );
		do_action( 'anchor_fm_prune_resume' );
		$this->assertSame( [ '0', '90' ], $wpdb->get_col( "SELECT resume_seconds FROM {$views} ORDER BY video_id" ) );
	}

	// ---- permission writes ----------------------------------------------------------------------------------

	public function test_permissions_round_trip_through_set_and_get() {
		$folder = $this->folder( 'Shared' );
		$uid    = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$set    = $this->call( 'anchor_fm_set_permissions', [
			'entity_type' => 'folder', 'entity_id' => $folder, 'roles' => [ 'subscriber', 'administrator', 'nonsense' ],
			'users' => [ [ 'id' => $uid ] ],
			'policy' => wp_json_encode( [ 'rules' => [ [ 'conditions' => [ [ 'type' => 'user', 'userId' => $uid ] ] ] ] ] ),
		] );
		$this->assertTrue( $set['success'], wp_json_encode( $set ) );
		$this->assertSame( [ 'subscriber' ], $set['data']['roles'] );

		$get = $this->call( 'anchor_fm_get_permissions', [ 'entity_type' => 'folder', 'entity_id' => $folder ] );
		$this->assertSame( [ 'subscriber' ], $get['data']['roles'] );
		$this->assertSame( (string) $uid, $get['data']['users'][0]['id'] );
		$name = get_user_by( 'id', $uid )->display_name;
		$this->assertSame( $name, $get['data']['policy']['rules'][0]['conditions'][0]['name'] );

		$this->assertTrue( \Anchor\Library\Permissions::can_view_folder( $folder, $uid ) );
		$roles = \Anchor\Library\PermissionWrites::editable_roles();
		$this->assertNotContains( 'administrator', wp_list_pluck( $roles, 'key' ) );
		$this->assertContains( 'subscriber', wp_list_pluck( $roles, 'key' ) );
	}

	public function test_copy_view_permissions_copies_roles_and_policy() {
		global $wpdb;
		$a = $this->folder( 'A' );
		$b = $this->folder( 'B' );
		$this->call( 'anchor_fm_set_permissions', [ 'entity_type' => 'folder', 'entity_id' => $a, 'roles' => [ 'subscriber' ] ] );
		\Anchor\Library\PermissionWrites::copy_view( 'folder', $a, 'folder', $b );
		$this->assertSame( [ 'subscriber' ], $wpdb->get_col( $wpdb->prepare( 'SELECT subject_key FROM ' . Schema::table( 'permissions' ) . " WHERE entity_type='folder' AND entity_id=%d", $b ) ) );
	}

	// ---- folder / file / link handlers ----------------------------------------------------------------------

	public function test_folder_crud_listing_and_search() {
		$root = $this->folder( 'Contracts' );
		$kid  = $this->folder( 'Signed', $root );
		$list = $this->call( 'anchor_fm_list', [ 'folder_id' => $root ] );
		$this->assertTrue( $list['success'] );
		$this->assertSame( 'Signed', $list['data']['folders'][0]['name'] );
		$this->assertSame( 'manage', $list['data']['capability'] );
		$this->assertSame( 'Contracts', $list['data']['breadcrumbs'][0]['name'] );
		$this->assertArrayHasKey( 'contents', $list['data'] );

		$boot = $this->call( 'anchor_fm_bootstrap' );
		$this->assertSame( 'Contracts', $boot['data']['tree'][0]['name'] );
		$this->assertSame( 'Signed', $boot['data']['tree'][0]['children'][0]['name'] );

		$found = $this->call( 'anchor_fm_search', [ 'term' => 'Signed' ] );
		$this->assertSame( 'folder', $found['data']['results'][0]['kind'] );

		$this->assertTrue( $this->call( 'anchor_fm_rename_folder', [ 'folder_id' => $kid, 'name' => 'Done' ] )['success'] );
		$moved = $this->call( 'anchor_fm_move_folder', [ 'folder_id' => $kid, 'target_folder_id' => 0 ] );
		$this->assertTrue( $moved['success'] );
		$this->assertFalse( $this->call( 'anchor_fm_move_folder', [ 'folder_id' => $root, 'target_folder_id' => $root ] )['success'] );
		$this->assertTrue( $this->call( 'anchor_fm_delete_folder', [ 'folder_id' => $root ] )['success'] );
		$this->assertFalse( $this->call( 'anchor_fm_list', [ 'folder_id' => $root ], 'subscriber' )['success'] );
	}

	public function test_non_admin_is_refused_everywhere_an_admin_is_required() {
		$this->assertFalse( $this->call( 'anchor_fm_create_folder', [ 'name' => 'X' ], 'subscriber' )['success'] );
		$this->assertFalse( $this->call( 'anchor_fm_users_list', [], 'subscriber' )['success'] );
		$this->assertFalse( $this->call( 'anchor_fm_roles_list', [], 'subscriber' )['success'] );
		$res = $this->call( 'anchor_fm_list', [ 'folder_id' => 0 ], 'subscriber' );
		$this->assertTrue( $res['success'] );
		$this->assertSame( 'view', $res['data']['capability'] );
	}

	public function test_links_files_copy_and_delete() {
		global $wpdb;
		$f    = $this->folder( 'Src' );
		$g    = $this->folder( 'Dst' );
		$link = $this->call( 'anchor_fm_create_link', [ 'folder_id' => $f, 'title' => 'Site', 'url' => 'https://example.org' ] )['data']['linkId'];
		$file = $this->stored_file( $f );
		$this->assertTrue( $this->call( 'anchor_fm_update_link', [ 'link_id' => $link, 'title' => 'Site 2', 'url' => 'https://example.org/2' ] )['success'] );
		$this->assertTrue( $this->call( 'anchor_fm_rename_file', [ 'file_id' => $file, 'name' => 'b.txt' ] )['success'] );

		$copy = $this->call( 'anchor_fm_copy_items', [ 'target_folder_id' => $g, 'items' => wp_json_encode( [ [ 'kind' => 'file', 'id' => $file ], [ 'kind' => 'link', 'id' => $link ] ] ) ] );
		$this->assertSame( 2, $copy['data']['copied'], wp_json_encode( $copy ) );
		$this->assertSame( 1, (int) $wpdb->get_var( 'SELECT COUNT(1) FROM ' . Schema::table( 'files' ) . " WHERE folder_id = $g" ) );

		$this->assertTrue( $this->call( 'anchor_fm_move_file', [ 'file_id' => $file, 'folder_id' => $g ] )['success'] );
		$this->assertTrue( $this->call( 'anchor_fm_delete_file', [ 'file_id' => $file ] )['success'] );
		$this->assertTrue( $this->call( 'anchor_fm_delete_link', [ 'link_id' => $link ] )['success'] );

		$remaining = (int) $wpdb->get_var( 'SELECT id FROM ' . Schema::table( 'files' ) . " WHERE folder_id = $g LIMIT 1" );
		$prev      = $this->call( 'anchor_fm_preview', [ 'file_id' => $remaining ] );
		$this->assertSame( 'text', $prev['data']['preview']['type'] );
		$this->assertStringContainsString( 'action=anchor_fm_stream', $prev['data']['preview']['inlineUrl'] );
	}

	public function test_vimeo_add_update_get_and_delete() {
		$f   = $this->folder( 'Videos' );
		$add = $this->call( 'anchor_fm_vimeo_add', [ 'folder_id' => $f, 'videos' => [ [ 'vimeo' => '123456', 'title' => 'Intro' ] ] ] );
		$this->assertTrue( $add['success'], wp_json_encode( $add ) );
		$id = $add['data']['videoId'];
		$this->assertSame( 'Intro', $this->call( 'anchor_fm_vimeo_get', [ 'video_id' => $id ] )['data']['video']['title'] );
		$this->assertTrue( $this->call( 'anchor_fm_vimeo_update', [ 'video_id' => $id, 'title' => 'Intro 2' ] )['success'] );
		$this->assertTrue( $this->call( 'anchor_fm_media_progress', [ 'source' => 'vimeo', 'item_id' => $id, 'segments' => '[[0,10]]', 'point' => 10, 'duration' => 100 ] )['success'] );
		$this->assertArrayHasKey( 'resumeSeconds', $this->call( 'anchor_fm_media_resume', [ 'source' => 'vimeo', 'item_id' => $id ] )['data'] );
		$this->assertCount( 1, $this->call( 'anchor_fm_vimeo_history', [ 'item_id' => $id ] )['data']['history'] );
		$this->assertTrue( $this->call( 'anchor_fm_vimeo_delete', [ 'video_id' => $id ] )['success'] );
	}

	public function test_request_access_emails_once_then_throttles() {
		$f   = $this->folder( 'Private' );
		$res = $this->call( 'anchor_fm_request_access', [ 'entity_type' => 'folder', 'entity_id' => $f, 'label' => 'Private' ], 'subscriber' );
		$this->assertTrue( $res['success'], wp_json_encode( $res ) );
		$this->assertFalse( $res['data']['throttled'] );
		$again = $this->call( 'anchor_fm_request_access', [ 'entity_type' => 'folder', 'entity_id' => $f ], '' );
		$this->assertTrue( $again['data']['throttled'] );
	}

	// ---- stream ---------------------------------------------------------------------------------------------

	public function test_stream_ajax_refuses_without_view_right() {
		$folder = $this->folder();
		$file   = $this->stored_file( $folder );
		[ $codes, $out ] = $this->stream( $file, self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$this->assertContains( 403, $codes );
		$this->assertSame( '', $out );
	}

	public function test_stream_ajax_serves_with_view_right() {
		$folder = $this->folder();
		$file   = $this->stored_file( $folder, 'hello world' );
		[ $codes, $out ] = $this->stream( $file, self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( 'hello world', $out );
		$this->assertNotContains( 403, $codes );
	}

	public function test_stream_ajax_refuses_a_bad_nonce() {
		$folder = $this->folder();
		$file   = $this->stored_file( $folder );
		$_GET   = [ 'file_id' => $file, 'nonce' => 'bad', 'disposition' => 'inline' ];
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$codes = [];
		add_filter( 'status_header', static function ( $h, $code ) use ( &$codes ) {
			$codes[] = $code;
			return $h;
		}, 10, 2 );
		add_action( 'anchor_library_halt', static function () {
			throw new RuntimeException( 'halted' );
		} );
		try {
			do_action( 'wp_ajax_anchor_fm_stream' );
		} catch ( RuntimeException $e ) {
			unset( $e );
		}
		$this->assertContains( 403, $codes );
	}

	// ---- Users tab ------------------------------------------------------------------------------------------

	public function test_users_tab_multi_role_assignment() {
		add_role( 'patient', 'Patient', [ 'read' => true ] );
		add_role( 'member', 'Member', [ 'read' => true ] );
		$made = $this->call( 'anchor_fm_user_create', [
			'username' => 'pat1', 'first_name' => 'Pat', 'last_name' => 'One', 'email' => 'pat1@example.org',
			'password' => 'Str0ng-Passw0rd!', 'roles' => [ 'patient', 'member' ],
		] );
		$this->assertTrue( $made['success'], wp_json_encode( $made ) );
		$user = get_user_by( 'id', $made['data']['user']['id'] );
		$this->assertEqualsCanonicalizing( [ 'patient', 'member' ], $user->roles );

		$changed = $this->call( 'anchor_fm_user_set_role', [ 'user_id' => $user->ID, 'roles' => [ 'subscriber', 'member' ] ] );
		$this->assertTrue( $changed['success'], wp_json_encode( $changed ) );
		$this->assertEqualsCanonicalizing( [ 'subscriber', 'member' ], get_user_by( 'id', $user->ID )->roles );

		$this->assertFalse( $this->call( 'anchor_fm_user_set_role', [ 'user_id' => $user->ID, 'roles' => [ 'administrator' ] ] )['success'], 'administrator is never assignable' );
		$this->assertFalse( $this->call( 'anchor_fm_user_set_role', [ 'user_id' => $user->ID, 'roles' => [ 'editor' ] ] )['success'], 'staff roles are not assignable' );

		$list = $this->call( 'anchor_fm_users_list', [ 'search' => 'pat1' ] );
		$this->assertSame( 1, $list['data']['total'] );
		$this->assertTrue( $list['data']['users'][0]['manageable'] );

		$this->assertTrue( $this->call( 'anchor_fm_user_delete', [ 'user_id' => $user->ID ] )['success'] );
	}

	public function test_deleting_a_user_clears_their_grants_and_watch_history() {
		global $wpdb;
		$uid = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$wpdb->insert( Schema::table( 'permissions' ), [ 'entity_type' => 'folder', 'entity_id' => 1, 'subject_type' => 'user', 'subject_key' => (string) $uid, 'capability' => 'view', 'created_by' => 1, 'created_at' => current_time( 'mysql' ) ] );
		wp_delete_user( $uid );
		$this->assertSame( 0, (int) $wpdb->get_var( 'SELECT COUNT(1) FROM ' . Schema::table( 'permissions' ) . " WHERE subject_key = '$uid'" ) );
	}

	public function test_portal_roles_can_be_created_renamed_and_deleted() {
		$made = $this->call( 'anchor_fm_role_create', [ 'name' => 'Patients' ] );
		$this->assertTrue( $made['success'], wp_json_encode( $made ) );
		$key = $made['data']['created'];
		$this->assertNotNull( get_role( $key ) );
		$this->assertSame( $key, $made['data']['portalRoles'][0]['key'] );
		$this->assertTrue( $this->call( 'anchor_fm_role_rename', [ 'key' => $key, 'name' => 'Clients' ] )['success'] );
		$this->assertSame( 'Clients', wp_roles()->get_names()[ $key ] );
		$this->assertFalse( $this->call( 'anchor_fm_role_delete', [ 'key' => 'subscriber' ] )['success'], 'only portal-created roles are deletable' );
		$this->assertTrue( $this->call( 'anchor_fm_role_delete', [ 'key' => $key ] )['success'] );
		$this->assertNull( get_role( $key ) );
	}

	public function test_bulk_import_users_requires_a_csv() {
		$res = $this->call( 'anchor_fm_bulk_import_users', [ 'roles' => [ 'subscriber' ] ] );
		$this->assertFalse( $res['success'] );
		$this->assertSame( 'No CSV file was uploaded.', $res['data']['message'] );
	}

	// ---- Account portal and Product Docs --------------------------------------------------------------------

	public function test_account_profile_and_password_endpoints() {
		$uid = self::factory()->user->create( [ 'role' => 'subscriber', 'user_email' => 'me@example.org' ] );
		wp_set_current_user( $uid );
		$ok = $this->call( 'anchor_ap_update_profile', [ 'first_name' => 'Ann', 'last_name' => 'Lee', 'user_email' => 'ann@example.org' ], '' );
		$this->assertTrue( $ok['success'], wp_json_encode( $ok ) );
		$this->assertSame( 'ann@example.org', get_user_by( 'id', $uid )->user_email );
		$this->assertFalse( $this->call( 'anchor_ap_update_profile', [ 'user_email' => 'nope' ], '' )['success'] );
		$this->assertFalse( $this->call( 'anchor_ap_change_password', [ 'new_password' => 'x' ], '' )['success'] );
		$this->assertTrue( $this->call( 'anchor_ap_send_reset', [], '' )['success'] );
	}

	public function test_product_docs_endpoints_need_woocommerce_and_the_right_cap() {
		$this->assertFalse( $this->call( 'anchor_pd_products', [], 'subscriber' )['success'] );
		$this->assertFalse( $this->call( 'anchor_pd_save_docs', [ 'product_id' => 1 ], 'subscriber' )['success'] );
		if ( ! function_exists( 'wc_get_products' ) ) {
			$res = $this->call( 'anchor_pd_my_docs', [], 'subscriber' );
			$this->assertFalse( $res['success'] );
			$this->assertSame( 'WooCommerce not available', $res['data']['message'] );
			return;
		}
		$this->assertTrue( $this->call( 'anchor_pd_my_docs', [], 'subscriber' )['success'] );
	}

	public function test_product_docs_save_keeps_the_meta_key_and_refuses_unpublishable_files() {
		if ( ! function_exists( 'wc_get_products' ) ) {
			$this->markTestSkipped( 'WooCommerce is not loaded.' );
		}
		$product = self::factory()->post->create( [ 'post_type' => 'product', 'post_status' => 'publish' ] );
		$folder  = $this->folder( 'Manuals' );
		$file    = $this->stored_file( $folder );
		$res     = $this->call( 'anchor_pd_save_docs', [ 'product_id' => $product, 'docs' => [ [ 'fileId' => $file, 'title' => 'Manual', 'expires' => '' ] ] ] );
		$this->assertTrue( $res['success'], wp_json_encode( $res ) );
		$this->assertSame( $file, get_post_meta( $product, '_anchor_pd_docs', true )[0]['fileId'] );
	}
}
