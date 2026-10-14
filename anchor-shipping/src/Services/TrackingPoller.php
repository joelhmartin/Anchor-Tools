<?php
declare(strict_types=1);

namespace Anchor\Shipping\Services;

use Anchor\Shipping\Carriers\CarrierRegistry;
use Anchor\Shipping\Database\ShipmentRepository;
use Anchor\Shipping\Domain\TrackingResult;
use Anchor\Shipping\Support\Settings;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Every two hours asks each carrier about the packages still in motion and keeps the
 * shipment rows, the order notes and the order's shipping state in step.
 *
 * Fires `anchor_shipping_first_scan( $order_id, $row )` once per order (customer
 * "shipped" email hooks here) and `anchor_shipping_problem( $order_id, $detail )` when a
 * package newly reports an exception. A tracking exception never changes the order's
 * shipping state: it only tells a person.
 */
final class TrackingPoller {

	public const ACTION        = 'anchor_shipping_poll_tracking';
	public const GROUP         = 'anchor-shipping';
	public const INTERVAL      = 7200;
	public const BATCH         = 50;
	public const SHIPPED_META  = '_anchor_shipping_shipped_sent';
	/** UPS voids labels that were never scanned after this many days. */
	public const AUTO_VOID_DAYS = 10;

	public function __construct( private CarrierRegistry $carriers, private ShipmentRepository $shipments, private ?LabelService $labels = null ) {
		// admin_init, not init: the recurring job survives, so only an admin visit needs to (re)create it.
		\add_action( 'admin_init', [ $this, 'schedule' ] );
		\add_action( self::ACTION, [ $this, 'poll' ] );
	}

	public function schedule(): void {
		if ( \function_exists( 'as_has_scheduled_action' ) && ! \as_has_scheduled_action( self::ACTION, [], self::GROUP ) ) {
			\as_schedule_recurring_action( time() + 60, self::INTERVAL, self::ACTION, [], self::GROUP, true );
		}
	}

	/**
	 * Sandbox labels are test shipments: rows still track, but the poller must not email customers
	 * ("shipped") or complete real orders because of them. Opt in with the filter (tests).
	 */
	private function quiet_for( string $carrier_id ): bool {
		$sandbox = 'sandbox' === ( Settings::carrier( $carrier_id )['environment'] ?? '' );
		return $sandbox && ! \apply_filters( 'anchor_shipping_poll_side_effects_in_sandbox', false );
	}

	private function labels(): LabelService {
		return $this->labels ?? \Anchor\Shipping\Module::instance()->labels;
	}

	public function poll(): void {
		$touched = []; // order_id => true: orders with a row that changed status this run
		$now     = \current_time( 'mysql', true );
		foreach ( $this->shipments->pollable( self::BATCH ) as $row ) {
			$carrier = $this->carriers->get( (string) $row['carrier'] );
			$number  = (string) $row['tracking_number'];
			if ( ! $carrier || '' === $number || ! $carrier->supports( 'track' ) ) {
				continue;
			}
			$id = (int) $row['id'];
			try {
				// One number per call: a failure must not cost the rest of the batch their update.
				$result = $carrier->track( [ $number ] )[ $number ] ?? null;
			} catch ( \Throwable $e ) {
				$this->log( sprintf( 'Tracking %s failed: %s', $number, $e->getMessage() ) );
				try {
					$this->shipments->update( $id, [ 'last_polled_at' => $now ] );
				} catch ( \Throwable $e2 ) {
					$this->log( sprintf( 'Could not stamp %s: %s', $number, $e2->getMessage() ) );
				}
				continue;
			}
			try {
				$fresh = $this->shipments->find( $id );
				if ( ! $fresh || 'voided' === $fresh['status'] ) {
					continue; // voided (by a person) since the batch was read: leave it alone
				}
				if ( $this->apply( $fresh, $result, $now ) && ! $this->quiet_for( (string) $row['carrier'] ) ) {
					$touched[ (int) $row['order_id'] ] = true;
				}
			} catch ( \Throwable $e ) {
				$this->log( sprintf( 'Could not record tracking for %s: %s', $number, $e->getMessage() ) );
			}
		}
		foreach ( array_keys( $touched ) as $order_id ) {
			try {
				$this->settle_order( $order_id );
			} catch ( \Throwable $e ) {
				$this->log( sprintf( 'Could not settle order %d after tracking: %s', $order_id, $e->getMessage() ) );
			}
		}
	}

	/** @return bool true when the row's status changed */
	private function apply( array $row, ?TrackingResult $r, string $now ): bool {
		$id = (int) $row['id'];
		// Only "carrier has no such package" (unknown) or a genuine label_created counts as never scanned;
		// an unmapped status type (unknown_type) must leave the row alone.
		$never_scanned = 'label_created' === $row['status'] && $this->older_than_void_window( (string) $row['created_at'] ) && ( ! $r || in_array( $r->status, [ 'label_created', 'unknown' ], true ) );
		$carrier_void  = $r && 'voided' === $r->status;
		if ( $never_scanned || $carrier_void ) {
			$this->shipments->update( $id, [ 'last_polled_at' => $now, 'status' => 'voided', 'voided_at' => $now ] + ( $r && '' !== $r->detail && ! in_array( $r->status, [ 'unknown', 'unknown_type' ], true ) ? [ 'status_detail' => \mb_substr( $r->detail, 0, 255 ) ] : [] ) );
			$this->note( (int) $row['order_id'], sprintf(
				/* translators: %s: tracking number */
				$carrier_void ? \__( 'UPS reports this label as voided: %s', 'anchor-schema' ) : \__( 'UPS auto-voided (never scanned): %s', 'anchor-schema' ),
				$row['tracking_number']
			) );
			$order = \wc_get_order( (int) $row['order_id'] );
			if ( $order ) {
				$this->labels()->reset_state_after_void( $order ); // same reset as a manual void
			}
			return true; // the order may now be fully delivered
		}
		if ( ! $r || in_array( $r->status, [ 'unknown', 'unknown_type' ], true ) ) {
			$this->shipments->update( $id, [ 'last_polled_at' => $now ] );
			return false;
		}
		$changed = $r->status !== $row['status'];
		$fields  = [ 'last_polled_at' => $now, 'status_detail' => \mb_substr( $r->detail, 0, 255 ), 'expected_delivery' => '' !== $r->expected_date ? $r->expected_date : null ];
		if ( '' !== $r->location ) {
			$fields['last_location'] = \mb_substr( $r->location, 0, 128 );
		}
		if ( null !== $r->event_at ) {
			$fields['last_event_at'] = gmdate( 'Y-m-d H:i:s', $r->event_at );
		}

		$fields['status'] = $r->status;
		if ( $changed && 'delivered' === $r->status ) {
			$fields['delivered_at'] = null !== $r->event_at ? gmdate( 'Y-m-d H:i:s', $r->event_at ) : $now;
		}
		$this->shipments->update( $id, $fields );

		if ( $changed && 'exception' === $r->status && ! $this->quiet_for( (string) $row['carrier'] ) ) { // sandbox labels are test shipments: no notes or alerts
			$detail = '' !== $r->detail ? $r->detail : \__( 'Carrier reported a delivery exception.', 'anchor-schema' );
			$this->note( (int) $row['order_id'], sprintf(
				/* translators: 1: tracking number, 2: carrier detail */
				\__( 'Shipping exception on %1$s: %2$s', 'anchor-schema' ),
				$row['tracking_number'],
				$detail
			) );
			\do_action( 'anchor_shipping_problem', (int) $row['order_id'], $detail );
		}
		return $changed;
	}

	private function older_than_void_window( string $created_at ): bool {
		$ts = strtotime( $created_at . ' UTC' );
		return false !== $ts && $ts < time() - self::AUTO_VOID_DAYS * DAY_IN_SECONDS;
	}

	/** First scan and delivery are order-level facts, decided from all of the order's rows. */
	private function settle_order( int $order_id ): void {
		$order = \wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$rows = $this->shipments->active_for_order( $order_id );
		if ( ! $rows ) {
			return;
		}

		$scanned = array_values( array_filter( $rows, static fn( array $r ) => 'label_created' !== $r['status'] ) );
		if ( $scanned && '' === (string) $order->get_meta( self::SHIPPED_META ) ) {
			$order->update_meta_data( self::SHIPPED_META, (string) time() );
			$order->update_meta_data( LabelService::STATE_META, 'shipped' );
			$order->save();
			\do_action( 'anchor_shipping_first_scan', $order_id, $scanned[0] );
		}

		if ( count( array_filter( $rows, static fn( array $r ) => 'delivered' !== $r['status'] ) ) > 0 ) {
			return;
		}
		if ( 'delivered' === $order->get_meta( LabelService::STATE_META ) ) {
			return;
		}
		$order->update_meta_data( LabelService::STATE_META, 'delivered' );
		$order->save();
		$note = \__( 'Delivered: every package shows as delivered.', 'anchor-schema' );
		if ( Settings::complete_on_delivery() && $order->has_status( 'processing' ) ) {
			$order->update_status( 'completed', $note . ' ' );
		} else {
			$order->add_order_note( $note );
		}
	}

	private function note( int $order_id, string $text ): void {
		$order = \wc_get_order( $order_id );
		if ( $order ) {
			$order->add_order_note( $text );
		}
	}

	private function log( string $message ): void {
		if ( \function_exists( 'wc_get_logger' ) ) {
			\wc_get_logger()->warning( $message, [ 'source' => 'anchor-shipping' ] );
		}
	}
}
