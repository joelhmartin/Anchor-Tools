<?php
declare(strict_types=1);

namespace Anchor\Courses\Services;

use Anchor\Courses\Database\CreditRepository;
use Anchor\Courses\Database\EnrollmentRepository;
use Anchor\Courses\Domain\Certificate;
use Anchor\Courses\Domain\Credit;
use Anchor\Courses\Domain\Enrollment;
use Anchor\Courses\Support\Clock;
use Anchor\Courses\Support\Log;
use Anchor\Courses\Support\Roles;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Course completion: the once-only pipeline (brief 11, 26, rule 7).
 *
 * enrolment -> `completed` + `completed_at` -> award CE -> issue certificate
 * -> grant the completion role -> fire `anchor_courses_course_completed`.
 *
 * The idempotency guard is `EnrollmentRepository::complete()`'s atomic
 * conditional UPDATE (`WHERE id = %d AND status <> 'completed'`, checked via
 * `$wpdb->rows_affected`), not a read-then-write check in this class. Two
 * concurrent calls for the same user+course can therefore never both pass:
 * exactly one UPDATE matches the row, so exactly one caller ever reaches
 * `award()`/`issue()`/`grant_completed()`/the hooks. The earlier
 * `is_complete()` check below is a cheap short-circuit for the common case
 * (already done, nothing to evaluate) - it is not what makes this safe.
 *
 * This also means `CreditService::award()` and `CertificateService::issue()`
 * are relied on as primitives, not as their own concurrency guards: both
 * return an existing row (and fire nothing) on a second call, but their
 * `insert_ignore()` also fires the awarded/issued hook on ANY non-null
 * result - including a row a concurrent caller just inserted (Task 27
 * review). That double-fire can only happen if two callers reach `award()`/
 * `issue()` for the same (user, course) at the same time, and the pipeline
 * gate above prevents exactly that for course completion. Their signatures
 * are unchanged.
 */
final class CompletionService {

	public function __construct(
		private EnrollmentService $enrollments,
		private ProgressService $progress,
		private ?CreditService $credits = null,
		private ?CertificateService $certificates = null
	) {
		$this->credits      = $credits ?? new CreditService();
		$this->certificates = $certificates ?? new CertificateService();
	}

	/** Has this course already been recorded as complete? */
	public function is_complete( int $user_id, int $course_id ): bool {
		$enrollment = EnrollmentRepository::find( $user_id, $course_id );
		return $enrollment instanceof Enrollment && $enrollment->is_complete();
	}

	/** Should it be? Pure read - no side effects. */
	public function evaluate( int $user_id, int $course_id ): bool {
		return $this->progress->get_course_progress( $user_id, $course_id )->complete;
	}

	/**
	 * Run the completion pipeline.
	 *
	 * @return bool True only for the call that performed the transition.
	 */
	public function complete( int $user_id, int $course_id ): bool {
		$enrollment = EnrollmentRepository::find( $user_id, $course_id );
		if ( ! $enrollment instanceof Enrollment ) {
			return false;
		}
		if ( $enrollment->is_complete() ) {
			return false; // Cheap short-circuit; see class docblock for what actually guards this.
		}
		// Only an active enrolment can complete. A learner cancelled or expired
		// mid-attempt reaches here through QuizService::submit(), which does not
		// re-check enrolment; without this line a closed row would flip straight
		// to completed and mint credit, certificate and role for a revoked user.
		if ( ! $enrollment->is_active() ) {
			return false;
		}
		if ( ! $this->evaluate( $user_id, $course_id ) ) {
			return false;
		}

		$now = Clock::now();
		$from = $enrollment->status;

		// The gate (ruling R-once): only the caller whose UPDATE matches a
		// still-not-completed row proceeds past this line.
		if ( ! EnrollmentRepository::complete( $enrollment->id, $now ) ) {
			return false;
		}

		$updated = EnrollmentRepository::find_by_id( $enrollment->id );
		if ( ! $updated instanceof Enrollment ) {
			return false; // Defensive: the row we just updated should always be readable.
		}

		/** This action is documented in EnrollmentService::set_status(). */
		\do_action( 'anchor_courses_enrollment_status_changed', $user_id, $course_id, $from, 'completed' );

		// Order matters (brief 12 step 3): the certificate links to the credit
		// record, and CertificateService::issue() only links a credit that
		// already exists - so credits are awarded first.
		$credit      = $this->credits->award( $user_id, $course_id );
		$certificate = $this->certificates->issue( $user_id, $course_id );

		// Wire the link explicitly (ruling R-credit-link) rather than relying
		// solely on CertificateService's own fallback: re-read the credit in
		// case issue() already attached it, and attach here if not.
		if ( $credit instanceof Credit && $certificate instanceof Certificate ) {
			$credit = CreditRepository::find( $user_id, $course_id ) ?? $credit;
			if ( 0 === $credit->certificate_id ) {
				CreditRepository::attach_certificate( $credit->id, $certificate->id );
			}
		}

		// Mint (lazily) and grant the COMPLETION role. This is the slug another
		// course or an event lists as a prerequisite (design spec 3.1). It is a
		// different role from the access role the learner already holds, and
		// granting it never touches their enrolment - Support\Roles' listener
		// matches the access slug only.
		Roles::grant_completed( $user_id, $course_id );

		Log::write( 'course_completed', [ 'user' => $user_id, 'course' => $course_id ] );

		/**
		 * Fires once, when a learner completes a course.
		 *
		 * @param int        $user_id
		 * @param int        $course_id
		 * @param Enrollment $updated
		 */
		\do_action( 'anchor_courses_course_completed', $user_id, $course_id, $updated );

		return true;
	}

	/**
	 * Reverse the completion flag (admin action).
	 *
	 * Credits and certificates are NOT revoked, and the completion role is NOT
	 * removed: they are a record of something that happened, and quietly
	 * deleting them would rewrite history.
	 */
	public function uncomplete( int $user_id, int $course_id ): bool {
		$enrollment = EnrollmentRepository::find( $user_id, $course_id );
		if ( ! $enrollment instanceof Enrollment || ! $enrollment->is_complete() ) {
			return false;
		}

		EnrollmentRepository::update( $enrollment->id, [ 'status' => 'in_progress', 'completed_at' => null ] );

		\do_action( 'anchor_courses_enrollment_status_changed', $user_id, $course_id, 'completed', 'in_progress' );

		return true;
	}
}
