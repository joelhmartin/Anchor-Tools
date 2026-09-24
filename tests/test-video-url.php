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
			[ 'https://vimeo.com/1234', 'vimeo', '1234', 0 ],
			[ 'https://vimeo.com/123456789#t=30', 'vimeo', '123456789', 0 ],
			// Pinned regressions (final whole-branch review, finding 1): every one
			// of these parsed under the OLD anchor-gallery normalize_video_url()
			// regex (git show be47ec7:anchor-gallery/anchor-gallery.php) and must
			// still parse to the SAME id now that any stored URL runs through this
			// shared parser at render time on every page load.
			[ 'https://www.youtube.com/watch?v=bBSSR2F69A0?t=5', 'youtube', 'bBSSR2F69A0', 0 ],
			[ 'https://www.youtube.com/watch?v=bBSSR2F69A0/', 'youtube', 'bBSSR2F69A0', 0 ],
			[ 'https://www.youtube.com/watch?v=bBSSR2F69A0)', 'youtube', 'bBSSR2F69A0', 0 ],
			[ 'https://www.youtube.com/watch?v=bBSSR2F69A0.', 'youtube', 'bBSSR2F69A0', 0 ],
			[ 'https://vimeo.com/123456789.', 'vimeo', '123456789', 0 ],
			[ 'https://player.vimeo.com/video/123&autoplay=1', 'vimeo', '123', 0 ],
			// Unlisted-hash URL: the greedy (?:.*/)? the new parser used to have
			// picked the trailing all-digit hash instead of the actual video id
			// (the first numeric path segment) — the old regex always won on the
			// first segment.
			[ 'https://vimeo.com/123456789/1234567890', 'vimeo', '123456789', 0 ],
			// Review-link URL: first numeric segment wins, trailing segments ignored.
			[ 'https://vimeo.com/123456789/review/987654321abc', 'vimeo', '123456789', 0 ],
		];
	}

	public function test_non_video_returns_null() {
		$this->assertNull( Anchor_Video_URL::parse( 'https://example.com/watch?v=nope' ) );
	}

	/**
	 * vimeo.com/event/<id> never matched the OLD regex either (no `video/`
	 * prefix and no digits immediately after `vimeo.com/`), so the new parser
	 * must keep rejecting it too — this is a case the finding calls out
	 * explicitly to "match old behavior", and old behavior here is null.
	 */
	public function test_vimeo_event_url_returns_null_matching_old_behavior() {
		$this->assertNull( Anchor_Video_URL::parse( 'https://vimeo.com/event/12345' ) );
	}

	/**
	 * Runs the OLD anchor-gallery normalize_video_url() regex (copied verbatim
	 * from git show be47ec7:anchor-gallery/anchor-gallery.php, provider/id
	 * fields only) over the full URL corpus above and asserts that for every
	 * URL the old regex accepted, Anchor_Video_URL::parse() accepts it too and
	 * returns the same provider and id. This is the superset guarantee the
	 * finding requires: any URL the OLD gallery accepted before auto-update
	 * must still resolve to a tile after it, not silently disappear.
	 */
	public function test_new_parser_is_a_superset_of_the_old_gallery_regex() {
		$corpus = array_column( $this->urls(), 0 );
		$corpus[] = 'https://vimeo.com/event/12345';
		$corpus[] = 'https://example.com/watch?v=nope';

		$checked_at_least_one = false;
		foreach ( $corpus as $url ) {
			$old = $this->old_gallery_normalize_video_url( $url );
			if ( $old === null ) {
				continue;
			}
			$checked_at_least_one = true;

			$new = Anchor_Video_URL::parse( $url );
			$this->assertNotNull( $new, "New parser must still accept a URL the old regex accepted: $url" );
			$this->assertSame( $old['provider'], $new['provider'], "Provider must match old behavior for: $url" );
			$this->assertSame( $old['id'], $new['id'], "Id must match old behavior for: $url" );
		}

		$this->assertTrue( $checked_at_least_one, 'The corpus must contain at least one URL the old regex accepted.' );
	}

	/**
	 * Verbatim port of anchor-gallery/anchor-gallery.php's private
	 * normalize_video_url() as it existed at be47ec7, trimmed to the
	 * provider/id fields this comparison needs.
	 */
	private function old_gallery_normalize_video_url( $url ) {
		if ( preg_match( '~(?:youtu\.be/|youtube\.com/(?:watch\?v=|embed/|shorts/|live/))([A-Za-z0-9_-]{6,})~', $url, $matches ) ) {
			return [ 'provider' => 'youtube', 'id' => $matches[1] ];
		}

		if ( preg_match( '~vimeo\.com/(?:video/)?([0-9]+)~', $url, $matches ) ) {
			return [ 'provider' => 'vimeo', 'id' => $matches[1] ];
		}

		return null;
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
