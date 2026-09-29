<?php
declare(strict_types=1);

namespace Anchor\Courses\Frontend;

use Anchor\Courses\Admin\CourseEditor;
use Anchor\Courses\Admin\LessonEditor;
use Anchor\Courses\Content\CoursePostType;
use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Domain\CourseProgress;
use Anchor\Courses\Integrations\Events;
use Anchor\Courses\Module;
use Anchor\Courses\Services\CertificateService;
use Anchor\Courses\Services\CreditService;
use Anchor\Courses\Services\EnrollmentService;
use Anchor\Courses\Services\ProgressService;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** The Phase 1 shortcodes (brief section 23). Presentation only. */
final class Shortcodes {

	public function __construct(
		private ProgressService $progress,
		private EnrollmentService $enrollments,
		private CreditService $credits,
		private CertificateService $certificates
	) {
		\add_shortcode( 'anchor_courses', [ $this, 'courses_index' ] );
		\add_shortcode( 'anchor_course', [ $this, 'single_course' ] );
		\add_shortcode( 'anchor_course_progress', [ $this, 'course_progress' ] );
		\add_shortcode( 'anchor_my_courses', [ $this, 'my_courses' ] );
		\add_shortcode( 'anchor_my_credits', [ $this, 'my_credits' ] );
		\add_shortcode( 'anchor_my_certificates', [ $this, 'my_certificates' ] );
	}

	public function courses_index( $atts = [] ): string {
		$atts = \shortcode_atts( [ 'limit' => 20 ], (array) $atts, 'anchor_courses' );

		$courses = \get_posts(
			[
				'post_type'      => CoursePostType::CPT,
				'post_status'    => 'publish',
				'posts_per_page' => \max( 1, (int) $atts['limit'] ),
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			]
		);

		\ob_start();
		echo '<ul class="anchor-courses-list">';
		foreach ( $courses as $course ) {
			\printf(
				'<li class="anchor-courses-list-item"><a href="%s">%s</a>%s</li>',
				\esc_url( (string) \get_permalink( $course ) ),
				\esc_html( (string) $course->post_title ),
				$this->credits_badge( (int) $course->ID )
			);
		}
		echo '</ul>';
		return (string) \ob_get_clean();
	}

	private function credits_badge( int $course_id ): string {
		$credits = (float) CourseEditor::setting( $course_id, 'ce_credits' );
		if ( $credits <= 0 ) {
			return '';
		}
		return \sprintf(
			' <span class="anchor-courses-credits">%s</span>',
			\esc_html(
				\sprintf(
					/* translators: %s: number of CE credits. */
					\__( '%s CE credits', 'anchor-schema' ),
					\number_format_i18n( $credits, 1 )
				)
			)
		);
	}

	public function single_course( $atts = [] ): string {
		$atts      = \shortcode_atts( [ 'id' => 0 ], (array) $atts, 'anchor_course' );
		$course_id = (int) $atts['id'] > 0 ? (int) $atts['id'] : (int) \get_the_ID();

		if ( CoursePostType::CPT !== \get_post_type( $course_id ) ) {
			return '';
		}

		$user_id = \get_current_user_id();
		// First, before anything reads progress or availability: close this
		// learner's lapsed quiz attempts in this course by their own timer
		// policy, and learn which quizzes are still open.
		$in_progress = $this->settle_open_attempts( $user_id, $course_id );

		return Templates::render(
			'course',
			[
				'course_id'    => $course_id,
				'user_id'      => $user_id,
				'modules'      => Curriculum::get( $course_id ),
				'progress'     => $user_id > 0 ? $this->progress->get_course_progress( $user_id, $course_id ) : null,
				// EnrollmentService::get() returns a cancelled/expired row too
				// (it is still "the" row for this user/course) - the template
				// must not treat that as access. is_enrolled() is the boolean
				// authority for "does this learner currently have access"
				// (Task 18 review fix round, ruling (c)).
				'is_enrolled'  => $user_id > 0 && $this->enrollments->is_enrolled( $user_id, $course_id ),
				// is_item_available() for every item, read once (PR #40 review).
				'availability' => $user_id > 0 ? $this->progress->availability( $user_id, $course_id ) : [],
				// Quizzes still open after settling ("In progress").
				'in_progress'  => $in_progress,
				'service'      => $this->progress,
			]
		);
	}

	public function course_progress( $atts = [] ): string {
		$atts      = \shortcode_atts( [ 'course_id' => 0 ], (array) $atts, 'anchor_course_progress' );
		$course_id = (int) $atts['course_id'] > 0 ? (int) $atts['course_id'] : (int) \get_the_ID();
		$user_id   = \get_current_user_id();

		if ( $user_id <= 0 || CoursePostType::CPT !== \get_post_type( $course_id ) ) {
			return '';
		}

		return self::progress_html( $this->progress->get_course_progress( $user_id, $course_id ) );
	}

	/**
	 * The progress bar markup: the one renderer behind [anchor_course_progress]
	 * and the lesson page's course outline (templates/course-outline.php), so
	 * both draw the same numbers from one get_course_progress() result.
	 */
	public static function progress_html( CourseProgress $progress ): string {
		return \sprintf(
			'<div class="anchor-courses-progress"><div class="anchor-courses-bar"><span style="width:%1$s%%"></span></div>'
			. '<p class="anchor-courses-progress-label">%2$s</p></div>',
			\esc_attr( (string) $progress->percent ),
			\esc_html(
				\sprintf(
					/* translators: 1: percent complete, 2: completed items, 3: total items. */
					\__( '%1$s%% complete (%2$d of %3$d)', 'anchor-schema' ),
					\number_format_i18n( $progress->percent, 0 ),
					$progress->completed_required,
					$progress->total_required
				)
			)
		);
	}

	public function my_courses(): string {
		$user_id = \get_current_user_id();
		if ( $user_id <= 0 ) {
			return '<p class="anchor-courses-notice">' . \esc_html__( 'Please sign in to see your courses.', 'anchor-schema' ) . '</p>';
		}

		return Templates::render(
			'dashboard',
			[
				'user_id'     => $user_id,
				'enrollments' => $this->enrollments->get_for_user( $user_id ),
				'progress'    => $this->progress,
			]
		);
	}

	/**
	 * [anchor_my_credits] - the signed-in learner's own CE credits.
	 *
	 * `CreditService::for_user()`/`::total_for_user()`, scoped to
	 * `get_current_user_id()`, are the only Task 27 call sites this method
	 * uses - never `Database\CreditRepository` directly. The Task 18
	 * layering exception (a Frontend shortcode reading a repository because
	 * the owning service was locked mid-review) is closed here on purpose,
	 * not repeated (progress.md ruling).
	 */
	public function my_credits(): string {
		$user_id = \get_current_user_id();
		if ( $user_id <= 0 ) {
			return '<p class="anchor-courses-notice">' . \esc_html__( 'Please sign in to see your CE credits.', 'anchor-schema' ) . '</p>';
		}

		$credits = $this->credits->for_user( $user_id );
		if ( [] === $credits ) {
			return '<p class="anchor-courses-notice">' . \esc_html__( 'No CE credits yet.', 'anchor-schema' ) . '</p>';
		}

		\ob_start();
		echo '<table class="anchor-courses-credits-table"><thead><tr>';
		\printf(
			'<th>%s</th><th>%s</th><th>%s</th><th>%s</th></tr></thead><tbody>',
			\esc_html__( 'Course', 'anchor-schema' ),
			\esc_html__( 'Credits', 'anchor-schema' ),
			\esc_html__( 'Type', 'anchor-schema' ),
			\esc_html__( 'Awarded', 'anchor-schema' )
		);
		foreach ( $credits as $credit ) {
			\printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				\esc_html( (string) \get_the_title( $credit->course_id ) ),
				\esc_html( \number_format_i18n( $credit->credits, 1 ) ),
				\esc_html( $credit->credit_type ),
				\esc_html( \mysql2date( (string) \get_option( 'date_format' ), $credit->awarded_at ) )
			);
		}
		\printf(
			'</tbody><tfoot><tr><th>%s</th><th colspan="3">%s</th></tr></tfoot></table>',
			\esc_html__( 'Total', 'anchor-schema' ),
			\esc_html( \number_format_i18n( $this->credits->total_for_user( $user_id ), 1 ) )
		);
		return (string) \ob_get_clean();
	}

	/**
	 * [anchor_my_certificates] - the signed-in learner's own certificates,
	 * each linking to its public `/certificate/{token}/` verification page
	 * (`CertificatePage`). Scoped the same way as `my_credits()` above:
	 * `CertificateService::for_user( $user_id )` only.
	 */
	public function my_certificates(): string {
		$user_id = \get_current_user_id();
		if ( $user_id <= 0 ) {
			return '<p class="anchor-courses-notice">' . \esc_html__( 'Please sign in to see your certificates.', 'anchor-schema' ) . '</p>';
		}

		$certificates = $this->certificates->for_user( $user_id );
		if ( [] === $certificates ) {
			return '<p class="anchor-courses-notice">' . \esc_html__( 'No certificates yet.', 'anchor-schema' ) . '</p>';
		}

		\ob_start();
		echo '<ul class="anchor-courses-certificates-list">';
		foreach ( $certificates as $certificate ) {
			\printf(
				'<li><a href="%s">%s</a> <span class="anchor-certificate-number">%s</span> <span>%s</span></li>',
				\esc_url( $certificate->url() ),
				\esc_html( (string) \get_the_title( $certificate->course_id ) ),
				\esc_html( $certificate->certificate_number ),
				\esc_html( \mysql2date( (string) \get_option( 'date_format' ), $certificate->issued_at ) )
			);
		}
		echo '</ul>';
		return (string) \ob_get_clean();
	}

	/**
	 * Rendered by single-lesson.php; not a public shortcode.
	 *
	 * The course is resolved by Access::course_for_lesson() - the course on
	 * the URL, else the one the learner is enrolled in (final review I2) -
	 * the same answer ContentGuard reaches for the body.
	 */
	public function render_lesson( int $lesson_id ): string {
		$user_id   = \get_current_user_id();
		$course_id = Access::course_for_lesson( $lesson_id, $user_id );

		if ( $course_id <= 0 ) {
			return '';
		}

		if ( 'live_session' === (string) LessonEditor::setting( $lesson_id, 'type' ) ) {
			return $this->render_live_session( $lesson_id, $course_id );
		}

		// Before any availability or progress read (settle_open_attempts()).
		$open_quizzes = $this->settle_open_attempts( $user_id, $course_id );

		$available = $user_id > 0 && $this->progress->is_item_available( $user_id, $course_id, $lesson_id, 'lesson' );
		if ( $available ) {
			$this->progress->start_lesson( $user_id, $course_id, $lesson_id );
		}

		// After start_lesson(): a `view`-completion lesson has just completed,
		// and the outline and the Next button must already say so.
		$course_progress = $user_id > 0 ? $this->progress->get_course_progress( $user_id, $course_id ) : null;
		$outline         = ( new CourseOutline( $this->progress ) )->build( $course_id, $user_id, $lesson_id, $course_progress, 'lesson', $open_quizzes );

		$body = Templates::render(
			'lesson',
			[
				'lesson_id' => $lesson_id,
				'course_id' => $course_id,
				'user_id'   => $user_id,
				'available' => $available,
				'complete'  => $course_progress && \in_array( 'lesson:' . $lesson_id, $course_progress->completed_item_keys, true ),
				// The neighbouring step's id when it is a LESSON (else 0), kept
				// for theme overrides written before $outline existed, which
				// link it as a lesson.
				'previous'  => self::neighbour_lesson( $outline['previous'] ),
				'next'      => self::neighbour_lesson( $outline['next'] ),
				'outline'   => $outline,
			]
		);

		return $this->lesson_layout( $body, $outline );
	}

	/**
	 * QuizService::settle_open_attempts(): close this learner's lapsed quiz
	 * attempts in this course by their own timer policy, once per page and
	 * before it reads progress or availability, and return the quizzes still
	 * open ("In progress").
	 *
	 * @return int[]
	 */
	private function settle_open_attempts( int $user_id, int $course_id ): array {
		$module = Module::instance();
		return $user_id > 0 && $module instanceof Module ? $module->quizzes->settle_open_attempts( $user_id, $course_id ) : [];
	}

	/** A previous/next step's id when it is a lesson, else 0. */
	private static function neighbour_lesson( ?array $step ): int {
		return null !== $step && 'lesson' === ( $step['type'] ?? '' ) ? (int) $step['id'] : 0;
	}

	/**
	 * The quiz step: a quiz inside its course, in the same frame as a lesson
	 * (the course outline beside it, the previous/next bar under it).
	 * Rendered by templates/single-quiz.php for /courses/{course}/quiz/{quiz}/
	 * (QuizStep); not a public shortcode.
	 *
	 * $course_id is the course in the URL (QuizStep has already checked it
	 * lists the quiz, and sent a learner enrolled only elsewhere to their
	 * own course's step). Access is a lesson's: Access::item_denial(), so a
	 * visitor or a learner not enrolled sees "not enrolled" plus the call to
	 * action, and an enrolled learner locked by progression sees the same
	 * "finish the earlier lessons" notice a locked lesson shows, in place of
	 * the quiz. The page's H1 is the quiz title, so the quiz box does not
	 * repeat it.
	 */
	public function render_quiz_step( int $quiz_id, int $course_id ): string {
		if ( $course_id <= 0 || ! Curriculum::contains( $course_id, $quiz_id, 'quiz' ) ) {
			return '';
		}

		$user_id         = \get_current_user_id();
		// First: every lapsed attempt in the course, this quiz's included, is
		// closed by its timer policy before the denial, the progress and the
		// outline are read. render_quiz()'s own resumable_attempt() then finds
		// nothing left to resolve, so nothing is resolved twice.
		$open_quizzes    = $this->settle_open_attempts( $user_id, $course_id );
		$denial          = Access::item_denial( $course_id, $quiz_id, 'quiz', $user_id );
		$quiz_html       = '' === $denial ? $this->render_quiz( $quiz_id, $course_id, false ) : '';
		$course_progress = $user_id > 0 ? $this->progress->get_course_progress( $user_id, $course_id ) : null;
		$outline         = ( new CourseOutline( $this->progress ) )->build( $course_id, $user_id, $quiz_id, $course_progress, 'quiz', $open_quizzes );

		$body = Templates::render(
			'quiz-step',
			[
				'quiz_id'   => $quiz_id,
				'course_id' => $course_id,
				'user_id'   => $user_id,
				'denial'    => $denial,
				'notice'    => '' === $denial ? '' : Access::denial_notice( $denial, $course_id, $user_id ),
				'quiz'      => $quiz_html,
				'complete'  => $course_progress && \in_array( 'quiz:' . $quiz_id, $course_progress->completed_item_keys, true ),
				'outline'   => $outline,
			]
		);

		return $this->lesson_layout( $body, $outline );
	}

	/**
	 * The lesson page frame both lesson types share: the course outline
	 * beside (on phones, above) the lesson body. templates/lesson-layout.php.
	 */
	private function lesson_layout( string $body, array $outline ): string {
		return Templates::render(
			'lesson-layout',
			[
				'outline' => $outline,
				'content' => $body,
			]
		);
	}

	/**
	 * Render a live_session lesson (design spec 3.3).
	 *
	 * `Integrations\Events` is the only surface this touches for the events
	 * side - never `_anchor_event_*` meta or an events-module table
	 * directly (Events' own docblock). `$available` here means "the events
	 * module can resolve this event" - a DIFFERENT question from
	 * `$item_available` (ProgressService::is_item_available(), the same
	 * progression/enrolment authority render_lesson() uses above), which
	 * gates the "Mark attended" form exactly as render_lesson() gates
	 * "Mark complete".
	 *
	 * The room link is additionally withheld from anyone not is_enrolled()
	 * in $course_id, regardless of whether the event resolves: this is a
	 * COURSE access decision, never an events one. The events module still
	 * separately enforces its own entitlement on the room itself
	 * (`Entitlements::can_access_stream()`) once a learner follows the
	 * link - that decision is never duplicated here.
	 */
	public function render_live_session( int $lesson_id, int $course_id ): string {
		$user_id  = \get_current_user_id();
		$event_id = (int) LessonEditor::setting( $lesson_id, 'event_id' );
		// Events::event_exists() (PR36 round 3, Codex): a deleted event, or a
		// legacy value that never named a real event post, must show the
		// "unavailable" branch below - not the "will appear later" branch,
		// which implies the event still exists and just hasn't started.
		$ready    = Events::available() && Events::event_exists( $event_id );

		// Before any availability or progress read (settle_open_attempts()).
		$open_quizzes = $this->settle_open_attempts( $user_id, $course_id );

		$item_available = $user_id > 0 && $this->progress->is_item_available( $user_id, $course_id, $lesson_id, 'lesson' );
		if ( $item_available ) {
			$this->progress->start_lesson( $user_id, $course_id, $lesson_id );
		}

		$is_enrolled     = $user_id > 0 && $this->enrollments->is_enrolled( $user_id, $course_id );
		$course_progress = $user_id > 0 ? $this->progress->get_course_progress( $user_id, $course_id ) : null;
		$outline         = ( new CourseOutline( $this->progress ) )->build( $course_id, $user_id, $lesson_id, $course_progress, 'lesson', $open_quizzes );

		// The stream veto's own decision (Events::prework_block(), the same
		// call veto_stream_access() makes), so this page never offers a Join
		// button the room would then refuse (final review I3).
		$block          = ( $ready && $is_enrolled ) ? Events::prework_block( $event_id, (int) LessonEditor::setting( $lesson_id, 'session_index' ), $user_id ) : null;
		$prework_notice = null === $block ? '' : Events::prework_notice( $block );

		$body = Templates::render(
			'live-session',
			[
				'lesson_id'      => $lesson_id,
				'course_id'      => $course_id,
				'event_id'       => $event_id,
				'available'      => $ready,
				'sessions'       => $ready ? Events::sessions( $event_id ) : [],
				// Course access, not events access (see docblock above): the
				// room's own entitlement check still runs when this link is
				// followed.
				'room_url'       => ( $ready && $is_enrolled ) ? Events::room_url( $event_id ) : '',
				'prework_notice' => $prework_notice,
				'state'          => $ready ? Events::stream_state( $event_id ) : [ 'state' => 'unknown' ],
				'item_available' => $item_available,
				'complete'       => $course_progress && \in_array( 'lesson:' . $lesson_id, $course_progress->completed_item_keys, true ),
				'outline'        => $outline,
			]
		);

		return $this->lesson_layout( $body, $outline );
	}

	/**
	 * The quiz box (templates/quiz.php): rendered by the quiz step
	 * (render_quiz_step()), never as a public shortcode (QuizPostType::CPT is
	 * not publicly_queryable - a quiz has no URL outside its course; see
	 * QuizPostType.php and QuizStep).
	 *
	 * render_quiz_step() only calls this once Access::item_denial() allows
	 * it (ProgressService::is_item_available() - the same authority a lesson
	 * uses), so a non-enrolled or sequentially-locked learner never reaches
	 * this method there. can_start() below is a second, independent check on
	 * top of that: it also covers exhausted attempts and an active retry
	 * delay, and it self-gates a theme/integration that calls render_quiz()
	 * directly - either way, a learner who may not start sees the notice from
	 * can_start()'s WP_Error message, never the quiz.
	 *
	 * $show_title: false where the page already names the quiz (the step's
	 * H1), so the title is printed once.
	 *
	 * $course_id is the course this quiz is being rendered FOR (Task 26
	 * review, IMPORTANT): a quiz may be shared by more than one course
	 * (Curriculum::course_for_item()'s tie-break is deterministic but
	 * arbitrary - lowest course id), so a caller that already knows which
	 * course it is rendering must say so, or a learner enrolled only in the
	 * second course would be evaluated (and would start attempts) against
	 * the first. Left at 0 (the default), this falls back to
	 * course_for_item() exactly as before, for any caller that genuinely has
	 * no course context of its own.
	 */
	public function render_quiz( int $quiz_id, int $course_id = 0, bool $show_title = true ): string {
		$course_id = $course_id > 0 ? $course_id : Curriculum::course_for_item( $quiz_id, 'quiz' );
		$user_id   = \get_current_user_id();

		if ( $course_id <= 0 || $user_id <= 0 ) {
			return '';
		}

		$module = Module::instance();
		if ( ! $module instanceof Module ) {
			return '';
		}

		// First: resolving an expired open attempt (its timer policy) can
		// change the allowance and the best score read below.
		$open = $module->quizzes->resumable_attempt( $user_id, $quiz_id, $course_id );

		return Templates::render(
			'quiz',
			[
				'quiz_id'            => $quiz_id,
				// The open attempt "Resume quiz" reopens (start_attempt()
				// returns it, answers and pinned deadline intact), or null.
				'open_attempt'       => $open,
				'course_id'          => $course_id,
				'user_id'            => $user_id,
				'can_start'          => $module->quizzes->can_start( $user_id, $quiz_id, $course_id ),
				'attempts_remaining' => $module->quizzes->attempts_remaining( $user_id, $quiz_id, $course_id ),
				'best'               => $module->quizzes->best_attempt( $user_id, $quiz_id, $course_id ),
				// Gate the "Best score" line the same way the REST payload
				// gates score/points_earned/passed (Task 26 review, MINOR).
				'show_score'         => 1 === (int) $module->quizzes->settings( $quiz_id )['show_score'],
				'show_title'         => $show_title,
			]
		);
	}
}
