<?php
/**
 * Prerequisite roles inside the capacity authority (virtual-events spec §4.6).
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Module;
use Anchor\Events\Registrations;

/** Thrown from the wp_redirect filter so handle_registration()'s exit never runs. */
class Anchor_Prerequisites_Redirected extends \Exception {}

/**
 * @group prerequisites
 */
class Test_Prerequisites extends Anchor_Events_TestCase {

	public function set_up() {
		parent::set_up();
		add_role( 'anchor_course_intro', 'Course: Intro', [] );
		add_role( 'anchor_course_safety', 'Course: Safety', [] );
	}

	public function tear_down() {
		remove_role( 'anchor_course_intro' );
		remove_role( 'anchor_course_safety' );
		remove_filter( 'wp_redirect', [ $this, 'trap_redirect' ] );
		$_POST    = [];
		$_REQUEST = [];
		parent::tear_down();
	}

	public function trap_redirect( $location ) {
		throw new Anchor_Prerequisites_Redirected( (string) $location );
	}

	private function gated( array $roles, $mode = 'any' ) {
		return $this->make_event( [
			'capacity'             => 0,
			'registration_enabled' => true,
			'required_roles'       => $roles,
			'required_roles_mode'  => $mode,
		] );
	}

	/** No required roles: the decision is bit-identical to before. */
	public function test_no_prerequisite_is_unchanged() {
		$event_id = $this->make_event( [ 'capacity' => 0 ] );
		wp_set_current_user( 0 );
		$this->assertSame( 'open', $this->registrations()->capacity_decision( $event_id, $this->module()->get_meta( $event_id ) ) );
	}

	/** mode=any: holding one of the listed roles is enough. */
	public function test_mode_any() {
		$event_id = $this->gated( [ 'anchor_course_intro', 'anchor_course_safety' ], 'any' );
		$user_id  = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $user_id );
		$meta = $this->module()->get_meta( $event_id );

		$this->assertSame( 'prerequisite', $this->registrations()->capacity_decision( $event_id, $meta ) );

		( new WP_User( $user_id ) )->add_role( 'anchor_course_intro' );
		$this->assertSame( 'open', $this->registrations()->capacity_decision( $event_id, $meta ) );
	}

	/** mode=all: every listed role is required. */
	public function test_mode_all() {
		$event_id = $this->gated( [ 'anchor_course_intro', 'anchor_course_safety' ], 'all' );
		$user_id  = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $user_id );
		$meta = $this->module()->get_meta( $event_id );

		( new WP_User( $user_id ) )->add_role( 'anchor_course_intro' );
		$this->assertSame( 'prerequisite', $this->registrations()->capacity_decision( $event_id, $meta ) );

		( new WP_User( $user_id ) )->add_role( 'anchor_course_safety' );
		$this->assertSame( 'open', $this->registrations()->capacity_decision( $event_id, $meta ) );
	}

	/** Staff bypass the gate. */
	public function test_staff_bypass() {
		$event_id = $this->gated( [ 'anchor_course_intro' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( 'open', $this->registrations()->capacity_decision( $event_id, $this->module()->get_meta( $event_id ) ) );
	}

	/** An anonymous visitor is refused with the sign-in wording. */
	public function test_anonymous_is_refused_and_told_to_sign_in() {
		$event_id = $this->gated( [ 'anchor_course_intro' ] );
		wp_set_current_user( 0 );

		$this->assertSame( 'prerequisite', $this->registrations()->capacity_decision( $event_id, $this->module()->get_meta( $event_id ) ) );
		$this->assertStringContainsString(
			'Sign in to check eligibility',
			$this->module()->entitlements->prerequisite_message( $event_id )
		);
	}

	/** The named role appears in the refusal for a signed-in visitor. */
	public function test_message_names_the_roles() {
		$event_id = $this->gated( [ 'anchor_course_intro' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$this->assertStringContainsString( 'Course: Intro', $this->module()->entitlements->prerequisite_message( $event_id ) );
	}

	/** bookability() surfaces it and is_bookable() refuses it. */
	public function test_bookability_refuses() {
		$event_id = $this->gated( [ 'anchor_course_intro' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$this->assertSame( 'prerequisite', $this->module()->bookability( $event_id ) );
		$this->assertFalse( $this->module()->is_bookable( $this->module()->bookability( $event_id ) ) );
	}

	/** JSON-LD keeps publishing an InStock Offer — eligibility is not inventory. */
	public function test_schema_still_publishes_an_offer() {
		$event_id = $this->gated( [ 'anchor_course_intro' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$this->assertSame(
			'https://schema.org/InStock',
			\Anchor\Events\Event_Schema::availability_for( 'prerequisite' )
		);
	}

	/**
	 * The full JSON-LD node keeps its Offer for a gated event, not just the
	 * static availability_for() mapping — omits_offer() must not have been
	 * left off the allow-list.
	 */
	public function test_schema_node_keeps_the_offer_for_a_gated_event() {
		$event = $this->make_event(
			[
				'registration_enabled' => true,
				'registration_mode'    => 'wc',
				'start_date'           => '2030-01-01',
				'timezone'             => 'UTC',
				'required_roles'       => [ 'anchor_course_intro' ],
				'required_roles_mode'  => 'any',
			],
			[ [ 'label' => 'General', 'price' => '25', 'active' => 1 ] ]
		);
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$node = $this->module()->event_schema->for_event( $event );

		$this->assertArrayHasKey( 'offers', $node );
		$this->assertSame( 'https://schema.org/InStock', $node['offers'][0]['availability'] );
	}

	/**
	 * A gated product is not purchasable.
	 *
	 * filter_is_purchasable() resolves an event only from a VARIATION's own
	 * `_anchor_evt_link_event_id` meta (Product_Sync::write_variation()) —
	 * the parent variable product carries no such meta (only the reverse
	 * `_anchor_evt_managed_event` bookkeeping pointer product_sync owns), so
	 * calling the filter with the bare parent is a permanent no-op regardless
	 * of bookability. Every other purchasability test in
	 * test-storefront-bookability.php checks the variation for exactly this
	 * reason; this one follows the same, already-established pattern rather
	 * than the parent product the task brief's draft named.
	 */
	public function test_purchasability_refuses() {
		$this->require_wc();
		$event_id = $this->gated( [ 'anchor_course_intro' ] );
		$tiers    = $this->ticket_types()->save( $event_id, [ [ 'label' => 'GA', 'price' => '100', 'active' => 1 ] ] );
		$this->product_sync()->sync_event( $event_id );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$product_id = (int) get_post_meta( $event_id, '_anchor_event_managed_product', true );
		$this->assertGreaterThan( 0, $product_id );

		$variation_id = (int) $this->product_sync()->variation_for_tier( $event_id, $tiers[0]['id'] );
		$this->assertGreaterThan( 0, $variation_id );
		$this->assertFalse( $this->woocommerce()->filter_is_purchasable( true, wc_get_product( $variation_id ) ) );
	}

	/**
	 * The choose-date picker's short availability hint must not fall through
	 * to "Open"/"N spots left" for a gated occurrence — it used to, because
	 * choose_date_availability_hint() only special-cased full/waitlist/
	 * closed/disabled and let everything else reach the unlimited-capacity
	 * default. Occurrences::picker_state() already routes is_bookable()
	 * correctly (the CTA says "Details"), so a wrong hint here is exactly the
	 * "Sold out" + "Register" mismatch RENDER-D32 exists to prevent, just the
	 * other state.
	 */
	public function test_date_picker_hint_does_not_default_to_open() {
		$event_id = $this->gated( [ 'anchor_course_intro' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$hint = $this->module()->choose_date_availability_hint( $event_id, $this->module()->get_meta( $event_id ) );

		$this->assertStringNotContainsString( 'Open', $hint );
		$this->assertStringNotContainsString( 'spot', $hint );
	}

	/**
	 * handle_registration() (the free-form CTA's POST target) must refuse a
	 * gated event too — REG_NONCE is a bare action nonce, so a stale or
	 * forged POST reaches this handler whether or not the form itself
	 * rendered. Before this guard, 'prerequisite' matched neither the
	 * 'closed' nor 'full' arm of the pre-check and fell straight through to
	 * claim_seats(), minting a real seat for a visitor capacity_decision()
	 * had just refused.
	 */
	public function test_internal_registration_post_is_refused_for_an_ineligible_visitor() {
		$event_id = $this->gated( [ 'anchor_course_intro' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		add_filter( 'wp_redirect', [ $this, 'trap_redirect' ] );

		$_POST = [
			Module::REG_NONCE    => wp_create_nonce( Module::REG_NONCE ),
			'event_id'           => $event_id,
			'redirect_to'        => 'https://example.org/events/',
			'anchor_event_name'  => 'Jane Doe',
			'anchor_event_email' => 'jane@example.org',
		];
		$_REQUEST = $_POST;

		try {
			$this->module()->handle_registration();
			$this->fail( 'handle_registration() returned without redirecting.' );
		} catch ( Anchor_Prerequisites_Redirected $e ) {
			$this->assertStringContainsString( 'registration_prerequisite', $e->getMessage() );
		}

		$this->assertSame( 0, $this->module()->get_registration_count( $event_id ) );
	}
}
