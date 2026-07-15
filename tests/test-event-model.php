<?php
/**
 * Event-type / registration-mode data model tests (no WooCommerce required).
 *
 * Covers Task 1.1+1.2: the `type`, `sessions`, `registration_mode`,
 * `external_url`, `external_embed`, `external_display_price` meta keys, the
 * `event_type()` / `registration_mode()` / `get_sessions()` resolvers, and the
 * one-time back-compat migration that derives `registration_mode` for
 * pre-existing events.
 *
 * @package Anchor\Events\Tests
 */

/**
 * @group event-model
 */
class Test_Event_Model extends Anchor_Events_TestCase {

	/** event_type() falls back to 'single' when no type meta is stored. */
	public function test_event_type_defaults_to_single() {
		$event_id = $this->make_event();

		$this->assertSame( 'single', $this->module()->event_type( $event_id ) );
	}

	/** event_type() returns the stored value when it's a valid enum member. */
	public function test_event_type_returns_stored_valid_value() {
		$event_id = $this->make_event( [ 'type' => 'multisession' ] );

		$this->assertSame( 'multisession', $this->module()->event_type( $event_id ) );
	}

	/** event_type() falls back to 'single' for a garbage stored value. */
	public function test_event_type_falls_back_on_garbage_value() {
		$event_id = $this->make_event( [ 'type' => 'not-a-real-type' ] );

		$this->assertSame( 'single', $this->module()->event_type( $event_id ) );
	}

	/** registration_mode(): an explicit stored mode wins over derivation. */
	public function test_registration_mode_explicit_stored_value_wins() {
		// Would derive to 'wc' via a paid active tier, but the stored mode wins.
		$event_id = $this->make_event(
			[ 'registration_mode' => 'external' ],
			[ [ 'label' => 'General', 'price' => '25', 'active' => 1 ] ]
		);

		$this->assertSame( 'external', $this->module()->registration_mode( $event_id ) );
	}

	/** registration_mode(): legacy registration_type=external derives 'external'. */
	public function test_registration_mode_derives_external_from_legacy_type() {
		$event_id = $this->make_event( [ 'registration_type' => 'external' ] );

		$this->assertSame( 'external', $this->module()->registration_mode( $event_id ) );
	}

	/** registration_mode(): a non-empty legacy registration_url also derives 'external'. */
	public function test_registration_mode_derives_external_from_legacy_url() {
		$event_id = $this->make_event( [ 'registration_url' => 'https://example.test/register' ] );

		$this->assertSame( 'external', $this->module()->registration_mode( $event_id ) );
	}

	/** registration_mode(): an active paid tier derives 'wc'. */
	public function test_registration_mode_derives_wc_from_paid_active_tier() {
		$event_id = $this->make_event(
			[],
			[ [ 'label' => 'General', 'price' => '25', 'active' => 1 ] ]
		);

		$this->assertSame( 'wc', $this->module()->registration_mode( $event_id ) );
	}

	/** registration_mode(): a managed product also derives 'wc'. */
	public function test_registration_mode_derives_wc_from_managed_product() {
		$event_id = $this->make_event( [ 'managed_product' => 123 ] );

		$this->assertSame( 'wc', $this->module()->registration_mode( $event_id ) );
	}

	/** registration_mode(): a plain event (no legacy signal, no paid tier) derives 'free'. */
	public function test_registration_mode_derives_free_for_plain_event() {
		$event_id = $this->make_event();

		$this->assertSame( 'free', $this->module()->registration_mode( $event_id ) );
	}

	/** get_sessions() returns [] when no sessions meta is stored. */
	public function test_get_sessions_empty_when_unset() {
		$event_id = $this->make_event();

		$this->assertSame( [], $this->module()->get_sessions( $event_id ) );
	}

	/** get_sessions() normalizes stored rows and drops rows with an empty date. */
	public function test_get_sessions_normalizes_and_drops_empty_dates() {
		$event_id = $this->make_event(
			[
				'sessions' => [
					[ 'date' => '2026-08-01', 'start_time' => '09:00', 'end_time' => '10:00', 'label' => 'Day 1' ],
					[ 'date' => '', 'start_time' => '11:00', 'end_time' => '12:00', 'label' => 'Bad row' ],
				],
			]
		);

		$sessions = $this->module()->get_sessions( $event_id );

		$this->assertCount( 1, $sessions );
		$this->assertSame(
			[
				'date'       => '2026-08-01',
				'start_time' => '09:00',
				'end_time'   => '10:00',
				'label'      => 'Day 1',
			],
			$sessions[0]
		);
	}

	/** The one-time migration derives registration_mode for legacy events and is idempotent. */
	public function test_migration_derives_registration_mode_for_legacy_events_and_is_idempotent() {
		delete_option( 'anchor_events_regmode_migrated' );

		$external_id = $this->make_event( [ 'registration_type' => 'external' ] );
		$wc_id       = $this->make_event(
			[],
			[ [ 'label' => 'General', 'price' => '25', 'active' => 1 ] ]
		);

		// Neither event has an explicit registration_mode yet.
		$this->assertSame( '', get_post_meta( $external_id, '_anchor_event_registration_mode', true ) );
		$this->assertSame( '', get_post_meta( $wc_id, '_anchor_event_registration_mode', true ) );

		$this->module()->migrate_registration_mode();

		$this->assertSame( 'external', get_post_meta( $external_id, '_anchor_event_registration_mode', true ) );
		$this->assertSame( 'wc', get_post_meta( $wc_id, '_anchor_event_registration_mode', true ) );
		$this->assertTrue( (bool) get_option( 'anchor_events_regmode_migrated' ) );

		// Idempotency: hand-edit a migrated value, re-run, and confirm it's left untouched.
		update_post_meta( $external_id, '_anchor_event_registration_mode', 'free' );
		$this->module()->migrate_registration_mode();

		$this->assertSame( 'free', get_post_meta( $external_id, '_anchor_event_registration_mode', true ) );
	}

	/**
	 * `external_embed` is REST-writable, so its sanitize_callback must run on
	 * the REST write path — exercised here via sanitize_meta(), which is what
	 * WordPress's REST meta-fields controller calls before persisting. An
	 * allowed <iframe> survives; `script` is absent from the default
	 * allowlist, so wp_kses() strips the tag itself outright — both an
	 * inline `<script>alert(1)</script>` payload and a `<script src="...">`
	 * loader tag lose their opening/closing tags, along with a disallowed
	 * onclick attribute. Note wp_kses() only removes the tag markup, not
	 * inert text nodes it exposes — the inline payload's text content
	 * ("alert(1)") is left behind as harmless, non-executing text once its
	 * <script> wrapper is gone; the loader tag leaves nothing behind since
	 * it has no text body.
	 */
	public function test_external_embed_sanitizer_strips_disallowed_markup_via_rest_write_path() {
		$event_id = $this->make_event();
		$meta_key = $this->module()->meta_key( 'external_embed' );

		$dirty = '<iframe src="https://example.com" width="600" height="400" allowfullscreen></iframe>'
			. '<script>alert(1)</script>'
			. '<script src="https://evil.example/x.js"></script>'
			. '<div onclick="alert(2)">click me</div>';

		// sanitize_meta() is the function WP's REST meta-fields controller calls
		// before writing REST-supplied meta — this exercises the exact exposed
		// surface the HIGH finding is about, without needing a full REST request.
		$sanitized = sanitize_meta( $meta_key, $dirty, 'post', \Anchor\Events\Module::CPT );
		update_post_meta( $event_id, $meta_key, $sanitized );

		$stored = get_post_meta( $event_id, $meta_key, true );

		$this->assertStringContainsString( '<iframe', $stored, 'Allowed <iframe> must survive sanitization.' );
		$this->assertStringContainsString( 'src="https://example.com"', $stored );
		$this->assertStringNotContainsString( '<script', $stored, 'Disallowed <script> tags (inline and src loader alike) must be stripped by default.' );
		$this->assertStringNotContainsString( 'evil.example', $stored, 'A <script src> loader tag must also be stripped by default now that script is off the allowlist.' );
		$this->assertStringNotContainsString( 'onclick', $stored, 'Disallowed onclick attribute must be stripped.' );
		// The div itself is allowed, but its onclick attribute is not — the
		// stripped opening tag should remain as plain <div>.
		$this->assertStringContainsString( '<div>click me</div>', $stored );
	}

	/** The `anchor_events_embed_allowed_html` filter can extend the allowlist (e.g. a custom tag). */
	public function test_external_embed_sanitizer_honors_allowed_html_filter() {
		$event_id = $this->make_event();
		$meta_key = $this->module()->meta_key( 'external_embed' );

		$allow_mark = function ( $allowed ) {
			$allowed['mark'] = [ 'class' => true ];
			return $allowed;
		};
		add_filter( 'anchor_events_embed_allowed_html', $allow_mark );

		$dirty     = '<mark class="highlight">Sale!</mark><script>alert(1)</script>';
		$sanitized = sanitize_meta( $meta_key, $dirty, 'post', \Anchor\Events\Module::CPT );

		remove_filter( 'anchor_events_embed_allowed_html', $allow_mark );

		$this->assertStringContainsString( '<mark class="highlight">Sale!</mark>', $sanitized, 'Tag added via the anchor_events_embed_allowed_html filter must survive.' );
		$this->assertStringNotContainsString( '<script', $sanitized );
	}
}
