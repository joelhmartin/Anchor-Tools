<?php
namespace Anchor\Events;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Event → managed-product sync engine (spec §4–5).
 *
 * Loaded ONLY when WooCommerce is active (instantiated inside the
 * `class_exists('WooCommerce')` block in Module::__construct).
 *
 * Responsibility: keep one auto-managed, catalog-hidden WooCommerce VARIABLE
 * product matching a paid event — each paid+active ticket tier maps to one
 * variation, keyed by the stable tier id (variation meta `_anchor_evt_tier_id`).
 * The sync is declarative, one-way (event → product), and idempotent: a second
 * call with no event change makes no further writes.
 *
 * Lifecycle:
 *  - on event save (after Module::save_meta) → reconcile the product/variations,
 *  - on event trash/delete → set the managed product to `draft` (never delete —
 *    orders reference it); seats/orders untouched,
 *  - managed fields (price, title, variation set) are locked: a direct edit to a
 *    managed product re-asserts from the event + queues an admin notice.
 *
 * All product/variation access is via WooCommerce CRUD (HPOS-safe); event-side
 * pointers live in event post meta (a CPT, not an order). Every wc_* / WC class
 * touch is guarded so nothing fatals defensively.
 */
class Product_Sync {

    /** Event meta: id of the managed WooCommerce product (0/absent = none). */
    const EVENT_PRODUCT_META = '_anchor_event_managed_product';

    /** Product meta: id of the owning event (marks a product as managed). */
    const PRODUCT_EVENT_META = '_anchor_evt_managed_event';

    /** Variation meta: the stable tier id this variation maps to. */
    const VARIATION_TIER_META = '_anchor_evt_tier_id';

    /** Custom product attribute name used to vary the managed product. */
    const ATTRIBUTE_NAME = 'Ticket';

    /**
     * Event meta: the last product-sync failure, or absent when the last pass
     * completed (WOO-D13). Shape: [reason, exception, message, event, time].
     *
     * The sync engine had no try/catch, no Events_Log call and no needs-review
     * write: a WC_Product_Variation::save() that threw part-way through aborted
     * save_post, left the product half-reconciled against a tier list still
     * holding the OLD variation ids, and showed the editor a PHP fatal with
     * nothing recorded anywhere. Self-clearing: a pass that completes deletes it.
     */
    const SYNC_FAILED_META = '_anchor_event_sync_failed';

    /** @var Module */
    private $module;

    /** @var Ticket_Types */
    private $ticket_types;

    /**
     * Per-PHP-process re-entrancy guard. Mirrors the `class-woocommerce.php`
     * in-flight pattern: while we are syncing an event (and saving its product),
     * the managed-product-update lock handler must not re-trigger a sync.
     *
     * Shape: [ 'event' => [event_id => true], 'product' => [product_id => true] ].
     *
     * @var array<string,array<int,bool>>
     */
    private static $in_flight = [ 'event' => [], 'product' => [] ];

    public function __construct( Module $module, Ticket_Types $ticket_types ) {
        $this->module       = $module;
        $this->ticket_types = $ticket_types;

        // Reconcile after Module::save_meta (default priority 10) has persisted
        // the tier list. Guarded against autosave/revisions in the handler.
        \add_action( 'save_post_' . Module::CPT, [ $this, 'on_event_saved' ], 20, 1 );

        // Trash / delete → demote the managed product to draft (never delete).
        \add_action( 'wp_trash_post', [ $this, 'on_event_trashed_or_deleted' ], 10, 1 );
        \add_action( 'before_delete_post', [ $this, 'on_event_trashed_or_deleted' ], 10, 1 );
        // NO `untrashed_post` mirror is needed, and adding one would just run a
        // second full sync per restore (audit WOO-D34, checked against core):
        // wp_untrash_post() restores the status THROUGH wp_update_post(), so
        // save_post_event fires with the event already out of the trash and
        // on_event_saved() below republishes the product. The trash half needs
        // its own hook only because save_post fires there too — with the status
        // already 'trash', which on_event_saved() skips.
        // tests/test-untrash.php pins that round trip.

        // Managed-field lock (Task 2.2): re-assert on a direct managed-product edit.
        \add_action( 'woocommerce_update_product', [ $this, 'on_product_updated' ], 30, 1 );

        // Admin notice surfaced after a re-assert.
        \add_action( 'admin_notices', [ $this, 'render_managed_notice' ] );

        // WOO-D13 — surface a captured sync failure on the event being edited.
        \add_action( 'admin_notices', [ $this, 'render_sync_failure_notice' ] );
    }

    /* ---------------------------------------------------------------------
     * Public API
     * ------------------------------------------------------------------- */

    /**
     * Ensure/refresh the managed product for an event and return its id.
     *
     * If the event has ≥1 ACTIVE tier with price > 0, ensure a managed variable
     * product exists, reconcile its variations to the tier set, and return the
     * product id. If the event has no paid+active tier, set any existing managed
     * product to `draft` and return 0.
     *
     * @param int $event_id
     * @return int Product id, or 0 when no paid+active tier.
     */
    public function sync_event( $event_id ) {
        $event_id = (int) $event_id;
        if ( $event_id <= 0 || \get_post_type( $event_id ) !== Module::CPT ) {
            return 0;
        }
        if ( ! \function_exists( 'wc_get_product' ) || ! \class_exists( '\\WC_Product_Variable' ) ) {
            return 0;
        }
        if ( isset( self::$in_flight['event'][ $event_id ] ) ) {
            return $this->managed_product_id( $event_id );
        }

        self::$in_flight['event'][ $event_id ] = true;
        try {
            $product_id = $this->do_sync_event( $event_id );
            // WOO-D13 — the condition that raised the marker (a pass that could
            // not finish) has passed: a pass just did. Self-clearing, so the
            // notice does not outlive the problem.
            $this->clear_sync_failure( $event_id );
            return $product_id;
        } catch ( \Throwable $e ) {
            // WOO-D13 — a mid-sync throw used to abort save_post with an uncaught
            // exception. Capture it instead: the save completes, the failure is in
            // the error log and on the event, and NOTHING is re-raised.
            // …and re-adopt a product the throw orphaned. A handler that threw
            // from save_post_product lands AFTER the product exists but BEFORE
            // the event → product pointer is written, so the next sync would see
            // no pointer and mint a SECOND product for the same event.
            $this->recover_product_pointer( $event_id );
            $this->record_sync_failure( $event_id, $e );
            return $this->managed_product_id( $event_id );
        } finally {
            unset( self::$in_flight['event'][ $event_id ] );
        }
    }

    /**
     * The managed variation id for a tier (0 if none) — VALIDATED.
     *
     * The tier row's `wc_variation_id` is a cache written by the last sync and
     * nothing invalidates it when the variation is deleted, re-parented or
     * deactivated in the Woo editor (audit WOO-D24). Returning a stale id sent
     * ajax_add_to_cart() into WC_Cart::add_to_cart() with a combination Woo
     * rejects, and the buyer got the generic "Could not add … to the cart".
     *
     * A returned id is guaranteed to be a published `product_variation` whose
     * parent is THIS event's validated managed product, so the sole caller can
     * treat a 0 as "this tier is not on sale" and say so. The cached id and the
     * fallback scan are held to the same bar — otherwise the scan would just
     * hand back the same private/foreign variation the cache check rejected.
     *
     * @param int    $event_id
     * @param string $tier_id
     * @return int
     */
    public function variation_for_tier( $event_id, $tier_id ) {
        $event_id = (int) $event_id;
        $tier_id  = (string) $tier_id;
        if ( $event_id <= 0 || $tier_id === '' ) {
            return 0;
        }

        // Fallback (and validation) both need the owning product.
        if ( ! \function_exists( 'wc_get_product' ) ) {
            return 0;
        }
        $product_id = $this->managed_product_id( $event_id );
        if ( $product_id <= 0 ) {
            return 0;
        }

        $tier = $this->ticket_types->find( $event_id, $tier_id );
        if ( $tier && $this->is_live_variation( (int) $tier['wc_variation_id'], $product_id, $tier_id ) ) {
            return (int) $tier['wc_variation_id'];
        }

        // Fallback: scan the managed product's variations by tier-id meta.
        foreach ( $this->variation_ids_for_product( $product_id ) as $vid ) {
            if ( $this->is_live_variation( $vid, $product_id, $tier_id ) ) {
                return (int) $vid;
            }
        }
        return 0;
    }

    /**
     * Whether $variation_id is a published `product_variation` of $product_id
     * mapped to $tier_id. The single validation both branches of
     * variation_for_tier() apply.
     *
     * @param int    $variation_id
     * @param int    $product_id Validated managed product id.
     * @param string $tier_id
     * @return bool
     */
    private function is_live_variation( $variation_id, $product_id, $tier_id ) {
        $variation_id = (int) $variation_id;
        if ( $variation_id <= 0 || \get_post_type( $variation_id ) !== 'product_variation' ) {
            return false;
        }
        if ( \get_post_status( $variation_id ) !== 'publish' ) {
            return false; // Deactivated (private) or drafted.
        }
        if ( (int) \wp_get_post_parent_id( $variation_id ) !== (int) $product_id ) {
            return false; // Another event's product, or re-parented.
        }
        $variation = \wc_get_product( $variation_id );
        return $variation && (string) $variation->get_meta( self::VARIATION_TIER_META ) === (string) $tier_id;
    }

    /**
     * Resolve the event + tier a managed variation maps to.
     *
     * @param int $variation_id
     * @return array{event_id:int,tier_id:string}
     */
    public function tier_for_variation( $variation_id ) {
        $out          = [ 'event_id' => 0, 'tier_id' => '' ];
        $variation_id = (int) $variation_id;
        if ( $variation_id <= 0 || ! \function_exists( 'wc_get_product' ) ) {
            return $out;
        }
        $variation = \wc_get_product( $variation_id );
        if ( ! $variation ) {
            return $out;
        }
        $out['tier_id'] = (string) $variation->get_meta( self::VARIATION_TIER_META );

        $parent_id = (int) $variation->get_parent_id();
        if ( $parent_id > 0 ) {
            $parent = \wc_get_product( $parent_id );
            if ( $parent ) {
                $out['event_id'] = (int) $parent->get_meta( self::PRODUCT_EVENT_META );
            }
        }
        return $out;
    }

    /**
     * The event's managed product id — VALIDATED (0 when there isn't one).
     *
     * The one accessor every caller uses, so "the event's product" means the
     * same thing everywhere (audit WOO-D23). The stored pointer is a bare int
     * with no referential integrity behind it, and it survives all three of:
     *
     *  - the product being permanently deleted (WordPress may later reissue
     *    the id to an unrelated post, which the event would then "own"),
     *  - the product being trashed,
     *  - the event being DUPLICATED — a duplicate-post plugin copies this meta
     *    verbatim, so two events point at one product. The product's own
     *    back-pointer (PRODUCT_EVENT_META) still names exactly one owner, so
     *    that is the tiebreak: a product whose back-pointer names a different,
     *    still-existing event is not this event's, and do_sync_event() below
     *    gives the copy its own product instead of stealing the original's.
     *    (Occurrences::disown_foreign_product() clears the borrowed pointer on
     *    the trash path; this rejects it on every read.)
     *
     * A `draft` product IS still the event's managed product — the demote path
     * drafts it on purpose and the untrash path republishes that same product.
     * Callers that need "can this be sold right now?" ask
     * managed_product_is_sellable() instead.
     *
     * @param int $event_id
     * @return int
     */
    public function managed_product_id( $event_id ) {
        $event_id   = (int) $event_id;
        $product_id = $this->stored_product_id( $event_id );
        if ( $product_id <= 0 ) {
            return 0;
        }
        if ( \get_post_type( $product_id ) !== 'product' ) {
            return 0; // Deleted, or an id reissued to something else.
        }
        if ( \get_post_status( $product_id ) === 'trash' ) {
            return 0;
        }
        $owner = (int) \get_post_meta( $product_id, self::PRODUCT_EVENT_META, true );
        if ( $owner !== $event_id && \get_post_type( $owner ) === Module::CPT ) {
            return 0; // Belongs to another live event — a copied pointer.
        }
        return $product_id;
    }

    /**
     * The RAW stored pointer, unvalidated — for the caller whose job is to
     * clear it (Occurrences::disown_foreign_product()) rather than to use the
     * product it names, and for managed_product_id() itself to validate.
     * Everything else wants managed_product_id().
     *
     * @param int $event_id
     * @return int
     */
    public function stored_product_id( $event_id ) {
        $event_id = (int) $event_id;
        if ( $event_id <= 0 ) {
            return 0;
        }
        return (int) \get_post_meta( $event_id, self::EVENT_PRODUCT_META, true );
    }

    /**
     * Whether the event's managed product is currently sellable — i.e. it is a
     * valid managed product AND published (audit WOO-D23). Demoted-to-draft is
     * the plugin's own "this event has nothing to sell" state, so it must not
     * read as sellable even though the pointer is still correct.
     *
     * @param int $event_id
     * @return bool
     */
    public function managed_product_is_sellable( $event_id ) {
        $product_id = $this->managed_product_id( $event_id );
        return $product_id > 0 && \get_post_status( $product_id ) === 'publish';
    }

    /* ---------------------------------------------------------------------
     * Lifecycle hooks
     * ------------------------------------------------------------------- */

    /**
     * Reconcile on event save. Guarded against autosave/revisions/auto-draft.
     *
     * @param int $post_id
     */
    public function on_event_saved( $post_id ) {
        $post_id = (int) $post_id;
        if ( \defined( 'DOING_AUTOSAVE' ) && \DOING_AUTOSAVE ) {
            return;
        }
        if ( \function_exists( 'wp_is_post_autosave' ) && \wp_is_post_autosave( $post_id ) ) {
            return;
        }
        if ( \function_exists( 'wp_is_post_revision' ) && \wp_is_post_revision( $post_id ) ) {
            return;
        }
        if ( \get_post_type( $post_id ) !== Module::CPT ) {
            return;
        }
        if ( \in_array( \get_post_status( $post_id ), [ 'auto-draft', 'trash' ], true ) ) {
            return;
        }
        $this->sync_event( $post_id );
    }

    /**
     * On event trash/delete, set the managed product to `draft`. Never delete it —
     * orders reference it; seats/orders are preserved.
     *
     * @param int $post_id
     */
    public function on_event_trashed_or_deleted( $post_id ) {
        $post_id = (int) $post_id;
        if ( \get_post_type( $post_id ) !== Module::CPT ) {
            return;
        }
        if ( ! \function_exists( 'wc_get_product' ) ) {
            return;
        }
        $product_id = $this->managed_product_id( $post_id );
        if ( $product_id <= 0 ) {
            return;
        }
        $this->demote_product( $product_id, $post_id );
    }

    /**
     * Take an event's managed product out of circulation, whole.
     *
     * Drafting the PARENT alone was never enough (audit WOO-D50): its
     * variations stayed `publish` with `_anchor_evt_link_event_id` intact, so
     * they still qualified for the linked-products mirror, still resolved as
     * event lines on an order, and stayed purchasable for anyone with
     * edit_post on the drafted parent (WooCommerce lets an editor through an
     * unpublished parent). Meanwhile the event's tier list still advertised a
     * `wc_variation_id` for each of them.
     *
     * So the demote does all three, in the vocabulary the rest of the module
     * already uses for a retired variation — `private`, exactly what
     * write_variation()'s deactivate branch sets:
     *
     *  1. product      → draft
     *  2. variations   → private
     *  3. tier rows    → wc_variation_id 0
     *
     * (3) clears the CACHE, not the author's tier rows — the alternative
     * ("mark the tiers inactive") would rewrite authored data to record a
     * derived state, and could not be undone by the code that set it: a group
     * parent is demoted on EVERY save, so its authored tiers would be
     * permanently deactivated the first time it grew an occurrence. Clearing
     * the pointer is what write_back_variation_ids() already owns and what the
     * next sync re-derives, so the whole demotion reverses itself: re-activate
     * a tier (or stop being a group parent) and the next sync republishes the
     * product, republishes its variations and re-writes the ids.
     *
     * Never deletes: orders reference these posts.
     *
     * Steps 2 and 3 run even when there is no product to demote ($product_id 0:
     * deleted, trashed, or a pointer that turned out to belong to another
     * event). The event-side state is the half we can always fix, and leaving
     * its tier rows naming variations that no longer resolve — or a mirror
     * listing a product that is gone — is the same stale-pointer defect one
     * level up. Guarded on the event ever having HAD a pointer, so an event
     * that never sold anything (much the commonest case, and re-synced on
     * every save) does no work at all.
     *
     * @param int $product_id Validated managed product id, or 0 when there is none.
     * @param int $event_id
     */
    private function demote_product( $product_id, $event_id ) {
        $product_id = (int) $product_id;
        $event_id   = (int) $event_id;
        if ( $event_id <= 0 ) {
            return;
        }
        if ( $product_id <= 0 && $this->stored_product_id( $event_id ) <= 0 ) {
            return; // Never had a managed product — nothing to take out of circulation.
        }

        if ( $product_id > 0 ) {
            self::$in_flight['product'][ $product_id ] = true;
            try {
                $product = \wc_get_product( $product_id );
                if ( $product && $product->get_status() !== 'draft' ) {
                    $product->set_status( 'draft' );
                    $product->save();
                }

                foreach ( $this->variation_ids_for_product( $product_id ) as $vid ) {
                    $variation = \wc_get_product( $vid );
                    if ( ! $variation ) {
                        continue;
                    }
                    if ( $variation->get_status() !== 'private' ) {
                        $variation->set_status( 'private' );
                        $variation->save();
                    }
                }
            } finally {
                unset( self::$in_flight['product'][ $product_id ] );
            }
        }

        // Stop the tier list advertising variations nothing can sell. Reuses the
        // same write-back the live path uses, so it stays a no-op when the ids
        // are already 0 (idempotency).
        $tiers = $this->ticket_types->get( $event_id );
        $this->write_back_variation_ids(
            $event_id,
            $tiers,
            \array_fill_keys( \array_map( 'strval', \wp_list_pluck( $tiers, 'id' ) ), 0 )
        );

        // …and the denormalized linked-products mirror, which event_is_linked()
        // reads instead of the live query. Its own rebuild rides on
        // `woocommerce_update_product`, which a variation save does not fire and
        // an ALREADY-draft product does not fire either — so an event demoted by
        // an older build (live: 7909) would keep a mirror listing three
        // unsellable variations forever. Rebuilding here makes the demote whole
        // whichever sub-save happened to run.
        if ( $this->module->woocommerce ) {
            $this->module->woocommerce->rebuild_event_mirror( $event_id );
        }
    }

    /**
     * Managed-field lock (Task 2.2). When a managed product is saved directly
     * (not from inside our own sync), re-assert the managed fields (price, title,
     * variation set) from the event and queue an admin notice. Descriptive fields
     * (image, long description) are left alone.
     *
     * @param int $product_id
     */
    public function on_product_updated( $product_id ) {
        $product_id = (int) $product_id;
        if ( $product_id <= 0 || ! \function_exists( 'wc_get_product' ) ) {
            return;
        }
        // Skip writes we triggered ourselves (trash demotion etc.).
        if ( isset( self::$in_flight['product'][ $product_id ] ) ) {
            return;
        }
        $product = \wc_get_product( $product_id );
        if ( ! $product ) {
            return;
        }
        $event_id = (int) $product->get_meta( self::PRODUCT_EVENT_META );
        if ( $event_id <= 0 ) {
            return; // Not a managed product.
        }
        // Skip if this save is part of our own event sync (avoids recursion).
        if ( isset( self::$in_flight['event'][ $event_id ] ) ) {
            return;
        }
        if ( \get_post_type( $event_id ) !== Module::CPT ) {
            return;
        }

        // Re-assert from the event; sync_event is itself idempotent + guarded.
        $this->sync_event( $event_id );
        $this->queue_managed_notice();
    }

    /** Render (and consume) the managed-product re-assert notice for this user. */
    public function render_managed_notice() {
        if ( ! \function_exists( 'get_current_user_id' ) ) {
            return;
        }
        $key = $this->notice_transient_key();
        if ( ! \get_transient( $key ) ) {
            return;
        }
        \delete_transient( $key );
        echo '<div class="notice notice-warning is-dismissible"><p>'
            . \esc_html__( 'This product is managed by its event; managed fields (price, title, variations) were restored. Edit them on the event.', 'anchor-schema' )
            . '</p></div>';
    }

    /* ---------------------------------------------------------------------
     * Reconcile internals
     * ------------------------------------------------------------------- */

    /**
     * The actual reconcile pass (runs inside the event in-flight guard).
     *
     * @param int $event_id
     * @return int
     */
    private function do_sync_event( $event_id ) {
        $all_tiers       = $this->ticket_types->get( $event_id );
        $paid_active_map = []; // tier_id => tier (active + price > 0)
        foreach ( $all_tiers as $tier ) {
            if ( ! empty( $tier['active'] ) && (float) $tier['price'] > 0 ) {
                $paid_active_map[ (string) $tier['id'] ] = $tier;
            }
        }

        // WOO-D36: only the 'wc' registration mode may have a managed
        // product. An event authored as Free or External can still carry an
        // active paid tier — a legacy `price` synthesizing the implicit
        // primary tier, or leftover tier rows from a mode switch — and that
        // alone used to be enough to publish a live, purchasable product the
        // organizer never asked for. Occurrences::sync_product() already
        // applies this exact gate before calling sync_event() for a child
        // occurrence; this is the direct save_post path's missing twin.
        if ( $this->module->registration_mode( $event_id ) !== 'wc' ) {
            $paid_active_map = [];
        }

        // A group parent is a container, not a bookable event — each of its
        // occurrences carries its own product. Treating it as having nothing to
        // sell reuses the demote-to-draft path below, and reverses itself: stop
        // being a parent and the next sync republishes the product normally.
        // Done here rather than after the fact because ensure_product_fields()
        // sets a managed product back to 'publish' on every save.
        if ( $this->module->occurrences->is_group_parent( $event_id ) ) {
            $paid_active_map = [];
        }

        $existing_product_id = $this->managed_product_id( $event_id );

        // No paid+active tier → demote any existing managed product (product to
        // draft, variations to private+inactive, tier variation ids cleared).
        if ( empty( $paid_active_map ) ) {
            $this->demote_product( $existing_product_id, $event_id );
            return 0;
        }

        // Ensure a managed VARIABLE product exists.
        $product = $existing_product_id > 0 ? \wc_get_product( $existing_product_id ) : null;
        if ( ! $product || ! $product->is_type( 'variable' ) ) {
            $product = new \WC_Product_Variable();
        }

        $product_id = $this->ensure_product_fields( $product, $event_id );
        if ( $product_id <= 0 ) {
            return 0;
        }
        self::$in_flight['product'][ $product_id ] = true;
        try {
            // Persist the event → product pointer once known.
            if ( $this->managed_product_id( $event_id ) !== $product_id ) {
                \update_post_meta( $event_id, self::EVENT_PRODUCT_META, $product_id );
            }

            // Index existing variations by tier id (all statuses).
            $mutated      = false; // tracks whether any variation was created/changed/deleted
            $existing     = []; // tier_id => WC_Product_Variation
            $existing_ids = $this->variation_ids_for_product( $product_id );
            foreach ( $existing_ids as $vid ) {
                $variation = \wc_get_product( $vid );
                if ( ! $variation ) {
                    continue;
                }
                $tier_id = (string) $variation->get_meta( self::VARIATION_TIER_META );
                if ( $tier_id !== '' && ! isset( $existing[ $tier_id ] ) ) {
                    $existing[ $tier_id ] = $variation;
                }
            }

            // WOO-D12: a variation with BLANK tier-id meta (hand-added), or a
            // second variation sharing a tier id already indexed above (a
            // failed half-sync, or a manual duplicate), is never added to
            // $existing — the indexing loop keeps only the first match per
            // tier id. Left alone, that orphan is never added to $specs,
            // never deleted, and never reconciled again: it stays published
            // under the managed product, sellable, with its stale price and
            // link meta, invisible to every future sync. Deactivate it now
            // (never delete — we cannot know here whether an order
            // references it) the same way a retired tier's variation is.
            $accounted_for = [];
            foreach ( $existing as $variation ) {
                $accounted_for[ $variation->get_id() ] = true;
            }
            foreach ( $existing_ids as $vid ) {
                if ( isset( $accounted_for[ $vid ] ) ) {
                    continue;
                }
                $orphan = \wc_get_product( $vid );
                if ( $orphan && $orphan->get_status() !== 'private' ) {
                    $orphan->set_status( 'private' );
                    $orphan->save();
                    $mutated = true;
                }
            }

            // Plan the resulting variation set + delete removed-with-no-seats.
            $specs    = []; // ordered list of variation specs to write
            $used_opt = [];

            // 1) Paid+active tiers (preserve $all_tiers order).
            foreach ( $all_tiers as $tier ) {
                $tier_id = (string) $tier['id'];
                if ( ! isset( $paid_active_map[ $tier_id ] ) ) {
                    continue;
                }
                // finding-19 (carry-over) — defers to Ticket_Types::default_label()
                // rather than its own literal: the tier's own unlabeled row
                // and the WooCommerce variation synced from it must agree on
                // what to call it (Ticket_Types is the source of truth for
                // tier label regardless of whether Woo is active).
                $label  = $tier['label'] !== '' ? $tier['label'] : Ticket_Types::default_label();
                $option = $this->unique_option( $label, $used_opt );
                $specs[] = [
                    'tier_id'   => $tier_id,
                    'variation' => $existing[ $tier_id ] ?? null,
                    'active'    => true,
                    'price'     => (float) $tier['price'],
                    'label'     => $label,
                    'option'    => $option,
                ];
            }

            // 2) Existing variations whose tier is no longer paid+active
            //    (removed / inactive / price→0): deactivate-with-seats, else delete.
            foreach ( $existing as $tier_id => $variation ) {
                if ( isset( $paid_active_map[ $tier_id ] ) ) {
                    continue;
                }
                if ( $this->module->registrations->tier_has_seats( $event_id, $tier_id ) ) {
                    // Keep current attribute option (fall back to tier id) so the
                    // deactivated variation stays a valid attribute combination.
                    $current_attrs = $variation->get_attributes();
                    $base          = $current_attrs[ \sanitize_title( self::ATTRIBUTE_NAME ) ] ?? $tier_id;
                    $option        = $this->unique_option( (string) $base, $used_opt );
                    $specs[]       = [
                        'tier_id'   => $tier_id,
                        'variation' => $variation,
                        'active'    => false,
                        'price'     => (float) $variation->get_regular_price(),
                        'label'     => (string) $variation->get_description(),
                        'option'    => $option,
                    ];
                } else {
                    $variation->delete( true );
                    $mutated = true;
                }
            }

            // Apply the product-level variation attribute (union of all options).
            $this->apply_product_attribute( $product, \wp_list_pluck( $specs, 'option' ) );

            // Create/update each variation; collect tier → variation id map.
            $tier_variation_map = [];
            foreach ( $specs as $spec ) {
                $vid = $this->write_variation( $product_id, $event_id, $spec, $mutated );
                if ( $vid > 0 ) {
                    $tier_variation_map[ $spec['tier_id'] ] = $vid;
                }
            }

            // Resync variable-product price cache only when something changed
            // (sync() saves the product; skipping it keeps a no-op pass write-free).
            if ( $mutated && \method_exists( '\\WC_Product_Variable', 'sync' ) ) {
                \WC_Product_Variable::sync( $product_id );
            }

            // Write wc_variation_id back into the tier list (idempotent: only if changed).
            $this->write_back_variation_ids( $event_id, $all_tiers, $tier_variation_map );
        } finally {
            unset( self::$in_flight['product'][ $product_id ] );
        }

        return $product_id;
    }

    /**
     * Apply the managed product fields (title, visibility, stock, status, event
     * pointer). Saves only when something changed. Returns the product id.
     *
     * @param \WC_Product $product
     * @param int         $event_id
     * @return int
     */
    private function ensure_product_fields( $product, $event_id ) {
        $dirty = false;

        $title = (string) \get_the_title( $event_id );
        if ( $product->get_name() !== $title ) {
            $product->set_name( $title );
            $dirty = true;
        }
        if ( $product->get_catalog_visibility() !== 'hidden' ) {
            $product->set_catalog_visibility( 'hidden' );
            $dirty = true;
        }
        if ( $product->get_manage_stock() ) {
            $product->set_manage_stock( false );
            $dirty = true;
        }
        if ( $product->get_status() !== 'publish' ) {
            $product->set_status( 'publish' );
            $dirty = true;
        }
        if ( (int) $product->get_meta( self::PRODUCT_EVENT_META ) !== (int) $event_id ) {
            $product->update_meta_data( self::PRODUCT_EVENT_META, (int) $event_id );
            $dirty = true;
        }
        // Populate the link meta the checkout/reconcile resolver reads
        // (WooCommerce::event_for_line) so managed-product carts are recognized as
        // event lines — attendee fields render and seats reconcile after payment.
        if ( (string) $product->get_meta( WooCommerce::META_ENABLED ) !== '1' ) {
            $product->update_meta_data( WooCommerce::META_ENABLED, '1' );
            $dirty = true;
        }

        if ( $dirty || $product->get_id() === 0 ) {
            return (int) $product->save();
        }
        return (int) $product->get_id();
    }

    /**
     * Set the single custom "Ticket" variation attribute on the product, saving
     * only when the option list actually changes (idempotency).
     *
     * @param \WC_Product $product
     * @param string[]    $options
     */
    private function apply_product_attribute( $product, array $options ) {
        $options = \array_values( \array_filter( \array_map( 'strval', $options ) ) );
        $key     = \sanitize_title( self::ATTRIBUTE_NAME );

        $current_options = [];
        $attributes      = $product->get_attributes();
        if ( isset( $attributes[ $key ] ) && $attributes[ $key ] instanceof \WC_Product_Attribute ) {
            $current_options = $attributes[ $key ]->get_options();
        }

        if ( $current_options === $options ) {
            return; // No change.
        }

        $attribute = new \WC_Product_Attribute();
        $attribute->set_id( 0 ); // Custom (non-taxonomy) attribute.
        $attribute->set_name( self::ATTRIBUTE_NAME );
        $attribute->set_options( $options );
        $attribute->set_visible( true );
        $attribute->set_variation( true );

        $attributes[ $key ] = $attribute;
        $product->set_attributes( $attributes );

        // The event + product in-flight guards are already held by the caller, so
        // this save can't re-trigger sync via the managed-product-update hook.
        $product->save();
    }

    /**
     * Create or update one variation from a spec. Saves only when something
     * changed (idempotency). Returns the variation id.
     *
     * @param int   $product_id
     * @param array $spec    [tier_id,variation,active,price,label,option]
     * @param bool  $mutated Set true (by reference) when this write changed data.
     * @return int
     */
    private function write_variation( $product_id, $event_id, array $spec, &$mutated = false ) {
        $variation = $spec['variation'];
        $created   = false;
        if ( ! $variation instanceof \WC_Product_Variation ) {
            $variation = new \WC_Product_Variation();
            $variation->set_parent_id( (int) $product_id );
            $created = true;
        }

        $dirty = $created;

        // Tier id meta (stable identity).
        if ( (string) $variation->get_meta( self::VARIATION_TIER_META ) !== (string) $spec['tier_id'] ) {
            $variation->update_meta_data( self::VARIATION_TIER_META, (string) $spec['tier_id'] );
            $dirty = true;
        }

        // Link-event-id meta the resolver reads (WooCommerce::event_for_line) so this
        // variation resolves to its event during checkout detection + reconcile.
        if ( (int) $variation->get_meta( WooCommerce::META_EVENT_ID ) !== (int) $event_id ) {
            $variation->update_meta_data( WooCommerce::META_EVENT_ID, (int) $event_id );
            $dirty = true;
        }

        // Attribute combination.
        $attr_key = \sanitize_title( self::ATTRIBUTE_NAME );
        $attrs    = $variation->get_attributes();
        if ( ( $attrs[ $attr_key ] ?? '' ) !== $spec['option'] ) {
            $attrs[ $attr_key ] = $spec['option'];
            $variation->set_attributes( $attrs );
            $dirty = true;
        }

        if ( $spec['active'] ) {
            // Event tickets are virtual — no shipping. Without this WooCommerce
            // treats them as physical, demands a shipping method, and can block
            // checkout/payment when none is configured.
            if ( ! $variation->is_virtual() ) {
                $variation->set_virtual( true );
                $dirty = true;
            }
            // Price.
            if ( (float) $variation->get_regular_price() !== (float) $spec['price'] ) {
                $variation->set_regular_price( (string) $spec['price'] );
                $dirty = true;
            }
            // Label → variation description.
            if ( (string) $variation->get_description() !== (string) $spec['label'] ) {
                $variation->set_description( (string) $spec['label'] );
                $dirty = true;
            }
            if ( $variation->get_manage_stock() ) {
                $variation->set_manage_stock( false );
                $dirty = true;
            }
            if ( $variation->get_status() !== 'publish' ) {
                $variation->set_status( 'publish' );
                $dirty = true;
            }
        } else {
            // Deactivate: private. Price/label left as-is.
            //
            // WOO-D8: this used to also write `_anchor_evt_tier_active`
            // ('1'/'0') alongside the status — a fact the variation's own
            // post_status already carries. Every real consumer
            // (products_for_event(), variation_for_tier(),
            // is_live_variation()) already keys off post_status or the
            // tier-id meta; the active flag had zero readers, so a row where
            // the two disagreed could never be detected. Removed rather than
            // kept in sync with a second reader, since post_status already IS
            // the single source of truth here.
            if ( $variation->get_status() !== 'private' ) {
                $variation->set_status( 'private' );
                $dirty = true;
            }
        }

        if ( $dirty ) {
            $mutated = true;
            return (int) $variation->save();
        }
        return (int) $variation->get_id();
    }

    /**
     * Write each tier's resulting wc_variation_id back via Ticket_Types::save,
     * preserving ids. No-op when nothing changed (idempotency).
     *
     * @param int   $event_id
     * @param array $all_tiers          The tier list as read this pass.
     * @param array $tier_variation_map tier_id => variation_id
     */
    private function write_back_variation_ids( $event_id, array $all_tiers, array $tier_variation_map ) {
        // WOO-D6: an event with NO authored tier list gets $all_tiers from
        // Ticket_Types::get()'s synthesized implicit-primary fallback, not
        // real stored meta. Writing a wc_variation_id back through
        // Ticket_Types::save() for that synthesized row materializes
        // `_anchor_event_ticket_types` for the first time — and from then on
        // get() stops re-reading the legacy `price` meta, so editing the
        // event's "Price label" field silently stops doing anything. The
        // variation is still discoverable without this: variation_for_tier()
        // falls back to scanning the product's variations by tier-id meta
        // when the cached id doesn't validate, so nothing is lost by skipping
        // the write-back here.
        $stored = \get_post_meta( $event_id, Ticket_Types::META_KEY, true );
        if ( ! \is_array( $stored ) || empty( $stored ) ) {
            return;
        }

        $changed = false;
        $rows    = [];
        foreach ( $all_tiers as $tier ) {
            $tier_id = (string) $tier['id'];
            if ( isset( $tier_variation_map[ $tier_id ] ) ) {
                $new_vid = (int) $tier_variation_map[ $tier_id ];
                if ( (int) $tier['wc_variation_id'] !== $new_vid ) {
                    $tier['wc_variation_id'] = $new_vid;
                    $changed                 = true;
                }
            }
            $rows[] = $tier;
        }

        if ( $changed ) {
            // WOO-D40: write the meta DIRECTLY rather than through
            // Ticket_Types::save() — save() is the authoring sanitizer (it
            // substitutes the "Registration" default for a blank label,
            // mints ids, drops empty rows...), all correct when an admin
            // submits the tickets form, but wrong here: this write only
            // wants to persist a cached wc_variation_id onto rows that
            // already went through get()'s normalize(). Routing it through
            // save() anyway silently renamed a blank-labeled tier to
            // "Registration" — on the storefront, the variation description
            // and the checkout heading — the first time any sync changed a
            // variation id. $rows is already the normalized shape save()
            // itself persists, so this writes the identical structure.
            \update_post_meta( $event_id, Ticket_Types::META_KEY, \wp_slash( $rows ) );
        }
    }

    /* ---------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------- */

    /**
     * All variation ids for a product across statuses (publish/private/draft).
     *
     * @param int $product_id
     * @return int[]
     */
    private function variation_ids_for_product( $product_id ) {
        $product_id = (int) $product_id;
        if ( $product_id <= 0 ) {
            return [];
        }
        $ids = \get_posts( [
            'post_type'      => 'product_variation',
            'post_parent'    => $product_id,
            'post_status'    => [ 'publish', 'private', 'draft' ],
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'orderby'        => 'menu_order',
            'order'          => 'ASC',
        ] );
        return \array_map( 'intval', $ids );
    }

    /**
     * Produce a unique attribute option string (case-insensitive) for the pass.
     *
     * @param string $base
     * @param array  $used Reference map of lowercased options already taken.
     * @return string
     */
    private function unique_option( $base, array &$used ) {
        $base = \trim( (string) $base );
        if ( $base === '' ) {
            // finding-19 — same shared default as the tier label itself; see
            // the call site above.
            $base = Ticket_Types::default_label();
        }
        $option = $base;
        $n      = 2;
        while ( isset( $used[ \strtolower( $option ) ] ) ) {
            $option = $base . ' ' . $n;
            $n++;
        }
        $used[ \strtolower( $option ) ] = true;
        return $option;
    }

    /** Per-user transient key for the managed-product re-assert notice. */
    private function notice_transient_key() {
        return 'anchor_evt_managed_notice_' . (int) \get_current_user_id();
    }

    /** Queue the managed-product re-assert admin notice for the current user. */
    private function queue_managed_notice() {
        if ( ! \function_exists( 'set_transient' ) || ! \function_exists( 'get_current_user_id' ) ) {
            return;
        }
        \set_transient( $this->notice_transient_key(), 1, 60 );
    }

    /* ---------------------------------------------------------------------
     * WOO-D13 — sync failure capture
     * ------------------------------------------------------------------- */

    /**
     * Record a sync failure: the site-wide error log (so it is inspectable
     * without the event) plus a needs-review marker on the event itself (so the
     * next editor to open it is told the product may be half-reconciled).
     *
     * @param int        $event_id
     * @param \Throwable $e
     */
    private function record_sync_failure( $event_id, $e ) {
        $event_id = (int) $event_id;
        Events_Log::error( 'product_sync_failed', [
            'event'     => $event_id,
            'exception' => \get_class( $e ),
            'message'   => $e->getMessage(),
        ] );
        \update_post_meta( $event_id, self::SYNC_FAILED_META, [
            'reason'    => 'product_sync_failed',
            'event'     => $event_id,
            'exception' => \get_class( $e ),
            'message'   => (string) $e->getMessage(),
            'time'      => \time(),
        ] );
    }

    /**
     * Re-point an event at the managed product it already owns, when the pointer
     * is missing but the product's own back-pointer names this event. One
     * bounded query, and a complete no-op when the pointer is already there.
     *
     * @param int $event_id
     */
    private function recover_product_pointer( $event_id ) {
        $event_id = (int) $event_id;
        if ( $event_id <= 0 || $this->stored_product_id( $event_id ) > 0 ) {
            return;
        }
        $found = \get_posts( [
            'post_type'        => 'product',
            'post_status'      => 'any',
            'numberposts'      => 1,
            'fields'           => 'ids',
            'meta_key'         => self::PRODUCT_EVENT_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'meta_value'       => $event_id,                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            'no_found_rows'    => true,
            'suppress_filters' => false,
        ] );
        if ( ! empty( $found ) && (int) $found[0] > 0 ) {
            \update_post_meta( $event_id, self::EVENT_PRODUCT_META, (int) $found[0] );
        }
    }

    /** Drop the failure marker once a pass completes (idempotent). */
    private function clear_sync_failure( $event_id ) {
        $event_id = (int) $event_id;
        if ( $event_id > 0 && \get_post_meta( $event_id, self::SYNC_FAILED_META, true ) !== '' ) {
            \delete_post_meta( $event_id, self::SYNC_FAILED_META );
        }
    }

    /**
     * Admin notice for an event whose last product sync failed. Rendered on that
     * event's editor only — the failure is per-event, and so is the fix.
     */
    public function render_sync_failure_notice() {
        if ( ! \function_exists( 'get_current_screen' ) ) {
            return;
        }
        $screen = \get_current_screen();
        if ( ! $screen || 'post' !== $screen->base || Module::CPT !== $screen->post_type ) {
            return;
        }
        $event_id = isset( $GLOBALS['post'] ) && $GLOBALS['post'] instanceof \WP_Post ? (int) $GLOBALS['post']->ID : 0;
        if ( $event_id <= 0 || ! \current_user_can( 'edit_post', $event_id ) ) {
            return;
        }
        $failure = \get_post_meta( $event_id, self::SYNC_FAILED_META, true );
        if ( ! \is_array( $failure ) ) {
            return;
        }
        echo '<div class="notice notice-error"><p><strong>'
            . \esc_html__( 'Anchor Events:', 'anchor-schema' ) . '</strong> '
            . \esc_html__( 'the last ticket/product sync for this event did not finish, so its product and variations may be out of step with the ticket tiers. Save the event again to retry; if it keeps failing, check the event error log.', 'anchor-schema' )
            . '</p><p><code>' . \esc_html( (string) ( $failure['exception'] ?? '' ) . ': ' . (string) ( $failure['message'] ?? '' ) ) . '</code></p></div>';
    }
}
