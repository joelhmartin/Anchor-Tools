<?php
declare(strict_types=1);

namespace Anchor\Courses\Integrations;

use Anchor\Courses\Support\Clock;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * The events module, seen from the courses side (design spec 3.3).
 *
 * A READER, and nothing else. It resolves an event's sessions, its room URL
 * and its stream state so a `live_session` lesson can render, and - once
 * Task 37 adds it - it may veto stream access until a learner's pre-work is
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
 * that shape again, this still degrades instead of fataling.
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
}
