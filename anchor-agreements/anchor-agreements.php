<?php
/**
 * Anchor Tools module: Anchor Agreements.
 * Bootstrap only; everything else is PSR-4 under src/. No strict_types here on purpose.
 */

namespace Anchor\Agreements;

use Anchor\Agreements\Database\Migrations;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

class Module {

	const VERSION = '1.0.0';

	private static ?Module $instance = null;

	public static function instance(): ?Module {
		return self::$instance;
	}

	public static function url( string $path ): string {
		return \plugins_url( $path, __FILE__ );
	}

	public static function dir(): string {
		return __DIR__;
	}

	/** @param bool|null $woocommerce_active Test seam; null detects WooCommerce. */
	public function __construct( $woocommerce_active = null ) {
		// Everything here (checkout, orders, sessions, emails) is WooCommerce; without it
		// is_checkout() and friends are undefined and every front-end page would fatal.
		if ( ! ( $woocommerce_active ?? \class_exists( 'WooCommerce' ) ) ) {
			\add_action( 'admin_notices', [ self::class, 'missing_woocommerce_notice' ] );
			return;
		}
		self::$instance = $this;
		Migrations::maybe_migrate();
		\add_action( 'admin_init', [ Migrations::class, 'maybe_migrate' ] );
		\add_action( 'init', [ Content\AgreementPostType::class, 'register' ] );
		if ( \is_admin() ) {
			new Admin\ProductFields();
			new Admin\ProductListFlag();
			new Admin\OrderMetabox();
			new Admin\SignaturesPage();
			new Admin\SettingsPage();
			new Admin\BlockCheckoutNotice();
		}
		new Frontend\SigningEndpoint();
		new Frontend\Checkout();
		new Frontend\SignedCopyPage();
		new Frontend\CustomerViews();
		new Services\Notifier();
		new Services\Cleanup();
	}

	public static function missing_woocommerce_notice(): void {
		if ( ! \current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>' . \esc_html__( 'Anchor Agreements requires WooCommerce. The module is switched on but does nothing until WooCommerce is active.', 'anchor-schema' ) . '</p></div>';
	}
}
