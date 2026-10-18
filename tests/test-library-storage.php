<?php
/**
 * Library storage: directory resolution, path mapping, Range planning/streaming and the exposure guard.
 *
 * @package Anchor\Library\Tests
 * @group library
 */

use Anchor\Library\Storage;
use Anchor\Library\StorageGuard;

class Anchor_Library_Storage_Test extends Anchor_Library_TestCase {

	private string $uploads = '';

	public function set_up() {
		parent::set_up();
		$this->uploads = sys_get_temp_dir() . '/anchor-library-test-' . getmypid() . '-' . wp_rand();
		mkdir( $this->uploads, 0755, true );
		add_filter( 'upload_dir', [ $this, 'uploads_filter' ] );
	}

	public function tear_down() {
		remove_filter( 'upload_dir', [ $this, 'uploads_filter' ] );
		remove_all_filters( 'anchor_fm_storage_dir' );
		remove_all_filters( 'pre_http_request' );
		exec( 'rm -rf ' . escapeshellarg( $this->uploads ) );
		parent::tear_down();
	}

	public function uploads_filter( $dirs ) {
		$dirs['basedir'] = $this->uploads;
		$dirs['baseurl'] = 'https://example.test/wp-content/uploads';
		$dirs['path']    = $this->uploads;
		$dirs['url']     = $dirs['baseurl'];
		$dirs['error']   = false;
		return $dirs;
	}

	public function test_storage_dir_constant_and_filter_precedence() {
		// Default: the dot-prefixed directory under uploads.
		$this->assertSame( $this->uploads . '/.anchor-private-files', Storage::resolve( null ) );
		$this->assertSame( $this->uploads . '/.anchor-private-files', Storage::resolve( '' ) );
		// The constant beats the default; trailing slashes are trimmed.
		$this->assertSame( '/srv/store', Storage::resolve( '/srv/store/' ) );
		// The filter has the last word, over both.
		add_filter( 'anchor_fm_storage_dir', static fn( $d ) => $d . '-filtered' );
		$this->assertSame( '/srv/store-filtered', Storage::resolve( '/srv/store' ) );
		$this->assertSame( $this->uploads . '/.anchor-private-files-filtered', Storage::base_dir() );
	}

	public function test_existing_legacy_store_is_kept_until_the_new_one_exists() {
		mkdir( $this->uploads . '/anchor-private-files' );
		$this->assertSame( $this->uploads . '/anchor-private-files', Storage::base_dir(), 'never relocate a live store' );
		mkdir( $this->uploads . '/.anchor-private-files' );
		$this->assertSame( $this->uploads . '/.anchor-private-files', Storage::base_dir() );
		$this->assertSame( [ $this->uploads . '/.anchor-private-files', $this->uploads . '/anchor-private-files' ], Storage::dirs() );
	}

	public function test_storage_dir_is_0700_and_dot_prefixed_by_default() {
		Storage::ensure();
		$base = Storage::base_dir();
		$this->assertStringStartsWith( '.', basename( $base ) );
		$this->assertFileExists( $base . '/.htaccess' );
		$this->assertFileExists( $base . '/index.php' );

		// tighten() is a deliberate no-op under CLI (a different OS user may own the tree); the narrowing it
		// applies on web requests is narrow_owned(), exercised the way the plugin's own suite does.
		chmod( $base, 0755 );
		StorageGuard::tighten( [ $base ] );
		clearstatcache();
		$this->assertSame( '0755', substr( sprintf( '%o', fileperms( $base ) ), -4 ) );
		StorageGuard::narrow_owned( [ $base ], posix_geteuid() + 1 );
		clearstatcache();
		$this->assertSame( '0755', substr( sprintf( '%o', fileperms( $base ) ), -4 ), 'never narrows a directory another user owns' );
		StorageGuard::narrow_owned( [ $base ], posix_geteuid() );
		clearstatcache();
		$this->assertSame( '0700', substr( sprintf( '%o', fileperms( $base ) ), -4 ) );
	}

	public function test_path_for_and_folder_dirs() {
		$dir = Storage::ensure_folder_dir( 7 );
		$this->assertSame( Storage::base_dir() . '/7', $dir );
		$this->assertFileExists( $dir . '/.htaccess' );
		$this->assertSame( Storage::base_dir() . '/7/abc.pdf', Storage::path_for( (object) [ 'folder_id' => '7', 'stored_name' => 'abc.pdf' ] ) );
	}

	public function test_plan_covers_full_partial_suffix_and_unsatisfiable() {
		$full = Storage::plan( '', 5000 );
		$this->assertSame( [ 200, 0, 4999, 5000, true ], [ $full['status'], $full['start'], $full['end'], $full['length'], $full['opening'] ] );
		$this->assertSame( '5000', $full['headers']['Content-Length'] );

		$part = Storage::plan( 'bytes=0-99', 3145728 );
		$this->assertSame( [ 206, 0, 99, 100, true ], [ $part['status'], $part['start'], $part['end'], $part['length'], $part['opening'] ] );
		$this->assertSame( 'bytes 0-99/3145728', $part['headers']['Content-Range'] );
		$this->assertSame( '100', $part['headers']['Content-Length'] );

		$later = Storage::plan( 'bytes=1000-', 5000 );
		$this->assertSame( [ 206, 1000, 4999, false ], [ $later['status'], $later['start'], $later['end'], $later['opening'] ] );

		$suffix = Storage::plan( 'bytes=-500', 5000 );
		$this->assertSame( [ 4500, 4999 ], [ $suffix['start'], $suffix['end'] ] );

		$bad = Storage::plan( 'bytes=5000-', 5000 );
		$this->assertSame( 416, $bad['status'] );
		$this->assertSame( 'bytes */5000', $bad['headers']['Content-Range'] );
		$this->assertSame( 200, Storage::plan( 'garbage', 5000 )['status'], 'unparseable Range falls back to the whole file' );
	}

	public function test_write_range_streams_exactly_the_window_across_chunk_boundaries() {
		$path = $this->uploads . '/blob.bin';
		$data = random_bytes( 700000 ); // > 2 chunks of 256 KB.
		file_put_contents( $path, $data );

		ob_start();
		Storage::write_range( $path, 262140, 524300, false );
		$out = ob_get_clean();
		$this->assertSame( substr( $data, 262140, 524300 - 262140 + 1 ), $out );

		ob_start();
		Storage::write_range( $path, 0, 699999, false );
		$this->assertSame( $data, ob_get_clean() );
	}

	public function test_describe_unreadable_names_the_path_and_store() {
		$text = Storage::describe_unreadable( $this->uploads . '/nope' );
		$this->assertStringContainsString( 'exists=0', $text );
		$this->assertStringContainsString( $this->uploads . '/nope', $text );
	}

	// --- StorageGuard (AFM-03) -------------------------------------------------------------------------------

	private function store(): string {
		$store = $this->uploads . '/.anchor-private-files';
		mkdir( $store, 0755, true );
		return $store;
	}

	/** Stand in for the web server: map each URL to a status/body, or serve the file the URL points at. */
	private function web( callable $respond ): void {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $respond ) {
				return $respond( $url );
			},
			10,
			3
		);
	}

	private function served(): callable {
		return function ( $url ) {
			$path = $this->uploads . substr( $url, strlen( 'https://example.test/wp-content/uploads' ) );
			return [ 'headers' => [], 'body' => (string) @file_get_contents( $path ), 'response' => [ 'code' => 200, 'message' => 'OK' ], 'cookies' => [], 'filename' => null ];
		};
	}

	private function status( int $code, string $body = '' ): callable {
		return static fn() => [ 'headers' => [], 'body' => $body, 'response' => [ 'code' => $code, 'message' => '' ], 'cookies' => [], 'filename' => null ];
	}

	public function test_guard_url_for_path() {
		$store = $this->store();
		$this->assertSame( 'https://example.test/wp-content/uploads/.anchor-private-files/x.txt', StorageGuard::url_for_path( $store . '/x.txt' ) );
		$this->assertSame( '', StorageGuard::url_for_path( '/var/private/store' ) );
	}

	public function test_guard_served_canary_is_exposed_and_removed() {
		$store = $this->store();
		$this->web( $this->served() );
		$this->assertSame( StorageGuard::STATUS_EXPOSED, StorageGuard::check( [ $store ] )['status'] );
		$this->assertSame( [], glob( $store . '/afm-canary-*' ) );
	}

	public function test_guard_status_mapping() {
		$store = $this->store();
		$cases = [
			'403 protected' => [ $this->status( 403, 'Forbidden' ), 'protected' ],
			'404 protected' => [ $this->status( 404 ), 'protected' ],
			'200 login page without canary' => [ $this->status( 200, '<html>Log in</html>' ), 'unknown' ],
			'401 staging auth' => [ $this->status( 401 ), 'unknown' ],
			'502' => [ $this->status( 502 ), 'unknown' ],
			'loopback failure' => [ static fn() => new WP_Error( 'http_request_failed', 'loopback blocked' ), 'unknown' ],
		];
		foreach ( $cases as $label => [ $respond, $expected ] ) {
			remove_all_filters( 'pre_http_request' );
			$this->web( $respond );
			$this->assertSame( $expected, StorageGuard::check( [ $store ] )['status'], $label );
		}
	}

	public function test_guard_one_exposed_directory_exposes_the_store_and_no_directories_is_unknown() {
		$store  = $this->store();
		$legacy = $this->uploads . '/anchor-private-files';
		mkdir( $legacy );
		$served = $this->served();
		$this->web( static fn( $url ) => ( false !== strpos( $url, '/anchor-private-files/' ) && false === strpos( $url, '/.anchor' ) ) ? $served( $url ) : [ 'headers' => [], 'body' => '', 'response' => [ 'code' => 403, 'message' => '' ], 'cookies' => [], 'filename' => null ] );
		$this->assertSame( 'exposed', StorageGuard::check( [ $store, $legacy ] )['status'] );
		$this->assertSame( 'unknown', StorageGuard::check( [ $this->uploads . '/missing' ] )['status'] );
	}
}
