<?php
declare(strict_types=1);

namespace Anchor\Agreements\Admin;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * The signing UI lives in the classic/FunnelKit checkout only. A Checkout block page
 * refuses agreement orders (Checkout::guard_store_api), so tell the store manager
 * before a customer finds out. One cached get_post() per admin screen.
 */
final class BlockCheckoutNotice {

	public function __construct() {
		\add_action( 'admin_notices', [ self::class, 'render' ] );
	}

	public static function uses_checkout_block(): bool {
		$page_id = \function_exists( 'wc_get_page_id' ) ? (int) \wc_get_page_id( 'checkout' ) : 0;
		return $page_id > 0 && \has_block( 'woocommerce/checkout', $page_id );
	}

	public static function render(): void {
		if ( ! \current_user_can( 'manage_woocommerce' ) || ! self::uses_checkout_block() ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . \esc_html__( 'Anchor Agreements: your checkout page uses the Checkout block, which cannot collect signatures. Orders for products that require an agreement will be refused there. Switch the checkout page to the classic [woocommerce_checkout] shortcode (or FunnelKit).', 'anchor-schema' ) . '</p></div>';
	}
}
