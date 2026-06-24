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
 * Phase 2:
 *  - ACTIVATES the `anchor_events_registration_form` filter so linked events show
 *    a "Register — $price" button instead of the free form (spec finding #12 —
 *    safe now because seat creation lands in the same phase),
 *  - per-seat attendee capture on the classic checkout, server-side validation +
 *    capacity re-check, and a Store-API/block-checkout fail-closed guard,
 *  - persistence of attendee data to the order line item.
 *
 * Phases 3 & 4 (this pass):
 *  - `reconcile_order()` is now fully declarative & idempotent for ALL order
 *    statuses (full status map), manual order edits, order trash/delete, and
 *    refunds (partial line / full / amount-only),
 *  - surplus is COUNT-based newest-first; gap-fill revives cancelled/failed and
 *    skips refunded; variation-change-in-place is handled; one batched
 *    `$order->save()` runs at end of pass inside the in-flight guard,
 *  - a manual "Resync order" button + per-order seat metabox.
 */
class WooCommerce {

    /** Master toggle on the parent product ('1' / ''). */
    const META_ENABLED  = '_anchor_evt_link_enabled';
    /** Target event id on product (simple) or variation (per-session). */
    const META_EVENT_ID = '_anchor_evt_link_event_id';

    /**
     * Order meta: which Phase 6 emails have already been sent for this order, so a
     * re-fired reconcile pass never re-spams. Associative: 'customer' => ts,
     * 'organizer:{event_id}' => ts. Written via WC CRUD (HPOS-safe).
     */
    const EMAILS_SENT_META = '_anchor_event_emails_sent';

    /**
     * Sentinel returned by map_order_status_to_seat() for ANY unrecognized/custom
     * order status (deposits, subscriptions, fulfillment, partial-payment, …).
     * reconcile_order() treats this as "leave seats exactly as-is" — it does NOT
     * force expected = 0 and never sweeps active seats (finding M6).
     */
    const SEAT_TARGET_UNKNOWN = '__anchor_unknown__';

    /** Short-lived cache of the needs-review order presence/count (finding L10). */
    const NEEDS_REVIEW_TRANSIENT = 'anchor_events_needs_review';

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

        // Seat creation/sync — single mutation entry point. payment_complete is
        // idempotent insurance for gateways/"mark as paid" flows (spec finding #4).
        \add_action( 'woocommerce_order_status_changed', [ $this, 'on_status_changed' ], 10, 4 );
        \add_action( 'woocommerce_payment_complete', [ $this, 'on_payment_complete' ], 20, 1 );

        /* -----------------------------------------------------------------
         * Phase 3 — full lifecycle sync, manual edits, trash/delete, resync
         * --------------------------------------------------------------- */

        // Manual order edits (add/remove line, qty ±) — re-fetch + reconcile.
        \add_action( 'woocommerce_saved_order_items', [ $this, 'on_saved_order_items' ], 10, 2 );
        // Line removal — arg is an INT item id, NOT an item object (finding #14).
        \add_action( 'woocommerce_before_delete_order_item', [ $this, 'on_delete_order_item' ], 10, 1 );

        // Order trash / permanent delete — release capacity BEFORE the order is
        // gone (finding #8); after deletion wc_get_order() returns false and
        // reconcile early-returns, leaking capacity.
        \add_action( 'woocommerce_before_trash_order', [ $this, 'on_order_trashed_or_deleted' ], 10, 1 );
        \add_action( 'woocommerce_trash_order', [ $this, 'on_order_trashed_or_deleted' ], 10, 1 );
        \add_action( 'woocommerce_before_delete_order', [ $this, 'on_order_trashed_or_deleted' ], 10, 1 );
        // Legacy (posts) order storage delete — guard the post type inside.
        \add_action( 'before_delete_post', [ $this, 'on_legacy_order_deleted' ], 10, 1 );

        // Manual "Resync order" button + per-order seat metabox.
        \add_action( 'admin_post_anchor_event_resync_order', [ $this, 'handle_resync_order' ] );
        \add_action( 'add_meta_boxes', [ $this, 'register_order_metabox' ], 30, 2 );

        // Event-page storefront — inline AJAX add-to-cart (Task 3.2). Both the
        // logged-in and guest endpoints share one nonce-verified handler.
        \add_action( 'wp_ajax_anchor_events_add_to_cart', [ $this, 'ajax_add_to_cart' ] );
        \add_action( 'wp_ajax_nopriv_anchor_events_add_to_cart', [ $this, 'ajax_add_to_cart' ] );

        /* -----------------------------------------------------------------
         * Phase 6 — emails, needs-review notices, manual order actions
         * --------------------------------------------------------------- */

        // Needs-review admin notices on Events list / WC Orders / Events settings.
        \add_action( 'admin_notices', [ $this, 'render_needs_review_notice' ] );
        // Per-order metabox buttons: clear review + resend buyer confirmation.
        \add_action( 'admin_post_anchor_events_clear_review', [ $this, 'handle_clear_review' ] );
        \add_action( 'admin_post_anchor_events_resend_confirmation', [ $this, 'handle_resend_confirmation' ] );

        /* -----------------------------------------------------------------
         * Phase 4 — refunds (full / partial line / amount-only needs-review)
         * --------------------------------------------------------------- */

        // woocommerce_order_refunded( int $order_id, int $refund_id ). Full refund
        // also drives order_status_changed → refunded; both are idempotent.
        \add_action( 'woocommerce_order_refunded', [ $this, 'on_order_refunded' ], 10, 2 );

        // NOTE: `woocommerce_update_order` is intentionally NOT hooked (finding #3).
        // It fires on essentially every $order->save() — including reconcile's own
        // end-of-pass save and mid-checkout while status='pending' — risking a save
        // loop and spurious pending→cancelled sweeps. Status changes are covered by
        // order_status_changed/payment_complete; item edits by saved_order_items.
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
     * Registration-form swap → event-page ticket storefront (Phase 3)
     * ------------------------------------------------------------------- */

    /** Per-PHP-process guard so the free-tier render seam can't re-enter. */
    private static $rendering_free = [];

    /** Sane upper bound for a single tier's quantity selector (unlimited cap). */
    const QTY_CAP = 20;

    /**
     * Swap the free `[event_registration]` form for the inline WooCommerce ticket
     * storefront (spec §6). For an event with ≥1 ACTIVE PAID tier this returns a
     * multi-tier ticket block with per-tier availability states + an AJAX
     * add-to-cart button. Events with no managed paid tier return `$html`
     * unchanged so the free inline form (or the legacy escape-hatch product link)
     * renders instead (spec §3 render seam / finding #12).
     *
     * Per-tier states (spec §6/§7):
     *  - outside the sale window → "Sales open <date>" (no qty),
     *  - tier/event sold out + waitlist off → disabled "Sold out" (no qty),
     *  - event full + waitlist on → "Join waitlist" note + qty input,
     *  - otherwise → a quantity input bounded by the remaining seats.
     *
     * @param string $html    Current form HTML ('' = render the free form).
     * @param int    $post_id Event post id.
     * @param array  $meta    Event meta.
     * @return string
     */
    public function filter_registration_form( $html, $post_id, $meta ) {
        $event_id = (int) $post_id;

        // Re-entry from our own free-tier render seam → let the native free form
        // build (this filter returns '' so render_registration_form continues).
        if ( ! empty( self::$rendering_free[ $event_id ] ) ) {
            return $html;
        }

        if ( ! $this->module->ticket_types ) {
            return $html;
        }

        $tiers           = $this->module->ticket_types->get( $event_id );
        $paid_active     = [];
        $has_free_active = false;
        foreach ( $tiers as $tier ) {
            if ( empty( $tier['active'] ) ) {
                continue;
            }
            if ( (float) $tier['price'] > 0 ) {
                $paid_active[] = $tier;
            } else {
                $has_free_active = true;
            }
        }

        // No managed paid tiers → fall back to the legacy escape-hatch product
        // link (if any) or let the free inline form render.
        if ( empty( $paid_active ) ) {
            return $this->legacy_product_link_form( $html, $event_id, $meta );
        }

        // Paid tiers require WooCommerce; degrade to the free form when absent.
        if ( ! \function_exists( 'wc_get_product' ) || ! $this->module->product_sync ) {
            return $html;
        }

        $waitlist = ! empty( $meta['waitlist'] );

        $rows = '';
        foreach ( $paid_active as $tier ) {
            $rows .= $this->render_ticket_row( $event_id, $tier, $waitlist );
        }

        $out  = '<div class="anchor-event-registration anchor-event-tickets" data-event="' . \esc_attr( $event_id ) . '">';
        $out .= '<div class="anchor-event-ticket-rows">' . $rows . '</div>';
        $out .= '<div class="anchor-event-tickets-actions">';
        $out .= '<button type="button" class="anchor-event-button anchor-event-register" data-add-to-cart>'
            . \esc_html__( 'Register / Add to cart', 'anchor-schema' ) . '</button>';
        $out .= '</div>';
        $out .= '<div class="anchor-event-cart-msg" aria-live="polite"></div>';

        // Mixed free + paid event → also render the lightweight inline free form.
        if ( $has_free_active ) {
            self::$rendering_free[ $event_id ] = true;
            $free = (string) $this->module->render_registration_form( $event_id );
            unset( self::$rendering_free[ $event_id ] );
            if ( $free !== '' ) {
                $out .= '<div class="anchor-event-free-registration">' . $free . '</div>';
            }
        }

        $out .= '</div>';

        $this->enqueue_storefront_assets();

        return $out;
    }

    /**
     * Render one ticket-tier row: label, price, and availability (state-dependent
     * qty input). All dynamic output is escaped; wc_price() returns safe markup.
     *
     * @param int   $event_id
     * @param array $tier     Normalized tier array.
     * @param bool  $waitlist Event-level waitlist toggle.
     * @return string
     */
    private function render_ticket_row( $event_id, array $tier, $waitlist ) {
        $tier_id = (string) $tier['id'];
        $label   = ( $tier['label'] !== '' ) ? (string) $tier['label'] : \__( 'Ticket', 'anchor-schema' );
        $price   = (float) $tier['price'];

        $price_html = \function_exists( 'wc_price' )
            ? \wc_price( $price )
            : \esc_html( \number_format_i18n( $price, 2 ) );

        $row  = '<div class="anchor-event-ticket-row" data-tier="' . \esc_attr( $tier_id ) . '">';
        $row .= '<span class="anchor-event-ticket-label">' . \esc_html( $label ) . '</span>';
        $row .= '<span class="anchor-event-ticket-price">' . $price_html . '</span>';

        // Outside the sale window → message only, no quantity input.
        if ( ! $this->module->ticket_types->is_on_sale( $tier ) ) {
            $start = (string) ( $tier['sale_start'] ?? '' );
            $msg   = ( $start !== '' )
                ? \sprintf( /* translators: %s: sale-start date. */ \__( 'Sales open %s', 'anchor-schema' ), $start )
                : \__( 'Not on sale', 'anchor-schema' );
            $row .= '<span class="anchor-event-ticket-availability anchor-event-ticket-upcoming">' . \esc_html( $msg ) . '</span>';
            $row .= '</div>';
            return $row;
        }

        $remaining = (int) $this->registrations->tier_remaining( $event_id, $tier );

        // Sold out (tier quota or event total exhausted) + waitlist off.
        if ( $remaining <= 0 && ! $waitlist ) {
            $row .= '<span class="anchor-event-ticket-availability anchor-event-ticket-soldout" aria-disabled="true">'
                . \esc_html__( 'Sold out', 'anchor-schema' ) . '</span>';
            $row .= '</div>';
            return $row;
        }

        if ( $remaining <= 0 && $waitlist ) {
            // Event full but waitlist on → allow a request beyond capacity.
            $max  = self::QTY_CAP;
            $row .= '<span class="anchor-event-ticket-availability anchor-event-ticket-waitlist">'
                . \esc_html__( 'Join waitlist', 'anchor-schema' ) . '</span>';
        } else {
            $max = \max( 1, \min( $remaining, self::QTY_CAP ) );
        }

        $row .= '<input type="number" class="anchor-event-ticket-qty" min="0" max="' . \esc_attr( $max ) . '"'
            . ' step="1" value="0" data-tier="' . \esc_attr( $tier_id ) . '"'
            . ' aria-label="' . \esc_attr( \sprintf( /* translators: %s: ticket tier label. */ \__( 'Quantity for %s', 'anchor-schema' ), $label ) ) . '" />';
        $row .= '</div>';
        return $row;
    }

    /**
     * Legacy escape-hatch: an event with a manually-linked product but no managed
     * paid tiers keeps the original "Register — $price" link to the product page.
     * Returns `$html` unchanged when the event has no linked product (free form).
     *
     * @param string $html
     * @param int    $event_id
     * @param array  $meta
     * @return string
     */
    private function legacy_product_link_form( $html, $event_id, $meta ) {
        $links = $this->products_for_event( $event_id );
        if ( empty( $links ) || ! \function_exists( 'wc_get_product' ) ) {
            return $html; // Not linked → let the free form render.
        }

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
        $url        = \get_permalink( $product_id );

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

    /** Enqueue + localize the storefront JS once per request (footer script). */
    private function enqueue_storefront_assets() {
        static $done = false;
        if ( $done || ! \function_exists( 'wp_enqueue_script' ) ) {
            return;
        }
        $done = true;

        \wp_enqueue_script(
            'anchor-event-storefront',
            \Anchor_Asset_Loader::url( 'anchor-events-manager/assets/event-storefront.js' ),
            [ 'jquery' ],
            '1.0.0',
            true
        );
        \wp_localize_script( 'anchor-event-storefront', 'AnchorEventsStore', [
            'ajaxUrl'   => \admin_url( 'admin-ajax.php' ),
            'nonce'     => \wp_create_nonce( 'anchor_events_add_to_cart' ),
            'addAction' => 'anchor_events_add_to_cart',
            'i18n'      => [
                'selectQty' => \__( 'Please choose at least one ticket.', 'anchor-schema' ),
                'error'     => \__( 'Sorry, something went wrong. Please try again.', 'anchor-schema' ),
                'viewCart'  => \__( 'View cart', 'anchor-schema' ),
                'checkout'  => \__( 'Checkout', 'anchor-schema' ),
            ],
        ] );
    }

    /* ---------------------------------------------------------------------
     * Add-to-cart AJAX endpoint (Task 3.2)
     * ------------------------------------------------------------------- */

    /**
     * Map the posted {tier_id => qty} selection to the event's managed product
     * variations and add them to the cart. Validates each tier server-side
     * (active + on sale + capacity) under the same authority as the back-stop
     * gates (which remain in place). Guards all WooCommerce (wc_* / WC()) access.
     */
    public function ajax_add_to_cart() {
        \check_ajax_referer( 'anchor_events_add_to_cart', 'nonce' );

        if (
            ! \function_exists( 'WC' ) || ! WC() || ! WC()->cart
            || ! \function_exists( 'wc_get_cart_url' )
        ) {
            \wp_send_json_error( [ 'messages' => [ \__( 'The cart is currently unavailable.', 'anchor-schema' ) ] ] );
        }

        $event_id = isset( $_POST['event_id'] ) ? (int) $_POST['event_id'] : 0;
        if ( $event_id <= 0 || \get_post_type( $event_id ) !== Module::CPT ) {
            \wp_send_json_error( [ 'messages' => [ \__( 'Invalid event.', 'anchor-schema' ) ] ] );
        }
        if ( ! $this->module->ticket_types || ! $this->module->product_sync ) {
            \wp_send_json_error( [ 'messages' => [ \__( 'Registration is not available for this event.', 'anchor-schema' ) ] ] );
        }

        // Normalize the posted tier map to sanitized tier_id => positive int qty.
        $raw       = ( isset( $_POST['tiers'] ) && \is_array( $_POST['tiers'] ) ) ? \wp_unslash( $_POST['tiers'] ) : [];
        $requested = [];
        foreach ( $raw as $tid => $qty ) {
            $tid = \sanitize_key( (string) $tid );
            $qty = \max( 0, (int) $qty );
            if ( $tid !== '' && $qty > 0 ) {
                $requested[ $tid ] = ( $requested[ $tid ] ?? 0 ) + $qty;
            }
        }
        if ( empty( $requested ) ) {
            \wp_send_json_error( [ 'messages' => [ \__( 'Please choose at least one ticket.', 'anchor-schema' ) ] ] );
        }

        $meta              = $this->module->get_meta( $event_id );
        $parent_product_id = (int) $this->module->product_sync->managed_product_id( $event_id );
        if ( $parent_product_id <= 0 ) {
            \wp_send_json_error( [ 'messages' => [ \__( 'Registration is not available for this event.', 'anchor-schema' ) ] ] );
        }

        $added    = 0;
        $messages = [];

        foreach ( $requested as $tier_id => $qty ) {
            $tier  = $this->module->ticket_types->find( $event_id, $tier_id );
            $label = ( $tier && (string) ( $tier['label'] ?? '' ) !== '' ) ? (string) $tier['label'] : \__( 'Ticket', 'anchor-schema' );

            if ( ! $tier || empty( $tier['active'] ) || (float) $tier['price'] <= 0 ) {
                /* translators: %s: ticket tier label. */
                $messages[] = \sprintf( \__( '%s is not available.', 'anchor-schema' ), $label );
                continue;
            }
            if ( ! $this->module->ticket_types->is_on_sale( $tier ) ) {
                /* translators: %s: ticket tier label. */
                $messages[] = \sprintf( \__( 'Sales for %s are not open.', 'anchor-schema' ), $label );
                continue;
            }

            $variation_id = (int) $this->module->product_sync->variation_for_tier( $event_id, $tier_id );
            if ( $variation_id <= 0 ) {
                /* translators: %s: ticket tier label. */
                $messages[] = \sprintf( \__( '%s is not available.', 'anchor-schema' ), $label );
                continue;
            }

            // Server-side capacity validation (single authority, spec §7).
            $decision = $this->registrations->capacity_decision( $event_id, $meta, $qty, $tier );
            if ( 'closed' === $decision ) {
                /* translators: %s: ticket tier label. */
                $messages[] = \sprintf( \__( 'Registration for %s is closed.', 'anchor-schema' ), $label );
                continue;
            }
            if ( 'full' === $decision ) {
                /* translators: %s: ticket tier label. */
                $messages[] = \sprintf( \__( '%s is sold out.', 'anchor-schema' ), $label );
                continue;
            }

            // 'open' or 'waitlist' → add (waitlist seats are resolved at creation).
            $key = WC()->cart->add_to_cart( $parent_product_id, $qty, $variation_id, [], [] );
            if ( $key ) {
                $added += $qty;
                if ( Registrations::STATUS_WAITLIST === $decision ) {
                    /* translators: 1: quantity, 2: ticket tier label. */
                    $messages[] = \sprintf( \__( 'Added %1$d × %2$s to the waitlist.', 'anchor-schema' ), $qty, $label );
                } else {
                    /* translators: 1: quantity, 2: ticket tier label. */
                    $messages[] = \sprintf( \__( 'Added %1$d × %2$s.', 'anchor-schema' ), $qty, $label );
                }
            } else {
                /* translators: %s: ticket tier label. */
                $messages[] = \sprintf( \__( 'Could not add %s to the cart.', 'anchor-schema' ), $label );
            }
        }

        if ( $added <= 0 ) {
            if ( empty( $messages ) ) {
                $messages[] = \__( 'Nothing could be added to the cart.', 'anchor-schema' );
            }
            \wp_send_json_error( [ 'messages' => $messages ] );
        }

        \wp_send_json_success( [
            'added'        => $added,
            'cart_url'     => \wc_get_cart_url(),
            'checkout_url' => \function_exists( 'wc_get_checkout_url' ) ? \wc_get_checkout_url() : '',
            'messages'     => $messages,
        ] );
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
            // P4 — tier-label the block heading (presentational). Resolve the tier
            // from the line's managed variation; fall back to the event title.
            $heading   = (string) $line['event_title'];
            $tier_label = '';
            if ( $this->module->product_sync && (int) $line['variation_id'] > 0 && $this->module->ticket_types ) {
                $resolved = $this->module->product_sync->tier_for_variation( (int) $line['variation_id'] );
                if ( ! empty( $resolved['tier_id'] ) ) {
                    $tier = $this->module->ticket_types->find( (int) $line['event_id'], (string) $resolved['tier_id'] );
                    if ( $tier && (string) ( $tier['label'] ?? '' ) !== '' ) {
                        $tier_label = (string) $tier['label'];
                    }
                }
            }
            if ( $tier_label !== '' ) {
                $heading = \sprintf(
                    /* translators: 1: event title, 2: ticket tier label. */
                    \__( '%1$s — %2$s', 'anchor-schema' ),
                    $line['event_title'],
                    $tier_label
                );
            }
            echo '<fieldset class="anchor-event-attendee-line" data-cart-item="' . \esc_attr( $cart_item_key ) . '">';
            echo '<legend>' . \esc_html( $heading ) . '</legend>';

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
     * Order lifecycle sync — declarative & idempotent reconcile (spec §7)
     * ------------------------------------------------------------------- */

    /**
     * Per-PROCESS re-entrancy guard keyed by order id, to avoid a self-re-entrant
     * double run within one request (e.g. status_changed + payment_complete in the
     * same process) and so the single end-of-pass $order->save() can't re-enter.
     * NOTE: this is per-PHP-process only — the per-event GET_LOCK in the data layer
     * is the real cross-process concurrency guard.
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
     * Map a WooCommerce order status (slug, no "wc-" prefix) to the seat status
     * for kept/created seats (spec §7.3). Returns null ONLY for the recognized
     * "pending" status — meaning there are NO active seats and any existing active
     * seats are swept. Terminal statuses (cancelled/refunded/failed) map to their
     * kept terminal seat status and force expected = 0. ANY unrecognized/custom
     * status (deposits, subscriptions, fulfillment, partial-payment, …) returns the
     * SEAT_TARGET_UNKNOWN sentinel so reconcile leaves seats untouched (finding M6).
     *
     * @param string $order_status
     * @return string|null
     */
    public function map_order_status_to_seat( $order_status ) {
        switch ( (string) $order_status ) {
            case 'processing':
            case 'completed':
                return Registrations::STATUS_CONFIRMED;
            case 'on-hold':
                return Registrations::STATUS_PENDING;
            case 'failed':
                return Registrations::STATUS_FAILED;
            case 'cancelled':
                return Registrations::STATUS_CANCELLED;
            case 'refunded':
                return Registrations::STATUS_REFUNDED;
            case 'pending':
                return null; // No active seats; sweep existing active → cancelled.
            default:
                // Unknown/custom status — leave seats exactly as-is (no expected=0).
                return self::SEAT_TARGET_UNKNOWN;
        }
    }

    /** Terminal seat statuses (kept, excluded from capacity, not revivable as a group). */
    private function terminal_seat_statuses() {
        return [ Registrations::STATUS_CANCELLED, Registrations::STATUS_REFUNDED, Registrations::STATUS_FAILED ];
    }

    /**
     * The single seat-mutation entry point: declarative & idempotent for ALL order
     * statuses, manual edits, and refunds. Computes the desired seat set per line
     * from the order's current state and converges existing seats toward it.
     * Re-firing is a no-op once converged. All order/item access is via WC CRUD
     * (HPOS-safe) — zero order/item postmeta.
     *
     * Save discipline (finding #3): per-line seat mutations happen via the data
     * layer; the per-order sync log + needs-review flags are accumulated locally
     * and written in ONE batched $order->save() at end of pass, inside the
     * in-flight guard. $order->save() is NEVER called inside the per-line loop.
     *
     * @param mixed  $order          WC_Order (handlers normalize before calling).
     * @param string $reason         Human reason for the sync log.
     * @param string $surplus_status Status applied to surplus active seats on a
     *                               non-terminal order ('cancelled' normally,
     *                               'refunded' from the refund path).
     * @param bool   $clear_review   Clear stale needs-review flags first (manual
     *                               resync) so a clean pass leaves none.
     * @param array  $seed_flags     Needs-review flags to thread into the single
     *                               batched save (finding M4 — e.g. the mixed-refund
     *                               extra-amount flag, so a separate stale-instance
     *                               save can't clobber it).
     */
    public function reconcile_order( $order, $reason = '', $surplus_status = Registrations::STATUS_CANCELLED, $clear_review = false, array $seed_flags = [] ) {
        if ( ! $order instanceof \WC_Order ) {
            return;
        }
        $order_id = (int) $order->get_id();
        if ( $order_id <= 0 ) {
            return;
        }
        // H2 — never touch (or write meta onto) orders with no event lines. These
        // hooks fire store-wide; a non-event order must be a complete no-op.
        if ( ! $this->order_has_event_lines( $order ) ) {
            return;
        }
        if ( isset( self::$in_flight[ $order_id ] ) ) {
            return; // Re-entrancy guard (per-process).
        }
        self::$in_flight[ $order_id ] = true;

        // Local accumulators flushed once at end of pass.
        $log_entries  = [];
        $review_flags = $seed_flags; // M4: seed threaded flags into the batched save.
        // Per-event seat-change tally for Phase 6 emails: [ event_id => [confirmed, waitlist, released] ].
        $email_events = [];

        try {
            $order_status  = $order->get_status(); // slug, no "wc-" prefix.
            $target        = $this->map_order_status_to_seat( $order_status );

            // M6 — unknown/custom status: leave every seat exactly as-is (do NOT
            // sweep, do NOT force expected=0). A true no-op for converged passes:
            // do NOT append a sync-log entry and do NOT save (writing a log entry
            // every pass would mark the order dirty and save even though nothing
            // changed). Just release the in-flight guard via the outer finally.
            if ( $target === self::SEAT_TARGET_UNKNOWN ) {
                return;
            }

            // Terminal ORDER statuses force expected = 0 (sweep all active seats).
            $terminal      = \in_array( $order_status, [ 'cancelled', 'refunded', 'failed' ], true );
            // Active status for kept/created seats (confirmed|pending) or null.
            $active_target = \in_array( $target, [ Registrations::STATUS_CONFIRMED, Registrations::STATUS_PENDING ], true )
                ? $target
                : null;
            // Status that surplus/swept seats move to: terminal → its mapped status;
            // otherwise the caller-supplied surplus status (cancelled / refunded).
            $removal_status = $terminal ? $target : (string) $surplus_status;

            $billing = [
                'name'        => \trim( (string) $order->get_formatted_billing_full_name() ),
                'email'       => (string) $order->get_billing_email(),
                'phone'       => (string) $order->get_billing_phone(),
                'customer_id' => (int) $order->get_customer_id(), // 0 = guest (spec §6.6).
            ];

            foreach ( $order->get_items() as $item_id => $item ) {
                $item_id = (int) $item_id;
                if ( $item_id <= 0 ) {
                    continue; // (0,1) wildcard guard (finding #11).
                }
                if ( ! $item instanceof \WC_Order_Item_Product ) {
                    continue;
                }

                $event_id = $this->resolve_event_for_item( $item );
                if ( $event_id <= 0 ) {
                    // Unmapped line (never linked / unlinked after purchase). Leave
                    // seats untouched. H2 — only log when the line actually has event
                    // EVIDENCE (a link snapshot or existing seats by order_item_id);
                    // ordinary non-event lines must produce no log churn.
                    if (
                        (int) $item->get_meta( '_anchor_event_id' ) > 0
                        || ! empty( $this->registrations->get_seats_for_order_item( $item_id ) )
                    ) {
                        $log_entries[] = $this->make_log_entry( 'Unmappable line left untouched.', [ 'item' => $item_id ] );
                    }
                    continue;
                }

                $this->reconcile_line(
                    $order,
                    $order_id,
                    $item,
                    $item_id,
                    $event_id,
                    $active_target,
                    $removal_status,
                    $billing,
                    (string) $reason,
                    $log_entries,
                    $review_flags,
                    $email_events
                );
            }

            // M5 — the per-event seat lock does NOT protect the order-level
            // EMAILS_SENT gate / sync log / review flags ($in_flight is per-process).
            // Serialize the email-gate check + end-of-pass meta write under a
            // per-ORDER MySQL named lock so a gateway IPN and an admin Resync racing
            // the same order can't double-send or drop a flag. Degrades gracefully
            // (runs unlocked) when GET_LOCK is unavailable.
            $have_lock = $this->acquire_order_lock( $order_id );
            try {
                // Under the lock, operate on a FRESH order instance so concurrent
                // log/flag/emails-sent writes aren't clobbered by our stale copy
                // (re-reads EMAILS_SENT_META immediately before sending).
                $save_order = ( $have_lock && ( $fresh = \wc_get_order( $order_id ) ) ) ? $fresh : $order;

                // Phase 6: send buyer/organizer emails for this pass's seat changes.
                // Appends to $log_entries/$review_flags + writes the emails-sent gate
                // onto $save_order; everything rides the SINGLE batched save below.
                $emails_dirty = $this->dispatch_emails( $save_order, $email_events, $log_entries, $review_flags );

                // Flush accumulators onto the order meta, then a SINGLE batched save —
                // only when something actually changed (H2: no-op passes never save).
                $logged  = $this->apply_order_log( $save_order, $log_entries );
                $flagged = $this->apply_review_flags( $save_order, $review_flags, (bool) $clear_review );

                if ( $logged || $flagged || $emails_dirty ) {
                    // L8 — a persistence error in a gateway/payment callback must not
                    // bubble up and abort checkout.
                    try {
                        $save_order->save();
                    } catch ( \Throwable $e ) {
                        Events_Log::error( 'reconcile_save_failed', [
                            'order'   => $order_id,
                            'message' => $e->getMessage(),
                        ] );
                    }
                }
            } finally {
                $this->release_order_lock( $order_id, $have_lock );
            }
        } finally {
            unset( self::$in_flight[ $order_id ] );
        }
    }

    /**
     * Acquire a per-order MySQL named lock (M5). Returns true on success, false if
     * the lock is held elsewhere or GET_LOCK is unavailable (degrade gracefully —
     * the caller proceeds unlocked rather than blocking checkout).
     *
     * @param int $order_id
     * @return bool
     */
    private function acquire_order_lock( $order_id ) {
        global $wpdb;
        if ( ! ( $wpdb instanceof \wpdb ) ) {
            return false;
        }
        $got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $this->order_lock_name( $order_id ), 5 ) );
        return '1' === (string) $got;
    }

    /**
     * Release the per-order named lock acquired by acquire_order_lock(). No-op when
     * the lock was never obtained.
     *
     * @param int  $order_id
     * @param bool $have_lock
     */
    private function release_order_lock( $order_id, $have_lock ) {
        if ( ! $have_lock ) {
            return;
        }
        global $wpdb;
        if ( ! ( $wpdb instanceof \wpdb ) ) {
            return;
        }
        $wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->order_lock_name( $order_id ) ) );
    }

    /** MySQL named-lock key for an order (≤64 chars). */
    private function order_lock_name( $order_id ) {
        return 'anchor_evt_order_' . (int) $order_id;
    }

    /**
     * Best-effort attendee copy for a WC Subscriptions renewal order (L11): pull
     * `_anchor_attendees` from the matching event line on the related
     * subscription(s) so a renewal recreates seats with the original attendees
     * rather than churning attendees_missing each cycle. Returns [] when nothing
     * usable is found. function_exists-guarded — inert without WC Subscriptions.
     *
     * @param int $order_id     Renewal order id.
     * @param int $event_id     Event the line resolves to.
     * @param int $product_id   Parent product id of the current line.
     * @param int $variation_id Variation id of the current line (0 = simple).
     * @return array Attendee payload keyed 1..n, or [].
     */
    private function copy_renewal_attendees( $order_id, $event_id, $product_id, $variation_id ) {
        if ( ! \function_exists( 'wcs_get_subscriptions_for_renewal_order' ) ) {
            return [];
        }
        $event_id     = (int) $event_id;
        $product_id   = (int) $product_id;
        $variation_id = (int) $variation_id;

        $subs = \wcs_get_subscriptions_for_renewal_order( (int) $order_id );
        if ( ! \is_array( $subs ) ) {
            return [];
        }
        foreach ( $subs as $sub ) {
            if ( ! $sub instanceof \WC_Order ) {
                continue;
            }
            foreach ( $sub->get_items() as $sub_item ) {
                if ( ! $sub_item instanceof \WC_Order_Item_Product ) {
                    continue;
                }
                // Match by event AND product (and variation when set) so two
                // subscription lines mapping to one event don't copy each other's
                // attendee payload (finding M-renewal-2).
                if ( $this->event_for_order_item( $sub_item ) !== $event_id ) {
                    continue;
                }
                if ( (int) $sub_item->get_product_id() !== $product_id ) {
                    continue;
                }
                if ( $variation_id > 0 && (int) $sub_item->get_variation_id() !== $variation_id ) {
                    continue;
                }
                $att = $sub_item->get_meta( '_anchor_attendees' );
                if ( \is_array( $att ) && ! empty( $att ) ) {
                    return $att;
                }
            }
        }
        return [];
    }

    /**
     * Reconcile a single order line item's seats toward the desired state. All
     * capacity-sensitive work (surplus release, flips, gap-fill revive/create)
     * runs inside the per-event GET_LOCK so a concurrent same-order webhook can't
     * duplicate seats (finding #9). Mutations go through the data layer; only the
     * local $log_entries / $review_flags accumulators are touched here (by ref) —
     * never $order->save().
     *
     * @param \WC_Order $order
     * @param int       $order_id
     * @param \WC_Order_Item_Product $item
     * @param int       $item_id
     * @param int       $event_id      Resolved (live) event for this line.
     * @param string|null $active_target confirmed|pending|null.
     * @param string    $removal_status Status surplus/swept seats move to.
     * @param array     $billing       name/email/phone/customer_id fallback.
     * @param string    $reason
     * @param array     $log_entries   (by ref)
     * @param array     $review_flags  (by ref)
     * @param array     $email_events  (by ref) per-event seat-change tally (Phase 6).
     */
    private function reconcile_line( $order, $order_id, $item, $item_id, $event_id, $active_target, $removal_status, array $billing, $reason, array &$log_entries, array &$review_flags, array &$email_events ) {
        // Expected active seat count for this line. Terminal/null target ⇒ 0.
        // Refund-safe: get_qty_refunded_for_item sign is version-dependent ⇒ abs()
        // (finding #1). Cumulative across all refunds ⇒ re-fire safe.
        if ( $active_target === null ) {
            $expected = 0;
        } else {
            $refunded = \abs( (int) $order->get_qty_refunded_for_item( $item_id ) );
            $expected = max( 0, (int) $item->get_quantity() - $refunded );
        }

        $meta             = $this->module->get_meta( $event_id );
        $capacity         = (int) ( $meta['capacity'] ?? 0 );
        $unlimited        = ( $capacity <= 0 );
        $waitlist_enabled = ! empty( $meta['waitlist'] );
        $terminal_set     = $this->terminal_seat_statuses();

        // Attendee data for creation. Whole-line absent on a paid line ⇒ flag, do
        // NOT billing-fill (finding #2/#17). Individual-entry-missing within a
        // present array ⇒ billing fallback with a history note (handled per seat).
        $attendees     = $item->get_meta( '_anchor_attendees' );
        $has_attendees = \is_array( $attendees ) && ! empty( $attendees );
        if ( ! \is_array( $attendees ) ) {
            $attendees = [];
        }

        // L11 — WC Subscriptions renewal orders never fire the checkout capture hook,
        // so they'd churn attendees_missing + recreate seats every billing cycle.
        // function_exists-guarded so non-Subscriptions sites are unaffected.
        $is_renewal = \function_exists( 'wcs_order_contains_renewal' ) && \wcs_order_contains_renewal( $order_id );

        if ( $expected > 0 && ! $has_attendees && $is_renewal ) {
            $copied = $this->copy_renewal_attendees( $order_id, $event_id, (int) $item->get_product_id(), (int) $item->get_variation_id() );
            if ( ! empty( $copied ) ) {
                $attendees     = $copied;
                $has_attendees = true;
                $log_entries[] = $this->make_log_entry( 'Renewal order — attendee data copied from subscription/parent.', [
                    'item'  => $item_id,
                    'event' => $event_id,
                ] );
            }
            // On copy FAILURE we deliberately do NOT short-circuit here: fall
            // through to the standard missing-attendee handling below so the
            // seat-less paid renewal is flagged needs-review + noted on the order
            // rather than silently skipped (finding M-renewal-3).
        }

        if ( $expected > 0 && ! $has_attendees ) {
            // M3 — paid line reached an active status with no captured attendees
            // (order-pay / admin-created order). Flag for review AND drop a visible
            // order note so staff see it on the order. Keep the no-seat behavior.
            $review_flags[] = $this->make_flag( 'attendees_missing', 'order item ' . $item_id );
            $log_entries[]  = $this->make_log_entry( 'Attendee data missing on event line; no seats created.', [
                'item'  => $item_id,
                'event' => $event_id,
            ] );
            // Surface ONE order note (until the flag is cleared) rather than spamming
            // a note on every reconcile pass (status_changed + payment_complete + …).
            $existing_flags = $order->get_meta( Events_Log::ORDER_REVIEW_META );
            $already_flagged = false;
            if ( \is_array( $existing_flags ) ) {
                foreach ( $existing_flags as $f ) {
                    if ( isset( $f['reason'] ) && 'attendees_missing' === $f['reason'] ) {
                        $already_flagged = true;
                        break;
                    }
                }
            }
            if ( ! $already_flagged ) {
                $order->add_order_note( \sprintf(
                    /* translators: 1: event title, 2: line item id. */
                    \__( 'Anchor Events: paid registration for "%1$s" (line #%2$d) has no attendee details — no seats were created. Add attendees from the event roster.', 'anchor-schema' ),
                    \get_the_title( $event_id ),
                    $item_id
                ) );
            }
        }
        $can_create = ( $expected > 0 && $has_attendees );

        $payload_base = [
            'order_id'      => $order_id,
            'order_item_id' => $item_id,
            'product_id'    => (int) $item->get_product_id(),
            'variation_id'  => (int) $item->get_variation_id(),
            'customer_id'   => (int) $billing['customer_id'],
        ];

        // P4 — resolve the ticket tier for this line once. Prefer the managed
        // variation's tier id; fall back to the event's primary tier id (covers a
        // null product_sync / unlinked-or-non-variation line / legacy data).
        $variation_id = (int) $item->get_variation_id();
        $tier_id      = '';
        if ( $this->module->product_sync && $variation_id > 0 ) {
            $resolved = $this->module->product_sync->tier_for_variation( $variation_id );
            if ( ! empty( $resolved['tier_id'] ) ) {
                $tier_id = (string) $resolved['tier_id'];
            }
        }
        if ( $tier_id === '' && $this->module->ticket_types ) {
            $tier_id = (string) $this->module->ticket_types->primary_id( $event_id );
        }
        if ( $tier_id === '' ) {
            $tier_id = 'primary';
        }

        // Per-tier quota (0/empty = bounded only by the event total). find() reads
        // event meta only; the authoritative reserved-for-tier count is taken FRESH
        // under the lock below.
        $tier        = ( $this->module->ticket_types ) ? $this->module->ticket_types->find( $event_id, $tier_id ) : null;
        $tier_quota  = $tier ? (int) ( $tier['quota'] ?? 0 ) : 0;

        $result = $this->registrations->with_event_lock( $event_id, function ( $locked ) use (
            $event_id, $item_id, $expected, $active_target, $removal_status, $capacity, $unlimited,
            $waitlist_enabled, $terminal_set, $attendees, $can_create, $billing, $payload_base, $order_id,
            $tier_id, $tier_quota
        ) {
            $created   = [];
            $revived   = [];
            $removed   = [];
            $flipped   = [];
            $overfill  = false;
            $moved_out = [];
            // L7 — count only removed seats that actually consumed capacity
            // (confirmed/pending); waitlist seats never did, so they must not be
            // reported as "seats released" to the organizer.
            $released_capacity = 0;
            // L12 — duplicate-seat prevention parity with the old data-layer contract.
            $dup_prevented = false;

            // L1 — map newly-CREATED seats to attendee payloads by a per-line
            // creation-sequence position (0,1,2…) over the present attendee entries
            // in seat-number order — NOT the absolute ++$max_index, which an inflated
            // cancelled-seat index would push past the 1..qty attendee keys, silently
            // overwriting captured attendee data with billing fallback.
            \ksort( $attendees );
            $attendee_seq = \array_values( $attendees );
            $create_seq   = 0;

            // Fresh seat snapshot for this item, under the lock.
            $all = [];
            foreach ( $this->registrations->get_seats_for_order_item( $item_id ) as $sid ) {
                $info = $this->registrations->get_seat_info( $sid );
                if ( $info ) {
                    $all[] = $info;
                }
            }

            // Highest existing seat_index across ANY status (finding #7 max+1 alloc).
            $max_index = 0;
            foreach ( $all as $info ) {
                if ( $info['seat_index'] > $max_index ) {
                    $max_index = $info['seat_index'];
                }
            }

            // VARIATION-CHANGE-IN-PLACE (finding #10): an existing seat whose event
            // != the resolved event is a mismatch → cancel it on its OLD event to
            // release that capacity; its index is vacated for the NEW event. Only
            // matching-event seats are reconciled below.
            $matching = [];
            foreach ( $all as $info ) {
                if ( $info['event_id'] !== $event_id ) {
                    if ( ! \in_array( $info['status'], $terminal_set, true ) ) {
                        $this->registrations->update_status( $info['id'], Registrations::STATUS_CANCELLED, 'variation changed — moved', 'woocommerce' );
                        $moved_out[] = $info['id'];
                    }
                    continue; // excluded from matching either way.
                }
                $matching[] = $info;
            }

            // Active matching seats (anything not terminal: confirmed/pending/waitlist).
            $active = \array_values( \array_filter( $matching, function ( $s ) use ( $terminal_set ) {
                return ! \in_array( $s['status'], $terminal_set, true );
            } ) );

            $diff = \count( $active ) - $expected;

            if ( $diff > 0 ) {
                // SURPLUS (finding #6): COUNT-based, newest-first by integer
                // seat_index DESC. Never threshold on seat_index > expected.
                \usort( $active, function ( $a, $b ) {
                    return $b['seat_index'] <=> $a['seat_index'];
                } );
                $to_remove = $diff;
                foreach ( $active as $s ) {
                    if ( $to_remove <= 0 ) {
                        break;
                    }
                    // Only consume a removal slot when the transition actually
                    // succeeds — otherwise a disallowed transition (e.g. a
                    // waitlist seat) would silently leave a surplus seat active
                    // while still decrementing the counter (CodeRabbit P2).
                    $was_capacity = \in_array( $s['status'], [ Registrations::STATUS_CONFIRMED, Registrations::STATUS_PENDING ], true );
                    if ( $this->registrations->update_status( $s['id'], $removal_status, 'order #' . $order_id . ' → ' . $removal_status, 'woocommerce' ) ) {
                        $removed[] = $s['id'];
                        if ( $was_capacity ) {
                            $released_capacity++; // L7 — only capacity-consuming seats.
                        }
                        $to_remove--;
                    }
                }
            } else {
                // Fresh recount under the lock — never the cache.
                $reserved  = $this->registrations->count_reserved_seats( $event_id, true );
                $remaining = $unlimited ? PHP_INT_MAX : max( 0, $capacity - $reserved );

                // P4 — per-tier remaining quota, recounted FRESH under the same lock
                // (folds in the old claim_woo_seats tier logic). quota<=0 ⇒ the tier
                // is bounded only by the event total.
                $tier_unlimited = ( $tier_quota <= 0 );
                $tier_left      = $tier_unlimited
                    ? PHP_INT_MAX
                    : max( 0, $tier_quota - $this->registrations->count_reserved_for_tier( $event_id, $tier_id, true ) );

                // DEFICIT: produce ($expected - active) more active seats by first
                // REVIVING cancelled/failed matching seats (finding #7), then
                // CREATING new ones at max+1. refunded seats are terminal and never
                // revived. All under the lock for capacity correctness.
                $deficit = $expected - \count( $active );
                // L1/finding-4 — number of seats that ALREADY occupy attendee slots
                // on this line before this pass creates any. New creates index their
                // fallback attendee ordinal AFTER these so an existing active seat
                // plus one new seat doesn't re-use attendee payload #1.
                $existing_active = \count( $active );
                if ( $deficit > 0 && $active_target !== null && $can_create ) {
                    $revivable = \array_values( \array_filter( $matching, function ( $s ) {
                        return \in_array( $s['status'], [ Registrations::STATUS_CANCELLED, Registrations::STATUS_FAILED ], true );
                    } ) );
                    \usort( $revivable, function ( $a, $b ) {
                        return $a['seat_index'] <=> $b['seat_index'];
                    } );

                    while ( $deficit > 0 ) {
                        $has_room = ( $unlimited || $remaining >= 1 );
                        if ( ! empty( $revivable ) ) {
                            $seat = \array_shift( $revivable );
                            // Revival can only go to confirmed/pending (transition
                            // table forbids cancelled→waitlist); flag overfill if no room.
                            if ( $this->registrations->update_status( $seat['id'], $active_target, 'order #' . $order_id . ' revived', 'woocommerce' ) ) {
                                $revived[] = $seat['id'];
                                if ( $has_room ) {
                                    $remaining--;
                                    // A revived seat re-consumes its tier's quota
                                    // (it already carries its tier from creation —
                                    // leave the tag as-is); keep the running tier
                                    // tally accurate for any new creates this pass.
                                    if ( ! $tier_unlimited && $tier_left > 0 ) {
                                        $tier_left--;
                                    }
                                } else {
                                    $overfill = true;
                                }
                            }
                        } else {
                            // CREATE a new seat at the next free index (max+1).
                            // P4 — decide against BOTH the event total and the tier
                            // quota (single authority, both recounted fresh above).
                            $event_has_room = ( $unlimited || $remaining >= 1 );
                            $tier_has_room  = ( $tier_unlimited || $tier_left >= 1 );
                            if ( $event_has_room && $tier_has_room ) {
                                // Both levels have room → confirmed/active; decrement both.
                                $status = $active_target;
                                $remaining--;
                                if ( ! $tier_unlimited ) {
                                    $tier_left--;
                                }
                            } elseif ( ! $event_has_room && $waitlist_enabled ) {
                                // EVENT total full + event waitlist toggle on →
                                // event-level waitlist (regardless of tier).
                                $status = Registrations::STATUS_WAITLIST;
                            } else {
                                // Either the event is full with no waitlist, OR the
                                // tier quota is exhausted while the event still has
                                // room (and we're not waitlisting): buyer paid but no
                                // tier seat can be created → leave uncreated and flag
                                // overfill so it surfaces in needs-review (spec §7/§9.3).
                                $overfill = true;
                                break;
                            }
                            // Seat-index identity stays max+1 (stable idempotency key).
                            $index = ++$max_index;
                            // L12 — assert no existing seat (any status) already
                            // occupies (order_item_id, seat_index) before creating, so
                            // a concurrent pass can't produce a duplicate. Skip + flag.
                            if ( $this->registrations->find_seat_by_item( $item_id, $index ) > 0 ) {
                                $dup_prevented = true;
                                break;
                            }
                            // L1/finding-4 — prefer the explicit attendee keyed by the
                            // NEW seat's index when present (so seat #2 gets attendee
                            // #2, not #1, even if the line already had an active seat).
                            // Otherwise fall back to the next ordinal AFTER the
                            // pre-existing active seats — never restart from 0 and
                            // never overwrite real attendee data with the wrong entry.
                            $att = null;
                            if ( isset( $attendees[ $index ] ) && \is_array( $attendees[ $index ] ) ) {
                                $att = $attendees[ $index ];
                            } else {
                                $seq_pos = $existing_active + $create_seq;
                                if ( isset( $attendee_seq[ $seq_pos ] ) && \is_array( $attendee_seq[ $seq_pos ] ) ) {
                                    $att = $attendee_seq[ $seq_pos ];
                                }
                            }
                            $create_seq++;
                            $name  = $att ? \sanitize_text_field( (string) ( $att['name'] ?? '' ) ) : '';
                            $email = $att ? \sanitize_email( (string) ( $att['email'] ?? '' ) ) : '';
                            $phone = $att ? \sanitize_text_field( (string) ( $att['phone'] ?? '' ) ) : '';
                            $note  = 'order #' . $order_id;
                            if ( $name === '' && $email === '' ) {
                                $name  = $billing['name'];
                                $email = $billing['email'];
                                $phone = $billing['phone'];
                                $note  = 'order #' . $order_id . ' (attendee data missing, used billing)';
                            }
                            $seat_id = $this->registrations->create_seat( \array_merge( $payload_base, [
                                'event_id'       => $event_id,
                                'status'         => $status,
                                'seat_index'     => $index,
                                'source'         => 'woocommerce',
                                'guests'         => 0,
                                'actor'          => 'woocommerce',
                                'name'           => $name,
                                'email'          => $email,
                                'phone'          => $phone,
                                'note'           => $note,
                                'ticket_type_id' => $tier_id, // P4 — tag seat with its tier.
                            ] ) );
                            if ( $seat_id ) {
                                $created[] = $seat_id;
                            }
                        }
                        $deficit--;
                    }
                }
            }

            // L5 — flip surviving active seats pending → confirmed UNCONDITIONALLY
            // (independent of the surplus/deficit branch) so a converged pass on a
            // completed order never leaves survivors 'pending' (and skips the
            // confirmation email). Surplus-removed seats are excluded; created/revived
            // seats were already placed at the active target. Skip waitlist (no MVP
            // auto-promotion) and same-status (no history spam).
            if ( $active_target === Registrations::STATUS_CONFIRMED ) {
                foreach ( $active as $s ) {
                    if ( \in_array( $s['id'], $removed, true ) ) {
                        continue; // Was surplus-released this pass.
                    }
                    if ( $s['status'] === Registrations::STATUS_PENDING ) {
                        if ( $this->registrations->update_status( $s['id'], Registrations::STATUS_CONFIRMED, 'order #' . $order_id . ' confirmed', 'woocommerce' ) ) {
                            $flipped[] = $s['id'];
                        }
                    }
                }
            }

            return [
                'created'           => $created,
                'revived'           => $revived,
                'removed'           => $removed,
                'flipped'           => $flipped,
                'moved_out'         => $moved_out,
                'overfill'          => $overfill,
                'released_capacity' => $released_capacity,
                'dup_prevented'     => $dup_prevented,
                'lock_unavailable'  => ! $locked,
            ];
        } );

        // Translate the locked result into sync-log entries + needs-review flags.
        $changed = \count( $result['created'] ) + \count( $result['revived'] )
            + \count( $result['removed'] ) + \count( $result['flipped'] ) + \count( $result['moved_out'] );
        if ( $changed > 0 ) {
            $log_entries[] = $this->make_log_entry(
                \sprintf( 'Reconciled line #%d for event #%d (%s).', $item_id, $event_id, $reason !== '' ? $reason : 'sync' ),
                [
                    'created'   => $result['created'],
                    'revived'   => $result['revived'],
                    'removed'   => $result['removed'],
                    'flipped'   => $result['flipped'],
                    'moved_out' => $result['moved_out'],
                    'expected'  => $expected,
                ]
            );
        }
        if ( ! empty( $result['overfill'] ) ) {
            $review_flags[] = $this->make_flag( 'capacity_overfill', 'event ' . $event_id . ' item ' . $item_id );
        }
        if ( ! empty( $result['dup_prevented'] ) ) {
            $review_flags[] = $this->make_flag( 'duplicate_seat_prevented', 'event ' . $event_id . ' item ' . $item_id );
        }
        if ( ! empty( $result['lock_unavailable'] ) && ( ! empty( $result['created'] ) || ! empty( $result['revived'] ) ) ) {
            $review_flags[] = $this->make_flag( 'capacity_lock_unavailable', 'event ' . $event_id );
        }

        // Phase 6: tally this pass's seat changes per event so reconcile_order can
        // fire the right emails (trigger matrix §11.4). Newly-active seats are
        // categorized confirmed vs waitlist by their resulting status; pending
        // (on-hold) seats trigger no email. Releases count only when the surplus
        // status is cancelled/refunded (failed never emails).
        $confirmed_new = 0;
        $waitlist_new  = 0;
        foreach ( \array_merge( $result['created'], $result['revived'], $result['flipped'] ) as $sid ) {
            $st = (string) \get_post_meta( (int) $sid, '_anchor_event_reg_status', true );
            if ( $st === Registrations::STATUS_CONFIRMED ) {
                $confirmed_new++;
            } elseif ( $st === Registrations::STATUS_WAITLIST ) {
                $waitlist_new++;
            }
        }
        $released_new = \in_array( $removal_status, [ Registrations::STATUS_CANCELLED, Registrations::STATUS_REFUNDED ], true )
            ? (int) $result['released_capacity'] // L7 — capacity-consuming seats only.
            : 0;

        if ( $confirmed_new || $waitlist_new || $released_new ) {
            if ( ! isset( $email_events[ $event_id ] ) ) {
                $email_events[ $event_id ] = [ 'confirmed' => 0, 'waitlist' => 0, 'released' => 0 ];
            }
            $email_events[ $event_id ]['confirmed'] += $confirmed_new;
            $email_events[ $event_id ]['waitlist']  += $waitlist_new;
            $email_events[ $event_id ]['released']  += $released_new;
        }
    }

    /* ---------------------------------------------------------------------
     * Sync-log / needs-review accumulation (batched onto a single save)
     * ------------------------------------------------------------------- */

    /** Build a sync-log entry in the Events_Log::order() shape. */
    private function make_log_entry( $message, array $context = [] ) {
        return [
            'time'    => \time(),
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /** Build a needs-review flag in the Events_Log::flag_review() shape. */
    private function make_flag( $reason, $detail = '' ) {
        return [
            'reason' => (string) $reason,
            'detail' => (string) $detail,
            'time'   => \time(),
        ];
    }

    /**
     * Append accumulated sync-log entries to the order's capped ring buffer. Does
     * NOT save — the caller batches a single $order->save() at end of pass.
     *
     * @return bool Whether any meta was modified (drives the dirty-flag save — H2).
     */
    private function apply_order_log( \WC_Order $order, array $entries ) {
        if ( empty( $entries ) ) {
            return false;
        }
        $log = $order->get_meta( Events_Log::ORDER_LOG_META );
        if ( ! \is_array( $log ) ) {
            $log = [];
        }
        foreach ( $entries as $e ) {
            $log[] = $e;
        }
        if ( \count( $log ) > Events_Log::ORDER_LOG_CAP ) {
            $log = \array_slice( $log, -Events_Log::ORDER_LOG_CAP );
        }
        $order->update_meta_data( Events_Log::ORDER_LOG_META, $log );
        return true;
    }

    /**
     * Merge accumulated needs-review flags (deduped by reason). When $clear is true
     * (manual resync) the existing flags are dropped first so a clean pass leaves
     * none and only genuinely-still-failing reasons are re-added. Does NOT save.
     *
     * @return bool Whether the review meta changed (drives the dirty-flag save — H2).
     */
    private function apply_review_flags( \WC_Order $order, array $flags, $clear ) {
        $had      = $order->get_meta( Events_Log::ORDER_REVIEW_META );
        $had      = \is_array( $had ) ? $had : [];
        $existing = $clear ? [] : $had;
        foreach ( $flags as $flag ) {
            $dupe = false;
            foreach ( $existing as $e ) {
                if ( isset( $e['reason'] ) && $e['reason'] === $flag['reason'] ) {
                    $dupe = true;
                    break;
                }
            }
            if ( ! $dupe ) {
                $existing[] = $flag;
            }
        }
        if ( $existing === $had ) {
            return false; // No net change — leave the order clean (no save).
        }
        // L10 — review presence changed; invalidate the cached notice count.
        \delete_transient( self::NEEDS_REVIEW_TRANSIENT );
        if ( empty( $existing ) ) {
            $order->delete_meta_data( Events_Log::ORDER_REVIEW_META );
            return true;
        }
        $order->update_meta_data( Events_Log::ORDER_REVIEW_META, $existing );
        return true;
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

    /**
     * Resolve the LIVE event for a line item (variation link → else product link →
     * else 0). Unlike event_for_order_item() this deliberately does NOT prefer the
     * persisted snapshot, so a variation change in place is detectable as a
     * seat-event mismatch (finding #10). A line resolving to 0 (never linked /
     * unlinked after purchase) is left untouched by reconcile (spec §7.4).
     *
     * @param mixed $item
     * @return int
     */
    private function resolve_event_for_item( $item ) {
        if ( ! $item instanceof \WC_Order_Item_Product ) {
            return 0;
        }
        $event_id = $this->event_for_line( (int) $item->get_product_id(), (int) $item->get_variation_id() );
        if ( $event_id > 0 ) {
            return $event_id;
        }
        // Live resolution failed (product/variation unlinked after purchase). Fall
        // back to the checkout link snapshot persisted on the line item so a later
        // cancel/refund still reconciles the original event and releases its
        // capacity instead of silently skipping the line (CodeRabbit P1).
        $snapshot = (int) $item->get_meta( '_anchor_event_id' );
        if ( $snapshot > 0 && \get_post_type( $snapshot ) === Module::CPT && \get_post_status( $snapshot ) !== 'trash' ) {
            return $snapshot; // L3 — never create seats against a trashed event.
        }
        return 0;
    }

    /* ---------------------------------------------------------------------
     * Order-edit / trash / delete / resync handlers (spec §7.6–7.8)
     * ------------------------------------------------------------------- */

    /**
     * woocommerce_saved_order_items( int $order_id, array $items ) — manual edits
     * (add line, qty ±, remove line) converge through the same reconcile.
     *
     * @param int   $order_id
     * @param array $items
     */
    public function on_saved_order_items( $order_id, $items = [] ) {
        $order = \wc_get_order( (int) $order_id );
        if ( ! $order ) {
            return;
        }
        $this->reconcile_order( $order, 'order items saved' );
    }

    /**
     * woocommerce_before_delete_order_item( int $item_id ) — the arg is an INT item
     * id, NOT an item object (finding #14). Cancel that item's non-terminal seats
     * by _anchor_event_order_item_id (they can't be resolved after deletion).
     *
     * @param int $item_id
     */
    public function on_delete_order_item( $item_id ) {
        $item_id = (int) $item_id;
        if ( $item_id <= 0 ) {
            return;
        }
        foreach ( $this->registrations->get_seats_for_order_item( $item_id ) as $sid ) {
            $info = $this->registrations->get_seat_info( $sid );
            if ( ! $info || \in_array( $info['status'], $this->terminal_seat_statuses(), true ) ) {
                continue;
            }
            $this->registrations->update_status( $sid, Registrations::STATUS_CANCELLED, 'line item removed', 'woocommerce' );
        }
    }

    /**
     * Order trash / permanent delete (HPOS). Hooks:
     *  - woocommerce_before_trash_order( int $order_id )
     *  - woocommerce_trash_order( int $order_id )
     *  - woocommerce_before_delete_order( int $order_id )
     * Release capacity BEFORE the order disappears (finding #8).
     *
     * @param int $order_id
     */
    public function on_order_trashed_or_deleted( $order_id ) {
        $this->release_order_capacity( (int) $order_id, 'order trashed/deleted' );
    }

    /**
     * before_delete_post( int $post_id ) — legacy (posts) order storage only.
     * Guard the post type since this fires for every post type.
     *
     * @param int $post_id
     */
    public function on_legacy_order_deleted( $post_id ) {
        $post_id = (int) $post_id;
        if ( \get_post_type( $post_id ) !== 'shop_order' ) {
            return;
        }
        $this->release_order_capacity( $post_id, 'order deleted' );
    }

    /**
     * Cancel every non-terminal seat belonging to an order (found by
     * _anchor_event_order_id while the id is still known), releasing capacity.
     * No order save here — the order is being trashed/deleted.
     *
     * @param int    $order_id
     * @param string $note
     */
    private function release_order_capacity( $order_id, $note ) {
        $order_id = (int) $order_id;
        if ( $order_id <= 0 ) {
            return;
        }
        foreach ( $this->registrations->get_seats_for_order( $order_id ) as $sid ) {
            $info = $this->registrations->get_seat_info( $sid );
            if ( ! $info || \in_array( $info['status'], $this->terminal_seat_statuses(), true ) ) {
                continue;
            }
            $this->registrations->update_status( $sid, Registrations::STATUS_CANCELLED, $note, 'woocommerce' );
        }
    }

    /**
     * admin-post handler for the manual "Resync order" button. Caps to
     * edit_others_posts, verifies the per-order nonce, clears stale needs-review on
     * a clean pass, runs the identical reconcile, and redirects back.
     */
    public function handle_resync_order() {
        $order_id = isset( $_POST['order_id'] ) ? (int) $_POST['order_id'] : 0;
        if ( ! \current_user_can( 'edit_others_posts' ) ) {
            \wp_die( \esc_html__( 'You are not allowed to resync this order.', 'anchor-schema' ) );
        }
        \check_admin_referer( 'anchor_event_resync_' . $order_id );

        $order = \wc_get_order( $order_id );
        if ( $order ) {
            // surplus_status = cancelled; clear_review = true (clean pass clears stale flags).
            $this->reconcile_order( $order, 'manual resync', Registrations::STATUS_CANCELLED, true );
        }

        $redirect = \wp_get_referer();
        if ( ! $redirect ) {
            $redirect = \admin_url();
        }
        \wp_safe_redirect( \add_query_arg( 'anchor_event_resynced', '1', $redirect ) );
        exit;
    }

    /* ---------------------------------------------------------------------
     * Phase 6 — registration emails (from the confirmed sync path only)
     * ------------------------------------------------------------------- */

    /**
     * Send Phase 6 emails for the seat changes accumulated during a reconcile pass.
     * Customer confirmation is gated PER EVENT (emails_sent['customer:{id}'] — L6) so
     * a later-added event line still confirms while partial refunds never re-spam;
     * organizer notices are one per order per event (gated by
     * emails_sent['organizer:{id}']) plus a seats-released notice when the pass
     * released seats (naturally idempotent — a converged re-fire releases nothing).
     *
     * Appends to $log_entries / $review_flags (by ref) and writes the emails-sent
     * gate via $order->update_meta_data — it does NOT save (the caller batches one
     * $order->save() at end of pass, inside the in-flight guard — finding #3).
     *
     * @param \WC_Order $order
     * @param array     $email_events [ event_id => [confirmed,waitlist,released] ].
     * @param array     $log_entries  (by ref)
     * @param array     $review_flags (by ref)
     * @return bool Whether the EMAILS_SENT gate was modified (dirty-flag save — H2).
     */
    private function dispatch_emails( \WC_Order $order, array $email_events, array &$log_entries, array &$review_flags ) {
        if ( empty( $email_events ) ) {
            return false;
        }

        $settings         = $this->module->get_settings();
        $notify_customer  = ! empty( $settings['wc_notify_customer'] );
        $notify_organizer = ! empty( $settings['wc_notify_organizer'] );
        $order_id         = (int) $order->get_id();

        // M5 — re-read the gate from THIS (fresh, under-lock) order instance right
        // before sending so a concurrent reconcile can't double-send.
        $sent = $order->get_meta( self::EMAILS_SENT_META );
        if ( ! \is_array( $sent ) ) {
            $sent = [];
        }
        $before = $sent;

        // Did any event gain newly-active (confirmed/waitlist) seats this pass?
        $has_new_active = false;
        foreach ( $email_events as $ev ) {
            if ( ! empty( $ev['confirmed'] ) || ! empty( $ev['waitlist'] ) ) {
                $has_new_active = true;
                break;
            }
        }

        // Customer confirmation — gated PER EVENT (L6) so a second event line added
        // by a later order edit still gets a confirmation, while partial refunds
        // (no new active seats) never re-spam. The email itself lists all current
        // active seats; we mark every event covered this pass as confirmed.
        if ( $notify_customer && $has_new_active ) {
            $uncovered = false;
            foreach ( $email_events as $eid => $ev ) {
                if ( ( ! empty( $ev['confirmed'] ) || ! empty( $ev['waitlist'] ) ) && empty( $sent[ 'customer:' . (int) $eid ] ) ) {
                    $uncovered = true;
                    break;
                }
            }
            if ( $uncovered ) {
                if ( $this->send_customer_confirmation( $order, $settings ) ) {
                    $now = \time();
                    foreach ( $email_events as $eid => $ev ) {
                        if ( ! empty( $ev['confirmed'] ) || ! empty( $ev['waitlist'] ) ) {
                            $sent[ 'customer:' . (int) $eid ] = $now;
                        }
                    }
                    $log_entries[] = $this->make_log_entry( 'Customer confirmation email sent.', [ 'to' => $order->get_billing_email() ] );
                } else {
                    $review_flags[] = $this->make_flag( 'customer_email_failed', 'order #' . $order_id );
                    $log_entries[]  = $this->make_log_entry( 'Customer confirmation email FAILED.', [ 'to' => $order->get_billing_email() ] );
                }
            }
        }

        // Organizer notices — per event.
        foreach ( $email_events as $event_id => $ev ) {
            $event_id    = (int) $event_id;
            $has_confirm = ( ! empty( $ev['confirmed'] ) || ! empty( $ev['waitlist'] ) );
            $has_release = ! empty( $ev['released'] );
            $gate_key    = 'organizer:' . $event_id;

            if ( $notify_organizer && $has_confirm && empty( $sent[ $gate_key ] ) ) {
                if ( $this->send_organizer_notice( $order, $settings, $event_id, $ev, 'confirmed' ) ) {
                    $sent[ $gate_key ] = \time();
                    $log_entries[]     = $this->make_log_entry( 'Organizer notice sent.', [ 'event' => $event_id ] );
                } else {
                    $log_entries[] = $this->make_log_entry( 'Organizer notice FAILED.', [ 'event' => $event_id ] );
                }
            }

            // Seats-released organizer notice (cancelled/refunded). Not gated by
            // emails_sent: idempotent because reconcile only releases seats once.
            if ( $notify_organizer && $has_release ) {
                if ( $this->send_organizer_notice( $order, $settings, $event_id, $ev, 'released' ) ) {
                    $log_entries[] = $this->make_log_entry( 'Organizer seats-released notice sent.', [ 'event' => $event_id, 'released' => (int) $ev['released'] ] );
                } else {
                    $log_entries[] = $this->make_log_entry( 'Organizer seats-released notice FAILED.', [ 'event' => $event_id ] );
                }
            }
        }

        if ( $sent === $before ) {
            return false; // No gate change — nothing to persist.
        }
        $order->update_meta_data( self::EMAILS_SENT_META, $sent );
        return true;
    }

    /**
     * Current non-terminal (confirmed/pending/waitlist) seats for an order, grouped
     * by event id. Reads SEAT post meta (REG_CPT posts — not the order), so it is
     * HPOS-safe; no order postmeta is touched.
     *
     * @param int $order_id
     * @return array<int,array<int,array>> [ event_id => [ {id,name,status,seat_index} ] ].
     */
    private function collect_order_seats( $order_id ) {
        $by_event = [];
        $active   = [ Registrations::STATUS_CONFIRMED, Registrations::STATUS_PENDING, Registrations::STATUS_WAITLIST ];
        foreach ( $this->registrations->get_seats_for_order( (int) $order_id ) as $sid ) {
            $sid    = (int) $sid;
            $status = (string) \get_post_meta( $sid, '_anchor_event_reg_status', true );
            if ( $status === '' ) {
                $status = Registrations::STATUS_CONFIRMED;
            }
            if ( ! \in_array( $status, $active, true ) ) {
                continue;
            }
            $event_id              = (int) \get_post_meta( $sid, '_anchor_event_id', true );
            $by_event[ $event_id ][] = [
                'id'         => $sid,
                'name'       => (string) \get_post_meta( $sid, '_anchor_event_name', true ),
                'status'     => $status,
                'seat_index' => (int) \get_post_meta( $sid, '_anchor_event_seat_index', true ),
            ];
        }
        return $by_event;
    }

    /**
     * Build + send the one-per-order buyer confirmation listing all current active
     * seats across every event line. Returns the send result (false → caller flags
     * customer_email_failed). Also used by the manual "Resend confirmation" button.
     *
     * @param \WC_Order $order
     * @param array     $settings Module settings.
     * @return bool
     */
    private function send_customer_confirmation( \WC_Order $order, array $settings ) {
        $to = (string) $order->get_billing_email();
        if ( $to === '' ) {
            return false;
        }
        $order_id = (int) $order->get_id();
        $buyer    = \trim( (string) $order->get_formatted_billing_full_name() );
        $by_event = $this->collect_order_seats( $order_id );

        $seat_list    = [];
        $detail_rows  = [];
        $any_waitlist = false;
        $total_seats  = 0;
        $primary_id   = 0;
        foreach ( $by_event as $event_id => $seats ) {
            $event_id = (int) $event_id;
            if ( $primary_id === 0 ) {
                $primary_id = $event_id;
            }
            $count         = \count( $seats );
            $detail_rows[] = [
                'label' => \get_the_title( $event_id ),
                'value' => \sprintf( \_n( '%d seat', '%d seats', $count, 'anchor-schema' ), $count ),
            ];
            foreach ( $seats as $s ) {
                $label = $s['name'] !== '' ? $s['name'] : \__( 'Guest', 'anchor-schema' );
                if ( $s['status'] === Registrations::STATUS_WAITLIST ) {
                    $any_waitlist = true;
                    $label       .= ' — ' . \__( 'waitlisted', 'anchor-schema' );
                }
                $seat_list[] = \sprintf( '%s (%s)', $label, \get_the_title( $event_id ) );
                $total_seats++;
            }
        }
        if ( $total_seats === 0 ) {
            return true; // Nothing active to confirm — treat as a no-op success.
        }

        $tokens = [
            'site_name'    => \get_bloginfo( 'name' ),
            'buyer_name'   => $buyer,
            'order_number' => $order->get_order_number(),
            'seat_count'   => $total_seats,
            'event_title'  => $primary_id ? \get_the_title( $primary_id ) : '',
        ];
        $subject = $this->module->expand_email_tokens(
            $settings['wc_customer_subject'] !== '' ? $settings['wc_customer_subject'] : \__( 'Your event registration is confirmed', 'anchor-schema' ),
            $tokens
        );
        $intro = $this->module->expand_email_tokens(
            $settings['wc_customer_intro'] !== '' ? $settings['wc_customer_intro'] : \__( 'Thank you for your order. Your registration is confirmed — the details are below.', 'anchor-schema' ),
            $tokens
        );

        $ctx = [
            'event_id'      => $primary_id,
            'name'          => $buyer,
            'status'        => $any_waitlist ? Registrations::STATUS_WAITLIST : Registrations::STATUS_CONFIRMED,
            'intro_message' => $intro,
            'guests'        => 0,
            'detail_rows'   => $detail_rows,
            'seat_list'     => $seat_list,
            'cta_label'     => \__( 'View event details', 'anchor-schema' ),
            'cta_url'       => $primary_id ? \get_permalink( $primary_id ) : \home_url(),
        ];
        $html = $this->module->build_registration_email_html( $ctx );
        return $this->module->send_html_email( $to, $subject, $html );
    }

    /**
     * Build + send an organizer notice for one event. $kind = 'confirmed' (new
     * seats) or 'released' (seats cancelled/refunded). Recipient resolves
     * per-event organizer email → global setting → admin_email.
     *
     * @param \WC_Order $order
     * @param array     $settings
     * @param int       $event_id
     * @param array     $ev       [confirmed,waitlist,released] tally for this event.
     * @param string    $kind     'confirmed'|'released'.
     * @return bool
     */
    private function send_organizer_notice( \WC_Order $order, array $settings, $event_id, array $ev, $kind ) {
        $event_id = (int) $event_id;
        $to       = $this->organizer_recipient( $event_id, $settings );
        if ( $to === '' ) {
            return false;
        }
        $buyer    = \trim( (string) $order->get_formatted_billing_full_name() );
        if ( $buyer === '' ) {
            $buyer = (string) $order->get_billing_email();
        }
        $title    = \get_the_title( $event_id );
        $summary  = $this->registrations->get_event_summary( $event_id );
        $remaining = ( isset( $summary['remaining'] ) && (int) $summary['remaining'] >= 0 )
            ? (string) (int) $summary['remaining']
            : \__( 'unlimited', 'anchor-schema' );

        if ( $kind === 'released' ) {
            $released = (int) $ev['released'];
            $subject  = \sprintf( \__( 'Seats released: %s', 'anchor-schema' ), $title );
            $intro    = \sprintf(
                \_n( '%1$d seat was released for "%2$s" (order #%3$s).', '%1$d seats were released for "%2$s" (order #%3$s).', $released, 'anchor-schema' ),
                $released,
                $title,
                $order->get_order_number()
            );
            $status_ctx = Registrations::STATUS_CANCELLED;
        } else {
            $tokens = [
                'event_title'     => $title,
                'site_name'       => \get_bloginfo( 'name' ),
                'order_number'    => $order->get_order_number(),
                'buyer_name'      => $buyer,
                'remaining_seats' => $remaining,
            ];
            $subject = $this->module->expand_email_tokens(
                $settings['wc_organizer_subject'] !== '' ? $settings['wc_organizer_subject'] : \__( 'New event registration: {event_title}', 'anchor-schema' ),
                $tokens
            );
            $confirmed = (int) $ev['confirmed'];
            $waitlist  = (int) $ev['waitlist'];
            $parts     = [];
            if ( $confirmed > 0 ) {
                $parts[] = \sprintf( \_n( '%d confirmed seat', '%d confirmed seats', $confirmed, 'anchor-schema' ), $confirmed );
            }
            if ( $waitlist > 0 ) {
                $parts[] = \sprintf( \_n( '%d waitlisted seat', '%d waitlisted seats', $waitlist, 'anchor-schema' ), $waitlist );
            }
            $intro = \sprintf(
                \__( 'Order #%1$s from %2$s created %3$s for "%4$s".', 'anchor-schema' ),
                $order->get_order_number(),
                $buyer,
                $parts ? \implode( ' + ', $parts ) : \__( 'new registrations', 'anchor-schema' ),
                $title
            );
            $status_ctx = $waitlist > 0 ? Registrations::STATUS_WAITLIST : Registrations::STATUS_CONFIRMED;
        }

        // Per-event seat list from current active seats.
        $seat_list = [];
        $by_event  = $this->collect_order_seats( (int) $order->get_id() );
        if ( isset( $by_event[ $event_id ] ) ) {
            foreach ( $by_event[ $event_id ] as $s ) {
                $label = $s['name'] !== '' ? $s['name'] : \__( 'Guest', 'anchor-schema' );
                if ( $s['status'] === Registrations::STATUS_WAITLIST ) {
                    $label .= ' — ' . \__( 'waitlisted', 'anchor-schema' );
                }
                $seat_list[] = $label;
            }
        }

        $detail_rows = [
            [ 'label' => \__( 'Order', 'anchor-schema' ), 'value' => '#' . $order->get_order_number() ],
            [ 'label' => \__( 'Buyer', 'anchor-schema' ), 'value' => $buyer ],
            [ 'label' => \__( 'Remaining capacity', 'anchor-schema' ), 'value' => $remaining ],
        ];

        $ctx = [
            'event_id'      => $event_id,
            'name'          => '',
            'status'        => $status_ctx,
            'intro_message' => $intro,
            'guests'        => 0,
            'detail_rows'   => $detail_rows,
            'seat_list'     => $seat_list,
            'cta_label'     => \__( 'View event', 'anchor-schema' ),
            'cta_url'       => \get_permalink( $event_id ),
        ];
        $html = $this->module->build_registration_email_html( $ctx );
        return $this->module->send_html_email( $to, $subject, $html );
    }

    /**
     * Resolve the organizer recipient for an event: per-event organizer_email →
     * global organizer_email setting → site admin_email.
     *
     * @param int   $event_id
     * @param array $settings
     * @return string
     */
    private function organizer_recipient( $event_id, array $settings ) {
        $meta  = $this->module->get_meta( (int) $event_id );
        $email = '';
        if ( ! empty( $meta['organizer_email'] ) ) {
            $email = \sanitize_email( (string) $meta['organizer_email'] );
        }
        if ( $email === '' && ! empty( $settings['organizer_email'] ) ) {
            $email = \sanitize_email( (string) $settings['organizer_email'] );
        }
        if ( $email === '' ) {
            $email = \sanitize_email( (string) \get_option( 'admin_email' ) );
        }
        return (string) $email;
    }

    /* ---------------------------------------------------------------------
     * Phase 6 — needs-review admin notices + manual order actions
     * ------------------------------------------------------------------- */

    /**
     * admin_notices: surface a summary of orders flagged needs-review on the Events
     * list, the WooCommerce Orders screen, and the Events settings tab. Flagged
     * orders are queried HPOS-safe via wc_get_orders + meta_query EXISTS (NOT the
     * unsupported bare 'meta_key' shorthand — finding #15), function_exists-guarded.
     */
    public function render_needs_review_notice() {
        if ( ! \function_exists( 'wc_get_orders' ) || ! \current_user_can( 'edit_others_posts' ) ) {
            return;
        }
        $screen = \get_current_screen();
        if ( ! $screen ) {
            return;
        }
        // L10 — only the Events settings TAB is relevant, not every anchor-schema
        // tab. (phpcs: read-only screen routing; nonce not applicable.)
        $on_events_tab = isset( $_GET['page'], $_GET['tab'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            && 'anchor-schema' === $_GET['page'] // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            && 'events' === \sanitize_key( \wp_unslash( $_GET['tab'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $relevant = ( $screen->id === 'edit-' . Module::CPT )
            || $screen->id === 'edit-shop_order'
            || \strpos( (string) $screen->id, 'wc-orders' ) !== false
            || $on_events_tab;
        if ( ! $relevant ) {
            return;
        }

        // L10 — cache presence/count in a short transient (busted on clear) instead
        // of running an uncapped EXISTS scan on every relevant admin load. The scan
        // is also capped to keep it bounded; counts above the cap render as "N+".
        $cached = \get_transient( self::NEEDS_REVIEW_TRANSIENT );
        if ( \is_array( $cached ) ) {
            $count   = (int) ( $cached['count'] ?? 0 );
            $first   = (int) ( $cached['first'] ?? 0 );
            $capped  = ! empty( $cached['capped'] );
        } else {
            $cap = 50;
            $ids = \wc_get_orders( [
                'limit'      => $cap + 1,
                'return'     => 'ids',
                'meta_query' => [
                    [
                        'key'     => Events_Log::ORDER_REVIEW_META,
                        'compare' => 'EXISTS',
                    ],
                ],
            ] );
            $ids    = \is_array( $ids ) ? $ids : [];
            $capped = \count( $ids ) > $cap;
            $count  = \count( $ids );
            $first  = $count > 0 ? (int) $ids[0] : 0;
            \set_transient( self::NEEDS_REVIEW_TRANSIENT, [
                'count'  => $count,
                'first'  => $first,
                'capped' => $capped,
            ], 5 * \MINUTE_IN_SECONDS );
        }
        if ( $count <= 0 ) {
            return;
        }

        echo '<div class="notice notice-warning"><p><strong>' . \esc_html__( 'Anchor Events:', 'anchor-schema' ) . '</strong> ';
        if ( $capped ) {
            echo \esc_html( \sprintf(
                /* translators: %d: capped order count. */
                \__( '%d+ orders need review for event registrations.', 'anchor-schema' ),
                $count
            ) );
        } else {
            echo \esc_html( \sprintf(
                \_n( '%d order needs review for event registrations.', '%d orders need review for event registrations.', $count, 'anchor-schema' ),
                $count
            ) );
        }
        if ( $count === 1 && $first > 0 ) {
            $url = $this->order_edit_url( $first );
            if ( $url !== '' ) {
                echo ' <a href="' . \esc_url( $url ) . '">' . \esc_html__( 'Review order', 'anchor-schema' ) . '</a>';
            }
        }
        echo '</p></div>';
    }

    /**
     * admin-post: clear all needs-review flags from an order ("Mark reviewed").
     * Cap edit_others_posts + per-order nonce.
     */
    public function handle_clear_review() {
        $order_id = isset( $_POST['order_id'] ) ? (int) $_POST['order_id'] : 0;
        if ( ! \current_user_can( 'edit_others_posts' ) ) {
            \wp_die( \esc_html__( 'You are not allowed to do this.', 'anchor-schema' ) );
        }
        \check_admin_referer( 'anchor_events_clear_review_' . $order_id );
        Events_Log::clear_review( $order_id );
        \delete_transient( self::NEEDS_REVIEW_TRANSIENT ); // L10 — refresh the notice.
        $this->redirect_back();
    }

    /**
     * admin-post: re-send the buyer confirmation for an order ("Resend
     * confirmation"). Re-sends from the order's current active seats and marks the
     * per-event customer emails-sent gates (emails_sent['customer:'.$event_id]) so a
     * later reconcile won't auto-send another confirmation for an already-covered
     * event. Cap edit_others_posts + per-order nonce.
     */
    public function handle_resend_confirmation() {
        $order_id = isset( $_POST['order_id'] ) ? (int) $_POST['order_id'] : 0;
        if ( ! \current_user_can( 'edit_others_posts' ) ) {
            \wp_die( \esc_html__( 'You are not allowed to do this.', 'anchor-schema' ) );
        }
        \check_admin_referer( 'anchor_events_resend_' . $order_id );

        $order = \wc_get_order( $order_id );
        if ( $order ) {
            $settings = $this->module->get_settings();
            $ok       = $this->send_customer_confirmation( $order, $settings );

            $sent = $order->get_meta( self::EMAILS_SENT_META );
            if ( ! \is_array( $sent ) ) {
                $sent = [];
            }
            if ( $ok ) {
                // finding-5 — dispatch_emails() gates the buyer confirmation PER
                // EVENT (emails_sent['customer:'.$event_id]); the legacy 'customer'
                // key is no longer consulted. Mark every event the order currently
                // has active seats for so a later reconcile won't auto-send another
                // confirmation for an already-covered event.
                $now = \time();
                foreach ( $this->collect_order_seats( $order_id ) as $eid => $seats ) {
                    $sent[ 'customer:' . (int) $eid ] = $now;
                }
                $order->update_meta_data( self::EMAILS_SENT_META, $sent );
                $order->save();
                Events_Log::order( $order_id, 'Customer confirmation re-sent (manual).' );
            } else {
                Events_Log::flag_review( $order_id, 'customer_email_failed', 'manual resend' );
            }
        }
        $this->redirect_back();
    }

    /** Redirect back to the referring admin screen after an admin-post action. */
    private function redirect_back() {
        $redirect = \wp_get_referer();
        if ( ! $redirect ) {
            $redirect = \admin_url();
        }
        \wp_safe_redirect( $redirect );
        exit;
    }

    /* ---------------------------------------------------------------------
     * Order admin metabox (seat summary + sync log + resync button)
     * ------------------------------------------------------------------- */

    /**
     * add_meta_boxes( string $post_type, mixed $post_or_order ) pri 30. Register on
     * both the HPOS order screen id and the legacy 'shop_order' screen.
     */
    /**
     * Edit-screen URL for an order, HPOS-aware (spec §10.2). Falls back to the
     * legacy post edit link when HPOS is off or the utility is unavailable.
     *
     * @param int $order_id
     * @return string
     */
    public function order_edit_url( $order_id ) {
        $order_id = (int) $order_id;
        if ( $order_id <= 0 ) {
            return '';
        }
        if ( \class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
            return \admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order_id );
        }
        return (string) \get_edit_post_link( $order_id );
    }

    public function register_order_metabox() {
        $screens = [ 'shop_order' ];
        if ( \function_exists( 'wc_get_page_screen_id' ) ) {
            $hpos = \wc_get_page_screen_id( 'shop-order' );
            if ( $hpos ) {
                $screens[] = $hpos;
            }
        }
        foreach ( \array_unique( $screens ) as $screen ) {
            \add_meta_box(
                'anchor_event_order_seats',
                \__( 'Event Registrations', 'anchor-schema' ),
                [ $this, 'render_order_metabox' ],
                $screen,
                'side',
                'default'
            );
        }
    }

    /**
     * Render the per-order seat summary, needs-review banner, sync log, and a
     * nonced Resync form. Everything is escaped.
     *
     * @param mixed $post_or_order WP_Post (legacy) or WC_Order (HPOS).
     */
    public function render_order_metabox( $post_or_order ) {
        $order = $post_or_order instanceof \WC_Order
            ? $post_or_order
            : \wc_get_order( isset( $post_or_order->ID ) ? (int) $post_or_order->ID : 0 );
        if ( ! $order ) {
            echo '<p>' . \esc_html__( 'Order not found.', 'anchor-schema' ) . '</p>';
            return;
        }
        $order_id = (int) $order->get_id();

        // Needs-review banner.
        $flags = $order->get_meta( Events_Log::ORDER_REVIEW_META );
        if ( \is_array( $flags ) && ! empty( $flags ) ) {
            echo '<div class="notice notice-warning inline" style="margin:0 0 10px;padding:6px 10px;">';
            echo '<strong>' . \esc_html__( 'Needs review:', 'anchor-schema' ) . '</strong><ul style="margin:4px 0 0 16px;list-style:disc;">';
            foreach ( $flags as $flag ) {
                $reason = isset( $flag['reason'] ) ? (string) $flag['reason'] : '';
                $detail = isset( $flag['detail'] ) ? (string) $flag['detail'] : '';
                echo '<li>' . \esc_html( $reason !== '' ? $reason : 'review' );
                if ( $detail !== '' ) {
                    echo ' — ' . \esc_html( $detail );
                }
                echo '</li>';
            }
            echo '</ul></div>';
        }

        // Per-line seat summary.
        $any = false;
        foreach ( $order->get_items() as $item_id => $item ) {
            if ( ! $item instanceof \WC_Order_Item_Product ) {
                continue;
            }
            $item_id  = (int) $item_id;
            $event_id = $this->event_for_order_item( $item );
            if ( $event_id <= 0 ) {
                continue;
            }
            $any = true;

            echo '<p style="margin:0 0 4px;"><strong>' . \esc_html( \get_the_title( $event_id ) ) . '</strong> '
                . '<span style="color:#666;">(' . \esc_html__( 'event', 'anchor-schema' ) . ' #' . (int) $event_id . ')</span>';
            if ( $this->module->roster ) {
                echo ' &middot; <a href="' . \esc_url( $this->module->roster->roster_url( $event_id ) ) . '">'
                    . \esc_html__( 'View roster', 'anchor-schema' ) . '</a>';
            }
            echo '</p>';

            $counts = [];
            foreach ( $this->registrations->get_seats_for_order_item( $item_id ) as $sid ) {
                $info = $this->registrations->get_seat_info( $sid );
                if ( ! $info ) {
                    continue;
                }
                $counts[ $info['status'] ] = ( $counts[ $info['status'] ] ?? 0 ) + 1;
            }
            if ( empty( $counts ) ) {
                echo '<p style="margin:0 0 8px;color:#666;">' . \esc_html__( 'No seats yet.', 'anchor-schema' ) . '</p>';
            } else {
                echo '<ul style="margin:0 0 8px 16px;list-style:disc;">';
                foreach ( $counts as $status => $n ) {
                    echo '<li>' . \esc_html( $status ) . ': ' . (int) $n . '</li>';
                }
                echo '</ul>';
            }
        }
        if ( ! $any ) {
            echo '<p>' . \esc_html__( 'No event registrations on this order.', 'anchor-schema' ) . '</p>';
        }

        // Sync log (newest first).
        $log = $order->get_meta( Events_Log::ORDER_LOG_META );
        if ( \is_array( $log ) && ! empty( $log ) ) {
            echo '<p style="margin:8px 0 4px;"><strong>' . \esc_html__( 'Sync log', 'anchor-schema' ) . '</strong></p>';
            echo '<ul style="margin:0 0 8px 16px;list-style:disc;max-height:160px;overflow:auto;">';
            foreach ( \array_reverse( $log ) as $entry ) {
                $time = isset( $entry['time'] ) ? (int) $entry['time'] : 0;
                $msg  = isset( $entry['message'] ) ? (string) $entry['message'] : '';
                $when = $time ? \date_i18n( 'Y-m-d H:i', $time ) : '';
                echo '<li><span style="color:#666;">' . \esc_html( $when ) . '</span> ' . \esc_html( $msg ) . '</li>';
            }
            echo '</ul>';
        }

        // Action buttons (cap edit_others_posts): Resync / Mark reviewed / Resend.
        if ( \current_user_can( 'edit_others_posts' ) ) {
            $post_url = \esc_url( \admin_url( 'admin-post.php' ) );

            echo '<form method="post" action="' . $post_url . '" style="margin:0 0 6px;">';
            echo '<input type="hidden" name="action" value="anchor_event_resync_order" />';
            echo '<input type="hidden" name="order_id" value="' . (int) $order_id . '" />';
            \wp_nonce_field( 'anchor_event_resync_' . $order_id );
            echo '<button type="submit" class="button button-secondary">' . \esc_html__( 'Resync order', 'anchor-schema' ) . '</button>';
            echo '</form>';

            echo '<form method="post" action="' . $post_url . '" style="margin:0 0 6px;">';
            echo '<input type="hidden" name="action" value="anchor_events_resend_confirmation" />';
            echo '<input type="hidden" name="order_id" value="' . (int) $order_id . '" />';
            \wp_nonce_field( 'anchor_events_resend_' . $order_id );
            echo '<button type="submit" class="button button-secondary">' . \esc_html__( 'Resend confirmation', 'anchor-schema' ) . '</button>';
            echo '</form>';

            if ( \is_array( $flags ) && ! empty( $flags ) ) {
                echo '<form method="post" action="' . $post_url . '" style="margin:0;">';
                echo '<input type="hidden" name="action" value="anchor_events_clear_review" />';
                echo '<input type="hidden" name="order_id" value="' . (int) $order_id . '" />';
                \wp_nonce_field( 'anchor_events_clear_review_' . $order_id );
                echo '<button type="submit" class="button button-secondary">' . \esc_html__( 'Mark reviewed', 'anchor-schema' ) . '</button>';
                echo '</form>';
            }
        }
    }

    /* ---------------------------------------------------------------------
     * Refunds (Phase 4 — spec §8)
     * ------------------------------------------------------------------- */

    /**
     * woocommerce_order_refunded( int $order_id, int $refund_id ). Classify the
     * refund, then route through the same reconcile (surplus active seats →
     * 'refunded' newest-first). Amount-only refunds change NO seats — they are
     * flagged for review (never guessed). Idempotent with the full-refund
     * order_status_changed → refunded double-fire.
     *
     * @param int $order_id
     * @param int $refund_id
     */
    public function on_order_refunded( $order_id, $refund_id ) {
        $order = \wc_get_order( (int) $order_id );
        if ( ! $order ) {
            return;
        }
        // H2/finding-6 — refunds on ordinary non-event orders must be a complete
        // no-op. The amount_only branch below writes review meta/log/notice BEFORE
        // any per-line event check, so guard here explicitly. (The line/mixed path
        // routes through reconcile_order which already guards on event lines.)
        if ( ! $this->order_has_event_lines( $order ) ) {
            return;
        }
        $class = $this->classify_refund( $order, (int) $refund_id );

        if ( 'amount_only' === $class ) {
            Events_Log::flag_review( (int) $order_id, 'amount_only_refund', 'refund #' . (int) $refund_id );
            Events_Log::order( (int) $order_id, 'Amount-only refund — seats not changed (not guessed).', [
                'refund' => (int) $refund_id,
            ] );
            return;
        }

        // M4 — for a mixed refund the extra unexplained amount needs review. Thread
        // the flag into reconcile's SINGLE batched save instead of flagging a
        // separate order instance (which reconcile's own save would clobber).
        $seed_flags = [];
        if ( 'mixed' === $class ) {
            $seed_flags[] = $this->make_flag( 'mixed_refund_extra_amount', 'refund #' . (int) $refund_id . ' (extra amount)' );
        }

        // line | mixed → surplus active seats become 'refunded' (count-based,
        // newest-first). expected already subtracts abs(get_qty_refunded_for_item)
        // so cumulative partials monotonically lower expected and re-fire is a no-op.
        $this->reconcile_order( $order, 'refund', Registrations::STATUS_REFUNDED, false, $seed_flags );
    }

    /**
     * Classify a refund as 'line' | 'amount_only' | 'mixed'.
     *
     * BLOCKER #1: refund line-item quantities are NEGATIVE — a line refund is
     * detected via abs($refund_item->get_quantity()) > 0, NOT qty > 0 (which would
     * misclassify every refund as amount_only). amount_only = every refund item has
     * zero qty but the refund carries an amount. mixed = line qty AND extra amount.
     *
     * @param \WC_Order $order
     * @param int       $refund_id
     * @return string
     */
    private function classify_refund( $order, $refund_id ) {
        if ( ! \function_exists( 'wc_get_order' ) ) {
            return 'amount_only';
        }
        $refund = \wc_get_order( (int) $refund_id );
        if ( ! $refund instanceof \WC_Order_Refund ) {
            return 'amount_only';
        }

        $has_line   = false;
        $line_total = 0.0;
        foreach ( $refund->get_items() as $ritem ) {
            if ( ! $ritem instanceof \WC_Order_Item_Product ) {
                continue;
            }
            if ( \abs( (int) $ritem->get_quantity() ) > 0 ) {
                $has_line = true;
            }
            $line_total += \abs( (float) $ritem->get_total() );
            $line_total += \abs( (float) $ritem->get_total_tax() );
        }

        $amount = \abs( (float) $refund->get_amount() );

        if ( $has_line ) {
            // Extra unexplained amount beyond the refunded line totals → mixed.
            return ( $amount - $line_total > 0.01 ) ? 'mixed' : 'line';
        }
        return 'amount_only';
    }
}
