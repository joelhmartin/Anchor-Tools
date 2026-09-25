<?php
declare(strict_types=1);

namespace Anchor\Courses\Content;

use Anchor\Courses\Support\Capabilities;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * The `anchor_lesson` post type (brief sections 6.1, 9; design spec 3.3).
 *
 * `show_in_rest => false` and `exclude_from_search => true` (Task 18 review
 * fix round, ruling (a)): a lesson body - video/live-session markup included
 * - must never ride core REST (`GET /wp-json/wp/v2/anchor_lesson/{id}` has no
 * permission callback of its own and would hand it to anyone; Task 26 ships
 * its own permission-checked routes) or a search excerpt. `public` and
 * `publicly_queryable` stay true so the single template still resolves for a
 * logged-out visitor - it renders the access notice, not the body, via
 * Frontend\ContentGuard on `the_content`/`the_excerpt`.
 */
final class LessonPostType {

	public const CPT         = 'anchor_lesson';
	public const META_PREFIX = '_anchor_lesson_';

	public const META_KEYS = [
		'completion_mode', 'required', 'quiz_id', 'type',
		'event_id', 'session_index', 'require_prior_items',
	];

	/** Brief section 9 Phase 1 modes. `external_event` is explicitly future. */
	public const COMPLETION_MODES = [ 'manual', 'view', 'quiz_pass' ];

	/** Design spec section 3.3. */
	public const TYPES = [ 'content', 'live_session' ];

	public static function meta_key( string $key ): string {
		return self::META_PREFIX . $key;
	}

	public static function register(): void {
		$cap = Capabilities::cap( 'edit_lessons' );

		\register_post_type(
			self::CPT,
			[
				'labels'          => [
					'name'          => \__( 'Lessons', 'anchor-schema' ),
					'singular_name' => \__( 'Lesson', 'anchor-schema' ),
					'add_new_item'  => \__( 'Add New Lesson', 'anchor-schema' ),
					'edit_item'     => \__( 'Edit Lesson', 'anchor-schema' ),
					'menu_name'     => \__( 'Lessons', 'anchor-schema' ),
				],
				'public'              => true,
				'publicly_queryable'  => true,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'has_archive'         => false,
				'show_in_menu'        => CoursePostType::parent_menu(),
				'supports'            => [ 'title', 'editor', 'excerpt', 'thumbnail', 'revisions' ],
				'rewrite'             => [ 'slug' => 'lessons', 'with_front' => false ],
				'capability_type'     => [ 'anchor_lesson', 'anchor_lessons' ],
				'map_meta_cap'        => true,
				'capabilities'        => Capabilities::post_type_capabilities( $cap ),
			]
		);
	}
}
