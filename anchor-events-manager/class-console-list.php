<?php
/**
 * The console's event list: a main event with dates is ONE item, its dates
 * nested inside it.
 *
 * Module::render_event_manager_list() queries the events; this decides how they
 * are laid out. A date (group child) whose main event is in the list is drawn
 * inside that main event's item — "click the title for the main event, expand
 * for each date" — and is not repeated as its own item. A date whose main event
 * is missing, trashed, or not in this list is drawn as an ordinary item, so no
 * event is ever hidden by the grouping.
 *
 * @package Anchor\Events
 */

namespace Anchor\Events;

defined( 'ABSPATH' ) || exit;

class Console_List {

    /** Highest number of events one console page lists. */
    const MAX = 200;

    /** @var Module */
    private $module;

    public function __construct( Module $module ) {
        $this->module = $module;
    }

    /**
     * posts_per_page for the shortcode's `limit`: 0 or negative means "all"
     * (the theme renders limit="-1"), capped at MAX either way.
     */
    public static function per_page( $limit ) {
        $limit = (int) $limit;
        return $limit <= 0 ? self::MAX : \min( self::MAX, $limit );
    }

    /**
     * Partition the queried events into top-level entries.
     *
     * @param \WP_Post[] $events   In list order.
     * @param string[]   $statuses Post statuses the list shows.
     * @return array<int,array{event:\WP_Post,dates:int[]}> dates is empty for a flat item.
     */
    public function entries( array $events, array $statuses ) {
        $occurrences = $this->module->occurrences;
        $listed      = [];
        foreach ( $events as $event ) {
            $listed[ (int) $event->ID ] = true;
        }

        $entries = [];
        foreach ( $events as $event ) {
            $id = (int) $event->ID;
            if ( $occurrences && $occurrences->is_group_child( $id ) ) {
                $main = (int) $occurrences->parent_of( $id );
                if ( $main > 0 && isset( $listed[ $main ] ) ) {
                    continue; // Drawn inside its main event's item.
                }
            }
            $dates = [];
            if ( $occurrences && $occurrences->is_group_parent( $id ) ) {
                foreach ( $occurrences->children_any_status( $id ) as $date_id ) {
                    if ( \in_array( \get_post_status( $date_id ), $statuses, true ) ) {
                        $dates[] = (int) $date_id;
                    }
                }
            }
            $entries[] = [ 'event' => $event, 'dates' => $dates ];
        }
        return $entries;
    }

    /**
     * The nested list of a main event's dates: date, status, seats, and the
     * same Edit / Attendees links a flat item carries.
     *
     * @param int[]  $dates
     * @param string $base_url The console URL without event_action/event_id.
     */
    public function render_dates( array $dates, $base_url ) {
        $out = '<ul class="anchor-event-admin-dates">';
        foreach ( $dates as $date_id ) {
            $out .= $this->render_date_row( (int) $date_id, $base_url );
        }
        return $out . '</ul>';
    }

    private function render_date_row( $date_id, $base_url ) {
        $occurrences = $this->module->occurrences;
        $closed      = $occurrences && $occurrences->is_closed( $date_id );
        $status      = \get_post_status( $date_id );
        $state       = $this->module->get_event_status( $date_id );
        $edit_url    = \add_query_arg( [ 'event_action' => 'edit', 'event_id' => $date_id ], $base_url );

        $out  = '<li class="anchor-event-admin-date-row' . ( $closed ? ' is-closed' : '' ) . '">';
        $out .= '<span class="anchor-event-admin-date-label">' . \esc_html( $this->module->email_inheritance->date_label( $date_id ) ) . '</span>';
        $label = $this->module->status_label( $state );
        if ( \is_string( $label ) && $label !== '' ) {
            $out .= ' <span class="anchor-event-admin-date-status">' . \esc_html( $label ) . '</span>';
        }
        if ( $closed ) {
            $out .= ' <span class="anchor-event-admin-date-closed">' . \esc_html__( 'Closed', 'anchor-schema' ) . '</span>';
        }
        if ( $status !== 'publish' ) {
            $out .= ' <span class="anchor-event-admin-date">[' . \esc_html( (string) $status ) . ']</span>';
        }
        $out .= $this->module->render_registrant_counts( $date_id );
        $out .= ' <span class="anchor-event-admin-date-actions"><a href="' . \esc_url( $edit_url ) . '">' . \esc_html__( 'Edit', 'anchor-schema' ) . '</a>';
        if ( ! $this->module->uses_external_signup( $date_id ) && Roster::current_user_can_manage() ) {
            $roster_url = \add_query_arg( [ 'event_action' => 'roster', 'event_id' => $date_id ], $base_url );
            $out       .= ' &middot; <a href="' . \esc_url( $roster_url ) . '">' . \esc_html__( 'Attendees', 'anchor-schema' ) . '</a>';
        }
        return $out . '</span></li>';
    }
}
