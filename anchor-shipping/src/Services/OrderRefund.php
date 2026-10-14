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

	/** Statuses a full refund may start from; the order must also have a recorded payment (paid()). */
	public const FROM = [ 'processing', 'on-hold', 'completed' ];

	/** Seconds after which a refund lock left by a crashed request is ignored. */
	private const LOCK_TTL = 120;

	/** Amount a full refund would return now, or 0.0 when this order cannot be refunded through its gateway. */
	public static function refundable_amount( \WC_Order $order ): float {
		if ( ! self::paid( $order ) ) {
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
		if ( ! self::paid( $order ) ) {
			throw new \RuntimeException( \__( 'Only a paid order can be refunded here. Check its payment history in WordPress admin.', 'anchor-schema' ) );
		}
		$gateway = self::gateway( $order );
		if ( ! $gateway || ! $gateway->can_refund_order( $order ) ) {
			throw new \RuntimeException( \__( "This order's payment method can't send a refund from here. Refund it in WordPress admin or with the payment processor.", 'anchor-schema' ) );
		}

		// One refund per order at a time: wc_create_refund() checks the remaining amount but doesn't
		// reserve it, so two clicks racing would both reach the gateway.
		$lock = 'anchor_shipping_refund_lock_' . $order->get_id();
		if ( ! self::lock( $lock ) ) {
			throw new \RuntimeException( \__( 'A refund for this order is already in progress. Reload the page in a moment.', 'anchor-schema' ) );
		}
		try {
			$order  = \wc_get_order( $order->get_id() ); // fresh: another refund may have landed before the lock
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
		} finally {
			self::unlock( $lock );
		}
	}

	/**
	 * Atomic per-order lock in the options table. add_option() is check-then-upsert, so it can't be a
	 * lock; INSERT IGNORE on the unique option_name either inserts (we hold it) or affects no row.
	 * A lock older than LOCK_TTL was left by a request that died and is taken over.
	 */
	private static function lock( string $name ): bool {
		global $wpdb;
		$insert = static fn(): bool => 1 === (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $name, (string) time() ) );
		if ( $insert() ) {
			return true;
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d", $name, time() - self::LOCK_TTL ) );
		return $insert();
	}

	private static function unlock( string $name ): void {
		global $wpdb;
		$wpdb->delete( $wpdb->options, [ 'option_name' => $name ] );
	}

	/** Paid status AND a recorded payment: an on-hold order awaiting a cheque or transfer has none. */
	private static function paid( \WC_Order $order ): bool {
		return $order->has_status( self::FROM ) && null !== $order->get_date_paid();
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
				if ( 0.0 !== round( $left, 6 ) ) { // signed: a negative fee carries negative tax
					$tax[ $rate_id ] = $left;
				}
			}
			$lines[ $id ] = [
				'qty'          => 'line_item' === $type ? max( 0, $item->get_quantity() + $order->get_qty_refunded_for_item( $id ) ) : 0,
				// Signed for fees (a negative fee is a discount and must reduce the refund lines too).
				'refund_total' => 'fee' === $type
					? (float) $item->get_total() - (float) $order->get_total_refunded_for_item( $id, $type )
					: max( 0.0, (float) $item->get_total() - (float) $order->get_total_refunded_for_item( $id, $type ) ),
				'refund_tax'   => $tax,
			];
		}
		return $lines;
	}
}
