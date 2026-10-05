<?php
declare(strict_types=1);

namespace Anchor\Agreements\Admin;

use Anchor\Agreements\Content\AgreementPostType;
use Anchor\Agreements\Services\Requirements;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class ProductFields {

	public function __construct() {
		\add_action( 'woocommerce_product_options_general_product_data', [ $this, 'render' ] );
		\add_action( 'woocommerce_admin_process_product_object', [ $this, 'save' ] );
	}

	public function render(): void {
		global $product_object;
		$options = [ '0' => \__( '— Site default —', 'anchor-schema' ) ];
		foreach ( \get_posts( [ 'post_type' => AgreementPostType::CPT, 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ] ) as $p ) {
			$options[ (string) $p->ID ] = $p->post_title;
		}
		echo '<div class="options_group">';
		\woocommerce_wp_checkbox( [
			'id'          => '_anchor_agreement_required',
			'label'       => \__( 'Require signed agreement', 'anchor-schema' ),
			'description' => \__( 'Buyers must sign before they can place the order.', 'anchor-schema' ),
			'value'       => $product_object ? $product_object->get_meta( '_anchor_agreement_required' ) : '',
		] );
		\woocommerce_wp_select( [
			'id'      => '_anchor_agreement_id',
			'label'   => \__( 'Agreement', 'anchor-schema' ),
			'options' => $options,
			'value'   => $product_object ? (string) (int) $product_object->get_meta( '_anchor_agreement_id' ) : '0',
		] );
		echo '</div>';
	}

	public function save( \WC_Product $product ): void {
		// Nonce is verified by WooCommerce before woocommerce_admin_process_product_object fires.
		$required = isset( $_POST['_anchor_agreement_required'] ) ? 'yes' : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$id       = isset( $_POST['_anchor_agreement_id'] ) ? \absint( \wp_unslash( $_POST['_anchor_agreement_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( 'yes' === $required ) {
			$product->update_meta_data( '_anchor_agreement_required', 'yes' );
		} else {
			$product->delete_meta_data( '_anchor_agreement_required' );
		}
		$product->update_meta_data( '_anchor_agreement_id', $id );

		if ( Requirements::is_misconfigured( $product ) && class_exists( '\WC_Admin_Meta_Boxes' ) ) {
			\WC_Admin_Meta_Boxes::add_error( \__( 'This product requires a signed agreement, but no published agreement is selected and no site default is set. Buyers will NOT be asked to sign until this is fixed (Agreements → Settings).', 'anchor-schema' ) );
		}
	}
}
