<?php
/**
 * WOO-D25: `anchor_events_checkout_attendees_hook` must be evaluated late
 * enough for a theme to filter it.
 *
 * The filter used to be applied inside the WooCommerce class constructor,
 * which runs on `plugins_loaded` priority 25 — before any theme's
 * functions.php has loaded. register_checkout_attendees_hook() now runs on
 * `init` instead, reading the filter at call time rather than at
 * construction time.
 *
 * @package Anchor\Events\Tests
 */

/**
 * @group woocommerce
 */
class Test_WooCommerce_Checkout_Hook_Timing extends Anchor_Events_TestCase {

	public function set_up() {
		parent::set_up();
		$this->require_wc();
	}

	/**
	 * A filter added AFTER the module was constructed (simulating a theme's
	 * functions.php, loaded after plugins_loaded) must still take effect —
	 * proof the hook is resolved when register_checkout_attendees_hook() runs,
	 * not when the WooCommerce object was built.
	 */
	public function test_a_late_filter_changes_where_the_attendee_fields_render() {
		remove_all_actions( 'woocommerce_checkout_before_customer_details' );
		remove_all_actions( 'woocommerce_checkout_after_customer_details' );

		add_filter( 'anchor_events_checkout_attendees_hook', function () {
			return 'woocommerce_checkout_after_customer_details';
		} );

		// Simulates the `init` callback firing AFTER this late filter is in place.
		$this->woocommerce()->register_checkout_attendees_hook();

		$this->assertFalse(
			has_action( 'woocommerce_checkout_before_customer_details', [ $this->woocommerce(), 'render_checkout_attendee_fields' ] ),
			'Must NOT still be on the default hook once a filter re-points it.'
		);
		$this->assertNotFalse(
			has_action( 'woocommerce_checkout_after_customer_details', [ $this->woocommerce(), 'render_checkout_attendee_fields' ] ),
			'A theme-supplied hook name must be honored.'
		);
	}

	/** With no filter at all, the documented default hook is used. */
	public function test_default_hook_is_before_customer_details() {
		remove_all_actions( 'woocommerce_checkout_before_customer_details' );

		$this->woocommerce()->register_checkout_attendees_hook();

		$this->assertNotFalse(
			has_action( 'woocommerce_checkout_before_customer_details', [ $this->woocommerce(), 'render_checkout_attendee_fields' ] )
		);
	}
}
