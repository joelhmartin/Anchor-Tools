<?php
declare(strict_types=1);

namespace Anchor\Agreements\Admin;

use Anchor\Agreements\Services\Requirements;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Flags products that require a signed agreement but have no usable document, so a
 * misconfigured product (no default set, document trashed) is visible in the products list
 * rather than silently selling unsigned.
 */
final class ProductListFlag {

	public function __construct() {
		// Priority 20: after WooCommerce renders the name cell (priority 10), so the badge sits beneath it.
		\add_action( 'manage_product_posts_custom_column', [ $this, 'render' ], 20, 2 );
	}

	public function render( $column, $post_id ): void {
		if ( 'name' !== $column ) {
			return;
		}
		$product = \wc_get_product( (int) $post_id );
		if ( ! $product || ! Requirements::is_misconfigured( $product ) ) {
			return;
		}
		printf(
			'<div class="aagr-flag"><span class="dashicons dashicons-warning" style="color:#b32d2e"></span> <strong style="color:#b32d2e">%s</strong></div>',
			\esc_html__( 'Requires a signed agreement, but none is set. Buyers are NOT asked to sign.', 'anchor-schema' )
		);
	}
}
