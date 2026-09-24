<?php
/**
 * Anchor_Video_URL is the shared YouTube/Vimeo URL parser and thumbnail
 * resolver used by the gallery and (going forward) by testimonials and
 * speakers. thumbnail() can hit the network for Vimeo (an oEmbed call), so
 * every thumbnail() test short-circuits wp_remote_get() via pre_http_request
 * instead of making a real request.
 *
 * @group video-url
 */
class Test_Video_URL extends WP_UnitTestCase {

	/** @dataProvider urls */
	public function test_parse( $url, $provider, $id, $start ) {
		$r = Anchor_Video_URL::parse( $url );
		$this->assertSame( $provider, $r['provider'] );
		$this->assertSame( $id, $r['id'] );
		$this->assertSame( $start, $r['start'] );
	}

	public function urls() {
		return [
			[ 'https://www.youtube.com/watch?v=bBSSR2F69A0&t=53s', 'youtube', 'bBSSR2F69A0', 53 ],
			[ 'https://youtu.be/dwr8S2iOfs8?t=5', 'youtube', 'dwr8S2iOfs8', 5 ],
			[ 'https://www.youtube.com/embed/8I9btCPqwfI', 'youtube', '8I9btCPqwfI', 0 ],
			[ 'https://youtube.com/shorts/KzVacYOs3AI', 'youtube', 'KzVacYOs3AI', 0 ],
			[ 'https://www.youtube.com/watch?feature=share&v=ZXyik8HzGSM', 'youtube', 'ZXyik8HzGSM', 0 ],
			[ 'https://vimeo.com/123456789', 'vimeo', '123456789', 0 ],
			[ 'https://player.vimeo.com/video/123456789?h=abc123', 'vimeo', '123456789', 0 ],
			[ 'https://vimeo.com/channels/staffpicks/987654321', 'vimeo', '987654321', 0 ],
		];
	}

	public function test_non_video_returns_null() {
		$this->assertNull( Anchor_Video_URL::parse( 'https://example.com/watch?v=nope' ) );
	}

	public function test_thumbnail_youtube_is_a_static_url_with_no_network_call() {
		$called = false;
		$listener = function () use ( &$called ) {
			$called = true;
		};
		add_filter( 'pre_http_request', $listener, 10, 0 );
		try {
			$thumb = Anchor_Video_URL::thumbnail( 'youtube', 'bBSSR2F69A0' );
		} finally {
			remove_filter( 'pre_http_request', $listener, 10 );
		}

		$this->assertSame( 'https://i.ytimg.com/vi/bBSSR2F69A0/hqdefault.jpg', $thumb );
		$this->assertFalse( $called, 'YouTube thumbnails are a predictable URL and must never hit the network.' );
	}

	public function test_thumbnail_vimeo_uses_canned_oembed_response_and_caches_it() {
		delete_transient( 'anchor_vimeo_thumb_123456789' );

		$requests = [];
		$mock = function ( $pre, $args, $url ) use ( &$requests ) {
			$requests[] = $url;
			return [
				'headers'  => [],
				'body'     => wp_json_encode( [ 'thumbnail_url' => 'https://i.vimeocdn.com/video/123456789.jpg' ] ),
				'response' => [ 'code' => 200, 'message' => 'OK' ],
				'cookies'  => [],
				'filename' => null,
			];
		};
		add_filter( 'pre_http_request', $mock, 10, 3 );

		try {
			$thumb = Anchor_Video_URL::thumbnail( 'vimeo', '123456789' );
		} finally {
			remove_filter( 'pre_http_request', $mock, 10 );
		}

		$this->assertSame( 'https://i.vimeocdn.com/video/123456789.jpg', $thumb );
		$this->assertCount( 1, $requests, 'oEmbed must be requested exactly once.' );
		$this->assertStringContainsString( 'vimeo.com/api/oembed.json', $requests[0] );
		$this->assertStringContainsString( 'vimeo.com%2F123456789', $requests[0] );

		// Cached: a second call must not hit the network again.
		add_filter( 'pre_http_request', $mock, 10, 3 );
		try {
			$thumb_again = Anchor_Video_URL::thumbnail( 'vimeo', '123456789' );
		} finally {
			remove_filter( 'pre_http_request', $mock, 10 );
		}
		$this->assertSame( $thumb, $thumb_again );
		$this->assertCount( 1, $requests, 'A cached thumbnail must not re-hit the oEmbed endpoint.' );

		delete_transient( 'anchor_vimeo_thumb_123456789' );
	}

	public function test_thumbnail_vimeo_failure_returns_empty_string_and_caches_briefly() {
		delete_transient( 'anchor_vimeo_thumb_000000000' );

		$mock = function ( $pre, $args, $url ) {
			return [
				'headers'  => [],
				'body'     => '',
				'response' => [ 'code' => 404, 'message' => 'Not Found' ],
				'cookies'  => [],
				'filename' => null,
			];
		};
		add_filter( 'pre_http_request', $mock, 10, 3 );
		try {
			$thumb = Anchor_Video_URL::thumbnail( 'vimeo', '000000000' );
		} finally {
			remove_filter( 'pre_http_request', $mock, 10 );
		}

		$this->assertSame( '', $thumb );

		// Cached briefly: get_transient() must return the cached '' (not
		// false, which would mean "not cached") so a broken/private video
		// doesn't re-hit the oEmbed API on every request.
		$this->assertSame( '', get_transient( 'anchor_vimeo_thumb_000000000' ) );

		delete_transient( 'anchor_vimeo_thumb_000000000' );
	}
}
