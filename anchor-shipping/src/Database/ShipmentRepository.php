<?php
declare(strict_types=1);

namespace Anchor\Shipping\Database;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** One row per package. Voided rows are kept for the audit trail. */
final class ShipmentRepository {

	public function insert( array $row ): int {
		global $wpdb;
		$row += [
			'status'     => 'label_created',
			'created_at' => \current_time( 'mysql', true ),
			'created_by' => \get_current_user_id(),
		];
		if ( false === $wpdb->insert( Migrations::table(), $row ) ) {
			throw new \RuntimeException( 'Could not save the shipment record: ' . $wpdb->last_error );
		}
		return (int) $wpdb->insert_id;
	}

	public function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Migrations::table() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore
		return $row ?: null;
	}

	public function for_order( int $order_id ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Migrations::table() . ' WHERE order_id = %d ORDER BY id', $order_id ), ARRAY_A ) ?: []; // phpcs:ignore
	}

	public function active_for_order( int $order_id ): array {
		return array_values( array_filter( $this->for_order( $order_id ), static fn( array $r ) => 'voided' !== $r['status'] ) );
	}

	public function by_shipment_id( string $carrier, string $shipment_id ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Migrations::table() . ' WHERE carrier = %s AND shipment_id = %s ORDER BY id', $carrier, $shipment_id ), ARRAY_A ) ?: []; // phpcs:ignore
	}

	public function update( int $id, array $fields ): void {
		global $wpdb;
		if ( false === $wpdb->update( Migrations::table(), $fields, [ 'id' => $id ] ) ) {
			throw new \RuntimeException( 'Could not update the shipment record: ' . $wpdb->last_error );
		}
	}

	/**
	 * Rows worth asking the carrier about: still moving, shipped within the last 30 days,
	 * the longest-unpolled first (never-polled rows lead). $carriers limits it to carriers that
	 * can be asked (null = any), so tracking-only rows never take a batch slot.
	 *
	 * @param string[]|null $carriers
	 */
	public function pollable( int $limit, ?array $carriers = null ): array {
		global $wpdb;
		if ( [] === $carriers ) {
			return [];
		}
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
		$only   = null === $carriers ? '' : ' AND carrier IN (' . implode( ',', array_fill( 0, count( $carriers ), '%s' ) ) . ')';
		$args   = array_merge( [ $cutoff ], null === $carriers ? [] : array_values( $carriers ), [ $limit ] );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM " . Migrations::table() . " WHERE status NOT IN ('voided','delivered','returned') AND created_at > %s{$only} ORDER BY last_polled_at IS NULL DESC, last_polled_at ASC, id ASC LIMIT %d", $args ), ARRAY_A ) ?: []; // phpcs:ignore
	}
}
