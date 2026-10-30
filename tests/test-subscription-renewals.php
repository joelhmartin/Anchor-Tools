<?php
/**
 * Installment-plan renewals must not create seats (require WooCommerce).
 *
 * WC Subscriptions is not installed in the test environment, so its two
 * renewal lookups are stubbed below. The stubs only answer for order ids a
 * test registers in $GLOBALS['anchor_test_wcs_renewals']; every other order
 * is "not a renewal", so other suites see the same behavior as without WCS.
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Registrations;
use Anchor\Events\Subscription_Renewals;

if ( ! function_exists( 'wcs_order_contains_renewal' ) ) {
	function wcs_order_contains_renewal( $order_id ) {
		return isset( $GLOBALS['anchor_test_wcs_renewals'][ (int) $order_id ] );
	}
}
if ( ! function_exists( 'wcs_get_subscriptions_for_renewal_order' ) ) {
	function wcs_get_subscriptions_for_renewal_order( $order_id ) {
		return $GLOBALS['anchor_test_wcs_renewals'][ (int) $order_id ] ?? [];
	}
}

/** Minimal stand-in for WC_Subscription: only get_time( 'end' ) is read. */
class Anchor_Test_Subscription_Stub {
	private $end;
	public function __construct( $end ) {
		$this->end = (int) $end;
	}
	public function get_time( $type ) {
		return 'end' === $type ? $this->end : 0;
	}
}

/**
 * @group woocommerce
 * @group reconcile
 */
class Test_Subscription_Renewals extends Anchor_Events_TestCase {

	public function set_up() {
		parent::set_up();
		$this->require_wc();
		$GLOBALS['anchor_test_wcs_renewals'] = [];
	}

	public function tear_down() {
		unset( $GLOBALS['anchor_test_wcs_renewals'] );
		remove_all_filters( 'anchor_events_renewal_creates_seats' );
		parent::tear_down();
	}

	/** @return array{event_id:int,variation_id:int} */
	private function paid_event() {
		$event_id = $this->make_event(
			[ 'title' => 'Installment Event', 'capacity' => 0 ],
			[ [ 'label' => 'Dentist', 'price' => '30', 'active' => 1 ] ]
		);
		$this->product_sync()->sync_event( $event_id );
		$tiers = $this->ticket_types()->get( $event_id );
		return [
			'event_id'     => $event_id,
			'variation_id' => $this->product_sync()->variation_for_tier( $event_id, $tiers[0]['id'] ),
		];
	}

	/**
	 * A processing order with one attendee on one line. $renewal_subs, when
	 * given, registers the order as a renewal of those subscriptions BEFORE it
	 * turns processing: the status change itself runs reconcile.
	 */
	private function paid_order( $variation_id, ?array $renewal_subs = null ) {
		$item = new WC_Order_Item_Product();
		$item->set_product( wc_get_product( $variation_id ) );
		$item->set_quantity( 1 );
		$item->set_subtotal( 10 );
		$item->set_total( 10 );
		$item->add_meta_data( '_anchor_attendees', [ 1 => [ 'name' => 'Dr A', 'email' => 'a@example.test', 'phone' => '' ] ], true );

		$order = new WC_Order();
		$order->add_item( $item );
		$order->set_billing_email( 'a@example.test' );
		$order->calculate_totals( false );
		$order->save();
		if ( null !== $renewal_subs ) {
			$GLOBALS['anchor_test_wcs_renewals'][ $order->get_id() ] = $renewal_subs;
		}
		$order->set_status( 'processing' );
		$order->save();
		return $order->get_id();
	}

	public function test_installment_detection() {
		$this->assertTrue( Subscription_Renewals::is_installment_plan( [ new Anchor_Test_Subscription_Stub( time() + DAY_IN_SECONDS * 60 ) ] ) );
		$this->assertFalse( Subscription_Renewals::is_installment_plan( [ new Anchor_Test_Subscription_Stub( 0 ) ] ) );
		$this->assertFalse( Subscription_Renewals::is_installment_plan( [] ) );
	}

	/** First order holds the seat; the two installment renewals add none. */
	public function test_installment_renewals_create_no_seats() {
		$ctx = $this->paid_event();
		$sub = new Anchor_Test_Subscription_Stub( time() + DAY_IN_SECONDS * 60 );

		$first = $this->paid_order( $ctx['variation_id'] );
		$this->woocommerce()->reconcile_order( wc_get_order( $first ), 'initial' );

		foreach ( [ 1, 2 ] as $n ) {
			$renewal = $this->paid_order( $ctx['variation_id'], [ $sub ] );
			$this->woocommerce()->reconcile_order( wc_get_order( $renewal ), 'renewal ' . $n );
		}

		$this->assertSame( 1, $this->count_seats( $ctx['event_id'], Registrations::STATUS_CONFIRMED ) );
	}

	/** An open-ended subscription renewal still creates its seat (unchanged behavior). */
	public function test_open_ended_renewal_still_creates_seat() {
		$ctx     = $this->paid_event();
		$renewal = $this->paid_order( $ctx['variation_id'], [ new Anchor_Test_Subscription_Stub( 0 ) ] );

		$this->woocommerce()->reconcile_order( wc_get_order( $renewal ), 'renewal' );

		$this->assertSame( 1, $this->count_seats( $ctx['event_id'], Registrations::STATUS_CONFIRMED ) );
	}

	/** The filter can opt an installment renewal back into seat creation. */
	public function test_filter_overrides_default() {
		$ctx     = $this->paid_event();
		add_filter( 'anchor_events_renewal_creates_seats', '__return_true' );
		$renewal = $this->paid_order( $ctx['variation_id'], [ new Anchor_Test_Subscription_Stub( time() + DAY_IN_SECONDS ) ] );

		$this->woocommerce()->reconcile_order( wc_get_order( $renewal ), 'renewal' );

		$this->assertSame( 1, $this->count_seats( $ctx['event_id'], Registrations::STATUS_CONFIRMED ) );
	}

	/** A renewal seat that already exists (pre-fix, or via the filter) is still released on cancel. */
	public function test_existing_renewal_seat_still_reconciles() {
		$ctx = $this->paid_event();
		add_filter( 'anchor_events_renewal_creates_seats', '__return_true' );
		$renewal = $this->paid_order( $ctx['variation_id'], [ new Anchor_Test_Subscription_Stub( time() + DAY_IN_SECONDS ) ] );
		$this->assertSame( 1, $this->count_seats( $ctx['event_id'], Registrations::STATUS_CONFIRMED ) );

		remove_all_filters( 'anchor_events_renewal_creates_seats' );
		$order = wc_get_order( $renewal );
		$order->set_status( 'cancelled' );
		$order->save();

		$this->assertSame( 0, $this->count_seats( $ctx['event_id'], Registrations::STATUS_CONFIRMED ) );
	}
}
