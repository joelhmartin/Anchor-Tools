<?php
declare(strict_types=1);

namespace Anchor\Shipping\Checkout;

use Anchor\Shipping\Services\Protection;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Classic-checkout checkboxes for insurance / signature when the store offers them.
 * The choice lives in the Woo session while the customer is on checkout (so the
 * fee shows live in the order table) and is copied to the order on placement.
 * Placing the order re-reads the submitted boxes before Woo totals the cart, so the
 * fee charged and the order meta always match what was submitted.
 */
final class ProtectionFields {

	private const OPTIONS = [ 'insurance' => 'anchor_shipping_insure', 'signature' => 'anchor_shipping_signature' ];

	public function __construct() {
		\add_action( 'woocommerce_review_order_before_payment', [ $this, 'render' ] );
		\add_action( 'woocommerce_checkout_update_order_review', [ $this, 'capture' ] );
		// Fires inside WC_Checkout::process_checkout() after its nonce check and before
		// update_session() recalculates totals (and so before woocommerce_cart_calculate_fees).
		\add_action( 'woocommerce_checkout_process', [ $this, 'capture_submitted' ] );
		\add_action( 'woocommerce_cart_calculate_fees', [ $this, 'apply_fees' ] );
		\add_action( 'woocommerce_checkout_create_order', [ $this, 'save_to_order' ] );
		\add_action( 'woocommerce_cart_emptied', [ $this, 'clear_choices' ] );
	}

	public function chosen( string $option ): bool {
		return Protection::offered( $option ) && \WC()->session && 'yes' === \WC()->session->get( self::OPTIONS[ $option ] );
	}

	/**
	 * Is the checkbox shown for this cart? One rule for render, fees and the order meta.
	 * A fee of '0' counts as set (Protection::offered()): the option is offered free.
	 * Insurance only exists above $100, which UPS covers anyway.
	 */
	public function available( string $option, \WC_Cart $cart ): bool {
		if ( ! Protection::offered( $option ) || ! $cart->needs_shipping() ) {
			return false;
		}
		return 'insurance' !== $option || $this->cart_shippable_subtotal( $cart ) > 100;
	}

	/** Pre-discount value of shippable lines (line_subtotal). Must agree with Packer::shippable_subtotal() for the order. */
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
		if ( $this->available( 'insurance', \WC()->cart ) ) {
			/* translators: %s: fee */
			$rows['insurance'] = sprintf( \__( 'Insure my shipment for its full value (+%s)', 'anchor-schema' ), \wc_price( Protection::insurance_fee( $subtotal ) ) );
		}
		if ( $this->available( 'signature', \WC()->cart ) ) {
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
		$this->store_choices( $fields );
	}

	/** The final Place order request: its own checkboxes win over the last order-review refresh. */
	public function capture_submitted(): void {
		$this->store_choices( \wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification -- WC_Checkout::process_checkout() verified the nonce
	}

	private function store_choices( array $fields ): void {
		if ( ! \WC()->session ) {
			return;
		}
		foreach ( self::OPTIONS as $key ) {
			\WC()->session->set( $key, isset( $fields[ $key ] ) && 'yes' === $fields[ $key ] ? 'yes' : '' );
		}
	}

	public function apply_fees( \WC_Cart $cart ): void {
		if ( ! $cart->needs_shipping() ) {
			return;
		}
		// A free (fee '0') option is still recorded on the order, it just adds no fee line.
		if ( $this->available( 'insurance', $cart ) && $this->chosen( 'insurance' ) ) {
			$fee = Protection::insurance_fee( $this->cart_shippable_subtotal( $cart ) );
			if ( $fee > 0 ) {
				$cart->add_fee( \__( 'Shipping insurance', 'anchor-schema' ), $fee, false );
			}
		}
		if ( $this->available( 'signature', $cart ) && $this->chosen( 'signature' ) && Protection::signature_fee() > 0 ) {
			$cart->add_fee( \__( 'Signature on delivery', 'anchor-schema' ), Protection::signature_fee(), false );
		}
	}

	public function save_to_order( \WC_Order $order ): void {
		$cart   = \WC()->cart;
		$insure = $cart && $this->available( 'insurance', $cart ) && $this->chosen( 'insurance' );
		$sign   = $cart && $this->available( 'signature', $cart ) && $this->chosen( 'signature' );
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
