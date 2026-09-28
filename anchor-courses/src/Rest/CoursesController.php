<?php
declare(strict_types=1);

namespace Anchor\Courses\Rest;

use Anchor\Courses\Admin\CourseEditor;
use Anchor\Courses\Content\CoursePostType;
use Anchor\Courses\Content\Curriculum;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Course catalogue endpoints (brief 14).
 *
 * These are marketing-side reads: titles, credits and the shape of the
 * curriculum. They never carry learner data, and the curriculum route never
 * carries quiz questions (brief 25).
 */
final class CoursesController {

	public function register_routes(): void {
		$id_arg = [ 'id' => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ] ];

		\register_rest_route(
			Routes::NAMESPACE,
			'/courses',
			[
				'methods'             => 'GET',
				'permission_callback' => [ Routes::class, 'public_read' ],
				'callback'            => [ $this, 'index' ],
				'args'                => [
					'per_page' => [ 'type' => 'integer', 'default' => 20, 'sanitize_callback' => 'absint' ],
					'page'     => [ 'type' => 'integer', 'default' => 1, 'sanitize_callback' => 'absint' ],
				],
			]
		);

		\register_rest_route(
			Routes::NAMESPACE,
			'/courses/(?P<id>\d+)',
			[
				'methods'             => 'GET',
				'permission_callback' => [ Routes::class, 'public_read' ],
				'callback'            => [ $this, 'read' ],
				'args'                => $id_arg,
			]
		);

		\register_rest_route(
			Routes::NAMESPACE,
			'/courses/(?P<id>\d+)/curriculum',
			[
				'methods'             => 'GET',
				'permission_callback' => [ Routes::class, 'public_read' ],
				'callback'            => [ $this, 'curriculum' ],
				'args'                => $id_arg,
			]
		);

		// No /enroll route, deliberately: access is a role, and roles are handed
		// out server-side (design spec 7). See the task notes.
	}

	public function index( \WP_REST_Request $request ): \WP_REST_Response {
		$courses = \get_posts(
			[
				'post_type'      => CoursePostType::CPT,
				'post_status'    => 'publish',
				'posts_per_page' => \max( 1, \min( 100, (int) $request['per_page'] ) ),
				'paged'          => \max( 1, (int) $request['page'] ),
				'orderby'        => 'title',
				'order'          => 'ASC',
			]
		);

		return new \WP_REST_Response( \array_map( [ $this, 'summary' ], $courses ), 200 );
	}

	public function read( \WP_REST_Request $request ): \WP_REST_Response {
		$course = \get_post( (int) $request['id'] );
		if ( ! $course instanceof \WP_Post || CoursePostType::CPT !== $course->post_type || 'publish' !== $course->post_status ) {
			return Routes::error_response( new \WP_Error( 'no_course', \__( 'Not found.', 'anchor-schema' ) ) );
		}

		return new \WP_REST_Response( $this->summary( $course ), 200 );
	}

	public function curriculum( \WP_REST_Request $request ): \WP_REST_Response {
		$course_id = (int) $request['id'];
		if ( CoursePostType::CPT !== \get_post_type( $course_id ) || 'publish' !== \get_post_status( $course_id ) ) {
			return Routes::error_response( new \WP_Error( 'no_course', \__( 'Not found.', 'anchor-schema' ) ) );
		}

		$modules = [];
		foreach ( Curriculum::get( $course_id ) as $module ) {
			$items = [];
			foreach ( $module['items'] as $item ) {
				// A draft lesson staged into a live course must not surface its
				// title publicly (Task 33 review); the front end skips it too.
				if ( 'publish' !== \get_post_status( (int) $item['id'] ) ) {
					continue;
				}
				// Titles and types only. Quiz CONTENT belongs to an attempt.
				$items[] = [
					'type'     => $item['type'],
					'id'       => $item['id'],
					'title'    => (string) \get_the_title( $item['id'] ),
					'required' => $item['required'],
				];
			}
			$modules[] = [
				'id'          => $module['id'],
				'title'       => $module['title'],
				'description' => $module['description'],
				'items'       => $items,
			];
		}

		return new \WP_REST_Response( [ 'course_id' => $course_id, 'modules' => $modules ], 200 );
	}

	private function summary( \WP_Post $course ): array {
		$id = (int) $course->ID;

		return [
			'id'               => $id,
			'title'            => (string) $course->post_title,
			'excerpt'          => (string) \get_the_excerpt( $course ),
			'permalink'        => (string) \get_permalink( $course ),
			'instructor'       => (string) CourseEditor::setting( $id, 'instructor' ),
			'duration'         => (string) CourseEditor::setting( $id, 'duration' ),
			'difficulty'       => (string) CourseEditor::setting( $id, 'difficulty' ),
			'ce_credits'       => (float) CourseEditor::setting( $id, 'ce_credits' ),
			'progression_mode' => (string) CourseEditor::setting( $id, 'progression_mode' ),
			// The same `publish` filter curriculum() applies (PR36 finding
			// f): otherwise a draft item staged into the curriculum inflates
			// the catalogue's count past what the curriculum route itself
			// ever lists, and its mere existence leaks through the number.
			'item_count'       => \count(
				\array_filter(
					Curriculum::items( $id ),
					static fn( array $i ): bool => 'publish' === \get_post_status( (int) $i['id'] )
				)
			),
		];
	}
}
