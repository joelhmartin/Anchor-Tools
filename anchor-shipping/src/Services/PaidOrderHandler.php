<?php
declare(strict_types=1);

namespace Anchor\Shipping\Services;

use Anchor\Shipping\Domain\AlreadyLabelled;
use Anchor\Shipping\Domain\CarrierError;
use Anchor\Shipping\Domain\LabelNotSaved;
use Anchor\Shipping\Packing\Packer;
use Anchor\Shipping\Support\Settings;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Paid order → background job. Checkbox off: ask a person (ready-to-ship email).
 * Checkbox on: create the label when the Packer can size it, otherwise ask a person.
 */
final class PaidOrderHandler {

	public const ACTION       = 'anchor_shipping_process_paid_order';
	public const GROUP        = 'anchor-shipping';
	public const MAX_ATTEMPTS = 3;

	public function __construct( private LabelService $labels, private Packer $packer ) {
		\add_action( 'woocommerce_order_status_processing', [ $this, 'queue' ], 20, 1 );
		\add_action( self::ACTION, [ $this, 'run' ], 10, 2 );
	}

	public function eligible( \WC_Order $order ): bool {
		return $order->needs_shipping_address()
			&& [] !== $order->get_shipping_methods()
			&& ! $this->labels->is_labelled( $order )
			&& 'needs_attention' !== $order->get_meta( LabelService::STATE_META );
	}

	public function queue( int $order_id ): void {
		$order = \wc_get_order( $order_id );
		if ( ! $order || ! $this->eligible( $order ) ) {
			return;
		}
		if ( \as_has_scheduled_action( self::ACTION, [ $order_id, 1 ], self::GROUP ) ) {
			return;
		}
		\as_enqueue_async_action( self::ACTION, [ $order_id, 1 ], self::GROUP );
	}

	public function run( int $order_id, int $attempt = 1 ): void {
		$order = \wc_get_order( $order_id );
		if ( ! $order || ! $this->eligible( $order ) ) {
			return; // already labelled or already waiting on a person
		}
		// The order may have been cancelled/refunded since the job was queued.
		if ( ! $order->has_status( (array) \apply_filters( 'anchor_shipping_autolabel_statuses', [ 'processing' ] ) ) ) {
			return;
		}
		if ( ! Settings::auto_label() ) {
			$this->labels->mark_needs_attention( $order, '' );
			return;
		}
		$pack = $this->packer->pack_order( $order );
		if ( ! $pack->ok() ) {
			$this->labels->mark_needs_attention( $order, $pack->reason );
			return;
		}
		try {
			$this->labels->create_for_order( $order, $pack->parcels, 'auto' );
		} catch ( CarrierError $e ) {
			if ( $e->retryable && $attempt < self::MAX_ATTEMPTS ) {
				\as_schedule_single_action( time() + 300 * $attempt, self::ACTION, [ $order_id, $attempt + 1 ], self::GROUP );
				return;
			}
			if ( $e->retryable ) {
				/* translators: 1: attempts, 2: carrier error message */
				$reason = sprintf( \__( 'The carrier could not be reached after %1$d attempts: %2$s', 'anchor-schema' ), $attempt, $e->getMessage() );
			} else {
				/* translators: %s: carrier error message */
				$reason = sprintf( \__( 'The carrier refused the label: %s', 'anchor-schema' ), $e->getMessage() );
			}
			// 'uncertain' = UPS may have created a label: a person must check ups.com first.
			$this->labels->mark_needs_attention( $order, $reason, 'uncertain' === $e->carrier_code ? 'problem' : 'ready' );
		} catch ( AlreadyLabelled $e ) {
			return; // labelled concurrently
		} catch ( LabelNotSaved $e ) {
			return; // label bought but not saved: LabelService already flagged + noted the order; a retry could buy a second label
		} catch ( \Throwable $e ) {
			// Never leave a paid order silently unlabelled and unflagged.
			/* translators: %s: error message */
			$this->labels->mark_needs_attention( $order, sprintf( \__( 'Automatic label failed: %s', 'anchor-schema' ), $e->getMessage() ), 'problem' );
		}
	}
}
