<?php
declare(strict_types=1);

namespace Anchor\CheckoutQuestions\Admin;

use Anchor\CheckoutQuestions\Services\Matcher;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** Read-only line on the product General tab: which question sets would apply. */
final class ProductNotice {

	public function __construct() {
		\add_action( 'woocommerce_product_options_general_product_data', [ $this, 'render' ] );
	}

	public function render(): void {
		$product_id = (int) ( $GLOBALS['post']->ID ?? 0 );
		if ( $product_id <= 0 ) {
			return;
		}
		$links = [];
		foreach ( Matcher::for_lines( [ [ 'product_id' => $product_id, 'variation_id' => 0 ] ] ) as $set_id ) {
			$url = \get_edit_post_link( $set_id, 'raw' );
			$title = \esc_html( \get_the_title( $set_id ) );
			$links[] = $url ? '<a href="' . \esc_url( $url ) . '">' . $title . '</a>' : $title;
		}
		if ( ! $links ) {
			return;
		}
		echo '<p class="form-field"><strong>' . \esc_html__( 'Checkout questions:', 'anchor-schema' ) . '</strong> '
			. \wp_kses( \implode( ', ', $links ), [ 'a' => [ 'href' => [] ] ] ) . '</p>';
	}
}
