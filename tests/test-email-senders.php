<?php
/**
 * Every sender resolves its copy and its on/off switch the same way.
 *
 * Four defects sat in the gap between the senders (REG-D1, D7, D8, D45):
 *
 * - The free/manual confirmation send hard-coded its subject and read the
 *   site-wide intro, so the per-event overrides the Emails builder writes were
 *   applied by the WooCommerce sender and ignored by the free one (REG-D1).
 * - "Preview with real data" resolved those same overrides, so an author could
 *   preview their copy, save, and watch free registrants receive the old
 *   wording with nothing reporting the divergence (REG-D45).
 * - The per-event "confirmation" switch also silenced the site's own internal
 *   "New registration" notice, which has its own `notify_admin` setting (REG-D8).
 * - The WooCommerce organizer notice never consulted the switch at all, so a
 *   type turned off still rendered and sent — filter and all (REG-D7).
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Module;
use Anchor\Events\Registrations;

/**
 * @group email
 */
class Test_Email_Senders extends Anchor_Events_TestCase {

	/** Every wp_mail() call made during the test (the raw $atts array). */
	private $sent = [];

	/** Whether anchor_events_registration_email_html fired. */
	private $filter_fired = false;

	public function set_up() {
		parent::set_up();
		$this->sent         = [];
		$this->filter_fired = false;
		add_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], 10, 2 );
		add_filter( 'anchor_events_registration_email_html', [ $this, 'flag_filter' ], 10, 2 );
	}

	public function tear_down() {
		remove_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], 10 );
		remove_filter( 'anchor_events_registration_email_html', [ $this, 'flag_filter' ], 10 );
		delete_option( Module::OPTION_KEY );
		parent::tear_down();
	}

	/** Short-circuit wp_mail() and record what would have been sent. */
	public function capture_mail( $short_circuit, $atts ) {
		$this->sent[] = $atts;
		return true;
	}

	/** Record that the render filter ran; leave the HTML alone. */
	public function flag_filter( $html, $ctx = [] ) {
		$this->filter_fired = true;
		return $html;
	}

	/** Merge overrides into the stored settings (defaults for everything else). */
	private function set_settings( array $overrides ) {
		update_option( Module::OPTION_KEY, array_merge( $this->module()->get_settings(), $overrides ), false );
	}

	/** Write a per-event override for one email field. */
	private function set_email_field( $event_id, $type, $field, $value ) {
		update_post_meta( $event_id, '_anchor_event_email_' . $field . '_' . $type, $value );
	}

	/** Turn one email type off for one event, the way the builder does. */
	private function switch_email_off( $event_id, $type ) {
		update_post_meta( $event_id, '_anchor_event_email_off_' . $type, '1' );
	}

	/** The captured mails whose subject contains $needle. */
	private function mails_matching( $needle ) {
		return array_values(
			array_filter(
				$this->sent,
				static function ( $atts ) use ( $needle ) {
					return strpos( (string) $atts['subject'], $needle ) !== false;
				}
			)
		);
	}

	// -------------------------------------------------------------------------
	// REG-D51 — a refund has its own wording, not the cancellation copy with a
	// word swapped inside it.
	// -------------------------------------------------------------------------

	public function test_a_refund_uses_the_refund_wording_not_a_rewritten_cancellation() {
		$this->set_settings( [
			'notify_cancellation'  => true,
			// Cancellation copy that never says "cancelled" — the case the old
			// str_ireplace() left with no mention of a refund at all.
			'cancellation_subject' => 'Your seat for {event_title} has been released',
			'cancellation_intro'   => 'Sorry — your seat has been released.',
			'refund_subject'       => 'Your registration for {event_title} has been refunded',
			'refund_intro'         => 'Your registration has been refunded.',
		] );

		$event_id = $this->make_event( [ 'title' => 'Refund Course' ] );
		$seat_id  = $this->make_seat( $event_id, [ 'email' => 'refundee@example.org', 'name' => 'Ray Fund' ] );
		$this->registrations()->update_status( $seat_id, Registrations::STATUS_REFUNDED );

		$this->sent = [];
		$this->assertTrue( $this->module()->send_cancellation_email( $seat_id )->is_sent() );

		$mail = $this->sent[0] ?? null;
		$this->assertNotNull( $mail );
		$this->assertSame( 'Your registration for Refund Course has been refunded', $mail['subject'] );
		$this->assertStringContainsString( 'Your registration has been refunded.', (string) $mail['message'] );
		$this->assertStringNotContainsString( 'has been released', (string) $mail['message'] );
	}

	public function test_a_cancellation_still_uses_the_cancellation_wording() {
		$this->set_settings( [
			'notify_cancellation'  => true,
			'cancellation_subject' => 'Your seat for {event_title} has been released',
			'cancellation_intro'   => 'Sorry — your seat has been released.',
		] );

		$event_id = $this->make_event( [ 'title' => 'Refund Course' ] );
		$seat_id  = $this->make_seat( $event_id, [ 'email' => 'goner@example.org' ] );
		$this->registrations()->update_status( $seat_id, Registrations::STATUS_CANCELLED );

		$this->sent = [];
		$this->assertTrue( $this->module()->send_cancellation_email( $seat_id )->is_sent() );

		$this->assertSame( 'Your seat for Refund Course has been released', $this->sent[0]['subject'] );
	}

	// -------------------------------------------------------------------------
	// REG-D1 — the free/manual confirmation send honors the per-event overrides.
	// -------------------------------------------------------------------------

	/** A per-event confirmation subject reaches the free-path send. */
	public function test_free_send_uses_per_event_subject_override() {
		$this->set_settings( [ 'notify_admin' => false, 'notify_user' => true ] );
		$event_id = $this->make_event( [ 'title' => 'Suture Level III' ] );
		$this->set_email_field( $event_id, 'confirmation', 'subject', "You're in for {event_title}" );

		$this->module()->send_registration_emails( $event_id, 'Jane Doe', 'jane@example.org', 'confirmed' );

		$this->assertCount( 1, $this->sent, 'Exactly one attendee email should have been sent.' );
		$this->assertSame( "You're in for Suture Level III", $this->sent[0]['subject'] );
	}

	/** A per-event confirmation intro reaches the free-path send. */
	public function test_free_send_uses_per_event_intro_override() {
		$this->set_settings( [ 'notify_admin' => false, 'notify_user' => true ] );
		$event_id = $this->make_event( [ 'title' => 'Suture Level III' ] );
		$this->set_email_field( $event_id, 'confirmation', 'intro', 'Bring loupes and a packed lunch.' );

		$this->module()->send_registration_emails( $event_id, 'Jane Doe', 'jane@example.org', 'confirmed' );

		$this->assertCount( 1, $this->sent );
		$this->assertStringContainsString( 'Bring loupes and a packed lunch.', $this->sent[0]['message'] );
		$this->assertStringNotContainsString(
			$this->module()->get_settings()['confirmation_message'],
			$this->sent[0]['message'],
			'The site-wide confirmation message must not survive a per-event override.'
		);
	}

	/** With no override the send keeps its historical subject and intro. */
	public function test_free_send_without_override_keeps_its_defaults() {
		$this->set_settings( [ 'notify_admin' => false, 'notify_user' => true, 'confirmation_message' => 'See you there.' ] );
		$event_id = $this->make_event( [ 'title' => 'Suture Level III' ] );

		$this->module()->send_registration_emails( $event_id, 'Jane Doe', 'jane@example.org', 'confirmed' );

		$this->assertCount( 1, $this->sent );
		$this->assertSame( 'You are registered for Suture Level III', $this->sent[0]['subject'] );
		$this->assertStringContainsString( 'See you there.', $this->sent[0]['message'] );
	}

	// -------------------------------------------------------------------------
	// REG-D45 — the preview and the send must say the same thing.
	// -------------------------------------------------------------------------

	/**
	 * Render the confirmation preview for an event with overrides, then capture
	 * the real free-path send for the same event: the subject the builder shows
	 * and the opening lines the preview renders must both be what ships.
	 */
	public function test_preview_and_free_send_agree_on_confirmation_copy() {
		$this->set_settings( [ 'notify_admin' => false, 'notify_user' => true ] );
		$event_id = $this->make_event( [ 'title' => 'Suture Level III' ] );
		$subject  = 'Your seat at {event_title} is held';
		$intro    = 'Welcome to {event_title} — here is what to bring.';
		$this->set_email_field( $event_id, 'confirmation', 'subject', $subject );
		$this->set_email_field( $event_id, 'confirmation', 'intro', $intro );

		$expected_subject = 'Your seat at Suture Level III is held';
		$expected_intro   = 'Welcome to Suture Level III — here is what to bring.';

		// What the author sees in "Preview with real data".
		$preview = $this->module()->render_email_preview_html( $event_id, 'confirmation', '<div>{intro}</div>' );
		$this->assertStringContainsString( $expected_intro, $preview, 'The preview should render the per-event intro.' );

		// What a free registration actually sends.
		$this->module()->send_registration_emails( $event_id, 'Jane Doe', 'jane@example.org', 'confirmed' );

		$this->assertCount( 1, $this->sent );
		$this->assertSame( $expected_subject, $this->sent[0]['subject'], 'Preview and send disagree on the subject.' );
		$this->assertStringContainsString(
			$expected_intro,
			$this->sent[0]['message'],
			'Preview and send disagree on the opening lines.'
		);
	}

	// -------------------------------------------------------------------------
	// REG-D8 — the admin notice answers to notify_admin, nothing else.
	// -------------------------------------------------------------------------

	/** Confirmation switched off silences the attendee email, not the admin notice. */
	public function test_confirmation_switch_off_leaves_the_admin_notice_alone() {
		$this->set_settings( [ 'notify_admin' => true, 'notify_user' => true, 'admin_email' => 'ops@example.org' ] );
		$event_id = $this->make_event( [ 'title' => 'Suture Level III' ] );
		$this->switch_email_off( $event_id, 'confirmation' );

		$this->module()->send_registration_emails( $event_id, 'Jane Doe', 'jane@example.org', 'confirmed' );

		$this->assertCount( 1, $this->sent, 'Only the internal admin notice should have been sent.' );
		$this->assertSame( 'ops@example.org', $this->sent[0]['to'] );
		$this->assertSame( 'New registration for Suture Level III', $this->sent[0]['subject'] );
		$this->assertFalse( $this->filter_fired, 'No attendee email should have been rendered.' );
	}

	/** The admin notice is still governed by its own setting. */
	public function test_admin_notice_respects_notify_admin() {
		$this->set_settings( [ 'notify_admin' => false, 'notify_user' => false ] );
		$event_id = $this->make_event( [ 'title' => 'Suture Level III' ] );

		$this->module()->send_registration_emails( $event_id, 'Jane Doe', 'jane@example.org', 'confirmed' );

		$this->assertSame( [], $this->sent, 'Both switches off should send nothing.' );
	}

	// -------------------------------------------------------------------------
	// REG-D7 — the WooCommerce organizer notice consults the switch too.
	// -------------------------------------------------------------------------

	/** A paid event with one tier, synced to a variation. */
	private function paid_event() {
		$event_id = $this->make_event(
			[ 'title' => 'Paid Event', 'capacity' => 0 ],
			[ [ 'label' => 'General', 'price' => '10', 'active' => 1 ] ]
		);
		$this->product_sync()->sync_event( $event_id );
		$tiers   = $this->ticket_types()->get( $event_id );
		$tier_id = $tiers[0]['id'];

		return [
			'event_id'     => $event_id,
			'item_id'      => 0,
			'variation_id' => $this->product_sync()->variation_for_tier( $event_id, $tier_id ),
		];
	}

	/**
	 * An unpaid order for $qty seats of $variation_id. Left in 'pending' on
	 * purpose: the 'processing' transition fires WooCommerce's own status hook,
	 * which reconciles (and therefore sends) synchronously — so the test has to
	 * control exactly when that happens. See place_order() below.
	 */
	private function make_order( $variation_id, $qty = 1 ) {
		$item = new WC_Order_Item_Product();
		$item->set_product( wc_get_product( $variation_id ) );
		$item->set_quantity( $qty );
		$item->set_subtotal( 10 * $qty );
		$item->set_total( 10 * $qty );
		$attendees = [];
		for ( $i = 1; $i <= $qty; $i++ ) {
			$attendees[ $i ] = [ 'name' => 'Attendee ' . $i, 'email' => 'attendee' . $i . '@example.test' ];
		}
		$item->add_meta_data( '_anchor_attendees', $attendees, true );

		$order = new WC_Order();
		$order->add_item( $item );
		$order->set_billing_email( 'buyer@example.test' );
		$order->set_billing_first_name( 'Buyer' );
		$order->calculate_totals( false );
		$order->save();

		return [ 'order' => $order, 'item_id' => $item->get_id() ];
	}

	/** Move the order to 'processing' — this is what creates the seats and sends. */
	private function place_order( \WC_Order $order ) {
		$order->set_status( 'processing' );
		$order->save();
	}

	/** Forget everything captured so far, so the next assertion sees one pass only. */
	private function reset_capture() {
		$this->sent         = [];
		$this->filter_fired = false;
	}

	/** Confirmation switched off: the organizer's "new registration" notice never renders. */
	public function test_organizer_notice_skipped_when_confirmation_disabled() {
		$this->require_wc();
		$this->set_settings( [ 'wc_notify_customer' => false, 'wc_notify_organizer' => true, 'organizer_email' => 'organizer@example.org' ] );
		$ctx = $this->paid_event();
		$this->switch_email_off( $ctx['event_id'], 'confirmation' );
		$res = $this->make_order( $ctx['variation_id'] );

		$this->reset_capture();
		$this->place_order( $res['order'] );

		$this->assertSame( 1, $this->count_seats( $ctx['event_id'], Registrations::STATUS_CONFIRMED ), 'The seat should still be created.' );
		// WooCommerce sends its own "Processing order" email on this transition;
		// only the events module's organizer notice is under test here.
		$this->assertSame( [], $this->mails_matching( 'New event registration' ), 'A disabled confirmation must not send an organizer notice.' );
		$this->assertFalse( $this->filter_fired, 'The render pipeline must not run behind a disabled switch.' );
	}

	/** Confirmation left on: the organizer notice still sends (the gate is not over-firing). */
	public function test_organizer_notice_sends_when_confirmation_enabled() {
		$this->require_wc();
		$this->set_settings( [ 'wc_notify_customer' => false, 'wc_notify_organizer' => true, 'organizer_email' => 'organizer@example.org' ] );
		$ctx = $this->paid_event();
		$res = $this->make_order( $ctx['variation_id'] );

		$this->reset_capture();
		$this->place_order( $res['order'] );

		$this->assertNotEmpty( $this->mails_matching( 'New event registration' ), 'The organizer notice should still send.' );
	}

	/** Cancellation switched off: the "seats released" organizer notice never renders. */
	public function test_released_organizer_notice_skipped_when_cancellation_disabled() {
		$this->require_wc();
		$this->set_settings( [ 'wc_notify_customer' => false, 'wc_notify_organizer' => false, 'organizer_email' => 'organizer@example.org' ] );
		$ctx      = $this->paid_event();
		$res      = $this->make_order( $ctx['variation_id'] );
		$order_id = $res['order']->get_id();
		$this->place_order( $res['order'] );
		$this->assertSame( 1, $this->count_seats( $ctx['event_id'], Registrations::STATUS_CONFIRMED ) );

		$this->set_settings( [ 'wc_notify_customer' => false, 'wc_notify_organizer' => true, 'organizer_email' => 'organizer@example.org' ] );
		$this->switch_email_off( $ctx['event_id'], 'cancellation' );

		$this->reset_capture();
		$refund = wc_create_refund(
			[
				'order_id'   => $order_id,
				'amount'     => 10,
				'line_items' => [ $res['item_id'] => [ 'qty' => 1, 'refund_total' => 10 ] ],
			]
		);
		$this->assertNotWPError( $refund );
		$this->woocommerce()->reconcile_order( wc_get_order( $order_id ), 'refund' );
		$this->module()->flush_cancellation_emails();

		$this->assertSame( [], $this->mails_matching( 'Seats released' ), 'A disabled cancellation must not send an organizer notice.' );
		$this->assertFalse( $this->filter_fired, 'The render pipeline must not run behind a disabled switch.' );
	}

	/** Cancellation left on: the "seats released" notice still sends. */
	public function test_released_organizer_notice_sends_when_cancellation_enabled() {
		$this->require_wc();
		$this->set_settings( [ 'wc_notify_customer' => false, 'wc_notify_organizer' => false, 'organizer_email' => 'organizer@example.org' ] );
		$ctx      = $this->paid_event();
		$res      = $this->make_order( $ctx['variation_id'] );
		$order_id = $res['order']->get_id();
		$this->place_order( $res['order'] );

		$this->set_settings( [ 'wc_notify_customer' => false, 'wc_notify_organizer' => true, 'organizer_email' => 'organizer@example.org' ] );

		$this->reset_capture();
		$refund = wc_create_refund(
			[
				'order_id'   => $order_id,
				'amount'     => 10,
				'line_items' => [ $res['item_id'] => [ 'qty' => 1, 'refund_total' => 10 ] ],
			]
		);
		$this->assertNotWPError( $refund );
		$this->woocommerce()->reconcile_order( wc_get_order( $order_id ), 'refund' );

		$this->assertNotEmpty( $this->mails_matching( 'Seats released' ), 'The seats-released notice should still send.' );
	}
}
