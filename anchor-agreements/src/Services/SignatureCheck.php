<?php
declare(strict_types=1);

namespace Anchor\Agreements\Services;

use Anchor\Agreements\Database\SignatureRepository;
use Anchor\Agreements\Support\Settings;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * The single answer to "is agreement X signed for this checkout?". Checkout
 * validation, the checkbox render and the endpoint's "remaining" count all ask
 * here, so they can never disagree.
 */
final class SignatureCheck {

	public const SESSION = 'anchor_agreements';

	public function __construct( private ?SignatureRepository $repo = null ) {
		$this->repo = $repo ?: new SignatureRepository();
	}

	public static function session_key(): string {
		if ( ! \function_exists( 'WC' ) || ! \WC()->session ) {
			return '';
		}
		return (string) \WC()->session->get_customer_id();
	}

	public static function session_map(): array {
		$map = \function_exists( 'WC' ) && \WC()->session ? \WC()->session->get( self::SESSION, [] ) : [];
		return \is_array( $map ) ? array_map( 'intval', $map ) : [];
	}

	public static function remember( int $agreement_id, int $signature_id ): void {
		if ( ! \function_exists( 'WC' ) || ! \WC()->session ) {
			return;
		}
		$map                  = self::session_map();
		$map[ $agreement_id ] = $signature_id;
		\WC()->session->set( self::SESSION, $map );
	}

	public static function forget(): void {
		if ( \function_exists( 'WC' ) && \WC()->session ) {
			\WC()->session->set( self::SESSION, [] );
		}
	}

	/**
	 * An order whose signatures may be moved to the order replacing it: WooCommerce
	 * abandons an unpaid awaiting order (cart changed, or hold-stock cancelled it)
	 * and creates a new one. Anything paid or on-hold keeps its signature.
	 */
	public static function is_reclaimable_order( int $order_id ): bool {
		$order = $order_id && \function_exists( 'wc_get_order' ) ? \wc_get_order( $order_id ) : null;
		return $order instanceof \WC_Order && ! $order->is_paid() && $order->has_status( [ 'pending', 'failed', 'cancelled' ] );
	}

	public function valid_signature_id( int $agreement_id, int $awaiting_order_id = 0 ): int {
		$sig_id = self::session_map()[ $agreement_id ] ?? 0;
		$row    = $sig_id ? $this->repo->get( $sig_id ) : null;
		if ( ! $row || $row['agreement_id'] !== $agreement_id || $row['session_key'] !== self::session_key() ) {
			return 0;
		}
		if ( strtotime( $row['signed_at'] . ' UTC' ) < time() - Settings::reuse_seconds() ) {
			return 0;
		}
		if ( null !== $row['order_id'] && ( $row['order_id'] !== $awaiting_order_id || ! self::is_reclaimable_order( $row['order_id'] ) ) ) {
			return 0;
		}
		return $sig_id;
	}

	/** @param array<int,int> $required agreement_id => product_id. @return array<int,int> agreement_id => signature_id, signed ones only. */
	public function valid_map( array $required, int $awaiting_order_id = 0 ): array {
		$out = [];
		foreach ( array_keys( $required ) as $aid ) {
			$sig = $this->valid_signature_id( (int) $aid, $awaiting_order_id );
			if ( $sig ) {
				$out[ (int) $aid ] = $sig;
			}
		}
		return $out;
	}

	/** @param array<int,int> $required agreement_id => product_id. @return int[] */
	public function unsigned( array $required, int $awaiting_order_id = 0 ): array {
		return array_values( array_diff( array_map( 'intval', array_keys( $required ) ), array_keys( $this->valid_map( $required, $awaiting_order_id ) ) ) );
	}

	/**
	 * woocommerce_guest_session_to_user_id: WooCommerce moved this browser's guest
	 * session to the user id (login, or an account created at checkout, which happens
	 * before the order exists). Follow it, so signatures made as a guest still match.
	 * The session map itself travels with the migrated session data.
	 */
	public static function adopt_guest_session( $guest_session_id, $user_session_id ): void {
		$guest = (string) $guest_session_id;
		$user  = (string) $user_session_id;
		if ( '' === $guest || '' === $user || $guest === $user ) {
			return;
		}
		( new SignatureRepository() )->reassign_session( $guest, $user, ctype_digit( $user ) ? (int) $user : 0 );
	}

	public static function awaiting_order_id(): int {
		return \function_exists( 'WC' ) && \WC()->session ? (int) \WC()->session->get( 'order_awaiting_payment', 0 ) : 0;
	}
}
