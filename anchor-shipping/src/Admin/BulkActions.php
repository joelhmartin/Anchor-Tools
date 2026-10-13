<?php
declare(strict_types=1);

namespace Anchor\Shipping\Admin;

use Anchor\Shipping\Frontend\ShopManager;
use Anchor\Shipping\Support\Urls;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * "Create shipping labels" bulk action on the wp-admin orders list (legacy + HPOS). Sends staff to the console
 * Bulk view when a console page is set, otherwise to a hidden admin screen rendering the same template.
 */
final class BulkActions {

	public const ACTION = 'anchor_shipping_bulk';
	public const PAGE   = 'anchor-shipping-bulk';

	public function __construct( private ShopManager $console ) {
		foreach ( [ 'edit-shop_order', 'woocommerce_page_wc-orders' ] as $screen ) {
			\add_filter( "bulk_actions-{$screen}", [ $this, 'register' ] );
			\add_filter( "handle_bulk_actions-{$screen}", [ $this, 'handle' ], 10, 3 );
		}
		\add_action( 'admin_menu', [ $this, 'menu' ] );
		\add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
	}

	/** @param array<string,string> $actions */
	public function register( $actions ): array {
		$actions = (array) $actions;
		if ( ShopManager::can_use() ) {
			$actions[ self::ACTION ] = \__( 'Create shipping labels', 'anchor-schema' );
		}
		return $actions;
	}

	/** @param int[]|string[] $ids */
	public static function redirect_url( array $ids ): string {
		$ids  = ShopManager::parse_ids( $ids );
		$args = [ 'bulk' => 1, 'ids' => implode( ',', $ids ) ];
		$url  = Urls::console( $args );
		return '' !== $url ? $url : \add_query_arg( $args + [ 'page' => self::PAGE ], \admin_url( 'admin.php' ) );
	}

	public function handle( $redirect, $action, $ids ) {
		if ( self::ACTION !== $action || ! ShopManager::can_use() ) {
			return $redirect;
		}
		return self::redirect_url( (array) $ids );
	}

	public function menu(): void {
		\add_submenu_page( 'woocommerce', \__( 'Create shipping labels', 'anchor-schema' ), '', ShopManager::capability(), self::PAGE, [ $this, 'screen' ] );
		\remove_submenu_page( 'woocommerce', self::PAGE ); // reachable by URL only
	}

	public function assets( string $hook ): void {
		if ( \str_ends_with( $hook, '_page_' . self::PAGE ) ) {
			ShopManager::enqueue();
		}
	}

	public function screen(): void {
		if ( ! ShopManager::can_use() ) {
			\wp_die( \esc_html__( 'You are not allowed to create labels.', 'anchor-schema' ), 403 );
		}
		$ids = isset( $_GET['ids'] ) ? ShopManager::parse_ids( \wp_unslash( $_GET['ids'] ) ) : []; // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- read-only; the create call is nonce-checked
		echo '<div class="wrap asm-console">' . $this->console->bulk_view( $ids, \admin_url( 'admin.php?page=wc-orders' ) ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the template
	}
}
