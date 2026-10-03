<?php
declare(strict_types=1);

namespace Anchor\Agreements\Frontend;

use Anchor\Agreements\Database\SignatureRepository;
use Anchor\Agreements\Database\VersionRepository;
use Anchor\Agreements\Module;
use Anchor\Agreements\Services\Requirements;
use Anchor\Agreements\Services\SignatureCheck;
use Anchor\Agreements\Support\Settings;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Classic + FunnelKit checkout. Both fire woocommerce_review_order_before_submit
 * inside the payment fragment that update_order_review REPLACES on every refresh,
 * so the checked state is always rendered from the session, never kept in JS.
 */
final class Checkout {

	public function __construct() {
		\add_action( 'woocommerce_review_order_before_submit', [ $this, 'render' ] );
		\add_action( 'woocommerce_after_checkout_validation', [ $this, 'validate' ], 10, 2 );
		\add_action( 'woocommerce_checkout_order_created', [ $this, 'attach' ] );
		\add_action( 'woocommerce_thankyou', [ $this, 'forget_on_thankyou' ] );
		\add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ $this, 'guard_store_api' ] );
		\add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	public function enqueue(): void {
		if ( ! \is_checkout() || \is_order_received_page() || ! Requirements::for_cart() ) {
			return;
		}
		$dir = Module::dir() . '/assets/';
		\wp_enqueue_style( 'aagr-checkout', Module::url( 'assets/checkout.css' ), [], (string) @filemtime( $dir . 'checkout.css' ) );
		\wp_enqueue_script( 'aagr-checkout', Module::url( 'assets/checkout.js' ), [ 'jquery' ], (string) @filemtime( $dir . 'checkout.js' ), true );
		\wp_localize_script( 'aagr-checkout', 'aagrCheckout', [
			'endpoint' => \WC_AJAX::get_endpoint( SigningEndpoint::ACTION ),
			'nonce'    => \wp_create_nonce( SigningEndpoint::NONCE ),
			'fonts'    => [
				[ 'key' => 'dancing-script', 'family' => 'Aagr Dancing Script' ],
				[ 'key' => 'great-vibes', 'family' => 'Aagr Great Vibes' ],
				[ 'key' => 'allura', 'family' => 'Aagr Allura' ],
				[ 'key' => 'caveat', 'family' => 'Aagr Caveat' ],
			],
			'i18n'     => [
				'draw' => \__( 'Draw', 'anchor-schema' ), 'generate' => \__( 'Generate', 'anchor-schema' ),
				'clear' => \__( 'Clear', 'anchor-schema' ), 'name' => \__( 'Full legal name', 'anchor-schema' ),
				'consent' => Settings::consent_text(),
				'sign' => \__( 'Sign & continue', 'anchor-schema' ), 'step' => \__( '%1$d of %2$d', 'anchor-schema' ),
				'close' => \__( 'Close', 'anchor-schema' ), 'next' => \__( 'Next', 'anchor-schema' ), 'error' => \__( 'We could not save your signature. Please try again.', 'anchor-schema' ),
				'version' => \__( 'Version of %s', 'anchor-schema' ),
			],
		] );
	}

	public function render(): void {
		$required = Requirements::for_cart();
		if ( ! $required ) {
			return;
		}
		$check    = new SignatureCheck();
		$unsigned = $check->unsigned( $required, SignatureCheck::awaiting_order_id() );
		$complete = ! $unsigned;
		$versions = new VersionRepository();
		$docs     = [];
		foreach ( array_keys( $required ) as $aid ) {
			$v = $versions->current_for( (int) $aid );
			if ( $v ) {
				$docs[] = $v;
			}
		}
		if ( ! $docs ) {
			return;
		}
		$label = 1 === \count( $docs )
			? Settings::label_for( $docs[0]['title'] )
			/* translators: %d: number of agreements */
			: sprintf( \__( 'I have read and signed %d required agreements', 'anchor-schema' ), \count( $docs ) );

		echo '<div class="aagr-checkout" data-complete="' . ( $complete ? '1' : '0' ) . '">';
		echo '<label class="aagr-checkout__label"><input type="checkbox" id="aagr-confirm" class="aagr-checkout__box" ' . \checked( $complete, true, false ) . ' tabindex="-1" aria-readonly="true" /> ';
		echo '<span>' . \esc_html( $label ) . '</span></label> ';
		echo '<button type="button" class="aagr-open">' . \esc_html( $complete ? \__( 'View', 'anchor-schema' ) : \__( 'Read & sign', 'anchor-schema' ) ) . '</button>';
		foreach ( $docs as $d ) {
			$signed = ! \in_array( $d['agreement_id'], $unsigned, true );
			printf(
				'<template class="aagr-doc" data-agreement-id="%d" data-title="%s" data-version-date="%s" data-signed="%d">%s</template>',
				$d['agreement_id'],
				\esc_attr( $d['title'] ),
				\esc_attr( \wp_date( \get_option( 'date_format' ), strtotime( $d['created_at'] . ' UTC' ) ) ),
				$signed ? 1 : 0,
				\wp_kses_post( \wpautop( $d['content'] ) )
			);
		}
		echo '</div>';
	}

	public function validate( array $data, \WP_Error $errors ): void {
		$required = Requirements::for_cart();
		if ( ! $required ) {
			return;
		}
		if ( ( new SignatureCheck() )->unsigned( $required, SignatureCheck::awaiting_order_id() ) ) {
			$errors->add( 'anchor_agreements_unsigned', \__( 'Please read and sign the required agreement before placing your order.', 'anchor-schema' ) );
		}
	}

	public function attach( \WC_Order $order ): void {
		$required = Requirements::for_order( $order );
		if ( ! $required ) {
			return;
		}
		$check = new SignatureCheck();
		$repo  = new SignatureRepository();
		$ids   = [];
		foreach ( array_keys( $required ) as $aid ) {
			$sig = $check->valid_signature_id( (int) $aid, $order->get_id() );
			if ( $sig && $repo->attach( $sig, $order->get_id() ) ) {
				$ids[] = $sig;
			}
		}
		if ( $ids ) {
			$order->update_meta_data( '_anchor_agreement_signature_ids', $ids );
			$order->save_meta_data();
			\do_action( 'anchor_agreements_attached', $order->get_id(), $ids );
		}
	}

	public function forget_on_thankyou(): void {
		SignatureCheck::forget();
	}

	public function guard_store_api( \WC_Order $order ): void {
		if ( Requirements::for_order( $order ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
				'anchor_agreements_block_checkout',
				\__( 'This order requires a signed agreement, which the block checkout does not support. Please contact us to complete your purchase.', 'anchor-schema' ),
				400
			);
		}
	}
}
