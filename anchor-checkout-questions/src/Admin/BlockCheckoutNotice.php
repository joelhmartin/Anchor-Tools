<?php
declare(strict_types=1);

namespace Anchor\CheckoutQuestions\Admin;

use Anchor\CheckoutQuestions\Support\QuestionSets;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** Questions are collected by classic-checkout hooks only; warn when the checkout page is the block. */
final class BlockCheckoutNotice {

	public function __construct() {
		\add_action( 'admin_notices', [ self::class, 'render' ] );
	}

	private static function uses_checkout_block(): bool {
		if ( \class_exists( '\Anchor\Agreements\Admin\BlockCheckoutNotice' ) ) {
			return \Anchor\Agreements\Admin\BlockCheckoutNotice::uses_checkout_block();
		}
		$page_id = \function_exists( 'wc_get_page_id' ) ? (int) \wc_get_page_id( 'checkout' ) : 0;
		return $page_id > 0 && \has_block( 'woocommerce/checkout', $page_id );
	}

	public static function render(): void {
		if ( ! \current_user_can( 'manage_woocommerce' ) || ! self::uses_checkout_block() || ! QuestionSets::published_set_ids() ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . \esc_html__( 'Checkout Questions works with the classic checkout only. On the block checkout, orders that need a required answer are refused and optional questions are not collected.', 'anchor-schema' ) . '</p></div>';
	}
}
