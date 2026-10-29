<?php
/**
 * Anchor Tools module: Anchor Checkout Questions.
 * Bootstrap only; everything else is PSR-4 under src/. No strict_types here on purpose.
 */

namespace Anchor\CheckoutQuestions;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

class Module {

	const VERSION = '1.0.0';

	private static ?Module $instance = null;

	public static function instance(): ?Module {
		return self::$instance;
	}

	/** @param bool|null $woocommerce_active Test seam; null detects WooCommerce. */
	public function __construct( $woocommerce_active = null ) {
		// Checkout, orders and the New order email are all WooCommerce; without it
		// the hooks this module attaches to do not exist.
		if ( ! ( $woocommerce_active ?? \class_exists( 'WooCommerce' ) ) ) {
			\add_action( 'admin_notices', [ self::class, 'missing_woocommerce_notice' ] );
			return;
		}
		self::$instance = $this;
		\add_action( 'init', [ Content\QuestionSetPostType::class, 'register' ] );
		// Not gated on is_admin(): its hooks are CPT-scoped, and set saves must also work from tests and imports.
		new Admin\SetEditor();
	}

	public static function missing_woocommerce_notice(): void {
		if ( ! \current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>' . \esc_html__( 'Anchor Checkout Questions requires WooCommerce. The module is switched on but does nothing until WooCommerce is active.', 'anchor-schema' ) . '</p></div>';
	}
}
