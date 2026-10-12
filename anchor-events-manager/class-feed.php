<?php
/**
 * Read-only REST feed for the member app (spec 2026-10-12-events-feed-api).
 *
 * The app mirrors the site, so this class decides nothing new: which events
 * exist in a range is Module::range_query_args(); what a row says is
 * Event_Schema::for_event(); who may see a gated event is
 * Entitlements::meets_prerequisites(); registration state is
 * Module::bookability(). It only selects, maps and serves.
 *
 * @package Anchor\Events
 */

namespace Anchor\Events;

if ( ! \defined( 'ABSPATH' ) ) {
    exit;
}

class Feed {

    const SUMMARY_MAX = 300;

    /** @var Module */
    private $module;

    public function __construct( Module $module ) {
        $this->module = $module;
    }

    /* ── Selection ─────────────────────────────────────────────── */

    /**
     * Event IDs the current user may see in [from, to], one per actual date.
     *
     * @param int   $from_ts
     * @param int   $to_ts
     * @param int[] $term_ids event_category term IDs; [] = no filter.
     * @return int[]
     */
    public function visible_ids( $from_ts, $to_ts, array $term_ids ) {
        $term_ids = array_map( 'intval', $term_ids );
        $rows     = [];
        foreach ( $this->module->event_ids_in_range( $from_ts, $to_ts, true ) as $id ) {
            if ( $this->is_container( $id ) ) {
                continue;
            }
            if ( ! $this->module->entitlements->meets_prerequisites( $id, 0 ) ) {
                continue;
            }
            if ( $term_ids && ! array_intersect( $term_ids, \wp_list_pluck( $this->category_terms( $id ), 'term_id' ) ) ) {
                continue;
            }
            $rows[] = [ (int) \get_post_meta( $id, $this->module->meta_key( 'start_ts' ), true ), $id ];
        }
        usort( $rows, static function ( $a, $b ) {
            return [ $a[0], $a[1] ] <=> [ $b[0], $b[1] ];
        } );
        return array_column( $rows, 1 );
    }

    /**
     * A group parent (or an offering/recurring event not yet reconciled) is a
     * container, never a date — the feed's rows are its children.
     */
    private function is_container( $id ) {
        $occ = $this->module->occurrences;
        if ( $occ->is_group_child( $id ) ) {
            return false;
        }
        return $occ->is_group_parent( $id ) || in_array( $this->module->event_type( $id ), [ 'offering', 'recurring' ], true );
    }

    /**
     * The categories a row belongs to. Reconcile does not copy event_category
     * to children, so a child answers with its parent's terms.
     *
     * @return \WP_Term[]
     */
    public function category_terms( $event_id ) {
        $source = (int) $event_id;
        if ( $this->module->occurrences->is_group_child( $source ) ) {
            $parent = (int) $this->module->occurrences->parent_of( $source );
            if ( $parent > 0 ) {
                $source = $parent;
            }
        }
        $terms = \get_the_terms( $source, 'event_category' );
        return is_array( $terms ) ? array_values( $terms ) : [];
    }

    /**
     * The App categories setting, minus terms deleted since it was saved.
     *
     * @return int[]
     */
    public function app_term_ids() {
        $ids = array_map( 'intval', (array) ( $this->module->get_settings()['app_categories'] ?? [] ) );
        return array_values( array_filter( $ids, static function ( $id ) {
            return $id > 0 && \get_term( $id, 'event_category' ) instanceof \WP_Term;
        } ) );
    }

    public function is_restricted( $event_id ) {
        $meta = $this->module->get_meta( (int) $event_id );
        return ! empty( $meta['required_roles'] ) && is_array( $meta['required_roles'] );
    }

    /* ── Mapping ───────────────────────────────────────────────── */

    /**
     * One feed row, or null when the event has no usable schema node.
     *
     * @return array|null
     */
    public function row( $event_id ) {
        $id   = (int) $event_id;
        $node = $this->module->event_schema->for_event( $id );
        if ( empty( $node['startDate'] ) ) {
            return null;
        }
        $meta = $this->module->get_meta( $id );
        $mode = (string) $this->module->registration_mode( $id );
        list( $price, $currency ) = $this->price( $node['offers'] ?? [] );

        return [
            'id'           => $id,
            'parent_id'    => $this->module->occurrences->is_group_child( $id ) ? (int) $this->module->occurrences->parent_of( $id ) : 0,
            'title'        => $this->plain( $node['name'] ?? \get_the_title( $id ) ),
            'summary'      => $this->summary( $node['description'] ?? '' ),
            'url'          => (string) \get_permalink( $id ),
            'image'        => isset( $node['image'] ) && is_string( $node['image'] ) ? $node['image'] : null,
            'categories'   => array_map( static function ( $t ) {
                return [ 'slug' => (string) $t->slug, 'name' => \html_entity_decode( (string) $t->name, \ENT_QUOTES, 'UTF-8' ) ];
            }, $this->category_terms( $id ) ),
            'start'        => (string) $node['startDate'],
            'end'          => (string) ( $node['endDate'] ?? $node['startDate'] ),
            'all_day'      => ! empty( $meta['all_day'] ),
            'timezone'     => $this->module->event_timezone( $meta )->getName(),
            'location'     => $this->location( $node, (string) ( $meta['venue'] ?? '' ) ),
            'registration' => [
                'mode'         => [ 'wc' => 'paid', 'free' => 'free', 'external' => 'external' ][ $mode ] ?? 'free',
                'status'       => $this->map_status( (string) $this->module->bookability( $id ), $mode ),
                'price'        => $price,
                'currency'     => $currency,
                'register_url' => (string) \get_permalink( $id ),
            ],
            'audience'     => $this->is_restricted( $id ) ? 'restricted' : 'public',
        ];
    }

    /**
     * bookability() → the feed's status vocabulary (spec "Registration
     * vocabulary"). An external-signup event takes sign-ups elsewhere, so the
     * native switch/seat states say nothing about it — only "closed" does.
     */
    public function map_status( $bookability, $mode ) {
        if ( $mode === 'external' ) {
            return $bookability === 'closed' ? 'closed' : 'open';
        }
        $map = [ 'open' => 'open', 'waitlist' => 'waitlist', 'full' => 'sold_out', 'disabled' => 'unavailable' ];
        return $map[ $bookability ] ?? 'closed';
    }

    /** @return array{0:?string,1:?string} Lowest offer price + its currency. */
    private function price( $offers ) {
        if ( isset( $offers['@type'] ) ) {
            $offers = [ $offers ];
        }
        $best = null;
        foreach ( (array) $offers as $offer ) {
            if ( ! is_array( $offer ) || ! isset( $offer['price'] ) || ! is_numeric( $offer['price'] ) ) {
                continue;
            }
            if ( $best === null || (float) $offer['price'] < (float) $best['price'] ) {
                $best = $offer;
            }
        }
        if ( $best === null ) {
            return [ null, null ];
        }
        return [ number_format( (float) $best['price'], 2, '.', '' ), isset( $best['priceCurrency'] ) ? (string) $best['priceCurrency'] : null ];
    }

    /**
     * Venue/city/state/country from the node's Place; online from its VirtualLocation. Never the URL.
     * Event_Schema names the Place after the post title when there is no venue,
     * so the venue is only trusted when the event's own venue meta is set.
     */
    private function location( array $node, $venue_meta ) {
        $loc   = $node['location'] ?? [];
        $nodes = isset( $loc['@type'] ) ? [ $loc ] : (array) $loc;
        $out   = [ 'venue' => '', 'city' => '', 'state' => '', 'country' => '', 'online' => false ];
        foreach ( $nodes as $n ) {
            if ( ! is_array( $n ) ) {
                continue;
            }
            if ( ( $n['@type'] ?? '' ) === 'VirtualLocation' ) {
                $out['online'] = true;
            } elseif ( ( $n['@type'] ?? '' ) === 'Place' ) {
                $out['venue']   = trim( (string) $venue_meta ) === '' ? '' : $this->plain( $n['name'] ?? '' );
                $out['city']    = (string) ( $n['address']['addressLocality'] ?? '' );
                $out['state']   = (string) ( $n['address']['addressRegion'] ?? '' );
                $out['country'] = (string) ( $n['address']['addressCountry'] ?? '' );
            }
        }
        return $out;
    }

    private function plain( $text ) {
        return trim( \html_entity_decode( \wp_strip_all_tags( (string) $text ), \ENT_QUOTES, 'UTF-8' ) );
    }

    private function summary( $text ) {
        $text = $this->plain( $text );
        if ( mb_strlen( $text ) <= self::SUMMARY_MAX ) {
            return $text;
        }
        return rtrim( mb_substr( $text, 0, self::SUMMARY_MAX - 1 ) ) . '…';
    }
}
