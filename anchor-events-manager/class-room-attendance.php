<?php
/**
 * Who was in the /live/ room, per session.
 *
 * room.js pings once a minute while the room is LIVE and its tab is visible.
 * Everything that counts is decided here, on the server: the session comes from
 * Stream_State, never from the browser, and credit is the time since this
 * person's last ping, so a second tab or a forged request cannot add time.
 *
 * This records presence on the page, not proof of watching. Every report says so.
 *
 * @package AnchorTools\Events
 */

namespace Anchor\Events;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

class Room_Attendance {

    const TABLE      = 'anchor_event_room_attendance';
    const DB_VERSION = '1';
    const DB_OPTION  = 'anchor_events_room_attendance_db';

    /** How often room.js pings. */
    const PING_SECONDS = 60;

    /** The longest gap still credited: one missed ping. A longer gap means they left. */
    const MAX_CREDIT_SECONDS = 150;

    /** @var Module */
    private $module;

    public function __construct( Module $module ) {
        $this->module = $module;
        \add_action( 'admin_post_anchor_event_attendance_export', [ $this, 'handle_export' ] );
        \add_action( 'deleted_user', [ $this, 'forget_user' ] );
    }

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    /** Create or upgrade the table. Cheap: one autoloaded option read once current. */
    public function ensure_table() {
        if ( \get_option( self::DB_OPTION ) === self::DB_VERSION ) {
            return;
        }
        global $wpdb;
        $table   = self::table();
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        \dbDelta(
            "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id BIGINT UNSIGNED NOT NULL,
            session_index INT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            first_seen DATETIME NOT NULL,
            last_seen DATETIME NOT NULL,
            seconds INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY person_session (event_id,session_index,user_id),
            KEY user_id (user_id)
        ) {$charset};"
        );
        \update_option( self::DB_OPTION, self::DB_VERSION );
    }

    public function register_routes() {
        \register_rest_route( Module::REST_NS, '/events/(?P<id>\d+)/room/ping', [
            'methods'  => 'POST',
            'args'     => [ 'id' => [ 'required' => true, 'validate_callback' => static function ( $v ) {
                return \is_numeric( $v ) && (int) $v > 0;
            } ] ],
            'permission_callback' => static function () {
                return \is_user_logged_in()
                    ? true
                    : new \WP_Error( 'rest_forbidden', \__( 'Sign in to join.', 'anchor-schema' ), [ 'status' => 401 ] );
            },
            'callback' => [ $this, 'rest_ping' ],
        ] );
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function rest_ping( $request ) {
        $event_id = (int) $request['id'];
        if ( \get_post_type( $event_id ) !== Module::CPT || $this->module->room_url( $event_id ) === '' ) {
            return new \WP_Error( 'anchor_events_room_missing', \__( 'No room for that event.', 'anchor-schema' ), [ 'status' => 404 ] );
        }
        /** Same clock the room reasons about. See Stream_State::for_event(). */
        $now   = (int) \apply_filters( 'anchor_events_stream_now', \time(), $event_id );
        $state = Stream_State::for_event( $event_id, $now );

        $response = [ 'recorded' => false, 'state' => (string) $state['state'] ];
        if ( $state['state'] !== Stream_State::LIVE ) {
            return new \WP_REST_Response( $response );
        }
        if ( ! $this->module->entitlements || ! $this->module->entitlements->can_access_stream( $event_id, (int) $state['session_index'], 0 ) ) {
            return new \WP_Error( 'anchor_events_room_denied', \__( "This account isn't registered for this event.", 'anchor-schema' ), [ 'status' => 403 ] );
        }
        $response['recorded'] = $this->record( $event_id, (int) $state['session_index'], (int) \get_current_user_id(), $now );

        $rest = new \WP_REST_Response( $response );
        $rest->header( 'Cache-Control', 'private, no-store, max-age=0' );
        return $rest;
    }

    /**
     * One ping. The first only opens the row. A later one adds the time since
     * the previous ping, when that gap is short enough to mean they stayed and
     * the session has actually started. The room also opens early and closes
     * late, and that time is not session time.
     *
     * @return bool Whether the row was written.
     */
    public function record( $event_id, $session_index, $user_id, $now ) {
        if ( (int) $user_id <= 0 ) {
            return false;
        }
        $sessions = $this->module->resolved_sessions( (int) $event_id );
        $row      = $sessions[ (int) $session_index ] ?? null;
        if ( ! \is_array( $row ) ) {
            return false;
        }
        $start     = (int) ( $row['start_ts'] ?? 0 );
        $end       = \max( $start, (int) ( $row['end_ts'] ?? 0 ) );
        $in_window = ( $now >= $start && $now <= $end ) ? 1 : 0;

        $this->ensure_table();
        global $wpdb;
        $table = self::table();
        $stamp = \gmdate( 'Y-m-d H:i:s', (int) $now );

        // MySQL applies these left to right: `seconds` must read the OLD last_seen.
        $sql = $wpdb->prepare(
            "INSERT INTO {$table} (event_id, session_index, user_id, first_seen, last_seen, seconds)
             VALUES (%d, %d, %d, %s, %s, 0)
             ON DUPLICATE KEY UPDATE
                seconds = seconds + IF(
                    %d = 1 AND TIMESTAMPDIFF(SECOND, last_seen, VALUES(last_seen)) BETWEEN 0 AND %d,
                    TIMESTAMPDIFF(SECOND, last_seen, VALUES(last_seen)),
                    0
                ),
                last_seen = GREATEST(last_seen, VALUES(last_seen))",
            (int) $event_id,
            (int) $session_index,
            (int) $user_id,
            $stamp,
            $stamp,
            $in_window,
            self::MAX_CREDIT_SECONDS
        );
        return $wpdb->query( $sql ) !== false; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
    }

    /** @param int $user_id */
    public function forget_user( $user_id ) {
        global $wpdb;
        if ( \get_option( self::DB_OPTION ) === false ) {
            return;
        }
        $wpdb->delete( self::table(), [ 'user_id' => (int) $user_id ], [ '%d' ] );
    }

    /**
     * The sessions a person could have been in the room for.
     *
     * @return array<int,array{index:int,label:string,date:string,start_ts:int,end_ts:int,seconds:int}>
     */
    public function streamed_sessions( $event_id ) {
        $out = [];
        foreach ( $this->module->resolved_sessions( (int) $event_id ) as $i => $row ) {
            if ( ! \in_array( (string) ( $row['modality'] ?? '' ), Stream_State::STREAMABLE, true ) || empty( $row['stream_embed']['src'] ) ) {
                continue;
            }
            $start = (int) ( $row['start_ts'] ?? 0 );
            $end   = \max( $start, (int) ( $row['end_ts'] ?? 0 ) );
            $out[] = [
                'index'    => (int) $i,
                'label'    => (string) ( $row['label'] ?? '' ),
                'date'     => (string) ( $row['date'] ?? '' ),
                'start_ts' => $start,
                'end_ts'   => $end,
                'seconds'  => $end - $start,
            ];
        }
        return $out;
    }

    /**
     * Attendance per confirmed seat. Anyone else who was in the room is listed
     * too (a manual grant has no seat), except staff.
     *
     * @return array{sessions:array,people:array<int,array{name:string,email:string,user_id:int,seat:bool,visits:array,seconds:int,joined:int}>}
     */
    public function report( $event_id ) {
        $event_id = (int) $event_id;
        $sessions = $this->streamed_sessions( $event_id );
        $visits   = $this->visits_by_user( $event_id );

        $people = [];
        $seen   = [];
        $seats  = $this->module->registrations->query_seats( [
            'event_id' => $event_id,
            'status'   => Registrations::STATUS_CONFIRMED,
            'per_page' => -1,
            'orderby'  => 'attendee',
            'order'    => 'ASC',
        ] );
        foreach ( $seats['items'] as $seat ) {
            $user_id = (int) ( $seat['user_id'] ?? 0 );
            if ( $user_id <= 0 && ! empty( $seat['email'] ) ) {
                $user    = \get_user_by( 'email', (string) $seat['email'] );
                $user_id = $user ? (int) $user->ID : 0;
            }
            $people[] = $this->person( (string) ( $seat['name'] ?? '' ), (string) ( $seat['email'] ?? '' ), $user_id, true, $visits );
            if ( $user_id > 0 ) {
                $seen[ $user_id ] = true;
            }
        }
        foreach ( $visits as $user_id => $rows ) {
            if ( isset( $seen[ $user_id ] ) || \user_can( (int) $user_id, 'manage_options' ) ) {
                continue;
            }
            $user = \get_user_by( 'id', (int) $user_id );
            if ( $user ) {
                $people[] = $this->person( (string) $user->display_name, (string) $user->user_email, (int) $user_id, false, $visits );
            }
        }
        return [ 'sessions' => $sessions, 'people' => $people ];
    }

    private function person( $name, $email, $user_id, $seat, array $visits ) {
        $rows = $visits[ $user_id ] ?? [];
        return [
            'name'    => $name,
            'email'   => $email,
            'user_id' => (int) $user_id,
            'seat'    => (bool) $seat,
            'visits'  => $rows,
            'seconds' => (int) \array_sum( \array_column( $rows, 'seconds' ) ),
            'joined'  => \count( $rows ),
        ];
    }

    /** @return array<int,array<int,array{seconds:int,first_ts:int,last_ts:int}>> user_id => session_index => visit. */
    private function visits_by_user( $event_id ) {
        if ( \get_option( self::DB_OPTION ) === false ) {
            return [];
        }
        global $wpdb;
        $table = self::table();
        $rows  = $wpdb->get_results(
            $wpdb->prepare( "SELECT session_index, user_id, first_seen, last_seen, seconds FROM {$table} WHERE event_id = %d", (int) $event_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            ARRAY_A
        );
        $out = [];
        foreach ( (array) $rows as $row ) {
            $out[ (int) $row['user_id'] ][ (int) $row['session_index'] ] = [
                'seconds'  => (int) $row['seconds'],
                'first_ts' => (int) \strtotime( $row['first_seen'] . ' UTC' ),
                'last_ts'  => (int) \strtotime( $row['last_seen'] . ' UTC' ),
            ];
        }
        return $out;
    }

    /** "2h 05m" / "12m". */
    public static function format_seconds( $seconds ) {
        $minutes = (int) \round( (int) $seconds / 60 );
        if ( $minutes < 60 ) {
            return $minutes . 'm';
        }
        return \floor( $minutes / 60 ) . 'h ' . \sprintf( '%02d', $minutes % 60 ) . 'm';
    }

    /** Share of the session, never over 100. '' when the session has no length. */
    public static function percent( $seconds, $session_seconds ) {
        if ( (int) $session_seconds <= 0 ) {
            return '';
        }
        return \min( 100, (int) \round( 100 * (int) $seconds / (int) $session_seconds ) ) . '%';
    }

    private function session_title( array $session ) {
        return $session['label'] !== '' ? $session['label'] : $session['date'];
    }

    private function clock( $ts, $event_id ) {
        return \wp_date( \get_option( 'time_format' ), (int) $ts, $this->module->event_timezone( $this->module->get_meta( (int) $event_id ) ) );
    }

    /**
     * The console panel. Empty for an event with no room.
     *
     * @param int  $event_id
     * @param bool $admin wp-admin styling rather than the front-end console's.
     * @return string
     */
    public function render_panel( $event_id, $admin = false ) {
        $event_id = (int) $event_id;
        if ( $this->module->room_url( $event_id ) === '' ) {
            return '';
        }
        $report = $this->report( $event_id );
        $total  = \count( $report['sessions'] );
        $export = \wp_nonce_url(
            \add_query_arg( [ 'action' => 'anchor_event_attendance_export', 'event_id' => $event_id ], \admin_url( 'admin-post.php' ) ),
            'anchor_event_export'
        );

        $out  = '<div class="anchor-roster-attendance">';
        $out .= '<h3>' . \esc_html__( 'Livestream attendance', 'anchor-schema' ) . '</h3>';
        $out .= '<p class="description">' . \esc_html__( 'Time counts while the room page is open and visible during a session. It shows presence on the page, not proof of watching.', 'anchor-schema' ) . '</p>';

        if ( empty( $report['people'] ) ) {
            $out .= '<p class="anchor-roster-fe-empty">' . \esc_html__( 'Nobody is registered yet.', 'anchor-schema' ) . '</p></div>';
            return $out;
        }

        $out .= '<div class="anchor-roster-fe-tablewrap"><table class="' . ( $admin ? 'widefat striped' : 'anchor-roster-fe-table' ) . '"><thead><tr>'
            . '<th>' . \esc_html__( 'Attendee', 'anchor-schema' ) . '</th>'
            . '<th>' . \esc_html__( 'Sessions', 'anchor-schema' ) . '</th>'
            . '<th>' . \esc_html__( 'Time in room', 'anchor-schema' ) . '</th>'
            . '<th>' . \esc_html__( 'By session', 'anchor-schema' ) . '</th>'
            . '</tr></thead><tbody>';

        foreach ( $report['people'] as $person ) {
            $out .= '<tr><td>' . \esc_html( $person['name'] )
                . '<br /><span class="anchor-roster-fe-sub">' . \esc_html( $person['email'] ) . '</span></td>'
                . '<td>' . (int) $person['joined'] . ' / ' . (int) $total . '</td>'
                . '<td>' . \esc_html( self::format_seconds( $person['seconds'] ) ) . '</td><td>';
            if ( empty( $person['visits'] ) ) {
                $out .= '&mdash;';
            } else {
                $out .= '<details><summary>' . \esc_html__( 'Show', 'anchor-schema' ) . '</summary><ul>';
                foreach ( $report['sessions'] as $session ) {
                    $visit = $person['visits'][ $session['index'] ] ?? null;
                    if ( ! $visit ) {
                        continue;
                    }
                    $out .= '<li>' . \esc_html(
                        \sprintf(
                            /* translators: 1: session name, 2: first seen, 3: last seen, 4: time in room, 5: percent of session. */
                            \__( '%1$s: recorded on the page %2$s to %3$s, %4$s (%5$s)', 'anchor-schema' ),
                            $this->session_title( $session ),
                            $this->clock( $visit['first_ts'], $event_id ),
                            $this->clock( $visit['last_ts'], $event_id ),
                            self::format_seconds( $visit['seconds'] ),
                            self::percent( $visit['seconds'], $session['seconds'] )
                        )
                    ) . '</li>';
                }
                $out .= '</ul></details>';
            }
            $out .= '</td></tr>';
        }
        $out .= '</tbody></table></div>';
        $out .= '<p class="anchor-roster-fe-tools"><a class="' . ( $admin ? 'button' : 'anchor-event-button-secondary' ) . '" href="' . \esc_url( $export ) . '">'
            . \esc_html__( 'Export attendance (CSV)', 'anchor-schema' ) . '</a></p></div>';
        return $out;
    }

    /**
     * The CSV: one row per person, three columns per streamed session.
     *
     * @return array{header:string[],rows:array<int,array>}
     */
    public function export_table( $event_id ) {
        $event_id = (int) $event_id;
        $report   = $this->report( $event_id );
        $header   = [ 'Name', 'Email', 'Seat', 'Sessions joined', 'Total minutes' ];
        foreach ( $report['sessions'] as $session ) {
            $title    = $this->session_title( $session );
            $header[] = $title . ' first seen';
            $header[] = $title . ' last seen';
            $header[] = $title . ' minutes';
        }
        $rows = [];
        foreach ( $report['people'] as $person ) {
            $cells = [ $person['name'], $person['email'], $person['seat'] ? 'Yes' : 'No', $person['joined'], (int) \round( $person['seconds'] / 60 ) ];
            foreach ( $report['sessions'] as $session ) {
                $visit   = $person['visits'][ $session['index'] ] ?? null;
                $cells[] = $visit ? $this->clock( $visit['first_ts'], $event_id ) : '';
                $cells[] = $visit ? $this->clock( $visit['last_ts'], $event_id ) : '';
                $cells[] = $visit ? (int) \round( $visit['seconds'] / 60 ) : '';
            }
            $rows[] = $cells;
        }
        return [ 'header' => $header, 'rows' => $rows ];
    }

    public function handle_export() {
        \check_admin_referer( 'anchor_event_export' );
        if ( ! Roster::current_user_can_manage() ) {
            \wp_die( \esc_html__( 'Unauthorized', 'anchor-schema' ) );
        }
        $event_id = isset( $_GET['event_id'] ) ? (int) \wp_unslash( $_GET['event_id'] ) : 0;
        if ( ! Roster::is_exportable_event( $event_id ) ) {
            \wp_die( \esc_html__( 'Invalid event.', 'anchor-schema' ) );
        }
        $this->module->roster->stream_csv( 'event-attendance-' . $event_id . '-' . \gmdate( 'Ymd' ) . '.csv', $this->export_table( $event_id ) );
    }
}
