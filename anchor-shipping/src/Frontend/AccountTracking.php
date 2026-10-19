<?php
declare(strict_types=1);

namespace Anchor\Shipping\Frontend;

use Anchor\Shipping\Module;
use Anchor\Shipping\Support\Template;
use Anchor\Shipping\Support\TrackingLinks;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** Tracking on the customer's side: the order page (My Account → view order, thank-you page) and a column in My Account → Orders. */
final class AccountTracking {

	public const COLUMN = 'anchor-shipping-tracking';

	public function __construct( private TrackingLinks $links ) {
		\add_action( 'woocommerce_order_details_after_order_table', [ $this, 'render' ] );
		\add_filter( 'woocommerce_account_orders_columns', [ $this, 'add_column' ] );
		\add_action( 'woocommerce_my_account_my_orders_column_' . self::COLUMN, [ $this, 'render_column' ] );
		\add_action( 'wp_enqueue_scripts', [ $this, 'assets' ] );
	}

	public function assets(): void {
		if ( \function_exists( 'is_account_page' ) && ( \is_account_page() || \is_order_received_page() ) ) {
			\wp_enqueue_style( 'anchor-shipping-account', Module::url( 'assets/account.css' ), [], '1.0.0' );
		}
	}

	public function render( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$rows = $this->links->for_order( $order->get_id() );
		if ( ! $rows ) {
			return;
		}
		echo Template::render( 'account/tracking', [ 'rows' => $rows ] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the template
	}

	/** Inserts the Tracking column after Status (or before Actions, or at the end). */
	public function add_column( $columns ): array {
		$columns = (array) $columns;
		$label   = \__( 'Tracking', 'anchor-schema' );
		$after   = isset( $columns['order-status'] ) ? 'order-status' : null;
		$out     = [];
		foreach ( $columns as $key => $name ) {
			if ( null === $after && 'order-actions' === $key ) {
				$out[ self::COLUMN ] = $label;
			}
			$out[ $key ] = $name;
			if ( $key === $after ) {
				$out[ self::COLUMN ] = $label;
			}
		}
		$out[ self::COLUMN ] ??= $label;
		return $out;
	}

	public function render_column( $order ): void {
		$rows = $order instanceof \WC_Order ? $this->links->for_order( $order->get_id() ) : [];
		if ( ! $rows ) {
			echo '&mdash;';
			return;
		}
		$cells = [];
		foreach ( $rows as $r ) {
			$expected = ( '' !== $r['expected'] && ! in_array( $r['status_key'], [ 'delivered', 'returned' ], true ) )
				? ' <small>' . \esc_html( sprintf( /* translators: %s: expected delivery date */ \__( 'Expected %s', 'anchor-schema' ), \wp_date( 'M j', (int) strtotime( $r['expected'] . ' 12:00:00 UTC' ), new \DateTimeZone( 'UTC' ) ) ) ) . '</small>'
				: '';
			$cells[]  = '<span class="anchor-shipping-tracking-cell">' . self::link( $r ) . ( '' !== $r['status'] ? ' <small>' . \esc_html( $r['status'] ) . '</small>' : '' ) . $expected . '</span>';
		}
		echo implode( '<br>', $cells );
	}

	/** @param array{carrier: string, number: string, url: string, status: string} $r */
	private static function link( array $r ): string {
		return '' !== $r['url']
			? '<a href="' . \esc_url( $r['url'] ) . '" target="_blank" rel="noopener">' . \esc_html( $r['number'] ) . '</a>'
			: \esc_html( $r['number'] );
	}
}
