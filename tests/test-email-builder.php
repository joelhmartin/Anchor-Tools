<?php
/**
 * Task 3.2 — Emails builder metabox: dedicated save path + AJAX real-data
 * preview endpoint.
 *
 * Two surfaces under test:
 *  - Module::save_meta() persisting `anchor_email_tpl_{type}` POST fields to
 *    `_anchor_event_email_tpl_{type}` via save_email_templates() — a
 *    DEDICATED validated path (email-safe wp_kses(), never the generic
 *    save_meta() $input allow-list), mirroring Test_Event_Save's proof for
 *    `external_embed`/sanitize_external_embed().
 *  - Module::ajax_email_preview() (`wp_ajax_anchor_events_email_preview`):
 *    nonce + capability gated, renders the POSTED (unsaved) template through
 *    build_registration_email_html() with real event tokens expanded, never
 *    sends, and kses's the posted template before rendering.
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Module;

/**
 * @group email
 * @group event-save
 */
class Test_Email_Builder extends Anchor_Events_TestCase {

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
	 * Build a save_meta() $_POST payload with a single overridden email-type
	 * field; other fields are omitted on purpose (save_meta() must tolerate a
	 * partial post — this test isolates the Task 3.2 email fields, matching
	 * Test_Event_Save's post_payload() convention).
	 */
	private function post_payload( array $overrides = [] ) {
		return array_merge(
			[ Module::NONCE => wp_create_nonce( Module::NONCE ) ],
			$overrides
		);
	}

	/* ---------------------------------------------------------------------
	 * save_meta() -> save_email_templates(): dedicated validated save path.
	 * ------------------------------------------------------------------- */

	/**
	 * RED-before-GREEN baseline: a customized (different-from-default)
	 * reminder template is persisted to the per-event override meta key,
	 * sanitized via the email-safe wp_kses() allowlist — a <script> tag is
	 * stripped, a <table> with an inline style survives.
	 */
	public function test_save_meta_persists_sanitized_reminder_override_strips_script_keeps_table() {
		$event_id = $this->make_event();

		$submitted = '<table style="width:100%;"><tr><td>Custom reminder for {event_title}</td></tr></table>'
			. '<script>alert(1)</script>';

		$_POST = $this->post_payload( [ 'anchor_email_tpl_reminder' => $submitted ] );
		$this->module()->save_meta( $event_id );

		$stored = get_post_meta( $event_id, '_anchor_event_email_tpl_reminder', true );

		$this->assertStringContainsString( '<table', $stored, 'Allowed <table> must survive the email-safe kses.' );
		// WP's safe-CSS filter (safecss_filter_attr(), applied by wp_kses() to any
		// allowed `style` attribute) keeps the property but drops the trailing ';'.
		$this->assertStringContainsString( 'style="width:100%"', $stored, 'Inline style must survive the email-safe kses.' );
		$this->assertStringContainsString( '{event_title}', $stored, 'Token braces are plain text to wp_kses and must pass through untouched.' );
		$this->assertStringNotContainsString( '<script', $stored, 'A <script> tag must be stripped by the dedicated email-template save path.' );

		// The override actually takes effect via the normal resolver.
		$this->assertSame( $stored, $this->module()->resolve_email_template( 'reminder', $event_id ) );
	}

	/** Only the posted email-type fields are touched; other events'/types' overrides are untouched. */
	public function test_save_meta_is_per_type_independent() {
		$event_id = $this->make_event();

		$_POST = $this->post_payload( [ 'anchor_email_tpl_cancellation' => '<p>Custom cancellation</p>' ] );
		$this->module()->save_meta( $event_id );

		$this->assertSame( '<p>Custom cancellation</p>', get_post_meta( $event_id, '_anchor_event_email_tpl_cancellation', true ) );
		// confirmation was never posted this request — its meta stays unset/empty, resolver falls to default.
		$this->assertSame( '', get_post_meta( $event_id, '_anchor_event_email_tpl_confirmation', true ) );
		$this->assertSame(
			$this->module()->default_email_template( 'confirmation' ),
			$this->module()->resolve_email_template( 'confirmation', $event_id )
		);
	}

	/**
	 * Saving content byte-equal to the resolved (override-less) default
	 * stores '' rather than a redundant literal copy — this is also exactly
	 * what happens after the JS "Reset to default" button writes the
	 * fallback text into the editor and the form is then submitted, so one
	 * assertion covers both documented behaviors.
	 */
	public function test_save_meta_stores_empty_when_submitted_content_equals_resolved_default() {
		$event_id = $this->make_event();
		$default  = $this->module()->resolve_email_template( 'confirmation', 0 );

		$_POST = $this->post_payload( [ 'anchor_email_tpl_confirmation' => $default ] );
		$this->module()->save_meta( $event_id );

		$this->assertSame( '', get_post_meta( $event_id, '_anchor_event_email_tpl_confirmation', true ) );
		$this->assertSame( $default, $this->module()->resolve_email_template( 'confirmation', $event_id ) );
	}

	/**
	 * An EXISTING per-event override, when the editor is reset (submitted
	 * content reverts to the resolved default) and saved, is cleared back to
	 * '' — proving "Reset to default" + Save actually removes a previously
	 * stored override rather than merely masking it.
	 */
	public function test_save_meta_clears_an_existing_override_when_reset_to_default_is_submitted() {
		$event_id = $this->make_event();
		update_post_meta( $event_id, '_anchor_event_email_tpl_confirmation', '<p>Old custom override</p>' );

		$default = $this->module()->resolve_email_template( 'confirmation', 0 );
		$_POST   = $this->post_payload( [ 'anchor_email_tpl_confirmation' => $default ] );
		$this->module()->save_meta( $event_id );

		$this->assertSame( '', get_post_meta( $event_id, '_anchor_event_email_tpl_confirmation', true ) );
	}

	/** save_meta() is a no-op when the shared metabox nonce is missing/invalid (existing guard, unchanged). */
	public function test_save_meta_noop_without_valid_nonce() {
		$event_id = $this->make_event();

		$_POST = $this->post_payload( [
			Module::NONCE               => 'invalid-nonce',
			'anchor_email_tpl_reminder' => '<p>Should not be saved</p>',
		] );
		$this->module()->save_meta( $event_id );

		$this->assertSame( '', get_post_meta( $event_id, '_anchor_event_email_tpl_reminder', true ) );
	}

	/* ---------------------------------------------------------------------
	 * render_email_preview_html(): the testable rendering core of the AJAX
	 * endpoint, factored out of ajax_email_preview() specifically so these
	 * tests never call wp_send_json_success()/wp_send_json_error() — those
	 * only route through the test suite's catchable wp_die() when
	 * wp_doing_ajax() is true; called directly (as any real request to
	 * admin-ajax.php would NOT be), wp_send_json() instead ends in a bare
	 * language-level `die;` (see wp_send_json()'s source) that no exception
	 * handler can intercept and that kills the entire PHPUnit process — this
	 * was confirmed empirically while developing this suite. Mirrors the
	 * same extraction Task 1.5 used for handle_event_manager_save() /
	 * save_event_manager_fields() (see Test_Event_Manager_Save).
	 * ------------------------------------------------------------------- */

	/** Renders the given (unsaved) template with real event tokens expanded; never persists anything. */
	public function test_render_email_preview_html_expands_tokens() {
		$event_id = $this->make_event( [ 'title' => 'AJAX Preview Event' ] );

		$html = $this->module()->render_email_preview_html(
			$event_id,
			'confirmation',
			'<div id="pv"><h2>{event_title}</h2><p>Hi {attendee_name}</p></div>'
		);

		$this->assertStringContainsString( 'AJAX Preview Event', $html );
		$this->assertStringContainsString( 'Hi Sample Attendee', $html );

		// Nothing was persisted by the preview call.
		$this->assertSame( '', get_post_meta( $event_id, '_anchor_event_email_tpl_confirmation', true ) );
	}

	/** A <script> tag in the posted (unsaved) template is stripped before rendering — preview never executes admin-authored script. */
	public function test_render_email_preview_html_strips_script_from_posted_template() {
		$event_id = $this->make_event();

		$html = $this->module()->render_email_preview_html(
			$event_id,
			'confirmation',
			'<div>{event_title}</div><script>alert(1)</script>'
		);

		$this->assertStringNotContainsString( '<script', $html );
	}

	/** The reminder type's preview builds its own representative $ctx (date/venue tokens present via the reminder $ctx shape). */
	public function test_render_email_preview_html_reminder_type_uses_reminder_ctx() {
		$event_id = $this->make_event( [ 'title' => 'Reminder Preview Event', 'venue' => 'Main Hall' ] );

		$html = $this->module()->render_email_preview_html(
			$event_id,
			'reminder',
			'<div>{event_title} — {venue}</div>'
		);

		$this->assertStringContainsString( 'Reminder Preview Event', $html );
		$this->assertStringContainsString( 'Main Hall', $html );
	}

	/**
	 * Email Appearance settings reach a previewed email.
	 *
	 * NOTE the asserted spellings. The preview runs its template through
	 * sanitize_email_template_html() -> wp_kses, which rebuilds every style
	 * attribute and drops the trailing ";" — so the shell's own declarations
	 * come out as `color:#0b1220"`, while the block tokens the builder appends
	 * afterwards (greeting/intro paragraphs, CTA) never pass through kses and
	 * keep theirs. Asserting a ";" on a shell declaration is how you write a
	 * test that can never pass.
	 */
	public function test_render_email_preview_html_applies_basic_email_branding() {
		update_option( Module::OPTION_KEY, [
			'email_logo_url'         => 'https://example.test/logo.png',
			'email_background_color' => '#101820',
			'email_card_color'       => '#ffffff',
			'email_text_color'       => '#1f2937',
			'email_heading_color'    => '#0b1220',
			'email_button_color'     => '#b91c1c',
		], false );

		$event_id = $this->make_event( [ 'title' => 'Branded Preview Event' ] );
		$html = $this->module()->render_email_preview_html(
			$event_id,
			'confirmation',
			$this->module()->default_email_template( 'confirmation' )
		);

		$this->assertStringContainsString( 'https://example.test/logo.png', $html );
		$this->assertStringContainsString( 'background:#101820', $html );
		// Shell declaration (kses-normalized): no trailing semicolon.
		$this->assertStringContainsString( 'color:#0b1220"', $html );
		// Block token (never kses'd): keeps its semicolon.
		$this->assertStringContainsString( 'color:#1f2937;', $html );
		$this->assertStringContainsString( 'background:#b91c1c', $html );

		// The stock #111 heading and #333/#222 body colors are fully replaced,
		// not left behind next to the branded ones.
		$this->assertStringNotContainsString( 'color:#111"', $html );
		$this->assertStringNotContainsString( 'color:#333;', $html );
		$this->assertStringNotContainsString( 'color:#222;', $html );

		delete_option( Module::OPTION_KEY );
	}

	/**
	 * The guard the whole feature rests on: Email Appearance left at its
	 * defaults must not touch a single byte of the email. Saving the settings
	 * screen without changing a color writes the 6-digit spellings (#111111),
	 * while the markup carries the 3-digit ones (#111) — so "unchanged" has to
	 * be decided by comparing normalized colors, not string equality.
	 *
	 * tests/test-email-templates.php covers the never-saved case; this covers
	 * the saved-but-untouched case, which is the one a color input produces.
	 */
	public function test_default_email_appearance_is_a_no_op() {
		$event_id = $this->make_event( [ 'title' => 'Unbranded Event' ] );

		$baseline = $this->module()->render_email_preview_html(
			$event_id,
			'confirmation',
			$this->module()->default_email_template( 'confirmation' )
		);

		update_option( Module::OPTION_KEY, [
			'email_logo_url'         => '',
			'email_background_color' => '#f4f4f4',
			'email_card_color'       => '#ffffff',
			'email_text_color'       => '#333333',
			'email_heading_color'    => '#111111',
			'email_button_color'     => '#111111',
		], false );

		$branded = $this->module()->render_email_preview_html(
			$event_id,
			'confirmation',
			$this->module()->default_email_template( 'confirmation' )
		);

		$this->assertSame( $baseline, $branded );
		// And specifically: the stock CTA stays near-black rather than picking
		// up an invented default accent.
		$this->assertStringContainsString( 'background:#111;', $branded );

		delete_option( Module::OPTION_KEY );
	}

	/* ---------------------------------------------------------------------
	 * ajax_email_preview(): the thin nonce+capability-gated wrapper.
	 * ------------------------------------------------------------------- */

	/**
	 * check_ajax_referer() reads $_REQUEST (not $_POST) for the nonce — PHP
	 * only populates $_REQUEST from a real HTTP request at boot, so a test
	 * that only assigns $_POST leaves $_REQUEST stale/empty and every nonce
	 * check fails regardless of the value under test. Mirrors how a real
	 * admin-ajax.php POST request populates both superglobals.
	 */
	private function set_ajax_post( array $data ) {
		$_POST    = $data;
		$_REQUEST = $data;
	}

	/**
	 * Makes wp_die() calls made while wp_doing_ajax() is true catchable as
	 * WPDieException, WITHOUT ever defining the DOING_AJAX constant.
	 *
	 * check_ajax_referer()'s nonce-failure path is `die('-1')` (a raw,
	 * uncatchable language-level die — NOT wp_die()) whenever wp_doing_ajax()
	 * is false, and wp_send_json_error()/wp_send_json_success() fall back to
	 * a bare `die;` in that same case (see wp_send_json()'s source) — so
	 * BOTH of ajax_email_preview()'s guard-rejection paths are only ever
	 * testable (survivable by the test runner) once wp_doing_ajax() is true.
	 * But WP_UnitTestCase only overrides the 'wp_die_handler' filter (the
	 * NON-ajax path) to throw exceptions; the 'wp_die_ajax_handler' filter
	 * still defaults to the real, raw-dying `_ajax_wp_die_handler`.
	 *
	 * DOING_AJAX is a PHP constant: once define()'d it can never be unset for
	 * the rest of the process, which would silently flip wp_die()'s branch
	 * for EVERY OTHER test that runs afterward — including totally unrelated
	 * ones expecting the default (non-ajax) exception-throwing handler.
	 * Confirmed by running the full suite during development of this file:
	 * defining DOING_AJAX here broke Test_Event_Manager_Save's own unrelated
	 * wp_die()-based nonce test purely via file-load/test-run ordering,
	 * because WP_UnitTestCase's per-test hook backup/restore (_backup_hooks())
	 * resets any add_filter() calls between tests but can't un-define a
	 * constant. wp_doing_ajax() itself is filterable — `apply_filters(
	 * 'wp_doing_ajax', defined('DOING_AJAX') && DOING_AJAX )` — so hooking
	 * THAT filter instead achieves the same effect for this test only, and
	 * is automatically cleaned up by the same per-test hook backup/restore
	 * that necessitated the unconditional add_filter() below in the first
	 * place. No permanent process-wide state is touched.
	 *
	 * `@runInSeparateProcess` was tried first and rejected: PHPUnit's
	 * process-isolation serializes test/global state between the parent and
	 * child process, which fails outright ("Serialization of 'Closure' is
	 * not allowed") given the closures and live DB connection objects this
	 * suite's fixtures involve.
	 */
	private function ensure_ajax_die_is_catchable() {
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', function () {
			return function ( $message ) {
				throw new WPDieException( is_scalar( $message ) ? (string) $message : '' );
			};
		} );
	}

	/** Missing/invalid nonce is rejected by check_ajax_referer(), before any capability check or rendering. */
	public function test_ajax_email_preview_requires_valid_nonce() {
		$this->ensure_ajax_die_is_catchable();
		$event_id = $this->make_event();
		$this->set_ajax_post( [
			'nonce'    => 'invalid-nonce',
			'event_id' => $event_id,
			'type'     => 'confirmation',
			'template' => '<p>x</p>',
		] );

		$this->expectException( WPDieException::class );
		$this->module()->ajax_email_preview();
	}

	/** A user without edit_post capability on the event is rejected (403), even with a valid nonce. */
	public function test_ajax_email_preview_requires_edit_post_capability() {
		$this->ensure_ajax_die_is_catchable();
		$event_id      = $this->make_event();
		$subscriber_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber_id );

		$this->set_ajax_post( [
			'nonce'    => wp_create_nonce( 'anchor_events_email_preview' ),
			'event_id' => $event_id,
			'type'     => 'confirmation',
			'template' => '<p>x</p>',
		] );

		ob_start();
		try {
			$this->module()->ajax_email_preview();
		} catch ( WPDieException $e ) {
			// Expected — wp_send_json_error() ends in wp_die().
		}
		$response = json_decode( ob_get_clean(), true );

		$this->assertIsArray( $response );
		$this->assertFalse( $response['success'] );
	}

	/** With a valid nonce and edit_post capability, the full AJAX entry point returns the rendered HTML (thin-wrapper sanity check over render_email_preview_html()'s dedicated tests above). */
	public function test_ajax_email_preview_succeeds_for_capable_user() {
		$this->ensure_ajax_die_is_catchable();
		$event_id = $this->make_event( [ 'title' => 'Full Endpoint Event' ] );

		$this->set_ajax_post( [
			'nonce'    => wp_create_nonce( 'anchor_events_email_preview' ),
			'event_id' => $event_id,
			'type'     => 'confirmation',
			'template' => '<div>{event_title}</div>',
		] );

		ob_start();
		try {
			$this->module()->ajax_email_preview();
		} catch ( WPDieException $e ) {
			// Expected — wp_send_json_success() ends in wp_die().
		}
		$response = json_decode( ob_get_clean(), true );

		$this->assertIsArray( $response );
		$this->assertTrue( $response['success'] );
		$this->assertStringContainsString( 'Full Endpoint Event', $response['data']['html'] );
	}

	/* ---------------------------------------------------------------------
	 * REG-D25 — the doctype survives a saved template.
	 *
	 * The kses allowlist cannot express a doctype declaration, so a template
	 * that carries one loses it permanently on save (production event 7258's
	 * four stored templates all begin with whitespace then <html>). The fix
	 * moves the doctype OUT of the stored/edited document and emits it at the
	 * assembly point, so there is exactly one source of truth for it.
	 * ------------------------------------------------------------------- */

	/** A stock event's email is a complete HTML document — doctype included, exactly once. */
	public function test_default_email_is_emitted_with_a_doctype() {
		$event_id = $this->make_event( [ 'title' => 'Doctype Event' ] );

		$html = $this->module()->build_registration_email_html( [
			'event_id' => $event_id,
			'name'     => 'Jane',
			'status'   => 'confirmed',
			'type'     => 'confirmation',
		] );

		$this->assertStringStartsWith( '<!DOCTYPE html>', $html );
		$this->assertSame( 1, substr_count( strtoupper( $html ), '<!DOCTYPE' ) );
	}

	/** The template the builder pre-fills (and stores) carries no doctype of its own. */
	public function test_resolved_template_carries_no_doctype() {
		$event_id = $this->make_event();

		$this->assertStringNotContainsStringIgnoringCase(
			'<!DOCTYPE',
			$this->module()->resolve_email_template( 'confirmation', $event_id )
		);
		$this->assertStringNotContainsStringIgnoringCase(
			'<!DOCTYPE',
			$this->module()->default_email_template( 'confirmation' )
		);
	}

	/**
	 * The 7258 signature: a hand-built full-document override, saved through
	 * the builder with a leading doctype. The doctype is not stored (kses
	 * cannot express it), but the sent email still has one.
	 */
	public function test_saved_full_document_override_still_sends_with_a_doctype() {
		$event_id = $this->make_event( [ 'title' => 'Override Doctype Event' ] );

		$submitted = "<!DOCTYPE html>\n<html><head><title>{event_title}</title></head>"
			. '<body style="background:#0b1220"><p>Hand built</p></body></html>';

		$_POST = $this->post_payload( [ 'anchor_email_tpl_confirmation' => $submitted ] );
		$this->module()->save_meta( $event_id );

		$stored = get_post_meta( $event_id, '_anchor_event_email_tpl_confirmation', true );
		$this->assertStringNotContainsStringIgnoringCase( '<!DOCTYPE', $stored, 'The doctype is not stored — it is emitted on assembly.' );
		$this->assertStringStartsWith( '<html', $stored );

		$html = $this->module()->build_registration_email_html( [
			'event_id' => $event_id,
			'name'     => 'Jane',
			'status'   => 'confirmed',
			'type'     => 'confirmation',
		] );
		$this->assertStringStartsWith( '<!DOCTYPE html>', $html );
		$this->assertSame( 1, substr_count( strtoupper( $html ), '<!DOCTYPE' ) );
	}

	/** A fragment override is not a document, so it gets no doctype (unchanged behaviour). */
	public function test_fragment_override_gets_no_doctype() {
		$event_id = $this->make_event();
		update_post_meta( $event_id, '_anchor_event_email_tpl_confirmation', '<div id="custom">{event_title}</div>' );

		$html = $this->module()->build_registration_email_html( [
			'event_id' => $event_id,
			'name'     => 'Jane',
			'status'   => 'confirmed',
			'type'     => 'confirmation',
		] );

		$this->assertStringNotContainsStringIgnoringCase( '<!DOCTYPE', $html );
	}

	/* ---------------------------------------------------------------------
	 * REG-D26 — the resolved default stays a live default.
	 * ------------------------------------------------------------------- */

	/** Invoke the private CTA save path with a $_POST-shaped array. */
	private function save_cta( $event_id, array $src ) {
		$method = new ReflectionMethod( Module::class, 'save_email_cta_fields' );
		$method->setAccessible( true );
		$method->invoke( $this->module(), $event_id, array_merge( [ 'anchor_event_email_cta_present' => '1' ], $src ) );
	}

	/**
	 * The defect: the builder posts the resolved default back, the save path
	 * writes it as explicit meta, and the "live default" is frozen. Change the
	 * event's Zoom URL afterwards and every email still links to the old room.
	 */
	public function test_posting_the_resolved_default_writes_no_cta_meta() {
		$event_id = $this->make_event( [ 'virtual' => true, 'virtual_url' => 'https://zoom.test/room-one' ] );

		$this->save_cta( $event_id, [
			'anchor_event_email_cta_label_confirmation' => 'Join the event',
			'anchor_event_email_cta_url_confirmation'   => 'https://zoom.test/room-one',
		] );

		$this->assertFalse( metadata_exists( 'post', $event_id, '_anchor_event_email_cta_label_confirmation' ) );
		$this->assertFalse( metadata_exists( 'post', $event_id, '_anchor_event_email_cta_url_confirmation' ) );

		// The default still follows the event: repoint the room and the email
		// links to the new one rather than to the URL that was current on save.
		update_post_meta( $event_id, '_anchor_event_virtual_url', 'https://zoom.test/room-two' );
		$html = $this->module()->build_registration_email_html( [
			'event_id' => $event_id,
			'name'     => 'Jane',
			'status'   => 'confirmed',
			'type'     => 'confirmation',
		] );
		$this->assertStringContainsString( 'href="https://zoom.test/room-two"', $html );
		$this->assertStringNotContainsString( 'href="https://zoom.test/room-one"', $html );
	}

	/** 7258's frozen meta thaws on the next save through the builder. */
	public function test_saving_thaws_a_previously_frozen_default() {
		$event_id = $this->make_event( [ 'virtual' => true, 'virtual_url' => 'https://zoom.test/room-one' ] );
		update_post_meta( $event_id, '_anchor_event_email_cta_label_confirmation', 'Join the event' );
		update_post_meta( $event_id, '_anchor_event_email_cta_url_confirmation', 'https://zoom.test/room-one' );

		$this->save_cta( $event_id, [
			'anchor_event_email_cta_label_confirmation' => 'Join the event',
			'anchor_event_email_cta_url_confirmation'   => 'https://zoom.test/room-one',
		] );

		$this->assertFalse( metadata_exists( 'post', $event_id, '_anchor_event_email_cta_label_confirmation' ) );
		$this->assertFalse( metadata_exists( 'post', $event_id, '_anchor_event_email_cta_url_confirmation' ) );
	}

	/**
	 * Clearing a field that HAS a value is still "deliberately no button".
	 * metadata_exists() is the discriminator, exactly as in get_email_cta().
	 */
	public function test_explicitly_emptied_cta_is_stored_as_empty() {
		$event_id = $this->make_event( [ 'virtual' => true, 'virtual_url' => 'https://zoom.test/room-one' ] );
		update_post_meta( $event_id, '_anchor_event_email_cta_label_confirmation', 'Come along' );
		update_post_meta( $event_id, '_anchor_event_email_cta_url_confirmation', 'https://example.test/come' );

		$this->save_cta( $event_id, [
			'anchor_event_email_cta_label_confirmation' => '',
			'anchor_event_email_cta_url_confirmation'   => '',
		] );

		$this->assertTrue( metadata_exists( 'post', $event_id, '_anchor_event_email_cta_label_confirmation' ) );
		$this->assertSame( '', get_post_meta( $event_id, '_anchor_event_email_cta_label_confirmation', true ) );
		$cta = $this->module()->get_email_cta( $event_id, 'confirmation', 1, [ 'label' => 'View event details', 'url' => 'https://example.test/' ] );
		$this->assertSame( '', $cta['label'], 'Empty meta still means "no button".' );
	}

	/**
	 * The other half of the placeholder change, and the one that would have
	 * been a far worse bug than the frozen default: the builder now renders the
	 * default as a PLACEHOLDER, so an untouched field posts ''. That must not
	 * be written — it would read as "deliberately no button" and drop the CTA
	 * from every email on the first save of any other field.
	 */
	public function test_untouched_placeholder_does_not_blank_the_default_cta() {
		$event_id = $this->make_event( [ 'virtual' => true, 'virtual_url' => 'https://zoom.test/room-one' ] );

		$this->save_cta( $event_id, [
			'anchor_event_email_cta_label_confirmation' => '',
			'anchor_event_email_cta_url_confirmation'   => '',
			'anchor_event_email_cta2_label_confirmation' => '',
			'anchor_event_email_cta2_url_confirmation'   => '',
		] );

		$this->assertFalse( metadata_exists( 'post', $event_id, '_anchor_event_email_cta_label_confirmation' ) );
		$this->assertFalse( metadata_exists( 'post', $event_id, '_anchor_event_email_cta_url_confirmation' ) );

		$html = $this->module()->build_registration_email_html( [
			'event_id' => $event_id,
			'name'     => 'Jane',
			'status'   => 'confirmed',
			'type'     => 'confirmation',
		] );
		$this->assertStringContainsString( 'href="https://zoom.test/room-one"', $html );
		$this->assertStringContainsString( 'Join the event', $html );
	}

	/** A genuinely customized CTA is written exactly as posted. */
	public function test_customized_cta_is_stored() {
		$event_id = $this->make_event();

		$this->save_cta( $event_id, [
			'anchor_event_email_cta_label_reminder' => 'Get directions',
			'anchor_event_email_cta_url_reminder'   => 'https://maps.example.test/venue',
		] );

		$this->assertSame( 'Get directions', get_post_meta( $event_id, '_anchor_event_email_cta_label_reminder', true ) );
		$this->assertSame( 'https://maps.example.test/venue', get_post_meta( $event_id, '_anchor_event_email_cta_url_reminder', true ) );
	}

	/** The builder shows the default as a placeholder, not as a value, until the author sets one. */
	public function test_builder_renders_the_default_cta_as_a_placeholder() {
		$event_id = $this->make_event( [ 'virtual' => true, 'virtual_url' => 'https://zoom.test/room-one' ] );

		$method = new ReflectionMethod( Module::class, 'render_event_manager_form' );
		$method->setAccessible( true );
		$html = $method->invoke( $this->module(), $event_id );

		$this->assertStringContainsString( 'placeholder="https://zoom.test/room-one"', $html );
		$this->assertStringContainsString( 'placeholder="Join the event"', $html );

		// The CTA inputs themselves carry an EMPTY value — printing the default
		// there is what the browser posts back and what freezes it on save.
		// (The event's own Virtual URL field legitimately holds the same string,
		// so match the CTA inputs specifically.)
		$this->assertMatchesRegularExpression(
			'/name="anchor_event_email_cta_url_confirmation"\s*\n?\s*value=""/',
			$html,
			'The default must not be printed into the CTA value= attribute.'
		);
		$this->assertMatchesRegularExpression(
			'/name="anchor_event_email_cta_label_confirmation"\s*\n?\s*value=""/',
			$html
		);
	}

	/* ---------------------------------------------------------------------
	 * REG-D27 — appearance settings reach a custom template through tokens.
	 * ------------------------------------------------------------------- */

	private function set_appearance() {
		update_option( Module::OPTION_KEY, [
			'email_logo_url'         => 'https://example.test/logo.png',
			'email_background_color' => '#0b1220',
			'email_card_color'       => '#101820',
			'email_text_color'       => '#e5e7eb',
			'email_heading_color'    => '#f9fafb',
			'email_button_color'     => '#b91c1c',
		], false );
	}

	/** Brace tokens inside a style attribute must survive the email-safe kses. */
	public function test_brand_tokens_survive_the_email_kses() {
		$event_id = $this->make_event();

		$_POST = $this->post_payload( [
			'anchor_email_tpl_confirmation' => '<div style="background:{brand_bg};color:{brand_text}">{logo}{intro}</div>',
		] );
		$this->module()->save_meta( $event_id );

		$stored = get_post_meta( $event_id, '_anchor_event_email_tpl_confirmation', true );
		$this->assertStringContainsString( '{brand_bg}', $stored );
		$this->assertStringContainsString( '{brand_text}', $stored );
		$this->assertStringContainsString( '{logo}', $stored );
	}

	/** A hand-built template that opts in gets the palette and the logo. */
	/**
	 * REG-D59 — the palette and the map that runs cannot disagree.
	 *
	 * There used to be four overlapping lists describing one vocabulary
	 * (wording_email_tokens, template_email_tokens, documented_email_tokens and
	 * the two maps that actually build the values), with nothing tying a list
	 * to its map — and they already had: {join_button} was produced and listed
	 * nowhere, {order_number}/{order_url} were listed and produced nowhere. The
	 * palettes are derived now; this renders every button the palette offers
	 * and fails if any of them reaches the inbox as literal text.
	 */
	public function test_every_palette_token_actually_resolves_in_a_template() {
		$method = new ReflectionMethod( $this->module(), 'documented_email_tokens' );
		$method->setAccessible( true );
		$tokens = $method->invoke( $this->module() );
		$this->assertNotEmpty( $tokens );

		$event_id = $this->make_event( [ 'title' => 'Palette Event' ] );
		$template = '';
		foreach ( $tokens as $token ) {
			$template .= '<div>{' . $token . '}</div>';
		}
		update_post_meta( $event_id, '_anchor_event_email_tpl_confirmation', $template );

		$html = $this->module()->build_registration_email_html( [
			'event_id'    => $event_id,
			'name'        => 'Jane',
			'status'      => 'confirmed',
			'detail_rows' => [ [ 'label' => 'Date', 'value' => 'March 1, 2027' ] ],
			'cta_label'   => 'View',
			'cta_url'     => 'https://example.test/e/',
			'type'        => 'confirmation',
		] );

		foreach ( $tokens as $token ) {
			$this->assertStringNotContainsString(
				'{' . $token . '}',
				$html,
				'The palette offers {' . $token . '}, but the body map never builds it.'
			);
		}

		delete_post_meta( $event_id, '_anchor_event_email_tpl_confirmation' );
	}

	public function test_brand_tokens_resolve_from_the_appearance_settings() {
		$this->set_appearance();
		$event_id = $this->make_event( [ 'title' => 'Token Branding Event' ] );
		update_post_meta(
			$event_id,
			'_anchor_event_email_tpl_confirmation',
			'<html><body style="background:{brand_bg}"><table width="700"><tr><td>own markup</td></tr>{logo}</table>'
				. '<p style="color:{brand_text}">{event_title}</p>'
				. '<a style="background:{brand_button};color:{brand_button_text}">go</a></body></html>'
		);

		$html = $this->module()->build_registration_email_html( [
			'event_id' => $event_id,
			'name'     => 'Jane',
			'status'   => 'confirmed',
			'type'     => 'confirmation',
		] );

		$this->assertStringContainsString( 'background:#0b1220', $html );
		$this->assertStringContainsString( 'color:#e5e7eb', $html );
		$this->assertStringContainsString( 'background:#b91c1c', $html );
		$this->assertStringContainsString( 'https://example.test/logo.png', $html );
		$this->assertStringNotContainsString( '{brand_bg}', $html );
		$this->assertStringNotContainsString( '{logo}', $html );

		delete_option( Module::OPTION_KEY );
	}

	/** With no logo configured, {logo} renders nothing rather than a broken image. */
	public function test_logo_token_is_empty_without_a_logo_setting() {
		$event_id = $this->make_event();
		update_post_meta( $event_id, '_anchor_event_email_tpl_confirmation', '<div id="c">[{logo}]</div>' );

		$html = $this->module()->build_registration_email_html( [
			'event_id' => $event_id,
			'name'     => 'Jane',
			'status'   => 'confirmed',
			'type'     => 'confirmation',
		] );

		$this->assertStringContainsString( '[]', $html );
	}

	/** The literal rewrite stays as the fallback for templates that use none of the tokens. */
	public function test_literal_rewrite_still_applies_to_token_less_templates() {
		$this->set_appearance();
		$event_id = $this->make_event();
		update_post_meta(
			$event_id,
			'_anchor_event_email_tpl_confirmation',
			'<html><body style="background:#f4f4f4"><table width="600"><tr><td>{intro}</td></tr></table></body></html>'
		);

		$html = $this->module()->build_registration_email_html( [
			'event_id' => $event_id,
			'name'     => 'Jane',
			'status'   => 'confirmed',
			'type'     => 'confirmation',
		] );

		$this->assertStringContainsString( 'background:#0b1220', $html );
		$this->assertStringContainsString( 'https://example.test/logo.png', $html );

		delete_option( Module::OPTION_KEY );
	}

	/** The builder says so when the template can never pick up the appearance settings. */
	public function test_builder_warns_when_a_template_uses_no_brand_tokens() {
		$event_id = $this->make_event();
		$method   = new ReflectionMethod( Module::class, 'render_event_manager_form' );
		$method->setAccessible( true );

		$this->assertStringNotContainsString( 'anchor-event-email-appearance-warning', $method->invoke( $this->module(), $event_id ) );

		update_post_meta( $event_id, '_anchor_event_email_tpl_confirmation', '<div id="own">{intro}</div>' );
		$this->assertStringContainsString( 'anchor-event-email-appearance-warning', $method->invoke( $this->module(), $event_id ) );
	}

	/* ---------------------------------------------------------------------
	 * Fix round 1 — the preview answers the "is this authored?" question the
	 * same way the save path does, and the loosened sanitizer is still a
	 * sanitizer.
	 * ------------------------------------------------------------------- */

	/** Run ajax_email_preview() and return the rendered HTML. */
	private function preview_via_ajax( array $data ) {
		$this->ensure_ajax_die_is_catchable();
		$this->set_ajax_post( array_merge( [ 'nonce' => wp_create_nonce( 'anchor_events_email_preview' ) ], $data ) );

		ob_start();
		try {
			$this->module()->ajax_email_preview();
		} catch ( WPDieException $e ) {
			// Expected — wp_send_json_success() ends in wp_die().
		}
		$response = json_decode( ob_get_clean(), true );

		$this->assertIsArray( $response );
		$this->assertTrue( $response['success'] );
		return (string) $response['data']['html'];
	}

	/**
	 * The builder posts its CTA fields on every preview. With the default now
	 * rendered as a PLACEHOLDER those fields are empty on an untouched event,
	 * and taking that literally previewed no button while the send had one —
	 * whereupon the author re-types the default into the field and re-freezes
	 * exactly what REG-D26 unfroze.
	 */
	public function test_preview_shows_the_default_button_for_untouched_cta_fields() {
		$event_id = $this->make_event( [
			'title'       => 'Preview Default CTA Event',
			'virtual'     => true,
			'virtual_url' => 'https://zoom.test/room-one',
		] );

		$html = $this->preview_via_ajax( [
			'event_id'  => $event_id,
			'type'      => 'confirmation',
			'template'  => '{cta_button}',
			'cta_label' => '',
			'cta_url'   => '',
		] );

		$this->assertStringContainsString( 'href="https://zoom.test/room-one"', $html );
		$this->assertStringContainsString( 'Join the event', $html );
	}

	/** A deliberately emptied (stored '') CTA previews as no button — the other half of the same rule. */
	public function test_preview_shows_no_button_when_the_cta_is_deliberately_empty() {
		$event_id = $this->make_event( [
			'title'       => 'Preview Empty CTA Event',
			'virtual'     => true,
			'virtual_url' => 'https://zoom.test/room-one',
		] );
		update_post_meta( $event_id, '_anchor_event_email_cta_label_confirmation', '' );
		update_post_meta( $event_id, '_anchor_event_email_cta_url_confirmation', '' );

		$html = $this->preview_via_ajax( [
			'event_id'  => $event_id,
			'type'      => 'confirmation',
			'template'  => '{cta_button}',
			'cta_label' => '',
			'cta_url'   => '',
		] );

		$this->assertStringNotContainsString( 'https://zoom.test/room-one', $html );
		$this->assertStringNotContainsString( 'Join the event', $html );
	}

	/** A CTA the author actually typed still wins over both meta and the default. */
	public function test_preview_honours_a_typed_cta() {
		$event_id = $this->make_event( [ 'virtual' => true, 'virtual_url' => 'https://zoom.test/room-one' ] );

		$html = $this->preview_via_ajax( [
			'event_id'  => $event_id,
			'type'      => 'confirmation',
			'template'  => '{cta_button}',
			'cta_label' => 'Take me there',
			'cta_url'   => 'https://example.test/typed',
		] );

		$this->assertStringContainsString( 'https://example.test/typed', $html );
		$this->assertStringContainsString( 'Take me there', $html );
		$this->assertStringNotContainsString( 'https://zoom.test/room-one', $html );
	}

	/** Invoke the private email-template sanitizer. */
	private function sanitize_template( $html ) {
		$method = new ReflectionMethod( Module::class, 'sanitize_email_template_html' );
		$method->setAccessible( true );
		return (string) $method->invoke( $this->module(), $html );
	}

	/**
	 * The safecss_filter_attr_allow_css hook only ever RE-ASKS WordPress's own
	 * question with the `{token}` placeholders removed. Everything that check
	 * rejects for any other reason must still be rejected.
	 *
	 * @dataProvider unsafe_css_provider
	 */
	public function test_loosened_sanitizer_still_strips_unsafe_css( $declaration, $needle ) {
		$out = $this->sanitize_template( '<div style="' . $declaration . '">x</div>' );
		$this->assertStringNotContainsString( $needle, $out, $declaration . ' must not survive the email sanitizer.' );
	}

	public function unsafe_css_provider() {
		return [
			'token plus javascript url' => [ 'background:{x} url(javascript:alert(1))', 'javascript:' ],
			'expression'                => [ 'width:expression(1)', 'expression(' ],
			'brace injection'           => [ 'color:red}', 'color:red}' ],
			'lone brace'                => [ '}', 'style="}"' ],
			'css comment'               => [ 'color:red/*x*/', '/*' ],
		];
	}

	/** The brand tokens are the one thing the hook rescues — and only in a declaration that is otherwise clean. */
	public function test_loosened_sanitizer_keeps_brand_tokens() {
		$out = $this->sanitize_template( '<div style="background:{brand_bg};color:{brand_text}">x</div>' );

		$this->assertStringContainsString( 'background:{brand_bg}', $out );
		$this->assertStringContainsString( 'color:{brand_text}', $out );
	}

	/** The hook is scoped to that one call: kses everywhere else is untouched afterwards. */
	public function test_sanitizer_hook_is_removed_again() {
		$this->sanitize_template( '<div style="background:{brand_bg}">x</div>' );

		$this->assertFalse( has_filter( 'safecss_filter_attr_allow_css' ) );
		$this->assertStringNotContainsString( 'color:red}', wp_kses_post( '<p style="color:red}">x</p>' ) );
		// And a token in a style attribute is NOT rescued outside the email path.
		$this->assertStringNotContainsString( '{brand_bg}', wp_kses_post( '<p style="background:{brand_bg}">x</p>' ) );
	}

	/** A UTF-8 BOM in front of the doctype does not leave a second one in the sent email. */
	public function test_doctype_strip_tolerates_a_utf8_bom() {
		$event_id = $this->make_event();
		update_post_meta(
			$event_id,
			'_anchor_event_email_tpl_confirmation',
			"\xEF\xBB\xBF<!DOCTYPE html>\n<html><body><p>{event_title}</p></body></html>"
		);

		$html = $this->module()->build_registration_email_html( [
			'event_id' => $event_id,
			'name'     => 'Jane',
			'status'   => 'confirmed',
			'type'     => 'confirmation',
		] );

		$this->assertStringStartsWith( '<!DOCTYPE html>', $html );
		$this->assertSame( 1, substr_count( strtoupper( $html ), '<!DOCTYPE' ) );
	}

	/** The wp-admin metabox carries the same no-token appearance warning as the front-end builder. */
	public function test_admin_metabox_warns_when_a_template_uses_no_brand_tokens() {
		$event_id = $this->make_event();
		$post     = get_post( $event_id );

		ob_start();
		$this->module()->render_email_builder_metabox( $post );
		$stock = ob_get_clean();
		$this->assertStringNotContainsString( 'anchor-event-email-appearance-warning', $stock );

		update_post_meta( $event_id, '_anchor_event_email_tpl_confirmation', '<div id="own">{intro}</div>' );

		ob_start();
		$this->module()->render_email_builder_metabox( get_post( $event_id ) );
		$custom = ob_get_clean();
		$this->assertStringContainsString( 'anchor-event-email-appearance-warning', $custom );
	}
}
