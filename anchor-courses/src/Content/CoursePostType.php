<?php
declare(strict_types=1);

namespace Anchor\Courses\Content;

use Anchor\Courses\Support\Capabilities;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** The `anchor_course` post type (brief section 6.1) and its meta vocabulary (6.3). */
final class CoursePostType {

	public const CPT         = 'anchor_course';
	public const META_PREFIX = '_anchor_course_';

	/** Authored settings, all stored under META_PREFIX. Order is the metabox order. */
	public const META_KEYS = [
		'duration', 'difficulty', 'instructor', 'ce_credits', 'ce_type', 'ce_provider_name',
		'ce_provider_number', 'ce_expires_days',
		'prerequisites', 'completion_mode', 'completion_percentage', 'progression_mode',
		'certificate_enabled', 'certificate_template', 'expiration_days',
		'available_from', 'available_until', 'curriculum',
	];

	/**
	 * There is deliberately NO access-type key (design spec 1, row
	 * "Course access type"). A course has exactly one access rule: hold
	 * `anchor_course_{id}` or you are not enrolled. Nothing to configure,
	 * nothing to get wrong, and no second answer to the same question.
	 */
	public const PROGRESSION_MODES = [ 'free', 'sequential' ];
	public const COMPLETION_MODES  = [ 'all_required_items', 'minimum_percentage', 'manual' ];

	public static function meta_key( string $key ): string {
		return self::META_PREFIX . $key;
	}

	/**
	 * Whether the courses admin menu tree is shown at all, filtered by
	 * `anchor_courses_parent_menu`. Lessons and quizzes nest under this CPT's
	 * own admin menu page, so LessonPostType/QuizPostType read this same
	 * filter through {@see parent_menu()} rather than re-declaring it.
	 */
	private static function show_parent_menu(): bool {
		return (bool) \apply_filters( 'anchor_courses_parent_menu', true );
	}

	/** The admin menu page lessons/quizzes nest under, or false to hide them from the menu entirely. */
	public static function parent_menu(): string|false {
		return self::show_parent_menu() ? 'edit.php?post_type=' . self::CPT : false;
	}

	public static function register(): void {
		$cap = Capabilities::cap( 'edit_courses' );

		\register_post_type(
			self::CPT,
			[
				'labels'          => [
					'name'          => \__( 'Courses', 'anchor-schema' ),
					'singular_name' => \__( 'Course', 'anchor-schema' ),
					'add_new_item'  => \__( 'Add New Course', 'anchor-schema' ),
					'edit_item'     => \__( 'Edit Course', 'anchor-schema' ),
					'menu_name'     => \__( 'Courses', 'anchor-schema' ),
				],
				'public'          => true,
				'show_in_rest'    => true,
				'has_archive'     => true,
				'menu_icon'       => 'dashicons-welcome-learn-more',
				'menu_position'   => 26,
				'show_in_menu'    => self::show_parent_menu(),
				'supports'        => [ 'title', 'editor', 'excerpt', 'thumbnail', 'revisions' ],
				'rewrite'         => [ 'slug' => 'courses', 'with_front' => false ],
				'capability_type' => [ 'anchor_course', 'anchor_courses' ],
				'map_meta_cap'    => true,
				'capabilities'    => Capabilities::post_type_capabilities( $cap ),
			]
		);
	}
}
