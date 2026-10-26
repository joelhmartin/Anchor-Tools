<?php
/**
 * Shared Vimeo client: token precedence, rendition parsing, caching, errors, Webinars reuse.
 *
 * @package Anchor_Tools
 * @group vimeo
 */

class Test_Vimeo_Api extends WP_UnitTestCase {

	/** @var array[] Requests captured by the stub: [ url, args ]. */
	private $requests = [];

	public function set_up() {
		parent::set_up();
		$this->requests = [];
		delete_option( Anchor_Vimeo_Api::OPTION );
		delete_option( 'anchor_webinars_settings' );
		delete_transient( Anchor_Vimeo_Api::CACHE_PREFIX . '123' );
	}

	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		delete_option( Anchor_Vimeo_Api::OPTION );
		delete_option( 'anchor_webinars_settings' );
		delete_transient( Anchor_Vimeo_Api::CACHE_PREFIX . '123' );
		parent::tear_down();
	}

	private function stub( $body, int $code = 200 ): void {
		remove_all_filters( 'pre_http_request' );
		add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( $body, $code ) {
			$this->requests[] = [ $url, $args ];
			return [ 'headers' => [], 'cookies' => [], 'filename' => null, 'response' => [ 'code' => $code, 'message' => '' ], 'body' => is_string( $body ) ? $body : wp_json_encode( $body ) ];
		}, 10, 3 );
	}

	private function item( string $rendition, int $size, string $expires = '2030-01-01T00:00:00+00:00', string $type = 'video/mp4' ): array {
		return [ 'rendition' => $rendition, 'size' => $size, 'link' => 'https://v/' . $rendition, 'expires' => $expires, 'type' => $type ];
	}

	public function test_token_prefers_constant_then_option_then_webinars_key() {
		$this->assertSame( '', Anchor_Vimeo_Api::token() );
		$this->assertFalse( Anchor_Vimeo_Api::is_configured() );
		update_option( 'anchor_webinars_settings', [ 'vimeo_api_key' => 'web-key' ] );
		$this->assertSame( 'web-key', Anchor_Vimeo_Api::token() );
		update_option( Anchor_Vimeo_Api::OPTION, 'opt-key' );
		$this->assertSame( 'opt-key', Anchor_Vimeo_Api::token() );
		$this->assertTrue( Anchor_Vimeo_Api::is_configured() );
	}

	public function test_renditions_parses_sorts_and_keeps_mp4_only() {
		update_option( Anchor_Vimeo_Api::OPTION, 't' );
		$this->stub( [ 'download' => [
			$this->item( '360p', 100 ),
			$this->item( '540p', 200 ),
			$this->item( 'source', 9, '2030-01-01T00:00:00+00:00', 'source' ),
		] ] );
		$list = Anchor_Vimeo_Api::renditions( '123' );
		$this->assertSame( [ '540p', '360p' ], array_column( $list, 'rendition' ) );
		$this->assertSame( 200, $list[0]['size'] );
		$this->assertSame( 'https://v/540p', $list[0]['url'] );
		$this->assertSame( strtotime( '2030-01-01T00:00:00+00:00' ), $list[0]['expires'] );
	}

	public function test_source_rendition_excluded_even_if_mp4() {
		update_option( Anchor_Vimeo_Api::OPTION, 't' );
		$this->stub( [ 'download' => [ $this->item( 'source', 9 ), $this->item( '1080p', 5 ) ] ] );
		$this->assertSame( [ '1080p' ], array_column( Anchor_Vimeo_Api::renditions( '123' ), 'rendition' ) );
	}

	public function test_request_sends_bearer_and_api_version() {
		update_option( Anchor_Vimeo_Api::OPTION, 'secret-tok' );
		$this->stub( [ 'download' => [] ] );
		Anchor_Vimeo_Api::renditions( '123' );
		$this->assertSame( 'https://api.vimeo.com/videos/123?fields=download', $this->requests[0][0] );
		$this->assertSame( 'Bearer secret-tok', $this->requests[0][1]['headers']['Authorization'] );
		$this->assertSame( 'application/vnd.vimeo.*+json;version=3.4', $this->requests[0][1]['headers']['Accept'] );
		$this->assertSame( 8, $this->requests[0][1]['timeout'] );
	}

	public function test_renditions_cached_when_links_live_long() {
		update_option( Anchor_Vimeo_Api::OPTION, 't' );
		$this->stub( [ 'download' => [ $this->item( '540p', 200 ) ] ] );
		Anchor_Vimeo_Api::renditions( '123' );
		$again = Anchor_Vimeo_Api::renditions( '123' );
		$this->assertCount( 1, $this->requests );
		$this->assertSame( [ '540p' ], array_column( $again, 'rendition' ) );
	}

	public function test_not_cached_when_a_link_expires_within_two_hours() {
		update_option( Anchor_Vimeo_Api::OPTION, 't' );
		$soon = gmdate( 'c', time() + HOUR_IN_SECONDS );
		$this->stub( [ 'download' => [ $this->item( '540p', 200 ), $this->item( '360p', 100, $soon ) ] ] );
		Anchor_Vimeo_Api::renditions( '123' );
		Anchor_Vimeo_Api::renditions( '123' );
		$this->assertCount( 2, $this->requests );
	}

	public function test_unconfigured_returns_wp_error() {
		$this->stub( [] );
		$r = Anchor_Vimeo_Api::renditions( '123' );
		$this->assertWPError( $r );
		$this->assertSame( 'anchor_vimeo_unconfigured', $r->get_error_code() );
		$this->assertCount( 0, $this->requests );
	}

	public function test_http_failure_returns_wp_error_with_status() {
		update_option( Anchor_Vimeo_Api::OPTION, 't' );
		$this->stub( [ 'error' => 'not found' ], 404 );
		$r = Anchor_Vimeo_Api::renditions( '123' );
		$this->assertWPError( $r );
		$this->assertSame( 'anchor_vimeo_http', $r->get_error_code() );
		$this->assertSame( 404, $r->get_error_data()['status'] );
	}

	public function test_non_numeric_id_is_a_400_without_a_request() {
		update_option( Anchor_Vimeo_Api::OPTION, 't' );
		$this->stub( [] );
		$r = Anchor_Vimeo_Api::renditions( '../me' );
		$this->assertSame( 'anchor_vimeo_http', $r->get_error_code() );
		$this->assertSame( 400, $r->get_error_data()['status'] );
		$this->assertCount( 0, $this->requests );
	}

	public function test_webinars_thumbnail_fetch_uses_shared_token() {
		update_option( Anchor_Vimeo_Api::OPTION, 'shared-tok' );
		update_option( 'anchor_webinars_settings', [ 'vimeo_api_key' => '' ] );
		$this->stub( [ 'pictures' => [ 'sizes' => [] ] ] );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$post_id = self::factory()->post->create( [ 'post_type' => 'anchor_webinar', 'post_status' => 'publish' ] );
		$_POST   = [
			\Anchor\Webinars\Module::NONCE => wp_create_nonce( \Anchor\Webinars\Module::NONCE ),
			'anchor_webinar_vimeo_id'      => '123',
		];
		\Anchor\Webinars\Module::instance()->save_meta( $post_id );
		$_POST = [];

		$hit = array_values( array_filter( $this->requests, static fn( $r ) => false !== strpos( $r[0], 'api.vimeo.com/videos/123' ) ) );
		$this->assertNotEmpty( $hit );
		$this->assertSame( 'Bearer shared-tok', $hit[0][1]['headers']['Authorization'] );
	}

	public function test_library_setting_blank_keeps_clear_empties_replace_wins() {
		$san = [ \Anchor\Library\Settings::class, 'sanitize_vimeo_token' ];
		update_option( Anchor_Vimeo_Api::OPTION, 'old', false );
		$this->assertSame( 'old', $san( '' ) );
		$this->assertSame( 'new', $san( ' new ' ) );
		$_POST[ Anchor_Vimeo_Api::OPTION . '_clear' ] = '1';
		$this->assertSame( '', $san( '' ) );
		unset( $_POST[ Anchor_Vimeo_Api::OPTION . '_clear' ] );
	}
}
