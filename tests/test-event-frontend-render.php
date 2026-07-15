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
}
