<?php
declare(strict_types=1);

namespace Anchor\Agreements\Frontend;

use Anchor\Agreements\Database\SignatureRepository;
use Anchor\Agreements\Database\VersionRepository;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class CustomerViews {

	public function __construct() {
		\add_action( 'woocommerce_email_after_order_table', [ self::class, 'email_block' ], 20, 3 );
		\add_shortcode( 'anchor_signed_agreements', [ self::class, 'shortcode' ] );
		\add_filter( 'woocommerce_account_menu_items', [ self::class, 'menu_item' ] );
		// Registers the endpoint with WooCommerce itself (rewrite rule, query var, active menu class).
		\add_filter( 'woocommerce_get_query_vars', fn( array $v ) => array_merge( $v, [ 'signed-documents' => 'signed-documents' ] ) );
		\add_action( 'woocommerce_account_signed-documents_endpoint', [ self::class, 'endpoint' ] );
	}

	private static function title_for( array $row ): string {
		$v = ( new VersionRepository() )->get( (int) $row['version_id'] );
		return $v ? $v['title'] : \__( 'Agreement', 'anchor-schema' );
	}

	public static function email_block( $order, $sent_to_admin, $plain_text ): void {
		if ( $sent_to_admin || ! $order instanceof \WC_Order || ! \Anchor\Agreements\Support\Settings::customer_email_links() ) {
			return;
		}
		$rows = ( new SignatureRepository() )->for_order( $order->get_id() );
		if ( ! $rows ) {
			return;
		}
		if ( $plain_text ) {
			echo "\n" . \esc_html__( 'Your signed agreements:', 'anchor-schema' ) . "\n";
			foreach ( $rows as $r ) {
				echo \wp_strip_all_tags( self::title_for( $r ) ) . ': ' . \esc_url_raw( SignedCopyPage::url( $r['token'] ) ) . "\n";
			}
			return;
		}
		echo '<h2>' . \esc_html__( 'Your signed agreements', 'anchor-schema' ) . '</h2><ul>';
		foreach ( $rows as $r ) {
			printf( '<li>%s (%s) &rarr; <a href="%s">%s</a></li>',
				\esc_html( self::title_for( $r ) ),
				/* translators: %s: date */
				\esc_html( sprintf( \__( 'signed %s', 'anchor-schema' ), \wp_date( \get_option( 'date_format' ), strtotime( $r['signed_at'] . ' UTC' ) ) ) ),
				\esc_url( SignedCopyPage::url( $r['token'] ) ),
				\esc_html__( 'View', 'anchor-schema' )
			);
		}
		echo '</ul>';
	}

	public static function list_html( array $rows ): string {
		if ( ! $rows ) {
			return '<p class="aagr-list aagr-list--empty">' . \esc_html__( 'You have no signed documents yet.', 'anchor-schema' ) . '</p>';
		}
		$out = '<table class="aagr-list shop_table"><thead><tr><th>' . \esc_html__( 'Document', 'anchor-schema' ) . '</th><th>' . \esc_html__( 'Signed', 'anchor-schema' ) . '</th><th>' . \esc_html__( 'Order', 'anchor-schema' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$out .= sprintf( '<tr><td>%s</td><td>%s</td><td>#%d</td><td><a href="%s">%s</a></td></tr>',
				\esc_html( self::title_for( $r ) ),
				\esc_html( \wp_date( \get_option( 'date_format' ), strtotime( $r['signed_at'] . ' UTC' ) ) ),
				(int) $r['order_id'],
				\esc_url( SignedCopyPage::url( $r['token'] ) ),
				\esc_html__( 'View', 'anchor-schema' )
			);
		}
		return $out . '</tbody></table>';
	}

	private static function current_rows(): array {
		$user = \wp_get_current_user();
		return $user->ID ? ( new SignatureRepository() )->for_signer( $user->ID, (string) $user->user_email ) : [];
	}

	public static function shortcode(): string {
		return \is_user_logged_in() ? self::list_html( self::current_rows() ) : '';
	}

	public static function menu_item( array $items ): array {
		$logout = $items['customer-logout'] ?? null;
		unset( $items['customer-logout'] );
		$items['signed-documents'] = \__( 'Signed documents', 'anchor-schema' );
		if ( $logout ) {
			$items['customer-logout'] = $logout;
		}
		return $items;
	}

	public static function endpoint(): void {
		echo self::list_html( self::current_rows() ); // phpcs:ignore -- escaped in list_html.
	}
}
