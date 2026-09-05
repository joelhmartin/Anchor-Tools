<?php
/**
 * Admin product/variation linking UI + mirror maintenance guards
 * (WOO-D10, WOO-D11, WOO-D12, WOO-D30, WOO-D31, WOO-D32).
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Product_Sync;
use Anchor\Events\WooCommerce;

/**
 * @group woocommerce
 */
class Test_WooCommerce_Admin_Linking extends Anchor_Events_TestCase {

	public function set_up() {
		parent::set_up();
		$this->require_wc();
	}

	/** A paid event with one active tier, synced to its managed product. */
	private function managed_event() {
		$event_id = $this->make_event(
			[ 'title' => 'Managed Event', 'timezone' => 'UTC' ],
			[ [ 'label' => 'General', 'price' => '10', 'active' => 1 ] ]
		);
		$product_id = (int) $this->product_sync()->sync_event( $event_id );
		$this->assertGreaterThan( 0, $product_id );
		$tiers = $this->ticket_types()->get( $event_id );
		$vid   = (int) $this->product_sync()->variation_for_tier( $event_id, $tiers[0]['id'] );
		$this->assertGreaterThan( 0, $vid );
		return [ $event_id, $product_id, $vid ];
	}

	/**
	 * WOO-D10: render_variation_fields() must not print the manual "Event
	 * Registration" selector on a managed product's variation —
	 * render_product_data_panel() already refuses the same UI on the parent
	 * for the identical reason.
	 */
	public function test_render_variation_fields_is_silent_for_a_managed_variation() {
		list( , , $vid ) = $this->managed_event();

		ob_start();
		$this->woocommerce()->render_variation_fields( 0, [], get_post( $vid ) );
		$html = ob_get_clean();

		$this->assertSame( '', $html, 'A managed variation must render no manual link UI at all.' );
	}

	/** An UNmanaged (self-linked) variation keeps the manual selector. */
	public function test_render_variation_fields_still_renders_for_an_unmanaged_variation() {
		$product = new WC_Product_Variable();
		$product->set_name( 'Self-managed' );
		$product_id = $product->save();
		$variation  = new WC_Product_Variation();
		$variation->set_parent_id( $product_id );
		$variation_id = $variation->save();

		ob_start();
		$this->woocommerce()->render_variation_fields( 0, [], get_post( $variation_id ) );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Event Registration', $html );
	}

	/**
	 * WOO-D11: save_variation_link() must refuse to write on a managed
	 * variation — a misaligned/absent posted index would otherwise zero the
	 * link meta the checkout resolver depends on.
	 */
	public function test_save_variation_link_does_not_touch_a_managed_variation() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		list( $event_id, , $vid ) = $this->managed_event();
		$before = (int) get_post_meta( $vid, WooCommerce::META_EVENT_ID, true );
		$this->assertSame( $event_id, $before );

		// Simulate WC posting an empty/misaligned index for this loop position
		// — exactly the scenario that used to zero the link.
		$_POST[ WooCommerce::META_EVENT_ID ] = [ 0 => 0 ];
		$this->woocommerce()->save_variation_link( $vid, 0 );
		unset( $_POST[ WooCommerce::META_EVENT_ID ] );

		$this->assertSame(
			$event_id,
			(int) get_post_meta( $vid, WooCommerce::META_EVENT_ID, true ),
			'A managed variation\'s link meta must survive an admin form save.'
		);
	}

	/**
	 * WOO-D30: a variation-only save (the ONLY hooks it fires are
	 * woocommerce_{update,new}_product_variation, not
	 * woocommerce_update_product) must still rebuild the denormalized
	 * linked-products mirror — before this, only a save of the PARENT
	 * product rebuilt it, so a variation-only change could leave
	 * event_is_linked() (the mirror) disagreeing with products_for_event()
	 * (the live query) indefinitely.
	 */
	public function test_a_variation_only_save_rebuilds_the_mirror() {
		$event_id = $this->make_event( [ 'title' => 'Self-managed link target' ] );

		$product = new WC_Product_Variable();
		$product->set_name( 'Self-managed' );
		$product->update_meta_data( WooCommerce::META_ENABLED, '1' );
		$product_id = $product->save();

		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $product_id );
		$variation->update_meta_data( WooCommerce::META_EVENT_ID, $event_id );
		$variation_id = $variation->save();

		// The live query already agrees; simulate the mirror having gone
		// stale (e.g. built before this variation existed).
		update_post_meta( $event_id, $this->module()->meta_key( 'linked_products' ), [] );
		$this->assertFalse( $this->woocommerce()->event_is_linked( $event_id ) );
		$this->assertNotSame( [], $this->woocommerce()->products_for_event( $event_id ) );

		$this->woocommerce()->on_variation_saved( $variation_id );

		$this->assertTrue(
			$this->woocommerce()->event_is_linked( $event_id ),
			'The mirror must be rebuilt from a variation-only save, not just a parent-product save.'
		);
	}

	/**
	 * WOO-D31: products_for_event()'s two product/variation link queries must
	 * be capped, not `posts_per_page => -1` — an uncapped meta query used to
	 * run on every legacy-link render and every mirror rebuild.
	 */
	public function test_products_for_event_caps_its_queries() {
		$event_id = $this->make_event();

		$captured = [];
		$spy      = function ( $query ) use ( &$captured ) {
			if ( in_array( $query->get( 'post_type' ), [ 'product', 'product_variation' ], true ) ) {
				$captured[] = (int) $query->get( 'posts_per_page' );
			}
			return $query;
		};
		add_action( 'pre_get_posts', $spy );
		$this->woocommerce()->products_for_event( $event_id );
		remove_action( 'pre_get_posts', $spy );

		$this->assertNotEmpty( $captured, 'Precondition: the product/variation queries must actually run.' );
		foreach ( $captured as $per_page ) {
			$this->assertSame( WooCommerce::LINK_QUERY_CAP, $per_page );
			$this->assertGreaterThan( 0, $per_page, 'Must never be the uncapped -1.' );
		}
	}

	/** An unmanaged variation on a plain (non-managed) variable product. */
	private function unmanaged_variation() {
		$product = new WC_Product_Variable();
		$product->set_name( 'Self-managed ' . uniqid() );
		$product_id = $product->save();
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $product_id );
		$variation_id = $variation->save();
		return get_post( $variation_id );
	}

	/**
	 * WOO-D32: event_options() is called once for the simple-product select
	 * PLUS once per variation row — a variable product with many variations
	 * used to run one full, uncapped event query PER ROW. Memoized per
	 * process, so two rows on the same admin page load must not run the
	 * query twice.
	 */
	public function test_event_options_is_memoized_across_variation_rows() {
		$this->make_event( [ 'title' => 'Memoization Probe ' . uniqid() ] );

		$count = 0;
		$spy   = function ( $query ) use ( &$count ) {
			if (
				$query->get( 'post_type' ) === \Anchor\Events\Module::CPT
				&& (int) $query->get( 'posts_per_page' ) === WooCommerce::LINK_QUERY_CAP
			) {
				$count++;
			}
			return $query;
		};
		add_action( 'pre_get_posts', $spy );
		ob_start();
		$this->woocommerce()->render_variation_fields( 0, [], $this->unmanaged_variation() );
		$this->woocommerce()->render_variation_fields( 1, [], $this->unmanaged_variation() );
		ob_end_clean();
		remove_action( 'pre_get_posts', $spy );

		$this->assertLessThanOrEqual(
			1,
			$count,
			'event_options() must be memoized, not re-queried for every variation row.'
		);
	}
}
