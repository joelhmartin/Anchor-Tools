<?php
declare(strict_types=1);

namespace Anchor\Shipping\Checkout;

use Anchor\Shipping\Services\Protection;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Classic-checkout checkboxes for insurance / signature when the store offers them.
 * The choice lives in the Woo session while the customer is on checkout (so the
 * fee shows live in the order table) and is copied to the order on placement.
 */
final class ProtectionFields {

	private const OPTIONS = [ 'insurance' => 'anchor_shipping_insure', 'signature' => 'anchor_shipping_signature' ];

	public function __construct() {
		\add_action( 'woocommerce_review_order_before_payment', [ $this, 'render' ] );
		\add_action( 'woocommerce_checkout_update_order_review', [ $this, 'capture' ] );
		\add_action( 'woocommerce_cart_calculate_fees', [ $this, 'apply_fees' ] );
		\add_action( 'woocommerce_checkout_create_order', [ $this, 'save_to_order' ] );
		\add_action( 'woocommerce_cart_emptied', [ $this, 'clear_choices' ] );
	}

	public function chosen( string $option ): bool {
		return Protection::offered( $option ) && \WC()->session && 'yes' === \WC()->session->get( self::OPTIONS[ $option ] );
	}

	public function cart_shippable_subtotal( \WC_Cart $cart ): float {
		$total = 0.0;
		foreach ( $cart->get_cart() as $line ) {
			$product = $line['data'] ?? null;
			if ( $product instanceof \WC_Product && $product->needs_shipping() ) {
				$total += (float) $line['line_subtotal'];
			}
		}
		return round( $total, 2 );
	}

	public function render(): void {
		if ( ! \WC()->cart || ! \WC()->cart->needs_shipping() ) {
			return;
		}
		$subtotal = $this->cart_shippable_subtotal( \WC()->cart );
		$rows     = [];
		if ( Protection::offered( 'insurance' ) && $subtotal > 100 ) {
			/* translators: %s: fee */
			$rows['insurance'] = sprintf( \__( 'Insure my shipment for its full value (+%s)', 'anchor-schema' ), \wc_price( Protection::insurance_fee( $subtotal ) ) );
		}
		if ( Protection::offered( 'signature' ) ) {
			/* translators: %s: fee */
			$rows['signature'] = sprintf( \__( 'Require a signature on delivery (+%s)', 'anchor-schema' ), \wc_price( Protection::signature_fee() ) );
		}
		if ( ! $rows ) {
			return;
		}
		echo '<div class="anchor-shipping-protection">';
		foreach ( $rows as $option => $label ) {
			printf(
				'<p class="form-row"><label class="checkbox"><input type="checkbox" class="input-checkbox" name="%1$s" value="yes" %2$s> %3$s</label></p>',
				\esc_attr( self::OPTIONS[ $option ] ),
				\checked( $this->chosen( $option ), true, false ),
				\wp_kses_post( $label )
			);
		}
		echo '</div>';
		// Re-total when ticked: Woo only refreshes on its own address fields.
		echo "<script>jQuery(function($){ $(document.body).off('change.anchorShipping').on('change.anchorShipping', '.anchor-shipping-protection input', function(){ $(document.body).trigger('update_checkout'); }); });</script>";
	}

	/** @param string $post_data serialized checkout form from update_order_review */
	public function capture( $post_data ): void {
		parse_str( (string) $post_data, $fields );
		foreach ( self::OPTIONS as $key ) {
			\WC()->session->set( $key, isset( $fields[ $key ] ) && 'yes' === $fields[ $key ] ? 'yes' : '' );
		}
	}

	public function apply_fees( \WC_Cart $cart ): void {
		if ( ! $cart->needs_shipping() ) {
			return;
		}
		if ( $this->chosen( 'insurance' ) ) {
			$fee = Protection::insurance_fee( $this->cart_shippable_subtotal( $cart ) );
			if ( $fee > 0 ) {
				$cart->add_fee( \__( 'Shipping insurance', 'anchor-schema' ), $fee, false );
			}
		}
		if ( $this->chosen( 'signature' ) && Protection::signature_fee() > 0 ) {
			$cart->add_fee( \__( 'Signature on delivery', 'anchor-schema' ), Protection::signature_fee(), false );
		}
	}

	public function save_to_order( \WC_Order $order ): void {
		$cart     = \WC()->cart;
		$shipping = $cart && $cart->needs_shipping();
		$insure   = $shipping && $this->chosen( 'insurance' ) && Protection::insurance_fee( $this->cart_shippable_subtotal( $cart ) ) > 0;
		$sign     = $shipping && $this->chosen( 'signature' ) && Protection::signature_fee() > 0;
		$order->update_meta_data( Protection::META_INSURE, $insure ? 'yes' : '' );
		$order->update_meta_data( Protection::META_SIGNATURE, $sign ? 'yes' : '' );
	}

	/** Woo empties the cart only after a successful order (or when the shopper does), so a declined payment keeps the choice. */
	public function clear_choices(): void {
		if ( \WC()->session ) {
			foreach ( self::OPTIONS as $key ) {
				\WC()->session->set( $key, '' );
			}
		}
	}
}
