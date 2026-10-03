<?php
declare(strict_types=1);

namespace Anchor\Shipping\Services;

use Anchor\Shipping\Database\ShipmentRepository;
use Anchor\Shipping\Domain\CarrierError;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** Cancelled or fully refunded → void its labels; if the carrier refuses, tell a person. */
final class VoidOnCancel {

	public function __construct( private LabelService $labels, private ShipmentRepository $shipments ) {
		\add_action( 'woocommerce_order_status_cancelled', [ $this, 'handle' ], 20, 1 );
		\add_action( 'woocommerce_order_status_refunded', [ $this, 'handle' ], 20, 1 );
	}

	public function handle( int $order_id ): void {
		$done = [];
		foreach ( $this->shipments->active_for_order( $order_id ) as $row ) {
			$key = $row['carrier'] . '|' . $row['shipment_id'];
			if ( isset( $done[ $key ] ) ) {
				continue; // one void covers every package of a shipment
			}
			$done[ $key ] = true;
			try {
				$this->labels->void_shipment( (int) $row['id'] );
			} catch ( CarrierError $e ) {
				$order = \wc_get_order( $order_id );
				if ( $order ) {
					/* translators: 1: tracking number, 2: carrier message */
					$this->labels->mark_needs_attention( $order, sprintf( \__( 'Order was cancelled/refunded but label %1$s could not be voided: %2$s', 'anchor-schema' ), $row['tracking_number'], $e->getMessage() ) );
				}
			}
		}
	}
}
