<?php
/**
 * render_registration_form() gating (RENDER-D1, WOO-D1, WOO-D19).
 *
 * Two independent guarantees:
 *
 *   1. `registration_enabled = false` suppresses ALL registration UI — the
 *      `anchor_events_registration_form` filter (WooCommerce's buy-button seam)
 *      must not be able to render a form for a disabled event.
 *   2. A `wc`-mode event whose storefront produced nothing must NEVER fall
 *      through to the free internal form; a ticketed course cannot give its
 *      seats away because WooCommerce is off or every tier is inactive.
 *
 * @package Anchor\Events\Tests
 *
 * @group registration
 */
class Test_Registration_Form_Gate extends Anchor_Events_TestCase {

	/**
	 * RENDER-D1 / WOO-D1: the render filter runs after the registration_enabled
	 * guard, so a callback on it cannot resurrect a form for a disabled event.
	 */
	public function test_filter_cannot_render_when_registration_disabled() {
		$event = $this->make_event( [ 'registration_enabled' => false, 'start_date' => '2030-10-23' ] );
		add_filter( 'anchor_events_registration_form', function () { return '<div class="fake-buy-button"></div>'; } );
		$html = $this->module()->render_registration_form( $event );
		remove_all_filters( 'anchor_events_registration_form' );
		$this->assertSame( '', $html );
	}

	/**
	 * WOO-D19: a paid (wc-mode) event whose storefront rendered nothing gets the
	 * closed notice, not the free internal form.
	 */
	public function test_wc_mode_without_storefront_never_falls_to_free_form() {
		$event = $this->make_event( [ 'registration_enabled' => true, 'registration_mode' => 'wc', 'start_date' => '2030-10-23' ] );
		// No tiers, no product, no filter callback: the storefront has nothing to render.
		$html = $this->module()->render_registration_form( $event );
		$this->assertStringNotContainsString( 'anchor_event_reg_nonce', $html );
		$this->assertStringContainsString( 'anchor-event-registration-closed', $html );
	}

	/**
	 * The WOO-D19 wc-mode guard must NOT swallow the mixed free+paid re-entry:
	 * WooCommerce::filter_registration_form() renders the paid storefront and
	 * then calls back into render_registration_form() (behind its
	 * `$rendering_free` flag) to append the inline form for the FREE tiers. That
	 * nested call is a wc-mode event by definition, so a naive
	 * `registration_mode === 'wc'` return would replace the free form with the
	 * "tickets unavailable" notice on an event that is actively selling.
	 */
	public function test_mixed_free_and_paid_event_still_appends_the_free_form() {
		$this->require_wc();

		$event = $this->make_event(
			[ 'registration_enabled' => true, 'registration_mode' => 'wc', 'start_date' => '2030-10-23' ],
			[
				[ 'label' => 'General', 'price' => '25', 'active' => 1 ],
				[ 'label' => 'Free seat', 'price' => '0', 'active' => 1 ],
			]
		);
		$this->product_sync()->sync_event( $event );

		$html = $this->module()->render_registration_form( $event );

		$this->assertStringContainsString( 'anchor-event-tickets', $html, 'The paid storefront must still render.' );
		$this->assertStringContainsString( 'anchor-event-free-registration', $html, 'The free-tier form must still be appended.' );
		$this->assertStringContainsString( 'anchor_event_reg_nonce', $html, 'The appended free form must be a real form.' );
		$this->assertStringNotContainsString( 'Tickets are not available right now.', $html );
	}

	/**
	 * Fix round 1, finding 1: a `wc`-mode event whose only ACTIVE tier is a free
	 * one is a bookable FREE course, not a ticketed one, so the WOO-D19 notice
	 * must not fire. WooCommerce::filter_registration_form() returns $html
	 * unchanged here ($paid_active is empty, no legacy product), and
	 * $rendering_free is never set, so only the free-tier predicate keeps the
	 * form alive.
	 */
	public function test_wc_mode_with_an_active_free_tier_still_renders_the_free_form() {
		$event = $this->make_event(
			[ 'registration_enabled' => true, 'registration_mode' => 'wc', 'start_date' => '2030-10-23' ],
			[ [ 'label' => 'Free seat', 'price' => '0', 'active' => 1 ] ]
		);

		$html = $this->module()->render_registration_form( $event );

		$this->assertStringContainsString( 'anchor_event_reg_nonce', $html );
		$this->assertStringNotContainsString( 'Tickets are not available right now.', $html );
	}

	/**
	 * The companion to the test above, and the reason its predicate is
	 * "AUTHORED active free tier" rather than "active free tier": an event with
	 * NO tier rows at all gets an implicit primary tier synthesized by
	 * Ticket_Types::get() at price 0 / active, which must NOT read as a free
	 * course. This is WOO-D19 case (a)/(b) — the no-tiers, no-product wc event —
	 * and it is covered by test 2 above; this test pins the discriminator
	 * directly so a future widening of the predicate fails loudly here.
	 */
	public function test_synthesized_primary_tier_does_not_count_as_a_free_course() {
		$event = $this->make_event( [ 'registration_enabled' => true, 'registration_mode' => 'wc', 'start_date' => '2030-10-23' ] );

		// Sanity: the plain helper DOES report a free tier for this event — the
		// synthesized primary. The guard must not be fooled by it.
		$this->assertNotEmpty(
			$this->module()->get_active_free_tiers( $event ),
			'Fixture assumption: Ticket_Types::get() synthesizes an active free primary tier when nothing is authored.'
		);
		$this->assertSame( '', get_post_meta( $event, \Anchor\Events\Ticket_Types::META_KEY, true ), 'Fixture must have no authored tier rows.' );

		$html = $this->module()->render_registration_form( $event );

		$this->assertStringContainsString( 'Tickets are not available right now.', $html );
	}

	/* ------------------------------------------------------------------
	 * NEW-D2 — the switch suppresses the FORM, not the fact.
	 *
	 * `registration_enabled = false` still means no booking UI of any kind.
	 * But a course that is sold out or over is sold out or over whether or not
	 * the switch is on, and rendering nothing at all told the visitor less
	 * than the truth (production child 7528). The form now mirrors
	 * bookability()'s branch order: the seat-layer verdict is stated first,
	 * and only an otherwise-bookable disabled event renders nothing.
	 * ------------------------------------------------------------------ */

	/** Sold out with the switch off says so rather than rendering nothing. */
	public function test_sold_out_event_says_so_even_with_registration_off() {
		$event = $this->make_event( [
			'registration_enabled' => false,
			'sold_out'             => true,
			'start_date'           => '2030-10-23',
		] );

		$html = $this->module()->render_registration_form( $event );

		$this->assertStringContainsString( 'This event is full.', $html );
		$this->assertStringNotContainsString( 'anchor_event_reg_nonce', $html, 'The free form stays suppressed.' );
	}

	/** A finished event with the switch off says registration is closed. */
	public function test_finished_event_with_registration_off_says_closed() {
		$event = $this->make_event( [
			'registration_enabled' => false,
			'start_date'           => '2020-01-01',
			'end_date'             => '2020-01-01',
			'timezone'             => 'UTC',
		] );
		$ts = $this->module()->compute_timestamps( $this->module()->get_meta( $event ) );
		update_post_meta( $event, '_anchor_event_start_ts', $ts['start'] );
		update_post_meta( $event, '_anchor_event_end_ts', $ts['end'] );

		$html = $this->module()->render_registration_form( $event );

		$this->assertStringContainsString( 'Registration is closed.', $html );
		$this->assertStringNotContainsString( 'anchor_event_reg_nonce', $html );
	}

	/** An otherwise-bookable disabled event still renders nothing at all. */
	public function test_a_bookable_event_with_the_switch_off_still_renders_nothing() {
		$event = $this->make_event( [ 'registration_enabled' => false, 'start_date' => '2030-10-23' ] );

		$this->assertSame( '', $this->module()->render_registration_form( $event ) );
	}
}
