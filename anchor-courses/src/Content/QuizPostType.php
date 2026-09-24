<?php
declare(strict_types=1);

namespace Anchor\Courses\Content;

use Anchor\Courses\Support\Capabilities;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * The `anchor_quiz` post type (brief section 6.1).
 *
 * public => false on purpose: a quiz is never a standalone URL - it renders
 * inside a lesson or course through the quiz service, which enforces enrolment
 * and progression. A publicly queryable quiz post would be a way to read quiz
 * content around those checks.
 */
final class QuizPostType {

	public const CPT         = 'anchor_quiz';
	public const META_PREFIX = '_anchor_quiz_';
	public const META_KEYS   = [ 'settings', 'questions', 'required' ];

	public static function meta_key( string $key ): string {
		return self::META_PREFIX . $key;
	}

	public static function register(): void {
		$cap = Capabilities::cap( 'edit_quizzes' );

		\register_post_type(
			self::CPT,
			[
				'labels'              => [
					'name'          => \__( 'Quizzes', 'anchor-schema' ),
					'singular_name' => \__( 'Quiz', 'anchor-schema' ),
					'add_new_item'  => \__( 'Add New Quiz', 'anchor-schema' ),
					'edit_item'     => \__( 'Edit Quiz', 'anchor-schema' ),
					'menu_name'     => \__( 'Quizzes', 'anchor-schema' ),
				],
				'public'              => false,
				'show_ui'             => true,
				'show_in_rest'        => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'has_archive'         => false,
				'rewrite'             => false,
				'show_in_menu'        => \apply_filters( 'anchor_courses_parent_menu', true )
					? 'edit.php?post_type=' . CoursePostType::CPT
					: false,
				'supports'            => [ 'title', 'revisions' ],
				'capability_type'     => [ 'anchor_quiz', 'anchor_quizzes' ],
				'map_meta_cap'        => true,
				'capabilities'        => [
					'edit_posts'           => $cap,
					'edit_others_posts'    => $cap,
					'publish_posts'        => $cap,
					'delete_posts'         => $cap,
					'edit_published_posts' => $cap,
					'read_private_posts'   => $cap,
					'create_posts'         => $cap,
				],
			]
		);
	}
}
