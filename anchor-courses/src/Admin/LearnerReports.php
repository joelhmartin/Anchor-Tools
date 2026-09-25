<?php
declare(strict_types=1);

namespace Anchor\Courses\Admin;

use Anchor\Courses\Content\CoursePostType;
use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Database\CertificateRepository;
use Anchor\Courses\Database\CreditRepository;
use Anchor\Courses\Database\EnrollmentRepository;
use Anchor\Courses\Database\ProgressRepository;
use Anchor\Courses\Database\QuizAttemptRepository;
use Anchor\Courses\Domain\Certificate;
use Anchor\Courses\Domain\Credit;
use Anchor\Courses\Services\CertificateService;
use Anchor\Courses\Services\CreditService;
use Anchor\Courses\Services\ProgressService;
use Anchor\Courses\Support\Accounts;
use Anchor\Courses\Support\Capabilities;
use Anchor\Courses\Support\Roles;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * The Learners tab (design spec 3.1) - a metabox, because the course edit
 * screen has metaboxes and not tabs (deviation D16) - plus a Courses block on
 * the user profile screen (Task 31).
 *
 * Who is in, how far they have got, and the two controls staff need: add
 * somebody by name and email, or take their access away. Both go through
 * Support\Roles, so this screen is a caller of the one enrolment door rather
 * than a second one. Task 31 adds the credits, certificate and quiz-attempt
 * columns, the user-profile surface, and hands the row-level enrolment
 * actions (cancel/reset/complete/uncomplete) to Admin\EnrollmentManager,
 * rendered inline below the add-learner form.
 *
 * The table prints learner email addresses, so it is gated on the REPORTS
 * capability, not on edit_posts; the write handlers need
 * manage_anchor_enrollments.
 *
 * rows() calls ProgressService::get_course_progress() once per enrolled
 * learner (an N+1 query per page). Known, reviewed, and deliberately left
 * alone here (Task 21 review, LOW) - Task 31 is careful not to make it
 * WORSE: the three new report columns (credits, certificate, best quiz
 * score) plus last_activity are each loaded with exactly one batched query
 * for the whole page, via CreditRepository/CertificateRepository/
 * QuizAttemptRepository/ProgressRepository methods that take the page's
 * whole array of user ids, not one call per row.
 */
final class LearnerReports {

	public const NONCE = 'anchor_courses_learners';

	/** Rows per page of the Learners table. */
	private const PER_PAGE = 50;

	public function __construct() {
		\add_action( 'add_meta_boxes', [ $this, 'add_metabox' ] );
		\add_action( 'show_user_profile', [ $this, 'render_user_profile' ] );
		\add_action( 'edit_user_profile', [ $this, 'render_user_profile' ] );
		\add_action( 'admin_post_anchor_courses_add_learner', [ $this, 'handle_add_learner' ] );
		\add_action( 'admin_post_anchor_courses_revoke_access', [ $this, 'handle_revoke' ] );
	}

	public function add_metabox(): void {
		\add_meta_box(
			'anchor_courses_learners',
			\__( 'Learners', 'anchor-schema' ),
			[ $this, 'render_learners' ],
			CoursePostType::CPT,
			'normal',
			'low'
		);
	}

	/**
	 * One row per enrolled learner.
	 *
	 * `has_access` is read from the role, not the row, and the two can
	 * legitimately disagree: the default loss policy keeps an enrolment after
	 * its role goes, so "enrolled, no access" is a real and visible state.
	 *
	 * The four Phase 4 columns (last_activity, best_score, credits,
	 * certificate_number/url) are batch-loaded once for the whole page of user
	 * ids, not once per row - see the class docblock's N+1 note.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function rows( int $course_id, int $limit = 50, int $offset = 0 ): array {
		$progress    = new ProgressService();
		$slug        = Roles::access_slug( $course_id );
		$enrollments = EnrollmentRepository::for_course( $course_id, [], $limit, $offset );

		$user_ids = \array_map( static fn( $enrollment ) => $enrollment->user_id, $enrollments );

		// One query per column source for the WHOLE PAGE (Task 21 review, N+1
		// LOW, carried and closed here): three new report columns must not
		// mean three more queries per learner.
		$credits       = CreditRepository::for_users_in_course( $user_ids, $course_id );
		$certificates  = CertificateRepository::for_users_in_course( $user_ids, $course_id );
		$best_scores   = QuizAttemptRepository::best_scores_for_users_in_course( $user_ids, $course_id );
		$last_activity = ProgressRepository::last_activity_for_users( $user_ids, $course_id );

		$rows = [];

		foreach ( $enrollments as $enrollment ) {
			$user        = \get_userdata( $enrollment->user_id );
			$credit      = $credits[ $enrollment->user_id ] ?? null;
			$certificate = $certificates[ $enrollment->user_id ] ?? null;

			$rows[] = [
				'user_id'            => $enrollment->user_id,
				'display_name'       => $user ? (string) $user->display_name : '',
				'user_email'         => $user ? (string) $user->user_email : '',
				'enrolled_at'        => $enrollment->enrolled_at,
				// Still one call per row - the pre-existing, already-reviewed
				// N+1 this class's docblock carries forward (out of Task 31's
				// scope, which is the THREE NEW columns below).
				'percent'            => $progress->get_course_progress( $enrollment->user_id, $course_id )->percent,
				'last_activity'      => $last_activity[ $enrollment->user_id ] ?? '',
				'best_score'         => $best_scores[ $enrollment->user_id ] ?? null,
				'status'             => $enrollment->status,
				'completed_at'       => (string) ( $enrollment->completed_at ?? '' ),
				'credits'            => $credit instanceof Credit ? $credit->credits : 0.0,
				'certificate_number' => $certificate instanceof Certificate ? $certificate->certificate_number : '',
				'certificate_url'    => $certificate instanceof Certificate ? $certificate->url() : '',
				'has_access'         => Roles::user_has( $enrollment->user_id, $slug ),
			];
		}

		return $rows;
	}

	public function render_learners( \WP_Post $post ): void {
		if ( ! Capabilities::current_user_can( 'reports' ) ) {
			echo '<p>' . \esc_html__( 'You do not have permission to view learner reports.', 'anchor-schema' ) . '</p>';
			return;
		}

		$course_id = (int) $post->ID;
		$page      = $this->current_page();
		$offset    = ( $page - 1 ) * self::PER_PAGE;
		$rows      = self::rows( $course_id, self::PER_PAGE, $offset );
		$total     = EnrollmentRepository::count_for_course( $course_id );
		$may_write = Capabilities::current_user_can( 'enrollments' );

		// The empty-curriculum question is per COURSE, not per learner - every
		// row in this table shares the same answer, so this is one call, not
		// one per row (the N+1 progress lookup below is a separate, known,
		// carried-forward issue - see class docblock).
		$has_curriculum = [] !== Curriculum::required_items( $course_id );

		if ( 0 === $total ) {
			echo '<p>' . \esc_html__( 'Nobody is enrolled yet.', 'anchor-schema' ) . '</p>';
		} else {
			echo '<table class="widefat striped anchor-courses-report"><thead><tr>';
			foreach ( [
				\__( 'Learner', 'anchor-schema' ),
				\__( 'Email', 'anchor-schema' ),
				\__( 'Enrolled', 'anchor-schema' ),
				\__( 'Progress', 'anchor-schema' ),
				\__( 'Last activity', 'anchor-schema' ),
				\__( 'Best quiz', 'anchor-schema' ),
				\__( 'Status', 'anchor-schema' ),
				\__( 'Completed', 'anchor-schema' ),
				\__( 'Credits', 'anchor-schema' ),
				\__( 'Certificate', 'anchor-schema' ),
				\__( 'Access', 'anchor-schema' ),
			] as $heading ) {
				echo '<th>' . \esc_html( $heading ) . '</th>';
			}
			echo '</tr></thead><tbody>';

			foreach ( $rows as $row ) {
				echo '<tr>';
				\printf( '<td>%s</td>', \esc_html( (string) $row['display_name'] ) );
				\printf( '<td>%s</td>', \esc_html( (string) $row['user_email'] ) );
				\printf( '<td>%s</td>', \esc_html( \mysql2date( 'Y-m-d', (string) $row['enrolled_at'] ) ) );
				if ( $has_curriculum ) {
					\printf(
						'<td class="progress"><div class="anchor-courses-bar"><span style="width:%1$s%%"></span></div>%1$s%%</td>',
						\esc_html( \number_format_i18n( (float) $row['percent'], 0 ) )
					);
				} else {
					// A course with nothing required is 100% by ProgressService's
					// contract (nothing left to do) - but printing "100%" here
					// reads as "this learner finished", which is false; there is
					// simply no content yet.
					\printf(
						'<td class="progress"><span title="%s">%s</span></td>',
						\esc_attr__( 'This course has no content yet.', 'anchor-schema' ),
						\esc_html__( '—', 'anchor-schema' )
					);
				}
				\printf( '<td>%s</td>', \esc_html( '' === $row['last_activity'] ? '-' : \mysql2date( 'Y-m-d', (string) $row['last_activity'] ) ) );
				\printf( '<td>%s</td>', \esc_html( null === $row['best_score'] ? '-' : \number_format_i18n( (float) $row['best_score'], 0 ) . '%' ) );
				\printf( '<td>%s</td>', \esc_html( (string) $row['status'] ) );
				\printf( '<td>%s</td>', \esc_html( '' === $row['completed_at'] ? '-' : \mysql2date( 'Y-m-d', (string) $row['completed_at'] ) ) );
				\printf( '<td>%s</td>', \esc_html( \number_format_i18n( (float) $row['credits'], 1 ) ) );
				if ( '' !== $row['certificate_number'] ) {
					\printf(
						'<td><a href="%s">%s</a></td>',
						\esc_url( (string) $row['certificate_url'] ),
						\esc_html( (string) $row['certificate_number'] )
					);
				} else {
					echo '<td>-</td>';
				}

				// Access + per-row Revoke, carried over from Task 21.
				echo '<td>';
				if ( $row['has_access'] && $may_write ) {
					$this->revoke_button( $course_id, (int) $row['user_id'] );
				} else {
					echo \esc_html( $row['has_access'] ? \__( 'Yes', 'anchor-schema' ) : \__( 'No', 'anchor-schema' ) );
				}
				echo '</td>';
				echo '</tr>';
			}

			echo '</tbody></table>';

			$this->render_pagination( $course_id, $page, $offset, \count( $rows ), $total );
		}

		if ( $may_write ) {
			$this->render_add_learner_form( $course_id ); // Task 21.
			( new EnrollmentManager() )->render_form( $course_id );
		}
	}

	/**
	 * The page number riding on the course edit URL - min 1, garbage collapses
	 * to 1. Not `absint()`: that takes an absolute value, so a negative page
	 * like "-4" would become page 4 instead of falling back to page 1.
	 */
	private function current_page(): int {
		// phpcs:ignore WordPress.Security.NonceVerification -- a read, not a state change.
		$raw  = \wp_unslash( $_GET['anchor_learners_page'] ?? 1 );
		$page = \is_numeric( $raw ) ? (int) $raw : 1;

		return \max( 1, $page );
	}

	/** "Showing X-Y of N" plus prev/next links, built on the course's own edit URL. */
	private function render_pagination( int $course_id, int $page, int $offset, int $shown, int $total ): void {
		if ( 0 === $shown ) {
			return;
		}

		$base = (string) \get_edit_post_link( $course_id, 'raw' );

		echo '<p class="anchor-courses-pagination">';
		\printf(
			/* translators: 1: first row number shown, 2: last row number shown, 3: total learners. */
			\esc_html__( 'Showing %1$d–%2$d of %3$d', 'anchor-schema' ),
			$offset + 1,
			$offset + $shown,
			$total
		);

		if ( $page > 1 ) {
			\printf(
				' <a href="%s">%s</a>',
				\esc_url( \add_query_arg( 'anchor_learners_page', $page - 1, $base ) ),
				\esc_html__( 'Previous', 'anchor-schema' )
			);
		}

		if ( $offset + self::PER_PAGE < $total ) {
			\printf(
				' <a href="%s">%s</a>',
				\esc_url( \add_query_arg( 'anchor_learners_page', $page + 1, $base ) ),
				\esc_html__( 'Next', 'anchor-schema' )
			);
		}

		echo '</p>';
	}

	public function render_add_learner_form( int $course_id ): void {
		\printf(
			'<h4>%s</h4><form method="post" action="%s" class="anchor-courses-add-learner">',
			\esc_html__( 'Add a learner', 'anchor-schema' ),
			\esc_url( \admin_url( 'admin-post.php' ) )
		);
		\wp_nonce_field( self::NONCE . '_' . $course_id );
		echo '<input type="hidden" name="action" value="anchor_courses_add_learner" />';
		\printf( '<input type="hidden" name="course_id" value="%d" />', $course_id );
		\printf(
			'<p><input type="text" name="learner_name" placeholder="%s" /> '
			. '<input type="email" name="learner_email" placeholder="%s" required /> '
			. '<button type="submit" class="button">%s</button></p>',
			\esc_attr__( 'Name', 'anchor-schema' ),
			\esc_attr__( 'Email', 'anchor-schema' ),
			\esc_html__( 'Add learner', 'anchor-schema' )
		);
		echo '<p class="description">' . \esc_html__( 'Creates the account if there is none. No email is sent.', 'anchor-schema' ) . '</p>';
		echo '</form>';
	}

	public function revoke_button( int $course_id, int $user_id ): void {
		\printf( '<form method="post" action="%s">', \esc_url( \admin_url( 'admin-post.php' ) ) );
		\wp_nonce_field( self::NONCE . '_' . $course_id );
		echo '<input type="hidden" name="action" value="anchor_courses_revoke_access" />';
		\printf( '<input type="hidden" name="course_id" value="%d" />', $course_id );
		\printf( '<input type="hidden" name="user_id" value="%d" />', $user_id );
		\printf( '<button type="submit" class="button-link">%s</button>', \esc_html__( 'Revoke', 'anchor-schema' ) );
		echo '</form>';
	}

	public function handle_add_learner(): void {
		$course_id = $this->authorise();

		$name  = \sanitize_text_field( \wp_unslash( (string) ( $_POST['learner_name'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$email = \sanitize_email( \wp_unslash( (string) ( $_POST['learner_email'] ?? '' ) ) );     // phpcs:ignore WordPress.Security.NonceVerification

		$user_id = Accounts::ensure_user( $name, $email );
		if ( $user_id <= 0 ) {
			$this->redirect( $course_id, 'no_user' );
		}

		// The one door. grant_access() checks can_enroll() first, so an unmet
		// prerequisite refuses here exactly as it does at the checkout.
		$result = Roles::grant_access( $user_id, $course_id, 'manual', (string) \get_current_user_id() );

		$this->redirect(
			$course_id,
			\is_wp_error( $result ) ? (string) $result->get_error_code() : 'learner_added'
		);
	}

	public function handle_revoke(): void {
		$course_id = $this->authorise();
		$user_id   = \absint( $_POST['user_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification

		if ( $user_id <= 0 ) {
			$this->redirect( $course_id, 'no_user' );
		}

		// revoke_access() returns false when the user never held the role - a
		// no-op that must not be reported as a success.
		$revoked = Roles::revoke_access( $user_id, $course_id, 'manual', (string) \get_current_user_id() );

		$this->redirect( $course_id, $revoked ? 'access_revoked' : 'revoke_failed' );
	}

	/** Nonce + capability, or redirect and stop. @return int The course id. */
	private function authorise(): int {
		$course_id = \absint( $_POST['course_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification

		$nonce = \sanitize_text_field( \wp_unslash( (string) ( $_REQUEST['_wpnonce'] ?? '' ) ) );
		if ( ! \wp_verify_nonce( $nonce, self::NONCE . '_' . $course_id ) ) {
			$this->redirect( $course_id, 'bad_nonce' );
		}
		if ( ! Capabilities::current_user_can( 'enrollments' ) ) {
			$this->redirect( $course_id, 'forbidden' );
		}

		return $course_id;
	}

	private function redirect( int $course_id, string $code ): void {
		$target = $course_id > 0 ? (string) \get_edit_post_link( $course_id, 'raw' ) : \admin_url();

		\wp_safe_redirect( \add_query_arg( 'anchor_courses_admin_notice', \sanitize_key( $code ), $target ) );
		exit;
	}

	/**
	 * Every course this learner is enrolled in, for the user-profile Courses
	 * block. Not paginated - bounded by one learner's own enrollment count,
	 * not by a report page size, so the N+1 ruling that governs rows() does
	 * not apply here the same way; this loops CreditService/CertificateService
	 * per course exactly as the brief's interface lists them as consumed.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function user_rows( int $user_id ): array {
		$progress_service    = new ProgressService();
		$credit_service      = new CreditService();
		// CertificateService takes no constructor args (it links to a credit
		// via CreditRepository internally, class_exists()-guarded) - the
		// brief's sample constructor call does not match the shipped class.
		$certificate_service = new CertificateService();

		$rows = [];

		foreach ( EnrollmentRepository::for_user( $user_id ) as $enrollment ) {
			$credit      = $credit_service->get( $user_id, $enrollment->course_id );
			$certificate = $certificate_service->get( $user_id, $enrollment->course_id );

			$attempts = [];
			foreach ( QuizAttemptRepository::for_user_course( $user_id, $enrollment->course_id ) as $attempt ) {
				$attempts[] = [
					'quiz_id'        => $attempt->quiz_id,
					'quiz_title'     => (string) \get_the_title( $attempt->quiz_id ),
					'attempt_number' => $attempt->attempt_number,
					'score'          => $attempt->score,
					'passed'         => $attempt->passed,
					'submitted_at'   => (string) ( $attempt->submitted_at ?? '' ),
				];
			}

			$rows[] = [
				'course_id'          => $enrollment->course_id,
				'course_title'       => (string) \get_the_title( $enrollment->course_id ),
				'status'             => $enrollment->status,
				'percent'            => $progress_service->get_course_progress( $user_id, $enrollment->course_id )->percent,
				'attempts'           => $attempts,
				'credits'            => $credit instanceof Credit ? $credit->credits : 0.0,
				'certificate_number' => $certificate instanceof Certificate ? $certificate->certificate_number : '',
				'certificate_url'    => $certificate instanceof Certificate ? $certificate->url() : '',
			];
		}

		return $rows;
	}

	/**
	 * The "Courses" block on a user's profile screen (show_user_profile /
	 * edit_user_profile). Same reports gate as the metabox, with one
	 * exception: a user may always see their OWN courses without the reports
	 * capability - this is their profile, not somebody else's report.
	 */
	public function render_user_profile( \WP_User $user ): void {
		if ( ! Capabilities::current_user_can( 'reports' ) && \get_current_user_id() !== (int) $user->ID ) {
			return;
		}

		$rows = self::user_rows( (int) $user->ID );

		echo '<h2 id="anchor-courses">' . \esc_html__( 'Courses', 'anchor-schema' ) . '</h2>';

		if ( [] === $rows ) {
			echo '<p>' . \esc_html__( 'No enrolments.', 'anchor-schema' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped anchor-courses-report"><thead><tr>';
		foreach ( [
			\__( 'Course', 'anchor-schema' ),
			\__( 'Status', 'anchor-schema' ),
			\__( 'Progress', 'anchor-schema' ),
			\__( 'Quiz attempts', 'anchor-schema' ),
			\__( 'Credits', 'anchor-schema' ),
			\__( 'Certificate', 'anchor-schema' ),
		] as $heading ) {
			echo '<th>' . \esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			echo '<tr>';
			\printf(
				'<td><a href="%s">%s</a></td>',
				\esc_url( (string) \get_edit_post_link( (int) $row['course_id'], 'raw' ) ),
				\esc_html( (string) $row['course_title'] )
			);
			\printf( '<td>%s</td>', \esc_html( (string) $row['status'] ) );
			\printf( '<td>%s%%</td>', \esc_html( \number_format_i18n( (float) $row['percent'], 0 ) ) );

			echo '<td>';
			foreach ( (array) $row['attempts'] as $attempt ) {
				\printf(
					'<div>%s #%d: %s%s</div>',
					\esc_html( (string) $attempt['quiz_title'] ),
					(int) $attempt['attempt_number'],
					\esc_html( null === $attempt['score'] ? '-' : \number_format_i18n( (float) $attempt['score'], 0 ) . '%' ),
					$attempt['passed'] ? ' ' . \esc_html__( '(passed)', 'anchor-schema' ) : ''
				);
			}
			echo '</td>';

			\printf( '<td>%s</td>', \esc_html( \number_format_i18n( (float) $row['credits'], 1 ) ) );
			if ( '' !== $row['certificate_number'] ) {
				\printf(
					'<td><a href="%s">%s</a></td>',
					\esc_url( (string) $row['certificate_url'] ),
					\esc_html( (string) $row['certificate_number'] )
				);
			} else {
				echo '<td>-</td>';
			}
			echo '</tr>';
		}

		echo '</tbody></table>';
	}
}
