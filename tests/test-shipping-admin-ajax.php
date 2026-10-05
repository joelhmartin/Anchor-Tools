<?php
use Anchor\Shipping\Admin\Ajax;
use Anchor\Shipping\Module;

class Test_Shipping_Admin_Ajax extends Anchor_Shipping_TestCase {

	private function ajax(): Ajax {
		$m = Module::instance();
		return new Ajax( $m->labels, $m->shipments, $m->store, $m->packer, $m->carriers );
	}

	private function as_role( string $role ): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => $role ] ) );
	}

	public function test_create_with_a_box_preset_builds_the_parcel_and_returns_the_panel() {
		update_option( 'woocommerce_weight_unit', 'kg' );
		update_option( 'woocommerce_dimension_unit', 'cm' );
		$this->configure_ups();
		$this->as_role( 'shop_manager' );
		$order = $this->make_order( [], 'processing' );
		$this->queue_token();
		$this->queue_response( 200, $this->fixture( 'ups-ship-single' ) );

		$out = $this->ajax()->handle_create( [ 'order_id' => $order->get_id(), 'carrier' => 'ups', 'service' => '03', 'box' => 'small', 'weight' => '1' ] );

		$this->assertStringContainsString( '1ZK877V90300000001', $out['html'] );
		$body = json_decode( $this->requests[1]['args']['body'], true );
		$this->assertSame( '2.2', $body['ShipmentRequest']['Shipment']['Package'][0]['PackageWeight']['Weight'] );
		$this->assertSame( '10', $body['ShipmentRequest']['Shipment']['Package'][0]['Dimensions']['Length'] );
	}

	public function test_create_rejects_missing_weight_and_custom_dims_without_values() {
		$this->configure_ups();
		$this->as_role( 'shop_manager' );
		$order = $this->make_order( [], 'processing' );
		foreach ( [ [ 'box' => 'small', 'weight' => '0' ], [ 'box' => 'custom', 'weight' => '1', 'length' => '', 'width' => '1', 'height' => '1' ] ] as $bad ) {
			try {
				$this->ajax()->handle_create( [ 'order_id' => $order->get_id() ] + $bad );
				$this->fail( 'expected RuntimeException' );
			} catch ( RuntimeException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
		$this->assertCount( 0, $this->requests );
	}

	public function test_customers_are_not_authorized() {
		$this->as_role( 'customer' );
		$this->assertFalse( $this->ajax()->authorized() );
		$this->as_role( 'shop_manager' );
		$this->assertTrue( $this->ajax()->authorized() );
	}

	public function test_download_resolves_the_file_and_rejects_unknown_rows() {
		$this->configure_ups();
		$this->as_role( 'shop_manager' );
		$order = $this->make_order( [], 'processing' );
		$this->queue_token();
		$this->queue_response( 200, $this->fixture( 'ups-ship-single' ) );
		$ids = Module::instance()->labels->create_for_order( $order, [ new \Anchor\Shipping\Domain\Parcel( 1, 10, 10, 10 ) ], 'manual' );

		$dl = $this->ajax()->handle_download( $ids[0] );
		$this->assertFileExists( $dl['path'] );
		$this->assertSame( 'application/pdf', $dl['type'] );
		$this->assertSame( 'label-1ZK877V90300000001.pdf', $dl['filename'] );

		$this->expectException( RuntimeException::class );
		$this->ajax()->handle_download( 999999 );
	}

	public function test_panel_shows_protection_defaults_and_hides_create_when_labelled() {
		$this->configure_ups( [ 'insurance' => [ 'mode' => 'auto', 'threshold' => 50 ] ] );
		$this->as_role( 'shop_manager' );
		$order = $this->make_order( [], 'processing' );
		$m     = Module::instance();
		$panel = ( new \Anchor\Shipping\Admin\OrderPanel( $m->shipments, $m->packer, $m->carriers ) )->render_panel( $order );
		$this->assertMatchesRegularExpression( '/name="insure" value="1"\s+checked=/', $panel );
		$this->assertStringContainsString( 'anchor-shipping-create', $panel );
	}

	private function unrecorded_order(): WC_Order {
		$order = $this->make_order( [], 'processing' );
		$order->update_meta_data( '_anchor_shipping_unrecorded', '1ZUNREC0001' );
		$order->save();
		return $order;
	}

	public function test_panel_warns_about_an_unrecorded_shipment_and_asks_for_a_void_confirmation() {
		$this->configure_ups();
		$this->as_role( 'shop_manager' );
		$order = $this->unrecorded_order();
		$m     = Module::instance();
		$panel = ( new \Anchor\Shipping\Admin\OrderPanel( $m->shipments, $m->packer, $m->carriers ) )->render_panel( $order );
		$this->assertStringContainsString( 'UPS shipment 1ZUNREC0001 was created but not recorded here', $panel );
		$this->assertStringContainsString( 'name="unrecorded_voided" value="1ZUNREC0001" required', $panel );
		$this->assertStringContainsString( 'UPS shipment 1ZUNREC0001 has been voided at ups.com', $panel );
		$this->assertStringNotContainsString( 'name="additional"', $panel, 'additional package is not a way past an unrecorded shipment' );
	}

	public function test_additional_package_does_not_bypass_an_unrecorded_shipment() {
		$this->configure_ups();
		$this->as_role( 'shop_manager' );
		$order = $this->unrecorded_order();
		foreach ( [ [ 'additional' => '1' ], [ 'additional' => '1', 'unrecorded_voided' => '1ZOTHER0002' ], [ 'unrecorded_voided' => '' ] ] as $post ) {
			try {
				$this->ajax()->handle_create( [ 'order_id' => $order->get_id(), 'box' => 'small', 'weight' => '1' ] + $post );
				$this->fail( 'expected refusal' );
			} catch ( RuntimeException $e ) {
				$this->assertStringContainsString( '1ZUNREC0001', $e->getMessage() );
			}
		}
		$this->assertCount( 0, $this->requests, 'the carrier is never called' );
		$this->assertSame( '1ZUNREC0001', wc_get_order( $order->get_id() )->get_meta( '_anchor_shipping_unrecorded' ) );
	}

	public function test_confirming_the_void_creates_the_replacement_clears_the_marker_and_notes_who_confirmed() {
		$this->configure_ups();
		$this->as_role( 'shop_manager' );
		$order = $this->unrecorded_order();
		$this->queue_token();
		$this->queue_response( 200, $this->fixture( 'ups-ship-single' ) );

		$out = $this->ajax()->handle_create( [ 'order_id' => $order->get_id(), 'box' => 'small', 'weight' => '1', 'unrecorded_voided' => '1ZUNREC0001' ] );

		$this->assertStringContainsString( '1ZK877V90300000001', $out['html'] );
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( '', $order->get_meta( '_anchor_shipping_unrecorded' ) );
		$notes = implode( ' ', wp_list_pluck( wc_get_order_notes( [ 'order_id' => $order->get_id() ] ), 'content' ) );
		$this->assertStringContainsString( '1ZUNREC0001 confirmed voided', $notes );
		$this->assertStringContainsString( wp_get_current_user()->user_login . ' (#' . get_current_user_id() . ')', $notes );
		$this->assertStringNotContainsString( 'unrecorded_voided', $out['html'] );
	}

	public function test_auto_job_stays_blocked_by_an_unrecorded_shipment() {
		$this->configure_ups( [ 'auto_label' => true ] );
		$order = $this->unrecorded_order();
		Module::instance()->paid_orders->run( $order->get_id() );
		$this->assertCount( 0, $this->requests );
		$this->assertSame( '1ZUNREC0001', wc_get_order( $order->get_id() )->get_meta( '_anchor_shipping_unrecorded' ) );
		$this->expectException( \Anchor\Shipping\Domain\AlreadyLabelled::class );
		Module::instance()->labels->create_for_order( $order, [ new \Anchor\Shipping\Domain\Parcel( 1, 10, 10, 10 ) ], 'auto', [ 'additional' => true ] );
	}
}
