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
}
