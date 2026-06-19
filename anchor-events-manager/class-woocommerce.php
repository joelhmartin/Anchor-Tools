<?php
namespace Anchor\Events;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * WooCommerce integration for Anchor Events (Phases 1–2).
 *
 * Loaded ONLY when WooCommerce is active (gated by `class_exists('WooCommerce')`
 * in Module::__construct).
 *
 * Phase 1:
 *  - product/variation → event link meta + admin product-data UI,
 *  - the resolver (product/variation → event id), and
 *  - the read-only event-side mirror (`_anchor_event_linked_products`) with its
 *    lifecycle (rebuild on link/toggle/save/delete/trash).
 *
 * Phase 2 (this phase):
 *  - ACTIVATES the `anchor_events_registration_form` filter so linked events show
 *    a "Register — $price" button instead of the free form (spec finding #12 —
 *    safe now because seat creation lands in the same phase),
 *  - per-seat attendee capture on the classic checkout, server-side validation +
 *    capacity re-check, and a Store-API/block-checkout fail-closed guard,
 *  - persistence of attendee data to the order line item, and
 *  - seat creation on `processing`/`completed` via the single reconcile entry
 *    point (cancellations/refunds/on-hold/resync are Phases 3–5).
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

        /* -----------------------------------------------------------------
         * Phase 2 — form swap + checkout capture + seat creation
         * --------------------------------------------------------------- */

        // ACTIVATE the form swap (spec finding #12) — linked events now show the
        // "Register — $price" button; unlinked events keep the free form.
        \add_filter( 'anchor_events_registration_form', [ $this, 'filter_registration_form' ], 10, 3 );

        // Capacity enforcement layer 1 — add-to-cart / purchasability gate.
        \add_filter( 'woocommerce_is_purchasable', [ $this, 'filter_is_purchasable' ], 10, 2 );
        \add_filter( 'woocommerce_variation_is_purchasable', [ $this, 'filter_is_purchasable' ], 10, 2 );
        \add_filter( 'woocommerce_add_to_cart_validation', [ $this, 'validate_add_to_cart' ], 10, 4 );
        \add_action( 'woocommerce_check_cart_items', [ $this, 'notice_over_capacity_cart_items' ] );

        // Checkout attendee capture (classic shortcode checkout).
        \add_action( 'woocommerce_checkout_after_customer_details', [ $this, 'render_checkout_attendee_fields' ], 10 );
        \add_action( 'woocommerce_after_checkout_validation', [ $this, 'validate_checkout_attendees' ], 10, 2 );
        \add_action( 'woocommerce_checkout_create_order_line_item', [ $this, 'persist_attendees_to_line_item' ], 10, 4 );
        \add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_checkout_assets' ] );

        // BLOCKER #2 — Store-API / block-checkout fail-closed guard. The classic
        // `woocommerce_after_checkout_validation` hook does NOT fire for the
        // Checkout block / Store API, so this separate guard fails an order with
        // event lines closed. Both hooks are wrapped in class_exists guards inside
        // the callback so nothing fatals when the Store API is absent.
        \add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ $this, 'guard_block_checkout' ], 10, 2 );
        \add_action( 'woocommerce_blocks_checkout_order_processed', [ $this, 'guard_block_checkout' ], 10, 1 );

        // Seat creation — single mutation entry point. Phase 2 handles only
        // processing/completed; payment_complete is idempotent insurance for
        // gateways/"mark as paid" flows (spec finding #4).
        \add_action( 'woocommerce_order_status_changed', [ $this, 'on_status_changed' ], 10, 4 );
        \add_action( 'woocommerce_payment_complete', [ $this, 'on_payment_complete' ], 20, 1 );
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
     * Registration-form swap (Phase 2 — now hooked)
     * ------------------------------------------------------------------- */

    /**
     * Swap the free `[event_registration]` form for a WooCommerce "Register"
     * button on linked events. Returns '' for unlinked events so the free form
     * renders unchanged (spec §3 render seam / finding #12).
     *
     * Button states (class `.anchor-event-register`):
     *  - "Register — $price"        when seats remain (links to the product page),
     *  - "Sold out"                 disabled, when sold out + waitlist off,
     *  - "Join waitlist — $price"   when over capacity + waitlist on.
     *
     * @param string $html    Current form HTML ('' = render the free form).
     * @param int    $post_id Event post id.
     * @param array  $meta    Event meta.
     * @return string
     */
    public function filter_registration_form( $html, $post_id, $meta ) {
        $event_id = (int) $post_id;
        $links    = $this->products_for_event( $event_id );
        if ( empty( $links ) ) {
            return $html; // Not linked → let the free form render.
        }

        if ( ! \function_exists( 'wc_get_product' ) ) {
            return $html;
        }

        // Use the first linked product/variation as the registration target.
        $link         = $links[0];
        $product_id   = (int) $link['product_id'];
        $variation_id = (int) $link['variation_id'];
        $target_id    = $variation_id > 0 ? $variation_id : $product_id;

        $product = \wc_get_product( $target_id );
        if ( ! $product ) {
            return $html;
        }

        $capacity  = (int) ( $meta['capacity'] ?? 0 );
        $unlimited = ( $capacity <= 0 );
        $waitlist  = ! empty( $meta['waitlist'] );
        $remaining = $unlimited ? PHP_INT_MAX : (int) $this->registrations->remaining_capacity( $event_id, $capacity );
        $sold_out  = ( ! $unlimited && $remaining <= 0 );

        $price_html = $product->get_price_html();
        // Link to the parent product page so variable products let the buyer pick
        // the session/variation; simple products land on their own page.
        $url = \get_permalink( $product_id );

        $out = '<div class="anchor-event-registration anchor-event-registration-woocommerce">';

        if ( $sold_out && ! $waitlist ) {
            $out .= '<button type="button" class="anchor-event-button anchor-event-register" disabled aria-disabled="true">'
                . \esc_html__( 'Sold out', 'anchor-schema' ) . '</button>';
        } else {
            $label = $sold_out && $waitlist
                ? \__( 'Join waitlist', 'anchor-schema' )
                : \__( 'Register', 'anchor-schema' );
            $text = $price_html !== ''
                ? $label . ' — ' . \wp_strip_all_tags( $price_html )
                : $label;
            $out .= '<a class="anchor-event-button anchor-event-register" href="' . \esc_url( $url ) . '">'
                . \esc_html( $text ) . '</a>';
        }

        $out .= '</div>';
        return $out;
    }

    /* ---------------------------------------------------------------------
     * Capacity enforcement layer 1 — purchasability / add-to-cart
     * ------------------------------------------------------------------- */

    /**
     * Resolve the event for a WC_Product object (simple or variation).
     *
     * @param mixed $product
     * @return int
     */
    private function event_for_product_object( $product ) {
        if ( ! $product instanceof \WC_Product ) {
            return 0;
        }
        if ( $product->is_type( 'variation' ) ) {
            return $this->event_for_variation( (int) $product->get_id() );
        }
        return $this->event_for_product( (int) $product->get_id() );
    }

    /**
     * Block purchase when the linked event is sold out and waitlist is off.
     * Serves both `woocommerce_is_purchasable` and `woocommerce_variation_is_purchasable`.
     *
     * @param bool  $purchasable
     * @param mixed $product
     * @return bool
     */
    public function filter_is_purchasable( $purchasable, $product ) {
        if ( ! $purchasable || ! $product instanceof \WC_Product ) {
            return $purchasable;
        }
        $event_id = $this->event_for_product_object( $product );
        if ( $event_id <= 0 ) {
            return $purchasable;
        }
        $meta = $this->module->get_meta( $event_id );
        if ( ! empty( $meta['waitlist'] ) ) {
            return $purchasable; // Waitlist on → always purchasable.
        }
        $capacity = (int) ( $meta['capacity'] ?? 0 );
        if ( $capacity <= 0 ) {
            return $purchasable; // Unlimited.
        }
        return $this->registrations->remaining_capacity( $event_id, $capacity ) > 0 ? $purchasable : false;
    }

    /**
     * Reject a stale add-to-cart when seats no longer remain (waitlist off).
     *
     * @param bool $passed
     * @param int  $product_id
     * @param int  $quantity
     * @param int  $variation_id
     * @return bool
     */
    public function validate_add_to_cart( $passed, $product_id, $quantity, $variation_id = 0 ) {
        $event_id = $this->event_for_line( (int) $product_id, (int) $variation_id );
        if ( $event_id <= 0 ) {
            return $passed;
        }
        $meta = $this->module->get_meta( $event_id );
        if ( ! empty( $meta['waitlist'] ) ) {
            return $passed;
        }
        $capacity = (int) ( $meta['capacity'] ?? 0 );
        if ( $capacity <= 0 ) {
            return $passed;
        }
        $remaining = (int) $this->registrations->remaining_capacity( $event_id, $capacity );
        if ( $remaining < (int) $quantity && \function_exists( 'wc_add_notice' ) ) {
            \wc_add_notice(
                \sprintf(
                    /* translators: 1: remaining seat count, 2: event title. */
                    \__( 'Sorry, only %1$d seat(s) remain for %2$s.', 'anchor-schema' ),
                    $remaining,
                    \get_the_title( $event_id )
                ),
                'error'
            );
            return false;
        }
        return $passed;
    }

    /**
     * Clearer messaging when a cart contains more event seats than remain
     * (covers WC silently dropping a now-unpurchasable product — finding #16).
     */
    public function notice_over_capacity_cart_items() {
        if ( ! \function_exists( 'wc_add_notice' ) ) {
            return;
        }
        foreach ( $this->get_event_cart_lines() as $line ) {
            $meta = $this->module->get_meta( $line['event_id'] );
            if ( ! empty( $meta['waitlist'] ) ) {
                continue;
            }
            $capacity = (int) ( $meta['capacity'] ?? 0 );
            if ( $capacity <= 0 ) {
                continue;
            }
            $remaining = (int) $this->registrations->remaining_capacity( $line['event_id'], $capacity );
            if ( $line['qty'] > $remaining ) {
                \wc_add_notice(
                    \sprintf(
                        /* translators: 1: remaining seat count, 2: event title. */
                        \__( 'Only %1$d seat(s) remain for %2$s. Please adjust the quantity.', 'anchor-schema' ),
                        $remaining,
                        $line['event_title']
                    ),
                    'error'
                );
            }
        }
    }

    /* ---------------------------------------------------------------------
     * Cart inspector + checkout attendee capture (spec §6)
     * ------------------------------------------------------------------- */

    /**
     * Cart lines that register for an event, keyed by cart_item_key. Uses the
     * resolver (master toggle respected via event_for_line).
     *
     * @return array<string,array{cart_item_key:string,product_id:int,variation_id:int,event_id:int,event_title:string,qty:int}>
     */
    private function get_event_cart_lines() {
        $lines = [];
        if ( ! \function_exists( 'WC' ) || ! WC()->cart ) {
            return $lines;
        }
        foreach ( WC()->cart->get_cart() as $cart_item_key => $item ) {
            $product_id   = (int) ( $item['product_id'] ?? 0 );
            $variation_id = (int) ( $item['variation_id'] ?? 0 );
            $event_id     = $this->event_for_line( $product_id, $variation_id );
            if ( $event_id <= 0 ) {
                continue;
            }
            $lines[ $cart_item_key ] = [
                'cart_item_key' => (string) $cart_item_key,
                'product_id'    => $product_id,
                'variation_id'  => $variation_id,
                'event_id'      => $event_id,
                'event_title'   => \get_the_title( $event_id ),
                'qty'           => max( 1, (int) ( $item['quantity'] ?? 1 ) ),
            ];
        }
        return $lines;
    }

    /**
     * Render per-seat attendee fields on the classic checkout. Field names are
     * exactly anchor_attendees[<cart_item_key>][<seat_index>][name|email|phone].
     * Repopulates from $_POST on validation failure.
     */
    public function render_checkout_attendee_fields() {
        $lines = $this->get_event_cart_lines();
        if ( empty( $lines ) ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC handles the checkout nonce; we only repopulate.
        $posted = ( isset( $_POST['anchor_attendees'] ) && \is_array( $_POST['anchor_attendees'] ) )
            ? \wp_unslash( $_POST['anchor_attendees'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            : [];

        echo '<div class="anchor-event-attendees" id="anchor-event-attendees">';
        echo '<h3>' . \esc_html__( 'Attendee details', 'anchor-schema' ) . '</h3>';

        foreach ( $lines as $cart_item_key => $line ) {
            echo '<fieldset class="anchor-event-attendee-line" data-cart-item="' . \esc_attr( $cart_item_key ) . '">';
            echo '<legend>' . \esc_html( $line['event_title'] ) . '</legend>';

            for ( $i = 1; $i <= $line['qty']; $i++ ) {
                $name  = isset( $posted[ $cart_item_key ][ $i ]['name'] ) ? \sanitize_text_field( $posted[ $cart_item_key ][ $i ]['name'] ) : '';
                $email = isset( $posted[ $cart_item_key ][ $i ]['email'] ) ? \sanitize_text_field( $posted[ $cart_item_key ][ $i ]['email'] ) : '';
                $phone = isset( $posted[ $cart_item_key ][ $i ]['phone'] ) ? \sanitize_text_field( $posted[ $cart_item_key ][ $i ]['phone'] ) : '';
                $base  = 'anchor_attendees[' . $cart_item_key . '][' . $i . ']';

                echo '<div class="anchor-event-attendee-seat">';
                echo '<p class="anchor-event-attendee-heading">' . \esc_html(
                    \sprintf(
                        /* translators: 1: seat number, 2: total seats on this line. */
                        \__( 'Attendee %1$d of %2$d', 'anchor-schema' ),
                        $i,
                        $line['qty']
                    )
                ) . '</p>';

                echo '<p class="form-row form-row-wide">';
                echo '<label>' . \esc_html__( 'Name', 'anchor-schema' ) . ' <abbr class="required" title="required">*</abbr></label>';
                echo '<input type="text" class="input-text" name="' . \esc_attr( $base . '[name]' ) . '" value="' . \esc_attr( $name ) . '" required />';
                echo '</p>';

                echo '<p class="form-row form-row-first">';
                echo '<label>' . \esc_html__( 'Email', 'anchor-schema' ) . ' <abbr class="required" title="required">*</abbr></label>';
                echo '<input type="email" class="input-text" name="' . \esc_attr( $base . '[email]' ) . '" value="' . \esc_attr( $email ) . '" required />';
                echo '</p>';

                echo '<p class="form-row form-row-last">';
                echo '<label>' . \esc_html__( 'Phone', 'anchor-schema' ) . ' <abbr class="required" title="required">*</abbr></label>';
                echo '<input type="tel" class="input-text" name="' . \esc_attr( $base . '[phone]' ) . '" value="' . \esc_attr( $phone ) . '" required />';
                echo '</p>';

                echo '</div>';
            }

            echo '</fieldset>';
        }

        echo '</div>';
    }

    /**
     * Validate per-seat attendee data + a pre-payment capacity re-check on the
     * classic checkout. Block/Store-API placement does NOT fire this hook — the
     * Store-API guard (guard_block_checkout) handles that path.
     *
     * @param array     $data
     * @param \WP_Error $errors
     */
    public function validate_checkout_attendees( $data, $errors ) {
        $lines = $this->get_event_cart_lines();
        if ( empty( $lines ) ) {
            return;
        }

        // Classic-only note: if the checkout page itself is a block checkout, the
        // real enforcement is the Store-API guard; fail closed here too.
        if (
            \function_exists( 'has_block' ) && \function_exists( 'wc_get_page_id' )
            && \has_block( 'woocommerce/checkout', \wc_get_page_id( 'checkout' ) )
        ) {
            $errors->add(
                'anchor_block_checkout',
                \__( 'Event registration is not supported on the block checkout. Please contact us to complete your registration.', 'anchor-schema' )
            );
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC validates the checkout nonce on this hook.
        $posted = ( isset( $_POST['anchor_attendees'] ) && \is_array( $_POST['anchor_attendees'] ) )
            ? \wp_unslash( $_POST['anchor_attendees'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            : [];

        $requested_per_event = [];

        foreach ( $lines as $cart_item_key => $line ) {
            $event_id = (int) $line['event_id'];
            $requested_per_event[ $event_id ] = ( $requested_per_event[ $event_id ] ?? 0 ) + $line['qty'];

            for ( $i = 1; $i <= $line['qty']; $i++ ) {
                $name  = isset( $posted[ $cart_item_key ][ $i ]['name'] ) ? \sanitize_text_field( $posted[ $cart_item_key ][ $i ]['name'] ) : '';
                $email = isset( $posted[ $cart_item_key ][ $i ]['email'] ) ? \sanitize_email( $posted[ $cart_item_key ][ $i ]['email'] ) : '';
                $phone = isset( $posted[ $cart_item_key ][ $i ]['phone'] ) ? \sanitize_text_field( $posted[ $cart_item_key ][ $i ]['phone'] ) : '';

                $who = \sprintf(
                    /* translators: 1: seat number, 2: event title. */
                    \__( 'attendee %1$d for %2$s', 'anchor-schema' ),
                    $i,
                    $line['event_title']
                );

                if ( $name === '' ) {
                    $errors->add(
                        'anchor_attendee_' . $cart_item_key . '_' . $i . '_name',
                        \sprintf( /* translators: %s: attendee descriptor. */ \__( 'Please enter a name for %s.', 'anchor-schema' ), $who )
                    );
                }
                if ( $email === '' || ! \is_email( $email ) ) {
                    $errors->add(
                        'anchor_attendee_' . $cart_item_key . '_' . $i . '_email',
                        \sprintf( /* translators: %s: attendee descriptor. */ \__( 'Please enter a valid email for %s.', 'anchor-schema' ), $who )
                    );
                }
                if ( $phone === '' ) {
                    $errors->add(
                        'anchor_attendee_' . $cart_item_key . '_' . $i . '_phone',
                        \sprintf( /* translators: %s: attendee descriptor. */ \__( 'Please enter a phone number for %s.', 'anchor-schema' ), $who )
                    );
                }
            }
        }

        // Pre-payment capacity re-check (aggregate per event across lines).
        foreach ( $requested_per_event as $event_id => $requested ) {
            $meta = $this->module->get_meta( $event_id );
            if ( ! empty( $meta['waitlist'] ) ) {
                continue; // Waitlist on → allow; overflow handled at seat creation.
            }
            $capacity = (int) ( $meta['capacity'] ?? 0 );
            if ( $capacity <= 0 ) {
                continue; // Unlimited.
            }
            $remaining = (int) $this->registrations->remaining_capacity( $event_id, $capacity );
            if ( $requested > $remaining ) {
                $errors->add(
                    'anchor_capacity_' . $event_id,
                    \sprintf(
                        /* translators: 1: remaining seat count, 2: event title. */
                        \__( 'Only %1$d seat(s) remain for %2$s.', 'anchor-schema' ),
                        $remaining,
                        \get_the_title( $event_id )
                    )
                );
            }
        }
    }

    /**
     * BLOCKER #2 — fail an order with event lines closed on block/Store-API
     * placement (the classic validation hook never fires there). Wrapped in
     * class_exists guards so nothing fatals when the Store API is absent.
     *
     * Hook signatures:
     *  - woocommerce_store_api_checkout_update_order_from_request( WC_Order $order, $request )
     *  - woocommerce_blocks_checkout_order_processed( WC_Order $order )
     *
     * @param mixed $order
     * @param mixed $request
     */
    public function guard_block_checkout( $order, $request = null ) {
        if ( ! $order instanceof \WC_Order ) {
            return;
        }
        if ( ! $this->order_has_event_lines( $order ) ) {
            return;
        }
        if ( \class_exists( '\\Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException' ) ) {
            throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
                'anchor_event_block_checkout_unsupported',
                \esc_html__( 'Event registrations cannot be completed through the block checkout. Please use the classic checkout.', 'anchor-schema' ),
                400
            );
        }
        // No Store API exception class available: leave a needs-review backstop so
        // the seat-less order is visible (reconcile also flags attendees_missing).
        Events_Log::flag_review( (int) $order->get_id(), 'attendees_missing', 'block/Store-API placement' );
    }

    /**
     * Persist per-seat attendee data + link snapshot onto the order line item.
     * HPOS-safe (CRUD only). Creates NO seat posts — order is still pending.
     *
     * Hook: woocommerce_checkout_create_order_line_item( WC_Order_Item_Product $item, string $cart_item_key, array $values, WC_Order $order )
     *
     * @param mixed  $item
     * @param string $cart_item_key
     * @param array  $values
     * @param mixed  $order
     */
    public function persist_attendees_to_line_item( $item, $cart_item_key, $values, $order ) {
        if ( ! $item instanceof \WC_Order_Item_Product ) {
            return;
        }
        $product_id   = (int) $item->get_product_id();
        $variation_id = (int) $item->get_variation_id();
        $event_id     = $this->event_for_line( $product_id, $variation_id );
        if ( $event_id <= 0 ) {
            return;
        }

        $qty = max( 1, (int) $item->get_quantity() );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- runs inside WC's nonce-verified checkout transaction.
        $raw = ( isset( $_POST['anchor_attendees'][ $cart_item_key ] ) && \is_array( $_POST['anchor_attendees'][ $cart_item_key ] ) )
            ? \wp_unslash( $_POST['anchor_attendees'][ $cart_item_key ] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            : [];

        $attendees = [];
        for ( $i = 1; $i <= $qty; $i++ ) {
            if ( ! isset( $raw[ $i ] ) || ! \is_array( $raw[ $i ] ) ) {
                continue;
            }
            $attendees[ $i ] = [
                'name'  => \sanitize_text_field( $raw[ $i ]['name'] ?? '' ),
                'email' => \sanitize_email( $raw[ $i ]['email'] ?? '' ),
                'phone' => \sanitize_text_field( $raw[ $i ]['phone'] ?? '' ),
            ];
        }

        if ( ! empty( $attendees ) ) {
            $item->update_meta_data( '_anchor_attendees', $attendees );
        }
        // Link snapshot — survives later un-linking; used by reconcile to resolve
        // the event without re-querying live product meta.
        $item->update_meta_data( '_anchor_event_id', $event_id );
        $item->update_meta_data( '_anchor_product_id', $product_id );
        $item->update_meta_data( '_anchor_variation_id', $variation_id );
    }

    /** Enqueue the minimal checkout-attendees JS on the checkout page only. */
    public function enqueue_checkout_assets() {
        if ( ! \function_exists( 'is_checkout' ) || ! \is_checkout() ) {
            return;
        }
        \wp_enqueue_script(
            'anchor-event-checkout-attendees',
            \Anchor_Asset_Loader::url( 'anchor-events-manager/assets/checkout-attendees.js' ),
            [ 'jquery' ],
            '1.0.0',
            true
        );
    }

    /* ---------------------------------------------------------------------
     * Seat creation on processing/completed (spec §7 — Phase 2 subset)
     * ------------------------------------------------------------------- */

    /**
     * Per-PROCESS re-entrancy guard keyed by order id, to avoid a self-re-entrant
     * double run within one request (e.g. status_changed + payment_complete in the
     * same process). NOTE: this is per-PHP-process only — the per-event GET_LOCK in
     * claim_woo_seats() is the real cross-process concurrency guard.
     *
     * @var array<int,bool>
     */
    private static $in_flight = [];

    /**
     * woocommerce_order_status_changed( int $order_id, string $from, string $to, WC_Order $order )
     *
     * @param int    $order_id
     * @param string $from
     * @param string $to
     * @param mixed  $order
     */
    public function on_status_changed( $order_id, $from, $to, $order = null ) {
        $order = $order instanceof \WC_Order ? $order : \wc_get_order( (int) $order_id );
        if ( ! $order ) {
            return;
        }
        $this->reconcile_order( $order, 'status changed to ' . (string) $to );
    }

    /**
     * woocommerce_payment_complete( int $order_id ) — idempotent insurance.
     *
     * @param int $order_id
     */
    public function on_payment_complete( $order_id ) {
        $order = \wc_get_order( (int) $order_id );
        if ( ! $order ) {
            return;
        }
        $this->reconcile_order( $order, 'payment complete' );
    }

    /**
     * The single seat-mutation entry point. PHASE 2 handles ONLY the
     * processing/completed targets (create confirmed/waitlist seats). All other
     * statuses, refunds, order edits, trash/delete, roster and emails are Phases
     * 3–6. All order/item access is via WC CRUD (HPOS-safe) — zero postmeta.
     *
     * @param mixed  $order  WC_Order (handlers normalize before calling).
     * @param string $reason Human reason for the sync log.
     */
    public function reconcile_order( $order, $reason = '' ) {
        if ( ! $order instanceof \WC_Order ) {
            return;
        }
        $order_id = (int) $order->get_id();
        if ( $order_id <= 0 ) {
            return;
        }

        // Phase 2: only create seats for paid statuses.
        $status = $order->get_status(); // slug, no "wc-" prefix.
        if ( ! \in_array( $status, [ 'processing', 'completed' ], true ) ) {
            return;
        }

        // Per-process re-entrancy guard (see $in_flight docblock).
        if ( isset( self::$in_flight[ $order_id ] ) ) {
            return;
        }
        self::$in_flight[ $order_id ] = true;

        try {
            $customer_id   = (int) $order->get_customer_id();   // 0 = guest (spec §6.6) — never current user.
            $billing_email = (string) $order->get_billing_email();
            $billing_phone = (string) $order->get_billing_phone();
            $billing_name  = \trim( (string) $order->get_formatted_billing_full_name() );

            foreach ( $order->get_items() as $item_id => $item ) {
                $item_id = (int) $item_id;
                if ( $item_id <= 0 ) {
                    continue; // (0,1) wildcard guard (finding #11).
                }
                if ( ! $item instanceof \WC_Order_Item_Product ) {
                    continue;
                }

                $event_id = $this->event_for_order_item( $item );
                if ( $event_id <= 0 ) {
                    continue; // Unmapped line — left untouched this phase.
                }

                $attendees = $item->get_meta( '_anchor_attendees' );
                if ( ! \is_array( $attendees ) || empty( $attendees ) ) {
                    // attendees_missing backstop (finding #2/#17): never billing-fill
                    // a whole line — flag for review instead.
                    Events_Log::flag_review( $order_id, 'attendees_missing', 'order item ' . $item_id );
                    Events_Log::order( $order_id, 'Attendee data missing on an event line; flagged for review.', [
                        'item'  => $item_id,
                        'event' => $event_id,
                    ] );
                    continue;
                }

                $product_id   = (int) $item->get_product_id();
                $variation_id = (int) $item->get_variation_id();
                $qty          = max( 1, (int) $item->get_quantity() );
                $meta         = $this->module->get_meta( $event_id );

                // Build the per-seat map for claim_woo_seats().
                $seats = [];
                for ( $i = 1; $i <= $qty; $i++ ) {
                    $att   = ( isset( $attendees[ $i ] ) && \is_array( $attendees[ $i ] ) ) ? $attendees[ $i ] : [];
                    $name  = \sanitize_text_field( (string) ( $att['name'] ?? '' ) );
                    $email = \sanitize_email( (string) ( $att['email'] ?? '' ) );
                    $phone = \sanitize_text_field( (string) ( $att['phone'] ?? '' ) );
                    $note  = 'order #' . $order_id;

                    // Individual seat entry missing within a present array → fall
                    // back to billing identity with a note (whole-array-missing was
                    // already handled above).
                    if ( $name === '' && $email === '' ) {
                        $name  = $billing_name;
                        $email = $billing_email;
                        $phone = $billing_phone;
                        $note  = 'order #' . $order_id . ' (attendee data missing, used billing)';
                    }

                    $seats[ $i ] = [
                        'name'          => $name,
                        'email'         => $email,
                        'phone'         => $phone,
                        'order_id'      => $order_id,
                        'order_item_id' => $item_id,
                        'product_id'    => $product_id,
                        'variation_id'  => $variation_id,
                        'customer_id'   => $customer_id,
                        'note'          => $note,
                    ];
                }

                $result = $this->registrations->claim_woo_seats( $event_id, $meta, $seats );

                if ( ! empty( $result['overfill'] ) ) {
                    Events_Log::flag_review( $order_id, 'capacity_overfill', 'event ' . $event_id );
                    Events_Log::order( $order_id, 'Capacity overfill: not all paid seats could be confirmed.', [
                        'event' => $event_id,
                        'item'  => $item_id,
                    ] );
                }
                if ( ! empty( $result['lock_unavailable'] ) ) {
                    Events_Log::flag_review( $order_id, 'capacity_lock_unavailable', 'event ' . $event_id );
                }

                $made = \count( $result['created'] ) + \count( $result['waitlisted'] );
                if ( $made > 0 ) {
                    Events_Log::order( $order_id, \sprintf( 'Created %d seat(s) for event #%d.', $made, $event_id ), [
                        'created'    => $result['created'],
                        'waitlisted' => $result['waitlisted'],
                        'reason'     => (string) $reason,
                    ] );
                }
            }
        } finally {
            unset( self::$in_flight[ $order_id ] );
        }
    }

    /**
     * Whether any line item on the order registers for an event (prefers the
     * persisted link snapshot, falls back to the live resolver).
     */
    private function order_has_event_lines( \WC_Order $order ) {
        foreach ( $order->get_items() as $item ) {
            if ( $this->event_for_order_item( $item ) > 0 ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Resolve the event for an order line item: prefer the persisted link
     * snapshot, else the live resolver. Returns a validated event id or 0.
     *
     * @param mixed $item
     * @return int
     */
    private function event_for_order_item( $item ) {
        if ( ! $item instanceof \WC_Order_Item_Product ) {
            return 0;
        }
        $snapshot = (int) $item->get_meta( '_anchor_event_id' );
        if ( $snapshot > 0 && $this->validate_event_id( $snapshot ) ) {
            return $snapshot;
        }
        return $this->event_for_line( (int) $item->get_product_id(), (int) $item->get_variation_id() );
    }
}
