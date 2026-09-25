<?php
declare(strict_types=1);

namespace Anchor\Courses\Frontend;

use Anchor\Courses\Domain\Certificate;
use Anchor\Courses\Services\CertificateService;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * /certificate/{token}/ - the HTML certificate and its public verification
 * (brief 13, design spec 1; task-30-brief.md).
 *
 * Public by token on purpose: "verify this certificate" has to work for a
 * state board with nothing but the certificate in front of them, and the
 * token is a random UUID v4 (`Support\Uuid::v4()`), not a guessable sequence
 * - `Domain\Certificate::url()` is the only mint site, and the id-based
 * `certificate_number` is never itself a lookup key here (progress.md's
 * ledger describes a "number + token" verification; no such
 * `CertificateService::verify()` method exists in this codebase - only
 * `get_by_token()`, keyed on the token alone - and `Certificate::url()`
 * already commits to a single-segment `/certificate/{token}/` shape. This
 * class follows the code and the concrete task-30-brief.md interfaces,
 * both of which agree with that shape; see task-30-report.md).
 *
 * The page is noindexed and never cached, and shows only what a printed
 * certificate shows - never the learner's email or user id (design spec 1,
 * progress.md ruling).
 */
final class CertificatePage {

	public const QUERY_VAR       = 'anchor_certificate';
	public const REWRITE_OPTION  = 'anchor_courses_rewrite_version';
	public const REWRITE_VERSION = '1';

	public function __construct( private CertificateService $certificates ) {
		\add_action( 'init', [ $this, 'add_rewrite' ] );
		\add_action( 'init', [ $this, 'flush_if_needed' ], 20 );
		\add_filter( 'query_vars', [ $this, 'register_query_var' ] );
		\add_action( 'template_redirect', [ $this, 'maybe_render' ] );
	}

	public function add_rewrite(): void {
		\add_rewrite_rule(
			'^certificate/([^/]+)/?$',
			'index.php?' . self::QUERY_VAR . '=$matches[1]',
			'top'
		);
	}

	public function register_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Self-healing flush.
	 *
	 * Anchor Tools modules have no activation hook (see Database\Migrations),
	 * so the rule is registered on every load and flushed once per version
	 * bump - the same pattern anchor-events-manager's room endpoint
	 * (`maybe_flush_rewrites()`) and anchor-locations use.
	 */
	public function flush_if_needed(): void {
		if ( \get_option( self::REWRITE_OPTION ) === self::REWRITE_VERSION ) {
			return;
		}
		\flush_rewrite_rules( false );
		\update_option( self::REWRITE_OPTION, self::REWRITE_VERSION, false );
	}

	/**
	 * The request's header/found DECISION, as pure data - no header(),
	 * nocache_headers() or wp_die() calls - so it is unit-testable without a
	 * real HTTP response, the same split anchor-events-manager's
	 * `room_header_list()` uses for its own public, noindexed page.
	 * `maybe_render()` is the thin wrapper that applies it.
	 *
	 * Both branches (found and unknown-token) carry identical headers on
	 * purpose: a token-guessing probe must never be able to tell a real
	 * certificate from a missing one by response caching/indexing behaviour
	 * alone.
	 *
	 * @return array{found:bool,nocache:bool,headers:array<string,string>}
	 */
	public function header_list( ?Certificate $certificate ): array {
		return [
			'found'   => $certificate instanceof Certificate,
			'nocache' => true,
			'headers' => [
				'Cache-Control' => 'private, no-store',
				'X-Robots-Tag'  => 'noindex, nofollow',
			],
		];
	}

	public function maybe_render(): void {
		$token = (string) \get_query_var( self::QUERY_VAR );
		if ( '' === $token ) {
			return;
		}

		$certificate = $this->certificates->get_by_token( $token );
		$decision    = $this->header_list( $certificate );

		if ( $decision['nocache'] ) {
			\nocache_headers();
		}
		foreach ( $decision['headers'] as $name => $value ) {
			\header( $name . ': ' . $value, true );
		}

		if ( ! $decision['found'] ) {
			global $wp_query;
			if ( $wp_query instanceof \WP_Query ) {
				$wp_query->set_404();
			}
			\wp_die(
				\esc_html__( 'This certificate could not be found.', 'anchor-schema' ),
				\esc_html__( 'Certificate not found', 'anchor-schema' ),
				[ 'response' => 404 ]
			);
		}

		echo $this->certificates->render( $certificate ); // phpcs:ignore WordPress.Security.EscapeOutput -- the template escapes every value.
		exit;
	}
}
