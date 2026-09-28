<?php
declare(strict_types=1);

namespace Anchor\Courses\Integrations;

use Anchor\Courses\Admin\LessonEditor;
use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Content\LessonPostType;
use Anchor\Courses\Frontend\Access;
use Anchor\Courses\Services\EnrollmentService;
use Anchor\Courses\Services\ProgressService;
use Anchor\Courses\Support\Clock;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * The events module, seen from the courses side (design spec 3.3).
 *
 * A READER first: it resolves an event's sessions, its room URL and its
 * stream state so a `live_session` lesson can render. Task 37 gives it one
 * more job, and the only one that WRITES a decision rather than just
 * reporting one - it may veto stream access until a learner's pre-work is
 * done. That is the whole surface.
 *
 * It does not enrol anybody, and there is no auto-enrolment from events. An
 * event grants `anchor_event_{id}`, which this module treats as a
 * PREREQUISITE role, never an access role: `Support\Roles`' listener matches
 * `anchor_course_{digits}` and nothing else, so an attendee never silently
 * lands inside a course (design spec 7 - if that is wanted later it belongs
 * on the EVENT as an "also grant these roles" field, not as a picker here).
 *
 * Courses never queries event tables, seat posts or `_anchor_event_*` meta
 * directly - every read goes through `Anchor\Events\Module`'s own public API
 * (anchor-events-manager/EVENTS.md "Main Public API"). The events module is
 * optional, so every symbol this class touches is guarded and every
 * degraded path renders a notice rather than fatals.
 *
 * Progress note (binding, progress.md 2026-09-28): deviation D6 as written
 * in the original task brief is FALSE on this base - `Module::room_url()`,
 * `Module::resolved_sessions()`, `Stream_State::for_event()` and
 * `Entitlements::can_access_stream()` all exist on `main` already, with the
 * exact signatures this class calls. This is the real integration, not a
 * stub: `Events::sessions()` reads `resolved_sessions()` (which always
 * returns at least one row, session or not) rather than the narrower
 * `get_sessions()` the stale draft assumed. The normalisation below is kept
 * defensive anyway - if a future release of the events module ever narrows
 * that shape again, this still degrades instead of fataling. The same
 * correction applies to Task 37 below: `anchor_events_can_access_stream`
 * already exists and already fires (`Entitlements::can_access_stream()`),
 * so `veto_stream_access()` is wired against the real filter from the
 * start, not a placeholder waiting for the events module to catch up.
 */
final class Events {

	/**
	 * `\Anchor\Events\Module::CPT`. Duplicated as a fallback literal, used
	 * only when the class itself cannot be loaded (module inactive): the
	 * `event` post type string is a stable, documented contract
	 * (EVENTS.md), and `event_exists()` must still be answerable - e.g. for
	 * `LessonEditor::save()`'s validation - on a request where the events
	 * module happens not to be booted but the post still is what it is.
	 */
	private const EVENT_CPT_FALLBACK = 'event';

	/**
	 * Static, like every other symbol on this class (`sessions()`,
	 * `room_url()` ...): callers - and this class's own tests - reach
	 * `veto_stream_access()` as `Events::veto_stream_access()`, never
	 * through an instance. The constructor below is what SETS these, once,
	 * from the Module bootstrap's own `$progress`/`$enrollments` instances
	 * (one progress/enrolment source of truth, not two); the lazy fallbacks
	 * in `progress()`/`enrollments()` below exist only for a request where,
	 * for whatever reason, `new Integrations\Events()` never ran - mirroring
	 * `Support\Roles`'s own `self::$enrollments ?? new EnrollmentService()`
	 * pattern.
	 */
	private static ?ProgressService $progress = null;
	private static ?EnrollmentService $enrollments = null;

	/**
	 * Per-request memo for `live_lessons_for_event()`, keyed by event id.
	 *
	 * `veto_stream_access()` calls `live_lessons_for_event()` on every single
	 * `can_access_stream()` check - one `get_posts()` plus a
	 * `Curriculum::courses_for_item()` scan per lesson found, every time the
	 * room's stream-state JS polls or a page full of live-session lessons
	 * renders. Nothing about that answer can change mid-request except a
	 * save this same request makes, so it is memoised for the life of the
	 * request and dropped by `flush()` - hooked to `anchor_courses_
	 * curriculum_saved` and called directly from `LessonEditor::save()` -
	 * whenever a lesson's or a course's curriculum changes the answer out
	 * from under it.
	 *
	 * @var array<int,array<int,array{lesson_id:int,course_id:int,session_index:int}>>
	 */
	private static array $live_lessons_cache = [];

	/**
	 * The last veto per (event, user) this request - what blocked, so the
	 * room's denial copy (`anchor_events_room_denied_message`) and its REST
	 * poll can say WHY instead of "not registered" (final review I3). Set
	 * and cleared by veto_stream_access() itself, so it always reflects the
	 * decision the events module just acted on; dropped by flush().
	 *
	 * @var array<int,array<int,array{course_id:int,item_id:int,item_type:string}>>
	 */
	private static array $denials = [];

	/**
	 * Constructed unconditionally by the Module bootstrap (like
	 * ContentGuard/Templates), sharing the same `ProgressService`/
	 * `EnrollmentService` instances the rest of the module uses rather than
	 * minting its own.
	 */
	public function __construct( ?ProgressService $progress = null, ?EnrollmentService $enrollments = null ) {
		self::$enrollments = $enrollments ?? new EnrollmentService();
		self::$progress    = $progress ?? new ProgressService( self::$enrollments );

		// The events module's single "may this person watch?" filter (events
		// spec 4.5, `Entitlements::can_access_stream()`). Registered
		// unconditionally: attaching to a filter the (optional) events
		// module never fires is free, and it must already be attached
		// before that module's own bootstrap can possibly call it.
		\add_filter( 'anchor_events_can_access_stream', [ self::class, 'veto_stream_access' ], 10, 4 );

		// live_lessons_for_event()'s per-request memo (Task 37 fix round 1):
		// `Curriculum::save()` is the one place curriculum writes actually
		// happen - CourseEditor::save_curriculum() and every direct test
		// fixture both go through it - so its own `do_action()` is the
		// single correct invalidation point, not something re-derived at
		// each caller.
		\add_action( 'anchor_courses_curriculum_saved', [ self::class, 'flush' ] );

		// Say why a vetoed learner is out (final review I3). The events
		// module fires this only on its denied branch, right after the
		// can_access_stream() call veto_stream_access() recorded against.
		\add_filter( 'anchor_events_room_denied_message', [ self::class, 'denied_message' ], 10, 2 );
	}

	private static function enrollments(): EnrollmentService {
		return self::$enrollments ??= new EnrollmentService();
	}

	private static function progress(): ProgressService {
		return self::$progress ??= new ProgressService( self::enrollments() );
	}

	/** Is the events module booted in this request? */
	public static function available(): bool {
		return \class_exists( '\Anchor\Events\Module' )
			&& null !== \Anchor\Events\Module::instance();
	}

	/**
	 * Whether $event_id names a real `event` post - independent of whether
	 * the events module is currently booted (see EVENT_CPT_FALLBACK). Used
	 * by `LessonEditor::save()` to refuse a deleted, wrong-type or
	 * fabricated event id rather than storing it unchecked.
	 */
	public static function event_exists( int $event_id ): bool {
		if ( $event_id <= 0 ) {
			return false;
		}

		$cpt = \class_exists( '\Anchor\Events\Module' ) ? \Anchor\Events\Module::CPT : self::EVENT_CPT_FALLBACK;

		return $cpt === (string) \get_post_type( $event_id );
	}

	/**
	 * Normalised session rows for an event, resolved exactly the way the
	 * room itself resolves them: `Module::resolved_sessions( $event_id )`
	 * (EVENTS.md "Main Public API" - "always at least one row"; a plain
	 * single event resolves to one implicit session spanning its own
	 * start/end, a multisession event to its real rows). `[]` for an
	 * event id that does not name a real event, or when the events module
	 * cannot supply the method at all.
	 *
	 * `start_ts`/`end_ts`/`modality` are derived here only as a fallback for
	 * a shape the events module does not supply - today's real
	 * `resolved_sessions()` already includes them, so this is defensive
	 * against a future narrowing, not the primary path.
	 *
	 * @return array<int,array{label:string,date:string,start_time:string,end_time:string,start_ts:int,end_ts:int,modality:string}>
	 */
	public static function sessions( int $event_id ): array {
		if ( ! self::available() || ! self::event_exists( $event_id ) ) {
			return [];
		}

		$module = \Anchor\Events\Module::instance();
		if ( ! \method_exists( $module, 'resolved_sessions' ) ) {
			return [];
		}

		$rows = (array) $module->resolved_sessions( $event_id );
		$out  = [];

		foreach ( $rows as $row ) {
			if ( ! \is_array( $row ) ) {
				continue;
			}

			$date  = (string) ( $row['date'] ?? '' );
			$start = (string) ( $row['start_time'] ?? '' );
			$end   = (string) ( $row['end_time'] ?? '' );

			$start_ts = (int) ( $row['start_ts'] ?? 0 );
			if ( $start_ts <= 0 && '' !== $date ) {
				$start_ts = (int) \strtotime( $date . ' ' . ( '' === $start ? '00:00' : $start ) . ' UTC' );
			}

			$end_ts = (int) ( $row['end_ts'] ?? 0 );
			if ( $end_ts <= 0 && '' !== $date ) {
				$end_ts = (int) \strtotime( $date . ' ' . ( '' === $end ? '23:59' : $end ) . ' UTC' );
			}

			$out[] = [
				'label'      => (string) ( $row['label'] ?? '' ),
				'date'       => $date,
				'start_time' => $start,
				'end_time'   => $end,
				'start_ts'   => \max( 0, $start_ts ),
				'end_ts'     => \max( 0, $end_ts ),
				'modality'   => (string) ( $row['modality'] ?? 'in_person' ),
			];
		}

		return $out;
	}

	/**
	 * The event's room URL - `Module::room_url()`, "the one definition of
	 * 'has a room'" (EVENTS.md). `''` when the events module is absent, the
	 * event id does not resolve, the access switch is off, or no stream
	 * resolves - callers never re-derive any of that, they just get `''`.
	 */
	public static function room_url( int $event_id ): string {
		if ( ! self::available() || ! self::event_exists( $event_id ) ) {
			return '';
		}

		$module = \Anchor\Events\Module::instance();
		if ( ! \method_exists( $module, 'room_url' ) ) {
			return '';
		}

		return (string) $module->room_url( $event_id );
	}

	/**
	 * The room's state machine result (`Stream_State::for_event()`), or
	 * `['state' => 'unknown']` when the events module cannot supply one.
	 *
	 * @return array{state:string,session_index?:int,target_ts?:int,embed?:array}
	 */
	public static function stream_state( int $event_id ): array {
		if ( ! self::available() || ! self::event_exists( $event_id ) || ! \class_exists( '\Anchor\Events\Stream_State' ) ) {
			return [ 'state' => 'unknown' ];
		}

		$state = \Anchor\Events\Stream_State::for_event( $event_id, Clock::timestamp() );

		return ( \is_array( $state ) && isset( $state['state'] ) ) ? $state : [ 'state' => 'unknown' ];
	}

	/**
	 * Live-session lessons that gate stream access on prior items - one row
	 * per (lesson, course) pairing. A `live_session` lesson shared by more
	 * than one PUBLISHED course (`Curriculum::courses_for_item()`) produces
	 * one row per course, so `veto_stream_access()` weighs each course's
	 * enrolment and progress separately and denies as soon as any one
	 * applicable course blocks - the same "deny if ANY blocks" rule already
	 * applies across LESSONS below; this is what makes it also apply across
	 * COURSES for a lesson shared between them.
	 *
	 * Only a PUBLISHED live_session lesson gates anything (PR36 round 2,
	 * Codex): a private or draft one is staged content a learner cannot
	 * open through any public route, so it must not be able to deny an
	 * attendee a stream nobody has told them is behind pre-work at all.
	 *
	 * @return array<int,array{lesson_id:int,course_id:int,session_index:int}>
	 */
	public static function live_lessons_for_event( int $event_id ): array {
		if ( $event_id <= 0 ) {
			return [];
		}

		if ( \array_key_exists( $event_id, self::$live_lessons_cache ) ) {
			return self::$live_lessons_cache[ $event_id ];
		}

		$lessons = \get_posts(
			[
				'post_type'      => LessonPostType::CPT,
				// PUBLISHED only (PR36 round 2, Codex): a private (or draft)
				// live_session lesson is staged content a learner cannot
				// open through any public route, so it must never be able to
				// gate someone else's event session either.
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery
					[ 'key' => LessonPostType::meta_key( 'event_id' ), 'value' => $event_id, 'type' => 'NUMERIC' ],
					[ 'key' => LessonPostType::meta_key( 'require_prior_items' ), 'value' => '1' ],
				],
			]
		);

		$rows = [];
		foreach ( $lessons as $lesson_id ) {
			$lesson_id = (int) $lesson_id;
			// event_id/require_prior_items meta can survive a lesson's type
			// changing away from live_session - only a lesson CURRENTLY
			// typed live_session actually renders/points at a room.
			if ( 'live_session' !== (string) LessonEditor::setting( $lesson_id, 'type' ) ) {
				continue;
			}

			$session_index = (int) LessonEditor::setting( $lesson_id, 'session_index' );
			foreach ( Curriculum::courses_for_item( $lesson_id, 'lesson' ) as $course_id ) {
				$rows[] = [
					'lesson_id'     => $lesson_id,
					'course_id'     => (int) $course_id,
					'session_index' => $session_index,
				];
			}
		}

		return self::$live_lessons_cache[ $event_id ] = $rows; // phpcs:ignore Squiz.PHP.DisallowMultipleAssignments
	}

	/**
	 * Drop the `live_lessons_for_event()` memo (all events, not just one -
	 * a lesson's `event_id` can itself change on the save that triggers
	 * this, so the safe invalidation is "everything", not "the event this
	 * post happens to name right now"). Hooked to `anchor_courses_curriculum_
	 * saved` (fired by `Curriculum::save()` itself, so every writer - the
	 * admin curriculum builder and every direct-call test fixture alike -
	 * is covered) and called directly from `LessonEditor::save()`, whose
	 * own `event_id`/`session_index`/`require_prior_items` writes never
	 * touch a course's curriculum meta and so never fire that action. Safe
	 * to call even when the events module never booted.
	 */
	public static function flush(): void {
		self::$live_lessons_cache = [];
		self::$denials            = [];
	}

	/**
	 * `anchor_events_can_access_stream` (events spec 4.5,
	 * `Entitlements::can_access_stream()`).
	 *
	 * Courses may only SUBTRACT access: a `false` coming in stays `false`
	 * going out - this veto never grants access the events module itself
	 * already refused. When a `live_session` lesson for this event and
	 * session has `require_prior_items` ticked, every required curriculum
	 * item ordered before it - in every PUBLISHED course that lists it
	 * (`live_lessons_for_event()`) - must be complete for this learner in
	 * THAT course; access is denied as soon as any one applicable
	 * (lesson, course) row blocks.
	 *
	 * Two exemptions, both evaluated per row (so per lesson/course), not
	 * globally:
	 *   - A learner who is not enrolled in that particular course has no
	 *     pre-work to owe it and is skipped, not denied - the course cannot
	 *     block an event it does not own the attendee of.
	 *   - Staff - anyone who can edit THIS LESSON post
	 *     (`user_can( $user_id, 'edit_post', $lesson_id )`, resolved through
	 *     `LessonPostType::register()`'s own `map_meta_cap` capability map)
	 *     - is never vetoed, so an instructor managing or previewing the
	 *     room is not locked out by their own unfinished "pre-work".
	 *
	 * The prior-items rule itself is
	 * `ProgressService::prior_required_items_complete()` - the exact loop
	 * `is_item_available()` runs for sequential progression, reused here
	 * rather than re-implemented, and applied regardless of the course's
	 * own `progression_mode`: a `free` course can still gate one specific
	 * `live_session` lesson on its own pre-work, which a plain call to
	 * `is_item_available()` (mode-gated) would not do.
	 */
	public static function veto_stream_access( bool $allowed, int $event_id, int $session_index = 0, int $user_id = 0 ): bool {
		$user_id = $user_id > 0 ? $user_id : (int) \get_current_user_id();

		if ( ! $allowed ) {
			// The events module refused on its own; whatever this module
			// said last time is not the reason now.
			unset( self::$denials[ $event_id ][ $user_id ] );
			return false;
		}

		if ( $user_id <= 0 ) {
			return $allowed;
		}

		$block = self::prework_block( $event_id, $session_index, $user_id );
		if ( null === $block ) {
			unset( self::$denials[ $event_id ][ $user_id ] );
			return true;
		}

		self::$denials[ $event_id ][ $user_id ] = $block;
		return false;
	}

	/**
	 * THE veto decision: the first (course, unfinished item) that blocks
	 * this learner from this event session, or null when nothing does.
	 * veto_stream_access() and the live-session lesson template both ask
	 * this, so the Join button and the room can never disagree.
	 *
	 * @return array{course_id:int,item_id:int,item_type:string}|null
	 */
	public static function prework_block( int $event_id, int $session_index, int $user_id ): ?array {
		if ( $user_id <= 0 ) {
			return null;
		}

		foreach ( self::live_lessons_for_event( $event_id ) as $row ) {
			if ( $row['session_index'] !== $session_index ) {
				continue;
			}
			if ( \user_can( $user_id, 'edit_post', $row['lesson_id'] ) ) {
				continue; // Staff: never vetoed by their own pre-work.
			}
			if ( ! self::enrollments()->is_enrolled( $user_id, $row['course_id'] ) ) {
				continue; // Not on this course: no pre-work owed to it.
			}
			$first = self::progress()->first_incomplete_prior_item( $user_id, $row['course_id'], $row['lesson_id'], 'lesson' );
			if ( null !== $first ) {
				return [ 'course_id' => $row['course_id'], 'item_id' => $first['id'], 'item_type' => $first['type'] ];
			}
		}

		return null;
	}

	/**
	 * `anchor_events_room_denied_message`: when this module's veto is what
	 * denied the current user, replace "not registered" with the real
	 * reason. Any other denial passes through untouched.
	 *
	 * @param mixed $message
	 * @param mixed $event_id
	 * @return mixed
	 */
	public static function denied_message( $message, $event_id = 0 ) {
		$user_id = (int) \get_current_user_id();
		$block   = self::$denials[ (int) $event_id ][ $user_id ] ?? null;

		return null === $block ? $message : self::prework_notice( $block );
	}

	/**
	 * The one piece of copy for a pre-work block, as a short escaped HTML
	 * fragment: the course named, and a link to the unfinished item in its
	 * course context (a quiz has no URL of its own, so it links the course).
	 * Raw post_title through esc_html(), not get_the_title(): the latter is
	 * already texturized, and escaping it again would print `&#8217;`.
	 *
	 * @param array{course_id:int,item_id:int,item_type:string} $block
	 */
	public static function prework_notice( array $block ): string {
		$course_id = (int) $block['course_id'];
		$item_id   = (int) $block['item_id'];

		$url = 'lesson' === $block['item_type']
			? Access::lesson_url( $item_id, $course_id )
			: (string) \get_permalink( $course_id );

		return \sprintf(
			/* translators: %s: course title. */
			\esc_html__( 'Finish the earlier lessons in %s first.', 'anchor-schema' ),
			\esc_html( (string) \get_post_field( 'post_title', $course_id ) )
		) . ' <a href="' . \esc_url( $url ) . '">' . \sprintf(
			/* translators: %s: lesson or quiz title. */
			\esc_html__( 'Continue with %s', 'anchor-schema' ),
			\esc_html( (string) \get_post_field( 'post_title', $item_id ) )
		) . '</a>';
	}
}
