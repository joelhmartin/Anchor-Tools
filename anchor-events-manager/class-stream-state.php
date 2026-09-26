<?php
/**
 * The room's state machine (virtual-events spec §5.3).
 *
 * decide() is a PURE function of resolved sessions, two window widths and a
 * clock. That is deliberate: the room renders it, the REST endpoint re-renders
 * it and the admin list column prints it, and all three must agree to the
 * second. for_event() is the thin WordPress-aware wrapper that reads the event
 * and calls it.
 *
 * `embed` is populated ONLY in the `live` state — the room's whole reason for
 * existing is that the stream URL is not in the page before its window opens.
 *
 * A session whose end_ts is before its start_ts (a mis-typed end time —
 * session rows carry one date so they cannot legitimately cross midnight) is
 * treated as zero-length: the window's close collapses to start_ts + close_after
 * via max( $start, $end ), rather than closing before it opens.
 *
 * @package AnchorTools\Events
 */

namespace Anchor\Events;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

class Stream_State {

    const UNAVAILABLE = 'unavailable';
    const PENDING     = 'pending';
    const COUNTDOWN   = 'countdown';
    const LIVE        = 'live';
    const BETWEEN     = 'between';
    const ENDED       = 'ended';

    /** Modalities that can carry a stream. */
    const STREAMABLE = [ 'virtual', 'hybrid' ];

    /**
     * Resolve the state for an event at an instant.
     *
     * @param int $event_id
     * @param int $now      Unix timestamp; 0 = time().
     * @return array{state:string,session_index:int,target_ts:int,embed:array}
     */
    public static function for_event( $event_id, $now = 0 ) {
        $event_id = (int) $event_id;
        /**
         * The instant the room reasons about.
         *
         * Exists so an end-to-end test can advance the clock without sleeping
         * through a real countdown. Nothing in the plugin filters it; a site
         * that does is choosing to lie to its own room.
         *
         * @param int $now
         * @param int $event_id
         */
        $now    = (int) \apply_filters( 'anchor_events_stream_now', $now > 0 ? (int) $now : \time(), (int) $event_id );
        $module = Module::instance();
        if ( ! $module ) {
            return self::result( self::UNAVAILABLE, 0, 0, [] );
        }

        $meta = $module->get_meta( $event_id );
        return self::decide(
            $module->resolved_sessions( $event_id ),
            \max( 0, (int) $meta['stream_open_before_minutes'] ) * \MINUTE_IN_SECONDS,
            \max( 0, (int) $meta['stream_close_after_minutes'] ) * \MINUTE_IN_SECONDS,
            $now,
            $module->stream_capable( $event_id )
        );
    }

    /**
     * The pure core. Unit-tested as a table.
     *
     * @param array $sessions     Rows from Module::resolved_sessions().
     * @param int   $open_before  Seconds before start_ts the player opens.
     * @param int   $close_after  Seconds after end_ts the player closes.
     * @param int   $now          Unix timestamp.
     * @param bool  $capable      Whether the event may hold a stream at all.
     * @return array{state:string,session_index:int,target_ts:int,embed:array}
     */
    public static function decide( array $sessions, $open_before, $close_after, $now, $capable = true ) {
        $open_before = \max( 0, (int) $open_before );
        $close_after = \max( 0, (int) $close_after );
        $now         = (int) $now;

        if ( ! $capable ) {
            return self::result( self::UNAVAILABLE, 0, 0, [] );
        }

        // Only streamable sessions with a real instant are windows at all.
        $windows = [];
        $any_streamable = false;
        foreach ( $sessions as $index => $row ) {
            if ( ! \in_array( (string) ( $row['modality'] ?? '' ), self::STREAMABLE, true ) ) {
                continue;
            }
            $any_streamable = true;
            $start = (int) ( $row['start_ts'] ?? 0 );
            $end   = (int) ( $row['end_ts'] ?? 0 );
            if ( $start <= 0 ) {
                continue;
            }
            // A mis-typed end time (end before start) collapses to a
            // zero-length session rather than a window that closes before it
            // opens — session rows carry one date, so they cannot
            // legitimately cross midnight.
            $windows[] = [
                'index' => (int) $index,
                'open'  => $start - $open_before,
                'close' => \max( $start, $end ) + $close_after,
                'embed' => \is_array( $row['stream_embed'] ?? null ) ? $row['stream_embed'] : [],
            ];
        }

        if ( ! $any_streamable ) {
            return self::result( self::UNAVAILABLE, 0, 0, [] );
        }

        // A window only enters the state machine when THAT session resolves
        // its own embed (event-level or an override — Module::resolved_
        // sessions()/event_level_embed() is what already folds the two into
        // one `stream_embed` per row). A later session's embed can never pull
        // an earlier, embed-less session into countdown/live/between: doing
        // so used to let render_room() pick that session's index and hand it
        // to can_access_stream(), which correctly refuses a session with no
        // embed — telling a registered attendee they were not registered at
        // all (audit finding a, 2026-09-25).
        $streamed_windows = [];
        foreach ( $windows as $w ) {
            if ( ! empty( $w['embed']['src'] ) ) {
                $streamed_windows[] = $w;
            }
        }

        // No session (streamable or not) resolves an embed, or none has a
        // date yet: the room says "details will appear here".
        if ( empty( $streamed_windows ) ) {
            return self::result( self::PENDING, 0, 0, [] );
        }

        $windows = $streamed_windows;
        \usort( $windows, static function ( $a, $b ) {
            return $a['open'] <=> $b['open'];
        } );

        // live — boundaries inclusive on both sides.
        foreach ( $windows as $w ) {
            if ( $now >= $w['open'] && $now <= $w['close'] ) {
                return self::result( self::LIVE, $w['index'], $w['close'], $w['embed'] );
            }
        }
        // countdown / between — the next window that has not opened yet. Which
        // of the two it is depends on whether anything has already CLOSED.
        $closed_any = false;
        foreach ( $windows as $w ) {
            if ( $now > $w['close'] ) {
                $closed_any = true;
                continue;
            }
            if ( $now < $w['open'] ) {
                return self::result( $closed_any ? self::BETWEEN : self::COUNTDOWN, $w['index'], $w['open'], [] );
            }
        }

        $last = \end( $windows );
        return self::result( self::ENDED, (int) $last['index'], (int) $last['close'], [] );
    }

    /** @return array{state:string,session_index:int,target_ts:int,embed:array} */
    private static function result( $state, $index, $target_ts, array $embed ) {
        return [
            'state'         => (string) $state,
            'session_index' => (int) $index,
            'target_ts'     => (int) $target_ts,
            'embed'         => $embed,
        ];
    }
}
