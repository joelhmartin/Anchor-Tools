<?php
declare(strict_types=1);

namespace Anchor\Shipping\Support;

use Anchor\Shipping\Carriers\CarrierRegistry;
use Anchor\Shipping\Database\ShipmentRepository;
use Anchor\Shipping\Domain\TrackingResult;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** Customer-facing view of an order's live (non-voided) shipments; shared by the shipped email and My Account. */
final class TrackingLinks {

	public function __construct( private ShipmentRepository $shipments, private CarrierRegistry $carriers ) {}

	/** @return array<int, array{carrier: string, number: string, url: string, status: string}> */
	public function for_order( int $order_id ): array {
		$out = [];
		foreach ( $this->shipments->active_for_order( $order_id ) as $row ) {
			$carrier = $this->carriers->get( (string) $row['carrier'] );
			$number  = (string) $row['tracking_number'];
			$out[]   = [
				'carrier' => $carrier ? $carrier->label() : (string) $row['carrier'],
				'number'  => $number,
				'url'     => ( $carrier && '' !== $number ) ? $carrier->tracking_url( $number ) : '',
				'status'  => self::status_label( (string) $row['status'] ),
			];
		}
		return $out;
	}

	public static function status_label( string $status ): string {
		$labels = [
			'label_created'    => \__( 'Label created', 'anchor-schema' ),
			'in_transit'       => \__( 'In transit', 'anchor-schema' ),
			'out_for_delivery' => \__( 'Out for delivery', 'anchor-schema' ),
			'delivered'        => \__( 'Delivered', 'anchor-schema' ),
			'exception'        => \__( 'Delivery exception', 'anchor-schema' ),
			'returned'         => \__( 'Returned', 'anchor-schema' ),
		];
		return $labels[ $status ] ?? '';
	}
}
