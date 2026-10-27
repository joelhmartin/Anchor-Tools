<?php
/**
 * Library video routes for the app: GET /videos/{id} and /videos/{id}/download (Vimeo HTTP stubbed).
 *
 * @package Anchor\Library\Tests
 * @group library
 */

use Anchor\Library\Permissions;
use Anchor\Library\Rest\Routes;
use Anchor\Library\Schema;

class Test_Library_Video_Downloads extends Anchor_Library_TestCase {

	private const TOKEN = 'secret-vimeo-token-xyz';

	private int $admin;
	private int $outsider;
	private int $folder;
	private int $video;

	public function set_up() {
		parent::set_up();
		Permissions::reset_memo();
		$this->admin    = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->outsider = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( 0 );
		delete_option( Anchor_Vimeo_Api::OPTION );
		delete_option( 'anchor_webinars_settings' );
		delete_transient( Anchor_Vimeo_Api::cache_key( '987654' ) );

		global $wpdb, $wp_rest_server;
		$now = current_time( 'mysql' );
		$wpdb->insert( Schema::table( 'folders' ), [ 'parent_id' => 0, 'name' => 'Training', 'owner_user_id' => 0, 'is_private' => 0, 'created_by' => $this->admin, 'created_at' => $now, 'updated_at' => $now ] );
		$this->folder = (int) $wpdb->insert_id;
		$wpdb->insert( Schema::table( 'videos' ), [ 'folder_id' => $this->folder, 'vimeo_id' => '987654', 'vimeo_hash' => 'abc', 'title' => 'Intro', 'thumbnail_url' => '', 'created_by' => $this->admin, 'created_at' => $now, 'updated_at' => $now ] );
		$this->video = (int) $wpdb->insert_id;

		$wp_rest_server = new WP_REST_Server();
		add_action( 'rest_api_init', [ Routes::class, 'register_routes' ] );
		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null;
		remove_action( 'rest_api_init', [ Routes::class, 'register_routes' ] );
		remove_all_filters( 'pre_http_request' );
		delete_option( Anchor_Vimeo_Api::OPTION );
		delete_transient( Anchor_Vimeo_Api::cache_key( '987654' ) );
		Permissions::reset_memo();
		parent::tear_down();
	}

	private function stub( array $sizes, int $code = 200 ): void {
		$files = [];
		foreach ( $sizes as $r => $bytes ) {
			$files[] = [ 'rendition' => $r, 'size' => $bytes, 'link' => 'https://vimeo.test/' . $r . '.mp4?sig=1', 'expires' => '2030-01-01T00:00:00+00:00', 'type' => 'video/mp4' ];
		}
		add_filter( 'pre_http_request', static fn() => [ 'headers' => [], 'cookies' => [], 'filename' => null, 'response' => [ 'code' => $code, 'message' => '' ], 'body' => wp_json_encode( [ 'download' => $files ] ) ], 10, 3 );
	}

	private function with_token(): void {
		update_option( Anchor_Vimeo_Api::OPTION, self::TOKEN );
	}

	private function get( string $route, array $params = [] ): WP_REST_Response {
		$req = new WP_REST_Request( 'GET', '/anchor-library/v1' . $route );
		$req->set_query_params( $params );
		return rest_do_request( $req );
	}

	private function download( array $params = [] ): WP_REST_Response {
		return $this->get( '/videos/' . $this->video . '/download', $params );
	}

	public function test_both_routes_need_sign_in_a_view_right_and_a_real_video() {
		foreach ( [ '', '/download' ] as $suffix ) {
			$r = $this->get( '/videos/' . $this->video . $suffix );
			$this->assertSame( 401, $r->get_status() );
			$this->assertSame( 'anchor_library_auth', $r->get_data()['code'] );
			$this->assertSame( 'no-store', $r->get_headers()['Cache-Control'] );
		}
		wp_set_current_user( $this->outsider );
		foreach ( [ '', '/download' ] as $suffix ) {
			$r = $this->get( '/videos/' . $this->video . $suffix );
			$this->assertSame( 403, $r->get_status() );
			$this->assertSame( 'anchor_library_forbidden', $r->get_data()['code'] );
		}
		wp_set_current_user( $this->admin );
		foreach ( [ '', '/download' ] as $suffix ) {
			$r = $this->get( '/videos/999999' . $suffix );
			$this->assertSame( 404, $r->get_status() );
			$this->assertSame( 'anchor_library_not_found', $r->get_data()['code'] );
		}
	}

	public function test_detail_lists_sizes_and_never_a_link() {
		$this->with_token();
		$this->stub( [ '720p' => 300, '360p' => 100 ] );
		wp_set_current_user( $this->admin );
		$r = $this->get( '/videos/' . $this->video, [ 'sizes' => '1' ] );
		$this->assertSame( 200, $r->get_status() );
		$d = $r->get_data();
		$this->assertSame( [ 'id', 'title', 'thumbnail_url', 'embed_url', 'downloadable', 'downloads' ], array_keys( $d ) );
		$this->assertTrue( $d['downloadable'] );
		$this->assertSame( [ [ 'rendition' => '720p', 'size' => 300 ], [ 'rendition' => '360p', 'size' => 100 ] ], $d['downloads'] );
		$this->assertSame( 'no-store', $r->get_headers()['Cache-Control'] );
		$json = wp_json_encode( $d );
		$this->assertStringNotContainsString( 'vimeo.test', $json );
		$this->assertStringNotContainsString( self::TOKEN, $json );
	}

	public function test_detail_without_sizes_never_contacts_vimeo() {
		$this->with_token();
		$calls = 0;
		add_filter( 'pre_http_request', static function () use ( &$calls ) { $calls++; return new WP_Error( 'x' ); }, 10, 3 );
		wp_set_current_user( $this->admin );
		$r = $this->get( '/videos/' . $this->video );
		$this->assertSame( 200, $r->get_status() );
		$this->assertSame( [], $r->get_data()['downloads'] );
		$this->assertTrue( $r->get_data()['downloadable'] );
		$this->assertSame( 0, $calls );
	}

	public function test_detail_without_a_token_is_200_and_not_downloadable() {
		wp_set_current_user( $this->admin );
		$r = $this->get( '/videos/' . $this->video, [ 'sizes' => '1' ] );
		$this->assertSame( 200, $r->get_status() );
		$this->assertFalse( $r->get_data()['downloadable'] );
		$this->assertSame( [], $r->get_data()['downloads'] );
	}

	public function test_download_returns_the_requested_rendition() {
		$this->with_token();
		$this->stub( [ '720p' => 300, '540p' => 200, '360p' => 100 ] );
		wp_set_current_user( $this->admin );
		$r = $this->download( [ 'rendition' => '540p' ] );
		$this->assertSame( 200, $r->get_status() );
		$d = $r->get_data();
		$this->assertSame( [ 'url', 'rendition', 'size', 'expires_at' ], array_keys( $d ) );
		$this->assertSame( 'https://vimeo.test/540p.mp4?sig=1', $d['url'] );
		$this->assertSame( '540p', $d['rendition'] );
		$this->assertSame( 200, $d['size'] );
		$this->assertSame( '2030-01-01T00:00:00Z', $d['expires_at'] );
		$this->assertSame( 'no-store', $r->get_headers()['Cache-Control'] );
		$this->assertStringNotContainsString( self::TOKEN, wp_json_encode( $d ) );
	}

	public function test_download_defaults_to_540p() {
		$this->with_token();
		$this->stub( [ '720p' => 300, '540p' => 200 ] );
		wp_set_current_user( $this->admin );
		$this->assertSame( '540p', $this->download()->get_data()['rendition'] );
	}

	public function test_a_missing_rendition_falls_back_down_then_to_the_lowest() {
		$this->with_token();
		wp_set_current_user( $this->admin );
		$this->stub( [ '720p' => 300, '360p' => 100 ] );
		$this->assertSame( '720p', $this->download( [ 'rendition' => '1080p' ] )->get_data()['rendition'] );
		$this->assertSame( '360p', $this->download( [ 'rendition' => '540p' ] )->get_data()['rendition'] );
		delete_transient( Anchor_Vimeo_Api::cache_key( '987654' ) );
		remove_all_filters( 'pre_http_request' );
		$this->stub( [ '360p' => 100, '240p' => 50 ] );
		$this->assertSame( '360p', $this->download( [ 'rendition' => '720p' ] )->get_data()['rendition'] );
		$this->assertSame( '240p', $this->download( [ 'rendition' => '144p' ] )->get_data()['rendition'] );
	}

	public function test_a_malformed_rendition_is_400() {
		$this->with_token();
		$this->stub( [ '540p' => 200 ] );
		wp_set_current_user( $this->admin );
		$r = $this->download( [ 'rendition' => 'huge' ] );
		$this->assertSame( 400, $r->get_status() );
		$this->assertSame( 'rest_invalid_param', $r->get_data()['code'] );
	}

	public function test_download_without_a_token_is_503() {
		wp_set_current_user( $this->admin );
		$r = $this->download();
		$this->assertSame( 503, $r->get_status() );
		$this->assertSame( 'anchor_library_downloads_unavailable', $r->get_data()['code'] );
	}

	public function test_a_vimeo_failure_is_503_and_an_empty_list_is_404() {
		$this->with_token();
		wp_set_current_user( $this->admin );
		$this->stub( [], 500 );
		$this->assertSame( 503, $this->download()->get_status() );
		remove_all_filters( 'pre_http_request' );
		delete_transient( Anchor_Vimeo_Api::cache_key( '987654' ) );
		$this->stub( [] );
		$r = $this->download();
		$this->assertSame( 404, $r->get_status() );
		$this->assertSame( 'anchor_library_no_download', $r->get_data()['code'] );
	}

	public function test_a_download_is_logged_with_source_app() {
		global $wpdb;
		$this->with_token();
		$this->stub( [ '540p' => 200 ] );
		wp_set_current_user( $this->admin );
		$this->download();
		$row = $wpdb->get_row( 'SELECT * FROM ' . Schema::table( 'activity' ) . " WHERE action = 'download_video'" );
		$this->assertNotNull( $row );
		$this->assertSame( 'video', $row->entity_type );
		$this->assertSame( $this->video, (int) $row->entity_id );
		$this->assertSame( $this->admin, (int) $row->actor_user_id );
		$this->assertSame( 'app', json_decode( $row->meta, true )['source'] );
	}

	public function test_folder_listing_videos_carry_downloadable() {
		wp_set_current_user( $this->admin );
		$this->assertFalse( $this->get( '/folders/' . $this->folder )->get_data()['videos'][0]['downloadable'] );
		$this->with_token();
		$this->assertTrue( $this->get( '/folders/' . $this->folder )->get_data()['videos'][0]['downloadable'] );
	}

	/** Regression: the sanitizer once called add_option(), which re-runs the sanitizer, and the first save never returned. */
	public function test_saving_the_token_through_the_settings_sanitizer_stores_it_with_autoload_off() {
		\Anchor\Library\Settings::register_settings();
		delete_option( Anchor_Vimeo_Api::OPTION );
		$this->assertTrue( update_option( Anchor_Vimeo_Api::OPTION, ' tok-first ' ) );
		$this->assertSame( 'tok-first', get_option( Anchor_Vimeo_Api::OPTION ) );
		global $wpdb;
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Anchor_Vimeo_Api::OPTION ) );
		$this->assertNotContains( $autoload, wp_autoload_values_to_autoload() );

		// Blank keeps it; Clear empties it; a new value after Clear is saved (the stored '' equals the registered default).
		update_option( Anchor_Vimeo_Api::OPTION, '' );
		$this->assertSame( 'tok-first', get_option( Anchor_Vimeo_Api::OPTION ) );
		$_POST[ Anchor_Vimeo_Api::OPTION . '_clear' ] = '1';
		update_option( Anchor_Vimeo_Api::OPTION, 'ignored' );
		unset( $_POST[ Anchor_Vimeo_Api::OPTION . '_clear' ] );
		$this->assertSame( '', get_option( Anchor_Vimeo_Api::OPTION ) );
		update_option( Anchor_Vimeo_Api::OPTION, 'tok-second' );
		$this->assertSame( 'tok-second', get_option( Anchor_Vimeo_Api::OPTION ) );
	}
}
