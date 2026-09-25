<?php
/** @group speakers */
class Test_Speakers_Render extends WP_UnitTestCase {
	public function test_featured_filter_and_order() {
		$a = self::factory()->post->create( [ 'post_type' => 'anchor_speaker', 'post_title' => 'Dr. B', 'menu_order' => 2 ] );
		$b = self::factory()->post->create( [ 'post_type' => 'anchor_speaker', 'post_title' => 'Dr. A', 'menu_order' => 1 ] );
		$c = self::factory()->post->create( [ 'post_type' => 'anchor_speaker', 'post_title' => 'Dr. C' ] );
		Anchor_Speaker_Meta::save( $a, [ 'featured' => '1', 'credentials' => 'DDS' ] );
		Anchor_Speaker_Meta::save( $b, [ 'featured' => '1' ] );
		$html = do_shortcode( '[anchor_speakers featured="1"]' );
		$this->assertLessThan( strpos( $html, 'Dr. B' ), strpos( $html, 'Dr. A' ) );
		$this->assertStringNotContainsString( 'Dr. C', $html );
		$this->assertStringContainsString( 'anchor-speaker__credentials">DDS', $html );
	}
	public function test_ids_order_and_link_off() {
		$a = self::factory()->post->create( [ 'post_type' => 'anchor_speaker', 'post_title' => 'One' ] );
		$b = self::factory()->post->create( [ 'post_type' => 'anchor_speaker', 'post_title' => 'Two' ] );
		$html = do_shortcode( "[anchor_speakers ids=\"$b,$a\" link=\"0\"]" );
		$this->assertLessThan( strpos( $html, 'One' ), strpos( $html, 'Two' ) );
		$this->assertStringNotContainsString( '<a ', $html );
	}

	/**
	 * An auto-generated excerpt's trailing "more" text is the literal entity
	 * "&hellip;", not a raw ellipsis character, so the excerpt is already
	 * HTML. Asserts it is never double-encoded into visible "&amp;hellip;"
	 * text (WordPress' esc_html() already guards against this by default,
	 * so this alone would pass either way; see the second assertion below
	 * for a case that actually distinguishes wp_kses_post() from esc_html()).
	 */
	public function test_auto_excerpt_does_not_double_encode_entities() {
		$id = self::factory()->post->create( [
			'post_type'    => 'anchor_speaker',
			'post_title'   => 'Dr. Long Bio',
			'post_content' => str_repeat( 'word ', 60 ),
			'post_excerpt' => '',
		] );
		$html = do_shortcode( "[anchor_speakers ids=\"$id\"]" );
		$this->assertStringContainsString( 'anchor-speaker__excerpt', $html );
		$this->assertStringNotContainsString( '&amp;hellip;', $html );
	}

	/**
	 * A manual excerpt is already HTML and may intentionally contain simple
	 * inline markup (the same convention testimonials uses for post_content
	 * via wp_kses_post()). esc_html() would strip that down to visible,
	 * escaped tag text instead of rendering it; this is the case that
	 * actually distinguishes the two.
	 */
	public function test_manual_excerpt_keeps_allowed_inline_markup() {
		$id = self::factory()->post->create( [
			'post_type'    => 'anchor_speaker',
			'post_title'   => 'Dr. Rich Excerpt',
			'post_excerpt' => 'Board-certified in <strong>orofacial pain</strong>.',
		] );
		$html = do_shortcode( "[anchor_speakers ids=\"$id\"]" );
		$this->assertStringContainsString( '<strong>orofacial pain</strong>', $html );
	}

	/**
	 * PR #28 review finding A: an `event` attribute that resolves to no
	 * linked speakers (a real event with none linked, or the Events module
	 * being inactive so event_speaker_ids() always returns []) must render
	 * nothing, not fall through to the unscoped query and list every
	 * published speaker.
	 */
	public function test_event_with_no_linked_speakers_renders_nothing() {
		self::factory()->post->create( [ 'post_type' => 'anchor_speaker', 'post_title' => 'Dr. Everyone' ] );
		$event = self::factory()->post->create( [ 'post_type' => 'post', 'post_title' => 'Some Event' ] );
		$html  = do_shortcode( "[anchor_speakers event=\"$event\"]" );
		$this->assertSame( '', $html );
	}

	/**
	 * An invalid/non-numeric event id (resolves to 0) must behave the same
	 * as a valid event with no linked speakers: nothing renders.
	 */
	public function test_invalid_event_id_renders_nothing() {
		self::factory()->post->create( [ 'post_type' => 'anchor_speaker', 'post_title' => 'Dr. Everyone' ] );
		$html = do_shortcode( '[anchor_speakers event="not-a-real-id"]' );
		$this->assertSame( '', $html );
	}

	/**
	 * PR #28 review finding K (Codex P2): [anchor_speakers] embedded on a
	 * non-speaker page normally runs during the_content, after wp_head has
	 * already printed the style queue, so speakers.css would only be picked
	 * up via print_late_styles() in the footer. maybe_enqueue_frontend_assets(),
	 * hooked on wp_enqueue_scripts (before wp_head), covers a singular
	 * post/page whose content literally contains the shortcode, in addition
	 * to the existing is_singular(self::CPT) case.
	 */
	public function test_maybe_enqueue_frontend_assets_enqueues_early_on_a_singular_page_with_the_shortcode() {
		$page = self::factory()->post->create( [ 'post_type' => 'page', 'post_content' => 'Meet our team. [anchor_speakers]' ] );
		$this->go_to( get_permalink( $page ) );

		( new Anchor_Speakers_Module() )->maybe_enqueue_frontend_assets();

		$this->assertTrue( wp_style_is( 'anchor-speakers', 'enqueued' ) );
	}

	public function test_maybe_enqueue_frontend_assets_is_a_noop_on_a_singular_page_without_the_shortcode() {
		// The style queue is process-global, not reset between test methods,
		// so start from a known-clean state (same convention as
		// tests/test-compliance-banner.php) rather than relying on test order.
		wp_dequeue_style( 'anchor-speakers' );
		wp_deregister_style( 'anchor-speakers' );

		$page = self::factory()->post->create( [ 'post_type' => 'page', 'post_content' => 'Nothing to see here.' ] );
		$this->go_to( get_permalink( $page ) );

		( new Anchor_Speakers_Module() )->maybe_enqueue_frontend_assets();

		$this->assertFalse( wp_style_is( 'anchor-speakers', 'enqueued' ) );
	}

	/**
	 * Owner feedback round 1: layout="avatars" renders one linked, alt-carrying
	 * headshot per post and no names/credentials/titles as text.
	 */
	public function test_avatars_layout_renders_photo_with_alt_and_link() {
		$id = self::factory()->post->create( [ 'post_type' => 'anchor_speaker', 'post_title' => 'Dr. Jane "J" Doe' ] );
		Anchor_Speaker_Meta::save( $id, [ 'credentials' => 'DDS', 'title' => 'Founder' ] );
		$attachment_id = self::factory()->attachment->create_object( [ 'file' => 'headshot.jpg', 'post_parent' => 0, 'post_mime_type' => 'image/jpeg', 'post_type' => 'attachment' ] );
		set_post_thumbnail( $id, $attachment_id );

		$html = do_shortcode( '[anchor_speakers layout="avatars"]' );

		$this->assertStringContainsString( 'anchor-speakers--avatars', $html );
		$this->assertStringContainsString( 'anchor-speaker-avatar__img', $html );
		// get_the_title() runs the title through wptexturize (straight quotes
		// become curly), and wp_get_attachment_image()'s own attribute
		// builder escapes the result into numeric entities - so the raw
		// double-quote character never reaches the alt attribute unescaped.
		$this->assertStringContainsString( 'alt="Dr. Jane &#8220;J&#8221; Doe"', $html );
		$this->assertStringNotContainsString( 'alt="Dr. Jane "J" Doe"', $html );
		$this->assertStringContainsString( '<a class="anchor-speaker-avatar" href="' . get_permalink( $id ) . '">', $html );
		// No visible name/credentials/title text anywhere in the avatars markup.
		$this->assertStringNotContainsString( 'anchor-speaker__name', $html );
		$this->assertStringNotContainsString( 'DDS', $html );
		$this->assertStringNotContainsString( 'Founder', $html );
	}

	/**
	 * A speaker with no thumbnail still gets an avatar slot (an initial
	 * placeholder) rather than being silently dropped from the stack, and its
	 * accessible name moves to an aria-label on the wrapper since there is no
	 * <img> to carry an alt attribute.
	 */
	public function test_avatars_layout_placeholder_when_no_photo() {
		self::factory()->post->create( [ 'post_type' => 'anchor_speaker', 'post_title' => 'Dr. No Photo' ] );

		$html = do_shortcode( '[anchor_speakers layout="avatars"]' );

		$this->assertStringContainsString( 'anchor-speaker-avatar__img--placeholder', $html );
		$this->assertStringContainsString( 'aria-label="Dr. No Photo"', $html );
		$this->assertStringContainsString( '>D<', $html );
	}

	/**
	 * `featured="1" limit="3"` (the brief's example usage) resolves through
	 * the same query every other layout uses: only featured speakers render,
	 * capped at the limit.
	 */
	public function test_avatars_layout_respects_featured_and_limit() {
		for ( $i = 0; $i < 5; $i++ ) {
			$id = self::factory()->post->create( [ 'post_type' => 'anchor_speaker', 'post_title' => "Featured $i" ] );
			Anchor_Speaker_Meta::save( $id, [ 'featured' => '1' ] );
		}
		self::factory()->post->create( [ 'post_type' => 'anchor_speaker', 'post_title' => 'Not Featured' ] );

		$html = do_shortcode( '[anchor_speakers layout="avatars" featured="1" limit="3"]' );

		$this->assertSame( 3, substr_count( $html, 'class="anchor-speaker-avatar"' ) );
		$this->assertStringNotContainsString( 'Not Featured', $html );
	}

	/**
	 * link="0" must swap the avatar's wrapper element from <a> to <span>,
	 * same convention as the grid/list/compact layouts' photo/name/CTA.
	 */
	public function test_avatars_layout_link_toggle_uses_span_not_anchor() {
		self::factory()->post->create( [ 'post_type' => 'anchor_speaker', 'post_title' => 'Dr. No Link' ] );

		$html = do_shortcode( '[anchor_speakers layout="avatars" link="0"]' );

		$this->assertStringContainsString( '<span class="anchor-speaker-avatar"', $html );
		$this->assertStringNotContainsString( '<a ', $html );
	}

	/**
	 * Owner feedback round 1 (still correct after round 2's body-wrapper
	 * restructuring): when a speaker has BOTH a title and credentials,
	 * layout="list" puts "Name, credentials" on one line (credentials
	 * nested inside the name heading) with the title as the role line below
	 * it, and renders a trailing "View" CTA with the .anchor-speaker__cta
	 * modifier, instead of the grid/compact "View profile" link.
	 */
	public function test_list_layout_combines_name_and_credentials_with_cta() {
		$id = self::factory()->post->create( [ 'post_type' => 'anchor_speaker', 'post_title' => 'Dr. Steven Olmos' ] );
		Anchor_Speaker_Meta::save( $id, [ 'credentials' => 'DDS', 'title' => 'Founder' ] );

		$html = do_shortcode( '[anchor_speakers layout="list"]' );

		$this->assertMatchesRegularExpression(
			'~<div class="anchor-speaker__body"><h3 class="anchor-speaker__name"><a href="[^"]*">Dr\. Steven Olmos</a><span class="anchor-speaker__credentials">, DDS</span></h3><p class="anchor-speaker__title">Founder</p>~',
			$html
		);
		$this->assertStringContainsString( 'anchor-speaker__link anchor-speaker__cta', $html );
		$this->assertStringContainsString( '>View<', $html );
		$this->assertStringNotContainsString( 'View profile', $html );
	}

	/**
	 * Owner feedback round 2 (2.png): a speaker with credentials but NO
	 * title used to render nothing on the role line at all (title-only
	 * logic, no fallback). Credentials must now fill that line instead,
	 * reusing the .anchor-speaker__title class (styled as plain body text,
	 * not the inline "name, credentials" treatment) - and must NOT also
	 * appear inline after the name in this case, so they show exactly once.
	 */
	public function test_list_layout_shows_credentials_as_the_role_line_when_title_is_empty() {
		$id = self::factory()->post->create( [ 'post_type' => 'anchor_speaker', 'post_title' => 'Dr. No Title' ] );
		Anchor_Speaker_Meta::save( $id, [ 'credentials' => 'DDS' ] );

		$html = do_shortcode( '[anchor_speakers layout="list"]' );

		$this->assertMatchesRegularExpression(
			'~<h3 class="anchor-speaker__name"><a href="[^"]*">Dr\. No Title</a></h3><p class="anchor-speaker__title">DDS</p>~',
			$html
		);
		// Credentials appear exactly once (as the role line), never also
		// nested inline after the name.
		$this->assertSame( 1, substr_count( $html, 'DDS' ) );
		$this->assertStringNotContainsString( 'anchor-speaker__credentials', $html );
	}

	/**
	 * A speaker with neither a title nor credentials gets no role line at
	 * all (not an empty <p>), and the body wrapper still exists around the
	 * name alone.
	 */
	public function test_list_layout_omits_role_line_when_title_and_credentials_are_both_empty() {
		// post_content explicitly empty: the factory default is non-empty
		// placeholder text, which would otherwise auto-generate a non-empty
		// excerpt (default `show` includes 'excerpt') and break this test's
		// assumption that nothing renders between the name and the closing
		// .anchor-speaker__body tag.
		$id = self::factory()->post->create( [ 'post_type' => 'anchor_speaker', 'post_title' => 'Dr. Bare', 'post_content' => '', 'post_excerpt' => '' ] );

		$html = do_shortcode( '[anchor_speakers layout="list"]' );

		$this->assertMatchesRegularExpression(
			'~<div class="anchor-speaker__body"><h3 class="anchor-speaker__name"><a href="[^"]*">Dr\. Bare</a></h3></div>~',
			$html
		);
		$this->assertStringNotContainsString( 'anchor-speaker__title', $html );
	}

	/**
	 * The grid layout must be unaffected by the list-only restructuring:
	 * credentials stay a sibling <p>, and the CTA stays "View profile" with
	 * no .anchor-speaker__cta modifier.
	 */
	public function test_grid_layout_keeps_separate_credentials_and_view_profile_link() {
		$id = self::factory()->post->create( [ 'post_type' => 'anchor_speaker', 'post_title' => 'Dr. Grid' ] );
		Anchor_Speaker_Meta::save( $id, [ 'credentials' => 'DDS' ] );

		$html = do_shortcode( '[anchor_speakers layout="grid"]' );

		$this->assertStringContainsString( '<p class="anchor-speaker__credentials">DDS</p>', $html );
		$this->assertStringContainsString( 'View profile', $html );
		$this->assertStringNotContainsString( 'anchor-speaker__cta', $html );
	}

	/**
	 * Owner feedback round 3: a speaker with no featured image used to have
	 * no .anchor-speaker__photo element at all in layout="list", so that
	 * row's text shifted left out of alignment with every other row. It
	 * must now get the same initial-letter placeholder circle the avatars
	 * layout uses, inside the same .anchor-speaker__photo slot every other
	 * row has.
	 */
	public function test_list_layout_renders_placeholder_photo_when_no_thumbnail() {
		$id = self::factory()->post->create( [ 'post_type' => 'anchor_speaker', 'post_title' => 'Dr. June Williamson' ] );

		$html = do_shortcode( '[anchor_speakers layout="list"]' );

		$this->assertMatchesRegularExpression(
			'~<a class="anchor-speaker__photo" href="[^"]*"><span class="anchor-speaker-avatar__img anchor-speaker-avatar__img--placeholder" aria-hidden="true">D</span></a>~',
			$html
		);
	}

	/**
	 * The placeholder is one shared implementation, not two: the exact same
	 * markup (classes and initial letter) renders for the same photo-less
	 * speaker under both layout="avatars" and layout="list".
	 */
	public function test_avatars_and_list_placeholder_share_one_implementation() {
		self::factory()->post->create( [ 'post_type' => 'anchor_speaker', 'post_title' => 'Zoe Placeholder' ] );

		$avatars_html = do_shortcode( '[anchor_speakers layout="avatars"]' );
		$list_html    = do_shortcode( '[anchor_speakers layout="list"]' );

		$placeholder = '<span class="anchor-speaker-avatar__img anchor-speaker-avatar__img--placeholder" aria-hidden="true">Z</span>';
		$this->assertStringContainsString( $placeholder, $avatars_html );
		$this->assertStringContainsString( $placeholder, $list_html );
	}

	/**
	 * The placeholder fix must not regress the real-photo path: a speaker
	 * with a thumbnail still gets their actual photo, not the placeholder.
	 */
	public function test_list_layout_uses_real_photo_when_thumbnail_is_set() {
		$id = self::factory()->post->create( [ 'post_type' => 'anchor_speaker', 'post_title' => 'Dr. Has Photo' ] );
		$attachment_id = self::factory()->attachment->create_object( [ 'file' => 'headshot.jpg', 'post_parent' => 0, 'post_mime_type' => 'image/jpeg', 'post_type' => 'attachment' ] );
		set_post_thumbnail( $id, $attachment_id );

		$html = do_shortcode( '[anchor_speakers layout="list"]' );

		$this->assertMatchesRegularExpression( '~<a class="anchor-speaker__photo" href="[^"]*"><img[^>]*></a>~', $html );
		$this->assertStringNotContainsString( 'anchor-speaker-avatar__img--placeholder', $html );
	}

	/**
	 * Review finding (round 4): .anchor-speaker-avatar__img's ring border is
	 * meant for the overlapping "avatars" layout stack. list_card()'s
	 * photo-less placeholder reuses that same class (photo_placeholder()),
	 * so an unscoped border rule would draw a ring around a list row's
	 * placeholder that its photo-having neighbors never get (confirmed
	 * visually in fac-fix2.png before this fix). Parses speakers.css's
	 * actual rules (same convention as
	 * test-event-frontend-render.php::test_frontend_css_covers_every_class_...)
	 * rather than a brittle string search, so a future reformatting of the
	 * file can't accidentally satisfy this test without the selector
	 * actually being scoped correctly.
	 */
	public function test_speakers_css_scopes_the_avatar_ring_border_to_avatars_layout_only() {
		$css = file_get_contents( ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-speakers/assets/speakers.css' );
		$this->assertNotFalse( $css, 'Could not read anchor-speakers/assets/speakers.css.' );
		$css = (string) preg_replace( '#/\*.*?\*/#s', '', $css );

		preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER );

		$bare_rule_has_border   = false;
		$scoped_rule_has_border = false;

		foreach ( $rules as $rule ) {
			$selectors = array_map( 'trim', explode( ',', $rule[1] ) );
			$body      = $rule[2];
			if ( in_array( '.anchor-speaker-avatar__img', $selectors, true ) && strpos( $body, 'border:' ) !== false ) {
				$bare_rule_has_border = true;
			}
			if ( in_array( '.anchor-speakers--avatars .anchor-speaker-avatar__img', $selectors, true ) && strpos( $body, 'border:' ) !== false ) {
				$scoped_rule_has_border = true;
			}
		}

		$this->assertFalse( $bare_rule_has_border, 'The unscoped .anchor-speaker-avatar__img rule must not set a border - it would also ring a list-layout placeholder.' );
		$this->assertTrue( $scoped_rule_has_border, 'The avatar ring border must be scoped to .anchor-speakers--avatars .anchor-speaker-avatar__img.' );
	}
}
