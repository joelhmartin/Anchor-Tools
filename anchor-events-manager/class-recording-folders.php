<?php
/**
 * Session recordings live in the Library, not the room: one folder per event, with a subfolder per online session.
 *
 * The event's own access role can view the course folder, so registering is what gives access. Each session folder
 * gets an "available until" date worked out from the session's date, which the Library enforces on the server.
 * Someone still puts each recording into its folder by hand: Vimeo saves one video per stop and start, and those
 * can only be matched to a session by a person until a real stream has run.
 *
 * Saving an event re-runs sync(), and it is safe to repeat. It never touches a date an admin changed, and it only
 * removes a folder that is empty and whose session left the schedule. Setting the day count to 0 takes the role's
 * access away and leaves the folders in place, so turning it back on restores them.
 *
 * @package AnchorTools\Events
 */

namespace Anchor\Events;

use Anchor\Library\Module as LibraryModule;
use Anchor\Library\Provisioning;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

class Recording_Folders {

    /** Top-level Library folder that holds every event's folder. */
    const ROOT_NAME = 'Event Recordings';

    /** Event meta: the event's Library folder id. */
    const META_FOLDER = '_anchor_event_recordings_folder';

    /** Event meta: session date => [ 'folder' => id, 'until' => the date sync() last generated ]. */
    const META_SESSIONS = '_anchor_event_recordings_sessions';

    /** @var Module */
    private $module;

    public function __construct( Module $module ) {
        $this->module = $module;
        // After save_meta (priority 10), so the saved schedule and day count are what gets read.
        \add_action( 'save_post_' . Module::CPT, [ $this, 'on_save' ], 30 );
    }

    public function on_save( $post_id ) {
        if ( \wp_is_post_revision( $post_id ) || \wp_is_post_autosave( $post_id ) ) {
            return;
        }
        $this->sync( (int) $post_id );
    }

    /** The Library module is optional and is switched on per site. */
    public function library_available() {
        return \class_exists( LibraryModule::class ) && LibraryModule::is_running();
    }

    public function days( $event_id ) {
        $meta = $this->module->get_meta( (int) $event_id );
        return \max( 0, (int) ( $meta['recording_days'] ?? 0 ) );
    }

    /**
     * Online sessions with a date, keyed by date. Two on one day share a folder.
     *
     * @return array<string,string> date => label
     */
    public function streamable_sessions( $event_id ) {
        $out = [];
        foreach ( $this->module->resolved_sessions( (int) $event_id ) as $row ) {
            $date = (string) ( $row['date'] ?? '' );
            if ( $date === '' || ! \in_array( (string) ( $row['modality'] ?? '' ), Stream_State::STREAMABLE, true ) ) {
                continue;
            }
            if ( ! isset( $out[ $date ] ) ) {
                $out[ $date ] = (string) ( $row['label'] ?? '' );
            }
        }
        return $out;
    }

    /**
     * Bring the event's folders in line with its schedule.
     *
     * @return int The event's folder id, or 0 when recordings are off or unavailable.
     */
    public function sync( $event_id ) {
        $event_id = (int) $event_id;
        $days     = $this->days( $event_id );
        if ( ! $this->library_available() ) {
            return 0;
        }
        if ( $days <= 0 ) {
            $this->close_access( $event_id );
            return 0;
        }
        if ( ! $this->module->entitlements->enabled( $event_id ) ) {
            return 0;
        }
        $sessions = $this->streamable_sessions( $event_id );
        if ( ! $sessions ) {
            return 0; // An offering's main event: its dates hold the sessions.
        }
        $role = $this->module->entitlements->role_for( $event_id );
        if ( $role === '' ) {
            return 0;
        }
        $folder_id = $this->course_folder( $event_id );
        if ( $folder_id <= 0 ) {
            return 0;
        }
        Provisioning::grant_role_view( $folder_id, $role );

        $known = \get_post_meta( $event_id, self::META_SESSIONS, true );
        $known = \is_array( $known ) ? $known : [];
        $map   = [];
        foreach ( $sessions as $date => $label ) {
            $entry = $this->session_folder( $folder_id, $date, $label, $days, $known[ $date ] ?? null );
            if ( $entry ) {
                $map[ $date ] = $entry;
            }
        }
        // A session that left the schedule keeps its folder only if something is in it.
        foreach ( $known as $date => $entry ) {
            if ( ! isset( $map[ $date ] ) && ! Provisioning::delete_if_empty( (int) ( $entry['folder'] ?? 0 ) ) && Provisioning::folder_exists( (int) ( $entry['folder'] ?? 0 ) ) ) {
                $map[ $date ] = $entry;
            }
        }
        \ksort( $map );
        \update_post_meta( $event_id, self::META_SESSIONS, $map );
        return $folder_id;
    }

    /** Recordings were switched off: registrants lose the folder. Nothing is deleted. */
    private function close_access( $event_id ) {
        $folder_id = (int) \get_post_meta( $event_id, self::META_FOLDER, true );
        $role      = $this->module->entitlements->role_for( $event_id, false );
        if ( $folder_id > 0 && $role !== '' ) {
            Provisioning::revoke_role_view( $folder_id, $role );
        }
    }

    /** The event's folder, reused while it exists. A name taken by another event gets the id added. */
    private function course_folder( $event_id ) {
        $stored = (int) \get_post_meta( $event_id, self::META_FOLDER, true );
        if ( Provisioning::folder_exists( $stored ) ) {
            return $stored;
        }
        $root = Provisioning::find_or_create_folder( 0, self::ROOT_NAME );
        if ( $root <= 0 ) {
            return 0;
        }
        $name = \wp_specialchars_decode( \get_the_title( $event_id ), ENT_QUOTES );
        if ( Provisioning::folder_id_by_name( $root, $name ) > 0 ) {
            $name .= ' #' . $event_id;
        }
        $folder_id = Provisioning::create_folder( $root, $name );
        if ( $folder_id > 0 ) {
            \update_post_meta( $event_id, self::META_FOLDER, $folder_id );
        }
        return $folder_id;
    }

    /**
     * One session's folder. The date is only rewritten when it still holds what sync() last generated. A deadline an
     * admin changed differs from that, and keeps differing, so it survives every later save of the event.
     *
     * @param array|null $known What sync() recorded last time.
     * @return array|null [ 'folder' => id, 'until' => the date sync() last generated ]
     */
    private function session_folder( $parent_id, $date, $label, $days, $known ) {
        $until = self::until_date( $date, $days );
        if ( $until === '' ) {
            return null;
        }
        $folder_id = \is_array( $known ) ? (int) ( $known['folder'] ?? 0 ) : 0;
        if ( Provisioning::folder_exists( $folder_id ) ) {
            if ( Provisioning::available_until( $folder_id ) !== (string) ( $known['until'] ?? '' ) ) {
                return $known;
            }
        } else {
            // The date comes first so the sessions sort in order; "Session 10" would otherwise follow "Session 1".
            $folder_id = Provisioning::find_or_create_folder( $parent_id, \trim( $date . ' ' . ( $label !== '' ? $label : \__( 'Session', 'anchor-schema' ) ) ) );
            if ( $folder_id <= 0 ) {
                return null;
            }
        }
        Provisioning::set_available_until( $folder_id, $until );
        return [ 'folder' => $folder_id, 'until' => $until ];
    }

    /** Last day a recording stays open: the session date plus the event's day count. */
    public static function until_date( $date, $days ) {
        try {
            return ( new \DateTimeImmutable( (string) $date, new \DateTimeZone( 'UTC' ) ) )
                ->modify( '+' . (int) $days . ' days' )
                ->format( 'Y-m-d' );
        } catch ( \Exception $e ) {
            return '';
        }
    }

    /** Where an attendee opens the recordings: the account portal, on the event's folder. */
    public function url( $event_id ) {
        $event_id  = (int) $event_id;
        $folder_id = (int) \get_post_meta( $event_id, self::META_FOLDER, true );
        if ( $this->days( $event_id ) <= 0 || ! $this->library_available() || ! Provisioning::folder_exists( $folder_id ) ) {
            return '';
        }
        $portal = \function_exists( 'wc_get_page_permalink' ) ? (string) \wc_get_page_permalink( 'myaccount' ) : '';
        if ( $portal === '' ) {
            $portal = Provisioning::portal_page_url();
        }
        /**
         * The page that renders the Library portal.
         *
         * @param string $portal   The WooCommerce account page, else the first page with a portal shortcode.
         * @param int    $event_id
         */
        $portal = (string) \apply_filters( 'anchor_events_recordings_portal_url', $portal, $event_id );
        return $portal === '' ? '' : $portal . '#afm-folder-' . $folder_id;
    }

    /** The link printed in the room. */
    public function render_room_link( $event_id ) {
        $url = $this->url( $event_id );
        if ( $url === '' ) {
            return '';
        }
        return '<p class="anchor-room-recordings"><a href="' . \esc_url( $url ) . '">' . \esc_html__( 'Session recordings', 'anchor-schema' ) . '</a> '
            . \esc_html( \sprintf(
                /* translators: %d: number of days */
                \_n( '(available for %d day after each session)', '(available for %d days after each session)', $this->days( $event_id ), 'anchor-schema' ),
                $this->days( $event_id )
            ) ) . '</p>';
    }

    /**
     * Each recording and how much of it each registered person played. Staff who open a recording to check it are
     * not registrants, so they are not listed.
     *
     * @return array<int,array{title:string,session:string,views:array}>
     */
    public function report( $event_id ) {
        $event_id = (int) $event_id;
        $map      = \get_post_meta( $event_id, self::META_SESSIONS, true );
        if ( ! \is_array( $map ) || ! $map || ! $this->library_available() ) {
            return [];
        }
        $dates = [];
        foreach ( $map as $date => $entry ) {
            $dates[ (int) ( $entry['folder'] ?? 0 ) ] = (string) $date;
        }
        $people = [];
        foreach ( $this->module->room_attendance->report( $event_id )['people'] as $person ) {
            if ( ! empty( $person['user_id'] ) ) {
                $people[ (int) $person['user_id'] ] = $person;
            }
        }
        $out = [];
        foreach ( Provisioning::videos_with_views( \array_keys( $dates ) ) as $video ) {
            $views = [];
            foreach ( $video['views'] as $view ) {
                if ( isset( $people[ $view['user_id'] ] ) ) {
                    $views[] = [
                        'name'    => (string) $people[ $view['user_id'] ]['name'],
                        'email'   => (string) $people[ $view['user_id'] ]['email'],
                        'percent' => (int) $view['percent'],
                        'last'    => (string) $view['last_viewed_at'],
                    ];
                }
            }
            \usort( $views, static function ( $a, $b ) {
                return $b['percent'] <=> $a['percent'];
            } );
            $out[] = [ 'title' => $video['title'], 'session' => $dates[ $video['folder_id'] ] ?? '', 'views' => $views ];
        }
        return $out;
    }

    /** Roster panel. Empty until the event keeps recordings. */
    public function render_panel( $event_id, $admin = false ) {
        $event_id = (int) $event_id;
        if ( $this->days( $event_id ) <= 0 || ! $this->library_available() ) {
            return '';
        }
        $out  = '<div class="anchor-roster-attendance anchor-roster-recordings">';
        $out .= '<h3>' . \esc_html__( 'Recording views', 'anchor-schema' ) . '</h3>';
        $out .= '<p class="description">' . \esc_html__( 'Share of each recording the person played in the player. It shows the video ran, not that they were paying attention.', 'anchor-schema' ) . '</p>';
        $rows = $this->report( $event_id );
        if ( ! $rows ) {
            return $out . '<p class="anchor-roster-fe-empty">' . \esc_html__( 'No recordings have been added yet.', 'anchor-schema' ) . '</p></div>';
        }
        $out .= '<div class="anchor-roster-fe-tablewrap"><table class="' . ( $admin ? 'widefat striped' : 'anchor-roster-fe-table' ) . '"><thead><tr>'
            . '<th>' . \esc_html__( 'Session', 'anchor-schema' ) . '</th>'
            . '<th>' . \esc_html__( 'Recording', 'anchor-schema' ) . '</th>'
            . '<th>' . \esc_html__( 'Played by', 'anchor-schema' ) . '</th>'
            . '</tr></thead><tbody>';
        foreach ( $rows as $row ) {
            $out .= '<tr><td>' . \esc_html( $row['session'] ) . '</td><td>' . \esc_html( $row['title'] ) . '</td><td>';
            if ( ! $row['views'] ) {
                $out .= '&mdash;';
            } else {
                $out .= '<details><summary>' . \esc_html( \sprintf( \_n( '%d person', '%d people', \count( $row['views'] ), 'anchor-schema' ), \count( $row['views'] ) ) ) . '</summary><ul>';
                foreach ( $row['views'] as $view ) {
                    $out .= '<li>' . \esc_html( \sprintf( '%s: %d%%', $view['name'], $view['percent'] ) ) . '</li>';
                }
                $out .= '</ul></details>';
            }
            $out .= '</td></tr>';
        }
        return $out . '</tbody></table></div></div>';
    }
}
