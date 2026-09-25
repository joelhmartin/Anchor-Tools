<?php
/**
 * Anchor Tools module: Anchor Courses.
 *
 * Bootstrap only. Everything else lives under src/ and is PSR-4 autoloaded
 * (composer.json -> autoload.psr-4 "Anchor\\Courses\\").
 *
 * No declare(strict_types=1) here on purpose: WordPress calls this file's
 * hooks with loose values.
 */

namespace Anchor\Courses;

use Anchor\Courses\Database\Migrations;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/api.php';

class Module {

	const VERSION = '1.0.0';

	private static ?Module $instance = null;

	public Services\EnrollmentService $enrollments;
	public Services\ProgressService $progress;
	public Services\QuizService $quizzes;
	public Services\CertificateService $certificates;
	public Services\CreditService $credits;
	public Services\CompletionService $completion;
	public Frontend\Shortcodes $shortcodes;

	public function __construct() {
		self::$instance = $this;

		// No per-module activation hook exists (anchor_tools_bootstrap_modules()
		// only requires + instantiates). Run on load and again on admin_init so a
		// plugin upgrade that never touches wp-admin still converges. Brief section 28.
		Migrations::maybe_migrate();
		\add_action( 'admin_init', [ Migrations::class, 'maybe_migrate' ] );

		\add_action( 'init', [ Content\CoursePostType::class, 'register' ] );
		\add_action( 'init', [ Content\LessonPostType::class, 'register' ] );
		\add_action( 'init', [ Content\QuizPostType::class, 'register' ] );

		// Mint the access role on publish and keep both names on the title
		// (design spec 3.1). Priority 20: after CourseEditor::save() has run, so
		// a title set in the same request is the one the role is named for.
		\add_action( 'save_post_' . Content\CoursePostType::CPT, [ Support\Roles::class, 'rename_on_title_change' ], 20, 2 );

		// A course role must survive an unrelated primary-role change (design
		// spec 3.1 - see Support\Roles::reapply_after_set_role()).
		\add_action( 'set_user_role', [ Support\Roles::class, 'reapply_after_set_role' ], 10, 3 );

		if ( \is_admin() ) {
			new Admin\CourseEditor();
			new Admin\LessonEditor();
			new Admin\QuizEditor();
			new Admin\Notices();
			new Admin\LearnerReports();
			new Admin\EnrollmentManager();
		}

		$this->enrollments = new Services\EnrollmentService();

		// Holding anchor_course_{id} IS enrolment (design spec 3.1). Registered
		// here, once, so the listener and the service share one instance.
		Support\Roles::register_listeners( $this->enrollments );

		$this->progress = new Services\ProgressService( $this->enrollments );
		$this->quizzes  = new Services\QuizService( $this->progress, $this->enrollments );
		$this->credits  = new Services\CreditService();

		$this->certificates = new Services\CertificateService();

		// The once-only completion pipeline (Task 29): credits, certificate,
		// completion role and the anchor_courses_course_completed hook all run
		// from here, guarded so a hundred calls produce one set of effects.
		$this->completion = new Services\CompletionService(
			$this->enrollments,
			$this->progress,
			$this->credits,
			$this->certificates
		);
		$this->progress->set_completion_service( $this->completion );

		// The quiz REST surface (Task 26). Routes::__construct() only hooks
		// rest_api_init; nothing else needs this instance, so it is not kept.
		new Rest\Routes( new Rest\QuizController( $this->quizzes ) );

		// admin-post.php, not wp-admin, so it must be constructed unconditionally
		// (not inside the is_admin() block above) - the handler runs on requests
		// from the front end too.
		new Frontend\Actions( $this->progress );

		new Frontend\Templates();
		new Frontend\Assets();
		$this->shortcodes = new Frontend\Shortcodes( $this->progress, $this->enrollments );

		// Daily expiry sweep. Scheduled here rather than on activation because
		// modules have no activation hook (see Migrations' note).
		\add_action( Services\EnrollmentService::CRON_HOOK, [ $this->enrollments, 'sweep_expired' ] );
		// Quiz timers share the same daily sweep - one cron, two closers (Task 25).
		\add_action( Services\EnrollmentService::CRON_HOOK, [ $this->quizzes, 'sweep_expired_attempts' ] );
		if ( ! \wp_next_scheduled( Services\EnrollmentService::CRON_HOOK ) ) {
			\wp_schedule_event( \time() + HOUR_IN_SECONDS, 'daily', Services\EnrollmentService::CRON_HOOK );
		}
		// Same convention as the events and compliance modules: plugin
		// deactivation unschedules; uninstall.php clears it again by literal name.
		if ( \defined( 'ANCHOR_TOOLS_PLUGIN_FILE' ) ) {
			\register_deactivation_hook( ANCHOR_TOOLS_PLUGIN_FILE, [ self::class, 'on_deactivate' ] );
		}
	}

	/** Clear the module's scheduled crons on plugin deactivation. */
	public static function on_deactivate(): void {
		\wp_clear_scheduled_hook( Services\EnrollmentService::CRON_HOOK );
	}

	public static function instance(): ?Module {
		return self::$instance;
	}

	/** Absolute URL of this module's assets directory. */
	public static function assets_url(): string {
		return ANCHOR_TOOLS_PLUGIN_URL . 'anchor-courses/assets/';
	}

	/** Absolute path of this module's directory. */
	public static function dir(): string {
		return ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-courses/';
	}
}
