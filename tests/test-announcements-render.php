<?php
use Anchor\Announcements\Content\AnnouncementPostType as PT;
use Anchor\Announcements\Rendering\Renderer;
use Anchor\Announcements\Suppression\Suppressions;
use Anchor\Announcements\Tracking\LinkRewriter;
use Anchor\Announcements\Tracking\Urls;
use Anchor\Announcements\Support\Settings;

class Test_Announcements_Render extends Anchor_Announcements_TestCase {

	public function test_suppressions_first_reason_wins_and_remove() {
		Suppressions::add( 'A@x.com', 'unsubscribed', 5 );
		Suppressions::add( 'a@x.com', 'bounced' );
		$this->assertTrue( Suppressions::is_suppressed( 'a@X.com' ) );
		$this->assertSame( 'unsubscribed', Suppressions::list()['rows'][0]['reason'] );
		Suppressions::remove( 'a@x.com' );
		$this->assertFalse( Suppressions::is_suppressed( 'a@x.com' ) );
	}

	public function test_suppress_action() {
		do_action( 'anchor_announcements_suppress', 'b@x.com', 'complained', 0 );
		$this->assertTrue( Suppressions::is_suppressed( 'b@x.com' ) );
	}

	public function test_links_are_collected_decoded_and_deduped() {
		$body  = '<a href="https://x.com/?a=1&amp;b=2">1</a><a href="mailto:a@x.com">m</a><a href="tel:123">t</a><a href="#top">h</a>'
			. '<a href="{unsubscribe_url}">u</a><a href="https://x.com/?a=1&amp;b=2">again</a><a href="{account_url}">acct</a><a href="javascript:x">j</a>';
		$this->assertSame( [ 'https://x.com/?a=1&b=2', '{account_url}' ], LinkRewriter::links( $body ) );
	}

	public function test_rewrite_replaces_only_trackable_hrefs() {
		$body  = '<a href="https://x.com/?a=1&amp;b=2">1</a> <a href="mailto:a@x.com">m</a> <a href=\'{account_url}\'>acct</a>';
		$links = LinkRewriter::links( $body );
		$out   = LinkRewriter::rewrite( $body, $links, fn( $i ) => 'https://site.test/?anchor_aa=c&t=T&l=' . $i );
		$this->assertStringContainsString( 'href="https://site.test/?anchor_aa=c&amp;t=T&amp;l=0"', $out );
		$this->assertStringContainsString( 'href="https://site.test/?anchor_aa=c&amp;t=T&amp;l=1"', $out );
		$this->assertStringContainsString( 'href="mailto:a@x.com"', $out );
	}

	public function test_a_pasted_full_document_renders_with_exactly_one_pixel() {
		Settings::save( [ 'footer_address' => '1 Main St' ] );
		$id   = $this->make_announcement();
		$mail = Renderer::render( $id, [ 'email' => 'a@x.com', 'user_id' => 0, 'name' => 'A' ], str_repeat( 'a', 32 ), true, [ 'subject' => 'S', 'preheader' => '', 'body' => '<html><head><title>T</title></head><body><p>Hi</p></body></html>' ] );
		$this->assertSame( 1, substr_count( $mail['html'], 'anchor_aa=o' ) );
		$this->assertSame( 1, substr_count( $mail['html'], '</body>' ) );
		$this->assertStringContainsString( '<p>Hi</p>', $mail['html'] );
	}

	public function test_render_expands_tokens_rewrites_links_and_adds_pixel_and_footer() {
		Settings::save( [ 'footer_address' => "1 Main St\nTown", 'brand_color' => '#112233' ] );
		$uid = $this->make_user( 'rita@x.com', [ 'first_name' => 'Rita', 'last_name' => 'Ray', 'display_name' => 'Rita Ray', 'user_login' => 'rita' ] );
		$id  = $this->make_announcement( [ PT::META_BODY => '<p>Hi {first_name} ({username})</p><a href="https://example.com/page?a=1&amp;b=2">read</a>' ] );
		update_post_meta( $id, PT::META_LINKS, LinkRewriter::links( get_post_meta( $id, PT::META_BODY, true ) ) );
		$out = Renderer::render( $id, [ 'email' => 'rita@x.com', 'user_id' => $uid, 'name' => 'Rita Ray' ], str_repeat( 'a', 32 ), true );
		$this->assertSame( 'Hello Rita', $out['subject'] );
		$this->assertStringContainsString( 'Hi Rita (rita)', $out['html'] );
		$this->assertStringContainsString( esc_attr( Urls::click( str_repeat( 'a', 32 ), 0 ) ), $out['html'] );
		$this->assertStringNotContainsString( 'https://example.com/page', $out['html'] );
		$this->assertStringContainsString( 'anchor_aa=o', $out['html'] );
		$this->assertStringContainsString( '1 Main St<br />', $out['html'] );
		$this->assertStringContainsString( 'anchor_aa=u', $out['html'] );
		$this->assertStringContainsString( '#112233', $out['html'] );
	}

	public function test_untracked_render_keeps_original_links_and_has_no_pixel() {
		Settings::save( [ 'footer_address' => '1 Main St' ] );
		$id  = $this->make_announcement();
		$out = Renderer::render( $id, [ 'email' => 'g@x.com', 'user_id' => 0, 'name' => 'Gia Guest' ], '', false );
		$this->assertStringContainsString( 'https://example.com/page?a=1&amp;b=2', $out['html'] );
		$this->assertStringNotContainsString( 'anchor_aa=o', $out['html'] );
	}

	public function test_guest_tokens() {
		$t = Renderer::tokens( [ 'email' => 'g@x.com', 'user_id' => 0, 'name' => 'Gia Van Guest' ], 'tok' );
		$this->assertSame( 'Gia', $t['first_name'] );
		$this->assertSame( 'Van Guest', $t['last_name'] );
		$this->assertSame( '', $t['username'] );
		$this->assertSame( 'g@x.com', $t['email'] );
		$this->assertSame( Urls::unsubscribe( 'tok' ), $t['unsubscribe_url'] );
	}

	public function test_override_renders_unsaved_content() {
		Settings::save( [ 'footer_address' => '1 Main St' ] );
		$id  = $this->make_announcement();
		$out = Renderer::render( $id, Renderer::sample_recipient(), '', false, [ 'subject' => 'Draft {site_name}', 'preheader' => '', 'body' => '<p>Unsaved</p><script>x</script>' ] );
		$this->assertSame( 'Draft ' . get_bloginfo( 'name' ), $out['subject'] );
		$this->assertStringContainsString( '<p>Unsaved</p>', $out['html'] );
		$this->assertStringNotContainsString( '<script', $out['html'] );
	}

	public function test_subject_is_plain_text_even_when_the_site_name_has_entities() {
		update_option( 'blogname', 'Bob&#039;s Dental &amp; Lasers' );
		Settings::save( [ 'footer_address' => '1 Main St' ] );
		$id  = $this->make_announcement();
		$out = Renderer::render( $id, Renderer::sample_recipient(), '', false, [ 'subject' => 'News from {site_name}', 'preheader' => '', 'body' => '<p>x</p>' ] );
		$this->assertSame( "News from Bob's Dental & Lasers", $out['subject'] );
	}
}
