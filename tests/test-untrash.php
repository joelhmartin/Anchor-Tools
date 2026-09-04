<?php
/**
 * Untrash / trash-mirror tests (audit MODEL-D15, WOO-D17, WOO-D34, WOO-D52, WOO-D53).
 *
 * wp_untrash_post() restores a post's status THROUGH wp_update_post(), so
 * save_post does fire on a restore — but Module::save_meta() bails without the
 * metabox nonce, so nothing on the events save path ran again and the trash was
 * a one-way door:
 *   - a restored group parent left its children retired (MODEL-D15);
 *   - a restored order left every seat cancelled (WOO-D17);
 *   - trashing an order on legacy (posts) order storage never released its
 *     seats at all, because `woocommerce_trash_order` only fires from the WC
 *     order data store, not from the list table's `wp_trash_post()` (WOO-D52);
 *   - deleting a refund gave the money back without giving the seat back
 *     (WOO-D53).
 *
 * WOO-D34 (the managed product) needed no hook: Product_Sync::on_event_saved()
 * already republishes it off that same save_post, with the event no longer in
 * the trash. test_untrashing_event_republishes_managed_product() is here to
 * keep that true, not because anything was added.
 *
 * Every WooCommerce test here pins its order storage — see pin_order_storage().
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Registrations;

/**
 * @group untrash
 */
class Test_Untrash extends Anchor_Events_TestCase {

	/** @return \Anchor\Events\Occurrences */
	protected function occurrences() {
		return $this->module()->occurrences;
	}

	/* ------------------------------------------------------------------
	 * MODEL-D15 — group parent trash/untrash.
	 * ------------------------------------------------------------------ */

	/**
	 * Create + reconcile a group-parent offering with two dates.
	 *
	 * @return array{0:int,1:int[]} [ parent_id, live child ids (date-ascending) ]
	 */
	private function make_offering() {
		$parent_id = $this->make_event( [ 'title' => 'Offering', 'timezone' => 'UTC' ] );
		update_post_meta(
			$parent_id,
			'_anchor_event_offering_dates',
			[
				[ 'date' => '2027-05-01', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'Session A', 'capacity' => 5 ],
				[ 'date' => '2027-05-08', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'Session B', 'capacity' => 5 ],
			]
		);
		return [ $parent_id, $this->occurrences()->reconcile( $parent_id ) ];
	}

	/** Untrashing a group parent brings its trashed (unseated) children back published. */
	public function test_untrashing_parent_republishes_trashed_children() {
		[ $parent_id, $children ] = $this->make_offering();
		$this->assertCount( 2, $children );

		wp_trash_post( $parent_id );
		foreach ( $children as $child_id ) {
			$this->assertSame( 'trash', get_post_status( $child_id ), 'An unseated child is trashed with its parent.' );
		}

		wp_untrash_post( $parent_id );

		foreach ( $children as $child_id ) {
			$this->assertSame(
				'publish',
				get_post_status( $child_id ),
				'Restoring the parent must restore its occurrences.'
			);
		}

		// …and the PARENT comes back published, not left as WordPress's blanket
		// post-5.6 `draft` (NEW-D4). A draft container with a live, published
		// date set hanging off it is invisible to the author: the dates are on
		// offer and the page that offers them is not.
		$this->assertSame( 'publish', get_post_status( $parent_id ) );
	}

	/** A single event restores to the status it was trashed in, too (NEW-D4). */
	public function test_untrashing_single_event_restores_its_previous_status() {
		$published = $this->make_event( [ 'title' => 'Published' ] );
		$draft     = $this->make_event( [ 'title' => 'Draft' ] );
		wp_update_post( [ 'ID' => $draft, 'post_status' => 'draft' ] );

		foreach ( [ $published, $draft ] as $event_id ) {
			$before = get_post_status( $event_id );
			wp_trash_post( $event_id );
			$this->assertSame( 'trash', get_post_status( $event_id ) );

			wp_untrash_post( $event_id );

			$this->assertSame(
				$before,
				get_post_status( $event_id ),
				'A restored event must come back in the status it was trashed in.'
			);
		}
	}

	/** A non-event post keeps WordPress's own untrash behaviour. */
	public function test_untrashing_a_plain_post_is_left_to_wordpress() {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );

		wp_trash_post( $post_id );
		wp_untrash_post( $post_id );

		$this->assertSame(
			'draft',
			get_post_status( $post_id ),
			'The events filter must not change how any other post type is restored.'
		);
	}

	/** ...and the parent's date picker lists them again. */
	public function test_untrashed_parent_picker_lists_children_again() {
		[ $parent_id, $children ] = $this->make_offering();

		wp_trash_post( $parent_id );
		$this->assertStringNotContainsString(
			esc_url( get_permalink( $children[0] ) ),
			$this->module()->render_choose_date_list( $parent_id ),
			'A retired occurrence must not still be offered.'
		);

		wp_untrash_post( $parent_id );

		$html = $this->module()->render_choose_date_list( $parent_id );
		foreach ( $children as $child_id ) {
			$this->assertStringContainsString( esc_url( get_permalink( $child_id ) ), $html );
		}
		$this->assertStringContainsString( 'May 1, 2027', $html );
		$this->assertStringContainsString( 'May 8, 2027', $html );
	}

	/** A SEATED child is soft-closed by the trash, and reopened by the untrash. */
	public function test_untrashing_parent_reopens_soft_closed_child() {
		[ $parent_id, $children ] = $this->make_offering();
		$seated = (int) $children[0];
		$this->make_seat( $seated );

		wp_trash_post( $parent_id );
		$this->assertSame( 'publish', get_post_status( $seated ), 'A seated child is preserved, not trashed.' );
		$this->assertTrue( $this->occurrences()->is_closed( $seated ), 'A seated child is soft-closed.' );

		wp_untrash_post( $parent_id );

		$this->assertFalse(
			$this->occurrences()->is_closed( $seated ),
			'Restoring the parent must reopen a soft-closed occurrence.'
		);
		$this->assertNotSame(
			'cancelled',
			get_post_meta( $seated, '_anchor_event_status', true ),
			'The reopened occurrence must not stay cancelled.'
		);
	}

	/**
	 * …and it comes back BOOKABLE. The soft close writes
	 * registration_enabled=false as one quarter of the closed quartet, so a
	 * parent trash/untrash round trip used to hand the author back a seated
	 * date that was listed on the picker and refused every booking.
	 */
	public function test_untrashing_parent_reopens_registration_on_a_seated_child() {
		[ $parent_id, $children ] = $this->make_offering();
		$seated = (int) $children[0];
		$this->make_seat( $seated );

		$this->assertTrue(
			(bool) get_post_meta( $seated, '_anchor_event_registration_enabled', true ),
			'Precondition: the date is bookable before the trash.'
		);

		wp_trash_post( $parent_id );
		$this->assertFalse(
			(bool) get_post_meta( $seated, '_anchor_event_registration_enabled', true ),
			'The soft close turns registration off.'
		);

		wp_untrash_post( $parent_id );

		$this->assertTrue(
			(bool) get_post_meta( $seated, '_anchor_event_registration_enabled', true ),
			'Restoring the parent must give a reopened date its registration back.'
		);
	}

	/** A plain (non-parent) event untrash must not be turned into a group parent. */
	public function test_untrashing_plain_event_does_not_create_occurrences() {
		$event_id = $this->make_event( [ 'title' => 'Standalone' ] );

		wp_trash_post( $event_id );
		wp_untrash_post( $event_id );

		$this->assertSame(
			'',
			(string) get_post_meta( $event_id, '_anchor_event_group_role', true ),
			'An ordinary event must never be stamped as a group parent by an untrash.'
		);
	}

	/* ------------------------------------------------------------------
	 * WOO-D17 / WOO-D52 — order trash/untrash on legacy (posts) storage.
	 * ------------------------------------------------------------------ */

	/**
	 * Build a paid event + its managed variation.
	 *
	 * @return array{event_id:int,tier_id:string,variation_id:int}
	 */
	private function paid_event_with_variation() {
		$event_id = $this->make_event(
			[ 'title' => 'Untrash Event', 'capacity' => 0 ],
			[ [ 'label' => 'General', 'price' => '10', 'active' => 1 ] ]
		);
		$this->product_sync()->sync_event( $event_id );
		$tiers        = $this->ticket_types()->get( $event_id );
		$tier_id      = $tiers[0]['id'];
		$variation_id = $this->product_sync()->variation_for_tier( $event_id, $tier_id );
		$this->assertGreaterThan( 0, $variation_id );

		return [ 'event_id' => $event_id, 'tier_id' => $tier_id, 'variation_id' => $variation_id ];
	}

	/**
	 * A processing order with one event line of $qty seats + attendee meta.
	 *
	 * @return array{order:WC_Order,item_id:int}
	 */
	private function make_order( $variation_id, $qty ) {
		$item = new WC_Order_Item_Product();
		$item->set_product( wc_get_product( $variation_id ) );
		$item->set_quantity( $qty );
		$item->set_subtotal( 10 * $qty );
		$item->set_total( 10 * $qty );

		$attendees = [];
		for ( $i = 1; $i <= $qty; $i++ ) {
			$attendees[ $i ] = [
				'name'  => 'Attendee ' . $i,
				'email' => 'attendee' . $i . '@example.test',
				'phone' => '555-000' . $i,
			];
		}
		$item->add_meta_data( '_anchor_attendees', $attendees, true );

		$order = new WC_Order();
		$order->add_item( $item );
		$order->set_billing_email( 'buyer@example.test' );
		$order->set_billing_first_name( 'Buyer' );
		$order->calculate_totals( false );
		$order->save();

		$order->set_status( 'processing' );
		$order->save();

		return [ 'order' => $order, 'item_id' => $item->get_id() ];
	}

	/**
	 * Pin the order storage for this test.
	 *
	 * Whether HPOS is on is NOT stable across runs: on its own this class sees
	 * legacy (posts) storage, but in the full suite an earlier test boots
	 * WooCommerce far enough for its installer to turn HPOS on. Unpinned, the
	 * storage-specific regressions here would pass, skip, or silently test the
	 * wrong path depending on what ran before them, so every WC test in this
	 * file states which storage it means.
	 *
	 * Pinned with `pre_option_…`, never update_option(): writing the real
	 * option runs CustomOrdersTableController::process_pre_update_option(),
	 * which flushes the order cache, may run CREATE TABLE, and throws outright
	 * when orders are pending sync. The filter forces the identical reading —
	 * custom_orders_table_usage_is_enabled() and the
	 * `woocommerce_order_data_store` selector both get_option() live — with none
	 * of those side effects, and WP_UnitTestCase's hook backup removes it at
	 * tear-down.
	 *
	 * @param bool $hpos True to force HPOS, false to force legacy posts storage.
	 */
	private function pin_order_storage( $hpos ) {
		$this->require_wc();
		$want = $hpos ? 'yes' : 'no';
		add_filter(
			'pre_option_woocommerce_custom_orders_table_enabled',
			static function () use ( $want ) {
				return $want;
			}
		);
		if (
			! class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			|| \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() !== (bool) $hpos
		) {
			$this->markTestSkipped( 'Could not pin order storage to ' . ( $hpos ? 'HPOS' : 'legacy posts' ) . ' in this run.' );
		}
	}

	/**
	 * Force legacy (posts) order storage — the path under test, and the one
	 * this store actually runs (woocommerce_custom_orders_table_enabled = no).
	 */
	private function require_cpt_order_storage() {
		$this->pin_order_storage( false );
	}

	/**
	 * Force HPOS. Skipped rather than failed when the orders tables are absent
	 * from the test database — HPOS storage cannot be exercised without them.
	 */
	private function require_hpos_order_storage() {
		$this->require_wc();
		global $wpdb;
		$table  = $wpdb->prefix . 'wc_orders';
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			$this->markTestSkipped( 'HPOS orders tables are not present in this test database.' );
		}
		$this->pin_order_storage( true );
	}

	/** WOO-D52: trashing an order on legacy storage releases its seats. */
	public function test_trashing_legacy_order_releases_seats() {
		$this->require_cpt_order_storage();

		$ctx      = $this->paid_event_with_variation();
		$res      = $this->make_order( $ctx['variation_id'], 2 );
		$order_id = (int) $res['order']->get_id();

		$this->woocommerce()->reconcile_order( wc_get_order( $order_id ), 'initial' );
		$this->assertSame( 2, $this->count_seats( $ctx['event_id'], Registrations::STATUS_CONFIRMED ) );

		wp_trash_post( $order_id );

		$this->assertSame(
			0,
			$this->count_seats( $ctx['event_id'], Registrations::STATUS_CONFIRMED ),
			'Trashing the order from the posts list must release its capacity.'
		);
		$this->assertSame( 2, $this->count_seats( $ctx['event_id'], Registrations::STATUS_CANCELLED ) );
	}

	/** WOO-D17: restoring a trashed processing order confirms its seats again. */
	public function test_untrashing_legacy_order_restores_seats() {
		$this->require_cpt_order_storage();

		$ctx      = $this->paid_event_with_variation();
		$res      = $this->make_order( $ctx['variation_id'], 2 );
		$order_id = (int) $res['order']->get_id();

		$this->woocommerce()->reconcile_order( wc_get_order( $order_id ), 'initial' );
		wp_trash_post( $order_id );
		$this->assertSame( 0, $this->count_seats( $ctx['event_id'], Registrations::STATUS_CONFIRMED ) );

		wp_untrash_post( $order_id );

		$this->assertSame(
			'processing',
			wc_get_order( $order_id )->get_status(),
			'WooCommerce restores the order to its pre-trash status.'
		);
		$this->assertSame(
			2,
			$this->count_seats( $ctx['event_id'], Registrations::STATUS_CONFIRMED ),
			'Restoring the order must restore its seats.'
		);
		$this->assertSame( 0, $this->count_seats( $ctx['event_id'], Registrations::STATUS_CANCELLED ) );
	}

	/**
	 * A restore reconciles to the order's REAL status, not to "it was trashed,
	 * so put everything back". A cancelled order that is trashed and restored
	 * comes back cancelled, and its seats must stay released.
	 */
	public function test_untrashing_cancelled_order_does_not_confirm_seats() {
		$this->require_cpt_order_storage();

		$ctx      = $this->paid_event_with_variation();
		$res      = $this->make_order( $ctx['variation_id'], 2 );
		$order_id = (int) $res['order']->get_id();

		$this->woocommerce()->reconcile_order( wc_get_order( $order_id ), 'initial' );
		$this->assertSame( 2, $this->count_seats( $ctx['event_id'], Registrations::STATUS_CONFIRMED ) );

		// Cancel the order first — the status change releases its seats.
		$order = wc_get_order( $order_id );
		$order->set_status( 'cancelled' );
		$order->save();
		$this->assertSame( 0, $this->count_seats( $ctx['event_id'], Registrations::STATUS_CONFIRMED ) );

		wp_trash_post( $order_id );
		wp_untrash_post( $order_id );

		$this->assertSame(
			'cancelled',
			wc_get_order( $order_id )->get_status(),
			'The order is restored to the status it was trashed in.'
		);
		$this->assertSame(
			0,
			$this->count_seats( $ctx['event_id'], Registrations::STATUS_CONFIRMED ),
			'Restoring a cancelled order must NOT confirm its seats.'
		);
		$this->assertSame( 2, $this->count_seats( $ctx['event_id'], Registrations::STATUS_CANCELLED ) );
		$this->assertSame( 0, $this->registrations()->count_reserved_seats( $ctx['event_id'], true ) );
	}

	/**
	 * HPOS: a trashed processing order still ends up with confirmed seats after
	 * a restore — but NOT via on_order_untrashed().
	 *
	 * OrdersTableDataStore::untrash_order() fires `woocommerce_untrash_order`
	 * BEFORE it restores the status, so our handler sees 'trash', maps it to
	 * SEAT_TARGET_UNKNOWN and leaves the seats alone; the repair comes from the
	 * set_status()+save() that follows, via woocommerce_order_status_changed →
	 * on_status_changed(). This test pins both halves of that, so a future
	 * "simplification" that drops one of the two hooks fails here.
	 */
	public function test_untrashing_hpos_order_restores_seats_via_status_change() {
		$this->require_hpos_order_storage();

		$ctx      = $this->paid_event_with_variation();
		$res      = $this->make_order( $ctx['variation_id'], 2 );
		$order_id = (int) $res['order']->get_id();

		$this->woocommerce()->reconcile_order( wc_get_order( $order_id ), 'initial' );
		$this->assertSame( 2, $this->count_seats( $ctx['event_id'], Registrations::STATUS_CONFIRMED ) );

		// Trash through the HPOS data store (delete with force_delete = false).
		wc_get_order( $order_id )->delete( false );
		$this->assertSame(
			0,
			$this->count_seats( $ctx['event_id'], Registrations::STATUS_CONFIRMED ),
			'Trashing releases the capacity.'
		);

		// Record what the order actually reads at woocommerce_untrash_order time.
		$status_at_hook = null;
		add_action(
			'woocommerce_untrash_order',
			static function ( $id ) use ( &$status_at_hook ) {
				$o              = wc_get_order( $id );
				$status_at_hook = $o ? $o->get_status() : null;
			},
			1,
			1
		);

		$order = wc_get_order( $order_id );
		$order->get_data_store()->untrash_order( $order );

		$this->assertSame(
			'trash',
			$status_at_hook,
			'HPOS fires woocommerce_untrash_order BEFORE restoring the status — on_order_untrashed() cannot repair anything there.'
		);
		$this->assertSame(
			'processing',
			wc_get_order( $order_id )->get_status(),
			'The order is restored to its pre-trash status.'
		);
		$this->assertSame(
			2,
			$this->count_seats( $ctx['event_id'], Registrations::STATUS_CONFIRMED ),
			'The status-changed pass that follows the restore must reconfirm the seats.'
		);
	}

	/** Trashing an ordinary (non-event) order is a complete no-op. */
	public function test_trashing_non_event_order_is_a_no_op() {
		$this->require_cpt_order_storage();

		$product = new WC_Product_Simple();
		$product->set_name( 'Plain Widget' );
		$product->set_regular_price( '5' );
		$product->save();

		$order = new WC_Order();
		$order->add_product( $product, 1 );
		$order->calculate_totals( false );
		$order->save();
		$order->set_status( 'processing' );
		$order->save();
		$order_id = (int) $order->get_id();

		wp_trash_post( $order_id );
		wp_untrash_post( $order_id );

		$this->assertSame( '', (string) get_post_meta( $order_id, '_anchor_event_sync_log', true ) );
	}

	/* ------------------------------------------------------------------
	 * WOO-D53 — refund deleted.
	 * ------------------------------------------------------------------ */

	/**
	 * Deleting a refund gives the seat back: the line's active seat count
	 * returns to the ordered quantity and the released capacity is re-taken.
	 */
	public function test_deleting_refund_restores_the_released_seat() {
		// Pinned to this store's real storage so the refund path is exercised
		// the same way on every run (see pin_order_storage()).
		$this->require_cpt_order_storage();

		$ctx      = $this->paid_event_with_variation();
		$res      = $this->make_order( $ctx['variation_id'], 2 );
		$order_id = (int) $res['order']->get_id();
		$item_id  = (int) $res['item_id'];

		$this->woocommerce()->reconcile_order( wc_get_order( $order_id ), 'initial' );
		$this->assertSame( 2, $this->count_seats( $ctx['event_id'], Registrations::STATUS_CONFIRMED ) );

		$refund = wc_create_refund(
			[
				'order_id'   => $order_id,
				'amount'     => 10,
				'line_items' => [ $item_id => [ 'qty' => 1, 'refund_total' => 10 ] ],
			]
		);
		$this->assertNotWPError( $refund );
		$refund_id = (int) $refund->get_id();

		$this->woocommerce()->on_order_refunded( $order_id, $refund_id );
		$this->assertSame( 1, $this->count_seats( $ctx['event_id'], Registrations::STATUS_CONFIRMED ) );
		$this->assertSame( 1, $this->count_seats( $ctx['event_id'], Registrations::STATUS_REFUNDED ) );

		// WooCommerce deletes the refund, then fires the hook (WC_AJAX::delete_refund).
		$refund->delete( true );
		do_action( 'woocommerce_refund_deleted', $refund_id, $order_id );

		$this->assertSame(
			2,
			$this->count_seats( $ctx['event_id'], Registrations::STATUS_CONFIRMED ),
			'Deleting the refund must give the seat back.'
		);
		$this->assertSame(
			2,
			$this->registrations()->count_reserved_seats( $ctx['event_id'], true ),
			'…and re-take the capacity the refund released.'
		);
	}

	/* ------------------------------------------------------------------
	 * WOO-D34 — event trash/untrash vs. the managed product.
	 * ------------------------------------------------------------------ */

	/** Untrashing a paid event republishes the managed product the trash drafted. */
	public function test_untrashing_event_republishes_managed_product() {
		// No order is involved, but pin the storage anyway so the whole WC half
		// of this file runs against one known configuration.
		$this->require_cpt_order_storage();

		$ctx        = $this->paid_event_with_variation();
		$event_id   = $ctx['event_id'];
		$product_id = $this->product_sync()->managed_product_id( $event_id );
		$this->assertGreaterThan( 0, $product_id );
		$this->assertSame( 'publish', wc_get_product( $product_id )->get_status() );

		wp_trash_post( $event_id );
		$this->assertSame( 'draft', wc_get_product( $product_id )->get_status() );

		wp_untrash_post( $event_id );

		$this->assertSame(
			'publish',
			wc_get_product( $product_id )->get_status(),
			'Restoring the event must republish its managed product.'
		);
		$this->assertSame(
			$product_id,
			$this->product_sync()->managed_product_id( $event_id ),
			'…the same product, not a second one.'
		);
	}
}
