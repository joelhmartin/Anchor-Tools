<?php
declare(strict_types=1);

namespace Anchor\Courses\Rest;

use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Services\CertificateService;
use Anchor\Courses\Services\CreditService;
use Anchor\Courses\Content\CoursePostType;
use Anchor\Courses\Services\EnrollmentService;
use Anchor\Courses\Services\ProgressService;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Learner-scoped endpoints (brief 14).
 *
 * Every callback reads get_current_user_id() and ignores any user id in the
 * request, so no learner can address another learner's records (brief 25).
 */
final class MeController {

	public function __construct(
		private EnrollmentService $enrollments,
		private ProgressService $progress,
		private CreditService $credits,
		private CertificateService $certificates
	) {}

	public function register_routes(): void {
		$login   = [ Routes::class, 'require_login' ];
		$id_arg  = [ 'id' => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ] ];
		$lesson  = \array_merge( $id_arg, [ 'course_id' => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ] ] );

		\register_rest_route( Routes::NAMESPACE, '/me/courses', [
			'methods' => 'GET', 'permission_callback' => $login, 'callback' => [ $this, 'courses' ],
		] );

		\register_rest_route( Routes::NAMESPACE, '/me/courses/(?P<id>\d+)/progress', [
			'methods' => 'GET', 'permission_callback' => $login, 'callback' => [ $this, 'progress' ], 'args' => $id_arg,
		] );

		\register_rest_route( Routes::NAMESPACE, '/lessons/(?P<id>\d+)/start', [
			'methods' => 'POST', 'permission_callback' => $login, 'callback' => [ $this, 'start_lesson' ], 'args' => $lesson,
		] );

		\register_rest_route( Routes::NAMESPACE, '/lessons/(?P<id>\d+)/complete', [
			'methods' => 'POST', 'permission_callback' => $login, 'callback' => [ $this, 'complete_lesson' ], 'args' => $lesson,
		] );

		\register_rest_route( Routes::NAMESPACE, '/me/certificates', [
			'methods' => 'GET', 'permission_callback' => $login, 'callback' => [ $this, 'certificates' ],
		] );

		\register_rest_route( Routes::NAMESPACE, '/me/credits', [
			'methods' => 'GET', 'permission_callback' => $login, 'callback' => [ $this, 'credits' ],
		] );
	}

	public function courses( \WP_REST_Request $request ): \WP_REST_Response {
		$user_id = \get_current_user_id();
		$out     = [];

		foreach ( $this->enrollments->get_for_user( $user_id ) as $enrollment ) {
			$out[] = [
				'course_id' => $enrollment->course_id,
				'title'     => (string) \get_the_title( $enrollment->course_id ),
				'permalink' => (string) \get_permalink( $enrollment->course_id ),
				'status'    => $enrollment->status,
				'percent'   => $this->progress->get_course_progress( $user_id, $enrollment->course_id )->percent,
			];
		}

		return new \WP_REST_Response( $out, 200 );
	}

	public function progress( \WP_REST_Request $request ): \WP_REST_Response {
		$course_id = (int) $request['id'];
		// Same guards the write routes get from ProgressService: a course the
		// caller cannot see is 404, one they are not enrolled in is 403 - never a
		// required-item count for somebody else's course (Task 33 review).
		if ( CoursePostType::CPT !== \get_post_type( $course_id ) || 'publish' !== \get_post_status( $course_id ) ) {
			return Routes::error_response( new \WP_Error( 'no_course', \__( 'Not found.', 'anchor-schema' ) ) );
		}
		if ( ! $this->enrollments->is_enrolled( \get_current_user_id(), $course_id ) ) {
			return Routes::error_response( new \WP_Error( 'not_enrolled', \__( 'You are not enrolled in this course.', 'anchor-schema' ) ) );
		}
		return new \WP_REST_Response(
			$this->progress->get_course_progress( \get_current_user_id(), $course_id )->to_array(),
			200
		);
	}

	public function start_lesson( \WP_REST_Request $request ): \WP_REST_Response {
		$progress = $this->progress->start_lesson(
			\get_current_user_id(),
			(int) $request['course_id'],
			(int) $request['id']
		);

		if ( \is_wp_error( $progress ) ) {
			// ProgressService::start_lesson()'s own publish guard - a
			// draft/private lesson is 404 `no_lesson`, never 403 `locked`
			// (PR36 round 3, Codex).
			return Routes::error_response( $progress );
		}

		if ( null === $progress ) {
			return Routes::error_response( new \WP_Error( 'locked', \__( 'That lesson is not available yet.', 'anchor-schema' ) ) );
		}

		return new \WP_REST_Response( $progress->to_array(), 200 );
	}

	public function complete_lesson( \WP_REST_Request $request ): \WP_REST_Response {
		$result = $this->progress->complete_lesson(
			\get_current_user_id(),
			(int) $request['course_id'],
			(int) $request['id']
		);

		if ( \is_wp_error( $result ) ) {
			return Routes::error_response( $result );
		}

		return new \WP_REST_Response( $result->to_array(), 200 );
	}

	public function certificates( \WP_REST_Request $request ): \WP_REST_Response {
		$out = [];
		foreach ( $this->certificates->for_user( \get_current_user_id() ) as $certificate ) {
			$out[] = \array_merge(
				$certificate->to_array(),
				[ 'course_title' => (string) \get_the_title( $certificate->course_id ) ]
			);
		}
		return new \WP_REST_Response( $out, 200 );
	}

	public function credits( \WP_REST_Request $request ): \WP_REST_Response {
		$user_id = \get_current_user_id();

		$rows = [];
		foreach ( $this->credits->for_user( $user_id ) as $credit ) {
			$rows[] = \array_merge(
				$credit->to_array(),
				[ 'course_title' => (string) \get_the_title( $credit->course_id ) ]
			);
		}

		return new \WP_REST_Response(
			[ 'credits' => $rows, 'total' => $this->credits->total_for_user( $user_id ) ],
			200
		);
	}
}
