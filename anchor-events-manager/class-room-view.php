<?php
/**
 * How the /live/ room looks to someone who is allowed in: a header band, the stage (countdown or player),
 * and a rail with the schedule. Module::render_room() decides WHO gets this view; this class only draws it.
 *
 * The stage is Module::room_state_block(), unchanged in contract: room.js re-renders that one block over REST,
 * so everything that changes while the page is open (state, countdown target, player) lives inside it and
 * everything here is drawn once per page load.
 *
 * Themes adjust it through filters rather than a template copy, so the markup stays one implementation:
 *   anchor_events_room_eyebrow      (string $text, int $event_id)       line above the heading
 *   anchor_events_room_heading      (string $text, int $event_id)       the h1
 *   anchor_events_room_tips         (array  $tips, int $event_id)       [ [ 'title' => '', 'text' => '' ], … ]
 *   anchor_events_room_after_stage  (string $html, int $event_id)       e.g. faculty, under the "now" line
 *   anchor_events_room_help         (string $html, int $event_id)       the help strip under the room
 *
 * @package AnchorTools\Events
 */

namespace Anchor\Events;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

class Room_View {

    /** @var Module */
    private $module;

    public function __construct( Module $module ) {
        $this->module = $module;
    }

    /**
     * The whole entitled view.
     *
     * @param int    $event_id
     * @param array  $state Stream_State result.
     * @param string $staff Staff links ('' for attendees).
     * @return string
     */
    public function render( $event_id, array $state, $staff = '' ) {
        $event_id = (int) $event_id;
        $now      = (int) \apply_filters( 'anchor_events_stream_now', \time(), $event_id );
        $rows     = $this->schedule( $event_id, $state, $now );

        return '<div class="anchor-room anchor-room--open">'
            . $this->head( $event_id, $state, $rows )
            . '<div class="anchor-room-wrap anchor-room-layout">'
            . '<section class="anchor-room-main" aria-label="' . \esc_attr__( 'Stream', 'anchor-schema' ) . '">'
            . $this->module->room_state_block( $event_id, $state )
            . $this->now_line( $event_id, $state, $rows )
            . (string) \apply_filters( 'anchor_events_room_after_stage', '', $event_id )
            . $this->tips( $event_id )
            . '</section>'
            . '<aside class="anchor-room-rail" aria-label="' . \esc_attr__( 'Schedule', 'anchor-schema' ) . '">'
            . $this->rail( $event_id, $rows )
            . $staff
            . '</aside>'
            . '</div>'
            . $this->help( $event_id )
            . '</div>';
    }

    /**
     * Each session with what a viewer needs to know about it right now.
     *
     * status: done | live | next | upcoming. `streamed` is false for in-person rows. `timed` is false when the
     * row has no start time (a bootcamp day), which is printed as a date only, never as midnight.
     *
     * @return array<int,array{label:string,start_ts:int,end_ts:int,date:string,streamed:bool,timed:bool,status:string,modality:string}>
     */
    public function schedule( $event_id, array $state, $now ) {
        $sessions   = $this->module->resolved_sessions( (int) $event_id );
        $meta       = $this->module->get_meta( (int) $event_id );
        $close      = \max( 0, (int) ( $meta['stream_close_after_minutes'] ?? 0 ) ) * \MINUTE_IN_SECONDS;
        $live_index = (string) $state['state'] === Stream_State::LIVE ? (int) $state['session_index'] : -1;
        $next_index = \in_array( (string) $state['state'], [ Stream_State::COUNTDOWN, Stream_State::BETWEEN ], true )
            ? (int) $state['session_index'] : -1;
        $tz    = $this->module->event_timezone( $meta );
        $today = \wp_date( 'Y-m-d', (int) $now, $tz );

        $out = [];
        foreach ( $sessions as $i => $row ) {
            $start  = (int) ( $row['start_ts'] ?? 0 );
            $end    = \max( $start, (int) ( $row['end_ts'] ?? 0 ) );
            $timed  = (string) ( $row['start_time'] ?? '' ) !== '';
            $date   = (string) ( $row['date'] ?? '' );
            $stream = \in_array( (string) ( $row['modality'] ?? '' ), Stream_State::STREAMABLE, true );

            if ( (int) $i === $live_index ) {
                $status = 'live';
            } elseif ( (int) $i === $next_index ) {
                $status = 'next';
            } elseif ( $timed ? ( $start > 0 && $now > $end + $close ) : ( $date !== '' && $date < $today ) ) {
                $status = 'done';
            } else {
                $status = 'upcoming';
            }

            $out[ $i ] = [
                'label'    => (string) ( $row['label'] ?? '' ),
                'start_ts' => $start,
                'end_ts'   => $end,
                'date'     => $date,
                'streamed' => $stream,
                'timed'    => $timed,
                'status'   => $status,
                'modality' => (string) ( $row['modality'] ?? '' ),
            ];
        }
        return $out;
    }

    /** Header band: eyebrow, heading, and a chip or two. */
    private function head( $event_id, array $state, array $rows ) {
        $eyebrow = (string) \apply_filters( 'anchor_events_room_eyebrow', \get_the_title( $event_id ), $event_id );
        $heading = (string) \apply_filters( 'anchor_events_room_heading', \__( 'Live classroom', 'anchor-schema' ), $event_id );

        $chips = '';
        if ( (string) $state['state'] === Stream_State::LIVE ) {
            $chips .= '<span class="anchor-room-chip anchor-room-chip--live"><span class="anchor-room-dot" aria-hidden="true"></span>'
                . \esc_html__( 'Live now', 'anchor-schema' ) . '</span>';
        }
        $position = $this->position( $state, $rows );
        if ( $position !== '' ) {
            $chips .= '<span class="anchor-room-chip">' . \esc_html( $position ) . '</span>';
        }

        return '<header class="anchor-room-head"><div class="anchor-room-wrap anchor-room-head-inner">'
            . '<div>'
            . ( $eyebrow !== '' ? '<p class="anchor-room-eyebrow">' . \esc_html( $eyebrow ) . '</p>' : '' )
            . '<h1 class="anchor-room-title">' . \esc_html( $heading ) . '</h1>'
            . '</div>'
            . ( $chips !== '' ? '<div class="anchor-room-chips">' . $chips . '</div>' : '' )
            . '</div></header>';
    }

    /** "Session 3 of 10", counted over streamed sessions only. '' when there is no current or next one. */
    private function position( array $state, array $rows ) {
        if ( ! \in_array( (string) $state['state'], [ Stream_State::LIVE, Stream_State::COUNTDOWN, Stream_State::BETWEEN ], true ) ) {
            return '';
        }
        $streamed = \array_keys( \array_filter( $rows, static function ( $r ) { return $r['streamed']; } ) );
        $pos      = \array_search( (int) $state['session_index'], $streamed, true );
        if ( $pos === false || \count( $streamed ) < 2 ) {
            return '';
        }
        $prefix = (string) $state['state'] === Stream_State::LIVE ? '' : \__( 'Next: ', 'anchor-schema' );
        /* translators: 1: session number, 2: number of streamed sessions */
        return $prefix . \sprintf( \__( 'Session %1$d of %2$d', 'anchor-schema' ), $pos + 1, \count( $streamed ) );
    }

    /** The line under the stage: what is on now or next, and when. */
    private function now_line( $event_id, array $state, array $rows ) {
        $key = (string) $state['state'];
        $row = $rows[ (int) $state['session_index'] ] ?? null;
        if ( ! $row || ! \in_array( $key, [ Stream_State::LIVE, Stream_State::COUNTDOWN, Stream_State::BETWEEN ], true ) ) {
            return '';
        }
        $name  = $row['label'] !== '' ? $row['label'] : \get_the_title( $event_id );
        $title = $key === Stream_State::LIVE
            /* translators: %s: session name */
            ? \sprintf( \__( '%s, live now', 'anchor-schema' ), $name )
            /* translators: %s: session name */
            : \sprintf( \__( 'Up next: %s', 'anchor-schema' ), $name );

        return '<div class="anchor-room-now">'
            . '<h2 class="anchor-room-now-title">' . \esc_html( $title ) . '</h2>'
            . '<p class="anchor-room-now-when">' . \esc_html( $this->when_long( $event_id, $row ) ) . '</p>'
            . ( $key !== Stream_State::LIVE ? $this->calendar_link( $event_id, $name, $row ) : '' )
            . '</div>';
    }

    /** "Friday, February 26, 2027 · 8:00 AM–12:00 PM PST". */
    public function when_long( $event_id, array $row ) {
        $tz = $this->module->event_timezone( $this->module->get_meta( (int) $event_id ) );
        if ( ! $row['start_ts'] ) {
            return '';
        }
        $day = \wp_date( 'l, F j, Y', $row['start_ts'], $tz );
        if ( ! $row['timed'] ) {
            return $day;
        }
        $time = \wp_date( 'g:i A', $row['start_ts'], $tz );
        if ( $row['end_ts'] > $row['start_ts'] ) {
            $time .= '–' . \wp_date( 'g:i A', $row['end_ts'], $tz );
        }
        return $day . ' · ' . $time . ' ' . \wp_date( 'T', $row['start_ts'], $tz );
    }

    /**
     * An .ics for the session, as a data: URI so it needs no endpoint. The room URL goes in the entry, so the
     * reminder on someone's phone opens the right page.
     */
    private function calendar_link( $event_id, $name, array $row ) {
        if ( ! $row['timed'] || ! $row['start_ts'] ) {
            return '';
        }
        $esc = static function ( $s ) {
            return \str_replace( [ '\\', ';', ',', "\n" ], [ '\\\\', '\;', '\,', '\n' ], (string) $s );
        };
        $room  = $this->module->room_url( $event_id );
        $title = \get_the_title( $event_id ) . ' — ' . $name;
        $ics   = \implode( "\r\n", [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Anchor Tools//Events//EN', 'BEGIN:VEVENT',
            'UID:anchor-room-' . (int) $event_id . '-' . (int) $row['start_ts'] . '@' . \wp_parse_url( \home_url(), \PHP_URL_HOST ),
            'DTSTAMP:' . \gmdate( 'Ymd\THis\Z' ),
            'DTSTART:' . \gmdate( 'Ymd\THis\Z', $row['start_ts'] ),
            'DTEND:' . \gmdate( 'Ymd\THis\Z', \max( $row['end_ts'], $row['start_ts'] + \HOUR_IN_SECONDS ) ),
            'SUMMARY:' . $esc( \html_entity_decode( $title, \ENT_QUOTES ) ),
            'URL:' . $esc( $room ),
            'DESCRIPTION:' . $esc( \__( 'Join here:', 'anchor-schema' ) . ' ' . $room ),
            'END:VEVENT', 'END:VCALENDAR', '',
        ] );
        return '<p class="anchor-room-now-actions"><a class="anchor-room-btn anchor-room-btn--line" download="session.ics"'
            . ' href="data:text/calendar;charset=utf-8,' . \esc_attr( \rawurlencode( $ics ) ) . '">'
            . \esc_html__( 'Add to calendar', 'anchor-schema' ) . '</a></p>';
    }

    private function tips( $event_id ) {
        $days = $this->module->recording_folders ? $this->module->recording_folders->days( $event_id ) : 0;
        $tips = [
            [ 'title' => \__( 'Join early', 'anchor-schema' ), 'text' => \__( 'The room opens 15 minutes before each session and switches to the stream on its own.', 'anchor-schema' ) ],
            [ 'title' => \__( 'Use a laptop', 'anchor-schema' ), 'text' => \__( 'Chrome or Safari with the sound on. Some office networks block video.', 'anchor-schema' ) ],
        ];
        if ( $days > 0 && $this->module->recording_folders->url( $event_id ) !== '' ) {
            $tips[] = [
                'title' => \__( 'Missed one?', 'anchor-schema' ),
                /* translators: %d: number of days */
                'text'  => \sprintf( \_n( 'Each recording stays in your account for %d day after its session.', 'Each recording stays in your account for %d days after its session.', $days, 'anchor-schema' ), $days ),
            ];
        }
        $tips = (array) \apply_filters( 'anchor_events_room_tips', $tips, $event_id );
        if ( ! $tips ) {
            return '';
        }
        $out = '<ul class="anchor-room-tips">';
        foreach ( $tips as $tip ) {
            $out .= '<li><strong>' . \esc_html( (string) ( $tip['title'] ?? '' ) ) . '</strong> '
                . \esc_html( (string) ( $tip['text'] ?? '' ) ) . '</li>';
        }
        return $out . '</ul>';
    }

    /** The schedule rail: progress, sessions, recordings. */
    private function rail( $event_id, array $rows ) {
        $streamed = \array_filter( $rows, static function ( $r ) { return $r['streamed']; } );
        $done     = \count( \array_filter( $streamed, static function ( $r ) { return $r['status'] === 'done'; } ) );
        $total    = \count( $streamed );

        $out = '<h2 class="anchor-room-rail-title">' . \esc_html__( 'Your schedule', 'anchor-schema' ) . '</h2>';
        if ( $total > 1 ) {
            $out .= '<div class="anchor-room-progress">'
                . '<div class="anchor-room-bar" role="progressbar" aria-valuemin="0" aria-valuemax="' . (int) $total . '" aria-valuenow="' . (int) $done . '">'
                . '<span style="width:' . \esc_attr( (string) \round( $done / $total * 100, 2 ) ) . '%"></span></div>'
                /* translators: 1: sessions done, 2: online sessions in total */
                . '<p>' . \esc_html( \sprintf( \__( '%1$d of %2$d online sessions done', 'anchor-schema' ), $done, $total ) ) . '</p>'
                . '</div>';
        }

        $out .= '<ol class="anchor-room-schedule">';
        foreach ( $rows as $row ) {
            $out .= $this->schedule_row( $event_id, $row );
        }
        $out .= '</ol>';

        if ( $this->module->recording_folders ) {
            $out .= $this->module->recording_folders->render_room_link( $event_id );
        }
        return $out;
    }

    private function schedule_row( $event_id, array $row ) {
        $tz   = $this->module->event_timezone( $this->module->get_meta( (int) $event_id ) );
        $ts   = $row['start_ts'];
        $name = $row['label'] !== '' ? $row['label'] : \get_the_title( $event_id );

        $date = $ts
            ? '<time class="anchor-room-date" datetime="' . \esc_attr( $row['timed'] ? \gmdate( 'c', $ts ) : $row['date'] ) . '">'
                . '<small>' . \esc_html( \wp_date( 'M', $ts, $tz ) ) . '</small><b>' . \esc_html( \wp_date( 'j', $ts, $tz ) ) . '</b></time>'
            : '<span class="anchor-room-date"></span>';

        return '<li class="anchor-room-session is-' . \esc_attr( $row['status'] ) . ' anchor-room-session--' . \esc_attr( $row['modality'] ) . '">'
            . $date
            . '<div class="anchor-room-session-body">'
            . '<span class="anchor-room-session-label">' . \esc_html( $name ) . '</span>'
            . '<span class="anchor-room-session-meta">' . $this->row_meta( $event_id, $row, $tz ) . '</span>'
            . '</div></li>';
    }

    /** The small line under a session's name. Returns escaped HTML. */
    private function row_meta( $event_id, array $row, $tz ) {
        if ( $row['status'] === 'live' ) {
            return \esc_html__( 'Live now', 'anchor-schema' );
        }
        $time = ( $row['timed'] && $row['start_ts'] )
            ? \wp_date( 'g:i A T', $row['start_ts'], $tz ) : '';

        if ( ! $row['streamed'] ) {
            $meta  = $this->module->get_meta( (int) $event_id );
            $where = \trim( (string) ( $meta['address_city'] ?? '' ) );
            $bits  = \array_filter( [ \__( 'In person', 'anchor-schema' ), $where, $time ] );
            return \esc_html( \implode( ' · ', $bits ) );
        }
        if ( $row['status'] === 'done' ) {
            $link = $this->module->recording_folders
                ? $this->module->recording_folders->session_link( $event_id, $row['date'] ) : [];
            if ( ! empty( $link['url'] ) ) {
                $until = $link['until'] !== ''
                    /* translators: %s: date the recording is available until */
                    ? \sprintf( \__( 'Recording until %s', 'anchor-schema' ), \wp_date( 'M j', (int) \strtotime( $link['until'] . ' 12:00:00' ), $tz ) ) . ' · '
                    : '';
                return \esc_html( $until ) . '<a href="' . \esc_url( $link['url'] ) . '">' . \esc_html__( 'Watch', 'anchor-schema' ) . '</a>';
            }
            return \esc_html__( 'Ended', 'anchor-schema' );
        }
        $prefix = $row['status'] === 'next' ? \__( 'Next', 'anchor-schema' ) : \__( 'Online', 'anchor-schema' );
        return \esc_html( \implode( ' · ', \array_filter( [ $prefix, $time ] ) ) );
    }

    private function help( $event_id ) {
        $default = '<strong>' . \esc_html__( 'Stream not loading?', 'anchor-schema' ) . '</strong> '
            . '<span>' . \esc_html__( 'Refresh the page first. Then try another browser, or turn off a VPN.', 'anchor-schema' ) . '</span>';
        $html = (string) \apply_filters( 'anchor_events_room_help', $default, $event_id );
        return $html === '' ? '' : '<div class="anchor-room-help"><div class="anchor-room-wrap">' . \wp_kses_post( $html ) . '</div></div>';
    }
}
