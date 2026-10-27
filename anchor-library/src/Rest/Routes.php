<?php
/**
 * Read-only REST API for the member app: namespace anchor-library/v1.
 *
 * The app mirrors the site, so nothing here decides who may see what: Permissions does, Repository selects, and
 * Shapes maps. Routes follow the Anchor Events feed (anchor-events-manager/class-feed.php, `Feed::respond()` and
 * `filter_nocache_headers()`): an open permission_callback with authentication inside the callback so the 401
 * carries our own code, plain-text rows, a weak body-hash ETag, `Cache-Control: private`, `Vary: Authorization`,
 * and core's no-cache headers switched off for the cacheable routes. That helper is a twin of the events one; once
 * both are on main they should be folded into one shared class (events was deliberately not touched here).
 *
 * Authentication is the Application Password user WordPress resolved before the callback runs. A cookie without a
 * nonce resolves to no user at all, so it is a 401; nothing in this API reads a nonce. The content route is the one
 * exception to "log in first": its credential is the signed token alone (LinkToken), the current user is ignored.
 *
 * @package Anchor\Library
 */

namespace Anchor\Library\Rest;

use Anchor\Library\Activity;
use Anchor\Library\Permissions;
use Anchor\Library\Repository;
use Anchor\Library\Storage;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

class Routes {

	public const NS = 'anchor-library/v1';

	/** Route of the request being dispatched, for filter_nocache_headers(). */
	private static string $dispatching = '';

	/** Hooked to rest_api_init by Module::register_hooks(). */
	public static function register_routes(): void {
		\add_filter( 'rest_pre_dispatch', [ self::class, 'note_route' ], 10, 3 );
		\add_filter( 'rest_send_nocache_headers', [ self::class, 'filter_nocache_headers' ] );
		\add_filter( 'rest_request_after_callbacks', [ self::class, 'no_store_errors' ], 10, 3 );

		$open = '__return_true'; // Auth is inside the callbacks so the error codes are ours.
		\register_rest_route( self::NS, '/folders/(?P<id>\d+)', [
			'methods'             => 'GET',
			'permission_callback' => $open,
			'callback'            => [ self::class, 'rest_folder' ],
			'args'                => [ 'id' => [ 'type' => 'integer' ] ],
		] );
		\register_rest_route( self::NS, '/search', [
			'methods'             => 'GET',
			'permission_callback' => $open,
			'callback'            => [ self::class, 'rest_search' ],
			'args'                => [ 'q' => [ 'type' => 'string' ] ],
		] );
		\register_rest_route( self::NS, '/files/(?P<id>\d+)/link', [
			'methods'             => 'GET',
			'permission_callback' => $open,
			'callback'            => [ self::class, 'rest_link' ],
			'args'                => [
				'id'          => [ 'type' => 'integer' ],
				'disposition' => [ 'type' => 'string', 'default' => 'attachment', 'enum' => [ 'inline', 'attachment' ] ],
			],
		] );
		\register_rest_route( self::NS, '/files/(?P<id>\d+)/content', [
			'methods'             => 'GET',
			'permission_callback' => $open, // The signed token is the credential; see rest_content().
			'callback'            => [ self::class, 'rest_content' ],
			'args'                => [ 'id' => [ 'type' => 'integer' ], 't' => [ 'type' => 'string' ] ],
		] );
		\register_rest_route( self::NS, '/videos/(?P<id>\d+)', [
			'methods'             => 'GET',
			'permission_callback' => $open,
			'callback'            => [ self::class, 'rest_video' ],
			'args'                => [
				'id'    => [ 'type' => 'integer' ],
				'sizes' => [ 'type' => 'boolean', 'default' => false ],
			],
		] );
		\register_rest_route( self::NS, '/videos/(?P<id>\d+)/download', [
			'methods'             => 'GET',
			'permission_callback' => $open,
			'callback'            => [ self::class, 'rest_video_download' ],
			'args'                => [
				'id'        => [ 'type' => 'integer' ],
				'rendition' => [ 'type' => 'string', 'default' => '540p', 'pattern' => '^\d{3,4}p$' ],
			],
		] );
	}

	/** @internal rest_pre_dispatch: remember the route being served; changes nothing. */
	public static function note_route( $result, $server, $request ) {
		self::$dispatching = $request instanceof \WP_REST_Request ? $request->get_route() : '';
		return $result;
	}

	/**
	 * serve_request() sends no-cache headers for any signed-in caller (an Application Password counts) AFTER our
	 * headers, overwriting `Cache-Control: private, max-age=60`. The two listing routes are private already.
	 */
	public static function filter_nocache_headers( $send ) {
		$own = '/' . self::NS;
		return \preg_match( '#^' . \preg_quote( $own, '#' ) . '/(folders/\d+|search)$#', self::$dispatching ) ? false : $send;
	}

	/**
	 * rest_request_after_callbacks: every error from this namespace (400/401/403/404/405) becomes a response with
	 * `Cache-Control: no-store`. The listing routes switch core's no-cache headers off, so without this their errors
	 * would carry no cache directive at all, and an intermediary must never keep a 401 or 403 for a URL another
	 * caller may open. (This hook, unlike rest_post_dispatch, also runs for in-process rest_do_request().)
	 *
	 * @param WP_REST_Response|WP_HTTP_Response|WP_Error|mixed $response
	 * @param array                                            $handler
	 */
	public static function no_store_errors( $response, $handler, $request ) {
		if ( ! $request instanceof \WP_REST_Request || 0 !== \strpos( $request->get_route(), '/' . self::NS . '/' ) ) {
			return $response;
		}
		if ( \is_wp_error( $response ) ) {
			$status = (int) ( ( $response->get_error_data() )['status'] ?? 500 );
			if ( $status < 400 ) {
				return $response;
			}
			$response = \rest_convert_error_to_response( $response );
		}
		if ( $response instanceof \WP_HTTP_Response && $response->get_status() >= 400 ) {
			$response->header( 'Cache-Control', 'no-store' );
		}
		return $response;
	}

	// ---- Listings -------------------------------------------------------------------------------------------

	public static function rest_folder( \WP_REST_Request $request ) {
		$user_id = self::user_id();
		if ( ! $user_id ) {
			return self::auth_error();
		}
		$folder_id = (int) $request['id'];

		// Gate first: Repository::breadcrumbs() and folder paths do no permission check, so a trail for a folder
		// the caller may not open would leak its ancestors' names.
		$row       = null;
		if ( $folder_id > 0 ) {
			$row = Repository::folder_row( $folder_id );
			// The Product Docs container and a user's private folder are never browsable here.
			if ( ! $row || 1 === (int) $row->is_private || $folder_id === Repository::product_docs_folder_id() ) {
				return self::not_found();
			}
			if ( ! Permissions::can_view_folder( $folder_id, $user_id ) ) {
				return self::forbidden();
			}
		}

		$data = Permissions::with_memo( static function () use ( $folder_id, $user_id, $row ) {
			$children = Repository::children( $folder_id, $user_id );
			$folders  = [];
			foreach ( $children['folders'] as $f ) {
				$folders[] = Shapes::folder( (int) $f['id'], (string) $f['name'], $folder_id, Shapes::counts( Repository::children( (int) $f['id'], $user_id ) ) );
			}
			return [
				'folder'      => $folder_id > 0
					? Shapes::folder( $folder_id, (string) $row->name, (int) $row->parent_id, Shapes::counts( $children ) )
					: Shapes::folder( 0, '', 0, Shapes::counts( $children ) ),
				'breadcrumbs' => $folder_id > 0 ? Repository::breadcrumbs( $folder_id ) : [],
				'folders'     => $folders,
				'files'       => \array_map( [ Shapes::class, 'file' ], $children['files'] ),
				'videos'      => \array_map( [ Shapes::class, 'video' ], $children['videos'] ),
				'links'       => \array_map( [ Shapes::class, 'link' ], $children['links'] ),
			];
		} );
		return self::respond( $request, $data );
	}

	public static function rest_search( \WP_REST_Request $request ) {
		$user_id = self::user_id();
		if ( ! $user_id ) {
			return self::auth_error();
		}
		$q = \trim( \sanitize_text_field( (string) $request['q'] ) );
		if ( \mb_strlen( $q ) < 2 ) {
			return new \WP_Error( 'anchor_library_query', \__( 'Search for at least 2 characters.', 'anchor-schema' ), [ 'status' => 400 ] );
		}
		$found = Repository::search( $q, $user_id );
		$out   = [ 'q' => $q, 'folders' => [], 'files' => [], 'videos' => [], 'links' => [], 'truncated' => (bool) $found['truncated'] ];
		foreach ( $found['results'] as $r ) {
			$where = [ 'folder_id' => (int) $r['folderId'], 'path' => (string) $r['path'] ];
			switch ( $r['kind'] ) {
				case 'folder':
					$out['folders'][] = [ 'id' => (int) $r['id'], 'name' => (string) $r['name'], 'parent_id' => (int) $r['folderId'], 'path' => (string) $r['path'] ];
					break;
				case 'file':
					$out['files'][] = [ 'id' => (int) $r['id'], 'name' => (string) $r['name'], 'mime' => (string) $r['mime'], 'size' => (int) $r['size'] ] + $where;
					break;
				case 'video':
					$out['videos'][] = Shapes::video( [ 'id' => $r['id'], 'title' => $r['name'], 'vimeoId' => $r['vimeoId'], 'vimeoHash' => $r['vimeoHash'], 'thumbnailUrl' => $r['thumbnailUrl'] ] ) + $where;
					break;
				default:
					$out['links'][] = Shapes::link( [ 'id' => $r['id'], 'title' => $r['name'], 'url' => $r['url'] ] ) + $where;
			}
		}
		return self::respond( $request, $out );
	}

	// ---- Files ----------------------------------------------------------------------------------------------

	/** Issue a signed link for a file the caller may view. Never cached: every call mints a new token. */
	public static function rest_link( \WP_REST_Request $request ) {
		$user_id = self::user_id();
		if ( ! $user_id ) {
			return self::auth_error();
		}
		$file_id = (int) $request['id'];
		if ( ! Repository::file_row( $file_id ) ) {
			return self::not_found();
		}
		if ( ! Permissions::can_view_file( $file_id, $user_id ) ) {
			return self::forbidden();
		}
		$now      = \time();
		$token    = LinkToken::issue( $user_id, $file_id, (string) $request['disposition'], $now );
		$response = new \WP_REST_Response( [
			'url'        => \add_query_arg( 't', $token, \rest_url( self::NS . '/files/' . $file_id . '/content' ) ),
			'expires_at' => \gmdate( 'Y-m-d\TH:i:s\Z', LinkToken::expiry( $now ) ),
		], 200 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Stream a file. The signed token is the only credential: this route never looks at the current user, a
	 * cookie or a nonce, so a request that relies on a browser session gets 401. The token's user is re-checked
	 * against the live permission model, so a grant revoked inside the 10 minutes stops the link.
	 */
	public static function rest_content( \WP_REST_Request $request ) {
		// GET only: a HEAD would run the whole handler (stream, log) with the body discarded by the server.
		if ( 'GET' !== $request->get_method() ) {
			return new \WP_Error( 'anchor_library_method', \__( 'Use GET to fetch a file.', 'anchor-schema' ), [ 'status' => 405 ] );
		}
		$file_id = (int) $request['id'];
		$claims  = LinkToken::verify( (string) $request['t'], $file_id, \time() );
		if ( null === $claims ) {
			return self::auth_error( \__( 'This link is missing, invalid or has expired.', 'anchor-schema' ) );
		}
		$user_id = $claims['user_id'];
		$file    = Repository::file_row( $file_id );
		if ( ! $file ) {
			Storage::log_refusal( $file_id, 'no database row' );
			return self::not_found();
		}
		if ( ! \get_userdata( $user_id ) || ! Permissions::can_view_file( $file_id, $user_id ) ) {
			Storage::log_refusal( $file_id, 'user ' . $user_id . ' lacks view capability' );
			return self::forbidden();
		}
		$path = Storage::path_for( $file );
		if ( ! @\file_exists( $path ) || ! @\is_readable( $path ) ) {
			Storage::log_refusal( $file_id, Storage::describe_unreadable( $path ) );
			return self::not_found();
		}

		$disposition = Storage::effective_disposition( $claims['disposition'], (string) $file->mime_type, true ); // A download is what the client gets for a type outside the inline-safe set, so that is what is logged.
		$plan        = Storage::plan( isset( $_SERVER['HTTP_RANGE'] ) ? (string) $_SERVER['HTTP_RANGE'] : '', (int) \filesize( $path ) ); // phpcs:ignore WordPress.Security
		// Same rule as the portal's stream: a preview logs only its opening range, a download is always logged.
		// A 416 delivers no bytes, so it is neither (the stream still answers it, with Content-Range: bytes */size).
		if ( 416 !== $plan['status'] && ( 'attachment' === $disposition || $plan['opening'] ) ) {
			Activity::log( 'inline' === $disposition ? 'preview_file' : 'download_file', 'file', $file_id, [ 'source' => 'app' ], $user_id );
		}
		Storage::stream( $path, (string) $file->mime_type, $disposition, (string) $file->original_name, Storage::discard_buffers(), true );
		return null; // Unreachable: stream() ends the request.
	}

	// ---- Videos ---------------------------------------------------------------------------------------------

	/**
	 * One video, and with `sizes=1` the sizes Vimeo can supply for saving it offline. Without it Vimeo is not contacted,
	 * which is the bare call the app uses as its access check for a saved copy.
	 * `downloads` carries rendition and size only: a signed link is minted by rest_video_download() alone.
	 */
	public static function rest_video( \WP_REST_Request $request ) {
		$gate = self::video_gate( (int) $request['id'] );
		if ( $gate instanceof \WP_Error ) {
			return $gate;
		}
		$downloads = [];
		if ( $request['sizes'] && \Anchor_Vimeo_Api::is_configured() ) {
			$list = \Anchor_Vimeo_Api::renditions( (string) $gate->vimeo_id );
			foreach ( \is_array( $list ) ? $list : [] as $r ) {
				$downloads[] = [ 'rendition' => (string) $r['rendition'], 'size' => (int) $r['size'] ];
			}
		}
		$data     = Shapes::video( Repository::shape_video( $gate ) ) + [ 'downloads' => $downloads ];
		$response = new \WP_REST_Response( $data, 200 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/** A short-lived Vimeo file link for one rendition. Never cached; each call is logged as a download. */
	public static function rest_video_download( \WP_REST_Request $request ) {
		$video_id = (int) $request['id'];
		$gate     = self::video_gate( $video_id );
		if ( $gate instanceof \WP_Error ) {
			return $gate;
		}
		if ( ! \Anchor_Vimeo_Api::is_configured() ) {
			return self::downloads_unavailable();
		}
		$list = \Anchor_Vimeo_Api::renditions( (string) $gate->vimeo_id );
		if ( \is_wp_error( $list ) ) {
			return self::downloads_unavailable();
		}
		$pick = self::pick_rendition( $list, (string) $request['rendition'] );
		if ( ! $pick ) {
			return new \WP_Error( 'anchor_library_no_download', \__( 'This video has no downloadable copy.', 'anchor-schema' ), [ 'status' => 404 ] );
		}
		Activity::log( 'download_video', 'video', $video_id, [ 'source' => 'app', 'rendition' => $pick['rendition'] ], self::user_id() );
		$response = new \WP_REST_Response( [
			'url'        => (string) $pick['url'],
			'rendition'  => (string) $pick['rendition'],
			'size'       => (int) $pick['size'],
			'expires_at' => \gmdate( 'Y-m-d\TH:i:s\Z', (int) $pick['expires'] ),
		], 200 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/** User, then row, then permission (as rest_link()), so a forbidden caller learns nothing. Row on success. */
	private static function video_gate( int $video_id ) {
		if ( ! self::user_id() ) {
			return self::auth_error();
		}
		$row = Repository::video_row( $video_id );
		if ( ! $row ) {
			return self::not_found();
		}
		return Permissions::can_view_video( $video_id, self::user_id() ) ? $row : self::forbidden();
	}

	/**
	 * The wanted rendition if Vimeo has it; else the best one below it; else the smallest there is.
	 *
	 * @param array[] $list Renditions, any order.
	 */
	private static function pick_rendition( array $list, string $wanted ): ?array {
		$height = static fn( array $r ): int => (int) $r['rendition'];
		\usort( $list, static fn( $a, $b ) => $height( $b ) <=> $height( $a ) ); // Highest first.
		foreach ( $list as $r ) {
			if ( $height( $r ) <= (int) $wanted ) {
				return $r;
			}
		}
		return $list ? $list[ \count( $list ) - 1 ] : null;
	}

	private static function downloads_unavailable(): \WP_Error {
		return new \WP_Error( 'anchor_library_downloads_unavailable', \__( 'Downloads are not available right now.', 'anchor-schema' ), [ 'status' => 503 ] );
	}

	// ---- Plumbing -------------------------------------------------------------------------------------------

	private static function user_id(): int {
		return (int) \get_current_user_id();
	}

	private static function auth_error( string $message = '' ): \WP_Error {
		return new \WP_Error( 'anchor_library_auth', '' !== $message ? $message : \__( 'Sign in to see the library.', 'anchor-schema' ), [ 'status' => 401 ] );
	}

	private static function forbidden(): \WP_Error {
		return new \WP_Error( 'anchor_library_forbidden', \__( 'You do not have access to this item.', 'anchor-schema' ), [ 'status' => 403 ] );
	}

	private static function not_found(): \WP_Error {
		return new \WP_Error( 'anchor_library_not_found', \__( 'Not found.', 'anchor-schema' ), [ 'status' => 404 ] );
	}

	/**
	 * 200 with private caching + a body-hash ETag, or 304 when the client already has it. Twin of
	 * Anchor\Events\Feed::respond(). `Vary: Authorization` because the URL is the same for every caller and the
	 * answer differs only by who asks.
	 */
	// TODO(merge order): whichever of feat/events-feed-api / feat/library-module merges second must fold this
	// respond() and filter_nocache_headers() with Anchor\Events\Feed's twins into one shared helper.
	private static function respond( \WP_REST_Request $request, array $data ): \WP_REST_Response {
		$etag     = 'W/"' . \md5( (string) \wp_json_encode( $data ) ) . '"';
		$match    = \trim( (string) $request->get_header( 'if_none_match' ) );
		$response = ( '' !== $match && $match === $etag ) ? new \WP_REST_Response( null, 304 ) : new \WP_REST_Response( $data, 200 );
		$response->header( 'ETag', $etag );
		$response->header( 'Cache-Control', 'private, max-age=60' );
		$vary = \array_filter( \array_map( 'trim', \explode( ',', (string) ( $response->get_headers()['Vary'] ?? '' ) ) ) );
		if ( ! \in_array( 'authorization', \array_map( 'strtolower', $vary ), true ) ) {
			$vary[] = 'Authorization';
		}
		$response->header( 'Vary', \implode( ', ', $vary ) );
		return $response;
	}
}
