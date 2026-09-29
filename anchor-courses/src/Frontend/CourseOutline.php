<?php
declare(strict_types=1);

namespace Anchor\Courses\Frontend;

use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Domain\CourseProgress;
use Anchor\Courses\Services\ProgressService;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * The course navigation a lesson page or a quiz step shows: the curriculum
 * outline (every module and item, each with its state for this learner) and
 * the previous / next step pair.
 *
 * Data only; templates/course-outline.php and templates/lesson-nav.php draw
 * it, and every step (templates/lesson.php, templates/live-session.php,
 * templates/quiz-step.php) gets it through Shortcodes, so there is one
 * outline, not one per template.
 *
 * Nothing here decides access on its own: availability is
 * ProgressService::availability() (is_item_available() for every item, the
 * authority the course page, the content guard and the quiz service all
 * ask) and the percentage is ProgressService::get_course_progress(), passed
 * in by the caller that already computed it.
 *
 * Unpublished items are left out: a draft, pending or private lesson or quiz
 * has no public route (ProgressService never makes one available and never
 * counts it toward the total), so listing it would only show a learner
 * something they can never open.
 */
final class CourseOutline {

	/** An item's state for this learner. */
	public const STATE_CURRENT   = 'current';
	public const STATE_DONE      = 'done';
	public const STATE_AVAILABLE = 'available';
	public const STATE_LOCKED    = 'locked';

	/** The fragment a step's footer (completion form + next/previous bar) carries. */
	public const FOOTER_ID = 'anchor-lesson-footer';

	public function __construct( private ProgressService $progress ) {}

	/**
	 * Where a curriculum item opens, in this course: a lesson's page or a
	 * quiz's step (Access::item_url()).
	 */
	public static function item_url( string $type, int $item_id, int $course_id ): string {
		return Access::item_url( $type, $item_id, $course_id );
	}

	/** The id attribute templates/course.php gives each curriculum item. */
	public static function item_anchor( string $type, int $item_id ): string {
		return 'anchor-course-item-' . \sanitize_key( $type ) . '-' . $item_id;
	}

	/**
	 * @param int                 $course_id  The course the step is in (Access::course_for_lesson(), or the quiz step's course).
	 * @param int                 $user_id    0 for a visitor: every item then reads locked.
	 * @param int                 $item_id    The lesson or quiz on screen.
	 * @param CourseProgress|null $progress   The caller's get_course_progress() result, or null for a visitor.
	 * @param string              $item_type  'lesson' or 'quiz': what $item_id is.
	 * @return array{
	 *     course_id:int, course_title:string, course_url:string, lesson_id:int,
	 *     current:array{type:string,id:int},
	 *     progress:?CourseProgress,
	 *     modules:array<int,array{id:string,title:string,items:array<int,array{type:string,id:int,title:string,url:string,required:bool,current:bool,complete:bool,available:bool,state:string}>}>,
	 *     previous:?array{type:string,id:int,title:string,url:string,available:bool,complete:bool},
	 *     next:?array{type:string,id:int,title:string,url:string,available:bool,complete:bool}
	 * }
	 */
	public function build( int $course_id, int $user_id, int $item_id, ?CourseProgress $progress, string $item_type = 'lesson' ): array {
		$completed    = $progress ? $progress->completed_item_keys : [];
		$availability = $user_id > 0 ? $this->progress->availability( $user_id, $course_id ) : [];
		$modules      = [];
		$steps        = []; // Every item, lessons and quizzes: the walk previous/next steps through.

		foreach ( Curriculum::get( $course_id ) as $module ) {
			$items = [];
			foreach ( $module['items'] as $item ) {
				$type    = (string) $item['type'];
				$id      = (int) $item['id'];
				$key     = $type . ':' . $id;
				$current = $type === $item_type && $id === $item_id;
				// The step on screen stays even when unpublished: that is an
				// editor's preview, and the outline should still show where it sits.
				if ( ! $current && 'publish' !== \get_post_status( $id ) ) {
					continue;
				}

				$complete  = \in_array( $key, $completed, true );
				$available = $availability[ $key ] ?? false;

				if ( $current ) {
					$state = self::STATE_CURRENT;
				} elseif ( $complete ) {
					$state = self::STATE_DONE;
				} elseif ( $available ) {
					$state = self::STATE_AVAILABLE;
				} else {
					$state = self::STATE_LOCKED;
				}

				$row = [
					'type'      => $type,
					'id'        => $id,
					'title'     => (string) \get_the_title( $id ),
					// No link to something this learner cannot open: a locked
					// step would only show the locked notice.
					'url'       => $available || $current ? self::item_url( $type, $id, $course_id ) : '',
					'required'  => (bool) $item['required'],
					'current'   => $current,
					'complete'  => $complete,
					'available' => $available,
					'state'     => $state,
				];

				$items[] = $row;
				$steps[] = $row;
			}

			$modules[] = [
				'id'    => (string) $module['id'],
				'title' => (string) $module['title'],
				'items' => $items,
			];
		}

		$previous = null;
		$next     = null;
		foreach ( $steps as $position => $row ) {
			if ( $row['current'] ) {
				$previous = $steps[ $position - 1 ] ?? null;
				$next     = $steps[ $position + 1 ] ?? null;
				break;
			}
		}

		return [
			'course_id'    => $course_id,
			'course_title' => (string) \get_the_title( $course_id ),
			'course_url'   => (string) \get_permalink( $course_id ),
			// Kept for templates written when only a lesson could be on screen.
			'lesson_id'    => 'lesson' === $item_type ? $item_id : 0,
			'current'      => [ 'type' => $item_type, 'id' => $item_id ],
			'progress'     => $progress,
			'modules'      => $modules,
			'previous'     => null === $previous ? null : self::neighbour( $previous, $course_id ),
			'next'         => null === $next ? null : self::neighbour( $next, $course_id ),
		];
	}

	/**
	 * A previous/next step. Its url is always set (the step itself explains
	 * a lock); `available` tells the template whether to offer it as a link.
	 *
	 * @return array{type:string,id:int,title:string,url:string,available:bool,complete:bool}
	 */
	private static function neighbour( array $row, int $course_id ): array {
		return [
			'type'      => $row['type'],
			'id'        => $row['id'],
			'title'     => $row['title'],
			'url'       => self::item_url( $row['type'], $row['id'], $course_id ),
			'available' => $row['available'],
			'complete'  => $row['complete'],
		];
	}
}
