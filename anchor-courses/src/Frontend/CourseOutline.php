<?php
declare(strict_types=1);

namespace Anchor\Courses\Frontend;

use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Domain\CourseProgress;
use Anchor\Courses\Services\ProgressService;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * The course navigation a lesson page shows: the curriculum outline (every
 * module and item, each with its state for this learner) and the previous /
 * next lesson pair.
 *
 * Data only; templates/course-outline.php and templates/lesson-nav.php draw
 * it, and both lesson types (templates/lesson.php, templates/live-session.php)
 * get it through Shortcodes::render_lesson(), so there is one outline, not one
 * per lesson template.
 *
 * Nothing here decides access on its own: availability is
 * ProgressService::is_item_available() (the authority the course page, the
 * content guard and the quiz service all ask) and the percentage is
 * ProgressService::get_course_progress(), passed in by the caller that
 * already computed it.
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

	/** The fragment the lesson footer (completion form + next/previous bar) carries. */
	public const FOOTER_ID = 'anchor-lesson-footer';

	public function __construct( private ProgressService $progress ) {}

	/**
	 * Where a curriculum item renders. A lesson has its own URL (with the
	 * course context, Access::lesson_url()); a quiz has none (it renders
	 * inside the course page, QuizPostType is not publicly queryable), so it
	 * links to its own item on the course page.
	 */
	public static function item_url( string $type, int $item_id, int $course_id ): string {
		if ( 'lesson' === $type ) {
			return Access::lesson_url( $item_id, $course_id );
		}
		return (string) \get_permalink( $course_id ) . '#' . self::item_anchor( $type, $item_id );
	}

	/** The id attribute templates/course.php gives each curriculum item. */
	public static function item_anchor( string $type, int $item_id ): string {
		return 'anchor-course-item-' . \sanitize_key( $type ) . '-' . $item_id;
	}

	/**
	 * @param int                 $course_id  The course the lesson is read in (Access::course_for_lesson()).
	 * @param int                 $user_id    0 for a visitor: every item then reads locked.
	 * @param int                 $lesson_id  The lesson on screen.
	 * @param CourseProgress|null $progress   The caller's get_course_progress() result, or null for a visitor.
	 * @return array{
	 *     course_id:int, course_title:string, course_url:string, lesson_id:int,
	 *     progress:?CourseProgress,
	 *     modules:array<int,array{id:string,title:string,items:array<int,array{type:string,id:int,title:string,url:string,required:bool,current:bool,complete:bool,available:bool,state:string}>}>,
	 *     previous:?array{id:int,title:string,url:string,available:bool,complete:bool},
	 *     next:?array{id:int,title:string,url:string,available:bool,complete:bool}
	 * }
	 */
	public function build( int $course_id, int $user_id, int $lesson_id, ?CourseProgress $progress ): array {
		$completed = $progress ? $progress->completed_item_keys : [];
		$modules   = [];
		$lessons   = []; // The lessons-only walk previous/next steps through.

		foreach ( Curriculum::get( $course_id ) as $module ) {
			$items = [];
			foreach ( $module['items'] as $item ) {
				$type    = (string) $item['type'];
				$item_id = (int) $item['id'];
				$current = 'lesson' === $type && $item_id === $lesson_id;
				// The lesson on screen stays even when unpublished: that is an
				// editor's preview, and the outline should still show where it sits.
				if ( ! $current && 'publish' !== \get_post_status( $item_id ) ) {
					continue;
				}

				$complete  = \in_array( $type . ':' . $item_id, $completed, true );
				$available = $user_id > 0 && $this->progress->is_item_available( $user_id, $course_id, $item_id, $type );

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
					'id'        => $item_id,
					'title'     => (string) \get_the_title( $item_id ),
					// No link to something this learner cannot open: a locked
					// lesson would only show the locked notice.
					'url'       => $available || $current ? self::item_url( $type, $item_id, $course_id ) : '',
					'required'  => (bool) $item['required'],
					'current'   => $current,
					'complete'  => $complete,
					'available' => $available,
					'state'     => $state,
				];

				$items[] = $row;
				if ( 'lesson' === $type ) {
					$lessons[] = $row;
				}
			}

			$modules[] = [
				'id'    => (string) $module['id'],
				'title' => (string) $module['title'],
				'items' => $items,
			];
		}

		// Previous/Next walk LESSONS only: a quiz has no URL of its own.
		$previous = null;
		$next     = null;
		foreach ( $lessons as $position => $row ) {
			if ( $row['current'] ) {
				$previous = $lessons[ $position - 1 ] ?? null;
				$next     = $lessons[ $position + 1 ] ?? null;
				break;
			}
		}

		return [
			'course_id'    => $course_id,
			'course_title' => (string) \get_the_title( $course_id ),
			'course_url'   => (string) \get_permalink( $course_id ),
			'lesson_id'    => $lesson_id,
			'progress'     => $progress,
			'modules'      => $modules,
			'previous'     => null === $previous ? null : self::neighbour( $previous, $course_id ),
			'next'         => null === $next ? null : self::neighbour( $next, $course_id ),
		];
	}

	/**
	 * A previous/next lesson. Its url is always set (the lesson page itself
	 * explains a lock); `available` tells the template whether to offer it
	 * as a link.
	 *
	 * @return array{id:int,title:string,url:string,available:bool,complete:bool}
	 */
	private static function neighbour( array $row, int $course_id ): array {
		return [
			'id'        => $row['id'],
			'title'     => $row['title'],
			'url'       => Access::lesson_url( $row['id'], $course_id ),
			'available' => $row['available'],
			'complete'  => $row['complete'],
		];
	}
}
