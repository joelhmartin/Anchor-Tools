<?php
/**
 * Ticket_Types model tests (no WooCommerce required).
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Ticket_Types;

/**
 * @group ticket-types
 */
class Test_Ticket_Types extends Anchor_Events_TestCase {

	/** No stored tiers → a single implicit 'primary' tier priced from event meta. */
	public function test_implicit_primary_fallback() {
		$event_id = $this->make_event( [ 'price' => '25.00' ] );

		$tiers = $this->ticket_types()->get( $event_id );

		$this->assertCount( 1, $tiers );
		$this->assertSame( Ticket_Types::PRIMARY_ID, $tiers[0]['id'] );
		$this->assertSame( 25.0, $tiers[0]['price'] );
		$this->assertTrue( $tiers[0]['active'] );
		$this->assertSame( Ticket_Types::PRIMARY_ID, $this->ticket_types()->primary_id( $event_id ) );
	}

	/**
	 * WOO-D57: the `attendee_fields` tier field was authored nowhere (no
	 * admin UI ever posted it) and read nowhere except by this model's own
	 * default-substitution — render_checkout_attendee_fields() hard-codes
	 * name/email/phone regardless of what a tier's attendee_fields held.
	 * Deleted from the model rather than wired up, since nothing depended on
	 * its presence.
	 */
	public function test_attendee_fields_is_not_part_of_the_tier_shape() {
		$event_id = $this->make_event( [ 'price' => '10' ] );
		$implicit = $this->ticket_types()->get( $event_id );
		$this->assertArrayNotHasKey( 'attendee_fields', $implicit[0] );

		$saved = $this->ticket_types()->save(
			$event_id,
			[ [ 'label' => 'General', 'price' => '10', 'active' => 1, 'attendee_fields' => [ 'custom' ] ] ]
		);
		$this->assertArrayNotHasKey( 'attendee_fields', $saved[0] );

		$read = $this->ticket_types()->get( $event_id );
		$this->assertArrayNotHasKey( 'attendee_fields', $read[0] );
	}

	/** save() assigns stable ids that are preserved across a re-save. */
	public function test_save_assigns_stable_ids_preserved_across_resave() {
		$event_id = $this->make_event();

		$saved = $this->ticket_types()->save(
			$event_id,
			[
				[ 'label' => 'General', 'price' => '10', 'active' => 1 ],
				[ 'label' => 'VIP', 'price' => '50', 'active' => 1 ],
			]
		);

		$this->assertCount( 2, $saved );
		$id_general = $saved[0]['id'];
		$id_vip     = $saved[1]['id'];
		$this->assertNotSame( '', $id_general );
		$this->assertNotSame( $id_general, $id_vip );

		// Re-save with the ids supplied + a price change → ids must be preserved.
		$resaved = $this->ticket_types()->save(
			$event_id,
			[
				[ 'id' => $id_general, 'label' => 'General', 'price' => '12', 'active' => 1 ],
				[ 'id' => $id_vip, 'label' => 'VIP', 'price' => '50', 'active' => 1 ],
			]
		);

		$this->assertSame( $id_general, $resaved[0]['id'] );
		$this->assertSame( $id_vip, $resaved[1]['id'] );
		$this->assertSame( 12.0, $resaved[0]['price'] );

		// find() resolves by the stable id.
		$found = $this->ticket_types()->find( $event_id, $id_vip );
		$this->assertNotNull( $found );
		$this->assertSame( 'VIP', $found['label'] );
	}

	/** Removing a tier row drops it from the stored list; survivors keep their ids. */
	public function test_removing_a_tier() {
		$event_id = $this->make_event();
		$saved    = $this->ticket_types()->save(
			$event_id,
			[
				[ 'label' => 'General', 'price' => '10', 'active' => 1 ],
				[ 'label' => 'VIP', 'price' => '50', 'active' => 1 ],
			]
		);
		$id_general = $saved[0]['id'];

		// Re-save with only the General row.
		$after = $this->ticket_types()->save(
			$event_id,
			[
				[ 'id' => $id_general, 'label' => 'General', 'price' => '10', 'active' => 1 ],
			]
		);

		$this->assertCount( 1, $after );
		$this->assertSame( $id_general, $after[0]['id'] );
		$this->assertNull( $this->ticket_types()->find( $event_id, $saved[1]['id'] ) );
	}

	/** Saving an empty list removes the meta and falls back to the implicit primary. */
	public function test_empty_save_falls_back_to_implicit_primary() {
		$event_id = $this->make_event( [ 'price' => '5' ] );
		$this->ticket_types()->save( $event_id, [ [ 'label' => 'X', 'price' => '9', 'active' => 1 ] ] );

		$after = $this->ticket_types()->save( $event_id, [] );

		$this->assertCount( 1, $after );
		$this->assertSame( Ticket_Types::PRIMARY_ID, $after[0]['id'] );
		$this->assertSame( '', get_post_meta( $event_id, Ticket_Types::META_KEY, true ) );
	}

	/**
	 * WOO-D28: a row with a quota but no label/price/dates must NOT be
	 * dropped as "empty" — the old emptiness test only looked at
	 * label/price/sale_start/sale_end, so an admin who set a quota and
	 * forgot the label lost the row (and the quota) silently.
	 */
	public function test_save_keeps_a_row_with_only_a_quota() {
		$event_id = $this->make_event();

		$saved = $this->ticket_types()->save( $event_id, [ [ 'quota' => 25 ] ] );

		$this->assertCount( 1, $saved );
		$this->assertSame( 25, $saved[0]['quota'] );
	}

	/** Same, for a row whose only real content is the active flag. */
	public function test_save_keeps_a_row_with_only_the_active_flag_set() {
		$event_id = $this->make_event();

		$saved = $this->ticket_types()->save( $event_id, [ [ 'active' => 1 ] ] );

		$this->assertCount( 1, $saved );
		$this->assertTrue( $saved[0]['active'] );
	}

	/** …and for a row whose only real content is a synced wc_variation_id. */
	public function test_save_keeps_a_row_with_only_a_wc_variation_id() {
		$event_id = $this->make_event();

		$saved = $this->ticket_types()->save( $event_id, [ [ 'wc_variation_id' => 42 ] ] );

		$this->assertCount( 1, $saved );
		$this->assertSame( 42, $saved[0]['wc_variation_id'] );
	}

	/** A row with truly nothing set is still dropped. */
	public function test_save_still_drops_a_truly_empty_row() {
		$event_id = $this->make_event();

		$saved = $this->ticket_types()->save(
			$event_id,
			[ [ 'label' => 'General', 'price' => '10', 'active' => 1 ], [] ]
		);

		$this->assertCount( 1, $saved );
	}

	/**
	 * WOO-D40: normalize() (the read path) deliberately preserves a blank
	 * label as '' — Roster::tier_label() depends on being able to tell "the
	 * row exists but its label is blank" (an authoring gap, shown as the raw
	 * tier id) apart from "the row is gone" (shown as "(retired tier)").
	 * save() legitimately defaults a blank label to "Registration" when an
	 * admin actually submits the tickets form with an empty label field —
	 * that direct author-initiated save is fine. The bug was a DIFFERENT
	 * write path (Product_Sync::write_back_variation_ids(), which only wants
	 * to update a cached wc_variation_id) round-tripping through save() and
	 * picking up its label default as an unintended side effect; that is
	 * fixed at the write side, not by changing what normalize() reports.
	 */
	public function test_normalize_preserves_a_blank_label_while_save_still_defaults_it() {
		$event_id = $this->make_event();
		update_post_meta(
			$event_id,
			Ticket_Types::META_KEY,
			[ [ 'id' => 'abc123', 'label' => '', 'price' => '10', 'active' => true, 'quota' => 0, 'sale_start' => '', 'sale_end' => '', 'wc_variation_id' => 0 ] ]
		);

		$read = $this->ticket_types()->get( $event_id );
		$this->assertSame( '', $read[0]['label'], 'The read path must NOT invent a label for an existing row.' );

		// An admin who actually submits the tickets FORM with a blank label
		// still gets the shipped default — this is save()'s existing,
		// deliberate behavior and WOO-D40 does not change it.
		$resaved = $this->ticket_types()->save(
			$event_id,
			[ [ 'id' => 'abc123', 'label' => '', 'price' => '10', 'active' => 1 ] ]
		);
		$this->assertSame( 'Registration', $resaved[0]['label'] );
	}

	/**
	 * WOO-D29: a reversed sale window (sale_end before sale_start) made a
	 * tier permanently unsellable while still advertising "Sales open
	 * <sale_start>" forever. save() swaps the pair rather than storing a
	 * window that can never open.
	 */
	public function test_save_swaps_a_reversed_sale_window() {
		$event_id = $this->make_event();

		$saved = $this->ticket_types()->save(
			$event_id,
			[ [ 'label' => 'General', 'price' => '10', 'active' => 1, 'sale_start' => '2026-12-01', 'sale_end' => '2026-11-01' ] ]
		);

		$this->assertSame( '2026-11-01', $saved[0]['sale_start'] );
		$this->assertSame( '2026-12-01', $saved[0]['sale_end'] );
	}

	/** A correctly-ordered window is left alone. */
	public function test_save_does_not_touch_a_correctly_ordered_window() {
		$event_id = $this->make_event();

		$saved = $this->ticket_types()->save(
			$event_id,
			[ [ 'label' => 'General', 'price' => '10', 'active' => 1, 'sale_start' => '2026-01-01', 'sale_end' => '2026-02-01' ] ]
		);

		$this->assertSame( '2026-01-01', $saved[0]['sale_start'] );
		$this->assertSame( '2026-02-01', $saved[0]['sale_end'] );
	}

	/** is_on_sale() respects the optional [sale_start, sale_end] window. */
	public function test_is_on_sale_window() {
		$tt  = $this->ticket_types();
		$now = strtotime( '2026-06-15 12:00:00' );

		// No window → always on sale.
		$this->assertTrue( $tt->is_on_sale( [ 'sale_start' => '', 'sale_end' => '' ], $now ) );

		// Inside the window.
		$this->assertTrue(
			$tt->is_on_sale( [ 'sale_start' => '2026-06-01', 'sale_end' => '2026-06-30' ], $now )
		);

		// Before the window opens.
		$this->assertFalse(
			$tt->is_on_sale( [ 'sale_start' => '2026-07-01', 'sale_end' => '' ], $now )
		);

		// After the window closes (sale_end is inclusive, end-of-day).
		$this->assertFalse(
			$tt->is_on_sale( [ 'sale_start' => '', 'sale_end' => '2026-06-14' ], $now )
		);

		// On the last day → still on sale (end-of-day inclusive).
		$this->assertTrue(
			$tt->is_on_sale( [ 'sale_start' => '', 'sale_end' => '2026-06-15' ], $now )
		);
	}

	/**
	 * WOO-D5: is_on_sale()/sale_state() compare Y-m-d strings in the SITE's
	 * timezone, not `strtotime($date.' 00:00:00')` (parsed in PHP's default
	 * timezone, forced to UTC by WordPress) against a raw timestamp. On a
	 * site with a negative gmt_offset the old comparison opened a sale window
	 * hours before the site's own local calendar day reached sale_start.
	 */
	public function test_is_on_sale_compares_in_site_local_time_not_utc() {
		update_option( 'timezone_string', '' );
		update_option( 'gmt_offset', -5 );
		$this->assertSame( '-05:00', wp_timezone_string(), 'Precondition: a negative-offset site.' );

		$tier = [ 'sale_start' => '2026-09-04', 'sale_end' => '' ];

		// 2026-09-04 00:30 UTC == 2026-09-03 19:30 site-local (UTC-5): the sale
		// window has NOT opened yet in the site's own local day.
		$now = gmmktime( 0, 30, 0, 9, 4, 2026 );
		$this->assertSame( '2026-09-03', wp_date( 'Y-m-d', $now ), 'Precondition: local date is still the 3rd.' );

		$this->assertFalse( $this->ticket_types()->is_on_sale( $tier, $now ) );
		$this->assertSame( 'before', $this->ticket_types()->sale_state( $tier, $now ) );
	}

	/**
	 * WOO-D4: sale_state() tells "not open yet" apart from "already closed" —
	 * a CLOSED tier must never be reported as "before" (which the storefront
	 * renders as "Sales open <sale_start>", advertising a future opening for a
	 * window that has already ended).
	 */
	public function test_sale_state_distinguishes_before_from_after() {
		$now = strtotime( '2026-09-03 12:00:00' );

		$this->assertSame(
			'after',
			$this->ticket_types()->sale_state( [ 'sale_start' => '2026-01-01', 'sale_end' => '2026-02-01' ], $now )
		);
		$this->assertSame(
			'before',
			$this->ticket_types()->sale_state( [ 'sale_start' => '2026-12-01', 'sale_end' => '' ], $now )
		);
		$this->assertSame(
			'open',
			$this->ticket_types()->sale_state( [ 'sale_start' => '', 'sale_end' => '' ], $now )
		);
	}

	/** primary_id() returns the first ACTIVE tier id. */
	public function test_primary_id_is_first_active_tier() {
		$event_id = $this->make_event();
		$saved    = $this->ticket_types()->save(
			$event_id,
			[
				[ 'label' => 'Inactive', 'price' => '10', 'active' => 0 ],
				[ 'label' => 'Active', 'price' => '20', 'active' => 1 ],
			]
		);

		$this->assertSame( $saved[1]['id'], $this->ticket_types()->primary_id( $event_id ) );
	}
}
