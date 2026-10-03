<?php
use Anchor\Shipping\Packing\Packer;

class Test_Shipping_Packer extends Anchor_Shipping_TestCase {

	public function set_up() {
		parent::set_up();
		update_option( 'woocommerce_weight_unit', 'kg' );
		update_option( 'woocommerce_dimension_unit', 'cm' );
		$this->configure_ups( [ 'boxes' => [
			[ 'id' => 'small', 'name' => 'Small', 'length' => 25.4, 'width' => 20.32, 'height' => 10.16, 'empty_weight' => 0.2, 'max_weight' => 2 ],
			[ 'id' => 'large', 'name' => 'Large', 'length' => 50, 'width' => 40, 'height' => 30, 'empty_weight' => 0.8, 'max_weight' => 20 ],
		] ] );
	}

	public function test_sizes_one_parcel_from_weights_and_the_largest_default_box() {
		$a     = $this->make_product( [ 'weight' => 0.5, 'box' => 'small' ] );
		$b     = $this->make_product( [ 'weight' => 1.0, 'box' => 'large' ] );
		$r     = ( new Packer() )->pack_order( $this->make_order( [ $a, $b ] ) );
		$this->assertTrue( $r->ok(), $r->reason );
		$this->assertCount( 1, $r->parcels );
		$this->assertSame( 'large', $r->parcels[0]->box_id );
		$this->assertEqualsWithDelta( 2.3, $r->parcels[0]->weight_kg, 0.001, 'items + empty box' );
		$this->assertEqualsWithDelta( 50.0, $r->parcels[0]->length_cm, 0.001 );
	}

	public function test_missing_weight_is_not_sizeable_but_still_suggests_a_box() {
		$a = $this->make_product( [ 'name' => 'Banner Stand', 'box' => 'large' ] );
		$r = ( new Packer() )->pack_order( $this->make_order( [ $a ] ) );
		$this->assertFalse( $r->ok() );
		$this->assertStringContainsString( 'Banner Stand', $r->reason );
		$this->assertSame( 'large', $r->suggestion['box'] );
	}

	public function test_missing_or_unknown_box_is_not_sizeable() {
		$a = $this->make_product( [ 'name' => 'Tip', 'weight' => 0.1 ] );
		$b = $this->make_product( [ 'name' => 'Odd', 'weight' => 0.1, 'box' => 'deleted-box' ] );
		$this->assertStringContainsString( 'Tip', ( new Packer() )->pack_order( $this->make_order( [ $a ] ) )->reason );
		$this->assertStringContainsString( 'Odd', ( new Packer() )->pack_order( $this->make_order( [ $b ] ) )->reason );
	}

	public function test_too_heavy_for_the_box_is_not_sizeable() {
		$a = $this->make_product( [ 'weight' => 1.9, 'box' => 'small' ] );
		$r = ( new Packer() )->pack_order( $this->make_order( [ $a ] ) );
		$this->assertFalse( $r->ok() );
		$this->assertStringContainsString( 'Small', $r->reason );
	}

	public function test_virtual_items_are_ignored_and_subtotal_counts_only_shippable_lines() {
		$ship   = $this->make_product( [ 'weight' => 0.5, 'box' => 'small', 'price' => 110 ] );
		$ticket = $this->make_product( [ 'virtual' => true, 'price' => 2500 ] );
		$order  = $this->make_order( [ $ship, $ticket ] );
		$this->assertTrue( ( new Packer() )->pack_order( $order )->ok() );
		$this->assertSame( 110.0, ( new Packer() )->shippable_subtotal( $order ) );
	}

	public function test_subtotal_is_pre_discount_so_a_coupon_cannot_lower_the_declared_value() {
		$this->configure_ups( [ 'insurance' => [ 'mode' => 'auto', 'threshold' => 100 ] ] );
		$order  = $this->make_order( [ $this->make_product( [ 'price' => 500, 'weight' => 0.5, 'box' => 'small' ] ) ] );
		$coupon = new WC_Coupon();
		$coupon->set_code( 'save200' );
		$coupon->set_discount_type( 'fixed_cart' );
		$coupon->set_amount( '200' );
		$coupon->save();
		$order->apply_coupon( $coupon );
		$order->calculate_totals();
		$order->save();
		$this->assertSame( 500.0, ( new Packer() )->shippable_subtotal( $order ) );
		$this->assertSame( 500.0, \Anchor\Shipping\Services\Protection::for_order( $order, ( new Packer() )->shippable_subtotal( $order ) )['declared_value'] );
	}
}
