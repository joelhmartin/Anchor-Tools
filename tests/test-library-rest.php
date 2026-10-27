<?php
/**
 * Library REST read routes: /folders/{id}, /search. Files, links and tokens are in test-library-links.php.
 *
 * @package Anchor\Library\Tests
 * @group library
 */

use Anchor\Library\Permissions;
use Anchor\Library\Repository;
use Anchor\Library\Rest\Routes;
use Anchor\Library\Schema;

class Anchor_Library_Rest_Test extends Anchor_Library_TestCase {

	private int $admin;
	private int $member;
	private int $outsider;

	public function set_up() {
		parent::set_up();
		Permissions::reset_memo();
		$this->admin    = self::factory()->user->create( [ 'role' => 'administrator', 'display_name' => 'Ada Admin', 'user_email' => 'ada@example.test' ] );
		$this->member   = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->outsider = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( 0 );

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		add_action( 'rest_api_init', [ Routes::class, 'register_routes' ] );
		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null;
		remove_action( 'rest_api_init', [ Routes::class, 'register_routes' ] );
		Permissions::reset_memo();
		parent::tear_down();
	}

	// ---- fixtures -------------------------------------------------------------------------------------------

	private function folder( string $name, int $parent = 0, int $private = 0 ): int {
		global $wpdb;
		$wpdb->insert( Schema::table( 'folders' ), [
			'parent_id' => $parent, 'name' => $name, 'owner_user_id' => 0, 'is_private' => $private,
			'created_by' => $this->admin, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		return (int) $wpdb->insert_id;
	}

	private function file( int $folder, string $name = 'guide.pdf', string $mime = 'application/pdf' ): int {
		global $wpdb;
		$wpdb->insert( Schema::table( 'files' ), [
			'folder_id' => $folder, 'original_name' => $name, 'stored_name' => 'x.bin', 'mime_type' => $mime, 'size' => 10,
			'uploader_user_id' => $this->admin, 'created_at' => '2026-01-02 03:04:05',
		] );
		return (int) $wpdb->insert_id;
	}

	private function video( int $folder, string $hash = 'abc123', string $title = 'Intro clip' ): int {
		global $wpdb;
		$wpdb->insert( Schema::table( 'videos' ), [
			'folder_id' => $folder, 'vimeo_id' => '987654', 'vimeo_hash' => $hash, 'title' => $title, 'thumbnail_url' => 'https://i.vimeocdn.test/t.jpg',
			'created_by' => $this->admin, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		return (int) $wpdb->insert_id;
	}

	private function link( int $folder, string $title = 'Portal site' ): int {
		global $wpdb;
		$wpdb->insert( Schema::table( 'links' ), [
			'folder_id' => $folder, 'title' => $title, 'url' => 'https://example.test/x', 'created_by' => $this->admin,
			'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		return (int) $wpdb->insert_id;
	}

	private function grant( int $folder, string $subject_type, string $key ): void {
		global $wpdb;
		$wpdb->insert( Schema::table( 'permissions' ), [
			'entity_type' => 'folder', 'entity_id' => $folder, 'subject_type' => $subject_type, 'subject_key' => $key,
			'capability' => 'view', 'created_by' => 0, 'created_at' => current_time( 'mysql' ),
		] );
	}

	private function get( string $route, array $params = [], array $headers = [] ): WP_REST_Response {
		$req = new WP_REST_Request( 'GET', '/anchor-library/v1' . $route );
		$req->set_query_params( $params );
		foreach ( $headers as $k => $v ) {
			$req->set_header( $k, $v );
		}
		return rest_do_request( $req );
	}

	// ---- auth and gating ------------------------------------------------------------------------------------

	public function test_signed_out_is_401_with_our_code() {
		foreach ( [ '/folders/0', '/folders/1', '/search' ] as $route ) {
			$res = $this->get( $route, [ 'q' => 'ab' ] );
			$this->assertSame( 401, $res->get_status(), $route );
			$this->assertSame( 'anchor_library_auth', $res->as_error()->get_error_code(), $route );
		}
	}

	public function test_routes_are_get_only() {
		$routes = rest_get_server()->get_routes( 'anchor-library/v1' );
		foreach ( $routes as $handlers ) {
			foreach ( $handlers as $h ) {
				$this->assertSame( [ 'GET' => true ], $h['methods'] );
			}
		}
	}

	public function test_root_lists_only_granted_folders() {
		$open   = $this->folder( 'Open' );
		$closed = $this->folder( 'Closed' );
		$this->grant( $open, 'role', 'subscriber' );
		wp_set_current_user( $this->member );
		$data = $this->get( '/folders/0' )->get_data();
		$this->assertSame( [ $open ], wp_list_pluck( $data['folders'], 'id' ) );
		$this->assertNotContains( $closed, wp_list_pluck( $data['folders'], 'id' ) );
		$this->assertSame( 0, $data['folder']['id'] );
		$this->assertSame( [], $data['breadcrumbs'] );
		$this->assertSame( [], $data['files'] );

		wp_set_current_user( $this->outsider );
		Permissions::reset_memo();
		$this->assertSame( [], $this->get( '/folders/0' )->get_data()['folders'] );
	}

	public function test_forbidden_missing_and_hidden_folders() {
		global $wpdb;
		$secret = $this->folder( 'Secret' );
		wp_set_current_user( $this->member );
		$res = $this->get( '/folders/' . $secret );
		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( 'anchor_library_forbidden', $res->as_error()->get_error_code() );
		$this->assertStringNotContainsString( 'Secret', wp_json_encode( $res->as_error()->get_error_message() ) );

		$res = $this->get( '/folders/999999' );
		$this->assertSame( 404, $res->get_status() );
		$this->assertSame( 'anchor_library_not_found', $res->as_error()->get_error_code() );

		// The Product Docs container is never addressable, not even for an admin; neither is a private folder.
		$pd = $this->folder( 'Product Docs' );
		update_option( Schema::OPT_PD_FOLDER_ID, $pd );
		$priv = $this->folder( 'Mine', 0, 1 );
		wp_set_current_user( $this->admin );
		Permissions::reset_memo();
		$this->assertSame( 404, $this->get( '/folders/' . $pd )->get_status() );
		$this->assertSame( 404, $this->get( '/folders/' . $priv )->get_status() );
		$this->assertNotContains( $pd, wp_list_pluck( $this->get( '/folders/0' )->get_data()['folders'], 'id' ) );
	}

	public function test_forbidden_folder_does_not_leak_breadcrumbs() {
		$top = $this->folder( 'TopSecretName' );
		$sub = $this->folder( 'Inner', $top );
		wp_set_current_user( $this->member );
		$body = wp_json_encode( $this->get( '/folders/' . $sub )->as_error()->get_error_data() ) . $this->get( '/folders/' . $sub )->as_error()->get_error_message();
		$this->assertStringNotContainsString( 'TopSecretName', $body );
	}

	public function test_memo_warm_for_another_user_does_not_leak() {
		$f = $this->folder( 'Staff' );
		$this->grant( $f, 'role', 'subscriber' );
		$begin = new ReflectionMethod( Permissions::class, 'begin_memo' ); // a leaked scope from "another request"
		$begin->setAccessible( true );
		$begin->invoke( null );
		wp_set_current_user( $this->admin );
		$this->assertSame( 200, $this->get( '/folders/' . $f )->get_status() );
		wp_set_current_user( $this->outsider );
		$this->assertSame( 403, $this->get( '/folders/' . $f )->get_status() );
		wp_set_current_user( $this->member );
		$this->assertSame( 200, $this->get( '/folders/' . $f )->get_status() );
		Permissions::reset_memo();
	}

	public function test_error_responses_are_never_cached() {
		$f = $this->folder( 'Staff' );
		$this->grant( $f, 'role', 'subscriber' );
		$cases = [
			[ 0, '/folders/' . $f, 401 ],
			[ 0, '/search', 401 ],
			[ $this->outsider, '/folders/' . $f, 403 ],
			[ $this->member, '/folders/999999', 404 ],
			[ $this->member, '/search', 400 ],
		];
		foreach ( $cases as [ $user, $route, $status ] ) {
			wp_set_current_user( $user );
			$res = $this->get( $route );
			$this->assertSame( $status, $res->get_status(), $route );
			$this->assertSame( 'no-store', $res->get_headers()['Cache-Control'] ?? '', $route . ' ' . $status );
		}
		wp_set_current_user( $this->member );
		$this->assertStringContainsString( 'private', $this->get( '/folders/' . $f )->get_headers()['Cache-Control'], 'success keeps private caching' );
	}

	// ---- shapes ---------------------------------------------------------------------------------------------

	public function test_folder_shape_keys_and_no_personal_data() {
		$parent = $this->folder( 'Training' );
		$child  = $this->folder( 'Videos', $parent );
		$sub    = $this->folder( 'Deep', $child );
		$this->grant( $parent, 'role', 'subscriber' );
		$f = $this->file( $child, 'guide.pdf' );
		$v = $this->video( $child );
		$l = $this->link( $child );
		wp_set_current_user( $this->member );

		$res  = $this->get( '/folders/' . $child );
		$data = $res->get_data();
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( [ 'folder', 'breadcrumbs', 'folders', 'files', 'videos', 'links' ], array_keys( $data ) );
		$this->assertSame( [ 'id', 'name', 'parent_id', 'counts' ], array_keys( $data['folder'] ) );
		$this->assertSame( [ 'folders' => 1, 'files' => 1, 'videos' => 1, 'links' => 1 ], $data['folder']['counts'] );
		$this->assertSame( $parent, $data['folder']['parent_id'] );
		$this->assertSame( [ [ 'id' => $parent, 'name' => 'Training' ], [ 'id' => $child, 'name' => 'Videos' ] ], $data['breadcrumbs'] );
		$this->assertSame( [ 'id', 'name', 'parent_id', 'counts' ], array_keys( $data['folders'][0] ) );
		$this->assertSame( $sub, $data['folders'][0]['id'] );
		$this->assertSame( [ 'id', 'name', 'mime', 'size', 'updated_at' ], array_keys( $data['files'][0] ) );
		$this->assertSame( $f, $data['files'][0]['id'] );
		$this->assertSame( 10, $data['files'][0]['size'] );
		$this->assertMatchesRegularExpression( '/^2026-01-02T03:04:05[+-Z]/', $data['files'][0]['updated_at'] );
		$this->assertSame( [ 'id', 'title', 'thumbnail_url', 'embed_url', 'downloadable' ], array_keys( $data['videos'][0] ) );
		$this->assertSame( $v, $data['videos'][0]['id'] );
		$this->assertSame( [ 'id', 'title', 'url' ], array_keys( $data['links'][0] ) );
		$this->assertSame( $l, $data['links'][0]['id'] );

		$json = wp_json_encode( $data );
		foreach ( [ 'uploadedBy', 'createdBy', 'created_by', 'uploader', 'ada@example.test', 'Ada Admin', 'vimeoHash', 'ownerUserId', 'isPrivate' ] as $needle ) {
			$this->assertStringNotContainsString( $needle, $json );
		}
	}

	public function test_video_embed_url_with_and_without_hash() {
		$f = $this->folder( 'Media' );
		$this->grant( $f, 'role', 'subscriber' );
		$this->video( $f, 'abc123', 'With hash' );
		$this->video( $f, '', 'No hash' );
		wp_set_current_user( $this->member );
		$by_title = array_column( $this->get( '/folders/' . $f )->get_data()['videos'], 'embed_url', 'title' );
		$this->assertSame( 'https://player.vimeo.com/video/987654?h=abc123', $by_title['With hash'] );
		$this->assertSame( 'https://player.vimeo.com/video/987654', $by_title['No hash'] );
	}

	// ---- caching headers ------------------------------------------------------------------------------------

	public function test_etag_304_and_cache_headers() {
		$f = $this->folder( 'Cached' );
		$this->grant( $f, 'role', 'subscriber' );
		wp_set_current_user( $this->member );
		$res  = $this->get( '/folders/' . $f );
		$etag = $res->get_headers()['ETag'];
		$this->assertMatchesRegularExpression( '/^W\/"[0-9a-f]{32}"$/', $etag );
		$this->assertSame( 'private, max-age=60', $res->get_headers()['Cache-Control'] );
		$this->assertStringContainsString( 'Authorization', $res->get_headers()['Vary'] );

		$again = $this->get( '/folders/' . $f, [], [ 'if_none_match' => $etag ] );
		$this->assertSame( 304, $again->get_status() );
		$this->assertNull( $again->get_data() );
		$this->assertSame( $etag, $again->get_headers()['ETag'] );
		$this->assertStringContainsString( 'Authorization', $again->get_headers()['Vary'] );

		$this->file( $f, 'new.pdf' ); // a changed body changes the tag
		$this->assertSame( 200, $this->get( '/folders/' . $f, [], [ 'if_none_match' => $etag ] )->get_status() );
	}

	public function test_core_nocache_headers_are_off_for_the_read_routes_only() {
		add_filter( 'rest_pre_dispatch', static fn( $r ) => $r, 5 ); // keep ordering stable
		$f = $this->folder( 'Hdr' );
		$this->grant( $f, 'role', 'subscriber' );
		wp_set_current_user( $this->member );
		$this->get( '/folders/' . $f );
		$this->assertFalse( apply_filters( 'rest_send_nocache_headers', true ) );
		$other = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		apply_filters( 'rest_pre_dispatch', null, rest_get_server(), $other );
		$this->assertTrue( apply_filters( 'rest_send_nocache_headers', true ) );
	}

	// ---- search ---------------------------------------------------------------------------------------------

	public function test_search_requires_two_characters() {
		wp_set_current_user( $this->member );
		foreach ( [ '', 'a', ' a ' ] as $q ) {
			$res = $this->get( '/search', [ 'q' => $q ] );
			$this->assertSame( 400, $res->get_status(), "[$q]" );
			$this->assertSame( 'anchor_library_query', $res->as_error()->get_error_code() );
		}
		$this->assertSame( 400, $this->get( '/search' )->get_status() );
	}

	public function test_search_is_permission_filtered_and_shaped() {
		$open   = $this->folder( 'Open quarterly' );
		$closed = $this->folder( 'Closed quarterly' );
		$this->grant( $open, 'role', 'subscriber' );
		$this->file( $open, 'quarterly.pdf' );
		$this->file( $closed, 'quarterly-secret.pdf' );
		$this->video( $open, 'h', 'Quarterly video' );
		$this->link( $open, 'Quarterly link' );
		wp_set_current_user( $this->member );

		$res  = $this->get( '/search', [ 'q' => 'quarterly' ] );
		$data = $res->get_data();
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( [ 'q', 'folders', 'files', 'videos', 'links', 'truncated' ], array_keys( $data ) );
		$this->assertSame( 'quarterly', $data['q'] );
		$this->assertSame( [ 'Open quarterly' ], wp_list_pluck( $data['folders'], 'name' ) );
		$this->assertSame( [ 'quarterly.pdf' ], wp_list_pluck( $data['files'], 'name' ) );
		$this->assertSame( [ 'id', 'name', 'mime', 'size', 'folder_id', 'path' ], array_keys( $data['files'][0] ) );
		$this->assertSame( [ 'id', 'name', 'parent_id', 'path' ], array_keys( $data['folders'][0] ) );
		$this->assertSame( [ 'id', 'title', 'thumbnail_url', 'embed_url', 'downloadable', 'folder_id', 'path' ], array_keys( $data['videos'][0] ) );
		$this->assertSame( 'https://player.vimeo.com/video/987654?h=h', $data['videos'][0]['embed_url'] );
		$this->assertSame( [ 'id', 'title', 'url', 'folder_id', 'path' ], array_keys( $data['links'][0] ) );
		$this->assertFalse( $data['truncated'] );
		$this->assertStringNotContainsString( 'secret', wp_json_encode( $data ) );
		$this->assertSame( 'private, max-age=60', $res->get_headers()['Cache-Control'] );
	}

	public function test_search_hides_product_docs() {
		$pd = $this->folder( 'Product Docs Vault' );
		update_option( Schema::OPT_PD_FOLDER_ID, $pd );
		$this->file( $pd, 'vault-manual.pdf' );
		wp_set_current_user( $this->admin );
		Permissions::reset_memo();
		$data = $this->get( '/search', [ 'q' => 'vault' ] )->get_data();
		$this->assertSame( [], $data['files'] );
		$this->assertSame( [], $data['folders'] );
	}

	public function test_module_boot_registers_the_rest_hook() {
		remove_action( 'rest_api_init', [ Routes::class, 'register_routes' ] );
		add_filter( 'anchor_library_legacy_active', '__return_false' );
		\Anchor\Library\Module::boot();
		$this->assertNotFalse( has_action( 'rest_api_init', [ Routes::class, 'register_routes' ] ) );
		global $wpdb;
		foreach ( Schema::TABLES as $name ) {
			$wpdb->query( 'DELETE FROM ' . Schema::table( $name ) ); // phpcs:ignore -- boot seeds the Product Docs row (DDL commits it).
		}
	}
}
