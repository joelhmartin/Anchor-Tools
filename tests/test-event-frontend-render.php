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

// RENDER-D21: a minimal stand-in for Yoast's own frontend class, so the
// canonical-emission tests below can flip class_exists( 'WPSEO_Frontend' )
// to true without needing the real plugin installed. Nothing else in this
// suite checks for WPSEO_Frontend, so declaring it here is inert everywhere
// but the tests that specifically look for it.
if ( ! class_exists( 'WPSEO_Frontend' ) ) {
	class WPSEO_Frontend {}
}

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
	 * that already emits its own canonical tag is active — Yoast here, via
	 * class_exists( 'WPSEO_Frontend' ). Before the fix this echoed
	 * unconditionally, so a Yoast site got two <link rel="canonical"> tags on
	 * a `?anchor_events_month=` URL.
	 */
	public function test_output_canonical_url_is_silent_when_yoast_is_active() {
		$_GET['anchor_events_month'] = '2026-10';

		ob_start();
		$this->module()->output_canonical_url();
		$html = ob_get_clean();

		unset( $_GET['anchor_events_month'] );

		$this->assertSame( '', $html, 'output_canonical_url() must not print a tag when Yoast (WPSEO_Frontend) is active.' );
	}

	/** The `anchor_events_emit_canonical` filter can force our own tag back on even with an SEO plugin detected. */
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
}
