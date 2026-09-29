<?php
declare(strict_types=1);

namespace Anchor\Courses\Frontend;

use Anchor\Courses\Content\CoursePostType;
use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Content\QuizPostType;
use Anchor\Courses\Module;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * The quiz step: a quiz opened inside its course, at
 * /courses/{course-slug}/quiz/{quiz-slug}/ (Access::quiz_url()).
 *
 * A quiz has no URL of its own (QuizPostType is not publicly queryable, on
 * purpose), so the route hangs off the COURSE: the main query is the course
 * singular, and QUERY_VAR names the quiz. That keeps the course context in
 * the path, so a quiz shared by two courses has one step in each and is
 * always evaluated against the course in the URL, and nothing here can open
 * a quiz outside a course.
 *
 * This class only routes: it registers the rule and the query var, resolves
 * the quiz (404 when the course does not list it), sends a learner enrolled
 * only in another course that lists the quiz to that course's step (the
 * lesson resolver's rule, Access::course_for_item()), and tells Templates to
 * draw templates/single-quiz.php. Access and progression are decided where
 * a lesson's are, in Shortcodes::render_quiz_step().
 */
final class QuizStep {

	/** The query var naming the quiz on a course URL. */
	public const QUERY_VAR = 'anchor_quiz_step';

	/** The path segment between the course and the quiz slug. */
	public const PATH = 'quiz';

	public function __construct() {
		// After the post types register (init, 10), so the course's own
		// rewrite slug is known.
		\add_action( 'init', [ $this, 'add_rewrite' ], 11 );
		\add_filter( 'query_vars', [ $this, 'register_query_var' ] );
		\add_action( 'template_redirect', [ $this, 'maybe_redirect' ], 5 );
	}

	/** The course post type's rewrite slug ("courses" unless filtered). */
	public static function course_base(): string {
		$type = \get_post_type_object( CoursePostType::CPT );
		$slug = $type && \is_array( $type->rewrite ) ? (string) ( $type->rewrite['slug'] ?? '' ) : '';
		return '' !== $slug ? \trim( $slug, '/' ) : 'courses';
	}

	public function add_rewrite(): void {
		\add_rewrite_rule(
			'^' . \preg_quote( self::course_base(), '#' ) . '/([^/]+)/' . self::PATH . '/([^/]+)/?$',
			'index.php?' . CoursePostType::CPT . '=$matches[1]&' . self::QUERY_VAR . '=$matches[2]',
			'top'
		);
	}

	public function register_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/** The path segment for a quiz: its slug, or its id before it has one. */
	public static function slug( int $quiz_id ): string {
		$slug = (string) \get_post_field( 'post_name', $quiz_id );
		return '' !== $slug ? $slug : (string) $quiz_id;
	}

	/** The quiz a slug (or an id) names, or 0. */
	public static function quiz_for( string $slug ): int {
		$slug = \sanitize_title( \rawurldecode( $slug ) );
		if ( '' === $slug ) {
			return 0;
		}
		$found = \get_posts(
			[
				'post_type'        => QuizPostType::CPT,
				'name'             => $slug,
				'post_status'      => 'any',
				'fields'           => 'ids',
				'posts_per_page'   => 1,
				'no_found_rows'    => true,
				'suppress_filters' => true,
			]
		);
		if ( [] !== $found ) {
			return (int) $found[0];
		}
		if ( \ctype_digit( $slug ) && QuizPostType::CPT === \get_post_type( (int) $slug ) ) {
			return (int) $slug;
		}
		return 0;
	}

	/**
	 * The quiz this request is a step for, or 0: the course singular with
	 * QUERY_VAR naming a quiz that course lists.
	 */
	public static function current_quiz(): int {
		if ( ! \is_singular( CoursePostType::CPT ) ) {
			return 0;
		}
		$slug = (string) \get_query_var( self::QUERY_VAR );
		if ( '' === $slug ) {
			return 0;
		}
		$quiz_id = self::quiz_for( $slug );
		return $quiz_id > 0 && Curriculum::contains( (int) \get_queried_object_id(), $quiz_id, 'quiz' ) ? $quiz_id : 0;
	}

	/**
	 * Before the template: a step for a quiz this course does not list is a
	 * 404, and a learner enrolled only in another course that lists the quiz
	 * goes to that course's step (the same rule a lesson link follows).
	 */
	public function maybe_redirect(): void {
		if ( ! \is_singular( CoursePostType::CPT ) || '' === (string) \get_query_var( self::QUERY_VAR ) ) {
			return;
		}

		$course_id = (int) \get_queried_object_id();
		$quiz_id   = self::current_quiz();
		if ( $quiz_id <= 0 ) {
			global $wp_query;
			$wp_query->set_404();
			\status_header( 404 );
			\nocache_headers();
			return;
		}

		// Only ever towards a course this learner is enrolled in: a visitor
		// enrolled nowhere stays on the course they asked for, with its call
		// to action.
		$user_id  = (int) \get_current_user_id();
		$resolved = Access::course_for_item( $quiz_id, 'quiz', $user_id, $course_id );
		$module   = Module::instance();
		if ( $resolved > 0 && $resolved !== $course_id && $module instanceof Module && $module->enrollments->is_enrolled( $user_id, $resolved ) ) {
			\wp_safe_redirect( Access::quiz_url( $quiz_id, $resolved ) );
			exit;
		}
	}
}
