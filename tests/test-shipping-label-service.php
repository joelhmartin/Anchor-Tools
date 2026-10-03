<?php
use Anchor\Shipping\Domain\CarrierError;
use Anchor\Shipping\Domain\Parcel;
use Anchor\Shipping\Module;
use Anchor\Shipping\Services\LabelService;
use Anchor\Shipping\Services\LabelStore;
use Anchor\Shipping\Services\Protection;
use Anchor\Shipping\Support\Settings;

class Test_Shipping_Label_Service extends Anchor_Shipping_TestCase {

	private function parcel(): Parcel {
		return new Parcel( 1.0, 25.4, 20.32, 10.16, 'small' );
	}

	private function create( WC_Order $order, array $opts = [] ): array {
		$this->queue_token();
		$this->queue_response( 200, $this->fixture( 'ups-ship-single' ) );
		return Module::instance()->labels->create_for_order( $order, [ $this->parcel() ], 'manual', $opts );
	}

	public function test_create_stores_rows_label_note_and_state() {
		$this->configure_ups();
		$order = $this->make_order( [], 'processing' );
		$fired = null;
		add_action( 'anchor_shipping_label_created', static function ( $id, $ids ) use ( &$fired ) { $fired = [ $id, $ids ]; }, 10, 2 );

		$ids = $this->create( $order );

		$row = Module::instance()->shipments->find( $ids[0] );
		$this->assertSame( '1ZK877V90300000001', $row['tracking_number'] );
		$this->assertSame( '1ZK877V90300000001', $row['shipment_id'] );
		$this->assertSame( '11.66', $row['cost'] );
		$this->assertSame( 'PDF', $row['label_format'] );
		$this->assertNotNull( Module::instance()->store->absolute( $row['label_path'] ) );
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'labelled', $order->get_meta( LabelService::STATE_META ) );
		$this->assertStringContainsString( '1ZK877V90300000001', implode( ' ', wp_list_pluck( wc_get_order_notes( [ 'order_id' => $order->get_id() ] ), 'content' ) ) );
		$this->assertSame( [ $order->get_id(), $ids ], $fired );
	}

	public function test_second_create_is_refused_without_additional_flag() {
		$this->configure_ups();
		$order = $this->make_order( [], 'processing' );
		$this->create( $order );
		try {
			Module::instance()->labels->create_for_order( $order, [ $this->parcel() ], 'manual' );
			$this->fail( 'expected LogicException' );
		} catch ( LogicException $e ) {
			$this->assertCount( 2, $this->requests, 'no second carrier call' );
		}
		$this->queue_response( 200, $this->fixture( 'ups-ship-single' ) ); // token already cached
		$this->assertCount( 1, Module::instance()->labels->create_for_order( $order, [ $this->parcel() ], 'manual', [ 'additional' => true ] ), 'an explicit additional package is allowed' );
	}

	public function test_carrier_failure_writes_nothing() {
		$this->configure_ups();
		$order = $this->make_order( [], 'processing' );
		$this->queue_token();
		$this->queue_response( 400, [ 'response' => [ 'errors' => [ [ 'code' => '120100', 'message' => 'Missing or invalid shipper number' ] ] ] ] );
		$this->expectException( CarrierError::class );
		try {
			Module::instance()->labels->create_for_order( $order, [ $this->parcel() ], 'manual' );
		} finally {
			$this->assertSame( [], Module::instance()->shipments->for_order( $order->get_id() ) );
		}
	}

	public function test_save_failure_after_the_label_exists_notes_the_tracking_number_and_rethrows() {
		$this->configure_ups();
		$order = $this->make_order( [], 'processing' );
		$dir   = sys_get_temp_dir() . '/anchor-shipping-ro-' . wp_generate_password( 8, false );
		wp_mkdir_p( $dir );
		file_put_contents( $dir . '/.htaccess', 'x' );
		file_put_contents( $dir . '/index.php', 'x' );
		chmod( $dir, 0555 );
		$labels = new LabelService( Module::instance()->carriers, Module::instance()->shipments, new LabelStore( $dir ), Module::instance()->packer );

		$this->queue_token();
		$this->queue_response( 200, $this->fixture( 'ups-ship-single' ) );
		try {
			$labels->create_for_order( $order, [ $this->parcel() ], 'manual' );
			$this->fail( 'expected RuntimeException' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( '1ZK877V90300000001', $e->getMessage() );
			$this->assertStringContainsString( 'could not be saved', $e->getMessage() );
		} finally {
			chmod( $dir, 0755 );
			array_map( 'unlink', glob( $dir . '/{,.}*[!.]*', GLOB_BRACE ) ?: [] );
			rmdir( $dir );
		}
		$notes = implode( ' ', wp_list_pluck( wc_get_order_notes( [ 'order_id' => $order->get_id() ] ), 'content' ) );
		$this->assertStringContainsString( '1ZK877V90300000001', $notes );
		$this->assertStringContainsString( 'could not be saved', $notes );
	}

	public function test_void_marks_every_package_of_the_shipment_and_is_idempotent() {
		$this->configure_ups();
		$order = $this->make_order( [], 'processing' );
		$this->queue_token();
		$this->queue_response( 200, $this->fixture( 'ups-ship-multi' ) );
		$ids = Module::instance()->labels->create_for_order( $order, [ $this->parcel(), $this->parcel() ], 'manual' );

		$this->queue_response( 200, $this->fixture( 'ups-void-ok' ) ); // token cached
		Module::instance()->labels->void_shipment( $ids[0] );
		Module::instance()->labels->void_shipment( $ids[1] ); // already voided: no HTTP

		foreach ( $ids as $id ) {
			$this->assertSame( 'voided', Module::instance()->shipments->find( $id )['status'] );
		}
		$this->assertCount( 3, $this->requests );
		$this->assertSame( '', wc_get_order( $order->get_id() )->get_meta( LabelService::STATE_META ) );
	}

	public function test_protection_auto_mode_uses_threshold_and_customer_mode_uses_order_meta() {
		$order = $this->make_order( [ $this->make_product( [ 'price' => 450, 'weight' => 0.5, 'box' => 'small' ] ) ] );
		$this->configure_ups( [ 'insurance' => [ 'mode' => 'auto', 'threshold' => 100 ], 'signature' => [ 'mode' => 'auto', 'threshold' => 500 ] ] );
		$this->assertSame( [ 'declared_value' => 450.0, 'signature' => false ], Protection::for_order( $order, 450.0 ) );

		$this->configure_ups( [ 'insurance' => [ 'mode' => 'customer', 'fee_per_100' => '1.25' ], 'signature' => [ 'mode' => 'customer', 'fee' => '7.70' ] ] );
		$this->assertSame( [ 'declared_value' => 0.0, 'signature' => false ], Protection::for_order( $order, 450.0 ) );
		$order->update_meta_data( Protection::META_INSURE, 'yes' );
		$order->update_meta_data( Protection::META_SIGNATURE, 'yes' );
		$this->assertSame( [ 'declared_value' => 450.0, 'signature' => true ], Protection::for_order( $order, 450.0 ) );
	}

	public function test_insurance_fee_rule() {
		$this->configure_ups( [ 'insurance' => [ 'mode' => 'customer', 'fee_per_100' => '1.25' ] ] );
		$this->assertSame( 0.0, Protection::insurance_fee( 100.0 ) );
		$this->assertSame( 1.25, Protection::insurance_fee( 100.01 ) );
		$this->assertSame( 5.0, Protection::insurance_fee( 450.0 ) );
		$this->assertTrue( Protection::offered( 'insurance' ) );
		$this->configure_ups( [ 'insurance' => [ 'mode' => 'customer', 'fee_per_100' => '' ] ] );
		$this->assertFalse( Protection::offered( 'insurance' ), 'hidden until a fee is set' );
	}

	public function test_explicit_protection_opts_override_and_reach_the_carrier() {
		$this->configure_ups();
		$order = $this->make_order( [], 'processing' );
		$ids   = $this->create( $order, [ 'declared_value' => 900.0, 'signature' => true ] );
		$body  = json_decode( $this->requests[1]['args']['body'], true );
		$this->assertSame( '900.00', $body['ShipmentRequest']['Shipment']['Package'][0]['PackageServiceOptions']['DeclaredValue']['MonetaryValue'] );
		$row = Module::instance()->shipments->find( $ids[0] );
		$this->assertSame( '900.00', $row['declared_value'] );
		$this->assertSame( '1', $row['signature'] );
	}
}
