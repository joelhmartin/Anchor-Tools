<?php
declare(strict_types=1);

namespace Anchor\Agreements\Frontend;

use Anchor\Agreements\Database\SignatureRepository;
use Anchor\Agreements\Database\VersionRepository;
use Anchor\Agreements\Services\Requirements;
use Anchor\Agreements\Services\SignatureCheck;
use Anchor\Agreements\Support\SignatureImage;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class SigningEndpoint {

	public const ACTION = 'anchor_agreement_sign';
	public const NONCE  = 'anchor_agreements_sign';
	public const FONTS  = [ 'dancing-script', 'great-vibes', 'allura', 'caveat' ];
	private const LIMIT = 10;

	public function __construct() {
		\add_action( 'wc_ajax_' . self::ACTION, [ $this, 'ajax' ] );
	}

	public function ajax(): void {
		if ( ! \check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			\wp_send_json( [ 'ok' => false, 'error' => 'nonce' ], 403 );
		}
		$in  = \wp_unslash( $_POST );
		$res = $this->handle( [
			'agreement_id' => $in['agreement_id'] ?? 0,
			'name'         => $in['name'] ?? '',
			'method'       => $in['method'] ?? '',
			'font'         => $in['font'] ?? '',
			'image'        => $in['image'] ?? '',
			'consent'      => $in['consent'] ?? '',
			'version_id'   => $in['version_id'] ?? 0,
		] );
		\wp_send_json( $res, $res['ok'] ? 200 : 400 );
	}

	public function handle( array $in ): array {
		$aid      = \absint( $in['agreement_id'] ?? 0 );
		$required = Requirements::for_cart();
		if ( ! isset( $required[ $aid ] ) ) {
			return [ 'ok' => false, 'error' => 'agreement' ];
		}
		if ( '1' !== (string) ( $in['consent'] ?? '' ) ) {
			return [ 'ok' => false, 'error' => 'consent' ];
		}
		$name = trim( \sanitize_text_field( (string) ( $in['name'] ?? '' ) ) );
		if ( '' === $name || mb_strlen( $name ) > 120 ) {
			return [ 'ok' => false, 'error' => 'name' ];
		}
		$method = (string) ( $in['method'] ?? '' );
		if ( ! \in_array( $method, [ 'draw', 'generate' ], true ) ) {
			return [ 'ok' => false, 'error' => 'method' ];
		}
		$font = null;
		if ( 'generate' === $method ) {
			$font = (string) ( $in['font'] ?? '' );
			if ( ! \in_array( $font, self::FONTS, true ) ) {
				return [ 'ok' => false, 'error' => 'font' ];
			}
		}
		$image = SignatureImage::decode( (string) ( $in['image'] ?? '' ) );
		if ( null === $image ) {
			return [ 'ok' => false, 'error' => 'image' ];
		}
		$repo    = new SignatureRepository();
		$session = SignatureCheck::session_key();
		if ( '' === $session ) {
			return [ 'ok' => false, 'error' => 'session', 'message' => \__( 'Your checkout session could not be found. Please reload the page and try again.', 'anchor-schema' ) ];
		}
		if ( $repo->count_recent_for_session( $session, HOUR_IN_SECONDS ) >= self::LIMIT ) {
			return [ 'ok' => false, 'error' => 'rate_limited' ];
		}
		$version = ( new VersionRepository() )->current_for( $aid );
		if ( ! $version ) {
			return [ 'ok' => false, 'error' => 'agreement' ];
		}
		if ( \absint( $in['version_id'] ?? 0 ) !== $version['id'] ) {
			return [ 'ok' => false, 'error' => 'version', 'message' => \__( 'This document changed while you were reading it. Please reload the page and read it again before signing.', 'anchor-schema' ) ];
		}
		$user = \wp_get_current_user();
		$id   = $repo->insert( [
			'version_id'   => $version['id'],
			'agreement_id' => $aid,
			'product_id'   => $required[ $aid ],
			'user_id'      => $user->ID ?: null,
			'signer_name'  => $name,
			'signer_email' => $user->ID ? (string) $user->user_email : (string) ( \WC()->customer ? \WC()->customer->get_billing_email() : '' ),
			'method'       => $method,
			'font'         => $font,
			'image'        => $image,
			'ip'           => \WC_Geolocation::get_ip_address(),
			'user_agent'   => (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ),
			'session_key'  => $session,
		] );
		SignatureCheck::remember( $aid, $id );

		$remaining = \count( ( new SignatureCheck( $repo ) )->unsigned( $required, SignatureCheck::awaiting_order_id() ) );
		return [ 'ok' => true, 'signature_id' => $id, 'remaining' => $remaining ];
	}
}
