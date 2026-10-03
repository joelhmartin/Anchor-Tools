<?php
declare(strict_types=1);

namespace Anchor\Agreements\Frontend;

use Anchor\Agreements\Database\SignatureRepository;
use Anchor\Agreements\Database\VersionRepository;
use Anchor\Agreements\Module;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * /signed-agreement/{token}/ - reachable by unguessable UUID only (same model as
 * anchor-courses CertificatePage). Found and not-found responses carry identical headers.
 */
final class SignedCopyPage {

	public const QUERY_VAR       = 'anchor_agreement_token';
	public const REWRITE_OPTION  = 'anchor_agreements_rewrite_version';
	public const REWRITE_VERSION = '1';

	public function __construct() {
		\add_action( 'init', [ $this, 'add_rewrite' ] );
		\add_filter( 'query_vars', fn( array $v ) => array_merge( $v, [ self::QUERY_VAR ] ) );
		\add_action( 'init', [ $this, 'flush_if_needed' ], 99 );
		\add_action( 'template_redirect', [ $this, 'maybe_render' ], 1 );
	}

	public function add_rewrite(): void {
		\add_rewrite_rule( '^signed-agreement/([0-9a-f-]{36})/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
	}

	public function flush_if_needed(): void {
		if ( \get_option( self::REWRITE_OPTION ) !== self::REWRITE_VERSION ) {
			\flush_rewrite_rules( false );
			\update_option( self::REWRITE_OPTION, self::REWRITE_VERSION, false );
		}
	}

	public static function url( string $token ): string {
		return \home_url( '/signed-agreement/' . rawurlencode( $token ) . '/' );
	}

	public static function headers(): array {
		return [ 'X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'no-store, private, max-age=0', 'Referrer-Policy' => 'no-referrer' ];
	}

	public function maybe_render(): void {
		$token = (string) \get_query_var( self::QUERY_VAR );
		if ( '' === $token ) {
			return;
		}
		\nocache_headers();
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // WP Rocket.
		}
		foreach ( self::headers() as $k => $v ) {
			header( $k . ': ' . $v );
		}
		$sig = ( new SignatureRepository() )->find_by_token( $token );
		if ( ! $sig || null === $sig['order_id'] ) {
			$sig = null;
			\status_header( 404 );
		}
		echo self::render_html( $sig ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in template.
		exit;
	}

	public static function render_html( ?array $sig ): string {
		$version = $sig ? ( new VersionRepository() )->get( (int) $sig['version_id'] ) : null;
		$css     = Module::url( 'assets/signed-copy.css' );
		ob_start();
		include Module::dir() . '/templates/signed-copy.php';
		return (string) ob_get_clean();
	}
}
