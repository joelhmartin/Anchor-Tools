<?php
namespace Anchor\Events;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * WooCommerce integration for Anchor Events (Phase 1).
 *
 * Loaded ONLY when WooCommerce is active (gated by `class_exists('WooCommerce')`
 * in Module::__construct). This phase implements:
 *  - product/variation → event link meta + admin product-data UI,
 *  - the resolver (product/variation → event id), and
 *  - the read-only event-side mirror (`_anchor_event_linked_products`) with its
 *    lifecycle (rebuild on link/toggle/save/delete/trash).
 *
 * It does NOT register the `anchor_events_registration_form` filter — the free
 * form swap + checkout capture + seat creation land together in Phase 2
 * (spec finding #12) so a linked product never loses the free form before seats
 * can be created. See filter_registration_form() below.
 */
class WooCommerce {

    /** Master toggle on the parent product ('1' / ''). */
    const META_ENABLED  = '_anchor_evt_link_enabled';
    /** Target event id on product (simple) or variation (per-session). */
    const META_EVENT_ID = '_anchor_evt_link_event_id';

    /** @var Module */
    private $module;

    /** @var Registrations */
    private $registrations;

    /**
     * Deferred mirror-rebuild buffer, keyed by post id. Used to capture the set
     * of event ids a product/variation was linked to BEFORE the link write or
     * deletion, so the rebuild (which queries live product meta) runs against
     * the correct {old}∪{new} event set afterwards (spec §5.4 mirror integrity).
     *
     * @var array<int,int[]>
     */
    private $deferred = [];

    public function __construct( Module $module, Registrations $registrations ) {
        $this->module        = $module;
        $this->registrations = $registrations;

        // Link meta registration (product + variation).
        \add_action( 'init', [ $this, 'register_link_meta' ] );

        // Admin product-data linking UI.
        \add_filter( 'woocommerce_product_data_tabs', [ $this, 'add_product_data_tab' ] );
        \add_action( 'woocommerce_product_data_panels', [ $this, 'render_product_data_panel' ] );
        \add_action( 'woocommerce_product_after_variable_attributes', [ $this, 'render_variation_fields' ], 10, 3 );
        \add_action( 'woocommerce_admin_process_product_object', [ $this, 'save_product_link' ] );
        \add_action( 'woocommerce_save_product_variation', [ $this, 'save_variation_link' ], 10, 2 );
        \add_action( 'admin_footer', [ $this, 'print_admin_panel_js' ] );

        // Mirror lifecycle — product save (after WC persists the new meta).
        \add_action( 'woocommerce_update_product', [ $this, 'on_product_saved' ], 20, 1 );
        \add_action( 'woocommerce_new_product', [ $this, 'on_product_saved' ], 20, 1 );

        // Mirror lifecycle — product / variation trash + delete. Capture the
        // linked event ids while the post meta still exists, then rebuild after.
        \add_action( 'wp_trash_post', [ $this, 'capture_linked_events' ] );
        \add_action( 'trashed_post', [ $this, 'rebuild_deferred' ] );
        \add_action( 'before_delete_post', [ $this, 'capture_linked_events' ] );
        \add_action( 'deleted_post', [ $this, 'rebuild_deferred' ] );
        \add_action( 'woocommerce_delete_product_variation', [ $this, 'capture_linked_events' ] );
    }

    /* ---------------------------------------------------------------------
     * Meta registration
     * ------------------------------------------------------------------- */

    public function register_link_meta() {
        $auth = static function () {
            return \current_user_can( 'edit_products' );
        };
        foreach ( [ 'product', 'product_variation' ] as $ptype ) {
            \register_post_meta( $ptype, self::META_ENABLED, [
                'type'          => 'boolean',
                'single'        => true,
                'show_in_rest'  => false,
                'auth_callback' => $auth,
            ] );
            \register_post_meta( $ptype, self::META_EVENT_ID, [
                'type'          => 'integer',
                'single'        => true,
                'show_in_rest'  => false,
                'auth_callback' => $auth,
            ] );
        }
    }

    /* ---------------------------------------------------------------------
     * Resolver (spec §5.2). All return a validated event id or 0.
     * ------------------------------------------------------------------- */

    /**
     * Resolve the event a cart/order line registers for.
     *
     * @param int $product_id   Parent product id.
     * @param int $variation_id Variation id (0 for simple products).
     */
    public function event_for_line( $product_id, $variation_id = 0 ) {
        $product_id   = (int) $product_id;
        $variation_id = (int) $variation_id;

        // Master toggle lives on the parent product; off ⇒ all linking ignored.
        if ( ! $this->product_link_enabled( $product_id ) ) {
            return 0;
        }

        $event_id = $variation_id > 0
            ? (int) \get_post_meta( $variation_id, self::META_EVENT_ID, true )
            : (int) \get_post_meta( $product_id, self::META_EVENT_ID, true );

        return $this->validate_event_id( $event_id );
    }

    public function event_for_product( $product_id ) {
        return $this->event_for_line( (int) $product_id, 0 );
    }

    public function event_for_variation( $variation_id ) {
        $variation_id = (int) $variation_id;
        $parent_id    = (int) \wp_get_post_parent_id( $variation_id );
        if ( $parent_id <= 0 ) {
            return 0;
        }
        return $this->event_for_line( $parent_id, $variation_id );
    }

    /**
     * Reverse lookup: products/variations registering for an event.
     *
     * @return array<int,array{product_id:int,variation_id:int}>
     */
    public function products_for_event( $event_id ) {
        $event_id = (int) $event_id;
        if ( $event_id <= 0 ) {
            return [];
        }

        $out = [];

        // Simple-product links: parent toggle on AND parent event id matches.
        $products = \get_posts( [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => [
                'relation' => 'AND',
                [ 'key' => self::META_ENABLED, 'value' => '1' ],
                [ 'key' => self::META_EVENT_ID, 'value' => $event_id, 'type' => 'NUMERIC' ],
            ],
        ] );
        foreach ( $products as $pid ) {
            $out[] = [ 'product_id' => (int) $pid, 'variation_id' => 0 ];
        }

        // Per-variation links: variation event id matches AND parent toggle on.
        $variations = \get_posts( [
            'post_type'      => 'product_variation',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => [
                [ 'key' => self::META_EVENT_ID, 'value' => $event_id, 'type' => 'NUMERIC' ],
            ],
        ] );
        foreach ( $variations as $vid ) {
            $parent = (int) \wp_get_post_parent_id( $vid );
            if ( $parent > 0 && $this->product_link_enabled( $parent ) ) {
                $out[] = [ 'product_id' => $parent, 'variation_id' => (int) $vid ];
            }
        }

        return $out;
    }

    /** O(1) front-end check using the denormalized mirror. */
    public function event_is_linked( $event_id ) {
        $mirror = \get_post_meta( (int) $event_id, $this->module->meta_key( 'linked_products' ), true );
        return \is_array( $mirror ) && ! empty( $mirror );
    }

    private function product_link_enabled( $product_id ) {
        return (bool) \get_post_meta( (int) $product_id, self::META_ENABLED, true );
    }

    /** Validate that an id is a non-trashed event post; else 0. */
    private function validate_event_id( $event_id ) {
        $event_id = (int) $event_id;
        if ( $event_id <= 0 ) {
            return 0;
        }
        if ( \get_post_type( $event_id ) !== Module::CPT ) {
            return 0;
        }
        if ( \get_post_status( $event_id ) === 'trash' ) {
            return 0;
        }
        return $event_id;
    }

    /* ---------------------------------------------------------------------
     * Admin product-data UI
     * ------------------------------------------------------------------- */

    public function add_product_data_tab( $tabs ) {
        $tabs['anchor_event'] = [
            'label'    => \__( 'Event Registration', 'anchor-schema' ),
            'target'   => 'anchor_evt_link_data',
            'class'    => [ 'show_if_simple', 'show_if_variable' ],
            'priority' => 65,
        ];
        return $tabs;
    }

    public function render_product_data_panel() {
        global $post;
        $product_id = $post ? (int) $post->ID : 0;
        $enabled    = $product_id ? (bool) \get_post_meta( $product_id, self::META_ENABLED, true ) : false;
        $selected   = $product_id ? (int) \get_post_meta( $product_id, self::META_EVENT_ID, true ) : 0;

        echo '<div id="anchor_evt_link_data" class="panel woocommerce_options_panel">';
        \wp_nonce_field( 'anchor_evt_link_save', 'anchor_evt_link_nonce' );

        \woocommerce_wp_checkbox( [
            'id'          => self::META_ENABLED,
            'label'       => \__( 'Register buyer for an event', 'anchor-schema' ),
            'description' => \__( 'When enabled, purchasing this product registers the buyer for the selected event.', 'anchor-schema' ),
            'value'       => $enabled ? 'yes' : 'no',
        ] );

        // Simple-product event selector.
        echo '<p class="form-field show_if_simple anchor-evt-simple-event">';
        echo '<label for="' . \esc_attr( self::META_EVENT_ID ) . '">' . \esc_html__( 'Event', 'anchor-schema' ) . '</label>';
        echo '<select id="' . \esc_attr( self::META_EVENT_ID ) . '" name="' . \esc_attr( self::META_EVENT_ID ) . '" class="select short">';
        echo '<option value="0">' . \esc_html__( '— Select an event —', 'anchor-schema' ) . '</option>';
        foreach ( $this->event_options() as $eid => $title ) {
            echo '<option value="' . \esc_attr( $eid ) . '" ' . \selected( $selected, $eid, false ) . '>' . \esc_html( $title ) . '</option>';
        }
        echo '</select>';
        echo '</p>';

        echo '<p class="form-field show_if_variable"><em>'
            . \esc_html__( 'For variable products, choose the event for each variation on the Variations tab.', 'anchor-schema' )
            . '</em></p>';

        echo '<p class="form-field"><em>'
            . \esc_html__( 'Recommended: disable "Manage stock" on this product so event capacity is the single source of truth for availability.', 'anchor-schema' )
            . '</em></p>';

        echo '</div>';
    }

    public function render_variation_fields( $loop, $variation_data, $variation ) {
        $variation_id = (int) $variation->ID;
        $selected     = (int) \get_post_meta( $variation_id, self::META_EVENT_ID, true );

        echo '<div class="form-row form-row-full anchor-evt-variation-event">';
        echo '<label>' . \esc_html__( 'Event Registration', 'anchor-schema' ) . '</label>';
        echo '<select name="' . \esc_attr( self::META_EVENT_ID . '[' . (int) $loop . ']' ) . '">';
        echo '<option value="0">' . \esc_html__( '— No event —', 'anchor-schema' ) . '</option>';
        foreach ( $this->event_options() as $eid => $title ) {
            echo '<option value="' . \esc_attr( $eid ) . '" ' . \selected( $selected, $eid, false ) . '>' . \esc_html( $title ) . '</option>';
        }
        echo '</select>';
        echo '</div>';
    }

    /** Minimal show/hide for the simple-product select based on the toggle. */
    public function print_admin_panel_js() {
        $screen = \function_exists( 'get_current_screen' ) ? \get_current_screen() : null;
        if ( ! $screen || $screen->post_type !== 'product' ) {
            return;
        }
        ?>
        <script>
        (function($){
            function toggle(){
                var on = $('#<?php echo \esc_js( self::META_ENABLED ); ?>').is(':checked');
                $('.anchor-evt-simple-event').toggle(on);
            }
            $(document).on('change', '#<?php echo \esc_js( self::META_ENABLED ); ?>', toggle);
            $(function(){ toggle(); });
        })(jQuery);
        </script>
        <?php
    }

    /**
     * @return array<int,string> event id => title (published events).
     */
    private function event_options() {
        $events = \get_posts( [
            'post_type'      => Module::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        ] );
        $out = [];
        foreach ( $events as $event ) {
            $out[ (int) $event->ID ] = $event->post_title;
        }
        return $out;
    }

    /* ---------------------------------------------------------------------
     * Saving link meta
     * ------------------------------------------------------------------- */

    /**
     * Save simple-product link meta. Fires on `woocommerce_admin_process_product_object`;
     * we write to the product object only (WC saves it afterwards — do NOT call
     * $product->save()). The mirror is rebuilt on `woocommerce_update_product`.
     *
     * @param \WC_Product $product
     */
    public function save_product_link( $product ) {
        if (
            ! isset( $_POST['anchor_evt_link_nonce'] )
            || ! \wp_verify_nonce( \sanitize_text_field( \wp_unslash( $_POST['anchor_evt_link_nonce'] ) ), 'anchor_evt_link_save' )
        ) {
            return;
        }
        if ( ! \current_user_can( 'edit_products' ) ) {
            return;
        }

        $product_id = (int) $product->get_id();

        // Capture OLD linked event ids BEFORE the new value is persisted, so the
        // mirror rebuild covers de-linked events too (spec §5.4).
        $old_events = $this->product_link_event_ids( $product_id );

        $enabled  = ( isset( $_POST[ self::META_ENABLED ] ) && 'yes' === $_POST[ self::META_ENABLED ] );
        $event_id = isset( $_POST[ self::META_EVENT_ID ] )
            ? $this->validate_event_id( (int) \wp_unslash( $_POST[ self::META_EVENT_ID ] ) )
            : 0;

        $product->update_meta_data( self::META_ENABLED, $enabled ? '1' : '' );
        $product->update_meta_data( self::META_EVENT_ID, $event_id );

        $new_events = ( $enabled && $event_id ) ? [ $event_id ] : [];
        $this->deferred[ $product_id ] = $this->normalize_ids( \array_merge( $old_events, $new_events ) );
    }

    /**
     * Save a single variation's link meta. Fires on `woocommerce_save_product_variation`
     * (WC verifies the nonce). The variation meta is written immediately so the
     * mirror can be rebuilt right away for {old}∪{new} event ids.
     *
     * @param int $variation_id
     * @param int $i Loop index in the posted variation arrays.
     */
    public function save_variation_link( $variation_id, $i ) {
        if ( ! \current_user_can( 'edit_products' ) ) {
            return;
        }
        $variation_id = (int) $variation_id;
        $i            = (int) $i;

        $old_event = (int) \get_post_meta( $variation_id, self::META_EVENT_ID, true );

        $posted = isset( $_POST[ self::META_EVENT_ID ][ $i ] ) ? (int) \wp_unslash( $_POST[ self::META_EVENT_ID ][ $i ] ) : 0;
        $new_event = $this->validate_event_id( $posted );

        \update_post_meta( $variation_id, self::META_EVENT_ID, $new_event );

        foreach ( $this->normalize_ids( [ $old_event, $new_event ] ) as $eid ) {
            $this->rebuild_event_mirror( $eid );
        }
    }

    /* ---------------------------------------------------------------------
     * Mirror maintenance
     * ------------------------------------------------------------------- */

    /**
     * Rebuild the read-only event mirror from a live product-meta query.
     *
     * @param int $event_id
     */
    public function rebuild_event_mirror( $event_id ) {
        $event_id = (int) $event_id;
        if ( $event_id <= 0 || \get_post_type( $event_id ) !== Module::CPT ) {
            return;
        }
        \update_post_meta( $event_id, $this->module->meta_key( 'linked_products' ), $this->products_for_event( $event_id ) );
        $this->module->clear_caches();
    }

    /**
     * After WC persists a product save, rebuild the {old}∪{new} event mirrors.
     * Hook signature: woocommerce_update_product / woocommerce_new_product pass
     * ($product_id, $product) — we only need the id.
     *
     * @param int $product_id
     */
    public function on_product_saved( $product_id ) {
        $product_id = (int) $product_id;
        $events     = isset( $this->deferred[ $product_id ] ) ? $this->deferred[ $product_id ] : [];
        // Merge in the now-saved live state (covers programmatic saves with no $_POST).
        $events = $this->normalize_ids( \array_merge( $events, $this->product_link_event_ids( $product_id ) ) );
        foreach ( $events as $eid ) {
            $this->rebuild_event_mirror( $eid );
        }
        unset( $this->deferred[ $product_id ] );
    }

    /**
     * Capture the event ids a product/variation links to before it is
     * trashed/deleted (its meta is still readable here). Rebuild happens in
     * rebuild_deferred() once the post is gone, so products_for_event() no
     * longer returns it.
     *
     * @param int $post_id
     */
    public function capture_linked_events( $post_id ) {
        $post_id = (int) $post_id;
        $type    = \get_post_type( $post_id );

        if ( 'product' === $type ) {
            $events = $this->product_link_event_ids( $post_id );
        } elseif ( 'product_variation' === $type ) {
            $events = [ (int) \get_post_meta( $post_id, self::META_EVENT_ID, true ) ];
        } else {
            return;
        }

        $events = $this->normalize_ids( $events );
        if ( ! empty( $events ) ) {
            $this->deferred[ $post_id ] = $events;
        }
    }

    /**
     * Rebuild mirrors captured by capture_linked_events() once the post is gone.
     *
     * @param int $post_id
     */
    public function rebuild_deferred( $post_id ) {
        $post_id = (int) $post_id;
        if ( empty( $this->deferred[ $post_id ] ) ) {
            return;
        }
        foreach ( $this->deferred[ $post_id ] as $eid ) {
            $this->rebuild_event_mirror( $eid );
        }
        unset( $this->deferred[ $post_id ] );
    }

    /**
     * All event ids a product currently links to (parent simple link + every
     * variation link). Used for mirror rebuild bookkeeping.
     *
     * @return int[]
     */
    private function product_link_event_ids( $product_id ) {
        $product_id = (int) $product_id;
        $events     = [];

        $parent_event = (int) \get_post_meta( $product_id, self::META_EVENT_ID, true );
        if ( $parent_event ) {
            $events[] = $parent_event;
        }

        $variations = \get_posts( [
            'post_type'      => 'product_variation',
            'post_status'    => [ 'publish', 'private', 'draft' ],
            'post_parent'    => $product_id,
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ] );
        foreach ( $variations as $vid ) {
            $ve = (int) \get_post_meta( $vid, self::META_EVENT_ID, true );
            if ( $ve ) {
                $events[] = $ve;
            }
        }

        return $this->normalize_ids( $events );
    }

    /** @return int[] unique, positive integer ids. */
    private function normalize_ids( array $ids ) {
        return \array_values( \array_unique( \array_filter( \array_map( 'intval', $ids ) ) ) );
    }

    /* ---------------------------------------------------------------------
     * Registration-form swap (DEFINED, NOT hooked — Phase 2)
     * ------------------------------------------------------------------- */

    /**
     * Swap the free `[event_registration]` form for a WooCommerce "Register"
     * button on linked events.
     *
     * DEFINED in Phase 1 but intentionally NOT registered on the
     * `anchor_events_registration_form` filter yet (spec finding #12).
     * Activating the swap before checkout capture + seat creation exist (Phase 2)
     * would let a linked-product purchase create an order with no seat records
     * and no attendee data — silent data loss. The free form therefore stays
     * live on EVERY event this phase. The add_filter() call lands in Phase 2.
     *
     * @param string $html    Current form HTML ('' = render the free form).
     * @param int    $post_id Event post id.
     * @param array  $meta    Event meta.
     * @return string
     */
    public function filter_registration_form( $html, $post_id, $meta ) {
        // TODO (Phase 2): render the "Register — $price" / "Sold out" /
        // "Join waitlist — $price" add-to-cart button for linked events.
        return $html;
    }
}
