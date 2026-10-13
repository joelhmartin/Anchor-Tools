<?php
declare(strict_types=1);

namespace Anchor\Shipping\Support;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** Where staff go to act on an order: the Shipping console page when configured, else the order edit screen. */
final class Urls {

	/** Permalink of the configured, published console page ('' when none). */
	public static function console( array $args = [] ): string {
		$page = (int) Settings::all()['console_page'];
		if ( $page <= 0 || 'publish' !== \get_post_status( $page ) ) {
			return '';
		}
		$url = (string) \get_permalink( $page );
		return $args ? \add_query_arg( $args, $url ) : $url;
	}

	public static function order( \WC_Order $order ): string {
		$url = self::console( [ 'order' => $order->get_id() ] );
		return '' !== $url ? $url : $order->get_edit_order_url();
	}

	/** @return string[] lower-cased addresses that receive WooCommerce's New order email; [] when it is disabled. */
	public static function new_order_recipients(): array {
		$opt = (array) \get_option( 'woocommerce_new_order_settings', [] );
		if ( 'no' === ( $opt['enabled'] ?? 'yes' ) ) {
			return [];
		}
		$raw = trim( (string) ( $opt['recipient'] ?? '' ) );
		$raw = '' !== $raw ? $raw : (string) \get_option( 'admin_email' );
		return array_values( array_filter( array_map( static fn( string $a ): string => strtolower( trim( $a ) ), explode( ',', $raw ) ) ) );
	}
}
