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
}
