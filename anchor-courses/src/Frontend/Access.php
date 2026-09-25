<?php
declare(strict_types=1);

namespace Anchor\Courses\Frontend;

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
}
