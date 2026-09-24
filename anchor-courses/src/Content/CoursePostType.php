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
				'show_in_menu'    => (bool) \apply_filters( 'anchor_courses_parent_menu', true ),
				'supports'        => [ 'title', 'editor', 'excerpt', 'thumbnail', 'revisions' ],
				'rewrite'         => [ 'slug' => 'courses', 'with_front' => false ],
				'capability_type' => [ 'anchor_course', 'anchor_courses' ],
				'map_meta_cap'    => true,
				'capabilities'    => [
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
