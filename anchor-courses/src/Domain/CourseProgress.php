<?php
declare(strict_types=1);

namespace Anchor\Courses\Domain;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * The rolled-up answer to "how far through is this learner" (brief 10).
 *
 * The return type of anchor_courses_get_progress(). Derived, never stored:
 * the progress table is the source of truth and this is recomputed from it.
 */
final class CourseProgress {

	/**
	 * @param string[] $completed_item_keys "lesson:12", "quiz:15", ...
	 */
	public function __construct(
		public readonly int $user_id,
		public readonly int $course_id,
		public readonly int $completed_required,
		public readonly int $total_required,
		public readonly float $percent,
		public readonly bool $complete,
		public readonly array $completed_item_keys
	) {}

	public function to_array(): array {
		return [
			'user_id'             => $this->user_id,
			'course_id'           => $this->course_id,
			'completed_required'  => $this->completed_required,
			'total_required'      => $this->total_required,
			'percent'             => $this->percent,
			'complete'            => $this->complete,
			'completed_item_keys' => $this->completed_item_keys,
		];
	}
}
