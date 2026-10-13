<?php
declare(strict_types=1);

namespace Anchor\Shipping\Frontend;

use Anchor\Shipping\Support\TrackingLinks;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** Tracking block on the customer's order page (My Account → view order, and the thank-you page). */
final class AccountTracking {

	public function __construct( private TrackingLinks $links ) {
		\add_action( 'woocommerce_order_details_after_order_table', [ $this, 'render' ] );
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
			echo '<li>' . \esc_html( $r['carrier'] ) . ' ';
			echo '' !== $r['url'] ? '<a href="' . \esc_url( $r['url'] ) . '" target="_blank" rel="noopener">' . \esc_html( $r['number'] ) . '</a>' : \esc_html( $r['number'] );
			echo '' !== $r['status'] ? ' &mdash; ' . \esc_html( $r['status'] ) : '';
			echo '</li>';
		}
		echo '</ul></section>';
	}
}
