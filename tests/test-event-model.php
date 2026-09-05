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

	/**
	 * WP_UnitTestCase_Base::tear_down() calls unregister_all_meta_keys() after
	 * every test, but register_meta() (the module's `init` callback) only
	 * fires once, during the WP test bootstrap — so from the second test in
	 * the whole run onward, get_registered_meta_keys() returns nothing unless
	 * something re-registers. Explicitly re-run register_meta() before each
	 * test in this class so meta-schema assertions (show_in_rest, etc.) are
	 * deterministic regardless of what ran before this class/test.
	 */
	public function set_up() {
		parent::set_up();
		$this->module()->register_meta();
	}

	/* ------------------------------------------------------------------
	 * Module settings: the shipped defaults, and what a save may reach for.
	 * ---------------------------------------------------------------- */

	/**
	 * MODEL-D29 — clearing a text setting resets it to the shipped default.
	 *
	 * sanitize_settings()'s fallback array was called `$defaults` but held the
	 * CURRENT merged settings, so `sanitize_text_field( '' ) ?: $defaults[...]`
	 * evaluated to the value already stored: the field reappeared unchanged
	 * and the admin saw a save that did nothing.
	 */
	public function test_clearing_a_text_setting_restores_the_shipped_default() {
		$shipped = $this->module()->default_settings();

		update_option( Anchor\Events\Module::OPTION_KEY, array_merge(
			$this->module()->get_settings(),
			[ 'reminder_subject' => 'Custom reminder subject', 'email_heading_color' => '#abcdef' ]
		), false );
		$this->assertSame( 'Custom reminder subject', $this->module()->get_settings()['reminder_subject'] );

		$saved = $this->module()->sanitize_settings( [
			'reminder_subject'    => '',
			'email_heading_color' => '',
		] );

		$this->assertSame( $shipped['reminder_subject'], $saved['reminder_subject'] );
		$this->assertSame( $shipped['email_heading_color'], $saved['email_heading_color'] );

		delete_option( Anchor\Events\Module::OPTION_KEY );
	}

	/** get_settings() is still the shipped defaults merged with the stored option. */
	public function test_get_settings_is_the_defaults_merged_with_the_option() {
		$this->assertSame( $this->module()->default_settings(), $this->module()->get_settings() );

		update_option( Anchor\Events\Module::OPTION_KEY, [ 'reminder_subject' => 'Mine' ], false );
		$merged = $this->module()->get_settings();

		$this->assertSame( 'Mine', $merged['reminder_subject'] );
		$this->assertSame( $this->module()->default_settings()['roster_subject'], $merged['roster_subject'] );

		delete_option( Anchor\Events\Module::OPTION_KEY );
	}

	/**
	 * REG-D58 fix round 1 — the Confirmation subject field is on the page.
	 *
	 * It was registered under the section `anchor_events_emails`, which does
	 * not exist (the page's sections are main, email_sender, email_appearance,
	 * registration, wc_emails, lifecycle_emails and slugs), so it never
	 * rendered — and a settings save therefore posted no value for it, which
	 * sanitize_settings() answered by resetting it to the shipped default
	 * every single time.
	 */
	public function test_every_settings_field_this_batch_added_actually_renders() {
		global $wp_settings_sections, $wp_settings_fields;
		$wp_settings_sections = [];
		$wp_settings_fields   = [];

		$this->module()->register_settings();

		ob_start();
		do_settings_sections( 'anchor_events_settings' );
		$html = (string) ob_get_clean();

		foreach ( [ 'confirmation_subject', 'refund_subject', 'refund_intro' ] as $field ) {
			$this->assertStringContainsString(
				'[' . $field . ']',
				$html,
				$field . ' is registered under a section the page never renders.'
			);
		}

		// No field on this page may name a section that was never added.
		$sections = array_keys( $wp_settings_sections['anchor_events_settings'] ?? [] );
		foreach ( array_keys( $wp_settings_fields['anchor_events_settings'] ?? [] ) as $section ) {
			$this->assertContains( $section, $sections, 'Fields are registered under the unknown section ' . $section . '.' );
		}
	}

	/**
	 * REG-D64 / MODEL-D28 — `notify_attendee` is gone. It was declared in the
	 * defaults as "Reserved/unused in MVP", carried through every save
	 * verbatim, and read by nothing, so a reader would reasonably conclude
	 * attendee notification was switched off site-wide when it was not.
	 */
	public function test_the_reserved_notify_attendee_setting_is_gone() {
		$this->assertArrayNotHasKey( 'notify_attendee', $this->module()->default_settings() );
		$this->assertArrayNotHasKey( 'notify_attendee', $this->module()->sanitize_settings( [] ) );
	}

	/**
	 * finding-4 — `in_array( $input['x'] ?? 'default', [...], true ) ?
	 * $input['x'] : 'default'` guards the CHECK with the coalesced value but
	 * re-reads the raw `$input['x']` in the true branch: on a genuinely
	 * missing key the check passes on its own fallback, then the true branch
	 * warns on the same missing key. Both `timezone_mode` and
	 * `template_source` must resolve to their default with no PHP warning
	 * when the key is absent from $input entirely (not merely empty).
	 */
	public function test_sanitize_settings_with_a_missing_key_warns_never_and_defaults_correctly() {
		$warnings = [];
		set_error_handler( static function ( $errno, $errstr ) use ( &$warnings ) {
			$warnings[] = $errstr;
			return true;
		}, E_WARNING | E_NOTICE | E_DEPRECATED );

		try {
			$saved = $this->module()->sanitize_settings( [] );
		} finally {
			restore_error_handler();
		}

		$this->assertSame( [], $warnings, 'A missing settings key must never raise a PHP warning/notice.' );
		$this->assertSame( 'site', $saved['timezone_mode'] );
		$this->assertSame( 'theme', $saved['template_source'] );
	}

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

	/**
	 * registration_mode(): a managed product also derives 'wc'.
	 *
	 * The fixture used to be the bare integer 123, which pinned the bug
	 * MODEL-D23 describes: the pointer was tested with empty() only, so ANY
	 * non-zero value derived 'wc' — including an id whose product had been
	 * deleted, leaving a legacy event routed to a WooCommerce branch with
	 * nothing to sell. The pointer must now name a real product; the
	 * deleted-target half is pinned by
	 * Test_Foreign_Keys::test_registration_mode_falls_back_to_free_when_managed_product_deleted.
	 */
	public function test_registration_mode_derives_wc_from_managed_product() {
		$this->require_wc();

		$product_id = self::factory()->post->create(
			[ 'post_type' => 'product', 'post_status' => 'publish' ]
		);
		$event_id = $this->make_event( [ 'managed_product' => $product_id ] );

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

	/**
	 * The one-time back-fill derives registration_mode for legacy events and
	 * is idempotent. backfill_registration_mode() is capability-gated
	 * (MODEL-D41: it now runs on admin_init, which fires unauthenticated on
	 * admin-post.php), so this needs a user who could edit events by hand.
	 */
	public function test_migration_derives_registration_mode_for_legacy_events_and_is_idempotent() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		delete_option( 'anchor_events_regmode_version' );
		delete_option( 'anchor_events_regmode_migrated' );

		$external_id = $this->make_event( [ 'registration_type' => 'external' ] );
		$wc_id       = $this->make_event(
			[],
			[ [ 'label' => 'General', 'price' => '25', 'active' => 1 ] ]
		);

		// Neither event has an explicit registration_mode yet.
		$this->assertSame( '', get_post_meta( $external_id, '_anchor_event_registration_mode', true ) );
		$this->assertSame( '', get_post_meta( $wc_id, '_anchor_event_registration_mode', true ) );

		$this->module()->backfill_registration_mode();

		$this->assertSame( 'external', get_post_meta( $external_id, '_anchor_event_registration_mode', true ) );
		$this->assertSame( 'wc', get_post_meta( $wc_id, '_anchor_event_registration_mode', true ) );
		$this->assertTrue( (int) get_option( 'anchor_events_regmode_version' ) >= 1 );

		// Idempotency: hand-edit a migrated value, re-run, and confirm it's left untouched.
		update_post_meta( $external_id, '_anchor_event_registration_mode', 'free' );
		$this->module()->backfill_registration_mode();

		$this->assertSame( 'free', get_post_meta( $external_id, '_anchor_event_registration_mode', true ) );
	}

	/**
	 * `external_embed` is show_in_rest=false (Task 1.3: classic-metabox-only,
	 * to avoid a Gutenberg/classic-metabox save race), but sanitize_meta()
	 * still runs the registered sanitize_callback on EVERY write regardless
	 * of REST exposure — including the direct update_post_meta() call in
	 * save_meta() — so this is exercised here via sanitize_meta() directly.
	 * An allowed <iframe> survives; `script` is absent from the default
	 * allowlist, so wp_kses() strips the tag itself outright — both an
	 * inline `<script>alert(1)</script>` payload and a `<script src="...">`
	 * loader tag lose their opening/closing tags, along with a disallowed
	 * onclick attribute. Note wp_kses() only removes the tag markup, not
	 * inert text nodes it exposes — the inline payload's text content
	 * ("alert(1)") is left behind as harmless, non-executing text once its
	 * <script> wrapper is gone; the loader tag leaves nothing behind since
	 * it has no text body.
	 */
	public function test_external_embed_sanitizer_strips_disallowed_markup_on_every_write() {
		$event_id = $this->make_event();
		$meta_key = $this->module()->meta_key( 'external_embed' );

		$dirty = '<iframe src="https://example.com" width="600" height="400" allowfullscreen></iframe>'
			. '<script>alert(1)</script>'
			. '<script src="https://evil.example/x.js"></script>'
			. '<div onclick="alert(2)">click me</div>';

		// sanitize_meta() applies the registered sanitize_callback unconditionally
		// — this is the same function update_metadata()/update_post_meta() call
		// internally, so it exercises the exact codepath every write goes through,
		// classic-metabox or otherwise.
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

	/**
	 * Regression guard (Task 1.3 fix): these six meta keys are edited ONLY via
	 * the classic metabox and must stay off REST/Gutenberg's meta save path —
	 * exposing them created a last-write-wins race where a REST/block-editor
	 * autosave could silently revert a just-saved classic-metabox value (e.g.
	 * event type, registration mode) back to its default on Publish. Asserted
	 * directly against the registered meta schema so a future change that
	 * flips show_in_rest back to true fails loudly here instead of
	 * resurfacing as a silent data-loss bug.
	 */
	public function test_classic_only_meta_keys_are_not_rest_exposed() {
		$classic_only_keys = [
			'type',
			'sessions',
			'registration_mode',
			'external_url',
			'external_embed',
			'external_display_price',
		];

		$registered = get_registered_meta_keys( 'post', \Anchor\Events\Module::CPT );

		foreach ( $classic_only_keys as $key ) {
			$meta_key = $this->module()->meta_key( $key );
			$this->assertArrayHasKey( $meta_key, $registered, "Expected {$meta_key} to be registered." );
			$this->assertFalse(
				$registered[ $meta_key ]['show_in_rest'],
				"Expected {$meta_key} to have show_in_rest === false (classic-metabox-only, avoids Gutenberg save race)."
			);
		}
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
