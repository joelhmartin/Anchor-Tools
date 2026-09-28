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
			return Routes::error_response( new \WP_Error( 'no_course', \__( 'Not found.', 'anchor-schema' ) ) );
		}

		$per_page = \max( 1, \min( 200, (int) $request['per_page'] ) );
		$offset   = ( \max( 1, (int) $request['page'] ) - 1 ) * $per_page;

		return new \WP_REST_Response( LearnerReports::rows( $course_id, $per_page, $offset ), 200 );
	}

	/** Every course one user is enrolled in, same row shape as the profile-screen Courses block. */
	public function user_courses( \WP_REST_Request $request ): \WP_REST_Response {
		$user_id = (int) $request['id'];
		if ( ! \get_userdata( $user_id ) ) {
			return Routes::error_response( new \WP_Error( 'no_user', \__( 'Not found.', 'anchor-schema' ) ) );
		}

		return new \WP_REST_Response( LearnerReports::user_rows( $user_id ), 200 );
	}

	/**
	 * Completed enrolments, optionally scoped to a course and/or date range.
	 *
	 * `total` is a COUNT over every match; `rows` stops at REPORT_LIMIT and
	 * `truncated` says when it did (final review I5).
	 */
	public function completions( \WP_REST_Request $request ): \WP_REST_Response {
		$range = self::range( $request );
		if ( $range instanceof \WP_Error ) {
			return Routes::error_response( $range );
		}
		[ $course_id, $from, $to ] = $range;

		$enrollments = EnrollmentRepository::completions( $course_id, $from, $to, self::REPORT_LIMIT );
		$total       = EnrollmentRepository::count_completions( $course_id, $from, $to );

		self::prime_caches( \array_map( static fn( $e ) => (int) $e->user_id, $enrollments ), \array_map( static fn( $e ) => (int) $e->course_id, $enrollments ) );

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

		return new \WP_REST_Response(
			[ 'total' => $total, 'truncated' => $total > \count( $rows ), 'rows' => $rows ],
			200
		);
	}

	/**
	 * Awarded CE credits, optionally scoped to a course and/or date range.
	 * `total` and `total_credits` cover every match, not just the returned
	 * rows; `truncated` says when REPORT_LIMIT cut the rows short.
	 */
	public function credits( \WP_REST_Request $request ): \WP_REST_Response {
		$range = self::range( $request );
		if ( $range instanceof \WP_Error ) {
			return Routes::error_response( $range );
		}
		[ $course_id, $from, $to ] = $range;

		$credits = CreditRepository::report( $course_id, $from, $to, self::REPORT_LIMIT );
		$totals  = CreditRepository::report_totals( $course_id, $from, $to );

		self::prime_caches( \array_map( static fn( $c ) => (int) $c->user_id, $credits ), \array_map( static fn( $c ) => (int) $c->course_id, $credits ) );

		$rows = [];
		foreach ( $credits as $credit ) {
			$user   = \get_userdata( $credit->user_id );
			$rows[] = \array_merge(
				$credit->to_array(),
				[
					'display_name' => $user ? (string) $user->display_name : '',
					'user_email'   => $user ? (string) $user->user_email : '',
					'course_title' => (string) \get_the_title( $credit->course_id ),
				]
			);
		}

		return new \WP_REST_Response(
			[
				'total'         => $totals['count'],
				'total_credits' => \round( $totals['credits'], 2 ),
				'truncated'     => $totals['count'] > \count( $rows ),
				'rows'          => $rows,
			],
			200
		);
	}

	/**
	 * The report routes' shared filters, with `from`/`to` validated as real
	 * `Y-m-d` dates ('' = unbounded).
	 *
	 * @return array{0:int,1:string,2:string}|\WP_Error
	 */
	private static function range( \WP_REST_Request $request ) {
		$dates = [];
		foreach ( [ 'from', 'to' ] as $key ) {
			$value = \trim( (string) $request[ $key ] );
			if ( '' !== $value ) {
				$parsed = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
				if ( ! $parsed || $parsed->format( 'Y-m-d' ) !== $value ) {
					return new \WP_Error(
						'invalid_date',
						/* translators: %s: the parameter name, from or to. */
						\sprintf( \__( '%s must be a date in YYYY-MM-DD form.', 'anchor-schema' ), $key )
					);
				}
			}
			$dates[] = $value;
		}

		return [ (int) $request['course_id'], $dates[0], $dates[1] ];
	}

	/**
	 * One query for the users and one for the courses a report row set names,
	 * so the per-row get_userdata()/get_the_title() calls are cache hits
	 * (Task 34 review: the module's house rule is no N+1 in reports).
	 *
	 * @param int[] $user_ids
	 * @param int[] $course_ids
	 */
	private static function prime_caches( array $user_ids, array $course_ids ): void {
		$user_ids   = \array_values( \array_unique( \array_filter( $user_ids ) ) );
		$course_ids = \array_values( \array_unique( \array_filter( $course_ids ) ) );
		if ( [] !== $user_ids ) {
			\cache_users( $user_ids );
		}
		if ( [] !== $course_ids ) {
			\_prime_post_caches( $course_ids, false, false );
		}
	}
}
