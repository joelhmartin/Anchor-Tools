<?php
declare(strict_types=1);

namespace Anchor\Announcements\Audience;

use Automattic\WooCommerce\Utilities\OrderUtil;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Order queries for the WooCommerce conditions, over the order tables themselves (HPOS
 * or legacy posts, whichever the store uses). The analytics lookup tables are not used:
 * they stop updating when WooCommerce Analytics is off (spec 1).
 */
final class WooOrders {

	public static function available(): bool {
		return \class_exists( 'WooCommerce' );
	}

	/** @return list<string> e.g. [ 'wc-completed', 'wc-processing' ]. */
	public static function statuses( array $raw ): array {
		$valid = \array_keys( \wc_get_order_statuses() );
		$out   = [];
		foreach ( $raw as $s ) {
			$s = 'wc-' . \preg_replace( '/^wc-/', '', \sanitize_key( (string) $s ) );
			if ( \in_array( $s, $valid, true ) ) {
				$out[] = $s;
			}
		}
		return $out ? \array_values( \array_unique( $out ) ) : [ 'wc-completed', 'wc-processing' ];
	}

	/** @return list<array{order_id:int,email:string,user_id:int,name:string,total:float}> */
	public static function orders( array $statuses, ?string $from_gmt, ?string $to_gmt, array $product_ids = [] ): array {
		global $wpdb;
		$statuses    = $statuses ? $statuses : [ 'wc-completed', 'wc-processing' ];
		$product_ids = \array_values( \array_filter( \array_map( 'absint', $product_ids ) ) );
		$args        = [];
		$in_status   = \implode( ',', \array_fill( 0, \count( $statuses ), '%s' ) );

		if ( \class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$orders = $wpdb->prefix . 'wc_orders';
			$addr   = $wpdb->prefix . 'wc_order_addresses';
			$sql    = "SELECT o.id AS order_id, o.billing_email AS email, o.customer_id AS user_id, CONCAT_WS(' ', a.first_name, a.last_name) AS name, o.total_amount AS total
				FROM {$orders} o LEFT JOIN {$addr} a ON a.order_id = o.id AND a.address_type = 'billing'
				WHERE o.type = 'shop_order' AND o.status IN ({$in_status})";
			$id_col   = 'o.id';
			$date_col = 'o.date_created_gmt';
		} else {
			$pm       = $wpdb->postmeta;
			$sql      = "SELECT p.ID AS order_id, em.meta_value AS email, CAST(cu.meta_value AS UNSIGNED) AS user_id, CONCAT_WS(' ', fn.meta_value, ln.meta_value) AS name, CAST(tot.meta_value AS DECIMAL(20,4)) AS total
				FROM {$wpdb->posts} p
				LEFT JOIN {$pm} em ON em.post_id = p.ID AND em.meta_key = '_billing_email'
				LEFT JOIN {$pm} cu ON cu.post_id = p.ID AND cu.meta_key = '_customer_user'
				LEFT JOIN {$pm} fn ON fn.post_id = p.ID AND fn.meta_key = '_billing_first_name'
				LEFT JOIN {$pm} ln ON ln.post_id = p.ID AND ln.meta_key = '_billing_last_name'
				LEFT JOIN {$pm} tot ON tot.post_id = p.ID AND tot.meta_key = '_order_total'
				WHERE p.post_type = 'shop_order' AND p.post_status IN ({$in_status})";
			$id_col   = 'p.ID';
			$date_col = 'p.post_date_gmt';
		}
		$args = $statuses;
		if ( $from_gmt ) { $sql .= " AND {$date_col} >= %s"; $args[] = $from_gmt; }
		if ( $to_gmt ) { $sql .= " AND {$date_col} <= %s"; $args[] = $to_gmt; }
		if ( $product_ids ) {
			$items = $wpdb->prefix . 'woocommerce_order_items';
			$meta  = $wpdb->prefix . 'woocommerce_order_itemmeta';
			$in_p  = \implode( ',', \array_fill( 0, \count( $product_ids ), '%d' ) );
			$sql  .= " AND {$id_col} IN (SELECT oi.order_id FROM {$items} oi JOIN {$meta} im ON im.order_item_id = oi.order_item_id
				WHERE oi.order_item_type = 'line_item' AND im.meta_key IN ('_product_id','_variation_id') AND im.meta_value IN ({$in_p}))";
			$args  = \array_merge( $args, $product_ids );
		}

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders only.
		$out  = [];
		foreach ( $rows as $r ) {
			$user_id = (int) $r->user_id;
			$user    = $user_id > 0 ? \get_userdata( $user_id ) : false;
			$out[]   = [
				'order_id' => (int) $r->order_id,
				'email'    => $user ? (string) $user->user_email : (string) $r->email,
				'user_id'  => $user ? $user_id : 0,
				'name'     => $user ? (string) $user->display_name : \trim( (string) $r->name ),
				'total'    => (float) $r->total,
			];
		}
		return $out;
	}

	public static function add_to( RecipientSet $set, array $row ): void {
		$set->add( $row['email'], $row['user_id'], $row['name'] );
	}

	/**
	 * Per-person aggregate over orders in range: 'count' or 'total'. Keyed by the
	 * recipient's email (a registered customer's account email).
	 *
	 * @return array<string,array{row:array,value:float}>
	 */
	public static function aggregate( string $measure, array $statuses, ?string $from_gmt, ?string $to_gmt ): array {
		$out = [];
		foreach ( self::orders( $statuses, $from_gmt, $to_gmt ) as $row ) {
			$key = \strtolower( $row['email'] );
			if ( ! isset( $out[ $key ] ) || ( $row['user_id'] > 0 && 0 === $out[ $key ]['row']['user_id'] ) ) {
				$out[ $key ] = [ 'row' => $row, 'value' => $out[ $key ]['value'] ?? 0.0 ];
			}
			$out[ $key ]['value'] += 'count' === $measure ? 1.0 : $row['total'];
		}
		return $out;
	}

	public static function compare( float $value, string $op, float $target ): bool {
		switch ( $op ) {
			case '<=': return $value <= $target;
			case '=':  return \abs( $value - $target ) < 0.005;
			default:   return $value >= $target;
		}
	}
}
