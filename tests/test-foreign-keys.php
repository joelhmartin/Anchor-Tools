<?php
/**
 * Stored-foreign-key validation (Task 19 — WOO-D23, WOO-D24, WOO-D48, WOO-D50,
 * MODEL-D22, MODEL-D23, WOO-D58).
 *
 * Every one of these ids is a bare integer/string in post meta with no
 * referential integrity behind it: the event → managed-product pointer, the
 * tier → variation pointer, the variation → parent product, the child → group
 * parent, and the seat → ticket tier. Delete or re-type the target and the
 * pointer keeps pointing. One test per accessor, each with a deleted, trashed
 * or wrong-type target.
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Module;
use Anchor\Events\Product_Sync;

/**
 * @group foreign-keys
 */
class Test_Foreign_Keys extends Anchor_Events_TestCase {

	public function set_up() {
		parent::set_up();
		$this->require_wc();
	}

	/** @return \Anchor\Events\Occurrences */
	protected function occurrences() {
		return $this->module()->occurrences;
	}

	/** A paid event with one active tier, synced. @return array{0:int,1:array,2:int} */
	private function paid_event() {
		$event_id = $this->make_event(
			[ 'title' => 'Paid Event', 'timezone' => 'UTC' ],
			[ [ 'label' => 'General', 'price' => '10', 'active' => 1 ] ]
		);
		$product_id = (int) $this->product_sync()->sync_event( $event_id );
		$this->assertGreaterThan( 0, $product_id );
		$tiers = $this->ticket_types()->get( $event_id );
		return [ $event_id, $tiers[0], $product_id ];
	}

	/**
	 * Draft a managed product WITHOUT touching its variations — the live shape
	 * of DEKA 7910 (parent draft, three variations still publish).
	 *
	 * Deliberately wp_update_post() rather than WC CRUD: a $product->save()
	 * fires `woocommerce_update_product`, and Product_Sync's managed-field lock
	 * correctly re-asserts publish from the event, so the state under test is
	 * unreachable through the CRUD layer. It is very reachable through a direct
	 * DB/status edit, a migration, or a partial demote by an older build — which
	 * is exactly how production got there.
	 */
	private function draft_product( $product_id ) {
		wp_update_post( [ 'ID' => (int) $product_id, 'post_status' => 'draft' ] );
		wp_cache_flush();
		$this->assertSame( 'draft', get_post_status( (int) $product_id ) );
	}

	/** Variation ids of a product across statuses. */
	private function variation_ids( $product_id ) {
		return array_map(
			'intval',
			get_posts(
				[
					'post_type'      => 'product_variation',
					'post_parent'    => (int) $product_id,
					'post_status'    => [ 'publish', 'private', 'draft' ],
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				]
			)
		);
	}

	/* ------------------------------------------------------------------
	 * WOO-D23 — managed_product_id()
	 * ------------------------------------------------------------------ */

	/** A permanently deleted managed product is not a managed product id. */
	public function test_managed_product_id_is_zero_when_product_deleted() {
		list( $event_id, , $product_id ) = $this->paid_event();

		wp_delete_post( $product_id, true );
		wp_cache_flush();

		$this->assertSame(
			(int) $product_id,
			(int) get_post_meta( $event_id, Product_Sync::EVENT_PRODUCT_META, true ),
			'The stale forward pointer is still stored — that is the premise of this test.'
		);
		$this->assertSame( 0, $this->product_sync()->managed_product_id( $event_id ) );
	}

	/** A trashed managed product is not a managed product id. */
	public function test_managed_product_id_is_zero_when_product_trashed() {
		list( $event_id, , $product_id ) = $this->paid_event();

		wp_trash_post( $product_id );

		$this->assertSame( 0, $this->product_sync()->managed_product_id( $event_id ) );
	}

	/** A pointer at a post that is not a product resolves to 0. */
	public function test_managed_product_id_is_zero_for_wrong_post_type() {
		list( $event_id ) = $this->paid_event();
		$page_id          = self::factory()->post->create( [ 'post_type' => 'page' ] );

		update_post_meta( $event_id, Product_Sync::EVENT_PRODUCT_META, $page_id );

		$this->assertSame( 0, $this->product_sync()->managed_product_id( $event_id ) );
	}

	/** A drafted (demoted) product is still THE managed product — just not sellable. */
	public function test_managed_product_id_keeps_a_drafted_product_but_it_is_not_sellable() {
		list( $event_id, , $product_id ) = $this->paid_event();

		$this->draft_product( $product_id );

		$this->assertSame( $product_id, $this->product_sync()->managed_product_id( $event_id ) );
		$this->assertFalse( $this->product_sync()->managed_product_is_sellable( $event_id ) );
	}

	/**
	 * Task 13 carry-in: a duplicated event copies the forward pointer, but the
	 * product's back-pointer still names exactly one owner. Syncing the copy
	 * must not steal the original's product.
	 */
	public function test_product_owned_by_another_event_is_not_adopted_on_sync() {
		list( $owner_id, , $product_id ) = $this->paid_event();

		// A duplicate-post plugin copies the pointer verbatim.
		$copy_id = $this->make_event(
			[ 'title' => 'Paid Event (copy)', 'timezone' => 'UTC' ],
			[ [ 'label' => 'General', 'price' => '10', 'active' => 1 ] ]
		);
		update_post_meta( $copy_id, Product_Sync::EVENT_PRODUCT_META, $product_id );

		$this->assertSame( 0, $this->product_sync()->managed_product_id( $copy_id ) );

		$copy_product_id = (int) $this->product_sync()->sync_event( $copy_id );

		$this->assertGreaterThan( 0, $copy_product_id );
		$this->assertNotSame( $product_id, $copy_product_id, 'The copy must get its OWN product.' );
		$this->assertSame(
			$owner_id,
			(int) get_post_meta( $product_id, Product_Sync::PRODUCT_EVENT_META, true ),
			'The original product still belongs to the original event.'
		);
		$this->assertSame( $product_id, $this->product_sync()->managed_product_id( $owner_id ) );
	}

	/* ------------------------------------------------------------------
	 * WOO-D24 — variation_for_tier()
	 * ------------------------------------------------------------------ */

	/** A deleted variation leaves a stale wc_variation_id; the accessor returns 0. */
	public function test_variation_for_tier_is_zero_when_variation_deleted() {
		list( $event_id, $tier ) = $this->paid_event();

		$vid = (int) $this->product_sync()->variation_for_tier( $event_id, $tier['id'] );
		$this->assertGreaterThan( 0, $vid );

		wp_delete_post( $vid, true );
		wp_cache_flush();

		$stored = $this->ticket_types()->find( $event_id, $tier['id'] );
		$this->assertSame( $vid, (int) $stored['wc_variation_id'], 'The stale cached id is the premise.' );

		$this->assertSame( 0, $this->product_sync()->variation_for_tier( $event_id, $tier['id'] ) );
	}

	/** A wrong-type cached id falls through to the tier-meta scan. */
	public function test_variation_for_tier_falls_back_when_cached_id_is_wrong_type() {
		list( $event_id, $tier ) = $this->paid_event();

		$real = (int) $this->product_sync()->variation_for_tier( $event_id, $tier['id'] );
		$page = self::factory()->post->create( [ 'post_type' => 'page' ] );

		$rows = $this->ticket_types()->get( $event_id );
		$rows[0]['wc_variation_id'] = $page;
		$this->ticket_types()->save( $event_id, $rows );

		$this->assertSame( $real, $this->product_sync()->variation_for_tier( $event_id, $tier['id'] ) );
	}

	/** A variation belonging to another event's product is never returned. */
	public function test_variation_for_tier_rejects_a_foreign_parent() {
		list( $event_a, $tier_a ) = $this->paid_event();
		list( $event_b, $tier_b ) = $this->paid_event();

		$foreign = (int) $this->product_sync()->variation_for_tier( $event_b, $tier_b['id'] );
		$this->assertGreaterThan( 0, $foreign );

		$rows = $this->ticket_types()->get( $event_a );
		$rows[0]['wc_variation_id'] = $foreign;
		$this->ticket_types()->save( $event_a, $rows );

		$this->assertNotSame(
			$foreign,
			$this->product_sync()->variation_for_tier( $event_a, $tier_a['id'] ),
			"Event A must never resolve to event B's variation."
		);
	}

	/* ------------------------------------------------------------------
	 * WOO-D48 / WOO-D50 — an unpublished parent product
	 * ------------------------------------------------------------------ */

	/** A publish variation under a draft parent is neither linked nor purchasable. */
	public function test_variation_of_unpublished_parent_is_not_linked_or_purchasable() {
		list( $event_id, $tier, $product_id ) = $this->paid_event();

		$vid = (int) $this->product_sync()->variation_for_tier( $event_id, $tier['id'] );
		$this->assertGreaterThan( 0, $vid );

		// Live state of DEKA 7909/7910: parent drafted, variations left publish.
		$this->draft_product( $product_id );
		$this->assertSame( 'publish', get_post_status( $vid ), 'Premise: the variation is still publish.' );

		$links = $this->woocommerce()->products_for_event( $event_id );
		$this->assertSame( [], $links, 'A variation under a draft parent is not a linked product.' );

		// WooCommerce lets an editor through a draft parent — the event gate must not.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertFalse(
			$this->woocommerce()->filter_is_purchasable( true, wc_get_product( $vid ) )
		);
	}

	/** Demoting the product also privates its variations and clears the tier ids. */
	public function test_demote_privates_variations_and_clears_tier_variation_ids() {
		list( $event_id, $tier, $product_id ) = $this->paid_event();

		$vid = (int) $this->product_sync()->variation_for_tier( $event_id, $tier['id'] );
		$this->assertGreaterThan( 0, $vid );

		// Deactivate the only paid tier → the demote-to-draft branch.
		$this->ticket_types()->save(
			$event_id,
			[ [ 'id' => $tier['id'], 'label' => 'General', 'price' => '10', 'active' => 0 ] ]
		);
		$this->assertSame( 0, (int) $this->product_sync()->sync_event( $event_id ) );

		$this->assertSame( 'draft', get_post_status( $product_id ) );
		// WOO-D8: post_status is the single source of truth for a retired
		// variation — the `_anchor_evt_tier_active` meta this used to also
		// assert on was removed (zero readers anywhere in the plugin).
		foreach ( $this->variation_ids( $product_id ) as $variation_id ) {
			$this->assertSame( 'private', get_post_status( $variation_id ) );
		}

		$stored = $this->ticket_types()->find( $event_id, $tier['id'] );
		$this->assertSame( 0, (int) $stored['wc_variation_id'], 'The tier must stop advertising a variation.' );
		$this->assertSame( [], $this->woocommerce()->products_for_event( $event_id ) );
		$this->assertFalse(
			$this->woocommerce()->event_is_linked( $event_id ),
			'…and the denormalized mirror agrees with the live query.'
		);

		// Re-activating republishes and repoints — the demote is reversible.
		$this->ticket_types()->save(
			$event_id,
			[ [ 'id' => $tier['id'], 'label' => 'General', 'price' => '10', 'active' => 1 ] ]
		);
		$this->assertSame( $product_id, (int) $this->product_sync()->sync_event( $event_id ) );
		$this->assertSame( 'publish', get_post_status( $product_id ) );
		$this->assertSame( $vid, (int) $this->product_sync()->variation_for_tier( $event_id, $tier['id'] ) );
	}

	/**
	 * WOO-D9: the mirror rebuild on a variation-post delete works via the
	 * generic before_delete_post/deleted_post pair — NOT via a dedicated
	 * `woocommerce_delete_product_variation` hook (removed: that action fires
	 * AFTER wp_delete_post() has already removed the post, so
	 * capture_linked_events()'s get_post_type() call always saw `false` and
	 * captured nothing).
	 */
	public function test_deleting_a_variation_post_directly_still_rebuilds_the_mirror() {
		list( $event_id, $tier, $product_id ) = $this->paid_event();
		$vid = (int) $this->product_sync()->variation_for_tier( $event_id, $tier['id'] );
		$this->assertGreaterThan( 0, $vid );
		$this->assertNotSame( [], $this->woocommerce()->products_for_event( $event_id ) );

		wp_delete_post( $vid, true );
		wp_cache_flush();

		$this->assertSame(
			[],
			$this->woocommerce()->products_for_event( $event_id ),
			'A deleted variation must drop out of the live query…'
		);
		$this->assertFalse(
			$this->woocommerce()->event_is_linked( $event_id ),
			'…and the mirror must have been rebuilt to agree, with no dedicated variation-delete hook.'
		);
	}

	/* ------------------------------------------------------------------
	 * MODEL-D22 — parent_of()
	 * ------------------------------------------------------------------ */

	/** A child whose group parent was trashed has no parent, and no sibling picker. */
	public function test_parent_of_is_zero_when_parent_trashed() {
		$parent_id = $this->make_event( [ 'title' => 'Workshop', 'timezone' => 'UTC' ] );
		update_post_meta(
			$parent_id,
			'_anchor_event_offering_dates',
			[
				[ 'date' => '2027-05-01', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'Session A' ],
				[ 'date' => '2027-05-08', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'Session B' ],
			]
		);
		$children = $this->occurrences()->reconcile( $parent_id );
		$this->assertCount( 2, $children );
		$child_id = (int) $children[0];
		$this->assertSame( $parent_id, $this->occurrences()->parent_of( $child_id ) );

		wp_trash_post( $parent_id );

		$this->assertTrue( $this->occurrences()->is_group_child( $child_id ), 'Still flagged as a child.' );
		$this->assertSame(
			$parent_id,
			(int) get_post_meta( $child_id, '_anchor_event_group_id', true ),
			'The stale pointer is still stored — that is the premise.'
		);
		$this->assertSame( 0, $this->occurrences()->parent_of( $child_id ) );
		$this->assertSame(
			'',
			$this->module()->render_sibling_dates( $child_id ),
			'No picker rather than a link to a trashed parent.'
		);
	}

	/**
	 * Losing the parent must not un-close a soft-closed occurrence: the engine's
	 * own closed flag, not the parent lookup, is what says "no longer available".
	 */
	public function test_soft_closed_child_stays_closed_after_its_parent_is_deleted() {
		$parent_id = $this->make_event( [ 'title' => 'Workshop', 'timezone' => 'UTC' ] );
		update_post_meta(
			$parent_id,
			'_anchor_event_offering_dates',
			[
				[ 'date' => '2027-06-01', 'start_time' => '09:00', 'end_time' => '11:00' ],
				[ 'date' => '2027-06-08', 'start_time' => '09:00', 'end_time' => '11:00' ],
			]
		);
		$children = $this->occurrences()->reconcile( $parent_id );
		$child_id = (int) $children[0];

		// A seated occurrence is preserved (soft-closed) rather than trashed when
		// its date is dropped from the parent.
		$this->make_seat( $child_id );
		update_post_meta(
			$parent_id,
			'_anchor_event_offering_dates',
			[ [ 'date' => '2027-06-08', 'start_time' => '09:00', 'end_time' => '11:00' ] ]
		);
		$this->occurrences()->reconcile( $parent_id );
		$this->assertTrue( $this->occurrences()->is_closed( $child_id ) );
		$this->assertSame( 'closed', $this->module()->bookability( $child_id ) );

		wp_delete_post( $parent_id, true );
		wp_cache_flush();

		$this->assertSame( 0, $this->occurrences()->parent_of( $child_id ) );
		$this->assertSame(
			'closed',
			$this->module()->bookability( $child_id ),
			'An orphaned soft-closed occurrence is still closed.'
		);
	}

	/** A group_id pointing at a non-event post resolves to 0. */
	public function test_parent_of_is_zero_for_wrong_post_type() {
		$child_id = $this->make_event( [ 'title' => 'Orphan' ] );
		$page_id  = self::factory()->post->create( [ 'post_type' => 'page' ] );
		update_post_meta( $child_id, '_anchor_event_group_role', 'child' );
		update_post_meta( $child_id, '_anchor_event_group_id', $page_id );

		$this->assertSame( 0, $this->occurrences()->parent_of( $child_id ) );
	}

	/* ------------------------------------------------------------------
	 * MODEL-D23 — derive_registration_mode()
	 * ------------------------------------------------------------------ */

	/** A legacy event whose managed product was deleted derives 'free', not 'wc'. */
	public function test_registration_mode_falls_back_to_free_when_managed_product_deleted() {
		list( $event_id, , $product_id ) = $this->paid_event();

		// Legacy shape: no explicit mode, and every tier free again.
		delete_post_meta( $event_id, '_anchor_event_registration_mode' );
		$this->ticket_types()->save(
			$event_id,
			[ [ 'id' => 'ga', 'label' => 'General', 'price' => '0', 'active' => 1 ] ]
		);
		update_post_meta( $event_id, Product_Sync::EVENT_PRODUCT_META, $product_id );

		wp_delete_post( $product_id, true );
		wp_cache_flush();

		$this->assertSame( 'free', $this->module()->registration_mode( $event_id ) );
	}

	/* ------------------------------------------------------------------
	 * WOO-D58 — a seat whose tier row was removed
	 * ------------------------------------------------------------------ */

	/** The roster/registrant tier column says "(retired tier)", not a bare id. */
	public function test_roster_labels_a_removed_tier_as_retired() {
		$event_id = $this->make_event(
			[ 'title' => 'Two Tier' ],
			[
				[ 'label' => 'General', 'price' => '10', 'active' => 1 ],
				[ 'label' => 'VIP', 'price' => '50', 'active' => 1 ],
			]
		);
		$tiers = $this->ticket_types()->get( $event_id );
		$this->assertSame( 'VIP', $this->module()->roster->tier_label( $event_id, $tiers[1]['id'] ) );

		$seat_id = $this->make_seat( $event_id, [ 'ticket_type_id' => $tiers[1]['id'] ] );
		$this->assertGreaterThan( 0, $seat_id );

		// The organizer removes the VIP row from the Tickets metabox.
		$this->ticket_types()->save(
			$event_id,
			[ [ 'id' => $tiers[0]['id'], 'label' => 'General', 'price' => '10', 'active' => 1 ] ]
		);
		$this->assertNull( $this->ticket_types()->find( $event_id, $tiers[1]['id'] ) );

		$label = $this->module()->roster->tier_label( $event_id, $tiers[1]['id'] );
		$this->assertStringContainsString( '(retired tier)', $label );
		$this->assertStringContainsString( $tiers[1]['id'], $label, 'The raw id stays visible for diagnosis.' );
	}

	/** A tier that EXISTS with a blank label is an authoring gap, not a dangling id. */
	public function test_a_blank_label_on_a_live_tier_is_not_marked_retired() {
		$event_id = $this->make_event( [ 'title' => 'Blank Label' ] );

		// Ticket_Types::save() defaults a blank label to "Registration", so write
		// the stored shape directly — the row exists, its label does not.
		update_post_meta(
			$event_id,
			\Anchor\Events\Ticket_Types::META_KEY,
			[ [ 'id' => 'ga', 'label' => '', 'price' => '10', 'active' => 1 ] ]
		);
		$this->assertNotNull( $this->ticket_types()->find( $event_id, 'ga' ) );

		$this->assertSame( 'ga', $this->module()->roster->tier_label( $event_id, 'ga' ) );
	}

	/** With the product gone, the demote still repairs the event side. */
	public function test_demote_clears_tier_ids_and_mirror_when_the_product_is_gone() {
		list( $event_id, $tier, $product_id ) = $this->paid_event();

		$this->assertGreaterThan( 0, (int) $this->ticket_types()->find( $event_id, $tier['id'] )['wc_variation_id'] );
		$this->assertNotSame( [], $this->woocommerce()->products_for_event( $event_id ) );

		// The product is deleted outright; the event keeps its stale pointer, its
		// cached variation id and its mirror.
		wp_delete_post( $product_id, true );
		wp_cache_flush();
		$this->assertSame( $product_id, $this->product_sync()->stored_product_id( $event_id ) );

		// Deactivate the tier → the demote branch, with no product to demote.
		$this->ticket_types()->save(
			$event_id,
			[ [ 'id' => $tier['id'], 'label' => 'General', 'price' => '10', 'active' => 0 ] ]
		);
		$this->assertSame( 0, (int) $this->product_sync()->sync_event( $event_id ) );

		$this->assertSame( 0, (int) $this->ticket_types()->find( $event_id, $tier['id'] )['wc_variation_id'] );
		$this->assertFalse( $this->woocommerce()->event_is_linked( $event_id ) );
	}

	/** An event that never had a product is untouched by the demote branch. */
	public function test_demote_is_a_no_op_for_an_event_that_never_had_a_product() {
		$event_id = $this->make_event(
			[ 'title' => 'Free' ],
			[ [ 'label' => 'Free', 'price' => '0', 'active' => 1 ] ]
		);

		$this->assertSame( 0, (int) $this->product_sync()->sync_event( $event_id ) );

		$this->assertSame( '', (string) get_post_meta( $event_id, Product_Sync::EVENT_PRODUCT_META, true ) );
		$this->assertSame(
			'',
			(string) get_post_meta( $event_id, '_anchor_event_linked_products', true ),
			'No mirror is minted for an event that never sold anything.'
		);
	}
}
