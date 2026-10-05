<?php
declare(strict_types=1);

namespace Anchor\Agreements\Admin;

use Anchor\Agreements\Database\SignatureRepository;
use Anchor\Agreements\Database\VersionRepository;
use Anchor\Agreements\Frontend\SignedCopyPage;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class OrderMetabox {

	public function __construct() {
		\add_action( 'add_meta_boxes', [ $this, 'register' ], 30 );
	}

	public function register(): void {
		$screen = \function_exists( 'wc_get_page_screen_id' ) ? \wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		\add_meta_box( 'anchor-agreements', \__( 'Signed agreements', 'anchor-schema' ), [ $this, 'render' ], $screen, 'side', 'default' );
	}

	public function render( $post_or_order ): void {
		$order = $post_or_order instanceof \WC_Order ? $post_or_order : \wc_get_order( $post_or_order->ID );
		$rows  = $order ? ( new SignatureRepository() )->for_order( $order->get_id() ) : [];
		if ( ! $rows ) {
			echo '<p>' . \esc_html__( 'No signed agreements on this order.', 'anchor-schema' ) . '</p>';
			return;
		}
		$versions = new VersionRepository();
		foreach ( $rows as $r ) {
			$v = $versions->get( (int) $r['version_id'] );
			printf( '<p><strong>%s</strong><br>%s · %s<br>%s · IP %s<br><a href="%s" target="_blank">%s</a></p>',
				\esc_html( $v['title'] ?? '' ),
				\esc_html( $r['signer_name'] ), \esc_html( $r['method'] ),
				\esc_html( \wp_date( 'Y-m-d H:i', strtotime( $r['signed_at'] . ' UTC' ) ) ), \esc_html( $r['ip'] ),
				\esc_url( SignedCopyPage::url( $r['token'] ) ), \esc_html__( 'View signed copy', 'anchor-schema' )
			);
		}
	}
}
