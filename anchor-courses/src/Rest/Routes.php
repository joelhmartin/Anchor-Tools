<?php
declare(strict_types=1);

namespace Anchor\Courses\Rest;

use Anchor\Courses\Content\CoursePostType;
use Anchor\Courses\Support\Capabilities;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * REST registrar and the shared permission callbacks (brief 14, 25).
 *
 * No route in this namespace may use '__return_true'. Even a hypothetically
 * public read names a real callback (public_read()), so "is this open?" is a
 * decision with a single place to audit.
 *
 * error_response() is the seam this module's error->status policy lives
 * behind: a service method returns a plain WP_Error with no status attached
 * (rule: the REST layer, not the service, decides HTTP status - the service
 * has no concept of "HTTP"), and this is the one place that mapping is made.
 * See task-26-report.md for the documented map.
 */
final class Routes {

	public const NAMESPACE = 'anchor-courses/v1';

	/** @var object[] Controllers with a register_routes() method. */
	private array $controllers;

	public function __construct( object ...$controllers ) {
		$this->controllers = $controllers;
		\add_action( 'rest_api_init', [ $this, 'register' ] );
	}

	public function register(): void {
		foreach ( $this->controllers as $controller ) {
			if ( \method_exists( $controller, 'register_routes' ) ) {
				$controller->register_routes();
			}
		}
	}

	/**
	 * Any signed-in user. A nonce proves a session, not ownership - callers
	 * needing ownership (e.g. "is this your attempt?") check that separately,
	 * inside the callback, against the real record.
	 *
	 * @return true|\WP_Error
	 */
	public static function require_login( \WP_REST_Request $request ) {
		if ( \get_current_user_id() > 0 ) {
			return true;
		}
		return new \WP_Error(
			'rest_forbidden',
			\__( 'You must be signed in.', 'anchor-schema' ),
			[ 'status' => 401 ]
		);
	}

	/** A permission_callback requiring one of our capabilities. */
	public static function require_cap( string $key ): callable {
		return static function ( \WP_REST_Request $request ) use ( $key ) {
			if ( \current_user_can( Capabilities::cap( $key ) ) ) {
				return true;
			}
			return new \WP_Error(
				'rest_forbidden',
				\__( 'You are not allowed to do that.', 'anchor-schema' ),
				[ 'status' => \get_current_user_id() > 0 ? 403 : 401 ]
			);
		};
	}

	/**
	 * Readable by anyone the course post type is already readable by.
	 *
	 * A real check, not __return_true: if a site makes courses non-public,
	 * this endpoint closes with them.
	 *
	 * @return true|\WP_Error
	 */
	public static function public_read( \WP_REST_Request $request ) {
		$type = \get_post_type_object( CoursePostType::CPT );
		if ( $type instanceof \WP_Post_Type && $type->publicly_queryable ) {
			return true;
		}
		return self::require_login( $request );
	}

	/**
	 * Map a service WP_Error onto an HTTP status.
	 *
	 * Documented error -> status map (task-26-report.md keeps the canonical
	 * copy): 403 for anything about who you are relative to the resource
	 * (not_enrolled, locked, not_in_course, course_closed,
	 * missing_prerequisite, no_attempts_remaining, retry_delay,
	 * attempt_not_yours); 404 for "that id does not exist" (no_attempt,
	 * no_course, no_user); 409 for a state conflict on an otherwise-valid
	 * request (attempt_closed, no_questions); 400 for a malformed request
	 * (unknown_question) and the fallback for anything unlisted.
	 */
	public static function error_response( \WP_Error $error, int $status = 400 ): \WP_REST_Response {
		$map = [
			'not_enrolled'          => 403,
			'locked'                => 403,
			'not_in_course'         => 403,
			'course_closed'         => 403,
			'missing_prerequisite'  => 403,
			'no_attempts_remaining' => 403,
			'retry_delay'           => 403,
			'attempt_not_yours'     => 403,
			'attempt_closed'        => 409,
			'no_attempt'            => 404,
			'no_course'             => 404,
			'no_user'               => 404,
			'unknown_question'      => 400,
			'no_questions'          => 409,
		];

		$code = (string) $error->get_error_code();

		return new \WP_REST_Response(
			[ 'code' => $code, 'message' => $error->get_error_message() ],
			$map[ $code ] ?? $status
		);
	}
}
