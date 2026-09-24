<?php
declare(strict_types=1);

namespace Anchor\Courses\Frontend;

use Anchor\Courses\Services\ProgressService;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Form posts from the front end.
 *
 * admin-post.php rather than admin-ajax.php or REST, so "Mark complete" works
 * with JavaScript switched off. The handler verifies login, nonce and service
 * rules, then redirects with a notice code - never a raw message, so nothing
 * user-supplied reaches the page.
 *
 * "Mark complete" is the ONLY thing a learner may post. There is no enrol
 * action: access arrives as a role, granted by a purchase or an admin.
 */
final class Actions {

	public const NONCE_COMPLETE = 'anchor_courses_complete';

	/** Notice codes this module will render. Anything else is ignored. */
	public const NOTICES = [
		'completed', 'bad_nonce', 'login_required', 'not_enrolled',
		'locked', 'quiz_required', 'not_in_course', 'error',
	];

	public function __construct( private ?ProgressService $progress = null ) {
		$this->progress = $progress ?? new ProgressService();

		\add_action( 'admin_post_anchor_courses_complete_lesson', [ $this, 'handle_complete_lesson' ] );
		\add_action( 'admin_post_nopriv_anchor_courses_complete_lesson', [ $this, 'handle_complete_lesson' ] );
	}

	public static function complete_url( int $course_id, int $lesson_id ): string {
		return \add_query_arg(
			[
				'action'    => 'anchor_courses_complete_lesson',
				'course_id' => $course_id,
				'lesson_id' => $lesson_id,
			],
			\admin_url( 'admin-post.php' )
		);
	}

	/** The notice code on the current request, or ''. */
	public static function notice(): string {
		$code = \sanitize_key( \wp_unslash( (string) ( $_GET['anchor_courses_notice'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		return \in_array( $code, self::NOTICES, true ) ? $code : '';
	}

	/** Human text for a notice code. */
	public static function notice_text( string $code ): string {
		$messages = [
			'completed'      => \__( 'Lesson marked complete.', 'anchor-schema' ),
			'bad_nonce'      => \__( 'That link expired. Please try again.', 'anchor-schema' ),
			'login_required' => \__( 'Please sign in first.', 'anchor-schema' ),
			'not_enrolled'   => \__( 'You do not have access to this course.', 'anchor-schema' ),
			'locked'         => \__( 'Finish the earlier lessons first.', 'anchor-schema' ),
			'quiz_required'  => \__( 'Pass this lesson\'s quiz to complete it.', 'anchor-schema' ),
			'not_in_course'  => \__( 'That lesson is not part of this course.', 'anchor-schema' ),
			'error'          => \__( 'Something went wrong. Please try again.', 'anchor-schema' ),
		];
		return $messages[ $code ] ?? '';
	}

	public function handle_complete_lesson(): void {
		$course_id = \absint( $_POST['course_id'] ?? $_GET['course_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		$lesson_id = \absint( $_POST['lesson_id'] ?? $_GET['lesson_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification

		if ( ! \is_user_logged_in() ) {
			$this->redirect( 'login_required', $lesson_id );
		}

		$nonce = \sanitize_text_field( \wp_unslash( (string) ( $_POST['_wpnonce'] ?? $_GET['_wpnonce'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! \wp_verify_nonce( $nonce, self::NONCE_COMPLETE . '_' . $lesson_id ) ) {
			$this->redirect( 'bad_nonce', $lesson_id );
		}

		$result = $this->progress->complete_lesson( \get_current_user_id(), $course_id, $lesson_id );

		$this->redirect(
			\is_wp_error( $result ) ? (string) $result->get_error_code() : 'completed',
			$lesson_id
		);
	}

	/** Redirect back with a notice CODE (never a message) and stop. */
	private function redirect( string $code, int $fallback_post_id ): void {
		if ( ! \in_array( $code, self::NOTICES, true ) ) {
			$code = 'error';
		}

		$requested = \wp_unslash( (string) ( $_POST['_redirect'] ?? $_GET['_redirect'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$target    = '' !== $requested
			? \wp_validate_redirect( $requested, (string) \get_permalink( $fallback_post_id ) )
			: (string) \get_permalink( $fallback_post_id );

		if ( '' === $target ) {
			$target = \home_url( '/' );
		}

		\wp_safe_redirect( \add_query_arg( 'anchor_courses_notice', $code, $target ) );
		exit;
	}
}
