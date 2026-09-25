<?php
/**
 * Embed normaliser tests (virtual-events spec §5.4). No WooCommerce required.
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Embed;

/**
 * @group embed
 */
class Test_Embed extends Anchor_Events_TestCase {

	/** Vimeo: plain video, player URL with an unlisted hash, and an event URL. */
	public function test_vimeo_variants() {
		$this->assertSame(
			'https://player.vimeo.com/video/123456',
			Embed::normalize( 'https://vimeo.com/123456' )['src']
		);
		$this->assertSame(
			'https://player.vimeo.com/video/123456?h=abc123',
			Embed::normalize( 'https://player.vimeo.com/video/123456?h=abc123' )['src'],
			'The unlisted-video hash must survive normalisation.'
		);
		$this->assertSame(
			'https://player.vimeo.com/event/456/embed',
			Embed::normalize( 'https://vimeo.com/event/456' )['src']
		);
	}

	/** YouTube: all four input shapes collapse to the nocookie embed. */
	public function test_youtube_variants() {
		foreach ( [
			'https://youtu.be/dQw4w9WgXcQ',
			'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
			'https://www.youtube.com/live/dQw4w9WgXcQ',
			'https://www.youtube.com/embed/dQw4w9WgXcQ',
		] as $input ) {
			$this->assertSame(
				'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
				Embed::normalize( $input )['src'],
				"Failed for {$input}"
			);
		}
	}

	/** A playlist/results URL is not a bare video id — the bare-path branch
	 * must not swallow it (CodeRabbit PR #27, class-embed.php:65). */
	public function test_youtube_playlist_and_non_video_paths_are_refused() {
		foreach ( [
			'https://www.youtube.com/playlist?list=PLabcdefghijklmnop',
			'https://www.youtube.com/results?search_query=lasers',
		] as $input ) {
			$out = Embed::normalize( $input );
			$this->assertInstanceOf( WP_Error::class, $out, "Expected an error for {$input}" );
		}
	}

	/** youtu.be/<id> (11-char id) still resolves. */
	public function test_youtube_short_link_still_works() {
		$this->assertSame(
			'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
			Embed::normalize( 'https://youtu.be/dQw4w9WgXcQ' )['src']
		);
	}

	/** /shorts/<id> still resolves via the embed/live/v/shorts branch. */
	public function test_youtube_shorts_still_works() {
		$this->assertSame(
			'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
			Embed::normalize( 'https://www.youtube.com/shorts/dQw4w9WgXcQ' )['src']
		);
	}

	/** Pasted iframe HTML has its src extracted. */
	public function test_pasted_iframe_html() {
		$html = '<iframe src="https://player.vimeo.com/video/987?h=zz" width="640" allowfullscreen></iframe>';
		$out  = Embed::normalize( $html );
		$this->assertSame( 'vimeo', $out['provider'] );
		$this->assertSame( 'https://player.vimeo.com/video/987?h=zz', $out['src'] );
		$this->assertSame( $html, $out['raw'], 'The author input is kept verbatim for the metabox.' );
	}

	/** Zoom forbids framing, so it normalises to a link. */
	public function test_zoom_is_a_link() {
		$out = Embed::normalize( 'https://us02web.zoom.us/j/8412345678?pwd=xyz' );
		$this->assertSame( 'zoom', $out['provider'] );
		$this->assertSame( 'link', $out['kind'] );
	}

	/** An unknown host is refused, not guessed at. */
	public function test_unknown_host_is_wp_error() {
		$out = Embed::normalize( 'https://evil.example.com/stream' );
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'anchor_events_embed_unknown_host', $out->get_error_code() );
	}

	/** A site may add its own host through the filter. */
	public function test_provider_filter_adds_a_host() {
		$add = function ( $providers ) {
			$providers['generic'] = [
				'hosts'     => [ 'stream.example.org' ],
				'kind'      => 'iframe',
				// Providers always declare the full ( $path, $query, $url )
				// signature documented on Embed::providers(); dispatch is
				// plain positional call_user_func(), not name-matched.
				'transform' => static function ( $path, $query, $url ) { return $url; },
			];
			return $providers;
		};
		add_filter( 'anchor_events_embed_providers', $add );
		$out = Embed::normalize( 'https://stream.example.org/live/1' );
		remove_filter( 'anchor_events_embed_providers', $add );

		$this->assertSame( 'generic', $out['provider'] );
		$this->assertSame( 'https://stream.example.org/live/1', $out['src'] );
	}

	/** A transform registered as an array (object or static) callable works the same as a closure. */
	public function test_provider_filter_accepts_array_callable() {
		$add = function ( $providers ) {
			$providers['arraycb'] = [
				'hosts'     => [ 'arraycb.example.org' ],
				'kind'      => 'iframe',
				'transform' => [ __CLASS__, 'array_callable_transform' ],
			];
			return $providers;
		};
		add_filter( 'anchor_events_embed_providers', $add );
		$out = Embed::normalize( 'https://arraycb.example.org/live/2' );
		remove_filter( 'anchor_events_embed_providers', $add );

		$this->assertSame( 'arraycb', $out['provider'] );
		$this->assertSame( 'https://arraycb.example.org/live/2', $out['src'] );
	}

	/** Static-method transform used by test_provider_filter_accepts_array_callable(). */
	public static function array_callable_transform( $path, $query, $url ) {
		return $url;
	}

	/** Non-https and javascript: payloads never become a src. */
	public function test_xss_payloads_rejected() {
		foreach ( [
			'javascript:alert(1)',
			'<iframe src="javascript:alert(1)"></iframe>',
			'http://vimeo.com/1',
			'data:text/html;base64,PHNjcmlwdD4=',
		] as $payload ) {
			$this->assertInstanceOf( WP_Error::class, Embed::normalize( $payload ), "Accepted: {$payload}" );
		}
	}

	/** Rendering an iframe carries the hardening attributes. */
	public function test_render_iframe_attributes() {
		$html = Embed::render( Embed::normalize( 'https://vimeo.com/5' ), 'My "Event"' );
		$this->assertStringContainsString( 'allowfullscreen', $html );
		$this->assertStringContainsString( 'referrerpolicy="strict-origin-when-cross-origin"', $html );
		$this->assertStringContainsString( 'title="My &quot;Event&quot;"', $html );
		$this->assertStringContainsString( 'loading="eager"', $html );
	}
}
