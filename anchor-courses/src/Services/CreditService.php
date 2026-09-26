<?php
declare(strict_types=1);

namespace Anchor\Courses\Services;

use Anchor\Courses\Admin\CourseEditor;
use Anchor\Courses\Content\CoursePostType;
use Anchor\Courses\Database\CreditRepository;
use Anchor\Courses\Domain\Credit;
use Anchor\Courses\Support\Clock;
use Anchor\Courses\Support\Log;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * CE credits as a first-class record, not a certificate side effect (brief 12).
 *
 * award() has three outcomes (audit F02): a Credit; null for "there is
 * nothing to award here" - a non-course, no such user, or a zero amount after
 * the filter - with the reason in last_skip_reason(); or a WP_Error
 * (`credit_insert_failed`) when a credit WAS due but could not be written.
 * CompletionService records the first two as done / not applicable and the
 * third as failed, so a retry knows what to repair.
 */
final class CreditService {

	/** Why the last award() returned null: not_a_course|no_user|no_credits, or ''. */
	private string $last_skip_reason = '';

	/** Why the most recent award() call returned null ('' after a Credit or a WP_Error). */
	public function last_skip_reason(): string {
		return $this->last_skip_reason;
	}

	/** @return array{credits:float,type:string,provider_name:string,provider_number:string,expires_days:int} */
	public function course_credit_config( int $course_id ): array {
		return [
			'credits'         => (float) CourseEditor::setting( $course_id, 'ce_credits' ),
			'type'            => (string) CourseEditor::setting( $course_id, 'ce_type' ),
			'provider_name'   => (string) CourseEditor::setting( $course_id, 'ce_provider_name' ),
			'provider_number' => (string) CourseEditor::setting( $course_id, 'ce_provider_number' ),
			'expires_days'    => (int) CourseEditor::setting( $course_id, 'ce_expires_days' ),
		];
	}

	/**
	 * Award CE credits. Idempotent per (user, course) - a second call returns the
	 * existing record and fires nothing (brief rule 7 / D13).
	 *
	 * @param float|null $credits Explicit amount, or null to use the course setting.
	 * @param array      $args    Optional `metadata` merged into the stored metadata.
	 * @return Credit|\WP_Error|null Null = nothing to award (see last_skip_reason());
	 *                               WP_Error `credit_insert_failed` = due but not written.
	 */
	public function award( int $user_id, int $course_id, ?float $credits = null, array $args = [] ): Credit|\WP_Error|null {
		$this->last_skip_reason = '';
		if ( CoursePostType::CPT !== \get_post_type( $course_id ) ) {
			$this->last_skip_reason = 'not_a_course';
			return null;
		}
		if ( ! \get_userdata( $user_id ) ) {
			$this->last_skip_reason = 'no_user';
			return null;
		}

		$existing = CreditRepository::find( $user_id, $course_id );
		if ( $existing instanceof Credit ) {
			return $existing;
		}

		$config = $this->course_credit_config( $course_id );
		$amount = null === $credits ? $config['credits'] : $credits;

		/**
		 * Filter the credit amount before the record is created.
		 *
		 * @param float $amount
		 * @param int   $user_id
		 * @param int   $course_id
		 */
		$amount = (float) \apply_filters( 'anchor_courses_ce_credit_amount', $amount, $user_id, $course_id );

		if ( $amount <= 0 ) {
			$this->last_skip_reason = 'no_credits';
			return null;
		}

		$data = [
			'user_id'     => $user_id,
			'course_id'   => $course_id,
			'credits'     => $amount,
			'credit_type' => $config['type'],
			'awarded_at'  => Clock::now(),
			'expires_at'  => $config['expires_days'] > 0 ? Clock::offset( $config['expires_days'] * DAY_IN_SECONDS ) : null,
			'metadata'    => \array_merge(
				[
					'provider_name'   => $config['provider_name'],
					'provider_number' => $config['provider_number'],
				],
				(array) ( $args['metadata'] ?? [] )
			),
		];

		/**
		 * Filter the whole credit row before insert.
		 *
		 * @param array $data
		 * @param int   $user_id
		 * @param int   $course_id
		 */
		$data = (array) \apply_filters( 'anchor_courses_ce_credit_data', $data, $user_id, $course_id );

		$credit = CreditRepository::insert_ignore( $data );
		if ( ! $credit instanceof Credit ) {
			Log::write( 'ce_award_failed', [ 'user' => $user_id, 'course' => $course_id ] );
			return new \WP_Error( 'credit_insert_failed', \__( 'The CE credit record could not be saved.', 'anchor-schema' ) );
		}

		Log::write( 'ce_awarded', [ 'user' => $user_id, 'course' => $course_id, 'credits' => $credit->credits ] );

		/**
		 * Fires once, when CE credits are recorded for a learner.
		 *
		 * @param int    $user_id
		 * @param int    $course_id
		 * @param Credit $credit
		 */
		\do_action( 'anchor_courses_ce_credit_awarded', $user_id, $course_id, $credit );

		return $credit;
	}

	public function get( int $user_id, int $course_id ): ?Credit {
		return CreditRepository::find( $user_id, $course_id );
	}

	/** @return Credit[] */
	public function for_user( int $user_id ): array {
		return CreditRepository::for_user( $user_id );
	}

	public function total_for_user( int $user_id ): float {
		return CreditRepository::total_for_user( $user_id );
	}
}
