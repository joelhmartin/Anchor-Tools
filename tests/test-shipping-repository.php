<?php
use Anchor\Shipping\Database\Migrations;
use Anchor\Shipping\Module;

class Test_Shipping_Repository extends Anchor_Shipping_TestCase {

	public function test_module_boots_and_creates_the_table() {
		global $wpdb;
		$this->assertInstanceOf( Module::class, Module::instance() );
		$this->assertSame( Migrations::table(), $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Migrations::table() ) ) );
	}

	public function test_insert_find_and_active_for_order() {
		$repo = Module::instance()->shipments;
		$a    = $repo->insert( [ 'order_id' => 10, 'carrier' => 'ups', 'shipment_id' => 'S1', 'tracking_number' => '1Z1' ] );
		$b    = $repo->insert( [ 'order_id' => 10, 'carrier' => 'ups', 'shipment_id' => 'S2', 'tracking_number' => '1Z2' ] );
		$repo->update( $b, [ 'status' => 'voided', 'voided_at' => current_time( 'mysql', true ) ] );

		$this->assertSame( '1Z1', $repo->find( $a )['tracking_number'] );
		$this->assertSame( 'label_created', $repo->find( $a )['status'] );
		$this->assertCount( 2, $repo->for_order( 10 ) );
		$this->assertSame( [ (string) $a ], array_column( $repo->active_for_order( 10 ), 'id' ) );
		$this->assertCount( 1, $repo->by_shipment_id( 'ups', 'S2' ) );
		$this->assertNull( $repo->find( 999999 ) );
	}
}
