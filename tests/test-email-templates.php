<?php
/**
 * Task 3.1 — email template model + render refactor tests.
 *
 * Lifecycle email BODIES moved from hardcoded PHP into stored, tokenized,
 * per-type templates (confirmation|reminder|cancellation|roster) resolved
 * per-event override -> global default option -> default constant. This
 * suite proves the refactor is byte-equivalent to the pre-refactor output
 * (the crux requirement) and exercises the new resolver + token expansion
 * + per-sender type routing.
 *
 * The five `expected_*()` fixtures below were captured from the UNMODIFIED
 * pre-refactor build_registration_email_html() (git-stashed the refactor,
 * ran the exact same $ctx inputs used in the byte-equivalence tests here,
 * and inlined the raw output). Together they exercise every conditional
 * region of the shared email shell:
 *   - header_image:    present (A, E) / absent (B, C, D)
 *   - greeting:         present (A, B, C, D) / absent (E — empty name, as
 *                        the real roster sender always passes)
 *   - guests_line:      present (A) / absent (B, C, D, E)
 *   - waitlist_notice:  present (B) / absent (A, C, D, E)
 *   - detail_rows:      present (A, C, D, E) / absent (B)
 *   - seat_list:        present (B, E) / absent (A, C, D)
 *   - join_button:      present (A, C, E — virtual + confirmed) / absent (B, D)
 *   - cta_button:       present (A, B, C, E) / absent (D — cta_label/url both '')
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Module;
use Anchor\Events\Registrations;

/**
 * @group email
 */
class Test_Email_Templates extends Anchor_Events_TestCase {

	/** Captured (type, template) pairs from anchor_events_registration_email_html. */
	private $captured_types = [];

	public function set_up() {
		parent::set_up();
		$this->captured_types = [];
	}

	/** Records $ctx['type'] on every render, then passes $html through unchanged. */
	private function start_type_capture() {
		add_filter( 'anchor_events_registration_email_html', [ $this, 'capture_type' ], 10, 2 );
	}

	private function stop_type_capture() {
		remove_filter( 'anchor_events_registration_email_html', [ $this, 'capture_type' ], 10 );
	}

	public function capture_type( $html, $ctx ) {
		$this->captured_types[] = $ctx['type'] ?? '';
		return $html;
	}

	/* ---------------------------------------------------------------------
	 * Byte-equivalence: the crux. Same $ctx as the pre-refactor capture run,
	 * no per-event override and no global option set, so resolve_email_template()
	 * falls through to the default constant — the refactored renderer must
	 * produce EXACTLY the same bytes as the original hardcoded shell.
	 * ------------------------------------------------------------------- */

	public function test_byte_equivalence_confirmation() {
		$event_id = $this->make_event( [ 'virtual' => true, 'virtual_url' => 'https://example.test/join-a' ] );
		$attachment_id = self::factory()->attachment->create_object( [ 'file' => 'image-a.jpg', 'post_parent' => 0, 'post_mime_type' => 'image/jpeg', 'post_type' => 'attachment' ] );
		set_post_thumbnail( $event_id, $attachment_id );
		$ctx = [
			'event_id'      => $event_id,
			'name'          => 'Jane Doe',
			'status'        => Registrations::STATUS_CONFIRMED,
			'intro_message' => "Thanks for joining us!\n\nWe can't wait to see you there.",
			'guests'        => 2,
			'detail_rows'   => [
				[ 'label' => 'Date', 'value' => 'January 5, 2027' ],
				[ 'label' => 'Time', 'value' => '6:00 pm' ],
			],
			'seat_list'     => [],
			'cta_label'     => 'View event details',
			'cta_url'       => 'https://example.test/event/test-event-a/',
			'type'          => 'confirmation',
		];
		$this->assertSame( $this->expected_confirmation(), $this->module()->build_registration_email_html( $ctx ) );
	}

	public function test_byte_equivalence_confirmation_waitlist() {
		$event_id = $this->make_event( [ 'virtual' => false ] );
		$ctx = [
			'event_id'      => $event_id,
			'name'          => 'Sam Waitperson',
			'status'        => Registrations::STATUS_WAITLIST,
			'intro_message' => 'You have been added to the waitlist.',
			'guests'        => 0,
			'detail_rows'   => [],
			'seat_list'     => [ 'Sam Waitperson — sam@example.test' ],
			'cta_label'     => 'View event details',
			'cta_url'       => 'https://example.test/event/test-event-b/',
			'type'          => 'confirmation',
		];
		$this->assertSame( $this->expected_confirmation_waitlist(), $this->module()->build_registration_email_html( $ctx ) );
	}

	public function test_byte_equivalence_reminder() {
		$event_id = $this->make_event( [ 'virtual' => true, 'virtual_url' => 'https://example.test/join-c' ] );
		$ctx = [
			'event_id'      => $event_id,
			'name'          => 'Riley Reminder',
			'status'        => Registrations::STATUS_CONFIRMED,
			'intro_message' => 'This is a friendly reminder that you are registered for Test Event on January 9, 2027. We look forward to seeing you.',
			'detail_rows'   => [
				[ 'label' => 'Date', 'value' => 'January 9, 2027' ],
				[ 'label' => 'Time', 'value' => '7:00 pm' ],
				[ 'label' => 'Location', 'value' => 'Online' ],
			],
			'cta_label'     => 'View event details',
			'cta_url'       => 'https://example.test/event/test-event-c/',
			'type'          => 'reminder',
		];
		$this->assertSame( $this->expected_reminder(), $this->module()->build_registration_email_html( $ctx ) );
	}

	public function test_byte_equivalence_cancellation() {
		$event_id = $this->make_event( [ 'virtual' => false ] );
		$ctx = [
			'event_id'      => $event_id,
			'name'          => 'Casey Cancelled',
			'status'        => Registrations::STATUS_CANCELLED,
			'intro_message' => 'Your registration for Test Event has been cancelled. If this is unexpected, please contact us.',
			'detail_rows'   => [
				[ 'label' => 'Event', 'value' => 'Test Event' ],
				[ 'label' => 'Date', 'value' => 'January 12, 2027' ],
			],
			'cta_label'     => '',
			'cta_url'       => '',
			'type'          => 'cancellation',
		];
		$this->assertSame( $this->expected_cancellation(), $this->module()->build_registration_email_html( $ctx ) );
	}

	public function test_byte_equivalence_roster() {
		$event_id = $this->make_event( [ 'virtual' => true, 'virtual_url' => 'https://example.test/join-e' ] );
		$attachment_id = self::factory()->attachment->create_object( [ 'file' => 'image-e.jpg', 'post_parent' => 0, 'post_mime_type' => 'image/jpeg', 'post_type' => 'attachment' ] );
		set_post_thumbnail( $event_id, $attachment_id );
		$ctx = [
			'event_id'      => $event_id,
			'name'          => '',
			'status'        => Registrations::STATUS_CONFIRMED,
			'intro_message' => 'Here is the current confirmed roster for Test Event on January 15, 2027.',
			'detail_rows'   => [
				[ 'label' => 'Date', 'value' => 'January 15, 2027' ],
				[ 'label' => 'Venue', 'value' => 'Online' ],
				[ 'label' => 'Capacity', 'value' => '50' ],
				[ 'label' => 'Confirmed', 'value' => '12' ],
				[ 'label' => 'Waitlist', 'value' => '0' ],
				[ 'label' => 'Remaining', 'value' => '38' ],
			],
			'seat_list'     => [
				'Alice — alice@example.test — 555-1111 (web)',
				'Bob — bob@example.test',
			],
			'cta_label'     => 'Open full roster',
			'cta_url'       => 'https://example.test/roster/fixed-id',
			'type'          => 'roster',
		];
		$this->assertSame( $this->expected_roster(), $this->module()->build_registration_email_html( $ctx ) );
	}

	/* ---------------------------------------------------------------------
	 * resolve_email_template() precedence: per-event override > global
	 * option > default constant.
	 * ------------------------------------------------------------------- */

	public function test_resolve_falls_back_to_default_constant_when_nothing_is_set() {
		$event_id = $this->make_event();
		$resolved = $this->module()->resolve_email_template( 'confirmation', $event_id );
		$this->assertSame( $this->module()->default_email_template( 'confirmation' ), $resolved );
	}

	public function test_resolve_prefers_global_option_over_default() {
		$event_id = $this->make_event();
		update_option( 'anchor_events_email_tpl_confirmation', '<p>Global default: {event_title}</p>', false );
		try {
			$resolved = $this->module()->resolve_email_template( 'confirmation', $event_id );
			$this->assertSame( '<p>Global default: {event_title}</p>', $resolved );
		} finally {
			delete_option( 'anchor_events_email_tpl_confirmation' );
		}
	}

	public function test_resolve_prefers_per_event_override_over_global_option() {
		$event_id = $this->make_event();
		update_option( 'anchor_events_email_tpl_confirmation', '<p>Global default: {event_title}</p>', false );
		update_post_meta( $event_id, '_anchor_event_email_tpl_confirmation', '<p>Per-event override: {event_title}</p>' );
		try {
			$resolved = $this->module()->resolve_email_template( 'confirmation', $event_id );
			$this->assertSame( '<p>Per-event override: {event_title}</p>', $resolved );
		} finally {
			delete_option( 'anchor_events_email_tpl_confirmation' );
			delete_post_meta( $event_id, '_anchor_event_email_tpl_confirmation' );
		}
	}

	public function test_resolve_falls_back_when_per_event_override_meta_is_empty_string() {
		$event_id = $this->make_event();
		update_option( 'anchor_events_email_tpl_confirmation', '<p>Global default: {event_title}</p>', false );
		update_post_meta( $event_id, '_anchor_event_email_tpl_confirmation', '' ); // explicit empty = no override
		try {
			$resolved = $this->module()->resolve_email_template( 'confirmation', $event_id );
			$this->assertSame( '<p>Global default: {event_title}</p>', $resolved );
		} finally {
			delete_option( 'anchor_events_email_tpl_confirmation' );
			delete_post_meta( $event_id, '_anchor_event_email_tpl_confirmation' );
		}
	}

	public function test_resolve_is_per_type_independent() {
		$event_id = $this->make_event();
		update_post_meta( $event_id, '_anchor_event_email_tpl_reminder', '<p>Reminder override</p>' );
		try {
			$this->assertSame( '<p>Reminder override</p>', $this->module()->resolve_email_template( 'reminder', $event_id ) );
			// The confirmation type on the SAME event must be unaffected.
			$this->assertSame( $this->module()->default_email_template( 'confirmation' ), $this->module()->resolve_email_template( 'confirmation', $event_id ) );
		} finally {
			delete_post_meta( $event_id, '_anchor_event_email_tpl_reminder' );
		}
	}

	/* ---------------------------------------------------------------------
	 * A per-event override template renders with scalar + block tokens
	 * expanded, exactly like the default template does.
	 * ------------------------------------------------------------------- */

	public function test_per_event_override_template_expands_tokens() {
		$event_id = $this->make_event( [ 'title' => 'Override Test Event' ] );
		update_post_meta(
			$event_id,
			'_anchor_event_email_tpl_confirmation',
			'<div id="custom"><h2>{event_title}</h2><p>Hello {attendee_name}</p>{detail_rows}{cta_button}</div>'
		);
		try {
			$ctx = [
				'event_id'      => $event_id,
				'name'          => 'Override Attendee',
				'status'        => Registrations::STATUS_CONFIRMED,
				'intro_message' => '',
				'detail_rows'   => [ [ 'label' => 'Date', 'value' => 'March 1, 2027' ] ],
				'seat_list'     => [],
				'cta_label'     => 'View',
				'cta_url'       => 'https://example.test/custom/',
				'type'          => 'confirmation',
			];
			$html = $this->module()->build_registration_email_html( $ctx );

			$this->assertStringContainsString( '<h2>Override Test Event</h2>', $html );
			$this->assertStringContainsString( 'Hello Override Attendee', $html );
			$this->assertStringContainsString( '>Date<', $html );
			$this->assertStringContainsString( '>March 1, 2027<', $html );
			$this->assertStringContainsString( 'https://example.test/custom/', $html );
			// cta_button's own markup places the label on its own line inside <a>...</a>,
			// not tight against either tag, so match the label text itself.
			$this->assertStringContainsString( 'View', $html );
			$this->assertStringContainsString( '<a href="https://example.test/custom/"', $html );
			// Nothing outside the custom markup — proves the DEFAULT shell was
			// NOT used once an override is in effect.
			$this->assertStringNotContainsString( '<!DOCTYPE html>', $html );
		} finally {
			delete_post_meta( $event_id, '_anchor_event_email_tpl_confirmation' );
		}
	}

	/* ---------------------------------------------------------------------
	 * Block tokens expand to the same structured HTML the original inline
	 * loops produced (spot-check independent of the full-shell fixtures).
	 * ------------------------------------------------------------------- */

	public function test_detail_rows_block_token_renders_all_rows() {
		$event_id = $this->make_event();
		update_post_meta( $event_id, '_anchor_event_email_tpl_confirmation', '{detail_rows}' );
		try {
			$ctx = [
				'event_id'      => $event_id,
				'name'          => '',
				'status'        => Registrations::STATUS_CONFIRMED,
				'intro_message' => '',
				'detail_rows'   => [
					[ 'label' => 'Row One', 'value' => 'Value One' ],
					[ 'label' => 'Row Two', 'value' => 'Value Two' ],
				],
				'seat_list'     => [],
				'cta_label'     => '',
				'cta_url'       => '',
				'type'          => 'confirmation',
			];
			$html = $this->module()->build_registration_email_html( $ctx );
			$this->assertStringContainsString( 'Row One', $html );
			$this->assertStringContainsString( 'Value One', $html );
			$this->assertStringContainsString( 'Row Two', $html );
			$this->assertStringContainsString( 'Value Two', $html );
			$this->assertSame( 2, substr_count( $html, '<tr>' ), 'Expected exactly one <tr> per detail row.' );
		} finally {
			delete_post_meta( $event_id, '_anchor_event_email_tpl_confirmation' );
		}
	}

	/* ---------------------------------------------------------------------
	 * The anchor_events_registration_email_html filter still fires on the
	 * final output, still receiving (html, ctx).
	 * ------------------------------------------------------------------- */

	public function test_filter_still_fires_on_final_html() {
		$event_id = $this->make_event();
		$seen = null;
		$cb = function ( $html, $ctx ) use ( &$seen ) {
			$seen = $ctx;
			return $html . '<!-- filtered -->';
		};
		add_filter( 'anchor_events_registration_email_html', $cb, 10, 2 );
		try {
			$html = $this->module()->build_registration_email_html( [
				'event_id' => $event_id,
				'type'     => 'confirmation',
			] );
			$this->assertStringEndsWith( '<!-- filtered -->', $html );
			$this->assertIsArray( $seen );
			$this->assertSame( 'confirmation', $seen['type'] );
		} finally {
			remove_filter( 'anchor_events_registration_email_html', $cb, 10 );
		}
	}

	/* ---------------------------------------------------------------------
	 * Every sender resolves the correct type.
	 * ------------------------------------------------------------------- */

	public function test_send_registration_emails_uses_confirmation_type() {
		$event_id = $this->make_event();
		$this->start_type_capture();
		try {
			$this->module()->send_registration_emails( $event_id, 'Free Attendee', 'free@example.test', Registrations::STATUS_CONFIRMED, 0 );
		} finally {
			$this->stop_type_capture();
		}
		$this->assertContains( 'confirmation', $this->captured_types );
	}

	public function test_send_reminder_email_uses_reminder_type() {
		$event_id = $this->make_event( [ 'start_ts' => time() + 2 * DAY_IN_SECONDS ] );
		$seat_id  = $this->make_seat( $event_id, [ 'email' => 'reminder@example.test' ] );
		$seat     = $this->registrations()->get_seat_info( $seat_id );
		$seat['name']  = 'Reminder Seat';
		$seat['email'] = 'reminder@example.test';

		$this->start_type_capture();
		try {
			$this->module()->send_reminder_email( $seat, $event_id, 1 );
		} finally {
			$this->stop_type_capture();
		}
		$this->assertSame( [ 'reminder' ], $this->captured_types );
	}

	public function test_send_cancellation_email_uses_cancellation_type() {
		$event_id = $this->make_event();
		$seat_id  = $this->make_seat( $event_id, [
			'status' => Registrations::STATUS_CANCELLED,
			'email'  => 'cancelled@example.test',
			'name'   => 'Cancelled Seat',
		] );

		$this->start_type_capture();
		try {
			$this->module()->send_cancellation_email( $seat_id );
		} finally {
			$this->stop_type_capture();
		}
		$this->assertSame( [ 'cancellation' ], $this->captured_types );
	}

	public function test_send_roster_email_uses_roster_type() {
		$event_id = $this->make_event();
		$this->make_seat( $event_id, [ 'email' => 'roster-seat@example.test' ] );

		$this->start_type_capture();
		try {
			$this->module()->send_roster_email( $event_id );
		} finally {
			$this->stop_type_capture();
		}
		$this->assertSame( [ 'roster' ], $this->captured_types );
	}

	/** WC customer confirmation + organizer new-registration notice both resolve 'confirmation'. */
	public function test_wc_confirmed_reconcile_uses_confirmation_type_for_both_notices() {
		$this->require_wc();

		$event_id = $this->make_event(
			[ 'title' => 'WC Type Routing Event', 'capacity' => 0 ],
			[ [ 'label' => 'General', 'price' => '10', 'active' => 1 ] ]
		);
		$this->product_sync()->sync_event( $event_id );
		$tiers        = $this->ticket_types()->get( $event_id );
		$variation_id = $this->product_sync()->variation_for_tier( $event_id, $tiers[0]['id'] );
		$variation    = wc_get_product( $variation_id );

		$item = new WC_Order_Item_Product();
		$item->set_product( $variation );
		$item->set_quantity( 1 );
		$item->set_subtotal( 10 );
		$item->set_total( 10 );
		$item->add_meta_data( '_anchor_attendees', [ 1 => [ 'name' => 'WC Attendee', 'email' => 'wc-attendee@example.test' ] ], true );

		$order = new WC_Order();
		$order->add_item( $item );
		$order->set_billing_email( 'wc-buyer@example.test' );
		$order->set_billing_first_name( 'Buyer' );
		$order->calculate_totals( false );
		$order->save();

		// The processing transition below fires WooCommerce's own status-change
		// hook synchronously, which calls reconcile_order() (and therefore
		// dispatch_emails()) automatically — the type capture must be attached
		// BEFORE that save(), or the notices render before this test ever sees them.
		$this->start_type_capture();
		try {
			$order->set_status( 'processing' );
			$order->save();
		} finally {
			$this->stop_type_capture();
		}

		$this->assertNotEmpty( $this->captured_types, 'Expected at least one lifecycle email to have been rendered.' );
		foreach ( $this->captured_types as $type ) {
			$this->assertSame( 'confirmation', $type, 'Both the customer confirmation and the organizer new-registration notice should resolve the confirmation type.' );
		}
	}

	/**
	 * Organizer "seats released" notice — fired by WooCommerce::send_organizer_notice()
	 * when $kind === 'released' (surplus active seats cancelled/refunded on
	 * reconcile) — must resolve the 'cancellation' template type, mirroring
	 * test_wc_confirmed_reconcile_uses_confirmation_type_for_both_notices above
	 * but driving a refund transition instead of the initial 'processing' one.
	 *
	 * The attendee-facing cancellation/refund email (send_cancellation_email(),
	 * queued on the live->refunded seat transition and flushed synchronously at
	 * the end of reconcile_order(), see class-woocommerce.php ~line 1689) also
	 * resolves 'cancellation', so every type captured during the refund pass is
	 * expected to be 'cancellation' — same assertion shape as the confirmed test.
	 */
	public function test_wc_released_reconcile_uses_cancellation_type_for_organizer_notice() {
		$this->require_wc();

		$event_id = $this->make_event(
			[ 'title' => 'WC Released Type Routing Event', 'capacity' => 0 ],
			[ [ 'label' => 'General', 'price' => '10', 'active' => 1 ] ]
		);
		$this->product_sync()->sync_event( $event_id );
		$tiers        = $this->ticket_types()->get( $event_id );
		$variation_id = $this->product_sync()->variation_for_tier( $event_id, $tiers[0]['id'] );
		$variation    = wc_get_product( $variation_id );

		$item = new WC_Order_Item_Product();
		$item->set_product( $variation );
		$item->set_quantity( 1 );
		$item->set_subtotal( 10 );
		$item->set_total( 10 );
		$item->add_meta_data( '_anchor_attendees', [ 1 => [ 'name' => 'WC Released Attendee', 'email' => 'wc-released@example.test' ] ], true );

		$order = new WC_Order();
		$order->add_item( $item );
		$order->set_billing_email( 'wc-released-buyer@example.test' );
		$order->set_billing_first_name( 'Buyer' );
		$order->calculate_totals( false );
		$order->save();
		$item_id = $item->get_id();

		// Move to processing first — creates the confirmed seat via the normal
		// 'confirmed' path (not under test here, so no capture needed yet).
		$order->set_status( 'processing' );
		$order->save();
		$this->assertSame( 1, $this->count_seats( $event_id, Registrations::STATUS_CONFIRMED ) );

		// Refund the single seat. Per the prior finding, WC's refund reconcile
		// fires synchronously (wc_create_refund() -> 'woocommerce_order_refunded'
		// -> on_order_refunded() -> reconcile_order() -> dispatch_emails() /
		// flush_cancellation_emails()), so the capture filter must be attached
		// BEFORE wc_create_refund() is called, not after.
		$this->start_type_capture();
		try {
			$refund = wc_create_refund(
				[
					'order_id'   => $order->get_id(),
					'amount'     => 10,
					'line_items' => [
						$item_id => [ 'qty' => 1, 'refund_total' => 10 ],
					],
				]
			);
			$this->assertNotWPError( $refund );
			// Mirrors tests/test-reconcile.php's refund pattern: explicitly drive
			// the refund reconcile rather than relying solely on the WC hook.
			$this->woocommerce()->on_order_refunded( $order->get_id(), $refund->get_id() );
		} finally {
			$this->stop_type_capture();
		}

		$this->assertSame( 1, $this->count_seats( $event_id, Registrations::STATUS_REFUNDED ) );
		$this->assertNotEmpty( $this->captured_types, 'Expected at least one lifecycle email to have been rendered for the released seat.' );
		foreach ( $this->captured_types as $type ) {
			$this->assertSame( 'cancellation', $type, 'The organizer seats-released notice (and any attendee refund notice) should resolve the cancellation template type.' );
		}
	}

	/* ---------------------------------------------------------------------
	 * Task 3.1 hardening: scalar tokens in the token map used by
	 * expand_email_tokens() inside build_registration_email_html() are
	 * output-escaped so a custom (admin-authored) per-event/global template
	 * cannot become a stored-injection vector via attendee/registration
	 * input that is only sanitize_text_field()'d upstream (tags stripped,
	 * but & and quotes left as literal characters).
	 * ------------------------------------------------------------------- */

	public function test_custom_template_scalar_tokens_are_output_escaped() {
		$event_id = $this->make_event( [ 'title' => 'Escaping Test Event' ] );
		update_post_meta(
			$event_id,
			'_anchor_event_email_tpl_confirmation',
			'<div id="custom">Hi {attendee_name} <a href="{event_url}">link</a></div>'
		);
		try {
			$malicious = '<b>x</b> & "q"';
			$ctx       = [
				'event_id'      => $event_id,
				'name'          => $malicious,
				'status'        => Registrations::STATUS_CONFIRMED,
				'intro_message' => '',
				'detail_rows'   => [],
				'seat_list'     => [],
				'cta_label'     => '',
				'cta_url'       => '',
				'type'          => 'confirmation',
			];
			$html = $this->module()->build_registration_email_html( $ctx );

			// {attendee_name} must render entity-escaped, not as a live tag.
			$this->assertStringNotContainsString( '<b>x</b>', $html );
			$this->assertStringContainsString( esc_html( $malicious ), $html );

			// {event_url} must be esc_url()'d.
			$expected_url = esc_url( get_permalink( $event_id ) );
			$this->assertStringContainsString( 'href="' . $expected_url . '"', $html );
		} finally {
			delete_post_meta( $event_id, '_anchor_event_email_tpl_confirmation' );
		}
	}

	private function expected_confirmation() {
		return <<<'EXPECTED_CONFIRMATION'
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8" />
            <meta name="viewport" content="width=device-width,initial-scale=1" />
            <title>Test Event</title>
        </head>
        <body style="margin:0;padding:0;background:#f4f4f4;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4;padding:24px 12px;">
                <tr>
                    <td align="center">
                        <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:8px;overflow:hidden;">
                                                        <tr>
                                <td style="padding:0;">
                                    <img src="http://example.org/wp-content/uploads/image-a.jpg" alt="Test Event" width="600" style="display:block;width:100%;max-width:600px;height:auto;border:0;" />
                                </td>
                            </tr>
                                                        <tr>
                                <td style="padding:28px 32px 8px;">
                                    <h1 style="margin:0;font-size:24px;line-height:1.3;color:#111;">Test Event</h1>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:16px 32px 8px;">
                                                                            <p style="margin:0 0 16px;font-size:16px;line-height:1.5;color:#333;">
                                            Hi Jane Doe,                                        </p>
                                                                        <p style="margin:0 0 16px;font-size:16px;line-height:1.5;color:#333;">Thanks for joining us!</p><p style="margin:0 0 16px;font-size:16px;line-height:1.5;color:#333;">We can&#039;t wait to see you there.</p>                                                                            <p style="margin:0 0 16px;font-size:15px;line-height:1.5;color:#333;">
                                            Your party of 3 is confirmed (you + 2 guests).                                        </p>
                                                                                                                                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 16px;border-collapse:collapse;">
                                                                                            <tr>
                                                    <td style="padding:4px 8px 4px 0;font-size:14px;color:#666;vertical-align:top;white-space:nowrap;">Date</td>
                                                    <td style="padding:4px 0;font-size:14px;color:#222;vertical-align:top;">January 5, 2027</td>
                                                </tr>
                                                                                            <tr>
                                                    <td style="padding:4px 8px 4px 0;font-size:14px;color:#666;vertical-align:top;white-space:nowrap;">Time</td>
                                                    <td style="padding:4px 0;font-size:14px;color:#222;vertical-align:top;">6:00 pm</td>
                                                </tr>
                                                                                    </table>
                                                                                                        </td>
                            </tr>
                                                        <tr>
                                <td style="padding:8px 32px 0;">
                                    <a href="https://example.test/join-a" target="_blank" rel="noopener" style="display:inline-block;padding:12px 20px;background:#0f766e;color:#ffffff;text-decoration:none;border-radius:4px;font-size:15px;">
                                        Join the event                                    </a>
                                </td>
                            </tr>
                                                                                    <tr>
                                <td style="padding:8px 32px 32px;">
                                    <a href="https://example.test/event/test-event-a/" style="display:inline-block;padding:12px 20px;background:#111;color:#ffffff;text-decoration:none;border-radius:4px;font-size:15px;">
                                        View event details                                    </a>
                                </td>
                            </tr>
                                                        <tr>
                                <td style="padding:16px 32px 24px;border-top:1px solid #eee;font-size:12px;color:#888;">
                                    Test Blog                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </body>
        </html>
        
EXPECTED_CONFIRMATION;
	}

	private function expected_confirmation_waitlist() {
		return <<<'EXPECTED_CONFIRMATION_WAITLIST'
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8" />
            <meta name="viewport" content="width=device-width,initial-scale=1" />
            <title>Test Event</title>
        </head>
        <body style="margin:0;padding:0;background:#f4f4f4;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4;padding:24px 12px;">
                <tr>
                    <td align="center">
                        <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:8px;overflow:hidden;">
                                                        <tr>
                                <td style="padding:28px 32px 8px;">
                                    <h1 style="margin:0;font-size:24px;line-height:1.3;color:#111;">Test Event</h1>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:16px 32px 8px;">
                                                                            <p style="margin:0 0 16px;font-size:16px;line-height:1.5;color:#333;">
                                            Hi Sam Waitperson,                                        </p>
                                                                        <p style="margin:0 0 16px;font-size:16px;line-height:1.5;color:#333;">You have been added to the waitlist.</p>                                                                                                                <p style="margin:0 0 16px;font-size:14px;line-height:1.5;color:#666;">
                                            You are currently on the waitlist and will be notified if a spot opens up.                                        </p>
                                                                                                                                                    <p style="margin:0 0 6px;font-size:14px;font-weight:600;color:#333;">Attendees</p>
                                        <ul style="margin:0 0 16px;padding:0 0 0 18px;font-size:14px;line-height:1.6;color:#333;">
                                                                                            <li>Sam Waitperson — sam@example.test</li>
                                                                                    </ul>
                                                                    </td>
                            </tr>
                                                                                    <tr>
                                <td style="padding:8px 32px 32px;">
                                    <a href="https://example.test/event/test-event-b/" style="display:inline-block;padding:12px 20px;background:#111;color:#ffffff;text-decoration:none;border-radius:4px;font-size:15px;">
                                        View event details                                    </a>
                                </td>
                            </tr>
                                                        <tr>
                                <td style="padding:16px 32px 24px;border-top:1px solid #eee;font-size:12px;color:#888;">
                                    Test Blog                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </body>
        </html>
        
EXPECTED_CONFIRMATION_WAITLIST;
	}

	private function expected_reminder() {
		return <<<'EXPECTED_REMINDER'
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8" />
            <meta name="viewport" content="width=device-width,initial-scale=1" />
            <title>Test Event</title>
        </head>
        <body style="margin:0;padding:0;background:#f4f4f4;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4;padding:24px 12px;">
                <tr>
                    <td align="center">
                        <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:8px;overflow:hidden;">
                                                        <tr>
                                <td style="padding:28px 32px 8px;">
                                    <h1 style="margin:0;font-size:24px;line-height:1.3;color:#111;">Test Event</h1>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:16px 32px 8px;">
                                                                            <p style="margin:0 0 16px;font-size:16px;line-height:1.5;color:#333;">
                                            Hi Riley Reminder,                                        </p>
                                                                        <p style="margin:0 0 16px;font-size:16px;line-height:1.5;color:#333;">This is a friendly reminder that you are registered for Test Event on January 9, 2027. We look forward to seeing you.</p>                                                                                                                                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 16px;border-collapse:collapse;">
                                                                                            <tr>
                                                    <td style="padding:4px 8px 4px 0;font-size:14px;color:#666;vertical-align:top;white-space:nowrap;">Date</td>
                                                    <td style="padding:4px 0;font-size:14px;color:#222;vertical-align:top;">January 9, 2027</td>
                                                </tr>
                                                                                            <tr>
                                                    <td style="padding:4px 8px 4px 0;font-size:14px;color:#666;vertical-align:top;white-space:nowrap;">Time</td>
                                                    <td style="padding:4px 0;font-size:14px;color:#222;vertical-align:top;">7:00 pm</td>
                                                </tr>
                                                                                            <tr>
                                                    <td style="padding:4px 8px 4px 0;font-size:14px;color:#666;vertical-align:top;white-space:nowrap;">Location</td>
                                                    <td style="padding:4px 0;font-size:14px;color:#222;vertical-align:top;">Online</td>
                                                </tr>
                                                                                    </table>
                                                                                                        </td>
                            </tr>
                                                        <tr>
                                <td style="padding:8px 32px 0;">
                                    <a href="https://example.test/join-c" target="_blank" rel="noopener" style="display:inline-block;padding:12px 20px;background:#0f766e;color:#ffffff;text-decoration:none;border-radius:4px;font-size:15px;">
                                        Join the event                                    </a>
                                </td>
                            </tr>
                                                                                    <tr>
                                <td style="padding:8px 32px 32px;">
                                    <a href="https://example.test/event/test-event-c/" style="display:inline-block;padding:12px 20px;background:#111;color:#ffffff;text-decoration:none;border-radius:4px;font-size:15px;">
                                        View event details                                    </a>
                                </td>
                            </tr>
                                                        <tr>
                                <td style="padding:16px 32px 24px;border-top:1px solid #eee;font-size:12px;color:#888;">
                                    Test Blog                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </body>
        </html>
        
EXPECTED_REMINDER;
	}

	private function expected_cancellation() {
		return <<<'EXPECTED_CANCELLATION'
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8" />
            <meta name="viewport" content="width=device-width,initial-scale=1" />
            <title>Test Event</title>
        </head>
        <body style="margin:0;padding:0;background:#f4f4f4;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4;padding:24px 12px;">
                <tr>
                    <td align="center">
                        <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:8px;overflow:hidden;">
                                                        <tr>
                                <td style="padding:28px 32px 8px;">
                                    <h1 style="margin:0;font-size:24px;line-height:1.3;color:#111;">Test Event</h1>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:16px 32px 8px;">
                                                                            <p style="margin:0 0 16px;font-size:16px;line-height:1.5;color:#333;">
                                            Hi Casey Cancelled,                                        </p>
                                                                        <p style="margin:0 0 16px;font-size:16px;line-height:1.5;color:#333;">Your registration for Test Event has been cancelled. If this is unexpected, please contact us.</p>                                                                                                                                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 16px;border-collapse:collapse;">
                                                                                            <tr>
                                                    <td style="padding:4px 8px 4px 0;font-size:14px;color:#666;vertical-align:top;white-space:nowrap;">Event</td>
                                                    <td style="padding:4px 0;font-size:14px;color:#222;vertical-align:top;">Test Event</td>
                                                </tr>
                                                                                            <tr>
                                                    <td style="padding:4px 8px 4px 0;font-size:14px;color:#666;vertical-align:top;white-space:nowrap;">Date</td>
                                                    <td style="padding:4px 0;font-size:14px;color:#222;vertical-align:top;">January 12, 2027</td>
                                                </tr>
                                                                                    </table>
                                                                                                        </td>
                            </tr>
                                                                                    <tr>
                                <td style="padding:16px 32px 24px;border-top:1px solid #eee;font-size:12px;color:#888;">
                                    Test Blog                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </body>
        </html>
        
EXPECTED_CANCELLATION;
	}

	private function expected_roster() {
		return <<<'EXPECTED_ROSTER'
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8" />
            <meta name="viewport" content="width=device-width,initial-scale=1" />
            <title>Test Event</title>
        </head>
        <body style="margin:0;padding:0;background:#f4f4f4;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4;padding:24px 12px;">
                <tr>
                    <td align="center">
                        <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:8px;overflow:hidden;">
                                                        <tr>
                                <td style="padding:0;">
                                    <img src="http://example.org/wp-content/uploads/image-e.jpg" alt="Test Event" width="600" style="display:block;width:100%;max-width:600px;height:auto;border:0;" />
                                </td>
                            </tr>
                                                        <tr>
                                <td style="padding:28px 32px 8px;">
                                    <h1 style="margin:0;font-size:24px;line-height:1.3;color:#111;">Test Event</h1>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:16px 32px 8px;">
                                                                        <p style="margin:0 0 16px;font-size:16px;line-height:1.5;color:#333;">Here is the current confirmed roster for Test Event on January 15, 2027.</p>                                                                                                                                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 16px;border-collapse:collapse;">
                                                                                            <tr>
                                                    <td style="padding:4px 8px 4px 0;font-size:14px;color:#666;vertical-align:top;white-space:nowrap;">Date</td>
                                                    <td style="padding:4px 0;font-size:14px;color:#222;vertical-align:top;">January 15, 2027</td>
                                                </tr>
                                                                                            <tr>
                                                    <td style="padding:4px 8px 4px 0;font-size:14px;color:#666;vertical-align:top;white-space:nowrap;">Venue</td>
                                                    <td style="padding:4px 0;font-size:14px;color:#222;vertical-align:top;">Online</td>
                                                </tr>
                                                                                            <tr>
                                                    <td style="padding:4px 8px 4px 0;font-size:14px;color:#666;vertical-align:top;white-space:nowrap;">Capacity</td>
                                                    <td style="padding:4px 0;font-size:14px;color:#222;vertical-align:top;">50</td>
                                                </tr>
                                                                                            <tr>
                                                    <td style="padding:4px 8px 4px 0;font-size:14px;color:#666;vertical-align:top;white-space:nowrap;">Confirmed</td>
                                                    <td style="padding:4px 0;font-size:14px;color:#222;vertical-align:top;">12</td>
                                                </tr>
                                                                                            <tr>
                                                    <td style="padding:4px 8px 4px 0;font-size:14px;color:#666;vertical-align:top;white-space:nowrap;">Waitlist</td>
                                                    <td style="padding:4px 0;font-size:14px;color:#222;vertical-align:top;">0</td>
                                                </tr>
                                                                                            <tr>
                                                    <td style="padding:4px 8px 4px 0;font-size:14px;color:#666;vertical-align:top;white-space:nowrap;">Remaining</td>
                                                    <td style="padding:4px 0;font-size:14px;color:#222;vertical-align:top;">38</td>
                                                </tr>
                                                                                    </table>
                                                                                                                <p style="margin:0 0 6px;font-size:14px;font-weight:600;color:#333;">Attendees</p>
                                        <ul style="margin:0 0 16px;padding:0 0 0 18px;font-size:14px;line-height:1.6;color:#333;">
                                                                                            <li>Alice — alice@example.test — 555-1111 (web)</li>
                                                                                            <li>Bob — bob@example.test</li>
                                                                                    </ul>
                                                                    </td>
                            </tr>
                                                        <tr>
                                <td style="padding:8px 32px 0;">
                                    <a href="https://example.test/join-e" target="_blank" rel="noopener" style="display:inline-block;padding:12px 20px;background:#0f766e;color:#ffffff;text-decoration:none;border-radius:4px;font-size:15px;">
                                        Join the event                                    </a>
                                </td>
                            </tr>
                                                                                    <tr>
                                <td style="padding:8px 32px 32px;">
                                    <a href="https://example.test/roster/fixed-id" style="display:inline-block;padding:12px 20px;background:#111;color:#ffffff;text-decoration:none;border-radius:4px;font-size:15px;">
                                        Open full roster                                    </a>
                                </td>
                            </tr>
                                                        <tr>
                                <td style="padding:16px 32px 24px;border-top:1px solid #eee;font-size:12px;color:#888;">
                                    Test Blog                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </body>
        </html>
        
EXPECTED_ROSTER;
	}
}
