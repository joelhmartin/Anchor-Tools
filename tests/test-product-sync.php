<?php
/**
 * Product_Sync tests (require WooCommerce — skipped when WC is inactive).
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Product_Sync;
use Anchor\Events\WooCommerce;

/**
 * @group woocommerce
 * @group product-sync
 */
class Test_Product_Sync extends Anchor_Events_TestCase {

	public function set_up() {
		parent::set_up();
		$this->require_wc();
	}

	/** Count managed variation children of a product across statuses. */
	private function variation_count( $product_id ) {
		return count(
			get_posts(
				[
					'post_type'      => 'product_variation',
					'post_parent'    => (int) $product_id,
					'post_status'    => [ 'publish', 'private', 'draft' ],
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				]
			)
		);
	}

	/** Create an event with two paid+active tiers. Returns [event_id, ga, vip]. */
	private function make_two_tier_event() {
		$event_id = $this->make_event(
			[ 'title' => 'Paid Event' ],
			[
				[ 'label' => 'General', 'price' => '10', 'active' => 1 ],
				[ 'label' => 'VIP', 'price' => '50', 'active' => 1 ],
			]
		);
		$tiers = $this->ticket_types()->get( $event_id );
		return [ $event_id, $tiers[0], $tiers[1] ];
	}

	/** sync_event builds a hidden variable product with one variation per paid tier. */
	public function test_sync_creates_hidden_variable_product_with_variations() {
		list( $event_id, $ga, $vip ) = $this->make_two_tier_event();

		$product_id = $this->product_sync()->sync_event( $event_id );
		$this->assertGreaterThan( 0, $product_id );

		$product = wc_get_product( $product_id );
		$this->assertNotNull( $product );
		$this->assertTrue( $product->is_type( 'variable' ) );
		$this->assertSame( 'hidden', $product->get_catalog_visibility() );
		$this->assertSame( 'publish', $product->get_status() );

		// Link meta on the parent so the checkout/reconcile resolver recognizes it.
		$this->assertSame( '1', (string) $product->get_meta( WooCommerce::META_ENABLED ) );
		$this->assertSame( $event_id, (int) $product->get_meta( Product_Sync::PRODUCT_EVENT_META ) );

		// Two variations.
		$this->assertSame( 2, $this->variation_count( $product_id ) );

		$ga_vid  = $this->product_sync()->variation_for_tier( $event_id, $ga['id'] );
		$vip_vid = $this->product_sync()->variation_for_tier( $event_id, $vip['id'] );
		$this->assertGreaterThan( 0, $ga_vid );
		$this->assertGreaterThan( 0, $vip_vid );
		$this->assertNotSame( $ga_vid, $vip_vid );

		$ga_var  = wc_get_product( $ga_vid );
		$vip_var = wc_get_product( $vip_vid );

		$this->assertSame( 10.0, (float) $ga_var->get_regular_price() );
		$this->assertSame( 50.0, (float) $vip_var->get_regular_price() );

		// Per-variation link + tier meta.
		$this->assertSame( $event_id, (int) $ga_var->get_meta( WooCommerce::META_EVENT_ID ) );
		$this->assertSame( $event_id, (int) $vip_var->get_meta( WooCommerce::META_EVENT_ID ) );
		$this->assertSame( $ga['id'], (string) $ga_var->get_meta( Product_Sync::VARIATION_TIER_META ) );
		$this->assertSame( $vip['id'], (string) $vip_var->get_meta( Product_Sync::VARIATION_TIER_META ) );

		// WOO-D8: the write-only `_anchor_evt_tier_active` meta is gone —
		// post_status ('publish' here) is the single source of truth.
		$this->assertSame( '', $ga_var->get_meta( '_anchor_evt_tier_active' ) );
		$this->assertSame( 'publish', $ga_var->get_status() );

		// Reverse lookup resolves the variation back to its event + tier.
		$resolved = $this->product_sync()->tier_for_variation( $ga_vid );
		$this->assertSame( $event_id, $resolved['event_id'] );
		$this->assertSame( $ga['id'], $resolved['tier_id'] );
	}

	/** Renaming + repricing a tier updates the SAME variation (no orphan). */
	public function test_rename_and_reprice_tier_updates_same_variation() {
		list( $event_id, $ga, $vip ) = $this->make_two_tier_event();
		$this->product_sync()->sync_event( $event_id );

		$ga_vid_before = $this->product_sync()->variation_for_tier( $event_id, $ga['id'] );
		$this->assertGreaterThan( 0, $ga_vid_before );

		// Re-save preserving ids: rename GA + bump its price.
		$tiers          = $this->ticket_types()->get( $event_id );
		$tiers[0]['label'] = 'General Admission';
		$tiers[0]['price'] = 15.0;
		$this->ticket_types()->save( $event_id, $tiers );

		$product_id = $this->product_sync()->sync_event( $event_id );

		$ga_vid_after = $this->product_sync()->variation_for_tier( $event_id, $ga['id'] );
		$this->assertSame( $ga_vid_before, $ga_vid_after, 'The GA variation id must be stable across edits.' );
		$this->assertSame( 2, $this->variation_count( $product_id ), 'No orphan variation should be created.' );

		$ga_var = wc_get_product( $ga_vid_after );
		$this->assertSame( 15.0, (float) $ga_var->get_regular_price() );
		$this->assertSame( 'General Admission', (string) $ga_var->get_description() );
	}

	/** Removing a tier that has no seats deletes its variation. */
	public function test_remove_no_sales_tier_deletes_variation() {
		list( $event_id, $ga, $vip ) = $this->make_two_tier_event();
		$product_id = $this->product_sync()->sync_event( $event_id );
		$this->assertSame( 2, $this->variation_count( $product_id ) );

		$vip_vid = $this->product_sync()->variation_for_tier( $event_id, $vip['id'] );
		$this->assertGreaterThan( 0, $vip_vid );

		// Drop VIP (which has no seats) → its variation is deleted, not deactivated.
		$this->ticket_types()->save(
			$event_id,
			[ [ 'id' => $ga['id'], 'label' => 'General', 'price' => '10', 'active' => 1 ] ]
		);
		$this->product_sync()->sync_event( $event_id );

		$this->assertSame( 1, $this->variation_count( $product_id ) );
		$this->assertSame( 0, $this->product_sync()->variation_for_tier( $event_id, $vip['id'] ) );
		// The variation post is force-deleted; check the DB directly (wc_get_product
		// can return a stale runtime-cached object after deletion).
		wp_cache_flush();
		$this->assertNull( get_post( $vip_vid ), 'The removed no-sales tier variation should be hard-deleted.' );
	}

	/**
	 * WOO-D12: a hand-added variation with NO tier-id meta is an orphan the
	 * sync used to never see again (never in $specs, never deleted, never
	 * reconciled) — it stays published and sellable under the managed
	 * product forever. A sync must now deactivate it.
	 */
	public function test_sync_deactivates_a_hand_added_variation_with_no_tier_meta() {
		list( $event_id, $ga ) = $this->make_two_tier_event();
		$product_id = $this->product_sync()->sync_event( $event_id );

		$orphan = new WC_Product_Variation();
		$orphan->set_parent_id( $product_id );
		$orphan->set_regular_price( '999' );
		$orphan_id = $orphan->save();
		$this->assertSame( 'publish', get_post_status( $orphan_id ) );

		$this->product_sync()->sync_event( $event_id );

		$this->assertSame(
			'private',
			get_post_status( $orphan_id ),
			'A variation with no tier-id meta must be swept (deactivated), not left published and invisible to future syncs.'
		);
	}

	/**
	 * WOO-D12: a SECOND variation sharing an already-indexed tier id (a
	 * failed half-sync, or a manual duplicate) is otherwise invisible to the
	 * sync forever — kept alive, sellable, with its own stale price.
	 */
	public function test_sync_deactivates_a_duplicate_variation_for_the_same_tier_id() {
		list( $event_id, $ga ) = $this->make_two_tier_event();
		$product_id = $this->product_sync()->sync_event( $event_id );
		$real_vid   = (int) $this->product_sync()->variation_for_tier( $event_id, $ga['id'] );
		$this->assertGreaterThan( 0, $real_vid );

		$duplicate = new WC_Product_Variation();
		$duplicate->set_parent_id( $product_id );
		$duplicate->set_regular_price( '999' );
		$duplicate->update_meta_data( Product_Sync::VARIATION_TIER_META, $ga['id'] );
		$duplicate_id = $duplicate->save();
		$this->assertSame( 'publish', get_post_status( $duplicate_id ) );

		$this->product_sync()->sync_event( $event_id );

		$this->assertSame(
			'private',
			get_post_status( $duplicate_id ),
			'A duplicate variation for an already-claimed tier id must be swept, not left published forever.'
		);
		// The original, already-indexed variation is untouched.
		$this->assertSame( 'publish', get_post_status( $real_vid ) );
	}

	/** Trashing the event demotes the managed product to draft (never deleted). */
	public function test_trash_event_drafts_product() {
		list( $event_id ) = $this->make_two_tier_event();
		$product_id       = $this->product_sync()->sync_event( $event_id );
		$this->assertSame( 'publish', wc_get_product( $product_id )->get_status() );

		wp_trash_post( $event_id );

		$this->assertSame( 'draft', wc_get_product( $product_id )->get_status() );
	}

	/** A second sync with no event change is a no-op (same product + variation ids). */
	public function test_sync_is_idempotent() {
		list( $event_id, $ga, $vip ) = $this->make_two_tier_event();

		$pid_1   = $this->product_sync()->sync_event( $event_id );
		$ga_1    = $this->product_sync()->variation_for_tier( $event_id, $ga['id'] );
		$vip_1   = $this->product_sync()->variation_for_tier( $event_id, $vip['id'] );
		$count_1 = $this->variation_count( $pid_1 );

		$pid_2   = $this->product_sync()->sync_event( $event_id );
		$ga_2    = $this->product_sync()->variation_for_tier( $event_id, $ga['id'] );
		$vip_2   = $this->product_sync()->variation_for_tier( $event_id, $vip['id'] );
		$count_2 = $this->variation_count( $pid_2 );

		$this->assertSame( $pid_1, $pid_2 );
		$this->assertSame( $ga_1, $ga_2 );
		$this->assertSame( $vip_1, $vip_2 );
		$this->assertSame( $count_1, $count_2 );
	}

	/** An event with no paid+active tier yields no managed product. */
	public function test_free_event_creates_no_product() {
		$event_id = $this->make_event(
			[],
			[ [ 'label' => 'Free', 'price' => '0', 'active' => 1 ] ]
		);
		$this->assertSame( 0, $this->product_sync()->sync_event( $event_id ) );
		$this->assertSame( 0, $this->product_sync()->managed_product_id( $event_id ) );
	}

	/**
	 * WOO-D6: an event with NO authored tier list — only a legacy `price`
	 * meta — syncs a product from the synthesized implicit-primary tier, but
	 * must NOT materialize `_anchor_event_ticket_types` in the process. Once
	 * that meta exists, Ticket_Types::get() stops re-reading `price` on every
	 * load, so editing the event's price field would silently do nothing.
	 */
	public function test_sync_does_not_materialize_ticket_types_meta_for_a_legacy_priced_event() {
		$event_id = $this->make_event( [ 'title' => 'Legacy Priced', 'price' => '500' ] );

		$product_id = $this->product_sync()->sync_event( $event_id );

		$this->assertGreaterThan( 0, $product_id );
		$this->assertSame(
			'',
			get_post_meta( $event_id, Anchor\Events\Ticket_Types::META_KEY, true ),
			'The implicit primary tier must not become real stored tier meta.'
		);

		// The variation is still discoverable via variation_for_tier()'s
		// fallback scan, so nothing that reads it is broken by the skip.
		$vid = (int) $this->product_sync()->variation_for_tier( $event_id, Anchor\Events\Ticket_Types::PRIMARY_ID );
		$this->assertGreaterThan( 0, $vid );

		// Changing the legacy price still takes effect — proof the price
		// field was not shadowed by a frozen stored tier row.
		update_post_meta( $event_id, '_anchor_event_price', '750' );
		$tiers = $this->ticket_types()->get( $event_id );
		$this->assertSame( 750.0, $tiers[0]['price'] );
	}

	/**
	 * WOO-D36: an event explicitly authored as External registration — but
	 * still carrying a legacy `price` (for display) that synthesizes an
	 * active-priced implicit tier — must NOT get a managed WooCommerce
	 * product. Occurrences::sync_product() already gates on registration_mode
	 * before calling sync_event() for a child occurrence; do_sync_event()
	 * itself had no such gate on the direct save_post path.
	 */
	public function test_external_mode_event_with_a_legacy_price_gets_no_product() {
		$event_id = $this->make_event( [
			'title'              => 'External Course',
			'registration_mode'  => 'external',
			'external_url'       => 'https://example.test/register',
			'price'              => '500',
		] );

		$this->assertSame( 0, $this->product_sync()->sync_event( $event_id ) );
		$this->assertSame( 0, $this->product_sync()->managed_product_id( $event_id ) );
	}

	/** Same, for the Free registration mode with an authored paid+active tier. */
	public function test_free_mode_event_with_a_paid_active_tier_gets_no_product() {
		$event_id = $this->make_event(
			[ 'title' => 'Free-mode Course', 'registration_mode' => 'free' ],
			[ [ 'label' => 'General', 'price' => '10', 'active' => 1 ] ]
		);

		$this->assertSame( 0, $this->product_sync()->sync_event( $event_id ) );
		$this->assertSame( 0, $this->product_sync()->managed_product_id( $event_id ) );
	}

	/**
	 * A 'wc' mode event switched to 'external'/'free' must have its existing
	 * managed product demoted, not left live and purchasable.
	 */
	public function test_switching_a_wc_event_to_external_demotes_its_existing_product() {
		$event_id = $this->make_event(
			[ 'title' => 'Switching Course' ],
			[ [ 'label' => 'General', 'price' => '10', 'active' => 1 ] ]
		);
		$product_id = $this->product_sync()->sync_event( $event_id );
		$this->assertGreaterThan( 0, $product_id );

		update_post_meta( $event_id, '_anchor_event_registration_mode', 'external' );
		$this->assertSame( 0, (int) $this->product_sync()->sync_event( $event_id ) );
		$this->assertSame( 'draft', get_post_status( $product_id ) );
	}
}
