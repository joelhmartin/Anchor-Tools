<?php
use Anchor\Shipping\Carriers\CarrierInterface;
use Anchor\Shipping\Domain\Address;
use Anchor\Shipping\Domain\Parcel;
use Anchor\Shipping\Module;

class Test_Shipping_Domain extends Anchor_Shipping_TestCase {

	public function test_parcel_converts_to_pounds_and_inches() {
		$p = new Parcel( 1.0, 25.4, 20.32, 10.16 );
		$this->assertSame( 2.2, $p->weight_lb() );
		$this->assertSame( [ 10, 8, 4 ], $p->dims_in() );
		$this->assertSame( 0.1, ( new Parcel( 0.001, 1, 1, 1 ) )->weight_lb(), 'UPS minimum is 0.1 lb' );
	}

	public function test_parcel_rejects_zero_weight() {
		$this->expectException( InvalidArgumentException::class );
		new Parcel( 0.0, 10, 10, 10 );
	}

	public function test_from_store_units_honours_store_units() {
		update_option( 'woocommerce_weight_unit', 'lbs' );
		update_option( 'woocommerce_dimension_unit', 'in' );
		$p = Parcel::from_store_units( 2.2, 10, 8, 4 );
		$this->assertEqualsWithDelta( 0.998, $p->weight_kg, 0.01 );
		$this->assertEqualsWithDelta( 25.4, $p->length_cm, 0.01 );
	}

	public function test_address_from_order_prefers_shipping_and_falls_back_to_billing_phone() {
		$a = Address::from_order_shipping( $this->make_order() );
		$this->assertSame( 'Pat Doe', $a->name );
		$this->assertSame( '1 Infinite Loop', $a->line1 );
		$this->assertSame( '5555555555', $a->phone );
		$this->assertTrue( $a->is_complete() );
	}

	public function test_registry_ships_ups_and_accepts_third_party_carriers() {
		$registry = Module::instance()->carriers;
		$this->assertInstanceOf( CarrierInterface::class, $registry->get( 'ups' ) );
		$this->assertNull( $registry->get( 'nope' ) );

		$fake = $this->createMock( CarrierInterface::class );
		add_filter( 'anchor_shipping_carriers', static fn( $c ) => $c + [ 'fake' => $fake, 'junk' => new stdClass() ] );
		$registry->reset();
		$this->assertSame( $fake, $registry->get( 'fake' ) );
		$this->assertNull( $registry->get( 'junk' ), 'non-carriers are dropped' );
		$registry->reset();
	}
}
