<?php
/**
 * Authorization hygiene for the events module (Wave 3).
 *
 * Every roster / export / resend / console surface has to agree about who is
 * allowed to see attendee PII and act on it. Before this task there were three
 * different answers in the code: Roster::cap() (manage_woocommerce on a store),
 * a hard-coded `edit_others_posts` in the two front-end shortcodes, and another
 * hard-coded `edit_others_posts` on the three WooCommerce order actions. On a
 * store that meant an Editor denied the Roster screen could read the same names
 * and emails from the front-end console and resend customer mail.
 *
 * These tests pin the single authority — Module::events_capability(), filtered
 * through `anchor_events_capability` — plus the two object checks that a
 * capability alone cannot make: an export target must be a real, live event
 * (REG-D16) and a seat may only be acted on through the event whose nonce
 * authorized the action (REG-D48).
 *
 * IDs: REG-D20, REG-D62, WOO-D41, REG-D16, REG-D21, REG-D48.
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Module;
use Anchor\Events\Registrations;
use Anchor\Events\Roster;

/** Thrown from the wp_redirect filter so a handler's exit() never runs. */
class Anchor_Caps_Redirected extends \Exception {}

/**
 * @group capabilities
 * @group roster
 */
class Test_Capabilities extends Anchor_Events_TestCase {

	/** Synthetic capability used to prove the gate reads events_capability(). */
	const TEST_CAP = 'anchor_events_test_cap';

	/** Every wp_mail() call made during the test. */
	private $sent = [];

	public function set_up() {
		parent::set_up();
		$this->sent = [];
		add_filter( 'wp_mail', [ $this, 'capture_mail' ] );
		add_filter( 'wp_redirect', [ $this, 'trap_redirect' ] );
	}

	public function tear_down() {
		remove_filter( 'wp_mail', [ $this, 'capture_mail' ] );
		remove_filter( 'wp_redirect', [ $this, 'trap_redirect' ] );
		remove_all_filters( 'anchor_events_capability' );
		$_GET     = [];
		$_POST    = [];
		$_REQUEST = [];
		parent::tear_down();
	}

	public function capture_mail( $args ) {
		$this->sent[] = $args;
		$args['to']   = 'nobody@example.org';
		return $args;
	}

	public function trap_redirect( $location ) {
		throw new Anchor_Caps_Redirected( (string) $location );
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                             */
	/* ------------------------------------------------------------------ */

	/** Force the module capability to a synthetic one nobody holds by default. */
	private function force_test_cap() {
		add_filter( 'anchor_events_capability', function () {
			return self::TEST_CAP;
		} );
	}

	/** A user who holds only the synthetic events capability. */
	private function user_with_test_cap() {
		$uid  = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$user = new WP_User( $uid );
		$user->add_cap( self::TEST_CAP );
		return $uid;
	}

	/** An event with one confirmed seat; returns [ event_id, seat_id, email ]. */
	private function event_with_seat( $email = 'roster-pii@example.org' ) {
		$event_id = $this->make_event( [
			'title'      => 'Capability Fixture',
			'start_date' => gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ),
			// The shortcode orders by start_ts, so the meta join has to match.
			'start_ts'   => time() + DAY_IN_SECONDS,
		] );
		$seat_id  = $this->make_seat( $event_id, [ 'name' => 'Pat Attendee', 'email' => $email ] );
		return [ $event_id, (int) $seat_id, $email ];
	}

	/** Run the export handler with a valid nonce and return the wp_die message. */
	private function export_die_message( $event_id, $scope = 'all' ) {
		$_GET = [
			'event_id' => $event_id,
			'scope'    => $scope,
			'_wpnonce' => wp_create_nonce( 'anchor_event_export' ),
		];
		$_REQUEST = $_GET;

		ob_start();
		try {
			$this->module()->roster->handle_export();
		} catch ( WPDieException $e ) {
			ob_end_clean();
			return $e->getMessage();
		} catch ( \Throwable $e ) {
			ob_end_clean();
			$this->fail( 'handle_export() threw ' . get_class( $e ) . ': ' . $e->getMessage() );
		}
		$body = ob_get_clean();
		$this->fail( 'handle_export() did not refuse; it emitted ' . strlen( $body ) . ' bytes of CSV.' );
	}

	/* ------------------------------------------------------------------ */
	/* One capability, one function                                        */
	/* ------------------------------------------------------------------ */

	public function test_events_capability_is_woocommerce_aware() {
		$expected = class_exists( 'WooCommerce' ) ? 'manage_woocommerce' : 'edit_others_posts';
		$this->assertSame( $expected, Module::events_capability() );
	}

	public function test_events_capability_resolves_without_woocommerce() {
		// The no-WooCommerce answer must exist even in a WC run: it is the value
		// a site that never installed a store resolves to, and a site without a
		// store must still get a usable capability rather than an empty string.
		$this->assertSame( 'edit_others_posts', Roster::CAP );
		$this->assertNotSame( '', Module::events_capability() );
	}

	public function test_events_capability_is_filterable() {
		$this->force_test_cap();
		$this->assertSame( self::TEST_CAP, Module::events_capability() );
	}

	public function test_events_capability_ignores_a_useless_filter_value() {
		add_filter( 'anchor_events_capability', '__return_empty_string' );
		$this->assertNotSame( '', Module::events_capability() );
		remove_filter( 'anchor_events_capability', '__return_empty_string' );

		add_filter( 'anchor_events_capability', '__return_empty_array' );
		$this->assertIsString( Module::events_capability() );
	}

	public function test_roster_cap_delegates_to_the_module() {
		$this->assertSame( Module::events_capability(), Roster::cap() );
		$this->force_test_cap();
		$this->assertSame( self::TEST_CAP, Roster::cap() );
	}

	public function test_current_user_can_manage_follows_the_filtered_capability() {
		$this->force_test_cap();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertFalse( Roster::current_user_can_manage(), 'An administrator without the events capability must be denied.' );

		wp_set_current_user( $this->user_with_test_cap() );
		$this->assertTrue( Roster::current_user_can_manage(), 'The holder of the events capability must be allowed.' );
	}

	/* ------------------------------------------------------------------ */
	/* REG-D20 — the two shortcodes are the same gate as the roster        */
	/* ------------------------------------------------------------------ */

	public function test_registrants_list_shortcode_is_gated_on_the_events_capability() {
		list( , , $email ) = $this->event_with_seat( 'reg-list@example.org' );
		$this->force_test_cap();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$denied = $this->module()->shortcode_event_registrants_list( [] );
		$this->assertStringNotContainsString( $email, (string) $denied, '[event_registrants_list] leaked attendee email to a user without the events capability.' );

		wp_set_current_user( $this->user_with_test_cap() );
		$allowed = $this->module()->shortcode_event_registrants_list( [] );
		$this->assertStringContainsString( $email, (string) $allowed, 'The events-capability holder must still see the roster.' );
	}

	public function test_event_manager_shortcode_is_gated_on_the_events_capability() {
		list( , , $email ) = $this->event_with_seat( 'manager-console@example.org' );
		$this->force_test_cap();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$denied = (string) $this->module()->shortcode_event_manager( [] );
		$this->assertStringNotContainsString( $email, $denied, '[event_manager] leaked attendee email to a user without the events capability.' );
		$this->assertStringContainsString( 'No access', $denied );
	}

	public function test_editor_is_denied_the_console_on_a_store() {
		$this->require_wc();
		list( , , $email ) = $this->event_with_seat( 'editor-denied@example.org' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertFalse( Roster::current_user_can_manage(), 'M2: an Editor has no manage_woocommerce, so the roster is closed.' );
		$this->assertStringNotContainsString( $email, (string) $this->module()->shortcode_event_registrants_list( [] ) );
		$this->assertStringNotContainsString( $email, (string) $this->module()->shortcode_event_manager( [] ) );
	}

	/* ------------------------------------------------------------------ */
	/* REG-D21 — no Export link a user cannot use                          */
	/* ------------------------------------------------------------------ */

	public function test_export_links_and_the_export_handler_agree() {
		list( $event_id ) = $this->event_with_seat( 'export-link@example.org' );
		$this->force_test_cap();

		// Denied: no link anywhere, and the handler refuses.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertStringNotContainsString(
			'anchor_event_export',
			(string) $this->module()->shortcode_event_registrants_list( [] )
		);
		$this->assertStringNotContainsString(
			'anchor_event_export',
			(string) $this->module()->shortcode_event_manager( [] )
		);
		$this->assertSame( 'Unauthorized', $this->export_die_message( $event_id ) );

		// Allowed: the link is offered, and the handler accepts the target.
		wp_set_current_user( $this->user_with_test_cap() );
		$this->assertStringContainsString(
			'anchor_event_export',
			(string) $this->module()->shortcode_event_registrants_list( [] )
		);
		$this->assertTrue( Roster::is_exportable_event( $event_id ) );
	}

	/* ------------------------------------------------------------------ */
	/* REG-D16 — the export target must be a live event                    */
	/* ------------------------------------------------------------------ */

	public function test_is_exportable_event_predicate() {
		list( $event_id ) = $this->event_with_seat();
		$page_id = self::factory()->post->create( [ 'post_type' => 'page' ] );

		$this->assertTrue( Roster::is_exportable_event( $event_id ) );
		$this->assertFalse( Roster::is_exportable_event( 0 ) );
		$this->assertFalse( Roster::is_exportable_event( $page_id ), 'A page is not an event.' );
		$this->assertFalse( Roster::is_exportable_event( $event_id + 100000 ), 'A missing post is not an event.' );

		wp_trash_post( $event_id );
		$this->assertFalse( Roster::is_exportable_event( $event_id ), 'A trashed event must not export its attendee list.' );
	}

	public function test_export_refuses_a_non_event_id() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$page_id = self::factory()->post->create( [ 'post_type' => 'page' ] );
		$this->assertSame( 'Invalid event.', $this->export_die_message( $page_id ) );
	}

	public function test_export_refuses_a_missing_post() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( 'Invalid event.', $this->export_die_message( 987654 ) );
	}

	public function test_export_refuses_a_trashed_event() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		list( $event_id ) = $this->event_with_seat( 'trashed-export@example.org' );
		wp_trash_post( $event_id );
		$this->assertSame( 'Invalid event.', $this->export_die_message( $event_id ) );
	}

	public function test_export_refuses_a_missing_event_id() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( 'Invalid event.', $this->export_die_message( 0 ) );
	}

	/* ------------------------------------------------------------------ */
	/* REG-D48 — a seat belongs to the event the nonce was minted for      */
	/* ------------------------------------------------------------------ */

	public function test_seat_belongs_to_event_predicate() {
		list( $event_a, $seat_a ) = $this->event_with_seat( 'a@example.org' );
		list( $event_b, $seat_b ) = $this->event_with_seat( 'b@example.org' );

		$this->assertTrue( Roster::seat_belongs_to_event( $seat_a, $event_a ) );
		$this->assertFalse( Roster::seat_belongs_to_event( $seat_b, $event_a ) );
		$this->assertFalse( Roster::seat_belongs_to_event( $event_a, $event_a ), 'An event post is not a seat.' );
		$this->assertFalse( Roster::seat_belongs_to_event( 0, $event_a ) );
		$this->assertFalse( Roster::seat_belongs_to_event( $seat_a, 0 ) );
	}

	public function test_roster_edit_refuses_a_seat_from_another_event() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		list( $event_a ) = $this->event_with_seat( 'a-edit@example.org' );
		list( , $seat_b ) = $this->event_with_seat( 'b-edit@example.org' );

		$_POST = [
			'event_id'      => $event_a,
			'seat_id'       => $seat_b,
			'roster_name'   => 'Hijacked',
			'roster_email'  => 'hijacked@example.org',
			'roster_status' => Registrations::STATUS_CANCELLED,
			'_wpnonce'      => wp_create_nonce( 'anchor_roster_edit_' . $event_a ),
		];
		$_REQUEST = $_POST;

		$location = null;
		try {
			$this->module()->roster->handle_edit();
		} catch ( Anchor_Caps_Redirected $e ) {
			$location = $e->getMessage();
		}
		$this->assertNotNull( $location, 'handle_edit() must refuse and redirect.' );
		$this->assertStringContainsString( 'roster_type=error', rawurldecode( (string) $location ) );

		// No state change on the foreign seat.
		$this->assertSame( 'b-edit@example.org', (string) get_post_meta( $seat_b, '_anchor_event_email', true ) );
		$this->assertSame(
			Registrations::STATUS_CONFIRMED,
			(string) get_post_meta( $seat_b, '_anchor_event_reg_status', true )
		);
	}

	public function test_roster_cancel_refuses_a_seat_from_another_event() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		list( $event_a ) = $this->event_with_seat( 'a-cancel@example.org' );
		list( , $seat_b ) = $this->event_with_seat( 'b-cancel@example.org' );

		$_REQUEST = [
			'event_id' => $event_a,
			'seat_id'  => $seat_b,
			'_wpnonce' => wp_create_nonce( 'anchor_roster_cancel_' . $event_a ),
		];
		$_GET = $_REQUEST;

		$location = null;
		try {
			$this->module()->roster->handle_cancel();
		} catch ( Anchor_Caps_Redirected $e ) {
			$location = $e->getMessage();
		}
		$this->assertNotNull( $location, 'handle_cancel() must refuse and redirect.' );
		$this->assertStringContainsString( 'roster_type=error', rawurldecode( (string) $location ) );
		$this->assertSame(
			Registrations::STATUS_CONFIRMED,
			(string) get_post_meta( $seat_b, '_anchor_event_reg_status', true ),
			'A foreign seat must not be cancelled through another event\'s nonce.'
		);
	}

	public function test_roster_cancel_still_works_on_its_own_event() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		list( $event_a, $seat_a ) = $this->event_with_seat( 'own-cancel@example.org' );

		$_REQUEST = [
			'event_id' => $event_a,
			'seat_id'  => $seat_a,
			'_wpnonce' => wp_create_nonce( 'anchor_roster_cancel_' . $event_a ),
		];
		$_GET = $_REQUEST;

		try {
			$this->module()->roster->handle_cancel();
		} catch ( Anchor_Caps_Redirected $e ) {
			// expected
		}
		$this->assertSame(
			Registrations::STATUS_CANCELLED,
			(string) get_post_meta( $seat_a, '_anchor_event_reg_status', true )
		);
	}

	/* ------------------------------------------------------------------ */
	/* REG-D62 + WOO-D41 — the three order actions share the gate          */
	/* ------------------------------------------------------------------ */

	/** @return string|null wp_die message, or null when the handler ran. */
	private function run_woo_handler( $method, $nonce_action, $order_id ) {
		$_POST = [
			'order_id' => $order_id,
			'_wpnonce' => wp_create_nonce( $nonce_action . $order_id ),
		];
		$_REQUEST = $_POST;
		try {
			$this->woocommerce()->{$method}();
		} catch ( WPDieException $e ) {
			return $e->getMessage();
		} catch ( Anchor_Caps_Redirected $e ) {
			return null;
		}
		return null;
	}

	public function test_order_actions_require_the_events_capability() {
		$this->require_wc();
		$this->force_test_cap();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->assertNotNull( $this->run_woo_handler( 'handle_resync_order', 'anchor_event_resync_', 4242 ) );
		$this->assertNotNull( $this->run_woo_handler( 'handle_clear_review', 'anchor_events_clear_review_', 4242 ) );
		$this->assertNotNull( $this->run_woo_handler( 'handle_resend_confirmation', 'anchor_events_resend_', 4242 ) );
		$this->assertSame( [], $this->sent, 'A refused resend must never reach the sender.' );
	}

	public function test_order_actions_accept_the_events_capability_holder() {
		$this->require_wc();
		$this->force_test_cap();
		wp_set_current_user( $this->user_with_test_cap() );

		$this->assertNull( $this->run_woo_handler( 'handle_resync_order', 'anchor_event_resync_', 4242 ) );
		$this->assertNull( $this->run_woo_handler( 'handle_clear_review', 'anchor_events_clear_review_', 4242 ) );
		$this->assertNull( $this->run_woo_handler( 'handle_resend_confirmation', 'anchor_events_resend_', 4242 ) );
	}

	public function test_editor_cannot_resend_a_customer_confirmation_on_a_store() {
		$this->require_wc();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertNotNull(
			$this->run_woo_handler( 'handle_resend_confirmation', 'anchor_events_resend_', 4242 ),
			'REG-D62: an Editor denied the roster must not be able to resend customer mail.'
		);
		$this->assertSame( [], $this->sent );
	}
}
