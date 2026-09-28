<?php
declare(strict_types=1);

namespace Anchor\Courses\Rest;

use Anchor\Courses\Admin\LearnerReports;
use Anchor\Courses\Content\CoursePostType;
use Anchor\Courses\Database\CreditRepository;
use Anchor\Courses\Database\EnrollmentRepository;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Reporting endpoints for staff (brief 14, Task 34).
 *
 * These expose learner PII, so each route names the capability it needs:
 * `reports` for rosters and completions, `credits` for the CE ledger - never
 * a single blanket "is admin" check. The roster and per-user routes are thin
 * shells over `Admin\LearnerReports::rows()`/`user_rows()`, the SAME
 * batch-loaded row builders the Learners metabox and the user-profile
 * Courses block already use - one row shape, three callers, not a second
 * query path invented for REST. `completions()`/`credits()` read through
 * `EnrollmentRepository`/`CreditRepository`, the one place each table's SQL
 * lives; neither route writes anything - every enrol/revoke/complete/reset
 * action already has its door (`admin-post.php?action=anchor_courses_*`,
 * see COURSES.md's "Other endpoints" table) and this controller does not
 * open a second one over REST.
 */
final class AdminController {

	/** Hard cap on a report response - there is no page param here (ruling: pagination bounded). */
	private const REPORT_LIMIT = 1000;

	public function register_routes(): void {
		$reports = Routes::require_cap( 'reports' );
		$credits = Routes::require_cap( 'credits' );

		$range_args = [
			'course_id' => [ 'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint' ],
			'from'      => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
			'to'        => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
		];

		\register_rest_route(
			Routes::NAMESPACE,
			'/admin/courses/(?P<id>\d+)/learners',
			[
				'methods'             => 'GET',
				'permission_callback' => $reports,
				'callback'            => [ $this, 'learners' ],
				'args'                => [
					'id'       => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ],
					'per_page' => [ 'type' => 'integer', 'default' => 50, 'sanitize_callback' => 'absint' ],
					'page'     => [ 'type' => 'integer', 'default' => 1, 'sanitize_callback' => 'absint' ],
				],
			]
		);

		\register_rest_route(
			Routes::NAMESPACE,
			'/admin/users/(?P<id>\d+)/courses',
			[
				'methods'             => 'GET',
				'permission_callback' => $reports,
				'callback'            => [ $this, 'user_courses' ],
				'args'                => [ 'id' => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ] ],
			]
		);

		\register_rest_route(
			Routes::NAMESPACE,
			'/admin/reports/completions',
			[
				'methods'             => 'GET',
				'permission_callback' => $reports,
				'callback'            => [ $this, 'completions' ],
				'args'                => $range_args,
			]
		);

		\register_rest_route(
			Routes::NAMESPACE,
			'/admin/reports/credits',
			[
				'methods'             => 'GET',
				'permission_callback' => $credits,
				'callback'            => [ $this, 'credits' ],
				'args'                => $range_args,
			]
		);
	}

	/** The Learners tab's roster, batch-loaded exactly as the metabox renders it. */
	public function learners( \WP_REST_Request $request ): \WP_REST_Response {
		$course_id = (int) $request['id'];
		if ( CoursePostType::CPT !== \get_post_type( $course_id ) ) {
			return new \WP_REST_Response( [ 'code' => 'no_course', 'message' => \__( 'Not found.', 'anchor-schema' ) ], 404 );
		}

		$per_page = \max( 1, \min( 200, (int) $request['per_page'] ) );
		$offset   = ( \max( 1, (int) $request['page'] ) - 1 ) * $per_page;

		return new \WP_REST_Response( LearnerReports::rows( $course_id, $per_page, $offset ), 200 );
	}

	/** Every course one user is enrolled in, same row shape as the profile-screen Courses block. */
	public function user_courses( \WP_REST_Request $request ): \WP_REST_Response {
		$user_id = (int) $request['id'];
		if ( ! \get_userdata( $user_id ) ) {
			return new \WP_REST_Response( [ 'code' => 'no_user', 'message' => \__( 'Not found.', 'anchor-schema' ) ], 404 );
		}

		return new \WP_REST_Response( LearnerReports::user_rows( $user_id ), 200 );
	}

	/** Completed enrolments, optionally scoped to a course and/or date range. */
	public function completions( \WP_REST_Request $request ): \WP_REST_Response {
		$enrollments = EnrollmentRepository::completions(
			(int) $request['course_id'],
			(string) $request['from'],
			(string) $request['to'],
			self::REPORT_LIMIT
		);

		$rows = [];
		foreach ( $enrollments as $enrollment ) {
			$user   = \get_userdata( $enrollment->user_id );
			$rows[] = [
				'user_id'      => $enrollment->user_id,
				'display_name' => $user ? (string) $user->display_name : '',
				'course_id'    => $enrollment->course_id,
				'course_title' => (string) \get_the_title( $enrollment->course_id ),
				'completed_at' => (string) ( $enrollment->completed_at ?? '' ),
			];
		}

		return new \WP_REST_Response( [ 'total' => \count( $rows ), 'rows' => $rows ], 200 );
	}

	/** Awarded CE credits, optionally scoped to a course and/or date range. */
	public function credits( \WP_REST_Request $request ): \WP_REST_Response {
		$credits = CreditRepository::report(
			(int) $request['course_id'],
			(string) $request['from'],
			(string) $request['to'],
			self::REPORT_LIMIT
		);

		$rows  = [];
		$total = 0.0;

		foreach ( $credits as $credit ) {
			$user   = \get_userdata( $credit->user_id );
			$total += $credit->credits;
			$rows[] = \array_merge(
				$credit->to_array(),
				[
					'display_name' => $user ? (string) $user->display_name : '',
					'user_email'   => $user ? (string) $user->user_email : '',
					'course_title' => (string) \get_the_title( $credit->course_id ),
				]
			);
		}

		return new \WP_REST_Response( [ 'total_credits' => \round( $total, 2 ), 'rows' => $rows ], 200 );
	}
}
