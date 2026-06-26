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
