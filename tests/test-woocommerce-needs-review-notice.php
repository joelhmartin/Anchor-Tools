<?php
/**
 * WOO-D54: the needs-review admin notice's order scan must include TRASHED
 * orders — wc_get_orders() with no `status` argument inherits WooCommerce's
 * default status set, which excludes `trash`. A flagged order later trashed
 * dropped out of the notice's scan while keeping its flag, so the cached
 * count under-reported and the metabox on the order edit screen (which an
 * admin would have to know to open) was the only remaining way to find it.
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Events_Log;
use Anchor\Events\Module;
use Anchor\Events\WooCommerce;

/**
 * @group woocommerce
 */
class Test_WooCommerce_Needs_Review_Notice extends Anchor_Events_TestCase {

	public function set_up() {
		parent::set_up();
		$this->require_wc();
		delete_transient( WooCommerce::NEEDS_REVIEW_TRANSIENT );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	public function tear_down() {
		set_current_screen( 'front' );
		delete_transient( WooCommerce::NEEDS_REVIEW_TRANSIENT );
		parent::tear_down();
	}

	private function flagged_trashed_order() {
		$order = new WC_Order();
		$order->save();
		Events_Log::flag_review( $order->get_id(), 'amount_only_refund', 'refund #1' );
		$order = wc_get_order( $order->get_id() );
		$order->set_status( 'trash' );
		$order->save();
		return $order->get_id();
	}

	/** The notice must report a flagged order even after it is trashed. */
	public function test_notice_counts_a_flagged_order_that_is_later_trashed() {
		// Pre-existing, unrelated to WOO-D54: WooCommerce 9.2+ deprecated
		// `meta_query` on wc_get_orders() for the CPT datastore in favor of
		// purpose-built query args, but still executes it via the underlying
		// WP_Query — this is a forward-looking deprecation notice, not a
		// functional break, and predates (and is outside the scope of) this
		// fix, which only adds the missing `status` argument. Unhooked
		// (rather than asserted on via setExpectedIncorrectUsage) because
		// whether wc_doing_it_wrong() takes the wp_doing_ajax() branch here
		// depends on what ran earlier in the suite (an ajax test class can
		// leave DOING_AJAX defined for the rest of the process) — this test
		// only cares that the order is counted, not how that notice fires.
		remove_all_actions( 'doing_it_wrong_run' );

		$order_id = $this->flagged_trashed_order();
		$this->assertSame( 'trash', wc_get_order( $order_id )->get_status() );

		set_current_screen( 'edit-shop_order' );
		ob_start();
		$this->woocommerce()->render_needs_review_notice();
		$html = ob_get_clean();

		$this->assertStringContainsString(
			'1',
			$html,
			'The trashed-but-flagged order must still be counted, not silently dropped.'
		);
		$this->assertNotSame( '', trim( $html ), 'The notice must not be silent.' );
	}
}
