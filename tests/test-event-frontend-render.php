<?php
/**
 * Front-end single-event render tests (Task 1.6): the multi-session
 * "Sessions" list, the external-registration link/embed block, and the
 * display-only price label.
 *
 * Covers render_single_content() (sessions) and render_registration_form()
 * (external mode), which the single-event.php template calls directly.
 * occurrence = event post — none of this touches seats/capacity/tiers/
 * product/roster/reconcile.
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Series;

/**
 * @group event-frontend-render
 */
class Test_Event_Frontend_Render extends Anchor_Events_TestCase {

	/** Multisession events render a Sessions block listing each session's date/label. */
	public function test_multisession_event_renders_sessions_list() {
		$event_id = $this->make_event(
			[
				'type'     => 'multisession',
				'sessions' => [
					[ 'date' => '2026-09-01', 'start_time' => '09:00', 'end_time' => '10:00', 'label' => 'Day 1: Orientation' ],
					[ 'date' => '2026-09-02', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'Day 2: Workshop' ],
				],
			]
		);

		$html = $this->module()->render_single_content( $event_id );

		$this->assertStringContainsString( '2026-09-01', $html );
		$this->assertStringContainsString( 'Day 1: Orientation', $html );
		$this->assertStringContainsString( '2026-09-02', $html );
		$this->assertStringContainsString( 'Day 2: Workshop', $html );
	}

	/** A single-type event (or one with zero sessions) renders no Sessions block. */
	public function test_single_type_event_renders_no_sessions_block() {
		$event_id = $this->make_event( [ 'type' => 'single' ] );

		$html = $this->module()->render_single_content( $event_id );

		$this->assertStringNotContainsString( 'anchor-event-sessions', $html );
	}

	/** A multisession event with zero normalized sessions also renders no block. */
	public function test_multisession_event_with_no_sessions_renders_no_block() {
		$event_id = $this->make_event( [ 'type' => 'multisession', 'sessions' => [] ] );

		$html = $this->module()->render_single_content( $event_id );

		$this->assertStringNotContainsString( 'anchor-event-sessions', $html );
	}

	/** External mode with only a URL renders a link button + the display price, escaped. */
	public function test_external_event_link_variant_renders_url_and_price() {
		$event_id = $this->make_event(
			[
				'registration_mode'       => 'external',
				'external_url'            => 'https://example.test/register?a=1&b=2',
				'external_display_price'  => '$495',
			]
		);

		$html = $this->module()->render_registration_form( $event_id );

		$this->assertStringContainsString( 'href="' . esc_url( 'https://example.test/register?a=1&b=2' ) . '"', $html );
		$this->assertStringContainsString( '$495', $html );
		$this->assertStringContainsString( 'anchor-event-external-price', $html );
	}

	/**
	 * External mode with an embed renders the iframe as HTML (real `<iframe`
	 * tag present in the OUTPUT), proving it was echoed raw and NOT
	 * esc_html()'d (which would show `&lt;iframe&gt;` literal text instead).
	 */
	public function test_external_event_embed_variant_renders_iframe_as_html() {
		$module   = $this->module();
		$meta_key = $module->meta_key( 'external_embed' );
		// Mirror the real save path: the value is sanitized once at write time
		// via the registered sanitize_callback (sanitize_external_embed()),
		// exactly like sanitize_meta() does inside update_post_meta().
		$sanitized = sanitize_meta( $meta_key, '<iframe src="https://example.test/embed" width="600" height="400" allowfullscreen></iframe>', 'post', \Anchor\Events\Module::CPT );

		$event_id = $this->make_event(
			[
				'registration_mode'      => 'external',
				'external_embed'         => $sanitized,
				'external_display_price' => '$99',
			]
		);

		$html = $module->render_registration_form( $event_id );

		$this->assertStringContainsString( '<iframe', $html, 'The embed must render as a real <iframe> tag, not escaped text.' );
		$this->assertStringNotContainsString( '&lt;iframe', $html );
		$this->assertStringContainsString( 'src="https://example.test/embed"', $html );
		$this->assertStringContainsString( '$99', $html );
	}

	/**
	 * A stored embed that (hypothetically) contained a <script> was stripped
	 * at save time (sanitize_external_embed()'s wp_kses() allowlist) — the
	 * OUTPUT must never contain an executable <script> tag, even though the
	 * field is echoed raw as trusted HTML.
	 */
	public function test_external_event_embed_never_renders_script_tag() {
		$module   = $this->module();
		$meta_key = $module->meta_key( 'external_embed' );
		$dirty    = '<iframe src="https://example.test/embed"></iframe><script>alert(1)</script>';
		$sanitized = sanitize_meta( $meta_key, $dirty, 'post', \Anchor\Events\Module::CPT );

		$event_id = $this->make_event(
			[
				'registration_mode' => 'external',
				'external_embed'    => $sanitized,
			]
		);

		$html = $module->render_registration_form( $event_id );

		$this->assertStringContainsString( '<iframe', $html );
		$this->assertStringNotContainsString( '<script', $html, 'A stripped-at-save <script> tag must never appear in the rendered output.' );
	}

	/** Embed takes priority over the URL when both are set. */
	public function test_external_event_embed_takes_priority_over_url() {
		$event_id = $this->make_event(
			[
				'registration_mode' => 'external',
				'external_url'      => 'https://example.test/should-not-appear',
				'external_embed'    => '<iframe src="https://example.test/embed-wins"></iframe>',
			]
		);

		$html = $this->module()->render_registration_form( $event_id );

		$this->assertStringContainsString( 'embed-wins', $html );
		$this->assertStringNotContainsString( 'should-not-appear', $html );
	}

	/**
	 * External mode is NOT authoritative over `registration_enabled` — when
	 * registration is explicitly disabled, render_registration_form() must
	 * return an empty string even though registration_mode is 'external' and
	 * external_url is set, matching the legacy external-URL path,
	 * can_view_virtual_link(), and maybe_append_registration_shortcode(),
	 * which all treat registration_enabled=false as "no registration UI".
	 */
	public function test_external_event_with_registration_disabled_renders_nothing() {
		$event_id = $this->make_event(
			[
				'registration_enabled'   => false,
				'registration_mode'      => 'external',
				'external_url'           => 'https://example.test/register',
				'external_display_price' => '$495',
			]
		);

		$html = $this->module()->render_registration_form( $event_id );

		$this->assertSame( '', $html );
		$this->assertStringNotContainsString( 'anchor-event-registration-external', $html );
	}

	/** A `free` mode event still renders the normal inline registration form (no regression). */
	public function test_free_event_still_renders_normal_registration_form() {
		$event_id = $this->make_event( [ 'registration_mode' => 'free' ] );

		$html = $this->module()->render_registration_form( $event_id );

		$this->assertStringContainsString( '<form class="anchor-event-registration"', $html );
		$this->assertStringContainsString( 'anchor_event_email', $html );
		$this->assertStringNotContainsString( 'anchor-event-registration-external', $html );
	}

	/** A `wc` mode event with a paid active tier still renders the normal WooCommerce-seam UI (no regression). */
	public function test_wc_event_still_renders_normal_registration_ui() {
		$this->require_wc();

		$event_id = $this->make_event(
			[ 'registration_mode' => 'wc' ],
			[ [ 'label' => 'General', 'price' => '25', 'active' => 1 ] ]
		);
		$this->product_sync()->sync_event( $event_id );

		$html = $this->module()->render_registration_form( $event_id );

		$this->assertStringNotContainsString( 'anchor-event-registration-external', $html );
	}

	/**
	 * RENDER-D21: output_canonical_url() must stay silent when an SEO plugin
	 * that already emits its own canonical tag is active (Yoast, Rank Math,
	 * All in One SEO or SEOPress — should_emit_canonical() ORs four
	 * class_exists()/defined() checks together). Before the fix this echoed
	 * unconditionally, so a Yoast site got two <link rel="canonical"> tags on
	 * a `?anchor_events_month=` URL.
	 *
	 * Item 5 (final fix wave) — this coverage used to fake detection with a
	 * `class WPSEO_Frontend {}` stand-in declared at FILE SCOPE, so
	 * `class_exists( 'WPSEO_Frontend' )` was true for the rest of the suite
	 * from the moment this file loaded, not just for the one test that
	 * needed it. That made EVERY canonical test in the suite — including
	 * the two filter tests below — exercise the "SEO plugin detected"
	 * branch, and the actual default happy path (no SEO plugin, our tag
	 * emitted) was never covered by anything. A PHP class declaration can't
	 * be un-declared once made, and `@runInSeparateProcess` is not viable in
	 * this suite (see test-email-builder.php's ensure_ajax_die_is_catchable()
	 * docblock — process isolation fails outright, "Serialization of
	 * 'Closure' is not allowed", given this suite's fixtures). The stub is
	 * gone; the class_exists()/defined() OR-chain itself is accepted as
	 * untested (none of its four markers exist in this environment, and
	 * faking any of them the same way would reproduce the identical leak),
	 * and coverage instead moves to what it gates: the default with nothing
	 * detected (below) and the operator override in both directions (the
	 * two filter tests that follow).
	 */
	public function test_output_canonical_url_emits_by_default_with_no_seo_plugin_active() {
		$this->assertFalse(
			class_exists( 'WPSEO_Frontend' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEO_VERSION' ) || defined( 'SEOPRESS_VERSION' ),
			'Fixture invalid: a real SEO-plugin marker is present, so this is no longer the happy path.'
		);
		$_GET['anchor_events_month'] = '2026-10';

		ob_start();
		$this->module()->output_canonical_url();
		$html = ob_get_clean();

		unset( $_GET['anchor_events_month'] );

		$this->assertStringContainsString(
			'<link rel="canonical"',
			$html,
			'With no SEO plugin detected and no filter override, this module must print its own fallback canonical tag.'
		);
	}

	/** The `anchor_events_emit_canonical` filter can force our own tag on even with no SEO plugin detected. */
	public function test_output_canonical_url_filter_can_force_emission() {
		$_GET['anchor_events_month'] = '2026-10';
		add_filter( 'anchor_events_emit_canonical', '__return_true' );

		ob_start();
		$this->module()->output_canonical_url();
		$html = ob_get_clean();

		remove_filter( 'anchor_events_emit_canonical', '__return_true' );
		unset( $_GET['anchor_events_month'] );

		$this->assertStringContainsString( '<link rel="canonical"', $html );
	}

	/** The `anchor_events_emit_canonical` filter can also force our tag off even with no SEO plugin detected. */
	public function test_output_canonical_url_filter_can_force_suppression() {
		$_GET['anchor_events_month'] = '2026-10';
		add_filter( 'anchor_events_emit_canonical', '__return_false' );

		ob_start();
		$this->module()->output_canonical_url();
		$html = ob_get_clean();

		remove_filter( 'anchor_events_emit_canonical', '__return_false' );
		unset( $_GET['anchor_events_month'] );

		$this->assertSame( '', $html );
	}

	/**
	 * RENDER-D18: frontend_assets() (hooked on wp_enqueue_scripts, so it runs
	 * before wp_head) must enqueue on a series-taxonomy archive, not just a
	 * singular event/post-type archive — otherwise the archive's own template
	 * has to enqueue it later, after wp_head, and WordPress prints it via
	 * print_late_styles()/print_late_scripts() in the footer instead.
	 */
	public function test_frontend_assets_enqueues_on_series_archive() {
		$term = wp_insert_term( 'RENDER-D18 series', Series::TAXONOMY );
		$this->assertIsArray( $term, 'wp_insert_term() must succeed for this test to mean anything.' );
		$this->go_to( get_term_link( (int) $term['term_id'], Series::TAXONOMY ) );

		$this->assertTrue( is_tax( Series::TAXONOMY ), 'go_to() must land on the series archive for this test to mean anything.' );

		$this->module()->frontend_assets();

		$this->assertTrue( wp_style_is( 'anchor-events-frontend', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'anchor-events-frontend', 'enqueued' ) );
	}

	/**
	 * RENDER-D35: template_include() -> locate_template() only ever looked
	 * for `events/<file>` in the active theme, so a theme overriding a
	 * template at its ROOT (the same place WordPress's own template
	 * hierarchy would find `single-event.php`) was silently ignored and the
	 * plugin's own bundled template rendered instead.
	 */
	public function test_template_include_honours_root_level_theme_override() {
		$event_id = $this->make_event();
		$this->go_to( get_permalink( $event_id ) );

		$theme_root = get_stylesheet_directory() . '/single-event.php';
		$this->assertFileDoesNotExist( $theme_root, 'A stray fixture from another test would invalidate this one.' );
		file_put_contents( $theme_root, '<?php // RENDER-D35 root-level theme override fixture' );

		try {
			$result = $this->module()->template_include( '/should-not-be-returned.php' );
		} finally {
			unlink( $theme_root );
		}

		$this->assertSame( $theme_root, $result, 'A root-level theme single-event.php must win over the plugin bundled template.' );
	}

	/** Regression: the existing events/<file> theme override location still wins over the plugin template. */
	public function test_template_include_still_honours_events_subdir_theme_override() {
		$event_id = $this->make_event();
		$this->go_to( get_permalink( $event_id ) );

		$theme_dir = get_stylesheet_directory() . '/events';
		if ( ! is_dir( $theme_dir ) ) {
			mkdir( $theme_dir );
		}
		$theme_override = $theme_dir . '/single-event.php';
		file_put_contents( $theme_override, '<?php // RENDER-D35 events/ subdir theme override fixture' );

		try {
			$result = $this->module()->template_include( '/should-not-be-returned.php' );
		} finally {
			unlink( $theme_override );
		}

		$this->assertSame( $theme_override, $result );
	}

	/**
	 * RENDER-D33: the old guard grepped the RAW STORED post_content string
	 * for the [event_registration] shortcode tag, so a shortcode injected at
	 * render time by a builder/another 'the_content' filter (not stored in
	 * the post at all) went undetected and the registration UI rendered
	 * twice. render_single_event_body() must catch this case because it
	 * checks what the content actually rendered, not the stored string.
	 */
	public function test_render_single_event_body_avoids_duplicate_when_shortcode_injected_at_render_time() {
		$event_id = $this->make_event();
		wp_update_post( [ 'ID' => $event_id, 'post_content' => 'Some course description.' ] );

		$inject = function ( $content ) use ( $event_id ) {
			return $content . '[event_registration id="' . $event_id . '"]';
		};
		add_filter( 'the_content', $inject, 9 );

		try {
			$html = $this->module()->render_single_event_body( $event_id );
		} finally {
			remove_filter( 'the_content', $inject, 9 );
		}

		$this->assertTrue( $this->module()->content_already_rendered_registration( $event_id ) );
		$this->assertSame(
			1,
			substr_count( $html, '<form class="anchor-event-registration"' ),
			'The registration form must render exactly once even when the shortcode was injected at render time.'
		);
	}

	/** Regression: a shortcode legitimately stored in post_content still renders once, and the flag reflects it. */
	public function test_render_single_event_body_avoids_duplicate_when_shortcode_is_stored_in_content() {
		$event_id = $this->make_event();
		wp_update_post( [
			'ID'           => $event_id,
			'post_content' => 'Intro text. [event_registration id="' . $event_id . '"]',
		] );

		$html = $this->module()->render_single_event_body( $event_id );

		$this->assertTrue( $this->module()->content_already_rendered_registration( $event_id ) );
		$this->assertSame( 1, substr_count( $html, '<form class="anchor-event-registration"' ) );
	}

	/**
	 * NEW-D6 (plugin half): the shortcode renders at most once per event per
	 * request. A theme rendering its own registration UI for an event AND
	 * the auto-appended shortcode both firing (or any other double-copy of
	 * the tag) must not double the picker/form — the second call renders ''.
	 */
	public function test_shortcode_event_registration_renders_once_per_event_per_request() {
		$event_id = $this->make_event();

		$first  = do_shortcode( '[event_registration id="' . $event_id . '"]' );
		$second = do_shortcode( '[event_registration id="' . $event_id . '"]' );

		$this->assertStringContainsString( '<form class="anchor-event-registration"', $first );
		$this->assertSame( '', $second, 'A second render of the same event this request must be suppressed.' );
	}

	/**
	 * finding-3 (bot review, PR #20): the early "already rendered this
	 * request" return in shortcode_event_registration() used to leave
	 * $registration_shortcode_rendered_for untouched. render_single_event_
	 * body() resets that marker to null immediately before running
	 * the_content — so when a widget/header block had already rendered the
	 * shortcode for this event earlier in the request, the SAME shortcode
	 * stored in post_content hit the early-return guard again during
	 * render_single_event_body(), left the marker null, and
	 * content_already_rendered_registration() then told the single-event
	 * template nothing had rendered — so it called render_registration_form()
	 * again, producing a second form. The marker must be set on the
	 * early-return path too.
	 */
	public function test_render_single_event_body_after_an_earlier_render_still_reports_one_render() {
		$event_id = $this->make_event();
		wp_update_post( [
			'ID'           => $event_id,
			'post_content' => 'Intro text. [event_registration id="' . $event_id . '"]',
		] );

		// A widget/header block rendering the shortcode BEFORE the
		// single-event template body runs — the first render, which wins.
		$widget_html = do_shortcode( '[event_registration id="' . $event_id . '"]' );
		$this->assertStringContainsString( '<form class="anchor-event-registration"', $widget_html );

		$html = $this->module()->render_single_event_body( $event_id );

		$this->assertTrue(
			$this->module()->content_already_rendered_registration( $event_id ),
			'The marker must say "already rendered" even though THIS call hit the render-once early return, not the real render.'
		);
		$this->assertSame(
			0,
			substr_count( $html, '<form class="anchor-event-registration"' ),
			'render_single_event_body() renders no second copy for an event the shortcode already rendered earlier in the request.'
		);
	}

	/** A DIFFERENT event's shortcode still renders normally — the guard is per event, not global. */
	public function test_shortcode_event_registration_render_once_guard_is_per_event() {
		$event_a = $this->make_event();
		$event_b = $this->make_event();

		do_shortcode( '[event_registration id="' . $event_a . '"]' );
		$other = do_shortcode( '[event_registration id="' . $event_b . '"]' );

		$this->assertStringContainsString( '<form class="anchor-event-registration"', $other );
	}

	/** With no [event_registration] shortcode anywhere, the flag stays false so the template still renders its own form. */
	public function test_render_single_event_body_flag_false_with_no_registration_shortcode() {
		$event_id = $this->make_event();
		wp_update_post( [ 'ID' => $event_id, 'post_content' => 'No shortcode here.' ] );

		$html = $this->module()->render_single_event_body( $event_id );

		$this->assertFalse( $this->module()->content_already_rendered_registration( $event_id ) );
		$this->assertStringContainsString( 'No shortcode here.', $html );
	}

	/**
	 * RENDER-D34: [event_gallery slug="typo"] (an unresolvable event) and
	 * [event_gallery] on a resolved event with zero images used to render
	 * the identical '' — an author with a typo'd slug had no way to tell it
	 * apart from a correctly-targeted, genuinely-empty gallery. The unresolved
	 * case must now say so; the genuinely-empty case still renders ''.
	 */
	public function test_gallery_shortcode_reports_unresolvable_event() {
		$html = $this->module()->shortcode_event_gallery( [ 'slug' => 'no-such-event-slug' ] );

		$this->assertNotSame( '', $html, 'An unresolvable [event_gallery] target must not render identically to a genuinely-empty gallery.' );
		$this->assertStringContainsString( 'No event specified for gallery', $html );
	}

	/** Regression: a resolved event with zero gallery images still renders '' (the genuinely-empty case). */
	public function test_gallery_shortcode_still_renders_empty_string_for_a_resolved_event_with_no_images() {
		$event_id = $this->make_event();

		$html = $this->module()->shortcode_event_gallery( [ 'id' => $event_id ] );

		$this->assertSame( '', $html );
	}

	/**
	 * RENDER-D23: [event_registrants_list] declared an `orderby` attribute
	 * that was never read — the query was hardcoded to start-date order, so
	 * `orderby="title"` silently sorted by start date instead.
	 */
	public function test_registrants_list_shortcode_honours_orderby_title() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		// Start dates deliberately REVERSED vs. title order, so a test that
		// still sorts by start_ts (the pre-fix bug) fails instead of passing
		// by coincidence.
		$zebra = $this->make_event( [ 'start_date' => '2026-01-01' ] );
		wp_update_post( [ 'ID' => $zebra, 'post_title' => 'Zebra Course' ] );
		$apple = $this->make_event( [ 'start_date' => '2027-01-01' ] );
		wp_update_post( [ 'ID' => $apple, 'post_title' => 'Apple Course' ] );

		$html = $this->module()->shortcode_event_registrants_list( [ 'orderby' => 'title', 'order' => 'ASC' ] );

		$apple_pos = strpos( $html, 'Apple Course' );
		$zebra_pos = strpos( $html, 'Zebra Course' );
		$this->assertNotFalse( $apple_pos );
		$this->assertNotFalse( $zebra_pos );
		$this->assertLessThan( $zebra_pos, $apple_pos, 'orderby="title" must sort by title, not by start date.' );
	}

	/**
	 * RENDER-D24: [events_list orderby="..."] only special-cased 'title' and
	 * 'priority' — every other value, including WP_Query's own vocabulary
	 * ('modified', 'rand', ...), silently fell through to start-date order
	 * with no notice. Unrecognised values must now pass straight through to
	 * WP_Query instead of being swallowed.
	 */
	public function test_events_list_shortcode_passes_unrecognised_orderby_through_to_wp_query() {
		$older = $this->make_event( [ 'start_date' => '2026-01-01' ] );
		wp_update_post( [ 'ID' => $older, 'post_modified' => '2020-01-01 00:00:00', 'post_modified_gmt' => '2020-01-01 00:00:00' ] );
		$newer = $this->make_event( [ 'start_date' => '2027-01-01' ] );
		wp_update_post( [ 'ID' => $newer, 'post_modified' => '2030-01-01 00:00:00', 'post_modified_gmt' => '2030-01-01 00:00:00' ] );

		$captured = null;
		$capture = function ( $args ) use ( &$captured ) {
			$captured = $args;
			return $args;
		};
		add_filter( 'anchor_events_query_args', $capture, 20 );
		$this->module()->shortcode_events_list( [ 'orderby' => 'modified', 'show_past' => 'yes' ] );
		remove_filter( 'anchor_events_query_args', $capture, 20 );

		$this->assertIsArray( $captured );
		$this->assertSame( 'modified', $captured['orderby'], 'An unrecognised orderby value must reach WP_Query verbatim, not fall back to start_ts.' );
		$this->assertArrayNotHasKey( 'meta_key', $captured, 'A non-meta orderby must not carry a stale meta_key along with it.' );
	}

	/** Regression: the documented default ('date'/unset) still orders by start_ts, and 'title'/'priority' still map as before. */
	public function test_events_list_shortcode_still_maps_date_title_priority_as_before() {
		$captured = [];
		$capture = function ( $args ) use ( &$captured ) {
			$captured[] = $args;
			return $args;
		};
		add_filter( 'anchor_events_query_args', $capture, 20 );
		$this->module()->shortcode_events_list( [ 'orderby' => 'date' ] );
		$this->module()->shortcode_events_list( [ 'orderby' => 'title' ] );
		$this->module()->shortcode_featured_events( [] ); // default orderby=priority
		remove_filter( 'anchor_events_query_args', $capture, 20 );

		$this->assertSame( 'meta_value_num', $captured[0]['orderby'] );
		$this->assertSame( $this->module()->meta_key( 'start_ts' ), $captured[0]['meta_key'] );

		$this->assertSame( 'title', $captured[1]['orderby'] );
		$this->assertArrayNotHasKey( 'meta_key', $captured[1] );

		$this->assertSame( 'meta_value_num', $captured[2]['orderby'] );
		$this->assertSame( $this->module()->meta_key( 'priority' ), $captured[2]['meta_key'] );
	}

	/**
	 * MODEL-D30 — [events_list type=] was ambiguous: it read as "the
	 * _anchor_event_type meta enum" (single|offering|recurring) but silently
	 * ran a tax_query against the UNRELATED event_type taxonomy instead.
	 * `event_type` is the new, explicit attribute name for that tax_query.
	 */
	public function test_events_list_shortcode_event_type_attribute_filters_the_taxonomy() {
		$captured = null;
		$capture = function ( $args ) use ( &$captured ) {
			$captured = $args;
			return $args;
		};
		add_filter( 'anchor_events_query_args', $capture, 20 );
		$this->module()->shortcode_events_list( [ 'event_type' => 'course,elite' ] );
		remove_filter( 'anchor_events_query_args', $capture, 20 );

		$this->assertIsArray( $captured );
		$this->assertNotEmpty( $captured['tax_query'] );
		$clause = null;
		foreach ( $captured['tax_query'] as $c ) {
			if ( ( $c['taxonomy'] ?? '' ) === 'event_type' ) {
				$clause = $c;
			}
		}
		$this->assertNotNull( $clause, 'event_type= must produce an event_type tax_query clause.' );
		$this->assertSame( [ 'course', 'elite' ], $clause['terms'] );
	}

	/**
	 * `type=` is kept as a deprecated alias for `event_type=` so an existing
	 * [events_list type="..."] shortcode in a page/widget keeps working.
	 */
	public function test_events_list_shortcode_type_attribute_is_a_deprecated_alias_for_event_type() {
		$this->setExpectedIncorrectUsage( 'Anchor\Events\Module::shortcode_events_list' );

		$captured = null;
		$capture = function ( $args ) use ( &$captured ) {
			$captured = $args;
			return $args;
		};
		add_filter( 'anchor_events_query_args', $capture, 20 );
		$this->module()->shortcode_events_list( [ 'type' => 'course' ] );
		remove_filter( 'anchor_events_query_args', $capture, 20 );

		$clause = null;
		foreach ( $captured['tax_query'] as $c ) {
			if ( ( $c['taxonomy'] ?? '' ) === 'event_type' ) {
				$clause = $c;
			}
		}
		$this->assertNotNull( $clause, 'The deprecated type= alias must still filter the taxonomy.' );
		$this->assertSame( [ 'course' ], $clause['terms'] );
	}

	/** event_type= wins when both attributes are given on the same shortcode. */
	public function test_events_list_shortcode_event_type_wins_over_deprecated_type_alias() {
		$this->setExpectedIncorrectUsage( 'Anchor\Events\Module::shortcode_events_list' );

		$captured = null;
		$capture = function ( $args ) use ( &$captured ) {
			$captured = $args;
			return $args;
		};
		add_filter( 'anchor_events_query_args', $capture, 20 );
		$this->module()->shortcode_events_list( [ 'type' => 'course', 'event_type' => 'elite' ] );
		remove_filter( 'anchor_events_query_args', $capture, 20 );

		$clause = null;
		foreach ( $captured['tax_query'] as $c ) {
			if ( ( $c['taxonomy'] ?? '' ) === 'event_type' ) {
				$clause = $c;
			}
		}
		$this->assertSame( [ 'elite' ], $clause['terms'] );
	}

	/**
	 * MODEL-D30 fix round 1 — using the deprecated `type=` attribute triggers
	 * _doing_it_wrong() (surfaced only under WP_DEBUG, same as every other
	 * core deprecation notice — setExpectedIncorrectUsage() above already
	 * covers this implicitly, but this test makes the trigger itself, not
	 * just its side effect, the explicit assertion). Using only the new
	 * `event_type=` name triggers nothing.
	 */
	public function test_events_list_shortcode_type_attribute_triggers_doing_it_wrong() {
		$this->setExpectedIncorrectUsage( 'Anchor\Events\Module::shortcode_events_list' );
		$this->module()->shortcode_events_list( [ 'type' => 'course' ] );
	}

	/** The explicit, non-deprecated attribute name triggers no notice at all. */
	public function test_events_list_shortcode_event_type_attribute_triggers_no_doing_it_wrong() {
		$this->module()->shortcode_events_list( [ 'event_type' => 'course' ] );
		// No setExpectedIncorrectUsage(): an unexpected _doing_it_wrong() call
		// fails this test at tearDown, so reaching this line IS the assertion.
		$this->assertTrue( true );
	}

	/**
	 * RENDER-D37: format_date_time() used to concatenate the raw `start_time`/
	 * `end_time` meta TEXT verbatim next to a localised date, so a stored
	 * "09:00" printed literally instead of running through the site's own
	 * time_format option. event_date_parts() is the new single source of
	 * these fields; format_date_time() (exercised here via
	 * render_single_content(), one of its 7 call sites) must be a thin
	 * formatter over it.
	 */
	public function test_format_date_time_runs_times_through_time_format_option() {
		update_option( 'time_format', 'g:i A' );
		$event_id = $this->make_event( [
			'start_date' => '2026-09-01',
			'start_time' => '09:00',
			'end_date'   => '2026-09-02',
			'end_time'   => '17:00',
		] );

		$html = $this->module()->render_single_content( $event_id );

		$this->assertStringContainsString( '9:00 AM', $html, 'start_time must be localised via time_format, not printed as raw "09:00".' );
		$this->assertStringContainsString( '5:00 PM', $html, 'end_time must be localised via time_format, not printed as raw "17:00".' );
		$this->assertStringContainsString( 'Sep 2, 2026', $html );
		$this->assertStringNotContainsString( '09:00', $html );
		$this->assertStringNotContainsString( '17:00', $html );
	}

	/** event_date_parts() is the single structured source format_date_time() and future callers build on. */
	public function test_event_date_parts_returns_structured_array() {
		update_option( 'time_format', 'g:i A' );
		$meta = $this->module()->get_meta( $this->make_event( [
			'start_date' => '2026-09-01',
			'start_time' => '14:00',
			'end_date'   => '2026-09-01',
			'end_time'   => '16:30',
		] ) );

		$parts = $this->module()->event_date_parts( $meta );

		$this->assertSame( 'Sep 1, 2026', $parts['start_date'] );
		$this->assertSame( '2:00 PM', $parts['start_time'] );
		$this->assertSame( '', $parts['end_date'], 'Same-day end date must not repeat as a second date part.' );
		$this->assertSame( '4:30 PM', $parts['end_time'] );
		$this->assertFalse( $parts['all_day'] );
	}

	/** An all-day event's parts carry no time-of-day text at all. */
	public function test_event_date_parts_all_day_event_has_no_times() {
		$meta = $this->module()->get_meta( $this->make_event( [
			'start_date' => '2026-09-01',
			'start_time' => '09:00',
			'all_day'    => true,
		] ) );

		$parts = $this->module()->event_date_parts( $meta );

		$this->assertSame( '', $parts['start_time'] );
		$this->assertTrue( $parts['all_day'] );
	}

	/** Regression: an event with no start_date at all still formats to '' (unchanged behaviour). */
	public function test_format_date_time_still_empty_with_no_start_date() {
		$event_id = $this->make_event( [ 'start_date' => '' ] );

		$html = $this->module()->render_single_content( $event_id );

		$this->assertStringContainsString( '<strong>Date:</strong>', $html );
	}

	/**
	 * RENDER-D39: the card ("badge" variant, render_event_card()) and the
	 * single page ("detail" variant, render_single_content()) rendered the
	 * same label rows with two different shapes — the card put the caption
	 * ONLY in a data-attribute, the detail view put it ONLY as visible text.
	 * A theme selector on .anchor-event-label-<key> inherited a different
	 * internal structure depending on which view it was in. Both must now
	 * carry the SAME machine-readable data-caption attribute.
	 */
	public function test_label_row_caption_is_machine_readable_in_both_badge_and_detail_variants() {
		$event_id = $this->make_event( [
			'labels' => [ [ 'key' => 'duration', 'value' => '2 Day Course' ] ],
		] );

		$badge_html  = $this->module()->render_event_card( $event_id, 'shortcode' );
		$detail_html = $this->module()->render_single_content( $event_id );

		$this->assertStringContainsString( 'data-caption="Duration"', $badge_html, 'The badge variant must expose the caption as a data attribute.' );
		$this->assertStringContainsString( 'data-caption="Duration"', $detail_html, 'The detail variant must ALSO expose the caption as a data attribute, not text-only.' );

		// Both variants keep the per-key class a theme selector targets.
		$this->assertStringContainsString( 'anchor-event-label-duration', $badge_html );
		$this->assertStringContainsString( 'anchor-event-label-duration', $detail_html );

		// The detail variant keeps its visible "Caption: value" text (unlike the badge).
		$this->assertStringContainsString( 'Duration', $detail_html );
		$this->assertStringContainsString( '2 Day Course', $detail_html );
	}

	/**
	 * Extract one named function's body (opening `{` through its matching
	 * closing `}`) from a file's raw source, by brace depth — good enough for
	 * this codebase's style (no braces inside string literals in these
	 * emitters' own bodies). Used to scan ONLY the front-end-rendering
	 * functions inside a file that also carries admin/authoring UI, so that
	 * UI's own classes (styled by a different stylesheet entirely) never
	 * show up as false "missing from frontend.css" positives.
	 *
	 * @param string $src
	 * @param string $function_name
	 * @return string
	 */
	private function extract_function_body( $src, $function_name ) {
		if ( ! preg_match( '/function\s+' . preg_quote( $function_name, '/' ) . '\s*\(/', $src, $m, PREG_OFFSET_CAPTURE ) ) {
			$this->fail( "Could not find function {$function_name}() — has it been renamed?" );
		}
		$open = strpos( $src, '{', $m[0][1] );
		$depth = 0;
		$i     = $open;
		$len   = strlen( $src );
		for ( ; $i < $len; $i++ ) {
			if ( $src[ $i ] === '{' ) {
				$depth++;
			} elseif ( $src[ $i ] === '}' ) {
				$depth--;
				if ( $depth === 0 ) {
					break;
				}
			}
		}
		return substr( $src, $open, $i - $open + 1 );
	}

	/**
	 * finding-18 (carry-over, Task 36 review) — the previous version of this
	 * test was a hand-typed inventory, which is exactly how three real
	 * classes went unstyled for a whole task cycle without failing anything:
	 * the WooCommerce classic-checkout attendee fields
	 * (.anchor-event-attendees and its .anchor-event-attendee-* children,
	 * render_checkout_attendee_fields()) and three "Choose a date"/"Other
	 * dates" classes (.anchor-event-choose-date-date/-label,
	 * .anchor-event-other-dates-list) were emitted by real renderers and
	 * simply never added to the list.
	 *
	 * This derives the inventory straight from the emitters instead: every
	 * `anchor-event(s)?-*` string literal in the actual front-end-only
	 * source (whole templates/JS files that carry no admin content, plus
	 * SPECIFIC functions — extract_function_body() above — inside files that
	 * also carry wp-admin/authoring UI styled by a different sheet
	 * entirely). A future renderer change that emits a class with no rule
	 * fails this test on its own; nobody has to remember to update a list.
	 *
	 * A short, individually-commented exclusion list covers real classes
	 * that legitimately need no rule of their own (a BEM-style modifier that
	 * always rides along with an already-styled base class, or a class
	 * styled via an ancestor+tag selector rather than its own name) —
	 * curated, not silently dropped.
	 */
	public function test_frontend_css_covers_every_class_the_front_end_renderers_emit() {
		$css = file_get_contents( ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-events-manager/assets/frontend.css' );
		$this->assertNotFalse( $css, 'Could not read anchor-events-manager/assets/frontend.css.' );
		// Strip comments first — a class name mentioned only in a /* ... */
		// note (as several baseline blocks do, documenting why a rule
		// exists) must not satisfy this test in place of a rule.
		$css = (string) preg_replace( '#/\*.*?\*/#s', '', $css );

		$dir = ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-events-manager/';

		// Whole-file sources: front-end rendering only, nothing admin/authoring.
		$sources = '';
		foreach ( [
			'templates/single-event.php',
			'templates/archive-event.php',
			'templates/calendar.php',
			'class-series.php',
			'assets/frontend.js',
			'assets/event-storefront.js',
		] as $file ) {
			$contents = file_get_contents( $dir . $file );
			$this->assertNotFalse( $contents, "Could not read {$file}." );
			$sources .= "\n" . $contents;
		}

		// Function-scoped sources: these two files ALSO carry admin-only UI
		// (the wp-admin metaboxes, the ticket-tiers authoring repeater) whose
		// classes belong to a different stylesheet — scanning the whole file
		// would report a pile of admin classes as "missing" from this one.
		foreach ( [
			'class-woocommerce.php'     => [ 'filter_registration_form', 'render_ticket_row', 'render_checkout_attendee_fields' ],
			'anchor-events-manager.php' => [ 'render_choose_date_list', 'render_sibling_dates', 'render_choose_date_row', 'render_label_row' ],
		] as $file => $functions ) {
			$contents = file_get_contents( $dir . $file );
			$this->assertNotFalse( $contents, "Could not read {$file}." );
			foreach ( $functions as $function_name ) {
				$sources .= "\n" . $this->extract_function_body( $contents, $function_name );
			}
		}

		\preg_match_all( '/anchor-events?-[a-z][a-z0-9_-]*/', $sources, $matches );
		$classes = \array_unique( $matches[0] );
		// A `.foo-' . $state . '"'`-style dynamic suffix (e.g.
		// render_choose_date_row()'s `anchor-event-choose-date-row--`) is a
		// regex artifact of the concatenation, not a real static class name —
		// no such literal ever exists in rendered markup on its own.
		$classes = \array_filter( $classes, function ( $c ) { return \substr( $c, -1 ) !== '-'; } );

		$excluded = [
			// Always compound with .anchor-event-button, which already
			// carries the visual rule — a semantic/JS hook, not a class that
			// needs its own look.
			'anchor-event-view-cart'  => 'compound modifier of .anchor-event-button',
			'anchor-event-checkout'   => 'compound modifier of .anchor-event-button',
			'anchor-event-register'   => 'compound modifier of .anchor-event-button (also the brand-color inline-<style> hook, anchor-events-manager.php ~6839)',
			// Styled via its ancestor + tag, not its own class — see
			// `.anchor-event-calendar-list a` above.
			'anchor-event-calendar-link' => 'styled via .anchor-event-calendar-list a, not its own selector',
			// A bare structural wrapper — every visual property lives on its
			// __-prefixed children (.anchor-event-series__*) above.
			'anchor-event-series' => 'bare wrapper; children carry all layout',
		];
		$classes = \array_diff( $classes, \array_keys( $excluded ) );

		$missing = [];
		foreach ( $classes as $class ) {
			// A selector boundary, not just substring containment — otherwise
			// `.anchor-event-tickets` would be satisfied by a rule for
			// `.anchor-event-tickets-actions` alone, which was exactly the
			// gap this test exists to catch.
			$pattern = '/\.' . preg_quote( $class, '/' ) . '(?![A-Za-z0-9_-])/';
			if ( ! preg_match( $pattern, $css ) ) {
				$missing[] = $class;
			}
		}
		\sort( $missing );

		$this->assertSame(
			[],
			$missing,
			"frontend.css has no rule for: " . implode( ', ', $missing )
		);
	}
}
