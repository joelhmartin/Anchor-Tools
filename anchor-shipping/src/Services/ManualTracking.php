<?php
declare(strict_types=1);

namespace Anchor\Shipping\Services;

use Anchor\Shipping\Carriers\CarrierRegistry;
use Anchor\Shipping\Database\ShipmentRepository;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Tracking numbers for packages shipped without a label from here (UPS.com, WorldShip, a FedEx or
 * USPS counter). The number becomes an ordinary shipment row, so tracking, the customer's shipped
 * email, My Account and complete-on-delivery work as for a label made here. Nothing was bought
 * here, so nothing is voided at a carrier: removing the number only takes it off the order.
 */
final class ManualTracking {

	/** shipments.source for these rows; they carry no carrier shipment id and no label file. */
	public const SOURCE = 'tracking';

	public function __construct(
		private CarrierRegistry $carriers,
		private ShipmentRepository $shipments,
		private TrackingPoller $poller
	) {}

	/** A row entered by hand rather than bought here: no carrier void, no label file. */
	public static function is_manual( array $row ): bool {
		return self::SOURCE === (string) ( $row['source'] ?? '' );
	}

	/** Upper-case, without the spaces and dashes people paste in. */
	public static function normalize( string $number ): string {
		return strtoupper( (string) preg_replace( '/[\s\-]+/', '', $number ) );
	}

	/**
	 * @throws \RuntimeException with a message fit to show staff
	 * @return int the new shipment row id
	 */
	public function add( \WC_Order $order, string $carrier_id, string $number ): int {
		$carrier = $this->carriers->for_tracking()[ $carrier_id ] ?? null;
		if ( ! $carrier ) {
			throw new \RuntimeException( \__( 'Choose a carrier.', 'anchor-schema' ) );
		}
		$number = self::normalize( $number );
		if ( ! preg_match( '/^[A-Z0-9]{8,40}$/', $number ) ) {
			throw new \RuntimeException( \__( 'Enter the tracking number (letters and digits only).', 'anchor-schema' ) );
		}
		foreach ( $this->shipments->active_for_order( $order->get_id() ) as $row ) {
			if ( $number === self::normalize( (string) $row['tracking_number'] ) ) {
				throw new \RuntimeException( \__( 'That tracking number is already on this order.', 'anchor-schema' ) );
			}
		}

		$trackable = $carrier->supports( 'track' );
		$id        = $this->shipments->insert( [
			'order_id'        => $order->get_id(),
			'carrier'         => $carrier->id(),
			'service'         => '',
			'shipment_id'     => '',
			'tracking_number' => $number,
			'currency'        => $order->get_currency() ?: 'USD',
			'label_path'      => '',
			'label_format'    => '',
			'box'             => '',
			'dims_cm'         => '',
			'source'          => self::SOURCE,
			// A carrier we cannot ask is taken at its word: the package has shipped (no progress is known).
			'status'          => $trackable ? 'label_created' : 'shipped',
		] );

		/* translators: 1: carrier, 2: tracking number */
		$order->add_order_note( sprintf( \__( 'Tracking number added: %1$s %2$s.', 'anchor-schema' ), $carrier->label(), $number ) );
		if ( $this->needs_label_state( $order ) ) {
			$order->update_meta_data( LabelService::STATE_META, 'labelled' );
		}
		if ( ! $order->has_status( [ 'processing', 'on-hold' ] ) ) {
			// Back-filling a finished (or cancelled) order: record it, but never tell the customer it is on its way.
			$order->update_meta_data( TrackingPoller::SHIPPED_META, (string) time() );
		}
		$order->save();

		if ( $trackable ) {
			$this->poller->refresh( $id ); // already scanned → Shipped and the customer's email now, not in two hours
		} else {
			$fresh = \wc_get_order( $order->get_id() );
			$this->poller->mark_shipped( $fresh, (array) $this->shipments->find( $id ) );
			if ( 'labelled' === (string) $fresh->get_meta( LabelService::STATE_META ) ) {
				// The shipped email went out earlier (or this is a back-fill): it has still shipped.
				$fresh->update_meta_data( LabelService::STATE_META, 'shipped' );
				$fresh->save();
			}
		}
		return $id;
	}

	/**
	 * Does the order's shipping state still mean "no package yet"? Empty, Labelled, or a processing
	 * order flagged only because it has nothing on it. Any other flag (a refused void, a label
	 * bought but not saved) is a problem for a person and is kept.
	 */
	private function needs_label_state( \WC_Order $order ): bool {
		$state = (string) $order->get_meta( LabelService::STATE_META );
		if ( in_array( $state, [ '', 'labelled' ], true ) ) {
			return true;
		}
		return 'needs_attention' === $state
			&& $order->has_status( 'processing' )
			&& '' === (string) $order->get_meta( LabelService::UNRECORDED_META )
			&& 1 === count( $this->shipments->active_for_order( $order->get_id() ) ); // only the row just added
	}
}
