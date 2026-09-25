<?php
declare(strict_types=1);

namespace Anchor\Courses\Frontend;

use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Module;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * How does this visitor get access to this course?
 *
 * Exactly one answer, in one place, used by the course template, the course
 * list and anything else that needs to say something to a visitor who is not
 * enrolled. It is deliberately NOT an "Enrol" button: self-enrolment does not
 * exist (design spec 7), so the honest default is a sentence telling them how
 * to ask.
 *
 * An integration turns that sentence into a link by filtering
 * `anchor_courses_access_cta`. In this repo that is the WooCommerce adapter
 * (Task 38), which supplies the permalink of a product mapped to the course.
 * The core knows nothing about it, so a site with no store still renders
 * correctly - no `if ( class_exists( 'WooCommerce' ) )` branch anywhere here.
 */
final class Access {

	/**
	 * @return array{url:string,label:string,message:string}
	 */
	public static function cta( int $course_id, int $user_id = 0 ): array {
		/**
		 * The text shown when nothing sells or grants this course.
		 *
		 * @param string $message
		 * @param int    $course_id
		 */
		$message = (string) \apply_filters(
			'anchor_courses_no_access_message',
			\__( 'Ask us about access to this course.', 'anchor-schema' ),
			$course_id
		);

		$cta = [ 'url' => '', 'label' => '', 'message' => $message ];

		/**
		 * Filter the access call to action.
		 *
		 * Return a `url` + `label` to render a link instead of the message.
		 * Anything that can grant `anchor_course_{id}` - a product, a form, a
		 * partner portal - belongs here.
		 *
		 * @param array{url:string,label:string,message:string} $cta
		 * @param int                                           $course_id
		 * @param int                                           $user_id
		 */
		$cta = (array) \apply_filters( 'anchor_courses_access_cta', $cta, $course_id, $user_id );

		return [
			'url'     => (string) ( $cta['url'] ?? '' ),
			'label'   => (string) ( $cta['label'] ?? '' ),
			'message' => (string) ( $cta['message'] ?? '' ),
		];
	}

	/**
	 * The query arg every lesson link the templates emit carries: the course
	 * the learner came from (final review I2). A lesson may sit in more than
	 * one course, and this is what tells the page which one it is being read
	 * in.
	 */
	public const COURSE_ARG = 'course';

	/** Why a lesson body is withheld (lesson_denial()). */
	public const DENIED_NOT_ENROLLED = 'not_enrolled';
	public const DENIED_LOCKED       = 'locked';

	/** A lesson's permalink, carrying the course it is being read in. */
	public static function lesson_url( int $lesson_id, int $course_id ): string {
		$url = (string) \get_permalink( $lesson_id );
		return $course_id > 0 ? \add_query_arg( self::COURSE_ARG, $course_id, $url ) : $url;
	}

	/**
	 * Which course a learner is reading this lesson in, or 0 when no
	 * published course lists it.
	 *
	 * The ONE resolver every lesson surface uses (ContentGuard, render_lesson,
	 * can_view_lesson), in this order (final review I2 - reverses the T7
	 * "lowest id wins" rule for access decisions):
	 *
	 *   1. the explicit course on the URL (COURSE_ARG), if that published
	 *      course really lists the lesson - a value naming anything else is
	 *      ignored, never trusted;
	 *   2. the lowest-id published course the user is enrolled in;
	 *   3. the lowest-id published course.
	 *
	 * Draft/pending/private courses are never candidates
	 * (Curriculum::courses_for_item()).
	 */
	public static function course_for_lesson( int $lesson_id, int $user_id = 0 ): int {
		$courses = Curriculum::courses_for_item( $lesson_id, 'lesson' );
		if ( [] === $courses ) {
			return 0;
		}

		// phpcs:ignore WordPress.Security.NonceVerification -- a read that only selects among courses already listing this lesson.
		$requested = \absint( \wp_unslash( $_GET[ self::COURSE_ARG ] ?? 0 ) );
		$user_id   = $user_id > 0 ? $user_id : (int) \get_current_user_id();
		$module    = Module::instance();
		$enrolled  = static fn( int $course_id ): bool => $user_id > 0
			&& $module instanceof Module
			&& $module->enrollments->is_enrolled( $user_id, $course_id );

		// The link's course wins when it lists the lesson AND the visitor is
		// enrolled in it (or is nobody in particular); a learner who is only
		// enrolled elsewhere falls through to their own course rather than
		// being shown another course's call to action.
		if ( $requested > 0 && \in_array( $requested, $courses, true ) && ( 0 === $user_id || $enrolled( $requested ) ) ) {
			return $requested;
		}

		if ( $user_id > 0 && $module instanceof Module ) {
			foreach ( $courses as $course_id ) {
				if ( $enrolled( $course_id ) ) {
					return $course_id;
				}
			}
		}

		return $courses[0];
	}

	/**
	 * Whether $user_id may see a lesson's real body right now.
	 *
	 * The single authority `Frontend\ContentGuard` calls to enforce the
	 * lesson gate on `the_content`/`the_excerpt`/`get_the_excerpt` - so a
	 * feed, a search excerpt or a stray theme loop over `anchor_lesson`
	 * refuses on exactly the same terms as the page itself.
	 *
	 * @param int $lesson_id
	 * @param int $user_id   Defaults to the current user.
	 */
	public static function can_view_lesson( int $lesson_id, int $user_id = 0 ): bool {
		return '' === self::lesson_denial( $lesson_id, $user_id );
	}

	/**
	 * Why $user_id may NOT see a lesson's body: '' (they may),
	 * DENIED_NOT_ENROLLED or DENIED_LOCKED.
	 *
	 * Anyone who can edit the lesson (its author, an editor, an admin)
	 * always may - that is the preview (final review I4). Everyone else goes
	 * through `ProgressService::is_item_available()` against the course
	 * course_for_lesson() resolves, the same authority the templates and
	 * `Shortcodes::render_lesson()` consult, including its
	 * `anchor_courses_can_access_lesson` filter. A refusal is "locked" only
	 * for somebody actually enrolled in that course; for anyone else it is
	 * "not enrolled", so nobody is told to finish lessons they cannot open.
	 */
	public static function lesson_denial( int $lesson_id, int $user_id = 0 ): string {
		$user_id = $user_id > 0 ? $user_id : (int) \get_current_user_id();
		if ( $user_id <= 0 ) {
			return self::DENIED_NOT_ENROLLED;
		}

		if ( \user_can( $user_id, 'edit_post', $lesson_id ) ) {
			return '';
		}

		$module    = Module::instance();
		$course_id = self::course_for_lesson( $lesson_id, $user_id );
		if ( ! $module instanceof Module || $course_id <= 0 ) {
			return self::DENIED_NOT_ENROLLED;
		}

		if ( $module->progress->is_item_available( $user_id, $course_id, $lesson_id, 'lesson' ) ) {
			return '';
		}

		return $module->enrollments->is_enrolled( $user_id, $course_id ) ? self::DENIED_LOCKED : self::DENIED_NOT_ENROLLED;
	}
}
