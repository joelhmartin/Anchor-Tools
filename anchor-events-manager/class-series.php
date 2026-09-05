<?php
/**
 * Series — the event-session grouping taxonomy + archive (spec §3.3, §6).
 *
 * One responsibility: session grouping. Registers the non-hierarchical
 * `event_series` taxonomy on the event CPT and renders the series archive
 * (a landing page listing that series' session-events ordered by start, each
 * with date, a "from $X" price hint, and an availability state). Sessions are
 * separate events grouped by a shared series term — never product variations.
 *
 * WooCommerce is NOT required here: `wc_price` is used only when available and
 * fully-free series render "Free" with Woo absent.
 *
 * @package Anchor\Events
 */

namespace Anchor\Events;

if ( ! \defined( 'ABSPATH' ) ) {
    exit;
}

class Series {

    /** Taxonomy slug registered on the event CPT. */
    const TAXONOMY = 'event_series';

    /**
     * Term meta key flagging a term Occurrences::assign_series() minted on
     * its own, rather than one an admin created by hand (audit MODEL-D36).
     *
     * A group parent's "group-{parent_id}" term is created with no opt-out,
     * so a site with several offering/recurrence parents silently gets one
     * public, indexable archive URL per parent that nobody asked for. The
     * taxonomy stays public — this IS a real, tested feature (spec §3.3/§6)
     * for a genuinely curated series — but a term this flag marks gets a
     * `noindex` robots directive on its own archive (noindex_auto_series()),
     * so the auto-minted ones stop accumulating in search results while
     * still resolving correctly for anyone (or anything, like [events_list])
     * that already links to or queries them.
     */
    const AUTO_TERM_META_KEY = '_anchor_series_auto';

    /** @var Module */
    private $module;

    public function __construct( Module $module ) {
        $this->module = $module;
        \add_action( 'init', [ $this, 'register_taxonomy' ] );
        \add_filter( 'wp_robots', [ $this, 'noindex_auto_series' ] );
    }

    /**
     * Register the non-hierarchical `event_series` taxonomy on the event CPT.
     * Mirrors the timing/shape of the existing event taxonomies (registered on
     * `init`); public + rewrite `series` gives the archive a front-end URL.
     */
    public function register_taxonomy() {
        \register_taxonomy( self::TAXONOMY, Module::CPT, [
            'labels' => [
                'name'          => \__( 'Series', 'anchor-schema' ),
                'singular_name' => \__( 'Series', 'anchor-schema' ),
                'menu_name'     => \__( 'Series', 'anchor-schema' ),
                'all_items'     => \__( 'All Series', 'anchor-schema' ),
                'edit_item'     => \__( 'Edit Series', 'anchor-schema' ),
                'view_item'     => \__( 'View Series', 'anchor-schema' ),
                'add_new_item'  => \__( 'Add New Series', 'anchor-schema' ),
                'new_item_name' => \__( 'New Series Name', 'anchor-schema' ),
                'search_items'  => \__( 'Search Series', 'anchor-schema' ),
                'not_found'     => \__( 'No series found.', 'anchor-schema' ),
            ],
            'public'            => true,
            'hierarchical'      => false,
            'show_in_rest'      => true,
            'show_admin_column' => true,
            'rewrite'           => [ 'slug' => 'series' ],
        ] );
    }

    /**
     * Add a `noindex` robots directive when the current archive is an
     * auto-minted event_series term (MODEL-D36).
     *
     * `wp_robots` (WP 5.7+) rather than echoing a `<meta name="robots">` tag
     * directly: it composes with whatever else — an SEO plugin included —
     * also filters the same array, instead of risking a second, conflicting
     * robots tag on the page.
     *
     * @param array $robots
     * @return array
     */
    public function noindex_auto_series( $robots ) {
        if ( ! \is_tax( self::TAXONOMY ) ) {
            return $robots;
        }

        $term = \get_queried_object();
        if ( $term instanceof \WP_Term && \get_term_meta( $term->term_id, self::AUTO_TERM_META_KEY, true ) ) {
            $robots['noindex'] = true;
        }

        return $robots;
    }

    /**
     * Render the current series archive: list every session-event in the queried
     * `event_series` term ordered by start ascending, each with title link,
     * formatted start date, a "from $X" price hint (lowest active tier; "Free"
     * when no priced active tier), and an availability hint.
     *
     * @return string Escaped HTML; empty string when not on a series archive.
     */
    public function render_archive() {
        $term = \get_queried_object();
        if ( ! $term instanceof \WP_Term || $term->taxonomy !== self::TAXONOMY ) {
            return '';
        }

        // "Hide past events" is a site setting, and the CPT archive
        // (Module::filter_archive_query()) gates on it — a series archive is
        // the same kind of list, so it honours the same switch.
        $meta_query = [ $this->module->build_hide_clause() ];
        $settings   = $this->module->get_settings();
        if ( ! empty( $settings['archive_hide_past'] ) ) {
            $meta_query[] = $this->module->build_visibility_clause();
        }

        $query = new \WP_Query( [
            'post_type'      => Module::CPT,
            'post_status'    => 'publish',
            // Bounded: a series archive renders every row it fetches, so an
            // unbounded -1 is an unbounded render. 200 is far past any real
            // series and stops one runaway term from loading every event.
            'posts_per_page' => 200,
            'tax_query'      => [
                [
                    'taxonomy' => self::TAXONOMY,
                    'field'    => 'term_id',
                    'terms'    => (int) $term->term_id,
                ],
            ],
            // The same listing exclusions every other list query applies
            // (audit RENDER-D15): hidden events and soft-closed occurrences
            // never reach a public list. representative_id() also collapses
            // groups and drops closed children, but that runs per-row after
            // the fact; excluding them in the query is what makes this archive
            // agree with [events_list], the calendar and the CPT archive.
            'meta_query'     => $meta_query,
            'meta_key'       => $this->module->meta_key( 'start_ts' ),
            'orderby'        => 'meta_value_num',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        ] );

        \ob_start();
        ?>
        <div class="anchor-event-series">
            <header class="anchor-event-series__header">
                <h1 class="anchor-event-series__title"><?php echo \esc_html( $term->name ); ?></h1>
                <?php if ( $term->description !== '' ) : ?>
                    <div class="anchor-event-series__desc"><?php echo \wp_kses_post( \wpautop( $term->description ) ); ?></div>
                <?php endif; ?>
            </header>

            <?php
            // Task 2.4: a group parent's series term is shared with every one
            // of its LIVE children (Occurrences::assign_series()), so the raw
            // query above can contain a parent + N of its own children (each
            // representing the same offering/recurrence), plus any child that
            // was soft-closed after being tagged (its term membership is
            // never retroactively cleared — see representative_id()). Collapse
            // each group down to ONE row (the parent, rendered as a "choose a
            // date" entry) and drop soft-closed children entirely, so a
            // visitor sees one row per distinct thing-to-book rather than a
            // wall of duplicate/stale dates.
            $rows = '';
            $seen = [];
            while ( $query->have_posts() ) {
                $query->the_post();
                $rep_id = $this->representative_id( (int) \get_the_ID() );
                if ( $rep_id <= 0 || isset( $seen[ $rep_id ] ) ) {
                    continue;
                }
                $row = $this->render_session_row( $rep_id );
                if ( $row === '' ) {
                    // A group parent with zero live children — nothing to book.
                    continue;
                }
                $seen[ $rep_id ] = true;
                $rows           .= $row;
            }
            \wp_reset_postdata();
            ?>
            <?php if ( $rows !== '' ) : ?>
                <ul class="anchor-event-series__list">
                    <?php echo $rows; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped fragments in render_session_row()/render_group_row(). ?>
                </ul>
            <?php else : ?>
                <p class="anchor-event-series__empty"><?php echo \esc_html__( 'No sessions found in this series.', 'anchor-schema' ); ?></p>
            <?php endif; ?>
        </div>
        <?php
        return \ob_get_clean();
    }

    /**
     * Resolve the archive row a queried post should be represented by (Task
     * 2.4). A plain (non-grouped) event represents itself. A group CHILD is
     * NEVER rendered as its own row — it's represented by its parent's single
     * "choose a date" row instead, EXCLUDING soft-closed children entirely: a
     * closed child is checked purely via the engine's own
     * children($parent_id, false) (live-only) set, never a meta-value read
     * (Task 2.1 review note — correction, review round: a closed child's own
     * registration_enabled meta actually reads back `false`, its schema
     * default, not `true` as a previous version of this comment claimed; it's
     * still not read directly here because the engine's own live-children set
     * is the authoritative definition of "closed", not a meta value that
     * merely happens to agree with it). A group PARENT represents itself.
     *
     * @param int $event_id
     * @return int Representative post id to render (0 = render nothing — a
     *             soft-closed child that isn't otherwise live).
     */
    private function representative_id( $event_id ) {
        $event_id = (int) $event_id;
        $occ      = $this->module->occurrences;
        if ( ! $occ || ! $occ->is_group_child( $event_id ) ) {
            return $event_id;
        }
        $parent_id = $occ->parent_of( $event_id );
        if ( $parent_id <= 0 ) {
            // No live parent (trashed or deleted — MODEL-D22). The child is on
            // its own, so it represents itself — unless it is soft-closed, which
            // is the same exclusion the children() check below applies and the
            // one thing the archive must still honour without a parent.
            return $occ->is_closed( $event_id ) ? 0 : $event_id;
        }
        if ( ! \in_array( $event_id, $occ->children( $parent_id, false ), true ) ) {
            return 0; // Soft-closed — excluded from the archive entirely.
        }
        return $parent_id;
    }

    /**
     * Render one session row: title link, start date, "from $X" / "Free", and an
     * availability hint. All output escaped. A group PARENT renders as a
     * "choose a date" summary row instead (Task 2.4) — see render_group_row().
     *
     * MODEL-D42: the hint is Module::choose_date_availability_hint(), the same
     * renderer the choose-a-date picker uses, rather than a local two-line
     * rule. The old private availability_hint() read remaining_capacity() and
     * the waitlist flag only — blind to sold_out, registration_enabled, the
     * registration window and the past — so the archive printed "Open" for
     * occurrences the picker on the next screen called "Sold out".
     *
     * @param int $event_id
     * @return string Empty string for a group parent with zero live children
     *                (nothing to book, so no row).
     */
    private function render_session_row( $event_id ) {
        $event_id = (int) $event_id;
        $occ      = $this->module->occurrences;
        if ( $occ && $occ->is_group_parent( $event_id ) ) {
            return $this->render_group_row( $event_id );
        }
        $meta = $this->module->get_meta( $event_id );

        $date_label = '';
        if ( ! empty( $meta['start_date'] ) ) {
            $date_label = \date_i18n( 'M j, Y', \strtotime( $meta['start_date'] ) );
        }

        \ob_start();
        ?>
        <li class="anchor-event-series__item">
            <a class="anchor-event-series__link" href="<?php echo \esc_url( \get_permalink( $event_id ) ); ?>">
                <?php echo \esc_html( \get_the_title( $event_id ) ); ?>
            </a>
            <?php if ( $date_label !== '' ) : ?>
                <span class="anchor-event-series__date"><?php echo \esc_html( $date_label ); ?></span>
            <?php endif; ?>
            <span class="anchor-event-series__price"><?php echo $this->price_hint( $event_id ); ?></span>
            <span class="anchor-event-series__availability"><?php echo \esc_html( $this->module->choose_date_availability_hint( $event_id, $meta ) ); ?></span>
        </li>
        <?php
        return \ob_get_clean();
    }

    /**
     * "Choose a date" summary row for a group PARENT in the series archive
     * (Task 2.4): title link to the parent's own page (its choose-a-date
     * picker — see Module::render_choose_date_list()), a date-range label
     * spanning the dates it still OFFERS (earliest–latest), the parent's own
     * "from $X"/"Free" price hint (ticket tiers are copied parent->child so
     * the parent's own copy is representative), and an "N dates available"
     * count in place of a single-date availability hint.
     *
     * "Available" counts the BOOKABLE dates, not merely the upcoming ones:
     * two sold-out dates in November are not two dates a visitor can have,
     * and saying so here contradicted the picker on the very next screen,
     * which called both of them "Sold out". With none bookable the count is
     * replaced by the container's own availability hint — Module::
     * choose_date_availability_hint(), the same renderer every other row on
     * this archive uses (MODEL-D42) — which reads the parent's bookability
     * and so says "Sold out" / "Registration closed" / "Date passed" rather
     * than "0 dates available". The RANGE stays on the upcoming dates: it
     * answers "when", which is a different question from "how many are left".
     *
     * The range used to span children($parent_id, false) — the raw live set,
     * which includes dates that have been and gone (audit MODEL-D4), so a
     * course running in November was advertised as "Sep 2026 – Dec 2026" off
     * a September date nobody could still attend, and counted it as
     * "available". It reads Occurrences::upcoming_children() instead, and
     * only falls back to the raw live set when NOTHING is upcoming: a site
     * with "Archive past events" switched off is asking for finished groups
     * on purpose, and their row should say when they ran rather than nothing.
     *
     * @param int $parent_id
     * @return string Empty string when the parent currently has zero live
     *                children — nothing to book, so no row at all.
     */
    private function render_group_row( $parent_id ) {
        $parent_id = (int) $parent_id;
        $occ       = $this->module->occurrences;
        $live      = $occ->children( $parent_id, false );
        if ( empty( $live ) ) {
            return '';
        }

        $offered = $occ->upcoming_children( $parent_id );
        if ( empty( $offered ) ) {
            $offered = $live;
        }

        $first_meta = $this->module->get_meta( $offered[0] );
        $last_meta  = $this->module->get_meta( $offered[ \count( $offered ) - 1 ] );

        $date_label = '';
        if ( ! empty( $first_meta['start_date'] ) ) {
            $date_label = \date_i18n( 'M j, Y', \strtotime( $first_meta['start_date'] ) );
            if ( ! empty( $last_meta['start_date'] ) && $last_meta['start_date'] !== $first_meta['start_date'] ) {
                $date_label .= ' – ' . \date_i18n( 'M j, Y', \strtotime( $last_meta['start_date'] ) );
            }
        }

        $bookable = \count( $occ->bookable_children( $parent_id ) );
        $summary  = $bookable > 0
            ? \sprintf(
                /* translators: %d: number of bookable dates available for this group. */
                \_n( '%d date available', '%d dates available', $bookable, 'anchor-schema' ),
                $bookable
            )
            : $this->module->choose_date_availability_hint( $parent_id, $this->module->get_meta( $parent_id ) );

        \ob_start();
        ?>
        <li class="anchor-event-series__item anchor-event-series__item--group">
            <a class="anchor-event-series__link" href="<?php echo \esc_url( \get_permalink( $parent_id ) ); ?>">
                <?php echo \esc_html( \get_the_title( $parent_id ) ); ?>
            </a>
            <?php if ( $date_label !== '' ) : ?>
                <span class="anchor-event-series__date"><?php echo \esc_html( $date_label ); ?></span>
            <?php endif; ?>
            <span class="anchor-event-series__price"><?php echo $this->price_hint( $parent_id ); ?></span>
            <span class="anchor-event-series__availability"><?php echo \esc_html( $summary ); ?></span>
        </li>
        <?php
        return \ob_get_clean();
    }

    /**
     * "from <lowest active tier price>" for an event; "Free" when no active tier
     * carries a price. Uses `wc_price` when WooCommerce is active, otherwise a
     * plain escaped number. Returns escaped HTML (wc_price emits safe markup).
     *
     * @param int $event_id
     * @return string
     */
    private function price_hint( $event_id ) {
        $prices = [];
        foreach ( $this->module->ticket_types->get( (int) $event_id ) as $tier ) {
            if ( empty( $tier['active'] ) ) {
                continue;
            }
            $price = (float) ( $tier['price'] ?? 0 );
            if ( $price > 0 ) {
                $prices[] = $price;
            }
        }

        if ( empty( $prices ) ) {
            return \esc_html__( 'Free', 'anchor-schema' );
        }

        $min = \min( $prices );
        if ( \function_exists( 'wc_price' ) ) {
            /* translators: %s: formatted lowest ticket price. */
            return \sprintf( \esc_html__( 'from %s', 'anchor-schema' ), \wc_price( $min ) );
        }

        /* translators: %s: formatted lowest ticket price. */
        return \esc_html( \sprintf( \__( 'from %s', 'anchor-schema' ), \number_format_i18n( $min, 2 ) ) );
    }

}
