<?php
/**
 * Verifies that the private store is not reachable over plain HTTP.
 *
 * The store must only be served through the authenticated stream endpoint, but whether a static request is
 * refused depends on the host's web-server config and filesystem users, which the code cannot see or guarantee
 * (AFM-03). So instead of assuming, it asks: drop a random canary file into each store directory, request it
 * anonymously from the server, and report "exposed" only if the canary's exact bytes come back.
 *
 * The check covers the origin as seen from this server. A CDN route in front of the site can differ and must be
 * checked separately.
 *
 * Ported unchanged in behaviour from Anchor_FM_Storage_Guard (Anchor Private File Manager 2.17.0).
 *
 * @package Anchor\Library
 */

namespace Anchor\Library;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

class StorageGuard {

	public const STATUS_PROTECTED = 'protected';
	public const STATUS_EXPOSED   = 'exposed';
	public const STATUS_UNKNOWN   = 'unknown';

	/**
	 * Remove group/other permission bits from each existing directory PHP owns. Only when PHP is the owner: chmod
	 * on someone else's directory fails anyway, and narrowing it could lock PHP out of its own store.
	 *
	 * @param string[] $dirs Store directories.
	 */
	public static function tighten( array $dirs ): void {
		// Never from WP-CLI: a CLI run may be a different OS user (a deploy user owning the tree, PHP-FPM reaching
		// it through the group), and narrowing to 0700 there would lock the web PHP out of its store.
		if ( \PHP_SAPI === 'cli' || ! \function_exists( 'posix_geteuid' ) ) {
			return;
		}
		self::narrow_owned( $dirs, \posix_geteuid() );
	}

	/**
	 * chmod 0700 each directory owned by $uid that grants group/other access.
	 *
	 * @param string[] $dirs Store directories.
	 * @param int      $uid  Owner whose directories may be narrowed.
	 */
	public static function narrow_owned( array $dirs, int $uid ): void {
		foreach ( $dirs as $dir ) {
			if ( ! \is_dir( $dir ) ) {
				continue;
			}
			if ( @\fileowner( $dir ) !== $uid ) {
				continue;
			}
			$perms = @\fileperms( $dir );
			if ( false !== $perms && ( $perms & 0077 ) ) {
				@\chmod( $dir, 0700 );
				\clearstatcache( true, $dir );
			}
		}
	}

	/** Public URL for a filesystem path under the uploads dir or the site root; '' if neither. */
	public static function url_for_path( string $path ): string {
		$path    = \wp_normalize_path( $path );
		$uploads = \wp_upload_dir( null, false );
		$basedir = \wp_normalize_path( \untrailingslashit( $uploads['basedir'] ) );
		if ( '' !== $basedir && 0 === \strpos( $path . '/', $basedir . '/' ) ) {
			return \untrailingslashit( $uploads['baseurl'] ) . \substr( $path, \strlen( $basedir ) );
		}
		$root = \wp_normalize_path( \untrailingslashit( ABSPATH ) );
		if ( 0 === \strpos( $path . '/', $root . '/' ) ) {
			return \untrailingslashit( \site_url() ) . \substr( $path, \strlen( $root ) );
		}
		return '';
	}

	/**
	 * Probe each directory.
	 *
	 * @param string[] $dirs Store directories.
	 * @return array{status:string,checked_at:string,details:array<int,array<string,string>>}
	 */
	public static function check( array $dirs ): array {
		$details = [];
		foreach ( \array_unique( $dirs ) as $dir ) {
			if ( ! \is_dir( $dir ) ) {
				continue;
			}
			$details[] = self::probe( $dir );
		}

		$results = \array_column( $details, 'result' );
		if ( \in_array( self::STATUS_EXPOSED, $results, true ) ) {
			$status = self::STATUS_EXPOSED;
		} elseif ( ! $results || \in_array( self::STATUS_UNKNOWN, $results, true ) ) {
			$status = self::STATUS_UNKNOWN;
		} else {
			$status = self::STATUS_PROTECTED;
		}
		return [ 'status' => $status, 'checked_at' => \current_time( 'mysql' ), 'details' => $details ];
	}

	private static function probe( string $dir ): array {
		$url_base = self::url_for_path( $dir );
		if ( '' === $url_base ) {
			// Not under the web root or uploads: there is no URL to serve it from.
			return [ 'dir' => $dir, 'url' => '', 'result' => self::STATUS_PROTECTED, 'note' => 'Outside the web root.' ];
		}

		$token = \wp_generate_password( 32, false, false );
		$name  = 'afm-canary-' . \strtolower( \wp_generate_password( 12, false, false ) ) . '.txt';
		$file  = \trailingslashit( $dir ) . $name;
		if ( false === @\file_put_contents( $file, $token ) ) {
			return [ 'dir' => $dir, 'url' => '', 'result' => self::STATUS_UNKNOWN, 'note' => 'Could not write a test file.' ];
		}
		// If the request dies mid-probe (fatal, timeout), still remove the canary.
		\register_shutdown_function(
			static function () use ( $file ) {
				if ( \file_exists( $file ) ) {
					@\unlink( $file );
				}
			}
		);
		// Make the canary as readable as a real stored file could be, so a directory-level block is what's being tested.
		@\chmod( $file, 0644 );

		$url      = $url_base . '/' . $name;
		$response = \wp_remote_get(
			$url,
			[
				'timeout'     => 10,
				'redirection' => 5,
				'cookies'     => [],
				'sslverify'   => \apply_filters( 'https_local_ssl_verify', false ),
				'headers'     => [ 'Cache-Control' => 'no-cache' ],
			]
		);
		@\unlink( $file );

		if ( \is_wp_error( $response ) ) {
			return [ 'dir' => $dir, 'url' => $url, 'result' => self::STATUS_UNKNOWN,
				'note' => 'The server could not request its own URL: ' . $response->get_error_message() ];
		}
		$code = (int) \wp_remote_retrieve_response_code( $response );
		$body = (string) \wp_remote_retrieve_body( $response );
		if ( 200 === $code && false !== \strpos( $body, $token ) ) {
			return [ 'dir' => $dir, 'url' => $url, 'result' => self::STATUS_EXPOSED,
				'note' => 'An anonymous request downloaded the test file.' ];
		}
		// Only an explicit refusal proves protection. A 401 (staging basic auth), 5xx, 429 or a 200 challenge page
		// says nothing about whether the file would be served to a visitor who got past it.
		if ( \in_array( $code, [ 403, 404, 410 ], true ) ) {
			return [ 'dir' => $dir, 'url' => $url, 'result' => self::STATUS_PROTECTED,
				'note' => \sprintf( 'Anonymous request refused (HTTP %d).', $code ) ];
		}
		return [ 'dir' => $dir, 'url' => $url, 'result' => self::STATUS_UNKNOWN,
			'note' => \sprintf( 'Inconclusive response (HTTP %d) — test by hand.', $code ) ];
	}
}
