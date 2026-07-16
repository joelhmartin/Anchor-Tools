<?php
/**
 * Task BC — backward-compatibility hardening for pre-upgrade events.
 *
 * The plugin ships to many client sites carrying EXISTING events created
 * under the OLD (pre-rework) data model. Those posts have ONLY legacy meta:
 *
 *   _anchor_event_registration_type   internal|external
 *   _anchor_event_registration_url    the external link, for type=external
 *   _anchor_event_registration_enabled, _anchor_event_price, dates, capacity, ...
 *
 * ...and are missing every new-model key (_anchor_event_type,
 * _anchor_event_registration_mode, _anchor_event_external_url,
 * _anchor_event_external_embed, _anchor_event_external_display_price,
 * sessions/group/occurrence meta). Old events are all FREE — they never had
 * a WooCommerce product — so BC here is scoped to the free-internal and
 * external-link-out modes.
 *
 * Every fixture below is built with Anchor_Events_TestCase::make_event(),
 * passing ONLY legacy keys (plus make_event()'s own registration_enabled/
 * capacity/waitlist defaults, which are themselves legacy-model fields) and
 * NEVER calling migrate_registration_mode() unless a test is specifically
 * exercising the migration — so most of these tests hit the resolvers'
 * live-read (un-migrated) code path, exactly like a real pre-upgrade site's
 * very first page load after the plugin update.
 *
 * The central bug this file proves/fixes: an old EXTERNAL event's real link
 * lives in the legacy `registration_url` meta, but the new render/JSON-LD
 * code reads `external_url`. Before the Task BC fix, `external_url` was
 * always '' for such an event, so render_external_registration() rendered no
 * link at all (or, if `external_embed`/`external_display_price` were also
 * empty, an empty shell) and Event_Schema's offer fell back to the bare
 * permalink instead of the real registration link — a silent BC break that
 * would have gone unnoticed until a customer complained a "Register" button
 * had vanished. The fix is Module::external_url() (a live-read fallback to
 * the legacy key, wired into get_meta()) plus a matching mapping in
 * migrate_registration_mode() — see those methods' docblocks.
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Module;

/**
 * @group backward-compat
 */
class Test_Backward_Compat extends Anchor_Events_TestCase {

	/** @var int */
	private $admin_id;

	public function set_up() {
		parent::set_up();
		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin_id );
	}

	public function tear_down() {
		unset( $_POST );
		parent::tear_down();
	}

	/**
	 * Assert a fixture is genuinely un-migrated: it must carry NONE of the
	 * new-model keys the rework introduced. This is what makes these fixtures
	 * a faithful stand-in for a real pre-upgrade row rather than an
	 * accidentally-already-converted one.
	 */
	private function assert_is_genuine_pre_upgrade_fixture( $event_id ) {
		foreach ( [ 'type', 'registration_mode', 'external_url', 'external_embed', 'external_display_price', 'sessions', 'group_role', 'group_id' ] as $key ) {
			$stored = get_post_meta( $event_id, '_anchor_event_' . $key, true );
			$this->assertTrue(
				$stored === '' || $stored === [] || $stored === 0,
				"Fixture must not carry any new-model key; _anchor_event_{$key} = " . var_export( $stored, true )
			);
		}
	}

	/* ------------------------------------------------------------------
	 * Part 1, case 1 — old FREE internal event.
	 * ------------------------------------------------------------------ */

	public function test_old_free_internal_event_resolves_single_free_and_renders_normal_form() {
		$event_id = $this->make_event( [
			'registration_type' => 'internal',
			'registration_url' => '',
			'registration_enabled' => true,
			'capacity' => 0,
			'start_date' => '2026-09-01',
			'start_time' => '09:00',
			'end_date' => '2026-09-01',
			'end_time' => '10:00',
		] );
		$this->assert_is_genuine_pre_upgrade_fixture( $event_id );

		$this->assertSame( 'single', $this->module()->event_type( $event_id ) );
		$this->assertSame( 'free', $this->module()->registration_mode( $event_id ) );

		$html = $this->module()->render_registration_form( $event_id );

		// The SAME free/internal registration form as before: a real <form>
		// posting to admin-post.php with the name/email fields, not the
		// external block and not an empty shell.
		$this->assertStringContainsString( '<form class="anchor-event-registration"', $html );
		$this->assertStringContainsString( 'name="anchor_event_name"', $html );
		$this->assertStringContainsString( 'name="anchor_event_email"', $html );
		$this->assertStringNotContainsString( 'anchor-event-registration-external', $html );

		// JSON-LD (if emitted) shows a free (price 0) offer, and nothing crashes.
		$node = $this->module()->event_schema->for_event( $event_id );
		$this->assertArrayHasKey( 'offers', $node );
		$this->assertCount( 1, $node['offers'] );
		$this->assertSame( 0, $node['offers'][0]['price'] );
	}

	/* ------------------------------------------------------------------
	 * Part 1, case 2 — old EXTERNAL event. THE crux test.
	 * ------------------------------------------------------------------ */

	public function test_old_external_event_resolves_external_mode_and_link_still_renders() {
		$event_id = $this->make_event( [
			'registration_type' => 'external',
			'registration_url' => 'https://ext.example/reg',
			'registration_enabled' => true,
			'start_date' => '2026-09-01',
			'start_time' => '09:00',
			'end_date' => '2026-09-01',
			'end_time' => '10:00',
		] );
		$this->assert_is_genuine_pre_upgrade_fixture( $event_id );

		$this->assertSame( 'external', $this->module()->registration_mode( $event_id ) );

		// The BC fallback: external_url()/get_meta() must resolve the legacy
		// registration_url even though _anchor_event_external_url was never set.
		$this->assertSame( 'https://ext.example/reg', $this->module()->external_url( $event_id ) );
		$this->assertSame( 'https://ext.example/reg', $this->module()->get_meta( $event_id )['external_url'] );

		// CRITICAL: the front-end external block must render the real link,
		// not an empty shell. This is the exact BC break the brief warns
		// about — before the fix, this href would have been empty/absent.
		$html = $this->module()->render_registration_form( $event_id );
		$this->assertStringContainsString( 'anchor-event-registration-external', $html );
		$this->assertStringContainsString( 'href="' . esc_url( 'https://ext.example/reg' ) . '"', $html );
		$this->assertStringContainsString( '>' . esc_html__( 'Register', 'anchor-schema' ) . '<', $html );

		// The legacy meta itself must remain intact and readable (untouched).
		$this->assertSame( 'https://ext.example/reg', get_post_meta( $event_id, '_anchor_event_registration_url', true ) );
		$this->assertSame( 'external', get_post_meta( $event_id, '_anchor_event_registration_type', true ) );

		// JSON-LD offer's url must be the real external link, not the bare permalink.
		$node = $this->module()->event_schema->for_event( $event_id );
		$this->assertArrayHasKey( 'offers', $node );
		$this->assertSame( 'https://ext.example/reg', $node['offers'][0]['url'] );
	}

	/* ------------------------------------------------------------------
	 * Part 1, case 3 — old single event, registration disabled.
	 * ------------------------------------------------------------------ */

	public function test_old_event_with_registration_disabled_renders_no_registration_ui() {
		$internal_id = $this->make_event( [
			'registration_type' => 'internal',
			'registration_enabled' => false,
		] );
		$external_id = $this->make_event( [
			'registration_type' => 'external',
			'registration_url' => 'https://ext.example/reg',
			'registration_enabled' => false,
		] );

		// Same as before the rework: registration_enabled=false suppresses ALL
		// registration UI, internal or external — no crash, no stray markup.
		$this->assertSame( '', $this->module()->render_registration_form( $internal_id ) );
		$this->assertSame( '', $this->module()->render_registration_form( $external_id ) );
	}

	/* ------------------------------------------------------------------
	 * Part 1, case 4 (+ Part 2) — migration correctness + external_url mapping.
	 * ------------------------------------------------------------------ */

	public function test_migration_backfills_registration_mode_and_maps_external_url_for_a_batch_of_old_events() {
		delete_option( 'anchor_events_regmode_migrated' );

		$free_id = $this->make_event( [ 'registration_type' => 'internal' ] );
		$external_id = $this->make_event( [
			'registration_type' => 'external',
			'registration_url' => 'https://ext.example/batch-reg',
		] );
		$wc_id = $this->make_event(
			[],
			[ [ 'label' => 'General', 'price' => '25', 'active' => 1 ] ]
		);

		// Sanity: none have an explicit registration_mode yet.
		foreach ( [ $free_id, $external_id, $wc_id ] as $id ) {
			$this->assertSame( '', get_post_meta( $id, '_anchor_event_registration_mode', true ) );
		}

		$this->module()->migrate_registration_mode();

		$this->assertSame( 'free', get_post_meta( $free_id, '_anchor_event_registration_mode', true ) );
		$this->assertSame( 'external', get_post_meta( $external_id, '_anchor_event_registration_mode', true ) );
		$this->assertSame( 'wc', get_post_meta( $wc_id, '_anchor_event_registration_mode', true ) );
		$this->assertTrue( (bool) get_option( 'anchor_events_regmode_migrated' ) );

		// The Task BC mapping: registration_url -> external_url, ONLY for the
		// external event, and the legacy key is left in place (not cleared).
		$this->assertSame( 'https://ext.example/batch-reg', get_post_meta( $external_id, '_anchor_event_external_url', true ) );
		$this->assertSame( 'https://ext.example/batch-reg', get_post_meta( $external_id, '_anchor_event_registration_url', true ) );

		// The free event never gets an external_url written (mapping is
		// external-mode only), and the wc event doesn't either.
		$this->assertSame( '', get_post_meta( $free_id, '_anchor_event_external_url', true ) );
		$this->assertSame( '', get_post_meta( $wc_id, '_anchor_event_external_url', true ) );

		// The migration only ever touches events lacking registration_mode
		// (query-level guard), so re-running it is a structural no-op: hand-edit
		// a migrated value and confirm a second run doesn't revisit that post.
		update_post_meta( $external_id, '_anchor_event_registration_mode', 'free' );
		update_post_meta( $external_id, '_anchor_event_external_url', 'https://ext.example/hand-edited' );
		$this->module()->migrate_registration_mode();

		$this->assertSame( 'free', get_post_meta( $external_id, '_anchor_event_registration_mode', true ), 'Idempotent: already-migrated posts must not be revisited.' );
		$this->assertSame( 'https://ext.example/hand-edited', get_post_meta( $external_id, '_anchor_event_external_url', true ), 'Idempotent: already-migrated posts must not be revisited.' );
	}

	/**
	 * The mapping itself only writes external_url when it is still empty —
	 * proven directly (not just via the outer once-per-site option guard) by
	 * resetting only the registration_mode meta (simulating the migration's
	 * own query re-matching a post) and confirming a pre-existing external_url
	 * survives untouched.
	 */
	public function test_migration_external_url_mapping_never_overwrites_an_existing_value() {
		delete_option( 'anchor_events_regmode_migrated' );

		$event_id = $this->make_event( [
			'registration_type' => 'external',
			'registration_url' => 'https://ext.example/legacy',
		] );
		// Simulate an event that already has an explicit external_url (e.g. a
		// partially-migrated row, or one already edited under the new UI)
		// before the migration query would otherwise pick it up.
		update_post_meta( $event_id, '_anchor_event_external_url', 'https://ext.example/already-explicit' );

		$this->module()->migrate_registration_mode();

		$this->assertSame(
			'https://ext.example/already-explicit',
			get_post_meta( $event_id, '_anchor_event_external_url', true ),
			'The mapping must never clobber an existing external_url.'
		);
		$this->assertSame( 'external', get_post_meta( $event_id, '_anchor_event_registration_mode', true ) );
	}

	/* ------------------------------------------------------------------
	 * Part 1, case 5 — resolver fallback WITHOUT the migration having run.
	 * ------------------------------------------------------------------ */

	/**
	 * These fixtures are created fresh in THIS test and migrate_registration_mode()
	 * is never called on them — proving the live-read fallback (not the
	 * migration) is what makes an un-migrated site work correctly on its very
	 * first page load after upgrade. (The site-wide 'anchor_events_regmode_migrated'
	 * option may already be true from the module's own init-time call in
	 * bootstrap — that's irrelevant here, since a no-op run over an empty DB
	 * cannot have touched posts that didn't exist yet; each assertion below
	 * confirms THIS event's own registration_mode meta is still unset.)
	 */
	public function test_resolvers_work_correctly_before_migration_has_touched_these_events() {
		$free_id = $this->make_event( [ 'registration_type' => 'internal' ] );
		$external_id = $this->make_event( [
			'registration_type' => 'external',
			'registration_url' => 'https://ext.example/live-read',
		] );

		$this->assertSame( '', get_post_meta( $free_id, '_anchor_event_registration_mode', true ), 'Fixture must be genuinely un-migrated.' );
		$this->assertSame( '', get_post_meta( $external_id, '_anchor_event_registration_mode', true ), 'Fixture must be genuinely un-migrated.' );
		$this->assertSame( '', get_post_meta( $external_id, '_anchor_event_external_url', true ), 'Fixture must not have the new key set.' );

		$this->assertSame( 'free', $this->module()->registration_mode( $free_id ) );
		$this->assertSame( 'external', $this->module()->registration_mode( $external_id ) );
		$this->assertSame( 'https://ext.example/live-read', $this->module()->external_url( $external_id ) );

		$html = $this->module()->render_registration_form( $external_id );
		$this->assertStringContainsString( 'https://ext.example/live-read', $html );
	}

	/* ------------------------------------------------------------------
	 * Part 3 — legacy UI removal must not blank old events' legacy meta,
	 * and old external events must open pre-filled/derived correctly.
	 * ------------------------------------------------------------------ */

	/** The admin metabox no longer renders the redundant legacy controls. */
	public function test_admin_metabox_no_longer_renders_legacy_registration_controls() {
		$event_id = $this->make_event( [
			'registration_type' => 'external',
			'registration_url' => 'https://ext.example/reg',
		] );

		ob_start();
		$this->module()->render_meta_box( get_post( $event_id ) );
		$html = ob_get_clean();

		$this->assertStringNotContainsString( 'anchor_event_registration_type', $html );
		$this->assertStringNotContainsString( 'name="anchor_event_registration_url"', $html );

		// The new registration-mode chooser and external-url field still exist
		// and are correctly pre-populated/derived for this old event.
		$this->assertStringContainsString( 'anchor_event_registration_mode', $html );
		$this->assertStringContainsString( 'value="https://ext.example/reg"', $html );
	}

	/** Same for the front-end manager form. */
	public function test_front_end_form_no_longer_renders_legacy_registration_controls() {
		$event_id = $this->make_event( [
			'registration_type' => 'external',
			'registration_url' => 'https://ext.example/reg',
		] );

		$method = new ReflectionMethod( Module::class, 'render_event_manager_form' );
		$method->setAccessible( true );
		$html = $method->invoke( $this->module(), $event_id );

		$this->assertStringNotContainsString( 'anchor_event_registration_type', $html );
		$this->assertStringNotContainsString( 'name="anchor_event_registration_url"', $html );
		$this->assertStringContainsString( 'value="https://ext.example/reg"', $html );
	}

	/**
	 * The crux Part 3 assertion: re-saving an old external event through the
	 * ADMIN metabox after the legacy fields are gone must NOT blank its real
	 * link. $_POST deliberately omits anchor_event_registration_type/
	 * anchor_event_registration_url (they no longer exist in the form), but
	 * DOES carry anchor_event_registration_mode + anchor_event_external_url
	 * exactly as the real (now-derived/pre-filled) fields would submit.
	 */
	public function test_admin_resave_of_old_external_event_does_not_blank_legacy_link() {
		$event_id = $this->make_event( [
			'registration_type' => 'external',
			'registration_url' => 'https://ext.example/reg',
		] );

		// What the editor actually shows for this event (derived/pre-filled).
		$this->assertSame( 'external', $this->module()->registration_mode( $event_id ) );
		$this->assertSame( 'https://ext.example/reg', $this->module()->get_meta( $event_id )['external_url'] );

		$_POST = [
			Module::NONCE => wp_create_nonce( Module::NONCE ),
			'anchor_event_start_date' => '2026-09-01',
			'anchor_event_registration_enabled' => '1',
			'anchor_event_registration_mode' => 'external',
			'anchor_event_external_url' => 'https://ext.example/reg',
			// No anchor_event_registration_type / anchor_event_registration_url —
			// those inputs no longer exist in the form.
		];
		$this->module()->save_meta( $event_id );

		$this->assertSame(
			'https://ext.example/reg',
			get_post_meta( $event_id, '_anchor_event_registration_url', true ),
			'The legacy registration_url must survive a re-save even though its field was removed from the form.'
		);
		$this->assertSame(
			'external',
			get_post_meta( $event_id, '_anchor_event_registration_type', true ),
			'The legacy registration_type must survive a re-save even though its field was removed from the form.'
		);
		$this->assertSame( 'external', get_post_meta( $event_id, '_anchor_event_registration_mode', true ) );
		$this->assertSame( 'https://ext.example/reg', get_post_meta( $event_id, '_anchor_event_external_url', true ) );

		// And the front-end link still renders correctly after the re-save.
		$html = $this->module()->render_registration_form( $event_id );
		$this->assertStringContainsString( 'href="' . esc_url( 'https://ext.example/reg' ) . '"', $html );
	}

	/** Same crux assertion via the FRONT-END manager form's save path. */
	public function test_front_end_resave_of_old_external_event_does_not_blank_legacy_link() {
		$event_id = $this->make_event( [
			'registration_type' => 'external',
			'registration_url' => 'https://ext.example/reg',
		] );

		$_POST = [
			'anchor_event_registration_mode' => 'external',
			'anchor_event_external_url' => 'https://ext.example/reg',
		];
		$fallback = $this->module()->registration_mode( $event_id );

		$method = new ReflectionMethod( Module::class, 'save_event_manager_fields' );
		$method->setAccessible( true );
		$method->invoke( $this->module(), $event_id, '2026-09-01', $fallback );

		$this->assertSame(
			'https://ext.example/reg',
			get_post_meta( $event_id, '_anchor_event_registration_url', true ),
			'The legacy registration_url must survive a re-save even though its field was removed from the form.'
		);
		$this->assertSame(
			'external',
			get_post_meta( $event_id, '_anchor_event_registration_type', true ),
			'The legacy registration_type must survive a re-save even though its field was removed from the form.'
		);
		$this->assertSame( 'external', get_post_meta( $event_id, '_anchor_event_registration_mode', true ) );
		$this->assertSame( 'https://ext.example/reg', get_post_meta( $event_id, '_anchor_event_external_url', true ) );
	}

	/**
	 * Guard against a subtler regression: even an OLD FREE/internal event
	 * (registration_type=internal, no registration_url) must survive a
	 * re-save with its legacy registration_type intact and unchanged, proving
	 * the removed fields' absence from $_POST never re-writes 'internal' over
	 * something else or otherwise touches the legacy keys.
	 */
	public function test_admin_resave_of_old_internal_event_leaves_legacy_type_untouched() {
		$event_id = $this->make_event( [ 'registration_type' => 'internal' ] );
		update_post_meta( $event_id, '_anchor_event_registration_type', 'internal' );

		$_POST = [
			Module::NONCE => wp_create_nonce( Module::NONCE ),
			'anchor_event_start_date' => '2026-09-01',
			'anchor_event_registration_mode' => 'free',
		];
		$this->module()->save_meta( $event_id );

		$this->assertSame( 'internal', get_post_meta( $event_id, '_anchor_event_registration_type', true ) );
		$this->assertSame( 'free', $this->module()->registration_mode( $event_id ) );
	}
}
