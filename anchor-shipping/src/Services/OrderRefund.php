<?php
declare(strict_types=1);

namespace Anchor\Shipping\Services;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Full refund of an order through its own payment gateway, as wp-admin's "Refund via <gateway>" does:
 * money back to the customer, every remaining line refunded, items restocked. Woo then marks the order
 * Refunded, which fires VoidOnCancel and voids its labels. Partial refunds stay in wp-admin.
 */
final class OrderRefund {

	/** Statuses a full refund may start from (the customer has paid). */
	public const FROM = [ 'processing', 'on-hold', 'completed' ];

	/** Amount a full refund would return now, or 0.0 when this order cannot be refunded through its gateway. */
	public static function refundable_amount( \WC_Order $order ): float {
		if ( ! $order->has_status( self::FROM ) ) {
			return 0.0;
		}
		$gateway = self::gateway( $order );
		if ( ! $gateway || ! $gateway->can_refund_order( $order ) ) {
			return 0.0;
		}
		return max( 0.0, (float) $order->get_remaining_refund_amount() );
	}

	/** @throws \RuntimeException with a message fit to show staff */
	public static function refund_in_full( \WC_Order $order, string $reason ): \WC_Order_Refund {
		if ( ! $order->has_status( self::FROM ) ) {
			throw new \RuntimeException( \__( 'Only a paid order can be refunded here.', 'anchor-schema' ) );
		}
		$gateway = self::gateway( $order );
		if ( ! $gateway || ! $gateway->can_refund_order( $order ) ) {
			throw new \RuntimeException( \__( "This order's payment method can't send a refund from here. Refund it in WordPress admin or with the payment processor.", 'anchor-schema' ) );
		}
		$amount = (float) $order->get_remaining_refund_amount();
		if ( $amount <= 0 ) {
			throw new \RuntimeException( \__( 'This order has already been refunded in full.', 'anchor-schema' ) );
		}

		$refund = \wc_create_refund( [
			'order_id'       => $order->get_id(),
			'amount'         => \wc_format_decimal( $amount, \wc_get_price_decimals() ),
			'reason'         => $reason,
			'line_items'     => self::remaining_lines( $order ),
			'refund_payment' => true,
			'restock_items'  => true,
		] );
		if ( \is_wp_error( $refund ) ) {
			throw new \RuntimeException( $refund->get_error_message() );
		}
		return $refund;
	}

	private static function gateway( \WC_Order $order ): ?\WC_Payment_Gateway {
		$gateways = \WC()->payment_gateways() ? \WC()->payment_gateways()->payment_gateways() : [];
		$gateway  = $gateways[ $order->get_payment_method() ] ?? null;
		return $gateway instanceof \WC_Payment_Gateway && $gateway->supports( 'refunds' ) ? $gateway : null;
	}

	/**
	 * What is still unrefunded on each line, in wc_create_refund()'s line_items shape, so the refund
	 * restocks products and records which lines it covered (exactly what the wp-admin refund form sends).
	 *
	 * @return array<int, array{qty:int|float, refund_total:float, refund_tax:array<int|string,float>}>
	 */
	private static function remaining_lines( \WC_Order $order ): array {
		$lines = [];
		foreach ( $order->get_items( [ 'line_item', 'fee', 'shipping' ] ) as $id => $item ) {
			$type = $item->get_type();
			$tax  = [];
			foreach ( (array) ( $item->get_taxes()['total'] ?? [] ) as $rate_id => $total ) {
				$left = (float) $total - (float) $order->get_tax_refunded_for_item( $id, $rate_id, $type );
				if ( $left > 0 ) {
					$tax[ $rate_id ] = $left;
				}
			}
			$lines[ $id ] = [
				'qty'          => 'line_item' === $type ? max( 0, $item->get_quantity() + $order->get_qty_refunded_for_item( $id ) ) : 0,
				'refund_total' => max( 0.0, (float) $item->get_total() - (float) $order->get_total_refunded_for_item( $id, $type ) ),
				'refund_tax'   => $tax,
			];
		}
		return $lines;
	}
}
