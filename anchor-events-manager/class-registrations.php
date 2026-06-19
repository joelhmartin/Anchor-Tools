<?php
/**
 * Registration (seat) data-access layer.
 *
 * Owns every read/write of the `anchor_event_reg` seat records and is the single
 * authority for capacity math, so the free and paid registration paths can never
 * diverge. Hiding all seat storage behind this class means a future move to a
 * custom table would be contained here.
 *
 * One seat = one published `anchor_event_reg` post. Status lives in post_meta
 * (`_anchor_event_reg_status`); records are never hard-deleted — terminal states
 * (cancelled/refunded/failed) are kept for history.
 *
 * @package AnchorTools\Events
 */

namespace Anchor\Events;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

class Registrations {

    // Status vocabulary (spec §4.2).
    const STATUS_CONFIRMED = 'confirmed'; // ACTIVE, counts toward capacity.
    const STATUS_PENDING   = 'pending';   // RESERVED (WC on-hold), counts toward capacity.
    const STATUS_WAITLIST  = 'waitlist';  // over-capacity overflow, counted separately.
    const STATUS_CANCELLED = 'cancelled'; // kept, excluded from counts.
    const STATUS_REFUNDED  = 'refunded';  // kept, terminal, never revived.
    const STATUS_FAILED    = 'failed';    // kept, excluded from counts.
    const STATUS_ATTENDED  = 'attended';  // reserved vocabulary (check-in deferred); NOT counted in MVP.
    const STATUS_NO_SHOW   = 'no_show';   // reserved vocabulary; inactive.

    /** Statuses that consume capacity. `attended` is intentionally NOT here in MVP (spec finding #21). */
    const RESERVING_STATUSES = [ self::STATUS_CONFIRMED, self::STATUS_PENDING ];

    /** Statuses counted as waitlist (never toward capacity). */
    const WAITLIST_STATUSES = [ self::STATUS_WAITLIST ];

    /** Allowed status transitions (spec §4.3). Illegal transitions are logged + no-oped. */
    protected static $transitions = [
        self::STATUS_PENDING   => [ self::STATUS_CONFIRMED, self::STATUS_CANCELLED, self::STATUS_FAILED, self::STATUS_REFUNDED, self::STATUS_WAITLIST ],
        self::STATUS_CONFIRMED => [ self::STATUS_CANCELLED, self::STATUS_REFUNDED, self::STATUS_FAILED, self::STATUS_ATTENDED, self::STATUS_NO_SHOW ],
        self::STATUS_WAITLIST  => [ self::STATUS_CONFIRMED, self::STATUS_CANCELLED, self::STATUS_FAILED ],
        self::STATUS_FAILED    => [ self::STATUS_CONFIRMED, self::STATUS_PENDING ],
        self::STATUS_CANCELLED => [ self::STATUS_CONFIRMED, self::STATUS_PENDING ],
        self::STATUS_REFUNDED  => [], // terminal — never auto-revived.
    ];

    /** @var Module */
    private $module;

    public function __construct( Module $module ) {
        $this->module = $module;
    }

    /* ---------------------------------------------------------------------
     * Seat creation & status changes
     * ------------------------------------------------------------------- */

    /**
     * Create one seat record. Writes all meta, the first history entry, and the
     * source. Busts the capacity cache for the event.
     *
     * @param array $args {
     *   @type int    $event_id      Required. Parent event post ID.
     *   @type string $name          Attendee name (also post_title).
     *   @type string $email         Attendee email.
     *   @type string $phone         Attendee phone.
     *   @type string $status        One of the STATUS_* constants. Default 'confirmed'.
     *   @type int    $guests        Free-path plus-ones (Woo seats are always 0).
     *   @type array  $reg_fields    Custom field values.
     *   @type string $source        internal|woocommerce|manual|imported. Default 'internal'.
     *   @type int    $order_id      WC order ID (0 if none).
     *   @type int    $order_item_id WC line-item ID (0 if none).
     *   @type int    $product_id    WC product ID.
     *   @type int    $variation_id  WC variation ID (0 for simple).
     *   @type int    $customer_id   WC customer ID (0 = guest).
     *   @type int    $seat_index    1..qty within an order item. Default 1.
     *   @type string $note          History note for the first entry.
     *   @type string $actor         History actor. Default 'system'.
     * }
     * @return int Seat post ID, or 0 on failure.
     */
    public function create_seat( array $args ) {
        $event_id = (int) ( $args['event_id'] ?? 0 );
        if ( $event_id <= 0 ) {
            return 0;
        }

        $name   = \sanitize_text_field( (string) ( $args['name'] ?? '' ) );
        $status = $this->valid_status( $args['status'] ?? self::STATUS_CONFIRMED ) ? $args['status'] : self::STATUS_CONFIRMED;
        $source = (string) ( $args['source'] ?? 'internal' );
        $actor  = (string) ( $args['actor'] ?? 'system' );
        $note   = (string) ( $args['note'] ?? '' );

        $seat_id = \wp_insert_post( [
            'post_type'   => Module::REG_CPT,
            'post_status' => 'publish',
            'post_title'  => $name !== '' ? $name : \__( '(attendee)', 'anchor-schema' ),
        ], true );

        if ( \is_wp_error( $seat_id ) || ! $seat_id ) {
            Events_Log::error( 'seat_insert_failed', [ 'event' => $event_id ] );
            return 0;
        }

        $meta = [
            '_anchor_event_id'            => $event_id,
            '_anchor_event_name'          => $name,
            '_anchor_event_email'         => \sanitize_email( (string) ( $args['email'] ?? '' ) ),
            '_anchor_event_phone'         => \sanitize_text_field( (string) ( $args['phone'] ?? '' ) ),
            '_anchor_event_reg_status'    => $status,
            '_anchor_event_reg_fields'    => \is_array( $args['reg_fields'] ?? null ) ? $args['reg_fields'] : [],
            '_anchor_event_guests'        => max( 0, (int) ( $args['guests'] ?? 0 ) ),
            '_anchor_event_source'        => $source,
            '_anchor_event_order_id'      => max( 0, (int) ( $args['order_id'] ?? 0 ) ),
            '_anchor_event_order_item_id' => max( 0, (int) ( $args['order_item_id'] ?? 0 ) ),
            '_anchor_event_product_id'    => max( 0, (int) ( $args['product_id'] ?? 0 ) ),
            '_anchor_event_variation_id'  => max( 0, (int) ( $args['variation_id'] ?? 0 ) ),
            '_anchor_event_customer_id'   => max( 0, (int) ( $args['customer_id'] ?? 0 ) ),
            '_anchor_event_seat_index'    => max( 1, (int) ( $args['seat_index'] ?? 1 ) ),
            '_anchor_event_history'       => [
                [ 'status' => $status, 'time' => \time(), 'note' => $note, 'actor' => $actor ],
            ],
        ];
        foreach ( $meta as $key => $value ) {
            \update_post_meta( $seat_id, $key, $value );
        }

        $this->bust_cache( $event_id );
        return (int) $seat_id;
    }

    /**
     * Change a seat's status with transition validation + history append.
     * No-ops (returns true) for same-status calls without a note; logs and returns
     * false for illegal transitions. Never fatal.
     *
     * @param int    $seat_id Seat post ID.
     * @param string $to      Target status.
     * @param string $note    History note.
     * @param string $actor   History actor.
     * @return bool True if the status changed (or was a benign no-op).
     */
    public function update_status( $seat_id, $to, $note = '', $actor = 'system' ) {
        $seat_id = (int) $seat_id;
        if ( $seat_id <= 0 || \get_post_type( $seat_id ) !== Module::REG_CPT ) {
            return false;
        }
        if ( ! $this->valid_status( $to ) ) {
            Events_Log::error( 'invalid_status', [ 'seat' => $seat_id, 'to' => $to ] );
            return false;
        }

        $from = (string) \get_post_meta( $seat_id, '_anchor_event_reg_status', true );
        if ( $from === '' ) {
            $from = self::STATUS_CONFIRMED;
        }

        // Same status: no-op unless a note is supplied (then just record the note).
        if ( $from === $to && $note === '' ) {
            return true;
        }

        if ( $from !== $to ) {
            $allowed = self::$transitions[ $from ] ?? [];
            if ( ! \in_array( $to, $allowed, true ) ) {
                Events_Log::error( 'illegal_transition', [ 'seat' => $seat_id, 'from' => $from, 'to' => $to ] );
                return false;
            }
        }

        // Legacy-row baseline backfill (spec §4.4 / finding #23): synthesize a
        // "created"-equivalent entry before the first real transition so the
        // audit trail isn't truncated.
        $history = \get_post_meta( $seat_id, '_anchor_event_history', true );
        if ( ! \is_array( $history ) ) {
            $history = [];
        }
        if ( empty( $history ) ) {
            $post = \get_post( $seat_id );
            $history[] = [
                'status' => $from,
                'time'   => $post ? (int) \mysql2date( 'U', $post->post_date_gmt ?: $post->post_date, false ) : \time(),
                'note'   => 'pre-existing',
                'actor'  => 'system',
            ];
        }

        \update_post_meta( $seat_id, '_anchor_event_reg_status', $to );
        $history[] = [ 'status' => $to, 'time' => \time(), 'note' => (string) $note, 'actor' => (string) $actor ];
        \update_post_meta( $seat_id, '_anchor_event_history', $history );

        $event_id = (int) \get_post_meta( $seat_id, '_anchor_event_id', true );
        $this->bust_cache( $event_id );
        return true;
    }

    /* ---------------------------------------------------------------------
     * Capacity counting (single authority — spec §4.5)
     * ------------------------------------------------------------------- */

    /** Weighted seat count of reserving statuses (confirmed + pending). */
    public function count_reserved_seats( $event_id, $fresh = false ) {
        $c = $this->counts( $event_id, $fresh );
        $total = 0;
        foreach ( self::RESERVING_STATUSES as $s ) {
            $total += $c[ $s ]['seats'] ?? 0;
        }
        return $total;
    }

    /** Weighted seat count of waitlist statuses. */
    public function count_waitlist_seats( $event_id, $fresh = false ) {
        $c = $this->counts( $event_id, $fresh );
        $total = 0;
        foreach ( self::WAITLIST_STATUSES as $s ) {
            $total += $c[ $s ]['seats'] ?? 0;
        }
        return $total;
    }

    /**
     * Weighted attendee count for a single status. Preserves the legacy
     * Module::get_attendee_count( $event_id, $status ) semantics.
     */
    public function attendee_count( $event_id, $status = self::STATUS_CONFIRMED ) {
        $c = $this->counts( $event_id );
        return $c[ $status ]['seats'] ?? 0;
    }

    /**
     * Record count (not weighted). $status = null counts all statuses.
     * Preserves the legacy Module::get_registration_count() semantics.
     */
    public function record_count( $event_id, $status = self::STATUS_CONFIRMED ) {
        $c = $this->counts( $event_id );
        if ( $status === null ) {
            $total = 0;
            foreach ( $c as $bucket ) {
                $total += $bucket['records'] ?? 0;
            }
            return $total;
        }
        return $c[ $status ]['records'] ?? 0;
    }

    /** Remaining capacity. Capacity 0 = unlimited. */
    public function remaining_capacity( $event_id, $capacity, $fresh = false ) {
        $capacity = (int) $capacity;
        if ( $capacity <= 0 ) {
            return PHP_INT_MAX;
        }
        return max( 0, $capacity - $this->count_reserved_seats( $event_id, $fresh ) );
    }

    /**
     * Per-status weighted seat + record counts via one aggregate query.
     * Cached in transient `anchor_evt_caps_{id}`; pass $fresh=true to bypass
     * (the authoritative recount under the lock always uses fresh).
     *
     * @return array<string,array{seats:int,records:int}>
     */
    private function counts( $event_id, $fresh = false ) {
        $event_id = (int) $event_id;
        if ( $event_id <= 0 ) {
            return [];
        }
        $key = 'anchor_evt_caps_' . $event_id;
        if ( ! $fresh ) {
            $cached = \get_transient( $key );
            if ( \is_array( $cached ) ) {
                return $cached;
            }
        }

        global $wpdb;
        $sql = "SELECT pm_status.meta_value AS status,
                       COALESCE(SUM(1 + GREATEST(0, CAST(pm_guests.meta_value AS UNSIGNED))), 0) AS seats,
                       COUNT(*) AS records
                FROM {$wpdb->posts} p
                JOIN {$wpdb->postmeta} pm_event  ON pm_event.post_id = p.ID AND pm_event.meta_key = '_anchor_event_id'
                JOIN {$wpdb->postmeta} pm_status ON pm_status.post_id = p.ID AND pm_status.meta_key = '_anchor_event_reg_status'
                LEFT JOIN {$wpdb->postmeta} pm_guests ON pm_guests.post_id = p.ID AND pm_guests.meta_key = '_anchor_event_guests'
                WHERE p.post_type = %s AND p.post_status = 'publish' AND pm_event.meta_value = %d
                GROUP BY pm_status.meta_value";
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, Module::REG_CPT, $event_id ), ARRAY_A );

        $out = [];
        foreach ( (array) $rows as $row ) {
            $status = (string) $row['status'];
            if ( $status === '' ) {
                $status = self::STATUS_CONFIRMED; // legacy rows that defaulted via `?: 'confirmed'`.
            }
            $out[ $status ] = [
                'seats'   => (int) $row['seats'],
                'records' => (int) $row['records'],
            ];
        }

        \set_transient( $key, $out, HOUR_IN_SECONDS );
        return $out;
    }

    /**
     * Capacity decision for a prospective registration. Preserves the legacy
     * get_registration_status() window/closed/full/waitlist semantics.
     *
     * @return string open|closed|full|waitlist
     */
    public function capacity_decision( $event_id, $meta, $requested = 1 ) {
        $now = \date( 'Y-m-d' );
        if ( ! empty( $meta['registration_open'] ) && $now < $meta['registration_open'] ) {
            return 'closed';
        }
        if ( ! empty( $meta['registration_close'] ) && $now > $meta['registration_close'] ) {
            return 'closed';
        }
        $capacity = (int) ( $meta['capacity'] ?? 0 );
        if ( $capacity > 0 ) {
            $requested = max( 1, (int) $requested );
            if ( ( $this->count_reserved_seats( $event_id ) + $requested ) > $capacity ) {
                return ! empty( $meta['waitlist'] ) ? self::STATUS_WAITLIST : 'full';
            }
        }
        return 'open';
    }

    /* ---------------------------------------------------------------------
     * Concurrency (spec §9.2)
     * ------------------------------------------------------------------- */

    /**
     * Run $fn while holding a per-event MySQL named lock. $fn receives a bool
     * indicating whether the lock was actually acquired (false = degraded mode).
     * Releases in finally. Degrades gracefully if GET_LOCK is unavailable.
     */
    public function with_event_lock( $event_id, callable $fn ) {
        global $wpdb;
        $name   = $this->lock_name( $event_id );
        $got    = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, 5 ) );
        $locked = ( 1 === $got );
        if ( ! $locked ) {
            Events_Log::error( 'lock_unavailable', [ 'event' => (int) $event_id ] );
        }
        try {
            return $fn( $locked );
        } finally {
            if ( $locked ) {
                $wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
            }
        }
    }

    private function lock_name( $event_id ) {
        global $wpdb;
        // Prefix with the DB table prefix so multi-site-on-one-DB installs don't collide.
        return $wpdb->prefix . 'anchor_evt_' . (int) $event_id;
    }

    /**
     * Shared reservation routine for the free and paid paths. Acquires the event
     * lock, recounts capacity from the DB (never the cache), de-dupes Woo seats by
     * (order_item_id, seat_index) across ALL statuses INSIDE the lock, then creates
     * confirmed/pending seats up to remaining and overflow as waitlist (when the
     * event's waitlist toggle is on).
     *
     * @param int   $event_id Event ID.
     * @param array $meta     Event meta (needs 'capacity', 'waitlist').
     * @param int   $qty      Number of seats to create.
     * @param array $payload  Per-seat create_seat() args (source, name, email, phone, order ids, etc.).
     * @return array{created:int[],waitlisted:int[],remaining_before:int,lock_unavailable:bool,status:string}
     */
    public function claim_seats( $event_id, $meta, $qty, array $payload ) {
        $event_id = (int) $event_id;
        $qty      = max( 1, (int) $qty );

        return $this->with_event_lock( $event_id, function ( $locked ) use ( $event_id, $meta, $qty, $payload ) {
            $capacity         = (int) ( $meta['capacity'] ?? 0 );
            $waitlist_enabled = ! empty( $meta['waitlist'] );
            $unlimited        = ( $capacity <= 0 );

            // Fresh recount under the lock (spec §9.2) — never the cache.
            $reserved  = $this->count_reserved_seats( $event_id, true );
            $remaining = $unlimited ? PHP_INT_MAX : max( 0, $capacity - $reserved );

            $is_woo     = ( (int) ( $payload['order_item_id'] ?? 0 ) > 0 );
            $base_index = max( 1, (int) ( $payload['seat_index'] ?? 1 ) );
            // The free path packs plus-ones into a single record; Woo is 1:1.
            $guests = max( 0, (int) ( $payload['guests'] ?? 0 ) );

            $created     = [];
            $waitlisted  = [];

            for ( $i = 0; $i < $qty; $i++ ) {
                $seat_index = $is_woo ? ( $base_index + $i ) : 1;
                $weight     = $is_woo ? 1 : ( 1 + $guests );

                // Woo idempotency self-defense (spec §4.1 / §9.2): no duplicate at
                // (order_item_id, seat_index) across any status. Runs under the lock.
                if ( $is_woo ) {
                    $dupe = $this->find_seat_by_item( (int) $payload['order_item_id'], $seat_index );
                    if ( $dupe ) {
                        Events_Log::flag_review(
                            (int) ( $payload['order_id'] ?? 0 ),
                            'duplicate_seat_prevented',
                            'item ' . (int) $payload['order_item_id'] . ' seat ' . $seat_index
                        );
                        continue;
                    }
                }

                if ( $unlimited || $remaining >= $weight ) {
                    $status     = self::STATUS_CONFIRMED;
                    $remaining -= $weight;
                } elseif ( $waitlist_enabled ) {
                    $status = self::STATUS_WAITLIST;
                } else {
                    // No room, no waitlist: create nothing. Caller decides how to surface.
                    continue;
                }

                $seat_args = array_merge( $payload, [
                    'event_id'   => $event_id,
                    'status'     => $status,
                    'seat_index' => $seat_index,
                ] );
                $seat_id = $this->create_seat( $seat_args );
                if ( ! $seat_id ) {
                    continue;
                }
                if ( $status === self::STATUS_WAITLIST ) {
                    $waitlisted[] = $seat_id;
                } else {
                    $created[] = $seat_id;
                }
            }

            $made = count( $created ) + count( $waitlisted );
            $status = 'ok';
            if ( $made === 0 ) {
                $status = 'full';
            } elseif ( $made < $qty ) {
                $status = 'partial';
            }

            return [
                'created'          => $created,
                'waitlisted'       => $waitlisted,
                'remaining_before' => $unlimited ? -1 : max( 0, $capacity - $reserved ),
                'lock_unavailable' => ! $locked,
                'status'           => $status,
            ];
        } );
    }

    /* ---------------------------------------------------------------------
     * Queries
     * ------------------------------------------------------------------- */

    /**
     * Find a single seat (any status) by its Woo idempotency key.
     * No-ops (returns 0) when $order_item_id <= 0 (spec §4.1 wildcard guard).
     */
    public function find_seat_by_item( $order_item_id, $seat_index ) {
        $order_item_id = (int) $order_item_id;
        if ( $order_item_id <= 0 ) {
            return 0;
        }
        $q = new \WP_Query( [
            'post_type'      => Module::REG_CPT,
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'posts_per_page' => 1,
            'no_found_rows'  => true,
            'meta_query'     => [
                [ 'key' => '_anchor_event_order_item_id', 'value' => $order_item_id, 'compare' => '=', 'type' => 'NUMERIC' ],
                [ 'key' => '_anchor_event_seat_index', 'value' => (int) $seat_index, 'compare' => '=', 'type' => 'NUMERIC' ],
            ],
        ] );
        return $q->posts ? (int) $q->posts[0] : 0;
    }

    /**
     * All seats for a Woo order item, ordered by seat_index (numeric).
     * Early-returns [] when $order_item_id <= 0 (spec §4.1 wildcard guard).
     *
     * @return int[] Seat post IDs.
     */
    public function get_seats_for_order_item( $order_item_id ) {
        $order_item_id = (int) $order_item_id;
        if ( $order_item_id <= 0 ) {
            return [];
        }
        $q = new \WP_Query( [
            'post_type'      => Module::REG_CPT,
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
            'meta_key'       => '_anchor_event_seat_index',
            'orderby'        => 'meta_value_num',
            'order'          => 'ASC',
            'meta_query'     => [
                [ 'key' => '_anchor_event_order_item_id', 'value' => $order_item_id, 'compare' => '=', 'type' => 'NUMERIC' ],
            ],
        ] );
        return array_map( 'intval', $q->posts );
    }

    /**
     * Registrant rows for display (preserves legacy Module::get_registrations shape).
     *
     * @return array<int,array{name:string,email:string,status:string,guests:int,date:string}>
     */
    public function get_registrations( $event_id, $limit = 50 ) {
        $q = new \WP_Query( [
            'post_type'      => Module::REG_CPT,
            'post_status'    => 'publish',
            'posts_per_page' => $limit ? (int) $limit : -1,
            'no_found_rows'  => true,
            'meta_query'     => [
                [ 'key' => '_anchor_event_id', 'value' => (int) $event_id, 'compare' => '=', 'type' => 'NUMERIC' ],
            ],
            'orderby' => 'date',
            'order'   => 'DESC',
        ] );
        $out = [];
        foreach ( $q->posts as $post ) {
            $out[] = [
                'name'   => \get_post_meta( $post->ID, '_anchor_event_name', true ),
                'email'  => \get_post_meta( $post->ID, '_anchor_event_email', true ),
                'status' => \get_post_meta( $post->ID, '_anchor_event_reg_status', true ) ?: self::STATUS_CONFIRMED,
                'guests' => (int) \get_post_meta( $post->ID, '_anchor_event_guests', true ),
                'date'   => \get_the_date( 'Y-m-d', $post ),
            ];
        }
        return $out;
    }

    /* ---------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------- */

    public function valid_status( $status ) {
        return \in_array( $status, [
            self::STATUS_CONFIRMED, self::STATUS_PENDING, self::STATUS_WAITLIST,
            self::STATUS_CANCELLED, self::STATUS_REFUNDED, self::STATUS_FAILED,
            self::STATUS_ATTENDED, self::STATUS_NO_SHOW,
        ], true );
    }

    /** Invalidate cached counts for an event + the module's list/calendar caches. */
    private function bust_cache( $event_id ) {
        if ( (int) $event_id > 0 ) {
            \delete_transient( 'anchor_evt_caps_' . (int) $event_id );
        }
        $this->module->clear_caches();
    }
}
