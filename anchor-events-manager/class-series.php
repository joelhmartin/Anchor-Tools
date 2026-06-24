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

    /** @var Module */
    private $module;

    public function __construct( Module $module ) {
        $this->module = $module;
        \add_action( 'init', [ $this, 'register_taxonomy' ] );
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

        $query = new \WP_Query( [
            'post_type'      => Module::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'tax_query'      => [
                [
                    'taxonomy' => self::TAXONOMY,
                    'field'    => 'term_id',
                    'terms'    => (int) $term->term_id,
                ],
            ],
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

            <?php if ( $query->have_posts() ) : ?>
                <ul class="anchor-event-series__list">
                    <?php
                    while ( $query->have_posts() ) {
                        $query->the_post();
                        echo $this->render_session_row( (int) \get_the_ID() );
                    }
                    \wp_reset_postdata();
                    ?>
                </ul>
            <?php else : ?>
                <p class="anchor-event-series__empty"><?php echo \esc_html__( 'No sessions found in this series.', 'anchor-schema' ); ?></p>
            <?php endif; ?>
        </div>
        <?php
        return \ob_get_clean();
    }

    /**
     * Render one session row: title link, start date, "from $X" / "Free", and an
     * availability hint. All output escaped.
     *
     * @param int $event_id
     * @return string
     */
    private function render_session_row( $event_id ) {
        $event_id = (int) $event_id;
        $meta     = $this->module->get_meta( $event_id );

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
            <span class="anchor-event-series__availability"><?php echo \esc_html( $this->availability_hint( $event_id, $meta ) ); ?></span>
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

    /**
     * Short availability hint: "Sold out" when the event total is full and the
     * waitlist is off, otherwise "Open". Capacity is read through the single
     * seat-layer authority (never Woo stock).
     *
     * @param int   $event_id
     * @param array $meta     Event meta (capacity, waitlist).
     * @return string
     */
    private function availability_hint( $event_id, array $meta ) {
        $capacity  = (int) ( $meta['capacity'] ?? 0 );
        $remaining = $this->module->registrations->remaining_capacity( (int) $event_id, $capacity );

        if ( $remaining === 0 && empty( $meta['waitlist'] ) ) {
            return \__( 'Sold out', 'anchor-schema' );
        }

        return \__( 'Open', 'anchor-schema' );
    }
}
