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
        self::STATUS_WAITLIST  => [ self::STATUS_CONFIRMED, self::STATUS_CANCELLED, self::STATUS_FAILED, self::STATUS_REFUNDED ],
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

    /**
     * Update a seat's editable contact fields (name/email/phone). Status is NOT
     * touched here — use update_status() for that. Keeps the post_title in sync
     * with the attendee name. Used by the roster manual-edit action so callers
     * never write seat meta directly.
     *
     * @param int   $seat_id
     * @param array $fields {name?, email?, phone?}
     * @return bool
     */
    public function update_contact( $seat_id, array $fields ) {
        $seat_id = (int) $seat_id;
        if ( $seat_id <= 0 || \get_post_type( $seat_id ) !== Module::REG_CPT ) {
            return false;
        }
        if ( \array_key_exists( 'name', $fields ) ) {
            $name = \sanitize_text_field( (string) $fields['name'] );
            if ( $name !== '' ) {
                \wp_update_post( [ 'ID' => $seat_id, 'post_title' => $name ] );
                \update_post_meta( $seat_id, '_anchor_event_name', $name );
            }
        }
        if ( \array_key_exists( 'email', $fields ) ) {
            \update_post_meta( $seat_id, '_anchor_event_email', \sanitize_email( (string) $fields['email'] ) );
        }
        if ( \array_key_exists( 'phone', $fields ) ) {
            \update_post_meta( $seat_id, '_anchor_event_phone', \sanitize_text_field( (string) $fields['phone'] ) );
        }
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
                       COALESCE(SUM(1 + GREATEST(0, CAST(COALESCE(pm_guests.meta_value, '0') AS SIGNED))), 0) AS seats,
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
        // Compare the registration window in WordPress site-local time — the
        // open/close dates are admin-entered in the site timezone (CodeRabbit).
        $now = \current_time( 'Y-m-d' );
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

    /**
     * WooCommerce reservation routine: create DISTINCT per-seat seat records for a
     * single order line. Unlike claim_seats() (one shared payload carrying guests
     * for the free path), each entry in $seats carries its own attendee details.
     *
     * Acquires the event lock ONCE, recounts remaining capacity from the DB inside
     * the lock (never the cache), and — for each requested seat_index — runs the
     * (order_item_id, seat_index) existence check across ALL statuses inside the
     * lock and SKIPS any seat that already exists (idempotency: re-firing
     * processing→completed must not duplicate). Allocates 'confirmed' while
     * capacity remains, else 'waitlist' when the event's waitlist toggle is on,
     * else leaves the seat UNCREATED and flags 'overfill' (buyer already paid but
     * no room and no waitlist — spec §9.3 finding #5/#9).
     *
     * @param int   $event_id Event post ID.
     * @param array $meta     Event meta (needs 'capacity', 'waitlist').
     * @param array $seats    Ordered map [seat_index => [
     *     'name','email','phone','order_id','order_item_id',
     *     'product_id','variation_id','customer_id', optional 'note'
     * ]].
     * @return array{created:int[],waitlisted:int[],overfill:bool,lock_unavailable:bool}
     */
    public function claim_woo_seats( $event_id, $meta, array $seats ) {
        $event_id = (int) $event_id;

        return $this->with_event_lock( $event_id, function ( $locked ) use ( $event_id, $meta, $seats ) {
            $capacity         = (int) ( $meta['capacity'] ?? 0 );
            $waitlist_enabled = ! empty( $meta['waitlist'] );
            $unlimited        = ( $capacity <= 0 );

            // Authoritative recount under the lock (spec §9.2) — never the cache.
            $reserved  = $this->count_reserved_seats( $event_id, true );
            $remaining = $unlimited ? PHP_INT_MAX : max( 0, $capacity - $reserved );

            $created    = [];
            $waitlisted = [];
            $overfill   = false;

            // Deterministic ascending allocation by seat_index.
            \ksort( $seats, SORT_NUMERIC );

            foreach ( $seats as $seat_index => $data ) {
                $seat_index    = (int) $seat_index;
                $order_item_id = (int) ( $data['order_item_id'] ?? 0 );

                // Idempotency self-defense (spec §4.1 / §9.2): the existence check
                // across any status runs INSIDE the lock, immediately before create,
                // so a re-fire (processing→completed) never duplicates a seat.
                if ( $order_item_id > 0 && $this->find_seat_by_item( $order_item_id, $seat_index ) ) {
                    continue;
                }

                if ( $unlimited || $remaining >= 1 ) {
                    $status = self::STATUS_CONFIRMED;
                    if ( ! $unlimited ) {
                        $remaining -= 1;
                    }
                } elseif ( $waitlist_enabled ) {
                    $status = self::STATUS_WAITLIST;
                } else {
                    // Buyer already paid, no room, no waitlist: leave uncreated and
                    // surface as overfill so an admin can audit (spec §9.3).
                    $overfill = true;
                    continue;
                }

                $seat_id = $this->create_seat( \array_merge( $data, [
                    'event_id'   => $event_id,
                    'status'     => $status,
                    'seat_index' => $seat_index,
                    'source'     => 'woocommerce',
                    'guests'     => 0,
                    'actor'      => 'woocommerce',
                    'note'       => (string) ( $data['note'] ?? ( 'order #' . (int) ( $data['order_id'] ?? 0 ) ) ),
                ] ) );
                if ( ! $seat_id ) {
                    continue;
                }
                if ( $status === self::STATUS_WAITLIST ) {
                    $waitlisted[] = $seat_id;
                } else {
                    $created[] = $seat_id;
                }
            }

            return [
                'created'          => $created,
                'waitlisted'       => $waitlisted,
                'overfill'         => $overfill,
                'lock_unavailable' => ! $locked,
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
     * Lightweight seat snapshot used by the reconcile engine. Reads seat-post meta
     * directly (HPOS governs orders/items, not the seat CPT). Returns null when the
     * id isn't a seat post.
     *
     * @param int $seat_id Seat post ID.
     * @return array{id:int,status:string,seat_index:int,event_id:int,order_item_id:int}|null
     */
    public function get_seat_info( $seat_id ) {
        $seat_id = (int) $seat_id;
        if ( $seat_id <= 0 || \get_post_type( $seat_id ) !== Module::REG_CPT ) {
            return null;
        }
        $status = (string) \get_post_meta( $seat_id, '_anchor_event_reg_status', true );
        return [
            'id'            => $seat_id,
            'status'        => $status !== '' ? $status : self::STATUS_CONFIRMED,
            'seat_index'    => (int) \get_post_meta( $seat_id, '_anchor_event_seat_index', true ),
            'event_id'      => (int) \get_post_meta( $seat_id, '_anchor_event_id', true ),
            'order_item_id' => (int) \get_post_meta( $seat_id, '_anchor_event_order_item_id', true ),
        ];
    }

    /**
     * All seats belonging to a WooCommerce order (any status, any line item).
     * Used by the order trash/delete capacity-release path (spec §7.8) where the
     * order is about to disappear so per-item lookups are unavailable afterwards.
     * Early-returns [] when $order_id <= 0.
     *
     * @param int $order_id WC order ID.
     * @return int[] Seat post IDs.
     */
    public function get_seats_for_order( $order_id ) {
        $order_id = (int) $order_id;
        if ( $order_id <= 0 ) {
            return [];
        }
        $q = new \WP_Query( [
            'post_type'      => Module::REG_CPT,
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
            'meta_query'     => [
                [ 'key' => '_anchor_event_order_id', 'value' => $order_id, 'compare' => '=', 'type' => 'NUMERIC' ],
            ],
        ] );
        return \array_map( 'intval', $q->posts );
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
     * Roster queries (spec §10) — Phase 5
     * ------------------------------------------------------------------- */

    /**
     * Paged, filtered, sortable seat query for the roster list table (spec §10.2).
     * All meta_query lives here (with `'type'=>'NUMERIC'` on `_anchor_event_id`,
     * and integer casting on `seat_index` ordering — finding #19). Primes the meta
     * cache for the page of results so DTO hydration is N+1-free.
     *
     * @param array $args {
     *   @type int    $event_id Required.
     *   @type string $status   '' / 'all' = any; 'active' = confirmed+pending; or a single status.
     *   @type string $source   '' / 'all' = any; internal|woocommerce|manual|imported.
     *   @type string $search   Numeric → exact order lookup (finding #15); else name/email LIKE.
     *   @type int    $paged    1-based page.
     *   @type int    $per_page Results per page.
     *   @type string $orderby  attendee|email|status|source|seat|date.
     *   @type string $order    ASC|DESC.
     * }
     * @return array{items:array<int,array>,total:int}
     */
    public function query_seats( array $args ) {
        $event_id = (int) ( $args['event_id'] ?? 0 );
        if ( $event_id <= 0 ) {
            return [ 'items' => [], 'total' => 0 ];
        }

        $status   = (string) ( $args['status'] ?? '' );
        $source   = (string) ( $args['source'] ?? '' );
        $search   = \trim( (string) ( $args['search'] ?? '' ) );
        $paged    = max( 1, (int) ( $args['paged'] ?? 1 ) );
        $per_page = max( 1, (int) ( $args['per_page'] ?? 25 ) );
        $orderby  = (string) ( $args['orderby'] ?? 'date' );
        $order    = \strtoupper( (string) ( $args['order'] ?? 'DESC' ) ) === 'ASC' ? 'ASC' : 'DESC';

        $meta_query = [
            'relation'     => 'AND',
            'event_clause' => [ 'key' => '_anchor_event_id', 'value' => $event_id, 'compare' => '=', 'type' => 'NUMERIC' ],
        ];

        // Status filter.
        if ( $status === 'active' ) {
            $meta_query[] = [ 'key' => '_anchor_event_reg_status', 'value' => self::RESERVING_STATUSES, 'compare' => 'IN' ];
        } elseif ( $status !== '' && $status !== 'all' && $this->valid_status( $status ) ) {
            $meta_query[] = [ 'key' => '_anchor_event_reg_status', 'value' => $status, 'compare' => '=' ];
        }

        // Source filter.
        if ( $source !== '' && $source !== 'all' ) {
            $meta_query[] = [ 'key' => '_anchor_event_source', 'value' => $source, 'compare' => '=' ];
        }

        // Search. Numeric → exact order lookup (finding #15), NOT fuzzy.
        if ( $search !== '' ) {
            if ( \ctype_digit( $search ) ) {
                $meta_query[] = [ 'key' => '_anchor_event_order_id', 'value' => (int) $search, 'compare' => '=', 'type' => 'NUMERIC' ];
            } else {
                $meta_query[] = [
                    'relation' => 'OR',
                    [ 'key' => '_anchor_event_name', 'value' => $search, 'compare' => 'LIKE' ],
                    [ 'key' => '_anchor_event_email', 'value' => $search, 'compare' => 'LIKE' ],
                ];
            }
        }

        $wp_args = [
            'post_type'              => Module::REG_CPT,
            'post_status'            => 'publish',
            'posts_per_page'         => $per_page,
            'paged'                  => $paged,
            'meta_query'             => $meta_query,
            'update_post_meta_cache' => true, // prime meta cache for the page (no N+1).
        ];

        // Ordering. seat_index ordered as an integer (CAST .. UNSIGNED via type clause).
        switch ( $orderby ) {
            case 'attendee':
                $wp_args['orderby'] = 'title';
                $wp_args['order']   = $order;
                break;
            case 'seat':
                $meta_query['seat_clause'] = [ 'key' => '_anchor_event_seat_index', 'compare' => 'EXISTS', 'type' => 'UNSIGNED' ];
                $wp_args['meta_query']     = $meta_query;
                $wp_args['orderby']        = [ 'seat_clause' => $order ];
                break;
            case 'status':
                $meta_query['status_clause'] = [ 'key' => '_anchor_event_reg_status', 'compare' => 'EXISTS' ];
                $wp_args['meta_query']       = $meta_query;
                $wp_args['orderby']          = [ 'status_clause' => $order ];
                break;
            case 'source':
                $meta_query['source_clause'] = [ 'key' => '_anchor_event_source', 'compare' => 'EXISTS' ];
                $wp_args['meta_query']       = $meta_query;
                $wp_args['orderby']          = [ 'source_clause' => $order ];
                break;
            case 'email':
                $meta_query['email_clause'] = [ 'key' => '_anchor_event_email', 'compare' => 'EXISTS' ];
                $wp_args['meta_query']      = $meta_query;
                $wp_args['orderby']         = [ 'email_clause' => $order ];
                break;
            case 'date':
            default:
                $wp_args['orderby'] = 'date';
                $wp_args['order']   = $order;
                break;
        }

        $q = new \WP_Query( $wp_args );

        $items = [];
        foreach ( $q->posts as $post ) {
            $items[] = $this->seat_dto( $post );
        }

        return [ 'items' => $items, 'total' => (int) $q->found_posts ];
    }

    /**
     * Build a seat DTO from a (meta-cache-primed) seat post.
     *
     * @param \WP_Post $post
     * @return array
     */
    private function seat_dto( $post ) {
        $id = (int) $post->ID;
        $g  = function ( $k ) use ( $id ) {
            return \get_post_meta( $id, $k, true );
        };
        $status = (string) $g( '_anchor_event_reg_status' );
        return [
            'id'            => $id,
            'name'          => (string) ( $g( '_anchor_event_name' ) ?: \get_the_title( $post ) ),
            'email'         => (string) $g( '_anchor_event_email' ),
            'phone'         => (string) $g( '_anchor_event_phone' ),
            'status'        => $status !== '' ? $status : self::STATUS_CONFIRMED,
            'guests'        => (int) $g( '_anchor_event_guests' ),
            'source'        => (string) ( $g( '_anchor_event_source' ) ?: 'internal' ),
            'order_id'      => (int) $g( '_anchor_event_order_id' ),
            'order_item_id' => (int) $g( '_anchor_event_order_item_id' ),
            'product_id'    => (int) $g( '_anchor_event_product_id' ),
            'variation_id'  => (int) $g( '_anchor_event_variation_id' ),
            'customer_id'   => (int) $g( '_anchor_event_customer_id' ),
            'seat_index'    => (int) $g( '_anchor_event_seat_index' ),
            'reg_fields'    => \is_array( $g( '_anchor_event_reg_fields' ) ) ? $g( '_anchor_event_reg_fields' ) : [],
            'date'          => \get_the_date( 'Y-m-d', $post ),
        ];
    }

    /**
     * Header summary for the roster screen (spec §10.1). Reuses the counts()
     * aggregate so this is a single query.
     *
     * @param int $event_id
     * @return array
     */
    public function get_event_summary( $event_id ) {
        $event_id = (int) $event_id;
        $meta     = $this->module->get_meta( $event_id );
        $capacity = (int) ( $meta['capacity'] ?? 0 );

        $c          = $this->counts( $event_id );
        $per_status = [];
        foreach ( [ self::STATUS_CONFIRMED, self::STATUS_PENDING, self::STATUS_WAITLIST, self::STATUS_CANCELLED, self::STATUS_REFUNDED, self::STATUS_FAILED, self::STATUS_ATTENDED, self::STATUS_NO_SHOW ] as $s ) {
            $per_status[ $s ] = [
                'seats'   => $c[ $s ]['seats'] ?? 0,
                'records' => $c[ $s ]['records'] ?? 0,
            ];
        }

        $reserved  = $this->count_reserved_seats( $event_id );
        $waitlist  = $this->count_waitlist_seats( $event_id );
        $confirmed = $per_status[ self::STATUS_CONFIRMED ]['seats'];
        $pending   = $per_status[ self::STATUS_PENDING ]['seats'];

        $linked     = \get_post_meta( $event_id, '_anchor_event_linked_products', true );
        $has_linked = \is_array( $linked ) && ! empty( $linked );

        return [
            'capacity'           => $capacity,
            'reserved'           => $reserved,
            'confirmed'          => $confirmed,
            'pending'            => $pending,
            'waitlist'           => $waitlist,
            'cancelled'          => $per_status[ self::STATUS_CANCELLED ]['seats'],
            'refunded'           => $per_status[ self::STATUS_REFUNDED ]['seats'],
            'failed'             => $per_status[ self::STATUS_FAILED ]['seats'],
            'remaining'          => $capacity > 0 ? max( 0, $capacity - $reserved ) : -1, // -1 = unlimited.
            'has_linked_product' => $has_linked,
            'is_overbooked'      => $capacity > 0 && $reserved > $capacity,
            'per_status'         => $per_status,
        ];
    }

    /**
     * Rows for the CSV export (spec §10.4). Batches order lookups via
     * wc_get_orders(['include'=>$ids]) once, guarded by function_exists so an
     * environment without WooCommerce simply leaves the order columns blank.
     *
     * @param int    $event_id
     * @param string $scope    'active' (confirmed only) or 'all'.
     * @return array{field_keys:string[],rows:array<int,array>}
     */
    public function get_export_rows( $event_id, $scope = 'all' ) {
        $event_id = (int) $event_id;
        if ( $event_id <= 0 ) {
            return [ 'field_keys' => [], 'rows' => [] ];
        }

        $meta_query = [
            [ 'key' => '_anchor_event_id', 'value' => $event_id, 'compare' => '=', 'type' => 'NUMERIC' ],
        ];
        if ( $scope === 'active' ) {
            $meta_query[] = [ 'key' => '_anchor_event_reg_status', 'value' => self::STATUS_CONFIRMED, 'compare' => '=' ];
        }

        $q = new \WP_Query( [
            'post_type'              => Module::REG_CPT,
            'post_status'            => 'publish',
            'posts_per_page'         => -1,
            'no_found_rows'          => true,
            'update_post_meta_cache' => true,
            'meta_query'             => $meta_query,
            'orderby'                => 'date',
            'order'                  => 'DESC',
        ] );

        $event_title = \get_the_title( $event_id );

        // Batch-load orders once (HPOS-safe), guarded by WC presence.
        $orders    = [];
        $order_ids = [];
        foreach ( $q->posts as $post ) {
            $oid = (int) \get_post_meta( $post->ID, '_anchor_event_order_id', true );
            if ( $oid > 0 ) {
                $order_ids[ $oid ] = true;
            }
        }
        if ( ! empty( $order_ids ) && \function_exists( 'wc_get_orders' ) ) {
            $batch = \wc_get_orders( [ 'include' => \array_keys( $order_ids ), 'limit' => -1 ] );
            foreach ( (array) $batch as $o ) {
                if ( $o instanceof \WC_Order ) {
                    $orders[ (int) $o->get_id() ] = $o;
                }
            }
        }

        $field_keys = [];
        $rows       = [];
        foreach ( $q->posts as $post ) {
            $dto = $this->seat_dto( $post );
            foreach ( \array_keys( $dto['reg_fields'] ) as $k ) {
                $field_keys[ $k ] = true;
            }

            $order_number = '';
            $order_status = '';
            $order_date   = '';
            $cust_email   = '';
            $oid          = $dto['order_id'];
            if ( $oid > 0 && isset( $orders[ $oid ] ) ) {
                $o            = $orders[ $oid ];
                $order_number = $o->get_order_number();
                $order_status = $o->get_status();
                $created      = $o->get_date_created();
                $order_date   = $created ? $created->date( 'Y-m-d' ) : '';
                $cust_email   = $o->get_billing_email();
            }

            $product_name = $dto['product_id'] > 0 ? \get_the_title( $dto['product_id'] ) : '';

            $rows[] = [
                'seat_id'        => $dto['id'],
                'event'          => $event_title,
                'name'           => $dto['name'],
                'email'          => $dto['email'],
                'phone'          => $dto['phone'],
                'status'         => $dto['status'],
                'source'         => $dto['source'],
                'guests'         => $dto['guests'],
                'party_size'     => 1 + $dto['guests'],
                'reg_date'       => $dto['date'],
                'order_number'   => (string) $order_number,
                'order_id'       => $oid > 0 ? $oid : '',
                'order_status'   => (string) $order_status,
                'order_date'     => (string) $order_date,
                'customer_id'    => $dto['customer_id'] > 0 ? $dto['customer_id'] : '',
                'customer_email' => (string) $cust_email,
                'product'        => (string) $product_name,
                'product_id'     => $dto['product_id'] > 0 ? $dto['product_id'] : '',
                'variation_id'   => $dto['variation_id'] > 0 ? $dto['variation_id'] : '',
                'order_item_id'  => $dto['order_item_id'] > 0 ? $dto['order_item_id'] : '',
                'seat_index'     => $dto['seat_index'],
                'fields'         => $dto['reg_fields'],
            ];
        }

        return [ 'field_keys' => \array_keys( $field_keys ), 'rows' => $rows ];
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
