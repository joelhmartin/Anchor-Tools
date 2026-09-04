<?php
/**
 * Buyer confirmation / organizer notice seat collection (WOO-D44, WOO-D51).
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Events_Log;
use Anchor\Events\Registrations;

/**
 * @group woocommerce
 */
class Test_WooCommerce_Confirmation_Emails extends Anchor_Events_TestCase {

	public function set_up() {
		parent::set_up();
		$this->require_wc();
	}

	/** collect_order_seats() via reflection (private on purpose — internal only). */
	private function collect_order_seats( $order_id ) {
		$method = new ReflectionMethod( get_class( $this->woocommerce() ), 'collect_order_seats' );
		$method->setAccessible( true );
		return $method->invoke( $this->woocommerce(), $order_id );
	}

	/** send_customer_confirmation() via reflection, returning [Outcome, primary_id]. */
	private function send_customer_confirmation( \WC_Order $order ) {
		$method = new ReflectionMethod( get_class( $this->woocommerce() ), 'send_customer_confirmation' );
		$method->setAccessible( true );
		$primary_id = 0;
		$outcome    = $method->invokeArgs( $this->woocommerce(), [ $order, $this->module()->get_settings(), &$primary_id ] );
		return [ $outcome, $primary_id ];
	}

	/**
	 * WOO-D44: a seat whose `_anchor_event_reg_status` meta was lost must NOT
	 * default into the active set as "confirmed" — that default is a guess
	 * presented as fact in the buyer confirmation and organizer notice. It is
	 * skipped and the order is flagged for review instead.
	 */
	public function test_a_status_less_seat_is_skipped_and_flagged_not_guessed() {
		$event_id = $this->make_event();
		$order    = new WC_Order();
		$order->save();
		$order_id = $order->get_id();

		$confirmed_seat = $this->make_seat( $event_id, [ 'order_id' => $order_id ] );
		$broken_seat    = $this->make_seat( $event_id, [ 'order_id' => $order_id ] );
		delete_post_meta( $broken_seat, '_anchor_event_reg_status' );
		$this->assertSame( '', get_post_meta( $broken_seat, '_anchor_event_reg_status', true ) );

		$by_event = $this->collect_order_seats( $order_id );

		$this->assertCount( 1, $by_event[ $event_id ], 'Only the seat with real status data counts.' );
		$this->assertSame( $confirmed_seat, $by_event[ $event_id ][0]['id'] );

		// HPOS-safe read (CRUD only) — order meta may not live in wp_postmeta.
		$flags = (array) wc_get_order( $order_id )->get_meta( Events_Log::ORDER_REVIEW_META );
		$this->assertNotEmpty( $flags );
		$this->assertSame( 'seat_missing_status', $flags[0]['reason'] );
	}

	/**
	 * WOO-D44 (fix round 1, minor): flag_review() dedupes by reason and
	 * no-ops once a reason is already flagged, so calling it inside the
	 * per-seat loop only ever recorded the FIRST status-less seat's id — a
	 * second one on the same order was silently dropped from the detail.
	 * Every status-less seat this pass finds must be accumulated into ONE
	 * flag_review() call.
	 */
	public function test_every_status_less_seat_id_is_accumulated_into_one_flag() {
		$event_id = $this->make_event();
		$order    = new WC_Order();
		$order->save();
		$order_id = $order->get_id();

		$broken_one = $this->make_seat( $event_id, [ 'order_id' => $order_id ] );
		$broken_two = $this->make_seat( $event_id, [ 'order_id' => $order_id ] );
		delete_post_meta( $broken_one, '_anchor_event_reg_status' );
		delete_post_meta( $broken_two, '_anchor_event_reg_status' );

		$this->collect_order_seats( $order_id );

		$flags = (array) wc_get_order( $order_id )->get_meta( Events_Log::ORDER_REVIEW_META );
		$this->assertCount( 1, $flags, 'One flag for the order, not one per seat.' );
		$this->assertStringContainsString( (string) $broken_one, $flags[0]['detail'] );
		$this->assertStringContainsString( (string) $broken_two, $flags[0]['detail'] );
	}

	/**
	 * WOO-D51: the buyer confirmation's "primary event" (whose title/CTA/
	 * per-event overrides drive the whole email) must be the event with the
	 * MOST seats, not silently whichever event's seats were iterated first —
	 * an order spanning a container and a real occurrence used to report the
	 * container's title, roster and remaining capacity for the WHOLE order.
	 */
	public function test_primary_event_is_the_one_with_the_most_seats_not_the_first_iterated() {
		$small_event = $this->make_event( [ 'title' => 'Small Event' ] );
		$big_event   = $this->make_event( [ 'title' => 'Big Event' ] );
		$order_id    = 4343;

		// Seeded so the SMALL event's seat is created (and thus iterated)
		// first, and the BIG event — the one with more seats — second.
		$this->make_seat( $small_event, [ 'order_id' => $order_id ] );
		$this->make_seat( $big_event, [ 'order_id' => $order_id ] );
		$this->make_seat( $big_event, [ 'order_id' => $order_id ] );
		$this->make_seat( $big_event, [ 'order_id' => $order_id ] );

		$order = new WC_Order();
		$order->set_id( $order_id );
		$order->set_billing_email( 'buyer@example.test' );

		list( $outcome, $primary_id ) = $this->send_customer_confirmation( $order );

		$this->assertTrue( $outcome->is_sent(), 'Response: ' . $outcome->reason() );
		$this->assertSame( $big_event, $primary_id, 'The event with the most seats must be primary, not the first one iterated.' );
	}
}
