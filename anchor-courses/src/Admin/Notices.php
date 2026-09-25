<?php
declare(strict_types=1);

namespace Anchor\Courses\Admin;

use Anchor\Courses\Content\CoursePostType;
use Anchor\Courses\Support\Capabilities;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * One shared admin-notice renderer for every `anchor_courses_admin_notice`
 * redirect code this module produces (Task 31 ruling, from the Task 21
 * review's GAP FOUND note).
 *
 * Before this class existed, every `admin_post_*` handler in the module
 * redirected with `add_query_arg( 'anchor_courses_admin_notice', $code, $target )`
 * and stopped - and nothing ever read the arg back, on any screen
 * (Admin\CourseEditor's delete-role handler, Admin\LearnerReports' add/revoke
 * handlers, and this task's own Admin\EnrollmentManager). One registry, one
 * renderer, so a code means the same thing wherever it was redirected from,
 * and a handler that wants its own code calls register() rather than
 * inventing its own admin_notices hook.
 *
 * CourseEditor's and LearnerReports' codes are registered from here;
 * EnrollmentManager registers its own from its constructor. All three
 * handlers authorise and redirect through the two helpers below
 * (authorisation_error()/authorise() and redirect() - final review I10),
 * so the nonce -> capability -> redirect-with-code sequence exists once.
 */
final class Notices {

	/** The query arg every redirect carries its notice code in. */
	public const QUERY_ARG = 'anchor_courses_admin_notice';

	public const TYPE_SUCCESS = 'success';
	public const TYPE_ERROR   = 'error';

	/**
	 * The admin screens this module's redirects actually land on: the course
	 * edit screen (id = the CPT slug itself - see WP_Screen::get()'s `post`
	 * case), and the two user-profile screens (own profile / editing someone
	 * else). A code riding on a URL that ends up somewhere else (copy-pasted,
	 * or simply another admin page) prints nothing there.
	 *
	 * @var string[]
	 */
	private const ALLOWED_SCREENS = [ CoursePostType::CPT, 'profile', 'user-edit' ];

	/** @var array<string,array{type:string,message:string}> */
	private static array $registry = [];

	private static bool $defaults_registered = false;

	public function __construct() {
		self::register_defaults();
		\add_action( 'admin_notices', [ $this, 'render' ] );
	}

	/**
	 * Add or replace one code's notice. Idempotent: a later call for the same
	 * code (compared post- sanitize_key()) just overwrites the earlier one.
	 *
	 * @param 'success'|'error' $type
	 */
	public static function register( string $code, string $type, string $message ): void {
		self::$registry[ \sanitize_key( $code ) ] = [
			'type'    => $type,
			'message' => $message,
		];
	}

	/**
	 * Why the current admin-post request may NOT proceed: '' when it may,
	 * `bad_nonce` when `_wpnonce` does not verify for $nonce_action, or
	 * `forbidden` when the current user lacks Capabilities::cap( $cap_key ).
	 * Nonce first, then capability - the house order for every handler.
	 *
	 * The one nonce -> capability check CourseEditor, LearnerReports and
	 * EnrollmentManager share (final review I10); each decides which code to
	 * redirect with (CourseEditor reports both as `forbidden`).
	 */
	public static function authorisation_error( string $nonce_action, string $cap_key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification -- this IS the verification.
		$nonce = \sanitize_text_field( \wp_unslash( (string) ( $_REQUEST['_wpnonce'] ?? '' ) ) );
		if ( ! \wp_verify_nonce( $nonce, $nonce_action ) ) {
			return 'bad_nonce';
		}
		if ( ! Capabilities::current_user_can( $cap_key ) ) {
			return 'forbidden';
		}
		return '';
	}

	/** True when authorisation_error() finds nothing wrong. */
	public static function authorise( string $nonce_action, string $cap_key ): bool {
		return '' === self::authorisation_error( $nonce_action, $cap_key );
	}

	/**
	 * Redirect to $target_url carrying a notice code, and stop.
	 *
	 * The code is whitelisted against the registry: anything unregistered
	 * (a WP_Error code a filter invented, say) is sent as `error`, so a
	 * redirect never carries a code render() would silently ignore.
	 */
	public static function redirect( string $code, string $target_url ): void {
		self::register_defaults();
		$code = \sanitize_key( $code );
		if ( ! isset( self::$registry[ $code ] ) ) {
			$code = 'error';
		}
		\wp_safe_redirect( \add_query_arg( self::QUERY_ARG, $code, '' !== $target_url ? $target_url : \admin_url() ) );
		exit;
	}

	/** The edit screen of a course, or the dashboard when there is none - where every handler lands. */
	public static function course_url( int $course_id ): string {
		return $course_id > 0 ? (string) \get_edit_post_link( $course_id, 'raw' ) : \admin_url();
	}

	/** Read the code off the current request and print its notice, if any. */
	public function render(): void {
		self::register_defaults();

		if ( ! self::on_allowed_screen() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification -- a read, not a state change; the value only ever selects one of a fixed set of canned messages.
		$code = \sanitize_key( \wp_unslash( (string) ( $_GET[ self::QUERY_ARG ] ?? '' ) ) );
		if ( '' === $code || ! isset( self::$registry[ $code ] ) ) {
			return; // Unknown (or absent) codes print nothing.
		}

		$notice = self::$registry[ $code ];

		\printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			\esc_attr( $notice['type'] ),
			\esc_html( $notice['message'] )
		);
	}

	/**
	 * Whether the current admin screen is one this module's handlers actually
	 * redirect to. No screen at all (admin-post.php itself has none, and
	 * neither does a direct call outside a real admin request, e.g. a test)
	 * is not a mismatch to withhold on - there is nothing to check against.
	 */
	private static function on_allowed_screen(): bool {
		if ( ! \function_exists( '\get_current_screen' ) ) {
			return true;
		}

		$screen = \get_current_screen();

		return ! $screen instanceof \WP_Screen || \in_array( $screen->id, self::ALLOWED_SCREENS, true );
	}

	/**
	 * The whole vocabulary CourseEditor and LearnerReports already produce,
	 * registered here rather than edited into either class (Task 31 ruling).
	 * Guarded so it only ever runs once per request.
	 */
	private static function register_defaults(): void {
		if ( self::$defaults_registered ) {
			return;
		}
		self::$defaults_registered = true;

		// Admin\CourseEditor::handle_delete_role().
		self::register( 'role_deleted', self::TYPE_SUCCESS, \__( 'Role deleted and removed from every holder.', 'anchor-schema' ) );

		// Admin\CourseEditor::save_curriculum() (audit F04) - a warning, the
		// curriculum itself was saved.
		self::register( 'curriculum_quiz_link', self::TYPE_ERROR, \__( 'Curriculum saved, but a required lesson set to "Complete when its quiz passes" has that quiz missing from this course or placed before the lesson. Learners cannot finish it - add the quiz after its lesson.', 'anchor-schema' ) );
		self::register( 'forbidden', self::TYPE_ERROR, \__( 'You are not allowed to do that.', 'anchor-schema' ) );
		self::register( 'error', self::TYPE_ERROR, \__( 'That action could not be completed.', 'anchor-schema' ) );

		// Admin\LearnerReports::handle_add_learner() / handle_revoke(), plus the
		// Support\Roles::grant_access() / Services\EnrollmentService::can_enroll()
		// WP_Error codes handle_add_learner() forwards to its redirect as-is.
		self::register( 'learner_added', self::TYPE_SUCCESS, \__( 'Learner added and given access.', 'anchor-schema' ) );
		self::register( 'access_revoked', self::TYPE_SUCCESS, \__( 'Access removed.', 'anchor-schema' ) );
		self::register( 'revoke_failed', self::TYPE_ERROR, \__( 'That person did not have access to revoke.', 'anchor-schema' ) );
		self::register( 'bad_nonce', self::TYPE_ERROR, \__( 'That request expired. Please try again.', 'anchor-schema' ) );
		self::register( 'no_user', self::TYPE_ERROR, \__( 'That person could not be resolved to an account.', 'anchor-schema' ) );
		self::register( 'no_course', self::TYPE_ERROR, \__( 'That course does not exist.', 'anchor-schema' ) );
		self::register( 'enroll_failed', self::TYPE_ERROR, \__( 'The enrolment could not be saved.', 'anchor-schema' ) );
		self::register( 'missing_prerequisite', self::TYPE_ERROR, \__( 'Access was not granted: this course has prerequisites they have not finished.', 'anchor-schema' ) );
		self::register( 'not_available_yet', self::TYPE_ERROR, \__( 'Access was not granted: this course is not open yet.', 'anchor-schema' ) );
		self::register( 'no_longer_available', self::TYPE_ERROR, \__( 'Access was not granted: this course has closed.', 'anchor-schema' ) );
	}
}
