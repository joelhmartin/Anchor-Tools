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
}
