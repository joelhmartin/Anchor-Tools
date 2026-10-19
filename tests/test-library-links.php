<?php
/**
 * Library signed file links: token format, /files/{id}/link and /files/{id}/content (token-only auth, Range).
 *
 * @package Anchor\Library\Tests
 * @group library
 */

use Anchor\Library\Permissions;
use Anchor\Library\Rest\LinkToken;
use Anchor\Library\Rest\Routes;
use Anchor\Library\Schema;

class Anchor_Library_Links_Test extends Anchor_Library_TestCase {

	private const NOW = 1800000000;

	private int $admin;
	private int $member;
	private int $folder;
	private string $store;

	public function set_up() {
		parent::set_up();
		Permissions::reset_memo();
		$this->admin  = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->member = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( 0 );

		$this->store = trailingslashit( get_temp_dir() ) . 'afm-links-' . wp_generate_password( 8, false );
		wp_mkdir_p( $this->store );
		add_filter( 'anchor_fm_storage_dir', fn() => $this->store );
		add_filter( 'anchor_library_stream_discard_buffers', '__return_false' );

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		add_action( 'rest_api_init', [ Routes::class, 'register_routes' ] );
		do_action( 'rest_api_init', $wp_rest_server );

		$this->folder = $this->insert_folder( 'Docs' );
		$this->grant_folder( $this->folder, 'role', 'subscriber' );
	}

	public function tear_down() {
		global $wp_rest_server, $wpdb;
		$wp_rest_server = null;
		remove_action( 'rest_api_init', [ Routes::class, 'register_routes' ] );
		unset( $_SERVER['HTTP_RANGE'] );
		Permissions::reset_memo();
		foreach ( glob( $this->store . '/*/*' ) ?: [] as $f ) {
			unlink( $f );
		}
		foreach ( glob( $this->store . '/*' ) ?: [] as $d ) {
			rmdir( $d );
		}
		rmdir( $this->store );
		parent::tear_down();
	}

	// ---- fixtures -------------------------------------------------------------------------------------------

	private function insert_folder( string $name ): int {
		global $wpdb;
		$wpdb->insert( Schema::table( 'folders' ), [
			'parent_id' => 0, 'name' => $name, 'owner_user_id' => 0, 'is_private' => 0, 'created_by' => 0,
			'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		return (int) $wpdb->insert_id;
	}

	private function grant_folder( int $id, string $type, string $key ): void {
		global $wpdb;
		$wpdb->insert( Schema::table( 'permissions' ), [
			'entity_type' => 'folder', 'entity_id' => $id, 'subject_type' => $type, 'subject_key' => $key,
			'capability' => 'view', 'created_by' => 0, 'created_at' => current_time( 'mysql' ),
		] );
	}

	/** A file row; $bytes = null writes no bytes (row without a file on disk). */
	private function file( ?string $bytes, string $name = 'doc.txt', string $mime = 'text/plain' ): int {
		global $wpdb;
		if ( null !== $bytes ) {
			wp_mkdir_p( $this->store . '/' . $this->folder );
			file_put_contents( $this->store . '/' . $this->folder . '/stored-' . $name, $bytes );
		}
		$wpdb->insert( Schema::table( 'files' ), [
			'folder_id' => $this->folder, 'original_name' => $name, 'stored_name' => 'stored-' . $name, 'mime_type' => $mime,
			'size' => strlen( (string) $bytes ), 'uploader_user_id' => 1, 'created_at' => current_time( 'mysql' ),
		] );
		return (int) $wpdb->insert_id;
	}

	private function get( string $route, array $params = [] ): WP_REST_Response {
		$req = new WP_REST_Request( 'GET', '/anchor-library/v1' . $route );
		$req->set_query_params( $params );
		return rest_do_request( $req );
	}

	/** Run /content and capture what it streamed. @return array{status:int,body:string,codes:int[],headers:string[],error:?WP_Error} */
	private function content( int $file_id, string $token, string $range = '' ): array {
		if ( '' !== $range ) {
			$_SERVER['HTTP_RANGE'] = $range;
		} else {
			unset( $_SERVER['HTTP_RANGE'] );
		}
		$codes = [];
		$cb    = static function ( $header, $code ) use ( &$codes ) {
			$codes[] = $code;
			return $header;
		};
		$halt = static function () {
			throw new RuntimeException( 'halted' );
		};
		add_filter( 'status_header', $cb, 10, 2 );
		add_action( 'anchor_library_halt', $halt );
		set_error_handler( static fn() => true, E_WARNING ); // phpcs:ignore -- header() after PHPUnit output is a warning in the CLI.
		ob_start();
		$res    = null;
		$halted = false;
		try {
			$res = $this->get( '/files/' . $file_id . '/content', [ 't' => $token ] );
		} catch ( RuntimeException $e ) {
			$halted = true;
		}
		$body = ob_get_clean();
		restore_error_handler();
		remove_filter( 'status_header', $cb, 10 );
		remove_action( 'anchor_library_halt', $halt );
		return [
			'halted'  => $halted,
			'status'  => $halted ? ( $codes ? end( $codes ) : 200 ) : $res->get_status(),
			'body'    => $body,
			'codes'   => $codes,
			'headers' => function_exists( 'headers_list' ) ? headers_list() : [],
			'error'   => ( $res && $res->is_error() ) ? $res->as_error() : null,
		];
	}

	private function activity_rows(): array {
		global $wpdb;
		return $wpdb->get_results( 'SELECT * FROM ' . Schema::table( 'activity' ) . ' ORDER BY id ASC' );
	}

	// ---- token ----------------------------------------------------------------------------------------------

	public function test_token_round_trips() {
		$t = LinkToken::issue( $this->member, 7, 'inline', self::NOW );
		$this->assertSame( [ 'user_id' => $this->member, 'disposition' => 'inline' ], LinkToken::verify( $t, 7, self::NOW + 1 ) );
		$this->assertSame( [ 'user_id' => $this->member, 'disposition' => 'inline' ], LinkToken::verify( $t, 7, self::NOW + LinkToken::TTL ) );
	}

	public function test_token_format_is_payload_dot_hmac() {
		$t = LinkToken::issue( 5, 9, 'attachment', self::NOW );
		[ $payload, $mac ] = explode( '.', $t );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]+$/', $payload );
		$this->assertSame( '5.9.attachment.' . ( self::NOW + 600 ), base64_decode( strtr( $payload, '-_', '+/' ) ) );
		$this->assertSame( hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) ), $mac );
		$this->assertSame( 600, LinkToken::TTL );
	}

	public function test_expired_token_is_null() {
		$t = LinkToken::issue( $this->member, 7, 'inline', self::NOW );
		$this->assertNull( LinkToken::verify( $t, 7, self::NOW + LinkToken::TTL + 1 ) );
	}

	public function test_tampered_or_malformed_tokens_are_null() {
		$t = LinkToken::issue( $this->member, 7, 'inline', self::NOW );
		[ $payload, $mac ] = explode( '.', $t );
		$forged = rtrim( strtr( base64_encode( '1.7.inline.' . ( self::NOW + 600 ) ), '+/', '-_' ), '=' ); // admin, same file, valid shape
		$this->assertNull( LinkToken::verify( $forged . '.' . $mac, 7, self::NOW ) );
		$this->assertNull( LinkToken::verify( $payload . '.' . strrev( $mac ), 7, self::NOW ) );
		$this->assertNull( LinkToken::verify( $payload, 7, self::NOW ) );
		$this->assertNull( LinkToken::verify( '', 7, self::NOW ) );
		$this->assertNull( LinkToken::verify( 'a.b.c', 7, self::NOW ) );
		$bad = rtrim( strtr( base64_encode( '1.7.evil.' . ( self::NOW + 600 ) ), '+/', '-_' ), '=' );
		$this->assertNull( LinkToken::verify( $bad . '.' . hash_hmac( 'sha256', $bad, wp_salt( 'auth' ) ), 7, self::NOW ), 'unknown disposition' );
	}

	public function test_token_for_file_a_is_refused_for_file_b() {
		$t = LinkToken::issue( $this->member, 7, 'inline', self::NOW );
		$this->assertNull( LinkToken::verify( $t, 8, self::NOW ) );
	}

	// ---- /link ----------------------------------------------------------------------------------------------

	public function test_link_signed_out_is_401() {
		$id  = $this->file( 'x' );
		$res = $this->get( '/files/' . $id . '/link' );
		$this->assertSame( 401, $res->get_status() );
		$this->assertSame( 'anchor_library_auth', $res->as_error()->get_error_code() );
	}

	public function test_link_without_view_right_is_403_and_missing_is_404() {
		$id = $this->file( 'x' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$res = $this->get( '/files/' . $id . '/link' );
		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( 'anchor_library_forbidden', $res->as_error()->get_error_code() );
		$this->assertSame( 404, $this->get( '/files/99999/link' )->get_status() );
	}

	public function test_link_returns_url_and_expiry_and_validates_disposition() {
		$id = $this->file( 'x' );
		wp_set_current_user( $this->member );
		$before = time();
		$res    = $this->get( '/files/' . $id . '/link' );
		$data   = $res->get_data();
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( [ 'url', 'expires_at' ], array_keys( $data ) );
		$this->assertStringContainsString( '/anchor-library/v1/files/' . $id . '/content', $data['url'] );
		parse_str( (string) wp_parse_url( $data['url'], PHP_URL_QUERY ), $q );
		$ver = LinkToken::verify( $q['t'], $id, time() );
		$this->assertSame( [ 'user_id' => $this->member, 'disposition' => 'attachment' ], $ver, 'default is attachment' );
		$exp = strtotime( $data['expires_at'] );
		$this->assertGreaterThanOrEqual( $before + 599, $exp );
		$this->assertLessThanOrEqual( time() + 601, $exp );
		$this->assertStringContainsString( 'no-store', $res->get_headers()['Cache-Control'] );

		$inline = $this->get( '/files/' . $id . '/link', [ 'disposition' => 'inline' ] )->get_data();
		parse_str( (string) wp_parse_url( $inline['url'], PHP_URL_QUERY ), $q );
		$this->assertSame( 'inline', LinkToken::verify( $q['t'], $id, time() )['disposition'] );

		$this->assertSame( 400, $this->get( '/files/' . $id . '/link', [ 'disposition' => 'zip' ] )->get_status() );
	}

	// ---- /content -------------------------------------------------------------------------------------------

	public function test_content_streams_bytes_and_logs_app_activity() {
		$id  = $this->file( 'hello world' );
		$tok = LinkToken::issue( $this->member, $id, 'attachment', time() );
		$out = $this->content( $id, $tok );
		$this->assertSame( 'hello world', $out['body'] );
		$this->assertSame( 200, $out['status'] );
		$rows = $this->activity_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'download_file', $rows[0]->action );
		$this->assertSame( (string) $this->member, (string) $rows[0]->actor_user_id );
		$this->assertSame( 'file', $rows[0]->entity_type );
		$this->assertSame( 'app', json_decode( $rows[0]->meta, true )['source'] );
	}

	public function test_inline_token_logs_preview_and_attachment_sets_disposition() {
		$id  = $this->file( 'abc', 'my doc.txt' );
		$out = $this->content( $id, LinkToken::issue( $this->member, $id, 'inline', time() ) );
		$this->assertSame( 'preview_file', $this->activity_rows()[0]->action );
		$out2 = $this->content( $id, LinkToken::issue( $this->member, $id, 'attachment', time() ) );
		if ( $out2['headers'] ) {
			$this->assertContains( 'Content-Disposition: attachment; filename="my-doc.txt"', $out2['headers'] );
		}
		$this->assertSame( 'download_file', end( $this->activity_rows() )->action );
	}

	public function test_content_ignores_cookie_session_and_needs_a_token() {
		$id = $this->file( 'secret' );
		wp_set_current_user( $this->member ); // a signed-in browser session is worth nothing here
		$out = $this->content( $id, '' );
		$this->assertSame( 401, $out['status'] );
		$this->assertSame( 'anchor_library_auth', $out['error']->get_error_code() );
		$this->assertSame( '', $out['body'] );
		$this->assertSame( [], $this->activity_rows() );

		wp_set_current_user( $this->admin );
		$this->assertSame( 401, $this->content( $id, 'garbage.token' )['status'] );
		$other = $this->file( 'other', 'other.txt' );
		$this->assertSame( 401, $this->content( $id, LinkToken::issue( $this->member, $other, 'inline', time() ) )['status'], 'token for another file' );
		$this->assertSame( 401, $this->content( $id, LinkToken::issue( $this->member, $id, 'inline', time() - 700 ) )['status'], 'expired' );
	}

	public function test_content_uses_the_token_user_not_the_current_user() {
		$id = $this->file( 'abc' );
		wp_set_current_user( $this->admin );
		$outsider = self::factory()->user->create( [ 'role' => 'editor' ] );
		$out      = $this->content( $id, LinkToken::issue( $outsider, $id, 'inline', time() ) );
		$this->assertSame( 403, $out['status'], 'the admin cookie does not lend its rights to the token user' );
		$this->assertSame( '', $out['body'] );
	}

	public function test_content_after_the_grant_is_removed_is_403_inside_the_ttl() {
		global $wpdb;
		$id  = $this->file( 'abc' );
		$tok = LinkToken::issue( $this->member, $id, 'inline', time() );
		$this->assertSame( 'abc', $this->content( $id, $tok )['body'] );

		$wpdb->query( 'DELETE FROM ' . Schema::table( 'permissions' ) ); // phpcs:ignore
		Permissions::reset_memo();
		$out = $this->content( $id, $tok );
		$this->assertSame( 403, $out['status'] );
		$this->assertSame( 'anchor_library_forbidden', $out['error']->get_error_code() );
		$this->assertSame( '', $out['body'] );
		$this->assertCount( 1, $this->activity_rows(), 'the refused request logged nothing' );
	}

	public function test_content_for_a_deleted_user_or_missing_bytes() {
		$id = $this->file( null, 'ghost.txt' );
		$this->assertSame( 404, $this->content( $id, LinkToken::issue( $this->member, $id, 'inline', time() ) )['status'] );
		$this->assertSame( 403, $this->content( $id, LinkToken::issue( 987654, $id, 'inline', time() ) )['status'] );
		$gone = $this->file( 'x', 'gone.txt' );
		global $wpdb;
		$wpdb->delete( Schema::table( 'files' ), [ 'id' => $gone ] );
		$this->assertSame( 404, $this->content( $gone, LinkToken::issue( $this->member, $gone, 'inline', time() ) )['status'] );
	}

	public function test_range_request_returns_206_with_exact_length_on_a_large_file() {
		$bytes = str_repeat( '0123456789', 314573 ); // 3,145,730 bytes
		$id    = $this->file( $bytes, 'big.bin', 'application/octet-stream' );
		$tok   = LinkToken::issue( $this->member, $id, 'inline', time() );

		$out = $this->content( $id, $tok, 'bytes=0-99' );
		$this->assertSame( 206, $out['status'] );
		$this->assertContains( 206, $out['codes'] );
		$this->assertSame( 100, strlen( $out['body'] ) );
		$this->assertSame( substr( $bytes, 0, 100 ), $out['body'] );
		$plan = \Anchor\Library\Storage::plan( 'bytes=0-99', strlen( $bytes ) );
		$this->assertSame( '100', $plan['headers']['Content-Length'] );
		if ( $out['headers'] ) {
			$this->assertContains( 'Content-Length: 100', $out['headers'] );
			$this->assertContains( 'Content-Range: bytes 0-99/' . strlen( $bytes ), $out['headers'] );
		}

		$mid = $this->content( $id, $tok, 'bytes=1000000-1000009' );
		$this->assertSame( 206, $mid['status'] );
		$this->assertSame( substr( $bytes, 1000000, 10 ), $mid['body'] );
		$this->assertSame( 416, $this->content( $id, $tok, 'bytes=9999999-' )['status'] );

		$rows = $this->activity_rows(); // only the opening range of an inline playthrough is logged
		$this->assertCount( 1, $rows );
		$this->assertSame( 'preview_file', $rows[0]->action );
	}

	public function test_uploaded_video_files_use_the_same_path() {
		$id  = $this->file( 'FAKEMP4', 'clip.mp4', 'video/mp4' );
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->get( '/files/' . $id . '/link' )->get_status() );
		wp_set_current_user( $this->member );
		$data = $this->get( '/files/' . $id . '/link', [ 'disposition' => 'inline' ] )->get_data();
		parse_str( (string) wp_parse_url( $data['url'], PHP_URL_QUERY ), $q );
		wp_set_current_user( 0 );
		$out = $this->content( $id, $q['t'], 'bytes=0-3' );
		$this->assertSame( 206, $out['status'] );
		$this->assertSame( 'FAKE', $out['body'] );
	}
}
