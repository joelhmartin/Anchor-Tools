<?php
/**
 * Ticket_Types — the per-event ticket-tier model (spec §3.2).
 *
 * One responsibility: tier data. Reads/normalizes/saves the ordered list of
 * ticket tiers stored as structured event meta (`_anchor_event_ticket_types`),
 * assigns stable tier ids, looks tiers up, and answers sale-window questions.
 *
 * WooCommerce is NOT required here — this is the source of truth for tier
 * label/price/availability regardless of whether Woo is active. Free events
 * (no tier list) synthesize a single implicit "primary" tier from the legacy
 * `price` meta so existing data behaves unchanged (spec §11).
 *
 * @package Anchor\Events
 */

namespace Anchor\Events;

if ( ! \defined( 'ABSPATH' ) ) {
    exit;
}

class Ticket_Types {

    /** Event meta key holding the ordered tier list (array of tier arrays). */
    const META_KEY = '_anchor_event_ticket_types';

    /** Stable id of the synthesized implicit tier when no list exists. */
    const PRIMARY_ID = 'primary';

    /** @var Module */
    private $module;

    public function __construct( Module $module ) {
        $this->module = $module;
    }

    /**
     * Ordered, normalized list of ticket tiers for an event.
     *
     * When the event has no `_anchor_event_ticket_types` meta, synthesize a
     * single implicit tier (`id='primary'`) from the legacy `price` meta so
     * existing free/paid events keep working untouched.
     *
     * @param int $event_id
     * @return array<int,array> Each tier: id,label,price(float),quota(int),
     *   sale_start,sale_end,active(bool),wc_variation_id(int).
     */
    public function get( $event_id ) {
        $event_id = (int) $event_id;
        $stored   = \get_post_meta( $event_id, self::META_KEY, true );

        if ( ! \is_array( $stored ) || empty( $stored ) ) {
            return [ $this->implicit_primary( $event_id ) ];
        }

        $tiers = [];
        foreach ( $stored as $row ) {
            if ( ! \is_array( $row ) ) {
                continue;
            }
            $tiers[] = $this->normalize( $row );
        }

        if ( empty( $tiers ) ) {
            return [ $this->implicit_primary( $event_id ) ];
        }

        return $tiers;
    }

    /**
     * Sanitize posted tier rows, assign stable ids, drop empty rows, persist.
     *
     * Existing ids are preserved; blank/new rows get a short stable id. A
     * removed id is never reused (we only ever mint fresh ids for blank rows).
     *
     * @param int   $event_id
     * @param array $raw Rows of [id,label,price,quota,sale_start,sale_end,active,...].
     * @return array The saved (normalized) tier list.
     */
    public function save( $event_id, array $raw ) {
        $event_id = (int) $event_id;

        $clean    = [];
        $seen_ids = [];

        foreach ( $raw as $row ) {
            if ( ! \is_array( $row ) ) {
                continue;
            }

            $label           = isset( $row['label'] ) ? \sanitize_text_field( (string) $row['label'] ) : '';
            $price_raw       = isset( $row['price'] ) ? (string) $row['price'] : '';
            $sale_start      = isset( $row['sale_start'] ) ? $this->sanitize_date( (string) $row['sale_start'] ) : '';
            $sale_end        = isset( $row['sale_end'] ) ? $this->sanitize_date( (string) $row['sale_end'] ) : '';
            $quota           = isset( $row['quota'] ) ? \max( 0, (int) $row['quota'] ) : 0;
            $active          = ! empty( $row['active'] );
            $wc_variation_id = isset( $row['wc_variation_id'] ) ? \max( 0, (int) $row['wc_variation_id'] ) : 0;

            // WOO-D29: a reversed sale window (sale_end before sale_start)
            // made the tier permanently unsellable — is_on_sale() is false for
            // every timestamp — while the storefront still advertised it as
            // "opening" on sale_start. Swap rather than reject: both values
            // were deliberately entered, just in the wrong boxes.
            if ( $sale_start !== '' && $sale_end !== '' && $sale_start > $sale_end ) {
                [ $sale_start, $sale_end ] = [ $sale_end, $sale_start ];
            }

            // WOO-D28: a row can carry real, save-worthy data (a quota, an
            // active flag, a synced wc_variation_id) with no label/price/dates
            // at all — an admin who sets a quota but forgets the label used to
            // have the whole row vanish silently. Only a row with NONE of
            // these fields set is truly empty.
            if (
                $label === '' && $price_raw === '' && $sale_start === '' && $sale_end === ''
                && $quota === 0 && ! $active && $wc_variation_id === 0
            ) {
                continue;
            }

            $id = isset( $row['id'] ) ? \sanitize_key( (string) $row['id'] ) : '';
            if ( $id === '' || isset( $seen_ids[ $id ] ) ) {
                $id = $this->generate_id();
                // Guarantee uniqueness within this save pass.
                while ( isset( $seen_ids[ $id ] ) ) {
                    $id = $this->generate_id();
                }
            }
            $seen_ids[ $id ] = true;

            $tier = [
                'id'              => $id,
                'label'          => $label !== '' ? $label : \__( 'Registration', 'anchor-schema' ),
                'price'           => $this->to_price( $price_raw ),
                'quota'           => $quota,
                'sale_start'      => $sale_start,
                'sale_end'        => $sale_end,
                'active'          => $active,
                'wc_variation_id' => $wc_variation_id,
            ];

            $clean[] = $tier;
        }

        if ( empty( $clean ) ) {
            // No tiers authored — remove the meta so get() falls back to the
            // implicit primary (legacy `price` field stays the source of truth).
            \delete_post_meta( $event_id, self::META_KEY );
            return [ $this->implicit_primary( $event_id ) ];
        }

        // wp_slash(): the rows arrive already unslashed (both save paths
        // wp_unslash() $_POST['anchor_event_tickets'] before calling), and
        // update_post_meta() unslashes AGAIN — so a tier label reading
        // `General\Admission` would be stored as `GeneralAdmission`. See
        // Module::persist_event_authoring()'s docblock for the contract. The
        // RETURNED rows stay unslashed: they are the literal values.
        \update_post_meta( $event_id, self::META_KEY, \wp_slash( $clean ) );
        return $clean;
    }

    /**
     * Look up a single tier by its stable id.
     *
     * @param int    $event_id
     * @param string $tier_id
     * @return array|null
     */
    public function find( $event_id, $tier_id ) {
        $tier_id = (string) $tier_id;
        foreach ( $this->get( $event_id ) as $tier ) {
            if ( $tier['id'] === $tier_id ) {
                return $tier;
            }
        }
        return null;
    }

    /**
     * Whether a tier is currently on sale (within its optional window).
     *
     * No window = always on sale. Dates are Y-m-d; sale_end is treated as
     * end-of-day (inclusive). Uses WP local time.
     *
     * @param array $tier
     * @param int   $now Optional timestamp override (defaults to current_time).
     * @return bool
     */
    public function is_on_sale( array $tier, $now = 0 ) {
        return $this->sale_state( $tier, $now ) === 'open';
    }

    /**
     * The reason a tier is or isn't on sale right now (audit WOO-D4/WOO-D5).
     *
     * Compares Y-m-d strings — the way Registrations::capacity_decision()
     * compares its own registration-window dates — rather than parsing
     * `strtotime($date.' 00:00:00')` against `current_time('timestamp')`. Those
     * two are NOT the same clock: current_time('timestamp') is time() shifted
     * by the site's gmt_offset, while strtotime() parses in PHP's default
     * timezone (UTC, forced by WordPress) — so every boundary used to be off by
     * the site's UTC offset, opening or closing a window early. Comparing two
     * Y-m-d strings sidesteps the mismatch entirely: both sides are read in the
     * same site-local "what day is it" the admin used when they typed the date.
     *
     * @param array  $tier
     * @param int    $now Optional Unix timestamp override (tests only); its
     *                    Y-m-d in the site's timezone is what gets compared.
     * @return string 'before' (sale not open yet), 'after' (window closed), or
     *                'open' (no window, or inside it).
     */
    public function sale_state( array $tier, $now = 0 ) {
        $now   = (int) $now;
        $today = $now > 0 ? \wp_date( 'Y-m-d', $now ) : \current_time( 'Y-m-d' );

        $start = isset( $tier['sale_start'] ) ? (string) $tier['sale_start'] : '';
        $end   = isset( $tier['sale_end'] ) ? (string) $tier['sale_end'] : '';

        if ( $start !== '' && $today < $start ) {
            return 'before';
        }
        if ( $end !== '' && $today > $end ) {
            return 'after';
        }
        return 'open';
    }

    /**
     * The id of the first active tier, else the implicit-primary id.
     *
     * @param int $event_id
     * @return string
     */
    public function primary_id( $event_id ) {
        foreach ( $this->get( $event_id ) as $tier ) {
            if ( ! empty( $tier['active'] ) ) {
                return (string) $tier['id'];
            }
        }
        return self::PRIMARY_ID;
    }

    // ------------------------------------------------------------------ //
    // Internal helpers                                                   //
    // ------------------------------------------------------------------ //

    /**
     * Build the synthesized single implicit tier from the legacy event meta.
     *
     * @param int $event_id
     * @return array
     */
    private function implicit_primary( $event_id ) {
        $meta  = $this->module->get_meta( $event_id );
        $price = $this->to_price( $meta['price'] ?? '' );

        return [
            'id'              => self::PRIMARY_ID,
            'label'           => \__( 'Registration', 'anchor-schema' ),
            'price'           => $price,
            'quota'           => 0,
            'sale_start'      => '',
            'sale_end'        => '',
            'active'          => true,
            'wc_variation_id' => 0,
        ];
    }

    /**
     * Normalize a stored tier row into the canonical shape.
     *
     * @param array $row
     * @return array
     */
    private function normalize( array $row ) {
        $id = isset( $row['id'] ) ? \sanitize_key( (string) $row['id'] ) : '';
        if ( $id === '' ) {
            $id = self::PRIMARY_ID;
        }
        // NOTE (WOO-D40): normalize() deliberately does NOT substitute the
        // "Registration" default for a blank label the way save() does — a
        // blank label on a row that EXISTS is a real, visible authoring gap
        // (Roster::tier_label() reads it to distinguish "blank label" from
        // "the row is gone entirely", falling back to the raw tier id only
        // for the former). WOO-D40 is fixed at the write side instead: see
        // Product_Sync::write_back_variation_ids(), which used to round-trip
        // through save() (applying its label default) merely to update a
        // cached wc_variation_id.
        return [
            'id'              => $id,
            'label'           => isset( $row['label'] ) ? \sanitize_text_field( (string) $row['label'] ) : '',
            'price'           => $this->to_price( $row['price'] ?? '' ),
            'quota'           => isset( $row['quota'] ) ? \max( 0, (int) $row['quota'] ) : 0,
            'sale_start'      => isset( $row['sale_start'] ) ? $this->sanitize_date( (string) $row['sale_start'] ) : '',
            'sale_end'        => isset( $row['sale_end'] ) ? $this->sanitize_date( (string) $row['sale_end'] ) : '',
            'active'          => ! empty( $row['active'] ),
            'wc_variation_id' => isset( $row['wc_variation_id'] ) ? \max( 0, (int) $row['wc_variation_id'] ) : 0,
        ];
    }

    /**
     * Cast a price to float via wc_format_decimal when available, else (float).
     *
     * @param mixed $value
     * @return float
     */
    private function to_price( $value ) {
        if ( \function_exists( 'wc_format_decimal' ) ) {
            return (float) \wc_format_decimal( $value );
        }
        return (float) $value;
    }

    /**
     * Validate a Y-m-d date string; empty otherwise.
     *
     * @param string $value
     * @return string
     */
    private function sanitize_date( $value ) {
        $value = \trim( (string) $value );
        if ( $value === '' ) {
            return '';
        }
        $d = \DateTime::createFromFormat( 'Y-m-d', $value );
        if ( $d && $d->format( 'Y-m-d' ) === $value ) {
            return $value;
        }
        return '';
    }

    /**
     * Generate a short, stable, never-reused tier id.
     *
     * @return string
     */
    private function generate_id() {
        if ( \function_exists( 'wp_generate_uuid4' ) ) {
            return \substr( \str_replace( '-', '', \wp_generate_uuid4() ), 0, 12 );
        }
        return \substr( \md5( \uniqid( '', true ) ), 0, 12 );
    }
}
