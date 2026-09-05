<?php
/**
 * WOO-D20: event registration requires the classic `[woocommerce_checkout]`
 * shortcode checkout — the Checkout BLOCK is not supported. guard_block_checkout()
 * already fails an order closed on that path; render_block_checkout_incompatibility_notice()
 * surfaces the conflict on the admin BEFORE a real buyer hits it.
 *
 * @package Anchor\Events\Tests
 */

/**
 * @group woocommerce
 */
class Test_WooCommerce_Checkout_Block extends Anchor_Events_TestCase {

	public function set_up() {
		parent::set_up();
		$this->require_wc();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	public function tear_down() {
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/** A checkout page with the given content, wired up via the WC option. */
	private function set_checkout_page_content( $content ) {
		$checkout_id = self::factory()->post->create( [
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_content' => $content,
		] );
		update_option( 'woocommerce_checkout_page_id', $checkout_id );
		return $checkout_id;
	}

	/** The notice is silent when the checkout page is the classic shortcode. */
	public function test_no_notice_for_the_classic_shortcode_checkout() {
		$this->set_checkout_page_content( '[woocommerce_checkout]' );

		set_current_screen( 'edit-shop_order' );
		ob_start();
		$this->woocommerce()->render_block_checkout_incompatibility_notice();
		$html = ob_get_clean();

		$this->assertSame( '', $html );
	}

	/** The notice fires when the checkout page contains the Checkout block. */
	public function test_notice_fires_when_the_checkout_page_uses_the_block() {
		$this->set_checkout_page_content(
			'<!-- wp:woocommerce/checkout --><div class="wp-block-woocommerce-checkout"></div><!-- /wp:woocommerce/checkout -->'
		);

		set_current_screen( 'edit-shop_order' );
		ob_start();
		$this->woocommerce()->render_block_checkout_incompatibility_notice();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Checkout block', $html );
		$this->assertStringContainsString( 'classic', $html );
	}

	/** Irrelevant screens stay silent even with a block checkout page. */
	public function test_notice_is_silent_on_an_unrelated_screen() {
		$this->set_checkout_page_content(
			'<!-- wp:woocommerce/checkout --><div class="wp-block-woocommerce-checkout"></div><!-- /wp:woocommerce/checkout -->'
		);

		set_current_screen( 'dashboard' );
		ob_start();
		$this->woocommerce()->render_block_checkout_incompatibility_notice();
		$html = ob_get_clean();

		$this->assertSame( '', $html );
	}
}
