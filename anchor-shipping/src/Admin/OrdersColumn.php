<?php
declare(strict_types=1);

namespace Anchor\Shipping\Admin;

use Anchor\Shipping\Frontend\ShopManager;
use Anchor\Shipping\Services\LabelService;
use Anchor\Shipping\Support\TrackingLinks;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** "Shipping" column on the WooCommerce orders list (legacy posts screen and HPOS): label state plus tracking. */
final class OrdersColumn {

	public const KEY = 'anchor_shipping';

	public function __construct( private TrackingLinks $tracking, private LabelService $labels ) {
		\add_filter( 'manage_edit-shop_order_columns', [ $this, 'add_column' ] );
		\add_filter( 'manage_woocommerce_page_wc-orders_columns', [ $this, 'add_column' ] );
		\add_action( 'manage_shop_order_posts_custom_column', [ $this, 'legacy_cell' ], 10, 2 );
		\add_action( 'manage_woocommerce_page_wc-orders_custom_column', [ $this, 'hpos_cell' ], 10, 2 );
	}

	/** @param array<string,string> $columns */
	public function add_column( $columns ): array {
		$out = [];
		foreach ( (array) $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'order_status' === $key ) {
				$out[ self::KEY ] = \__( 'Shipping', 'anchor-schema' );
			}
		}
		return isset( $out[ self::KEY ] ) ? $out : $out + [ self::KEY => \__( 'Shipping', 'anchor-schema' ) ];
	}

	public function legacy_cell( $column, $post_id ): void {
		$order = self::KEY === $column ? \wc_get_order( (int) $post_id ) : false;
		if ( $order instanceof \WC_Order ) {
			echo $this->render_cell( $order ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in render_cell()
		}
	}

	public function hpos_cell( $column, $order ): void {
		if ( self::KEY === $column && $order instanceof \WC_Order ) {
			echo $this->render_cell( $order ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in render_cell()
		}
	}

	/** Empty when the order has nothing to ship or is not yet paid. */
	public function render_cell( \WC_Order $order ): string {
		$tracking = $this->tracking->for_order( $order->get_id() );
		$state    = (string) $order->get_meta( LabelService::STATE_META );
		if ( 'needs_attention' === $state && ! $this->labels->has_live_attention_flag( $order ) ) {
			$state = ''; // stale flag on a finished order: same predicate as the console's Needs label tab
		}
		$label    = ShopManager::state_label( $state );
		if ( '' === $label && $tracking ) {
			$label = ShopManager::state_label( 'labelled' );
		}
		if ( '' === $label ) {
			if ( ! $this->labels->awaits_label( $order ) ) {
				return '';
			}
			$label = \__( 'Needs label', 'anchor-schema' );
			$state = 'needs_label';
		}
		$html = '<span class="anchor-shipping-state anchor-shipping-state-' . \esc_attr( $state ?: 'labelled' ) . '">' . \esc_html( $label ) . '</span>';
		foreach ( $tracking as $t ) {
			$num   = '' !== $t['url'] ? '<a href="' . \esc_url( $t['url'] ) . '" target="_blank" rel="noopener">' . \esc_html( $t['number'] ) . '</a>' : \esc_html( $t['number'] );
			$html .= '<br>' . $num . ( '' !== $t['status'] ? ' <small>' . \esc_html( $t['status'] ) . '</small>' : '' );
		}
		return $html;
	}
}
