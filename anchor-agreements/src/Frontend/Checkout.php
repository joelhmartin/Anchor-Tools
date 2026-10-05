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

	/** @var array<int,int> agreement_id => signature_id that validate() approved in this request. */
	private array $approved = [];

	public function __construct() {
		\add_action( 'woocommerce_guest_session_to_user_id', [ SignatureCheck::class, 'adopt_guest_session' ], 10, 2 );
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
				'<template class="aagr-doc" data-agreement-id="%d" data-version-id="%d" data-title="%s" data-version-date="%s" data-signed="%d">%s</template>',
				$d['agreement_id'],
				$d['id'],
				\esc_attr( $d['title'] ),
				\esc_attr( \wp_date( \get_option( 'date_format' ), strtotime( $d['created_at'] . ' UTC' ) ) ),
				$signed ? 1 : 0,
				\wp_kses_post( \wpautop( $d['content'] ) )
			);
		}
		echo '</div>';
	}

	public function validate( array $data, \WP_Error $errors ): void {
		$this->approved = [];
		$required       = Requirements::for_cart();
		if ( ! $required ) {
			return;
		}
		$this->approved = ( new SignatureCheck() )->valid_map( $required, SignatureCheck::awaiting_order_id() );
		$unsigned       = array_diff( array_map( 'intval', array_keys( $required ) ), array_keys( $this->approved ) );
		if ( $unsigned ) {
			$errors->add( 'anchor_agreements_unsigned', self::unsigned_message( $unsigned ) );
		}
	}

	/** @param int[] $agreement_ids */
	public static function unsigned_message( array $agreement_ids ): string {
		$agreement_ids = array_values( $agreement_ids );
		$v             = 1 === \count( $agreement_ids ) ? ( new VersionRepository() )->current_for( (int) $agreement_ids[0] ) : null;
		if ( $v ) {
			/* translators: %s: agreement title */
			return sprintf( \__( 'Please read and sign the %s before placing your order.', 'anchor-schema' ), \esc_html( \wp_strip_all_tags( $v['title'] ) ) );
		}
		return \__( 'Please read and sign the required agreements before placing your order.', 'anchor-schema' );
	}

	/**
	 * woocommerce_checkout_order_created. Runs after WooCommerce may have logged a new
	 * account in and switched the session, and on a NEW order when the awaiting one was
	 * abandoned, so it reuses validate()'s approved ids and may move a signature off an
	 * unpaid awaiting order. If any required agreement still cannot be attached it
	 * throws: create_order() turns that into a checkout error, so nothing sells unsigned.
	 *
	 * @throws \Exception When a required agreement has no attachable signature.
	 */
	public function attach( \WC_Order $order ): void {
		$approved       = $this->approved;
		$this->approved = [];
		$required       = Requirements::for_order( $order );
		if ( ! $required ) {
			return;
		}
		$order_id = $order->get_id();
		$check    = new SignatureCheck();
		$repo     = new SignatureRepository();
		$awaiting = SignatureCheck::awaiting_order_id();
		$plan     = [];
		foreach ( array_keys( $required ) as $aid ) {
			$aid = (int) $aid;
			$sig = $approved[ $aid ] ?? 0;
			$sig = $sig && SignatureCheck::is_current_version( (array) $repo->get( $sig ) ) ? $sig : 0; // The agreement may have changed since validate().
			$sig = $sig ?: $check->valid_signature_id( $aid, $awaiting );
			$sig = $sig ?: $check->valid_signature_id( $aid, $order_id ); // Already ours (hook re-run).
			$row = $sig ? $repo->get( $sig ) : null;
			$from = $row && null !== $row['order_id'] && $row['order_id'] !== $order_id ? $row['order_id'] : 0;
			if ( ! $row || $row['agreement_id'] !== $aid || ( $from && ! SignatureCheck::is_reclaimable_order( $from ) ) ) {
				continue;
			}
			$plan[ $aid ] = [ $sig, $from ];
		}
		$missing = array_diff( array_map( 'intval', array_keys( $required ) ), array_keys( $plan ) );
		if ( $missing ) {
			$this->refuse( $order, $missing );
		}

		$ids = [];
		foreach ( $plan as $aid => [ $sig, $from ] ) {
			if ( ! $repo->attach( $sig, $order_id, $from ) ) {
				$missing[] = $aid;
				continue;
			}
			$ids[] = $sig;
			if ( $from ) {
				self::release_from( $from, $sig, $order_id );
			}
		}
		if ( $ids ) {
			$order->update_meta_data( '_anchor_agreement_signature_ids', $ids );
			$order->save_meta_data();
			\do_action( 'anchor_agreements_attached', $order_id, $ids );
		}
		if ( $missing ) {
			$this->refuse( $order, $missing );
		}
	}

	/** The signature moved to the replacement order; keep the old order's record honest. */
	private static function release_from( int $old_order_id, int $sig, int $new_order_id ): void {
		$old = \wc_get_order( $old_order_id );
		if ( ! $old instanceof \WC_Order ) {
			return;
		}
		$ids = array_values( array_diff( array_map( 'intval', (array) $old->get_meta( '_anchor_agreement_signature_ids' ) ), [ $sig ] ) );
		$ids ? $old->update_meta_data( '_anchor_agreement_signature_ids', $ids ) : $old->delete_meta_data( '_anchor_agreement_signature_ids' );
		$old->save_meta_data();
		/* translators: 1: signature id, 2: order number */
		$old->add_order_note( sprintf( \__( 'Agreement signature #%1$d moved to replacement order #%2$s.', 'anchor-schema' ), $sig, $new_order_id ) );
	}

	/**
	 * @param int[] $missing agreement ids.
	 * @throws \Exception Always.
	 */
	private function refuse( \WC_Order $order, array $missing ): void {
		$titles = [];
		foreach ( $missing as $aid ) {
			$v        = ( new VersionRepository() )->current_for( (int) $aid );
			$titles[] = $v ? \wp_strip_all_tags( $v['title'] ) : '#' . (int) $aid;
		}
		/* translators: %s: comma-separated agreement titles */
		$order->add_order_note( sprintf( \__( 'Checkout stopped: no valid signature could be attached for %s.', 'anchor-schema' ), implode( ', ', $titles ) ) );
		if ( \function_exists( 'wc_get_logger' ) ) {
			\wc_get_logger()->warning(
				sprintf( 'Order #%d: checkout stopped, no attachable signature for agreement(s) %s.', $order->get_id(), implode( ', ', array_map( 'intval', $missing ) ) ),
				[ 'source' => 'anchor-agreements' ]
			);
		}
		throw new \Exception( self::unsigned_message( $missing ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped in unsigned_message().
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
