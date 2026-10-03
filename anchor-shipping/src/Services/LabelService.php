<?php
declare(strict_types=1);

namespace Anchor\Shipping\Services;

use Anchor\Shipping\Carriers\CarrierRegistry;
use Anchor\Shipping\Database\ShipmentRepository;
use Anchor\Shipping\Domain\Address;
use Anchor\Shipping\Domain\AlreadyLabelled;
use Anchor\Shipping\Domain\LabelNotSaved;
use Anchor\Shipping\Domain\CarrierError;
use Anchor\Shipping\Domain\Parcel;
use Anchor\Shipping\Domain\ShipmentRequest;
use Anchor\Shipping\Packing\Packer;
use Anchor\Shipping\Support\Settings;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** The one place labels are created and voided. Every path (manual, auto, bulk) goes through here. */
final class LabelService {

	public const STATE_META = '_anchor_shipping_state';

	public function __construct(
		private CarrierRegistry $carriers,
		private ShipmentRepository $shipments,
		private LabelStore $store,
		private Packer $packer
	) {}

	private const LOCK_SECONDS = 120;

	public const UNRECORDED_META = '_anchor_shipping_unrecorded';

	public function is_labelled( \WC_Order $order ): bool {
		return [] !== $this->shipments->active_for_order( $order->get_id() )
			|| '' !== (string) $order->get_meta( self::UNRECORDED_META );
	}

	/** Refuse before spending money if the label could not be stored afterwards. */
	private function preflight( array $settings ): void {
		if ( 'ZPL' !== $settings['label_format'] && ! \function_exists( 'imagecreatefromstring' ) ) {
			throw new CarrierError( 'setup', \__( 'The server is missing the GD image extension needed to make label PDFs.', 'anchor-schema' ) );
		}
		$dir = $this->store->dir();
		if ( ! \wp_is_writable( $dir ) ) {
			/* translators: %s: folder path */
			throw new CarrierError( 'setup', sprintf( \__( 'The label folder is not writable: %s', 'anchor-schema' ), $dir ) );
		}
	}

	/** The label was bought but not (fully) kept: tell a person on the problem channel, then stop. */
	private function fail_after_billing( \WC_Order $order, string $carrier_label, string $shipment_id, string $numbers, \Throwable $e, bool $row_exists ): never {
		$message = sprintf(
			/* translators: 1: carrier, 2: carrier shipment id, 3: tracking numbers, 4: what was not kept, 5: error */
			\__( '%1$s shipment %2$s (tracking %3$s) was created at %1$s but %4$s: %5$s. Void it from the order or at the carrier\'s website.', 'anchor-schema' ),
			$carrier_label,
			$shipment_id,
			$numbers,
			$row_exists ? \__( 'its label file could not be saved', 'anchor-schema' ) : \__( 'it could not be recorded', 'anchor-schema' ),
			$e->getMessage()
		);
		$this->mark_needs_attention( $order, $message, 'problem' );
		throw new LabelNotSaved( $message, 0, $e );
	}

	/**
	 * @param Parcel[] $parcels
	 * @return int[] shipment row ids
	 */
	public function create_for_order( \WC_Order $order, array $parcels, string $source, array $opts = [] ): array {
		// One create per order at a time: two clicks / a click and the auto job must not both buy a label.
		$lock = 'anchor_shipping_lock_' . $order->get_id();
		if ( ! \add_option( $lock, time(), '', 'no' ) ) {
			if ( time() - (int) \get_option( $lock ) < self::LOCK_SECONDS ) {
				throw new AlreadyLabelled( \__( 'A label is already being created for this order. Reload in a minute.', 'anchor-schema' ) );
			}
			\delete_option( $lock ); // stale: the earlier request died
			if ( ! \add_option( $lock, time(), '', 'no' ) ) {
				throw new AlreadyLabelled( \__( 'A label is already being created for this order. Reload in a minute.', 'anchor-schema' ) );
			}
		}
		try {
			return $this->create_locked( $order, $parcels, $source, $opts );
		} finally {
			\delete_option( $lock );
		}
	}

	/**
	 * @param Parcel[] $parcels
	 * @return int[]
	 */
	private function create_locked( \WC_Order $order, array $parcels, string $source, array $opts ): array {
		if ( empty( $opts['additional'] ) && $this->is_labelled( $order ) ) {
			throw new AlreadyLabelled( \__( 'This order already has a label. Void it first, or tick "additional package".', 'anchor-schema' ) );
		}
		$parcels    = array_values( $parcels );
		$s          = Settings::all();
		$carrier_id = (string) ( $opts['carrier'] ?? $s['default_carrier'] );
		$service    = (string) ( $opts['service'] ?? $s['default_service'] );
		$carrier    = $this->carriers->get( $carrier_id );
		if ( ! $carrier || ! $carrier->supports( 'labels' ) ) {
			throw new CarrierError( 'unknown_carrier', \__( 'That carrier is not available for labels.', 'anchor-schema' ) );
		}
		$this->preflight( $s );
		$to = Address::from_order_shipping( $order );
		if ( ! $to->is_complete() ) {
			throw new CarrierError( 'address', \__( 'The order is missing part of its shipping address.', 'anchor-schema' ) );
		}

		$protection = Protection::for_order( $order, $this->packer->shippable_subtotal( $order ) );
		$declared   = isset( $opts['declared_value'] ) ? max( 0.0, (float) $opts['declared_value'] ) : $protection['declared_value'];
		$signature  = isset( $opts['signature'] ) ? (bool) $opts['signature'] : $protection['signature'];

		$result = $carrier->create_label(
			new ShipmentRequest(
				Settings::ship_from(),
				$to,
				$parcels,
				$service,
				sprintf( 'Order %s', $order->get_order_number() ),
				$declared,
				$signature,
				$order->get_currency() ?: 'USD'
			)
		);

		// The carrier label exists (and is billed) from here on. Record the row FIRST so a
		// failed file save still blocks a second purchase and can be voided from the panel.
		$ids      = [];
		$failures = [];
		$numbers  = implode( ', ', array_map( static fn( $p ) => $p->tracking_number, $result->packages ) );
		foreach ( $result->packages as $i => $pkg ) {
			$parcel = $parcels[ $i ] ?? end( $parcels );
			try {
				$id = $this->shipments->insert(
					[
						'order_id'        => $order->get_id(),
						'carrier'         => $carrier_id,
						'service'         => $service,
						'shipment_id'     => $result->shipment_id,
						'tracking_number' => $pkg->tracking_number,
						'cost'            => 0 === $i ? $result->cost : null,
						'currency'        => $result->currency,
						'declared_value'  => count( $result->packages ) ? round( $declared / count( $result->packages ), 2 ) : 0,
						'signature'       => $signature ? 1 : 0,
						'label_path'      => '',
						'label_format'    => '',
						'box'             => $parcel->box_id,
						'weight_kg'       => $parcel->weight_kg,
						'dims_cm'         => $parcel->dims_label(),
						'source'          => $source,
					]
				);
			} catch ( \Throwable $e ) {
				// No row: nothing else will stop a second purchase, so leave a marker on the order.
				$order->update_meta_data( self::UNRECORDED_META, $result->shipment_id );
				$order->save();
				$this->fail_after_billing( $order, $carrier->label(), $result->shipment_id, $numbers, $e, false );
			}
			$ids[] = $id;
			try {
				$saved = $this->store->save( $order->get_id(), $pkg );
				$this->shipments->update( $id, [ 'label_path' => $saved['path'], 'label_format' => $saved['format'] ] );
			} catch ( \Throwable $e ) {
				$failures[] = $e->getMessage();
			}
		}
		if ( $failures ) {
			$this->fail_after_billing( $order, $carrier->label(), $result->shipment_id, $numbers, new \RuntimeException( implode( '; ', array_unique( $failures ) ) ), true );
		}

		$services = $carrier->services();
		$order->add_order_note(
			sprintf(
				/* translators: 1: carrier, 2: tracking numbers, 3: service, 4: cost */
				\__( '%1$s label created: %2$s (%3$s%4$s).', 'anchor-schema' ),
				$carrier->label(),
				implode( ', ', array_map( static fn( $p ) => $p->tracking_number, $result->packages ) ),
				$services[ $service ] ?? $service,
				null !== $result->cost ? ', ' . \wp_strip_all_tags( \wc_price( $result->cost, [ 'currency' => $result->currency ] ) ) : ''
			)
		);
		$order->update_meta_data( self::STATE_META, 'labelled' );
		$order->save();

		\do_action( 'anchor_shipping_label_created', $order->get_id(), $ids );
		return $ids;
	}

	public function void_shipment( int $row_id ): void {
		$row = $this->shipments->find( $row_id );
		if ( ! $row || 'voided' === $row['status'] ) {
			return;
		}
		$carrier = $this->carriers->get( $row['carrier'] );
		if ( ! $carrier || ! $carrier->supports( 'void' ) ) {
			throw new CarrierError( 'unsupported', \__( 'This carrier cannot void labels here.', 'anchor-schema' ) );
		}
		$carrier->void_label( $row['shipment_id'] );

		$now = \current_time( 'mysql', true );
		foreach ( $this->shipments->by_shipment_id( $row['carrier'], $row['shipment_id'] ) as $r ) {
			$this->shipments->update( (int) $r['id'], [ 'status' => 'voided', 'voided_at' => $now ] );
		}

		$order = \wc_get_order( (int) $row['order_id'] );
		if ( $order ) {
			/* translators: 1: carrier, 2: shipment id */
			$order->add_order_note( sprintf( \__( '%1$s label voided: %2$s.', 'anchor-schema' ), $carrier->label(), $row['shipment_id'] ) );
			if ( ! $this->is_labelled( $order ) ) {
				// Staff voided it themselves, so no email; but a still-paid order has no label now.
				$order->update_meta_data( self::STATE_META, $order->has_status( 'processing' ) ? 'needs_attention' : '' );
			}
			$order->save();
		}
		\do_action( 'anchor_shipping_label_voided', (int) $row['order_id'], $row['shipment_id'] );
	}

	/**
	 * Flag the order for a person. $kind 'ready' = "needs a label" (ReadyToShipEmail);
	 * 'problem' = something went wrong, do not just retry (ProblemEmail).
	 */
	public function mark_needs_attention( \WC_Order $order, string $reason, string $kind = 'ready' ): void {
		$order->update_meta_data( self::STATE_META, 'needs_attention' );
		$order->add_order_note(
			'' === $reason
				? \__( 'Shipping label needed.', 'anchor-schema' )
				/* translators: %s: reason */
				: sprintf( \__( 'Shipping label needed: %s', 'anchor-schema' ), $reason )
		);
		$order->save();
		\do_action( 'problem' === $kind ? 'anchor_shipping_problem' : 'anchor_shipping_needs_attention', $order->get_id(), $reason );
	}
}
