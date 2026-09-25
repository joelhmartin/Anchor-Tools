<?php
declare(strict_types=1);

namespace Anchor\Courses\Frontend;

use Anchor\Courses\Content\LessonPostType;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * The one place an `anchor_lesson` body is withheld from a visitor who has
 * not earned it (Task 18 review fix round, ruling (b) - CRITICAL: the CPT
 * was public + REST-exposed with no permission layer, so the body rode out
 * through core REST, feeds and search excerpts regardless of the template's
 * own `$available` check).
 *
 * `LessonPostType::register()` now keeps `show_in_rest => false` and
 * `exclude_from_search => true`, but the post type stays `public` and
 * `publicly_queryable` so its single template still resolves for a
 * logged-out visitor - it must render the access notice, not the body. This
 * class is what makes that true everywhere the body could otherwise leak:
 * hooking `the_content`, `the_excerpt` and `get_the_excerpt` means a feed,
 * a stray theme loop, or `templates/lesson.php` all get the same answer from
 * the same call ({@see Access::can_view_lesson()}), because `lesson.php`
 * renders its body through `apply_filters( 'the_content', ... )` rather than
 * a second `if ( ! $available )` branch of its own.
 */
final class ContentGuard {

	/**
	 * Re-entrancy guard. `get_the_excerpt()` falls back to `wp_trim_excerpt()`
	 * when no manual excerpt is set, and that function runs the post content
	 * back through the `the_content` filter internally - so a lesson with no
	 * manual excerpt can run this guard twice for one call. The flag makes
	 * the inner pass a no-op instead of re-entering the access check (which
	 * would be harmless here, but the brief asks for the guard explicitly).
	 */
	private static bool $active = false;

	public static function register(): void {
		\add_filter( 'the_content', [ self::class, 'filter_content' ] );
		\add_filter( 'the_excerpt', [ self::class, 'filter_excerpt' ] );
		\add_filter( 'get_the_excerpt', [ self::class, 'filter_excerpt' ], 10, 2 );
	}

	public static function filter_content( string $content ): string {
		return self::guard( $content, null );
	}

	public static function filter_excerpt( string $excerpt, ?\WP_Post $post = null ): string {
		return self::guard( $excerpt, $post );
	}

	private static function guard( string $output, ?\WP_Post $post ): string {
		if ( self::$active ) {
			return $output;
		}

		$post = $post instanceof \WP_Post ? $post : \get_post();
		if ( ! $post instanceof \WP_Post || LessonPostType::CPT !== $post->post_type ) {
			return $output;
		}

		if ( Access::can_view_lesson( (int) $post->ID ) ) {
			return $output;
		}

		self::$active = true;
		$notice        = self::notice();
		self::$active = false;

		return $notice;
	}

	/** Same wording the template used before this guard existed. */
	private static function notice(): string {
		return '<p class="anchor-courses-notice">'
			. \esc_html__( 'Finish the earlier lessons to unlock this one.', 'anchor-schema' )
			. '</p>';
	}
}
