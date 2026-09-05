<?php
/**
 * Self-correcting needs-review flags + a log that stops eating itself.
 *
 * Wave 3 / Task 30 — WOO-D33, WOO-D16, REG-D31, REG-D46, WOO-D13.
 *
 * The five behaviours under test:
 *  - WOO-D33 a reconcile pass that re-evaluates a flag's condition and finds it
 *            satisfied drops the flag; a flag with no condition never clears.
 *  - WOO-D16 Events_Log::flag_review()/clear_review() bust the same notice
 *            transient apply_review_flags() busts, so a cached count of 0 can
 *            not hide a freshly flagged order for five minutes.
 *  - REG-D31 clearing the error log archives it instead of destroying it.
 *  - REG-D46 repeats collapse into one counted entry per (code, subject) within
 *            24h, a genuinely new failure after the window gets its own entry,
 *            and one noisy code can not evict every other code.
 *  - WOO-D13 a throw inside the product sync is caught, logged and flagged
 *            rather than aborting the save with a fatal.
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Events_Log;
use Anchor\Events\Product_Sync;
use Anchor\Events\Registrations;
use Anchor\Events\WooCommerce;

/** Thrown from the wp_redirect filter so an admin-post handler's exit never runs. */
class Anchor_Events_Log_Redirect_Signal extends \Exception {}

/**
 * @group events-log
 */
class Test_Events_Log extends Anchor_Events_TestCase {

	public function set_up() {
		parent::set_up();
		delete_option( Events_Log::ERROR_OPTION );
		delete_option( Events_Log::ERROR_ARCHIVE_OPTION );
		delete_transient( WooCommerce::NEEDS_REVIEW_TRANSIENT );
	}

	public function tear_down() {
		delete_option( Events_Log::ERROR_OPTION );
		delete_option( Events_Log::ERROR_ARCHIVE_OPTION );
		parent::tear_down();
	}

	/* -----------------------------------------------------------------
	 * Helpers
	 * --------------------------------------------------------------- */

	/** The raw site-wide error log rows. @return array[] */
	private function error_log_rows() {
		$log = get_option( Events_Log::ERROR_OPTION, [] );
		return is_array( $log ) ? $log : [];
	}

	/** Error-log codes, oldest first. @return string[] */
	private function error_codes() {
		return array_column( $this->error_log_rows(), 'code' );
	}

	/** Needs-review reasons on an order. @return string[] */
	private function review_reasons( $order_id ) {
		$flags = wc_get_order( $order_id )->get_meta( Events_Log::ORDER_REVIEW_META );
		return is_array( $flags ) ? array_column( $flags, 'reason' ) : [];
	}

	/**
	 * A paid single-tier event plus its managed variation.
	 *
	 * @return array{event_id:int,tier_id:string,variation_id:int}
	 */
	private function paid_event_with_variation() {
		$event_id = $this->make_event(
			[ 'title' => 'Log Event', 'capacity' => 0 ],
			[ [ 'label' => 'General', 'price' => '10', 'active' => 1 ] ]
		);
		$this->product_sync()->sync_event( $event_id );
		$tiers        = $this->ticket_types()->get( $event_id );
		$tier_id      = (string) $tiers[0]['id'];
		$variation_id = (int) $this->product_sync()->variation_for_tier( $event_id, $tier_id );
		$this->assertGreaterThan( 0, $variation_id );

		return [
			'event_id'     => $event_id,
			'tier_id'      => $tier_id,
			'variation_id' => $variation_id,
		];
	}

	/**
	 * An order with one event line. Attendee meta is deliberately optional so a
	 * test can reproduce the "paid line, no attendees" flag.
	 *
	 * @return array{order_id:int,item_id:int}
	 */
	private function make_order( $variation_id, $qty, $with_attendees = true, $status = 'processing' ) {
		$item = new WC_Order_Item_Product();
		$item->set_product( wc_get_product( $variation_id ) );
		$item->set_quantity( $qty );
		$item->set_subtotal( 10 * $qty );
		$item->set_total( 10 * $qty );
		if ( $with_attendees ) {
			$item->add_meta_data( '_anchor_attendees', $this->attendee_rows( $qty ), true );
		}

		$order = new WC_Order();
		$order->add_item( $item );
		$order->set_billing_email( 'buyer@example.test' );
		$order->set_billing_first_name( 'Buyer' );
		$order->calculate_totals( false );
		$order->save();
		$order->set_status( $status );
		$order->save();

		return [ 'order_id' => (int) $order->get_id(), 'item_id' => (int) $item->get_id() ];
	}

	/** Per-seat attendee payload keyed 1..qty. */
	private function attendee_rows( $qty ) {
		$rows = [];
		for ( $i = 1; $i <= $qty; $i++ ) {
			$rows[ $i ] = [
				'name'  => 'Attendee ' . $i,
				'email' => 'attendee' . $i . '@example.test',
				'phone' => '555-000' . $i,
			];
		}
		return $rows;
	}

	/* -----------------------------------------------------------------
	 * WOO-D16 — the notice transient follows the flag
	 * --------------------------------------------------------------- */

	/**
	 * flag_review() raises a flag outside reconcile. If the cached notice count
	 * (written moments earlier, legitimately 0) survives, render_needs_review_notice
	 * returns early and the order is invisible for five minutes.
	 */
	/**
	 * REG-D30 — there is no per-event activity roll-up. Events_Log::event() was
	 * an empty method with a full docblock, so a caller would have read as
	 * recording activity while recording nothing, and the event meta schema
	 * reserved an 'activity' key nothing ever wrote.
	 */
	public function test_there_is_no_no_op_per_event_activity_log() {
		$this->assertFalse(
			method_exists( Events_Log::class, 'event' ),
			'A method that logs nothing is worse than no method at all.'
		);

		$meta = $this->module()->get_meta( $this->make_event() );
		$this->assertArrayNotHasKey( 'activity', $meta );
	}

	public function test_flag_review_busts_the_needs_review_notice_transient() {
		$this->require_wc();
		$ctx = $this->paid_event_with_variation();
		$res = $this->make_order( $ctx['variation_id'], 1 );

		set_transient( WooCommerce::NEEDS_REVIEW_TRANSIENT, [ 'count' => 0, 'first' => 0, 'capped' => false ], 300 );

		Events_Log::flag_review( $res['order_id'], 'amount_only_refund', 'refund #1' );

		$this->assertFalse(
			get_transient( WooCommerce::NEEDS_REVIEW_TRANSIENT ),
			'Raising a flag must invalidate the cached needs-review count.'
		);
		$this->assertContains( 'amount_only_refund', $this->review_reasons( $res['order_id'] ) );
	}

	/** …and the same for the clearing side, so the notice drops the order promptly. */
	public function test_clear_review_busts_the_needs_review_notice_transient() {
		$this->require_wc();
		$ctx = $this->paid_event_with_variation();
		$res = $this->make_order( $ctx['variation_id'], 1 );

		Events_Log::flag_review( $res['order_id'], 'amount_only_refund', 'refund #1' );
		set_transient( WooCommerce::NEEDS_REVIEW_TRANSIENT, [ 'count' => 1, 'first' => $res['order_id'], 'capped' => false ], 300 );

		Events_Log::clear_review( $res['order_id'] );

		$this->assertFalse( get_transient( WooCommerce::NEEDS_REVIEW_TRANSIENT ) );
		$this->assertSame( [], $this->review_reasons( $res['order_id'] ) );
	}

	/** A duplicate flag changes nothing, so it must not churn the cache either. */
	public function test_a_duplicate_flag_leaves_the_cached_count_alone() {
		$this->require_wc();
		$ctx = $this->paid_event_with_variation();
		$res = $this->make_order( $ctx['variation_id'], 1 );

		Events_Log::flag_review( $res['order_id'], 'amount_only_refund', 'refund #1' );
		set_transient( WooCommerce::NEEDS_REVIEW_TRANSIENT, [ 'count' => 1, 'first' => $res['order_id'], 'capped' => false ], 300 );

		Events_Log::flag_review( $res['order_id'], 'amount_only_refund', 'refund #1 again' );

		$this->assertIsArray(
			get_transient( WooCommerce::NEEDS_REVIEW_TRANSIENT ),
			'A no-op re-flag must not throw away a still-correct cached count.'
		);
	}

	/* -----------------------------------------------------------------
	 * WOO-D33 — flags clear when their condition passes
	 * --------------------------------------------------------------- */

	/**
	 * The concrete failure from the audit: an order flagged attendees_missing has
	 * its attendees added, and nothing re-evaluates the flag.
	 */
	public function test_attendees_missing_clears_once_the_attendees_arrive() {
		$this->require_wc();
		$ctx = $this->paid_event_with_variation();
		$res = $this->make_order( $ctx['variation_id'], 2, false );

		$this->woocommerce()->reconcile_order( wc_get_order( $res['order_id'] ), 'placed' );
		$this->assertContains(
			'attendees_missing',
			$this->review_reasons( $res['order_id'] ),
			'Precondition: a paid line with no attendee data is flagged.'
		);
		$this->assertSame( 0, $this->count_seats( $ctx['event_id'], Registrations::STATUS_CONFIRMED ) );

		// Staff add the attendees to the line (roster / order editor).
		$order = wc_get_order( $res['order_id'] );
		$item  = $order->get_item( $res['item_id'] );
		$item->add_meta_data( '_anchor_attendees', $this->attendee_rows( 2 ), true );
		$item->save();

		$this->woocommerce()->reconcile_order( wc_get_order( $res['order_id'] ), 'resync' );

		$this->assertSame( 2, $this->count_seats( $ctx['event_id'], Registrations::STATUS_CONFIRMED ) );
		$this->assertNotContains(
			'attendees_missing',
			$this->review_reasons( $res['order_id'] ),
			'The flag must clear once the condition that raised it passes.'
		);
	}

	/** A flag with no condition records a historical fact — only a human clears it. */
	public function test_a_condition_less_flag_survives_a_clean_pass() {
		$this->require_wc();
		$ctx = $this->paid_event_with_variation();
		$res = $this->make_order( $ctx['variation_id'], 1 );

		$this->woocommerce()->reconcile_order( wc_get_order( $res['order_id'] ), 'paid' );
		Events_Log::flag_review( $res['order_id'], 'amount_only_refund', 'refund #7' );

		$this->woocommerce()->reconcile_order( wc_get_order( $res['order_id'] ), 'resync' );

		$this->assertContains(
			'amount_only_refund',
			$this->review_reasons( $res['order_id'] ),
			'An amount-only refund happened; no later pass can un-happen it.'
		);
	}

	/**
	 * CodeRabbit finding-1 (PR #20, 2nd round): collect_order_seats() used to
	 * flag a status-less seat by loading and saving a SEPARATE \WC_Order
	 * instance directly (Events_Log::flag_review()) from inside
	 * dispatch_emails() — a second, independent load/save racing the batched
	 * pass's own single end-of-pass $save_order->save(). Whichever flag
	 * reaches the database last wins; the other is silently dropped even
	 * though both were genuinely raised the same pass. Both must survive.
	 *
	 * Three events on ONE order exercise this: A's seat (confirmed and
	 * already emailed in an earlier pass) is corrupted afterwards; B is a
	 * brand-new well-formed line whose customer confirmation is what
	 * actually calls collect_order_seats() THIS pass (A's gate is already
	 * stamped, so only B's confirmation is new); C is a brand-new line with
	 * no attendee data, raising the unrelated attendees_missing flag through
	 * the ordinary batched $review_flags path in the very same pass.
	 */
	public function test_seat_missing_status_flag_survives_the_batched_reconcile_pass() {
		$this->require_wc();

		$ctx_a = $this->paid_event_with_variation();
		$res_a = $this->make_order( $ctx_a['variation_id'], 1 );
		$this->woocommerce()->reconcile_order( wc_get_order( $res_a['order_id'] ), 'placed' );
		$this->assertSame( 1, $this->count_seats( $ctx_a['event_id'], Registrations::STATUS_CONFIRMED ) );

		$broken_seats = $this->registrations()->get_seats_for_order( $res_a['order_id'] );
		$this->assertNotEmpty( $broken_seats );
		delete_post_meta( (int) $broken_seats[0], '_anchor_event_reg_status' );

		$ctx_b = $this->paid_event_with_variation();
		$ctx_c = $this->paid_event_with_variation();

		$order = wc_get_order( $res_a['order_id'] );

		$item_b = new WC_Order_Item_Product();
		$item_b->set_product( wc_get_product( $ctx_b['variation_id'] ) );
		$item_b->set_quantity( 1 );
		$item_b->set_subtotal( 10 );
		$item_b->set_total( 10 );
		$item_b->add_meta_data( '_anchor_attendees', $this->attendee_rows( 1 ), true );
		$order->add_item( $item_b );

		$item_c = new WC_Order_Item_Product();
		$item_c->set_product( wc_get_product( $ctx_c['variation_id'] ) );
		$item_c->set_quantity( 1 );
		$item_c->set_subtotal( 10 );
		$item_c->set_total( 10 );
		// Deliberately no _anchor_attendees meta — raises attendees_missing
		// through the normal batched $review_flags path (unaffected by this
		// bug on its own), giving apply_review_flags() a genuine reason to
		// persist a changed array in the SAME pass collect_order_seats()
		// flags the corrupted seat on event A.
		$order->add_item( $item_c );

		$order->calculate_totals( false );
		$order->save();

		$this->woocommerce()->reconcile_order( wc_get_order( $res_a['order_id'] ), 'resync' );

		$reasons = $this->review_reasons( $res_a['order_id'] );
		$this->assertContains(
			'attendees_missing',
			$reasons,
			'A flag genuinely raised this pass through the normal batched path must survive it.'
		);
		$this->assertContains(
			'seat_missing_status',
			$reasons,
			'The status-less seat flag must ALSO survive the same batched pass — not lost to a second, independent order load/save racing the single end-of-pass save.'
		);
	}

	/** A pass that never evaluated the condition leaves the flag where it is. */
	public function test_a_flag_is_not_cleared_by_a_pass_that_could_not_check_it() {
		$this->require_wc();
		$ctx = $this->paid_event_with_variation();
		$res = $this->make_order( $ctx['variation_id'], 2, false );

		$this->woocommerce()->reconcile_order( wc_get_order( $res['order_id'] ), 'placed' );
		$this->assertContains( 'attendees_missing', $this->review_reasons( $res['order_id'] ) );

		// Cancelled: the line now expects no seats, so the attendee condition is
		// never evaluated. The flag must survive rather than silently vanish.
		$order = wc_get_order( $res['order_id'] );
		$order->set_status( 'cancelled' );
		$order->save();
		$this->woocommerce()->reconcile_order( wc_get_order( $res['order_id'] ), 'cancelled' );

		$this->assertContains( 'attendees_missing', $this->review_reasons( $res['order_id'] ) );
	}

	/**
	 * A revived seat that overflows capacity IS created, so the very next pass is
	 * converged. If "the pass could have created" were the condition, that pass
	 * would clear capacity_overfill while the event is still overbooked — and
	 * nothing else records it (there is no capacity_overfill error-log entry).
	 * The condition is "the pass actually attempted a create".
	 */
	public function test_capacity_overfill_survives_a_converged_pass() {
		$this->require_wc();
		$event_id = $this->make_event(
			[ 'title' => 'Tight Event', 'capacity' => 5, 'waitlist' => 0 ],
			[ [ 'label' => 'General', 'price' => '10', 'active' => 1 ] ]
		);
		$this->product_sync()->sync_event( $event_id );
		$tiers        = $this->ticket_types()->get( $event_id );
		$variation_id = (int) $this->product_sync()->variation_for_tier( $event_id, (string) $tiers[0]['id'] );

		// make_order() lands on `processing`, and the status-change hook reconciles.
		$res = $this->make_order( $variation_id, 1 );
		$this->assertSame( 1, $this->count_seats( $event_id, Registrations::STATUS_CONFIRMED ) );

		// The buyer cancels — the seat goes cancelled and gives its capacity back.
		$order = wc_get_order( $res['order_id'] );
		$order->set_status( 'cancelled' );
		$order->save();
		$this->assertSame( 1, $this->count_seats( $event_id, Registrations::STATUS_CANCELLED ) );

		// The organizer fills the event from the roster in the meantime.
		for ( $i = 0; $i < 5; $i++ ) {
			$this->make_seat( $event_id, [ 'email' => 'roster' . $i . '@example.test' ] );
		}

		// The cancellation is reversed: the seat is REVIVED with no room left.
		$order = wc_get_order( $res['order_id'] );
		$order->set_status( 'processing' );
		$order->save();
		$this->assertSame( 6, $this->count_seats( $event_id, Registrations::STATUS_CONFIRMED ) );
		$this->assertContains(
			'capacity_overfill',
			$this->review_reasons( $res['order_id'] ),
			'Precondition: reviving over capacity flags the overfill.'
		);

		// …and the order is now converged, so the next pass attempts nothing.
		$this->woocommerce()->reconcile_order( wc_get_order( $res['order_id'] ), 'resync' );

		$this->assertContains(
			'capacity_overfill',
			$this->review_reasons( $res['order_id'] ),
			'A pass that never tried to create a seat has not re-checked capacity.'
		);
	}

	/**
	 * A revive asked the EVENT total for room and never the tier quota, so a
	 * paid seat coming back into a sold-out tier looked like an ordinary
	 * revive: nothing flagged it, and the running tier tally the rest of the
	 * pass reads stopped matching the seats. The seat still comes back — the
	 * order is paid and the seat is owed — it is now RECORDED.
	 */
	public function test_a_revive_into_a_sold_out_tier_flags_the_overfill() {
		$this->require_wc();
		$event_id = $this->make_event(
			[ 'title' => 'Tiered Event', 'capacity' => 50, 'waitlist' => 0 ],
			[ [ 'label' => 'General', 'price' => '10', 'active' => 1, 'quota' => 2 ] ]
		);
		$this->product_sync()->sync_event( $event_id );
		$tier_id      = (string) $this->ticket_types()->get( $event_id )[0]['id'];
		$variation_id = (int) $this->product_sync()->variation_for_tier( $event_id, $tier_id );

		$res = $this->make_order( $variation_id, 1 );
		$this->assertSame( 1, $this->count_seats( $event_id, Registrations::STATUS_CONFIRMED ) );

		// The buyer cancels — the seat gives its tier slot back.
		$order = wc_get_order( $res['order_id'] );
		$order->set_status( 'cancelled' );
		$order->save();

		// The organizer sells that tier out from the roster in the meantime.
		// The EVENT still has 48 seats spare; only the tier is exhausted.
		for ( $i = 0; $i < 2; $i++ ) {
			$this->make_seat(
				$event_id,
				[ 'email' => 'roster' . $i . '@example.test', 'ticket_type_id' => $tier_id ]
			);
		}

		// The cancellation is reversed: the seat is owed, so it comes back.
		$order = wc_get_order( $res['order_id'] );
		$order->set_status( 'processing' );
		$order->save();

		$this->assertSame(
			3,
			$this->count_seats( $event_id, Registrations::STATUS_CONFIRMED ),
			'The paid seat was not revived — a tier shortage must not cost the buyer their seat.'
		);
		$this->assertContains(
			'capacity_overfill',
			$this->review_reasons( $res['order_id'] ),
			'A revive that broke the tier quota was recorded nowhere.'
		);
	}

	/* -----------------------------------------------------------------
	 * "Resync order" re-evaluates; only "Mark reviewed" clears
	 * --------------------------------------------------------------- */

	/**
	 * "Resync order" used to replace the whole flag set with [], so it
	 * destroyed flags the pass never looked at — an `amount_only_refund`
	 * seeded by the refund path is evaluated by nothing in a reconcile, and
	 * pressing Resync silently retired a refund discrepancy nobody had read.
	 */
	public function test_a_manual_resync_keeps_a_flag_it_never_evaluated() {
		$this->require_wc();
		$ctx = $this->paid_event_with_variation();
		$res = $this->make_order( $ctx['variation_id'], 1 );
		$this->woocommerce()->reconcile_order( wc_get_order( $res['order_id'] ), 'paid' );
		Events_Log::flag_review( $res['order_id'], 'amount_only_refund', 'refund #7' );

		$this->press_order_action( 'handle_resync_order', 'anchor_event_resync_', $res['order_id'] );

		$this->assertContains(
			'amount_only_refund',
			$this->review_reasons( $res['order_id'] ),
			'"Resync order" destroyed a refund discrepancy nobody had read.'
		);
	}

	/** "Mark reviewed" is the button that clears everything — deliberately. */
	public function test_mark_reviewed_clears_every_flag() {
		$this->require_wc();
		$ctx = $this->paid_event_with_variation();
		$res = $this->make_order( $ctx['variation_id'], 1 );
		$this->woocommerce()->reconcile_order( wc_get_order( $res['order_id'] ), 'paid' );
		Events_Log::flag_review( $res['order_id'], 'amount_only_refund', 'refund #7' );

		$this->press_order_action( 'handle_clear_review', 'anchor_events_clear_review_', $res['order_id'] );

		$this->assertSame( [], $this->review_reasons( $res['order_id'] ) );
	}

	/**
	 * Drive one order-action admin-post handler for real — capability, nonce
	 * and all — and swallow the redirect it ends on.
	 */
	private function press_order_action( $method, $nonce_prefix, $order_id ) {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_POST    = [ 'order_id' => $order_id, '_wpnonce' => wp_create_nonce( $nonce_prefix . $order_id ) ];
		$_REQUEST = $_POST;

		$trap = function ( $location ) {
			throw new Anchor_Events_Log_Redirect_Signal( (string) $location );
		};
		add_filter( 'wp_redirect', $trap );
		try {
			$this->woocommerce()->$method();
			$this->fail( $method . '() did not redirect.' );
		} catch ( Anchor_Events_Log_Redirect_Signal $e ) {
			// Expected: the handler finished and tried to send the operator back.
		} finally {
			remove_filter( 'wp_redirect', $trap );
			$_POST    = [];
			$_REQUEST = [];
		}
	}

	/** …and the scenario the audit actually describes still clears. */
	public function test_capacity_overfill_clears_once_capacity_is_raised() {
		$this->require_wc();
		$event_id = $this->make_event(
			[ 'title' => 'Full Event', 'capacity' => 1, 'waitlist' => 0 ],
			[ [ 'label' => 'General', 'price' => '10', 'active' => 1 ] ]
		);
		$this->product_sync()->sync_event( $event_id );
		$tiers        = $this->ticket_types()->get( $event_id );
		$variation_id = (int) $this->product_sync()->variation_for_tier( $event_id, (string) $tiers[0]['id'] );

		// The single seat is already taken from the roster, so the paid line has
		// nowhere to go: the create is REFUSED and the deficit persists.
		$this->make_seat( $event_id, [ 'email' => 'roster@example.test' ] );

		$res = $this->make_order( $variation_id, 1 );
		$this->woocommerce()->reconcile_order( wc_get_order( $res['order_id'] ), 'paid' );
		$this->assertContains( 'capacity_overfill', $this->review_reasons( $res['order_id'] ) );

		// The organizer raises the capacity; the next pass has a deficit, attempts
		// the create, and succeeds.
		update_post_meta( $event_id, '_anchor_event_capacity', 10 );
		$this->woocommerce()->reconcile_order( wc_get_order( $res['order_id'] ), 'resync' );

		$this->assertNotContains(
			'capacity_overfill',
			$this->review_reasons( $res['order_id'] ),
			'A pass that attempted the create and found room has re-checked the condition.'
		);
	}

	/* -----------------------------------------------------------------
	 * REG-D46 — the log stops filling with duplicates
	 * --------------------------------------------------------------- */

	/** The same failure about the same subject collapses into one counted row. */
	public function test_repeat_errors_collapse_into_one_counted_entry() {
		Events_Log::error( 'capacity_lock_unavailable', [ 'event' => 41 ] );
		Events_Log::error( 'capacity_lock_unavailable', [ 'event' => 41 ] );
		Events_Log::error( 'capacity_lock_unavailable', [ 'event' => 41 ] );

		$rows = $this->error_log_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 3, (int) $rows[0]['count'] );
		$this->assertArrayHasKey( 'first_time', $rows[0] );
		$this->assertGreaterThanOrEqual( (int) $rows[0]['first_time'], (int) $rows[0]['time'] );
	}

	/** …but two different subjects are two different failures. */
	public function test_the_same_code_about_different_subjects_stays_separate() {
		Events_Log::error( 'capacity_lock_unavailable', [ 'event' => 41 ] );
		Events_Log::error( 'capacity_lock_unavailable', [ 'event' => 42 ] );
		Events_Log::error( 'email_retry_abandoned', [ 'seat' => 9, 'type' => 'confirmation' ] );
		Events_Log::error( 'email_retry_abandoned', [ 'seat' => 9, 'type' => 'cancellation' ] );

		$this->assertCount( 4, $this->error_log_rows() );
	}

	/** The transition a seat was refused tells them apart, so `from` identifies too. */
	public function test_two_illegal_transitions_on_one_seat_are_two_rows() {
		Events_Log::error( 'illegal_transition', [ 'seat' => 9, 'from' => 'refunded', 'to' => 'confirmed' ] );
		Events_Log::error( 'illegal_transition', [ 'seat' => 9, 'from' => 'cancelled', 'to' => 'waitlist' ] );

		$this->assertCount( 2, $this->error_log_rows() );
	}

	/**
	 * `from` alone was not enough: the status a change was refused TO also
	 * tells two failures apart, and on one seat both rejected targets are real
	 * bugs. They used to collapse into one counted row, so the second target
	 * was never named anywhere an operator could read it.
	 */
	public function test_two_rejected_targets_on_one_seat_are_two_rows() {
		Events_Log::error( 'invalid_status', [ 'seat' => 7, 'to' => 'attended' ] );
		Events_Log::error( 'invalid_status', [ 'seat' => 7, 'to' => 'bogus' ] );

		$this->assertCount( 2, $this->error_log_rows() );
	}

	/** Two different exceptions from one event's sync are two different failures. */
	public function test_two_exceptions_on_one_event_are_two_rows() {
		Events_Log::error( 'product_sync_failed', [ 'event' => 5, 'exception' => 'RuntimeException', 'message' => 'a' ] );
		Events_Log::error( 'product_sync_failed', [ 'event' => 5, 'exception' => 'LogicException', 'message' => 'b' ] );

		$this->assertCount( 2, $this->error_log_rows() );
	}

	/**
	 * A failed send used to collapse across recipients because the context carried
	 * no subject id at all. Every mail failure now names the event it belongs to.
	 */
	public function test_a_failed_send_carries_the_event_it_belongs_to() {
		$event_a = $this->make_event( [ 'title' => 'Mail A' ] );
		$event_b = $this->make_event( [ 'title' => 'Mail B' ] );

		add_filter( 'pre_wp_mail', '__return_false' );
		$this->module()->send_html_email( 'a@example.test', 'Subject A', '<p>a</p>', [], $event_a );
		$this->module()->send_html_email( 'b@example.test', 'Subject B', '<p>b</p>', [], $event_b );
		remove_filter( 'pre_wp_mail', '__return_false' );

		$rows = array_values( array_filter( $this->error_log_rows(), function ( $row ) {
			return 'email_send_returned_false' === $row['code'];
		} ) );
		$this->assertCount( 2, $rows, 'Two events, two rows — not one row counted twice.' );
		$this->assertSame( $event_a, (int) $rows[0]['context']['event'] );
		$this->assertSame( $event_b, (int) $rows[1]['context']['event'] );

		// REG-D63 — and no second row under a second code for the same two
		// failures. The wp_mail_failed hook used to log `email_failed` beside
		// send_html_email()'s `email_send_returned_false`, so the capped log
		// filled twice as fast and an operator counting failures double-counted.
		$this->assertSame( [], array_values( array_filter( $this->error_log_rows(), function ( $row ) {
			return 'email_failed' === $row['code'];
		} ) ) );
	}

	/**
	 * finding-13 (carry-over) — `to` is the only other per-recipient context
	 * key on a mail failure, and Events_Log::redact() masks it to `***@domain`
	 * BEFORE the identity is computed, so two attendees on the SAME event AND
	 * the same email domain used to collapse into one deduped row even though
	 * they are two different failures about two different people. Passing the
	 * seat id through (send_html_email()'s $identity param) fixes it: same
	 * event, same domain, two seats, two rows.
	 */
	public function test_a_failed_send_to_two_seats_on_the_same_event_and_domain_is_two_rows() {
		$event_id = $this->make_event( [ 'title' => 'Same Domain Event' ] );
		$seat_a   = $this->make_seat( $event_id, [ 'email' => 'alice@example.test' ] );
		$seat_b   = $this->make_seat( $event_id, [ 'email' => 'bob@example.test' ] );

		add_filter( 'pre_wp_mail', '__return_false' );
		$this->module()->send_html_email( 'alice@example.test', 'Subject', '<p>a</p>', [], $event_id, [ 'seat' => $seat_a ] );
		$this->module()->send_html_email( 'bob@example.test', 'Subject', '<p>b</p>', [], $event_id, [ 'seat' => $seat_b ] );
		remove_filter( 'pre_wp_mail', '__return_false' );

		$rows = array_values( array_filter( $this->error_log_rows(), function ( $row ) {
			return 'email_send_returned_false' === $row['code'];
		} ) );
		$this->assertCount( 2, $rows, 'Two seats on the same event/domain, two rows — not one collapsed by the masked email.' );
		$this->assertSame( $seat_a, (int) $rows[0]['context']['seat'] );
		$this->assertSame( $seat_b, (int) $rows[1]['context']['seat'] );
	}

	/**
	 * REG-D63 — one spelling for a degraded capacity lock. with_event_lock()
	 * used to log `lock_unavailable` while the callers that also record the
	 * degradation logged `capacity_lock_unavailable`, so a search for either
	 * found half the incidents.
	 */
	public function test_a_degraded_capacity_lock_is_logged_under_one_code() {
		$event_id = $this->make_event();

		// Hold the same named lock on a second connection so GET_LOCK fails.
		global $wpdb;
		$reflect = new ReflectionMethod( $this->registrations(), 'lock_name' );
		$reflect->setAccessible( true );
		$name = $reflect->invoke( $this->registrations(), $event_id );

		$other = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, %d)', $name, 5 ) );
		try {
			$this->registrations()->with_event_lock( $event_id, function ( $locked ) {
				$this->assertFalse( $locked, 'The lock is held elsewhere for this assertion.' );
				return null;
			} );
		} finally {
			$other->get_var( $other->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		}

		$codes = array_column( $this->error_log_rows(), 'code' );
		$this->assertContains( 'capacity_lock_unavailable', $codes );
		$this->assertNotContains( 'lock_unavailable', $codes );
	}

	/** A failure that returns after the window is news, not a repeat. */
	public function test_a_repeat_after_the_window_starts_a_new_entry() {
		Events_Log::error( 'seat_insert_failed', [ 'event' => 7 ] );

		$rows                 = $this->error_log_rows();
		$rows[0]['time']      = time() - ( 25 * HOUR_IN_SECONDS );
		$rows[0]['first_time'] = $rows[0]['time'];
		update_option( Events_Log::ERROR_OPTION, $rows, false );

		Events_Log::error( 'seat_insert_failed', [ 'event' => 7 ] );

		$rows = $this->error_log_rows();
		$this->assertCount( 2, $rows, 'A recurrence a day later must not be swallowed by the old row.' );
		$this->assertSame( 1, (int) $rows[1]['count'] );
	}

	/** One noisy code must not evict every other code from the ring. */
	public function test_one_noisy_code_cannot_evict_every_other_entry() {
		Events_Log::error( 'seat_insert_failed', [ 'event' => 1 ] );
		for ( $i = 0; $i < Events_Log::ERROR_CODE_CAP + 10; $i++ ) {
			Events_Log::error( 'email_send_returned_false', [ 'order' => 1000 + $i ] );
		}

		$codes = $this->error_codes();
		$this->assertContains( 'seat_insert_failed', $codes, 'The rare entry an operator needs must survive the flood.' );
		$this->assertSame(
			Events_Log::ERROR_CODE_CAP,
			count( array_keys( $codes, 'email_send_returned_false', true ) ),
			'The noisy code is capped per code, not globally.'
		);
	}

	/* -----------------------------------------------------------------
	 * REG-D31 — clearing the log archives it
	 * --------------------------------------------------------------- */

	public function test_clearing_the_error_log_keeps_an_archived_copy() {
		Events_Log::error( 'seat_insert_failed', [ 'event' => 3 ] );
		Events_Log::error( 'illegal_transition', [ 'seat' => 4, 'from' => 'refunded', 'to' => 'confirmed' ] );

		$archived = Events_Log::archive_and_clear();

		$this->assertSame( 2, $archived );
		$this->assertSame( [], $this->error_log_rows(), 'The live log is emptied.' );
		$archive = get_option( Events_Log::ERROR_ARCHIVE_OPTION, [] );
		$this->assertCount( 2, $archive );
		$this->assertSame(
			[ 'seat_insert_failed', 'illegal_transition' ],
			array_column( $archive, 'code' ),
			'The evidence survives the tidy-up.'
		);
	}

	/** Clearing twice appends rather than overwriting the archive. */
	public function test_archives_accumulate_across_clears() {
		Events_Log::error( 'seat_insert_failed', [ 'event' => 3 ] );
		Events_Log::archive_and_clear();
		Events_Log::error( 'illegal_transition', [ 'seat' => 4 ] );
		Events_Log::archive_and_clear();

		$this->assertCount( 2, get_option( Events_Log::ERROR_ARCHIVE_OPTION, [] ) );
	}

	/* -----------------------------------------------------------------
	 * WOO-D13 — a mid-sync throw is captured, not fatal
	 * --------------------------------------------------------------- */

	public function test_a_throw_inside_the_product_sync_is_logged_and_flagged() {
		$this->require_wc();

		$boom = function () {
			throw new RuntimeException( 'variation save exploded' );
		};
		add_action( 'woocommerce_new_product_variation', $boom, 1 );

		$event_id = $this->make_event(
			[ 'title' => 'Exploding Sync', 'capacity' => 0 ],
			[ [ 'label' => 'General', 'price' => '10', 'active' => 1 ] ]
		);
		delete_option( Events_Log::ERROR_OPTION );

		$product_id = $this->product_sync()->sync_event( $event_id );

		remove_action( 'woocommerce_new_product_variation', $boom, 1 );

		$this->assertIsInt( $product_id, 'The sync must return rather than fatal.' );
		$this->assertContains( 'product_sync_failed', $this->error_codes() );

		$failure = get_post_meta( $event_id, Product_Sync::SYNC_FAILED_META, true );
		$this->assertIsArray( $failure );
		$this->assertSame( 'RuntimeException', $failure['exception'] );
		$this->assertSame( $event_id, (int) $failure['event'] );
		$this->assertStringContainsString( 'exploded', (string) $failure['message'] );
	}

	/**
	 * A throw from a third-party save_post_product handler lands AFTER the product
	 * exists but BEFORE the event → product pointer is written. Left orphaned, the
	 * next sync sees no pointer and builds a second product. The catch re-points
	 * the event at the product it already owns.
	 */
	public function test_a_throw_that_orphans_the_product_re_points_the_event() {
		$this->require_wc();

		$boom = function () {
			throw new RuntimeException( 'save_post_product exploded' );
		};
		add_action( 'woocommerce_new_product', $boom, 1 );

		$event_id = $this->make_event(
			[ 'title' => 'Orphaning Sync', 'capacity' => 0 ],
			[ [ 'label' => 'General', 'price' => '10', 'active' => 1 ] ]
		);
		$returned = $this->product_sync()->sync_event( $event_id );

		remove_action( 'woocommerce_new_product', $boom, 1 );

		$pointer = (int) get_post_meta( $event_id, Product_Sync::EVENT_PRODUCT_META, true );
		$this->assertGreaterThan( 0, $pointer, 'The orphaned product must be re-adopted, not abandoned.' );
		$this->assertSame( $event_id, (int) get_post_meta( $pointer, Product_Sync::PRODUCT_EVENT_META, true ) );
		$this->assertSame( $pointer, (int) $returned, 'The catch returns the validated product id.' );

		// …and the next sync reuses it rather than minting a second product.
		$this->assertSame( $pointer, (int) $this->product_sync()->sync_event( $event_id ) );
	}

	/** …and the marker clears itself the moment a sync completes. */
	public function test_the_sync_failure_marker_clears_on_the_next_good_sync() {
		$this->require_wc();

		$boom = function () {
			throw new RuntimeException( 'variation save exploded' );
		};
		add_action( 'woocommerce_new_product_variation', $boom, 1 );
		$event_id = $this->make_event(
			[ 'title' => 'Recovering Sync', 'capacity' => 0 ],
			[ [ 'label' => 'General', 'price' => '10', 'active' => 1 ] ]
		);
		$this->product_sync()->sync_event( $event_id );
		remove_action( 'woocommerce_new_product_variation', $boom, 1 );

		$this->assertIsArray( get_post_meta( $event_id, Product_Sync::SYNC_FAILED_META, true ) );

		$this->product_sync()->sync_event( $event_id );

		$this->assertSame(
			'',
			get_post_meta( $event_id, Product_Sync::SYNC_FAILED_META, true ),
			'A clean pass is the condition that clears the marker.'
		);
	}
}
