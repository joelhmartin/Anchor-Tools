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
}
