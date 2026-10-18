<?php
/**
 * The private file store: where it lives on disk, how a stored file maps to a path, and how bytes are streamed
 * out (Range-aware, in bounded chunks).
 *
 * SECURITY: the store must not be reachable over HTTP. Per-folder .htaccess files are inert on Nginx (which
 * Kinsta runs), so the default relies on two guards that do work there: the leading dot in the directory name
 * (Nginx denies any URI containing "/."), and mode 0700 on directories PHP owns. Neither is assumed:
 * StorageGuard checks over HTTP daily. Moving the store outside the web root is NOT safe on every host, because
 * managed platforms confine PHP-FPM with open_basedir. See PRIVATE-STORAGE.md next to this module.
 *
 * Override with the ANCHOR_FM_STORAGE_DIR constant in wp-config.php, or the anchor_fm_storage_dir filter.
 *
 * Ported from Anchor Private File Manager 2.17.0 (storage_base, default_storage_base, ensure_upload_storage,
 * ensure_folder_dir, get_file_path_on_disk, stream_file_range and the range half of ajax_stream).
 *
 * @package Anchor\Library
 */

namespace Anchor\Library;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

class Storage {

	/**
	 * Store directory names, relative to the uploads basedir. The leading dot is load-bearing, not cosmetic: it is
	 * what makes Nginx refuse to serve the tree.
	 */
	public const DIRNAME        = '.anchor-private-files';
	public const LEGACY_DIRNAME = 'anchor-private-files';

	/** Absolute filesystem path of the private file store (constant, then default, then the filter). */
	public static function base_dir(): string {
		$constant = ( \defined( 'ANCHOR_FM_STORAGE_DIR' ) && ANCHOR_FM_STORAGE_DIR ) ? (string) ANCHOR_FM_STORAGE_DIR : null;
		return self::resolve( $constant );
	}

	/**
	 * The resolution order, with the constant passed in so it can be tested (a constant cannot be undefined).
	 *
	 * @param string|null $constant Value of ANCHOR_FM_STORAGE_DIR when it is defined and non-empty.
	 */
	public static function resolve( ?string $constant ): string {
		$base = ( null !== $constant && '' !== $constant ) ? \rtrim( $constant, '/\\' ) : self::default_dir();
		return (string) \apply_filters( 'anchor_fm_storage_dir', $base );
	}

	/**
	 * Where a site with no explicit configuration keeps its store.
	 *
	 * New installs get the dot-prefixed directory. An existing install that already has files in the pre-2.13.3
	 * location keeps using it: changing the default must never relocate a live store out from under itself, because
	 * paths are resolved at read time and every file would 404. Migrating is a deliberate act (move the tree, then
	 * chmod it), and once the new directory exists this returns it.
	 */
	private static function default_dir(): string {
		$upload_dir = \wp_upload_dir();
		$safe       = \trailingslashit( $upload_dir['basedir'] ) . self::DIRNAME;
		$legacy     = \trailingslashit( $upload_dir['basedir'] ) . self::LEGACY_DIRNAME;

		if ( ! \is_dir( $safe ) && \is_dir( $legacy ) ) {
			return $legacy;
		}
		return $safe;
	}

	/**
	 * Every store directory on disk that may hold files: the active one, plus a leftover legacy one.
	 *
	 * @return string[]
	 */
	public static function dirs(): array {
		$dirs    = [ self::base_dir() ];
		$uploads = \wp_upload_dir( null, false );
		$legacy  = \trailingslashit( $uploads['basedir'] ) . self::LEGACY_DIRNAME;
		if ( \is_dir( $legacy ) ) {
			$dirs[] = $legacy;
		}
		return \array_values( \array_unique( $dirs ) );
	}

	/** Create the store with its deny files, and narrow directories PHP owns. Safe to call repeatedly. */
	public static function ensure(): void {
		$base = self::base_dir();
		if ( ! \file_exists( $base ) ) {
			\wp_mkdir_p( $base );
		}
		// Nginx ignores the .htaccess below, so on those hosts the directory mode is a guard that can hold: when the
		// web server runs as a different user than PHP-FPM, 0700 leaves it unable to traverse the tree. Applied to
		// existing stores too, including a leftover legacy directory, since an older, wider mode is exactly the
		// exposure (AFM-03). Whether it works is verified over HTTP by StorageGuard::check().
		StorageGuard::tighten( self::dirs() );

		$htaccess = $base . '/.htaccess';
		if ( ! \file_exists( $htaccess ) ) {
			@\file_put_contents( $htaccess, "Deny from all\n" );
		}

		$index = $base . '/index.php';
		if ( ! \file_exists( $index ) ) {
			@\file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
	}

	/** The on-disk directory for a folder, created with its deny files if missing. */
	public static function ensure_folder_dir( int $folder_id ): string {
		self::ensure();
		$dir = \trailingslashit( self::base_dir() ) . $folder_id;
		if ( ! \file_exists( $dir ) ) {
			\wp_mkdir_p( $dir );
			if ( ! \file_exists( $dir . '/.htaccess' ) ) {
				@\file_put_contents( $dir . '/.htaccess', "Deny from all\n" );
			}
			if ( ! \file_exists( $dir . '/index.php' ) ) {
				@\file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
			}
		}
		return $dir;
	}

	/**
	 * Absolute path of a stored file.
	 *
	 * @param object $file Row of {prefix}anchor_fm_files (folder_id, stored_name).
	 */
	public static function path_for( object $file ): string {
		return \trailingslashit( self::base_dir() ) . (int) $file->folder_id . '/' . $file->stored_name;
	}

	/**
	 * Why every refusal to stream is worth logging: the endpoint answers with a bare status code and no body, so
	 * from the browser a store the server cannot read looks exactly like a deleted file, an expired nonce and a
	 * permission denial. On 2026-08-21 tmjtherapycentre.com moved its store outside PHP-FPM's open_basedir;
	 * file_exists() then returned false for all 768 files, every download 404'd for six days, and nothing said so.
	 */
	public static function log_refusal( int $file_id, string $reason ): void {
		\error_log( \sprintf( 'Anchor FM: refused to stream file %d — %s', $file_id, $reason ) );
	}

	/**
	 * Why the bytes for a row could not be served, for the log.
	 *
	 * Every probe is silenced deliberately: for a store outside open_basedir each unsuppressed stat emits its own
	 * warning, which floods the log and, with display_errors on, prepends text to the JSON body being sent.
	 */
	public static function describe_unreadable( string $path ): string {
		$base = self::base_dir();
		return \sprintf(
			'bytes unreachable at "%s" (exists=%d readable=%d); store "%s" (is_dir=%d readable=%d); open_basedir="%s"',
			$path,
			(int) @\file_exists( $path ),
			(int) @\is_readable( $path ),
			$base,
			(int) @\is_dir( $base ),
			(int) @\is_readable( $base ),
			(string) \ini_get( 'open_basedir' )
		);
	}

	/**
	 * Decide how to answer a request for a file of $size bytes, without sending anything.
	 *
	 * 'opening' is true for a request that starts at byte 0 (or has no Range): one inline playthrough issues dozens
	 * of range requests, so callers log only the opening one for previews. Attachments must NOT be deduplicated,
	 * or `Range: bytes=1-` could pull a whole document with zero download rows (an audit-trail hole).
	 *
	 * @param string $range_header Raw Range header value ('' when absent).
	 * @param int    $size         File size in bytes.
	 * @return array{status:int,start:int,end:int,length:int,headers:array<string,string>,opening:bool}
	 */
	public static function plan( string $range_header, int $size ): array {
		$range = \Anchor_FM_Range::parse( $range_header, $size );

		if ( \is_array( $range ) && empty( $range['satisfiable'] ) ) {
			return [ 'status' => 416, 'start' => 0, 'end' => -1, 'length' => 0,
				'headers' => [ 'Content-Range' => 'bytes */' . $size ], 'opening' => false ];
		}
		if ( null === $range ) {
			return [ 'status' => 200, 'start' => 0, 'end' => $size - 1, 'length' => $size,
				'headers' => [ 'Content-Length' => (string) $size ], 'opening' => true ];
		}
		$start = (int) $range['start'];
		$end   = (int) $range['end'];
		return [ 'status' => 206, 'start' => $start, 'end' => $end, 'length' => ( $end - $start ) + 1,
			'headers' => [ 'Content-Range' => 'bytes ' . $start . '-' . $end . '/' . $size, 'Content-Length' => (string) ( ( $end - $start ) + 1 ) ],
			'opening' => 0 === $start ];
	}

	/**
	 * Send headers and body for a stored file, then exit. The caller has already checked permission and that the
	 * file is readable, and logged activity.
	 *
	 * @param string $path        Absolute path on disk.
	 * @param string $mime        Content-Type.
	 * @param string $disposition 'inline' or 'attachment' (anything else is an attachment).
	 * @param string $name        Download file name.
	 */
	public static function stream( string $path, string $mime, string $disposition, string $name ): void {
		$disp = 'inline' === $disposition ? 'inline' : 'attachment';
		$size = (int) \filesize( $path );
		$plan = self::plan( isset( $_SERVER['HTTP_RANGE'] ) ? (string) $_SERVER['HTTP_RANGE'] : '', $size ); // phpcs:ignore WordPress.Security

		\nocache_headers();
		\header( 'Accept-Ranges: bytes' );
		\header( 'Content-Type: ' . $mime );
		\header( 'Content-Disposition: ' . $disp . '; filename="' . \sanitize_file_name( $name ) . '"' );
		\header( 'X-Content-Type-Options: nosniff' );
		foreach ( $plan['headers'] as $header => $value ) {
			\header( $header . ': ' . $value );
		}
		if ( 200 !== $plan['status'] ) {
			\status_header( $plan['status'] );
		}
		if ( $plan['length'] > 0 ) {
			self::write_range( $path, $plan['start'], $plan['end'] );
		}
		exit;
	}

	/**
	 * Write an inclusive byte window of a file to the output stream.
	 *
	 * readfile() would pull the whole file into the output buffer, which a multi-hundred-megabyte video will not
	 * survive. This reads in bounded chunks and flushes as it goes.
	 *
	 * @param bool $discard_buffers Drop WordPress's output buffering first, or the body accumulates in memory
	 *                              instead of streaming. Only tests that capture output pass false.
	 */
	public static function write_range( string $path, int $start, int $end, bool $discard_buffers = true ): void {
		if ( $discard_buffers ) {
			while ( \ob_get_level() > 0 ) {
				@\ob_end_clean();
			}
		}
		// Finite, not unlimited: a stalled client must not pin a PHP worker forever.
		@\set_time_limit( 300 );

		$requested = ( $end - $start ) + 1;

		$fh = @\fopen( $path, 'rb' );
		if ( ! $fh ) {
			// Unambiguous failure, never caused by a client going away. The caller already sent Content-Length for
			// $requested bytes; the client now gets a truncated body with no explanation unless this is logged.
			\error_log( \sprintf( 'Anchor FM: stream_file_range() could not open "%s" (requested %d-%d)', $path, $start, $end ) );
			return;
		}
		if ( $start > 0 ) {
			\fseek( $fh, $start );
		}

		$remaining = $requested;
		$written   = 0;
		$chunk     = 262144; // 256KB.

		while ( $remaining > 0 && ! \feof( $fh ) ) {
			$read = ( $remaining > $chunk ) ? $chunk : $remaining;
			$buf  = \fread( $fh, $read );
			if ( false === $buf || '' === $buf ) {
				break;
			}
			echo $buf; // phpcs:ignore WordPress.Security.EscapeOutput -- raw file bytes.
			$written   += \strlen( $buf );
			$remaining -= \strlen( $buf );
			\flush();
		}
		\fclose( $fh );

		// A short read is either a truncated file on disk, or (far more commonly with video) the client aborting
		// mid-stream: every seek and player teardown kills an in-flight range request. Both leave $written <
		// $requested, so only log when the connection is still alive, or normal seeking floods the error log.
		if ( $written < $requested && \CONNECTION_NORMAL === \connection_status() ) {
			\error_log( \sprintf( 'Anchor FM: stream_file_range() wrote %d of %d requested bytes for "%s" (%d-%d) with connection still open', $written, $requested, $path, $start, $end ) );
		}
	}
}
