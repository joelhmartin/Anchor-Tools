<?php
declare(strict_types=1);

namespace Anchor\Shipping\Frontend;

use Anchor\Shipping\Support\TrackingLinks;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** Tracking on the customer's side: the order page (My Account → view order, thank-you page) and a column in My Account → Orders. */
final class AccountTracking {

	public const COLUMN = 'anchor-shipping-tracking';

	public function __construct( private TrackingLinks $links ) {
		\add_action( 'woocommerce_order_details_after_order_table', [ $this, 'render' ] );
		\add_filter( 'woocommerce_account_orders_columns', [ $this, 'add_column' ] );
		\add_action( 'woocommerce_my_account_my_orders_column_' . self::COLUMN, [ $this, 'render_column' ] );
	}

	public function render( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$rows = $this->links->for_order( $order->get_id() );
		if ( ! $rows ) {
			return;
		}
		echo '<section class="anchor-shipping-tracking"><h2>' . \esc_html__( 'Tracking', 'anchor-schema' ) . '</h2><ul>';
		foreach ( $rows as $r ) {
			echo '<li>' . \esc_html( $r['carrier'] ) . ' ' . self::link( $r ) . ( '' !== $r['status'] ? ' &mdash; ' . \esc_html( $r['status'] ) : '' ) . '</li>';
		}
		echo '</ul></section>';
	}

	/** Inserts the Tracking column after Status (or before Actions, or at the end). */
	public function add_column( $columns ): array {
		$columns = (array) $columns;
		$label   = \__( 'Tracking', 'anchor-schema' );
		$after   = isset( $columns['order-status'] ) ? 'order-status' : null;
		$out     = [];
		foreach ( $columns as $key => $name ) {
			if ( null === $after && 'order-actions' === $key ) {
				$out[ self::COLUMN ] = $label;
			}
			$out[ $key ] = $name;
			if ( $key === $after ) {
				$out[ self::COLUMN ] = $label;
			}
		}
		$out[ self::COLUMN ] ??= $label;
		return $out;
	}

	public function render_column( $order ): void {
		$rows = $order instanceof \WC_Order ? $this->links->for_order( $order->get_id() ) : [];
		if ( ! $rows ) {
			echo '&mdash;';
			return;
		}
		$cells = [];
		foreach ( $rows as $r ) {
			$cells[] = '<span class="anchor-shipping-tracking-cell">' . self::link( $r ) . ( '' !== $r['status'] ? ' <small>' . \esc_html( $r['status'] ) . '</small>' : '' ) . '</span>';
		}
		echo implode( '<br>', $cells );
	}

	/** @param array{carrier: string, number: string, url: string, status: string} $r */
	private static function link( array $r ): string {
		return '' !== $r['url']
			? '<a href="' . \esc_url( $r['url'] ) . '" target="_blank" rel="noopener">' . \esc_html( $r['number'] ) . '</a>'
			: \esc_html( $r['number'] );
	}
}
