<?php
declare(strict_types=1);

namespace Anchor\Courses\Services;

use Anchor\Courses\Admin\CourseEditor;
use Anchor\Courses\Content\CoursePostType;
use Anchor\Courses\Database\CertificateRepository;
use Anchor\Courses\Database\CreditRepository;
use Anchor\Courses\Domain\Certificate;
use Anchor\Courses\Domain\Credit;
use Anchor\Courses\Frontend\Templates;
use Anchor\Courses\Support\Clock;
use Anchor\Courses\Support\Log;
use Anchor\Courses\Support\Uuid;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Certificates (brief 13).
 *
 * Phase 1 is an HTML page with a print stylesheet and a public verification
 * route (Task 30); `file_path` is deliberately left empty so that adding PDF
 * generation later needs no schema change (design spec 1).
 *
 * `issue()` links a matching CE credit to the new certificate and freezes
 * what the certificate vouches for (learner name, course name, credits,
 * provider, instructor) into its metadata; `template_data()` renders that
 * snapshot. Both prefer the learner's actual awarded `credits` value over
 * the course's configured default - both read `Database\CreditRepository`
 * directly (Task 27/28 join; no `class_exists()` guard - credits are
 * unconditionally on this tree). Neither is required for a certificate to be
 * issued or rendered: `issue()` only links a credit that already exists, so
 * `Services\CompletionService` (Task 29) awards the credit before issuing the
 * certificate, and that pipeline wires the link explicitly too rather than
 * depending solely on this fallback.
 */
final class CertificateService {

	/** Why the last issue() returned null: not_a_course|no_user|certificates_disabled, or ''. */
	private string $last_skip_reason = '';

	/** Why the most recent issue() call returned null ('' after a Certificate or a WP_Error). */
	public function last_skip_reason(): string {
		return $this->last_skip_reason;
	}

	/**
	 * AC-{YYYY}-{8-digit zero-padded id}. Pure.
	 *
	 * The id comes from the table's own AUTO_INCREMENT, never a counter
	 * option, so two simultaneous issues cannot collide (design spec 4).
	 */
	public static function format_number( int $id, int $year ): string {
		return \sprintf( 'AC-%04d-%08d', $year, $id );
	}

	/**
	 * Issue (or return) the certificate for this learner and course.
	 *
	 * Null when the course, the user, or `certificate_enabled` say no
	 * certificate should exist (the reason is in last_skip_reason());
	 * WP_Error `certificate_insert_failed` when one was due but could not be
	 * written (audit F02); otherwise idempotent (brief rule 7 / D13): a
	 * second call for the same user+course returns the existing row, never
	 * mints a second one.
	 */
	public function issue( int $user_id, int $course_id ): Certificate|\WP_Error|null {
		$this->last_skip_reason = '';
		if ( CoursePostType::CPT !== \get_post_type( $course_id ) ) {
			$this->last_skip_reason = 'not_a_course';
			return null;
		}
		if ( ! \get_userdata( $user_id ) ) {
			$this->last_skip_reason = 'no_user';
			return null;
		}
		if ( 1 !== (int) CourseEditor::setting( $course_id, 'certificate_enabled' ) ) {
			$this->last_skip_reason = 'certificates_disabled';
			return null;
		}

		$existing = CertificateRepository::find( $user_id, $course_id );
		if ( $existing instanceof Certificate ) {
			return $existing;
		}

		$expires_days = (int) CourseEditor::setting( $course_id, 'ce_expires_days' );

		$certificate = CertificateRepository::insert_ignore(
			[
				'user_id'            => $user_id,
				'course_id'          => $course_id,
				'issued_at'          => Clock::now(),
				'expires_at'         => $expires_days > 0 ? Clock::offset( $expires_days * DAY_IN_SECONDS ) : null,
				'verification_token' => Uuid::v4(),
				// What the certificate vouches for, frozen now (final review
				// I7): template_data() renders these, so a later rename of the
				// learner or the course cannot rewrite an issued certificate.
				// $configured_fallback = false: the certificate vouches for
				// what was actually AWARDED (CodeRabbit PR #29). award() can
				// return null with no Credit row - a free course, or the
				// anchor_courses_ce_credit_amount filter zeroing the amount -
				// and this snapshot must record 0, never the course's
				// configured ce_credits, or the public verification page
				// shows credits nobody received.
				'metadata'           => [ 'template' => (string) CourseEditor::setting( $course_id, 'certificate_template' ) ]
					+ self::live_values( $user_id, $course_id, false ),
			]
		);

		// Also the clean-failure path for an (astronomically unlikely)
		// verification_token collision: insert_ignore() is INSERT IGNORE, so
		// a collision on the now-UNIQUE verification_token key (Migrations
		// 1.2.0) - not only the (user_id, course_id) key - silently writes no
		// row, and find() for THIS pair then finds nothing either. Same as any
		// failed insert; never a fatal (see
		// tests/test-courses-certificate-repo.php's collision test for the
		// repository-level proof). A WP_Error, not null (audit F02): null
		// means "no certificate is due", this means "due, and not written".
		if ( ! $certificate instanceof Certificate ) {
			Log::write( 'certificate_issue_failed', [ 'user' => $user_id, 'course' => $course_id ] );
			return new \WP_Error( 'certificate_insert_failed', \__( 'The certificate could not be saved.', 'anchor-schema' ) );
		}

		// Two-write numbering (deviation D14): the id is only known after
		// insert. A row this call did not create (a concurrent winner already
		// holds it) already carries its real number, so this is skipped.
		if ( 0 === \strpos( $certificate->certificate_number, 'PENDING-' ) ) {
			$numbered = CertificateRepository::set_number(
				$certificate->id,
				self::format_number( $certificate->id, (int) \gmdate( 'Y', Clock::timestamp() ) )
			);
			if ( $numbered instanceof Certificate ) {
				$certificate = $numbered;
			}
		}

		// Link a matching CE credit to this certificate when one is already
		// there (brief 12 step 3). One-directional: a credit awarded AFTER
		// this call is never linked retroactively, so CompletionService (Task
		// 29) awards the credit before issuing the certificate.
		$credit = CreditRepository::find( $user_id, $course_id );
		if ( $credit instanceof Credit && 0 === $credit->certificate_id ) {
			CreditRepository::attach_certificate( $credit->id, $certificate->id );
		}

		Log::write( 'certificate_issued', [ 'user' => $user_id, 'course' => $course_id, 'number' => $certificate->certificate_number ] );

		/**
		 * Fires once, when a certificate is issued.
		 *
		 * @param int         $user_id
		 * @param int         $course_id
		 * @param Certificate $certificate
		 */
		\do_action( 'anchor_courses_certificate_issued', $user_id, $course_id, $certificate );

		return $certificate;
	}

	public function get( int $user_id, int $course_id ): ?Certificate {
		return CertificateRepository::find( $user_id, $course_id );
	}

	public function get_by_token( string $token ): ?Certificate {
		return CertificateRepository::find_by_token( $token );
	}

	/** @return Certificate[] */
	public function for_user( int $user_id ): array {
		return CertificateRepository::for_user( $user_id );
	}

	/**
	 * The snapshot keys issue() freezes into the certificate's metadata.
	 * `credits` is the learner's awarded credit when one exists (the pipeline
	 * awards before it issues), else the course's configured value.
	 */
	public const SNAPSHOT_KEYS = [ 'learner_name', 'course_name', 'credits', 'provider_name', 'provider_number', 'instructor_name' ];

	/**
	 * @param bool $configured_fallback Whether an absent Credit row falls back
	 *                                  to the course's CONFIGURED ce_credits.
	 *                                  issue() passes false: the certificate
	 *                                  vouches for what was awarded, and "no
	 *                                  credit row" must snapshot 0, not the
	 *                                  setting (CodeRabbit PR #29).
	 *                                  template_data()'s legacy-certificate
	 *                                  fallback (no snapshot at all) keeps the
	 *                                  default true, since that path renders a
	 *                                  certificate issued before this existed.
	 * @return array{learner_name:string,course_name:string,credits:float,provider_name:string,provider_number:string,instructor_name:string}
	 */
	private static function live_values( int $user_id, int $course_id, bool $configured_fallback = true ): array {
		$user   = \get_userdata( $user_id );
		$credit = CreditRepository::find( $user_id, $course_id );

		return [
			'learner_name'    => $user ? (string) $user->display_name : '',
			'course_name'     => (string) \get_the_title( $course_id ),
			'credits'         => $credit instanceof Credit
				? $credit->credits
				: ( $configured_fallback ? (float) CourseEditor::setting( $course_id, 'ce_credits' ) : 0.0 ),
			'provider_name'   => (string) CourseEditor::setting( $course_id, 'ce_provider_name' ),
			'provider_number' => (string) CourseEditor::setting( $course_id, 'ce_provider_number' ),
			'instructor_name' => (string) CourseEditor::setting( $course_id, 'instructor' ),
		];
	}

	/**
	 * The brief section 13 template variables, plus the verification URL.
	 *
	 * Rendered from the snapshot issue() stored (final review I7). A row
	 * issued before snapshots existed has none of those keys and falls back
	 * to live values - key by key, so a partial snapshot still prefers what
	 * it has.
	 */
	public function template_data( Certificate $certificate ): array {
		$snapshot = \array_intersect_key( $certificate->metadata, \array_flip( self::SNAPSHOT_KEYS ) );
		$values   = \count( $snapshot ) === \count( self::SNAPSHOT_KEYS )
			? $snapshot
			: $snapshot + self::live_values( $certificate->user_id, $certificate->course_id );

		$data = [
			'learner_name'       => (string) $values['learner_name'],
			'course_name'        => (string) $values['course_name'],
			'completion_date'    => $certificate->issued_at,
			'ce_credits'         => (float) $values['credits'],
			'certificate_number' => $certificate->certificate_number,
			'instructor_name'    => (string) $values['instructor_name'],
			'provider_name'      => (string) $values['provider_name'],
			'provider_number'    => (string) $values['provider_number'],
			'expiration_date'    => (string) ( $certificate->expires_at ?? '' ),
			'verification_url'   => $certificate->url(),
		];

		/**
		 * Filter the certificate template variables.
		 *
		 * @param array       $data
		 * @param Certificate $certificate
		 */
		return (array) \apply_filters( 'anchor_courses_certificate_data', $data, $certificate );
	}

	/**
	 * Which template file renders this certificate.
	 *
	 * The course's "Certificate template slug" setting is frozen into the
	 * certificate's metadata at issue, so a certificate keeps the design it
	 * was issued with. A slug other than `default` selects
	 * `certificate-{slug}.php` (theme override under `anchor-courses/`, then
	 * the plugin's templates/); when no such file exists the default
	 * `certificate.php` is used, so a typo can never blank a certificate.
	 */
	public function template_name( Certificate $certificate ): string {
		$slug = \sanitize_key( (string) ( $certificate->metadata['template'] ?? 'default' ) );
		if ( '' === $slug || 'default' === $slug ) {
			return 'certificate';
		}
		$candidate = 'certificate-' . $slug;
		return \file_exists( Templates::locate( $candidate ) ) ? $candidate : 'certificate';
	}

	/** Render the HTML certificate. All values are escaped in the template. */
	public function render( Certificate $certificate ): string {
		return Templates::render(
			$this->template_name( $certificate ),
			[ 'certificate' => $certificate, 'data' => $this->template_data( $certificate ) ]
		);
	}
}
