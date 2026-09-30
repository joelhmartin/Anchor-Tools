<?php
/**
 * Email kit: tokens, sanitizer, shell.
 */
class Test_Email_Kit extends WP_UnitTestCase {

	public function test_expand_escapes_values_in_html_context() {
		$out = Anchor_Email_Tokens::expand( '<p>Hi {first_name}</p>', [ 'first_name' => 'Bob <b>"Jr"</b>' ] );
		$this->assertSame( '<p>Hi Bob &lt;b&gt;&quot;Jr&quot;&lt;/b&gt;</p>', $out );
	}

	public function test_expand_leaves_values_raw_in_text_context() {
		$out = Anchor_Email_Tokens::expand( 'Hi {first_name}', [ 'first_name' => 'Bob <b>"Jr"</b>' ], false );
		$this->assertSame( 'Hi Bob <b>"Jr"</b>', $out );
	}

	public function test_url_tokens_are_url_escaped_in_html() {
		$out = Anchor_Email_Tokens::expand( '<a href="{login_url}">x</a>', [ 'login_url' => 'javascript:alert(1)' ] );
		$this->assertStringNotContainsString( 'javascript:', $out );
	}

	public function test_unknown_tokens_are_left_alone() {
		$this->assertSame( 'Hi {nope}', Anchor_Email_Tokens::expand( 'Hi {nope}', [ 'first_name' => 'A' ] ) );
	}

	public function test_registry_round_trip() {
		Anchor_Email_Tokens::reset();
		Anchor_Email_Tokens::register( 'first_name', 'First name', 'Recipient' );
		$this->assertSame( [ 'first_name' => [ 'label' => 'First name', 'group' => 'Recipient' ] ], Anchor_Email_Tokens::registered() );
	}

	public function test_sanitizer_keeps_email_markup_and_strips_script() {
		$html = '<table role="presentation" style="width:100%"><tr><td style="color:red"><a href="https://x.com" style="color:blue">x</a><img src="https://x.com/a.png" alt="a" width="10"></td></tr></table><script>alert(1)</script><form><input></form>';
		$out  = Anchor_Email_Sanitizer::body( $html );
		$this->assertStringContainsString( '<table role="presentation" style="width:100%">', $out );
		$this->assertStringContainsString( '<a href="https://x.com" style="color:blue">', $out );
		$this->assertStringContainsString( '<img src="https://x.com/a.png" alt="a" width="10"', $out );
		$this->assertStringNotContainsString( '<script', $out );
		$this->assertStringNotContainsString( '<form', $out );
		$this->assertStringNotContainsString( '<input', $out );
	}

	public function test_sanitizer_keeps_only_the_inner_content_of_a_pasted_document() {
		$out = Anchor_Email_Sanitizer::body( '<!doctype html><html><head><meta charset="utf-8"><title>T</title></head><body style="x:y"><p>Inner</p></body></html>' );
		foreach ( [ '<html', '<head', '<meta', '<body', '</body>', '</html>' ] as $tag ) {
			$this->assertStringNotContainsString( $tag, $out );
		}
		$this->assertStringContainsString( '<p>Inner</p>', $out );
	}

	public function test_sanitizer_strips_data_uri_hrefs() {
		$out = Anchor_Email_Sanitizer::body( '<a href="data:text/html,x">x</a>' );
		$this->assertStringNotContainsString( 'data:', $out );
	}

	public function test_sanitizer_keeps_token_placeholders_in_hrefs() {
		$out = Anchor_Email_Sanitizer::body( '<a href="{login_url}">Sign in</a>' );
		$this->assertStringContainsString( 'href="{login_url}"', $out );
	}

	public function test_shell_renders_parts() {
		$html = Anchor_Email_Shell::render(
			[
				'title'       => 'Hello',
				'preheader'   => 'Quick note',
				'body'        => '<p>Body text</p>',
				'footer'      => '<p>123 Main St</p>',
				'brand_color' => '#123456',
				'logo_url'    => 'https://x.com/logo.png',
			]
		);
		$this->assertStringContainsString( '<p>Body text</p>', $html );
		$this->assertStringContainsString( '123 Main St', $html );
		$this->assertStringContainsString( 'Quick note', $html );
		$this->assertStringContainsString( '#123456', $html );
		$this->assertStringContainsString( 'https://x.com/logo.png', $html );
		$this->assertStringContainsString( '</body>', $html );
	}

	public function test_shell_rejects_a_bad_brand_color() {
		$html = Anchor_Email_Shell::render( [ 'body' => 'x', 'brand_color' => 'red;background:url(x)' ] );
		$this->assertStringNotContainsString( 'url(x)', $html );
	}

	public function test_builder_markup_carries_fields_and_tokens() {
		Anchor_Email_Tokens::reset();
		Anchor_Email_Tokens::register( 'first_name', 'First name', 'Recipient' );
		$html = Anchor_Email_Kit::builder_markup(
			[ 'id' => 'aa', 'name' => 'anchor_announcement', 'subject' => 'S & T', 'preheader' => 'P', 'body' => '<p>B</p>' ]
		);
		$this->assertStringContainsString( 'data-anchor-email-builder', $html );
		$this->assertStringContainsString( 'name="anchor_announcement[subject]" value="S &amp; T"', $html );
		$this->assertStringContainsString( 'name="anchor_announcement[preheader]"', $html );
		$this->assertStringContainsString( 'name="anchor_announcement[body]"', $html );
		$this->assertStringContainsString( '&lt;p&gt;B&lt;/p&gt;', $html ); // textarea content is escaped
		$this->assertStringContainsString( 'data-token="{first_name}"', $html );
		$this->assertStringContainsString( 'anchor-email-builder__frame', $html );
	}
}
