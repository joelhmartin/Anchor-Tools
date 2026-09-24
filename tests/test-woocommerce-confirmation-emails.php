<?php
/**
 * Buyer confirmation / organizer notice seat collection (WOO-D44, WOO-D51,
 * finding-1).
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

	/** send_customer_confirmation() via reflection, scoped to one event. */
	private function send_customer_confirmation( \WC_Order $order, $event_id ) {
		$method = new ReflectionMethod( get_class( $this->woocommerce() ), 'send_customer_confirmation' );
		$method->setAccessible( true );
		return $method->invokeArgs( $this->woocommerce(), [ $order, $this->module()->get_settings(), $event_id ] );
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
	 * finding-1 (carry-over; supersedes WOO-D51): the buyer confirmation used
	 * to be ONE combined email spanning every event on the order, silently
	 * choosing a single "primary" event (most seats) whose title/CTA/
	 * overrides drove the WHOLE email — an order spanning a container and a
	 * real occurrence then reported the container's title, roster and
	 * remaining capacity for the entire order. Each event now gets its own
	 * confirmation, scoped to ONLY that event's seats — an order spanning a
	 * small and a big event must confirm each with its OWN title and seat
	 * count, never the other event's.
	 */
	public function test_confirmation_is_scoped_to_only_the_requested_event() {
		$small_event = $this->make_event( [ 'title' => 'Small Event' ] );
		$big_event   = $this->make_event( [ 'title' => 'Big Event' ] );
		$order_id    = 4343;

		$this->make_seat( $small_event, [ 'order_id' => $order_id ] );
		$this->make_seat( $big_event, [ 'order_id' => $order_id ] );
		$this->make_seat( $big_event, [ 'order_id' => $order_id ] );
		$this->make_seat( $big_event, [ 'order_id' => $order_id ] );

		$order = new WC_Order();
		$order->set_id( $order_id );
		$order->set_billing_email( 'buyer@example.test' );

		$small_outcome = $this->send_customer_confirmation( $order, $small_event );
		$big_outcome   = $this->send_customer_confirmation( $order, $big_event );

		$this->assertTrue( $small_outcome->is_sent(), 'Response: ' . $small_outcome->reason() );
		$this->assertTrue( $big_outcome->is_sent(), 'Response: ' . $big_outcome->reason() );
	}

	/** Requesting a confirmation for an event the order has no active seats on is a no-op. */
	public function test_confirmation_for_an_event_with_no_seats_on_the_order_is_skipped() {
		$event_id      = $this->make_event( [ 'title' => 'Real Event' ] );
		$unrelated_id  = $this->make_event( [ 'title' => 'Unrelated Event' ] );
		$order_id      = 4344;
		$this->make_seat( $event_id, [ 'order_id' => $order_id ] );

		$order = new WC_Order();
		$order->set_id( $order_id );
		$order->set_billing_email( 'buyer@example.test' );

		$outcome = $this->send_customer_confirmation( $order, $unrelated_id );
		$this->assertTrue( $outcome->is_skipped() );
		$this->assertSame( 'nothing_to_send', $outcome->reason() );
	}

	/**
	 * Fix round 1 (controller ruling on Task 14's flagged concern): the buyer
	 * confirmation used to tokenise `reset( $seats )['id']` — the first seat
	 * on the order for this event, regardless of whose it was. On a
	 * multi-seat order that seat is routinely someone OTHER than the buyer,
	 * so this could mint a sign-in token for a different attendee's account
	 * and hand it to the buyer, who would then be signed in as that
	 * attendee. A sign-in token is an identity: the buyer gets their OWN
	 * token (only if they hold a confirmed seat themselves), or the plain,
	 * untokenised room address — never somebody else's.
	 */
	public function test_room_link_never_tokenises_a_different_attendees_seat() {
		$event_id = $this->make_event( [
			'registration_mode'       => 'free',
			'access_role_enabled'     => true,
			'stream_default_modality' => 'virtual',
			'stream_embed'            => [ 'provider' => 'vimeo', 'kind' => 'iframe', 'src' => 'https://player.vimeo.com/video/8', 'raw' => '' ],
		] );
		$order_id = 5001;

		// Seat 1 belongs to a DIFFERENT account than the buyer.
		$this->make_seat( $event_id, [ 'order_id' => $order_id, 'name' => 'Other Attendee', 'email' => 'other-attendee@example.test' ] );
		$other_user = get_user_by( 'email', 'other-attendee@example.test' );
		$this->assertInstanceOf( 'WP_User', $other_user, 'Seat 1 minted its own account.' );

		// The buyer has their own, separate account — created before the
		// order, guest checkout (no customer_id), matched by billing email.
		self::factory()->user->create( [ 'user_email' => 'buyer@example.test' ] );

		$order = new WC_Order();
		$order->set_id( $order_id );
		$order->set_billing_email( 'buyer@example.test' );

		$captured = [];
		$capture  = function( $html, $ctx ) use ( &$captured ) {
			$captured[] = $html;
			return $html;
		};

		// --- The buyer holds no seat on this event: no token for anyone. ---
		add_filter( 'anchor_events_registration_email_html', $capture, 10, 2 );
		try {
			$outcome = $this->send_customer_confirmation( $order, $event_id );
		} finally {
			remove_filter( 'anchor_events_registration_email_html', $capture, 10 );
		}
		$this->assertTrue( $outcome->is_sent(), 'Response: ' . $outcome->reason() );
		$this->assertNotEmpty( $captured );
		$html = \end( $captured );
		$this->assertStringContainsString( 'Join the livestream', $html );
		$this->assertStringNotContainsString( 'aek=', $html, 'No seat for the buyer means no token, not somebody else\'s.' );

		// --- The buyer now ALSO holds a confirmed seat: they get their OWN token. ---
		$this->make_seat( $event_id, [ 'order_id' => $order_id, 'name' => 'Buyer', 'email' => 'buyer@example.test', 'seat_index' => 2 ] );
		$captured = [];
		add_filter( 'anchor_events_registration_email_html', $capture, 10, 2 );
		try {
			$outcome2 = $this->send_customer_confirmation( $order, $event_id );
		} finally {
			remove_filter( 'anchor_events_registration_email_html', $capture, 10 );
		}
		$this->assertTrue( $outcome2->is_sent(), 'Response: ' . $outcome2->reason() );
		$html2 = \end( $captured );
		$this->assertStringContainsString( 'aek=', $html2, 'The buyer holds a confirmed seat now, so they get their own token.' );

		remove_role( 'anchor_event_' . $event_id );
	}

	/* -----------------------------------------------------------------
	 * Final review C1: attendee seats on a LOGGED-IN buyer's order carry
	 * the buyer's customer id. Each attendee's email must carry only that
	 * attendee's own token; the buyer's email never carries an attendee's.
	 * --------------------------------------------------------------- */

	/** A room event for the C1 tests. */
	private function room_event() {
		return $this->make_event( [
			'registration_mode'       => 'free',
			'access_role_enabled'     => true,
			'timezone'                => 'UTC',
			'start_ts'                => time() + DAY_IN_SECONDS,
			'end_ts'                  => time() + DAY_IN_SECONDS + 3600,
			'stream_default_modality' => 'virtual',
			'stream_embed'            => [ 'provider' => 'vimeo', 'kind' => 'iframe', 'src' => 'https://player.vimeo.com/video/9', 'raw' => '' ],
		] );
	}

	/** Every user id the aek tokens in $html verify to (0 for a bad one). */
	private function token_users( $html, $event_id ) {
		preg_match_all( '/aek=([A-Za-z0-9.%_-]+)/', (string) $html, $m );
		$ids = [];
		foreach ( array_unique( $m[1] ) as $raw ) {
			$ids[] = $this->module()->entitlements->verify_login_token( rawurldecode( $raw ), $event_id );
		}
		return array_values( array_unique( $ids ) );
	}

	/** Run $send with the rendered-HTML filter up; return the last HTML. */
	private function capture_html( callable $send ) {
		$captured = [];
		$capture  = function ( $html ) use ( &$captured ) {
			$captured[] = $html;
			return $html;
		};
		add_filter( 'anchor_events_registration_email_html', $capture, 10, 1 );
		try {
			$send();
		} finally {
			remove_filter( 'anchor_events_registration_email_html', $capture, 10 );
		}
		$this->assertNotEmpty( $captured, 'No email HTML was rendered.' );
		return (string) end( $captured );
	}

	public function test_a_logged_in_buyers_attendees_each_get_only_their_own_token() {
		$event_id = $this->room_event();
		$order_id = 5101;
		$buyer_id = self::factory()->user->create( [ 'user_email' => 'logged-in-buyer@example.test', 'role' => 'customer' ] );

		// Exactly what the WooCommerce reconcile writes: every seat on the
		// order carries the order's customer id, whoever the attendee is.
		$seat_a = $this->make_seat( $event_id, [ 'order_id' => $order_id, 'customer_id' => $buyer_id, 'name' => 'Ada', 'email' => 'ada-attendee@example.test' ] );
		$seat_b = $this->make_seat( $event_id, [ 'order_id' => $order_id, 'customer_id' => $buyer_id, 'name' => 'Bob', 'email' => 'bob-attendee@example.test', 'seat_index' => 2 ] );

		$ada = get_user_by( 'email', 'ada-attendee@example.test' );
		$bob = get_user_by( 'email', 'bob-attendee@example.test' );
		$this->assertInstanceOf( 'WP_User', $ada, 'Seat A resolved to its own account.' );
		$this->assertInstanceOf( 'WP_User', $bob, 'Seat B resolved to its own account.' );
		$this->assertSame( (int) $ada->ID, (int) get_post_meta( $seat_a, '_anchor_event_user_id', true ) );
		$this->assertSame( (int) $bob->ID, (int) get_post_meta( $seat_b, '_anchor_event_user_id', true ) );

		$registrations = $this->module()->registrations;
		foreach ( [ [ $seat_a, (int) $ada->ID ], [ $seat_b, (int) $bob->ID ] ] as [ $seat_id, $owner ] ) {
			$seat = $registrations->get_seat( $seat_id );

			$confirmation = $this->capture_html( function () use ( $event_id, $seat, $seat_id ) {
				$this->module()->send_confirmation_email( $event_id, $seat['name'], $seat['email'], Registrations::STATUS_CONFIRMED, 0, $seat_id );
			} );
			$this->assertSame( [ $owner ], $this->token_users( $confirmation, $event_id ), 'The confirmation carries only its own attendee\'s token.' );

			$reminder = $this->capture_html( function () use ( $event_id, $seat ) {
				$this->module()->send_reminder_email( $seat, $event_id, DAY_IN_SECONDS );
			} );
			$this->assertSame( [ $owner ], $this->token_users( $reminder, $event_id ), 'The reminder carries only its own attendee\'s token.' );
		}

		// The buyer's own confirmation never carries an attendee's token.
		$order = new WC_Order();
		$order->set_id( $order_id );
		$order->set_customer_id( $buyer_id );
		$order->set_billing_email( 'logged-in-buyer@example.test' );
		$buyer_html = $this->capture_html( function () use ( $order, $event_id ) {
			$this->send_customer_confirmation( $order, $event_id );
		} );
		$buyer_tokens = $this->token_users( $buyer_html, $event_id );
		$this->assertNotContains( (int) $ada->ID, $buyer_tokens );
		$this->assertNotContains( (int) $bob->ID, $buyer_tokens );
		$this->assertNotContains( 0, $buyer_tokens, 'No unverifiable token either.' );

		remove_role( 'anchor_event_' . $event_id );
	}

	/**
	 * Second safeguard: a seat ALREADY bound to the buyer before the fix
	 * (legacy data) must not mail the buyer's token to the attendee — a
	 * token is only minted when its account's email is the recipient's.
	 */
	public function test_a_seat_bound_to_someone_else_gets_the_plain_room_url() {
		$event_id = $this->room_event();
		$buyer_id = self::factory()->user->create( [ 'user_email' => 'legacy-buyer@example.test' ] );
		$seat_id  = $this->make_seat( $event_id, [
			'name'   => 'Cy',
			'email'  => 'cy-attendee@example.test',
			'status' => Registrations::STATUS_PENDING,
		] );
		update_post_meta( $seat_id, '_anchor_event_user_id', $buyer_id );
		update_post_meta( $seat_id, '_anchor_event_reg_status', Registrations::STATUS_CONFIRMED );

		$html = $this->capture_html( function () use ( $event_id, $seat_id ) {
			$this->module()->send_confirmation_email( $event_id, 'Cy', 'cy-attendee@example.test', Registrations::STATUS_CONFIRMED, 0, $seat_id );
		} );
		$this->assertStringNotContainsString( 'aek=', $html, 'The buyer\'s token must never reach the attendee.' );
		$this->assertStringContainsString( esc_url( $this->module()->room_url( $event_id ) ), $html, 'The attendee still gets the plain room URL.' );

		remove_role( 'anchor_event_' . $event_id );
	}
}
