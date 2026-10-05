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
		$note_text = implode( ' ', wp_list_pluck( wc_get_order_notes( [ 'order_id' => $order->get_id() ] ), 'content' ) );
		$this->assertStringContainsString( '1ZK877V90300000001', $note_text );
		$this->assertStringContainsString( '$11.66', $note_text );
		$this->assertStringNotContainsString( '&#36;', $note_text );
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

	public function test_unwritable_label_dir_is_refused_before_any_carrier_call() {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			$this->markTestSkipped( 'chmod-based failure cannot be forced as root.' );
		}
		$this->configure_ups();
		$order = $this->make_order( [], 'processing' );
		$dir   = sys_get_temp_dir() . '/anchor-shipping-ro-' . wp_generate_password( 8, false );
		wp_mkdir_p( $dir );
		file_put_contents( $dir . '/.htaccess', 'x' );
		file_put_contents( $dir . '/index.php', 'x' );
		chmod( $dir, 0555 );
		$labels = new LabelService( Module::instance()->carriers, Module::instance()->shipments, new LabelStore( $dir ), Module::instance()->packer );
		try {
			$labels->create_for_order( $order, [ $this->parcel() ], 'manual' );
			$this->fail( 'expected CarrierError' );
		} catch ( CarrierError $e ) {
			$this->assertSame( 'setup', $e->carrier_code );
			$this->assertStringContainsString( 'not writable', $e->getMessage() );
		} finally {
			chmod( $dir, 0755 );
			array_map( 'unlink', glob( $dir . '/{,.}*[!.]*', GLOB_BRACE ) ?: [] );
			rmdir( $dir );
		}
		$this->assertCount( 0, $this->requests, 'no carrier call, nothing billed' );
	}

	public function test_save_failure_after_billing_keeps_the_row_blocks_a_second_label_and_uses_the_problem_channel() {
		$this->configure_ups();
		$order = $this->make_order( [], 'processing' );
		$this->queue_token();
		$this->queue_response( 200, $this->fixture_with_unreadable_label() );
		reset_phpmailer_instance();
		try {
			Module::instance()->labels->create_for_order( $order, [ $this->parcel() ], 'manual' );
			$this->fail( 'expected LabelNotSaved' );
		} catch ( \Anchor\Shipping\Domain\LabelNotSaved $e ) {
			$this->assertStringContainsString( '1ZK877V90300000001', $e->getMessage() );
			$this->assertStringContainsString( 'label file could not be saved', $e->getMessage() );
		}
		$rows = Module::instance()->shipments->for_order( $order->get_id() );
		$this->assertCount( 1, $rows, 'the billed label stays on record' );
		$this->assertSame( '', $rows[0]['label_path'] );
		$this->assertSame( 'label_created', $rows[0]['status'] );
		$order = wc_get_order( $order->get_id() );
		$this->assertTrue( Module::instance()->labels->is_labelled( $order ) );
		$this->assertSame( 'needs_attention', $order->get_meta( LabelService::STATE_META ) );
		$sent = tests_retrieve_phpmailer_instance()->mock_sent;
		$this->assertCount( 1, $sent );
		$this->assertStringContainsString( 'Shipping problem', $sent[0]['subject'] );
		$this->assertStringContainsString( '1ZK877V90300000001', $sent[0]['body'] );
		$this->assertStringNotContainsString( 'Create label', $sent[0]['body'] );
		try {
			Module::instance()->labels->create_for_order( $order, [ $this->parcel() ], 'manual' );
			$this->fail( 'expected AlreadyLabelled' );
		} catch ( \Anchor\Shipping\Domain\AlreadyLabelled $e ) {
			$this->assertCount( 2, $this->requests );
		}
		$panel = ( new \Anchor\Shipping\Admin\OrderPanel( Module::instance()->shipments, Module::instance()->packer, Module::instance()->carriers ) )->render_panel( $order );
		$this->assertStringContainsString( 'Label file missing', $panel );
		$this->assertStringContainsString( 'anchor-shipping-void', $panel );
		$this->assertStringNotContainsString( 'action=anchor_shipping_label', $panel, 'no Print link for a missing file' );
	}

	public function test_failed_row_insert_after_billing_leaves_an_unrecorded_marker_that_blocks_auto_creation() {
		$this->configure_ups();
		$order = $this->make_order( [], 'processing' );
		$this->queue_token();
		$this->queue_response( 200, $this->fixture( 'ups-ship-single' ) );
		global $wpdb;
		$fail = static fn( $q ) => str_contains( $q, 'INSERT INTO `' . \Anchor\Shipping\Database\Migrations::table() . '`' ) ? 'SELECT 1 FROM nonexistent_anchor_table' : $q;
		add_filter( 'query', $fail );
		$wpdb->suppress_errors( true );
		try {
			Module::instance()->labels->create_for_order( $order, [ $this->parcel() ], 'manual' );
			$this->fail( 'expected LabelNotSaved' );
		} catch ( \Anchor\Shipping\Domain\LabelNotSaved $e ) {
			$this->assertStringContainsString( 'could not be recorded', $e->getMessage() );
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( false );
		}
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( '1ZK877V90300000001', $order->get_meta( '_anchor_shipping_unrecorded' ) );
		$this->assertTrue( Module::instance()->labels->is_labelled( $order ) );
		$this->assertSame( [], Module::instance()->shipments->for_order( $order->get_id() ) );
	}

	public function test_empty_reason_writes_a_clean_note() {
		$order = $this->make_order( [], 'processing' );
		Module::instance()->labels->mark_needs_attention( $order, '' );
		$notes = wp_list_pluck( wc_get_order_notes( [ 'order_id' => $order->get_id() ] ), 'content' );
		$this->assertContains( 'Shipping label needed.', $notes );
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
		$this->assertSame( 'needs_attention', wc_get_order( $order->get_id() )->get_meta( LabelService::STATE_META ), 'still-processing order with no label needs a person (no email)' );
	}

	public function test_voiding_the_last_label_of_a_non_processing_order_clears_the_state() {
		$this->configure_ups();
		$order = $this->make_order( [], 'processing' );
		$ids   = $this->create( $order );
		$order = wc_get_order( $order->get_id() );
		$order->set_status( 'on-hold' );
		$order->save();
		$this->queue_response( 200, $this->fixture( 'ups-void-ok' ) );
		Module::instance()->labels->void_shipment( $ids[0] );
		$this->assertSame( '', wc_get_order( $order->get_id() )->get_meta( LabelService::STATE_META ) );
	}

	public function test_a_fresh_lock_blocks_create_and_a_stale_one_is_retaken() {
		$this->configure_ups();
		$order = $this->make_order( [], 'processing' );
		add_option( 'anchor_shipping_lock_' . $order->get_id(), time(), '', 'no' );
		try {
			Module::instance()->labels->create_for_order( $order, [ $this->parcel() ], 'manual' );
			$this->fail( 'expected AlreadyLabelled' );
		} catch ( \Anchor\Shipping\Domain\AlreadyLabelled $e ) {
			$this->assertCount( 0, $this->requests );
		}
		update_option( 'anchor_shipping_lock_' . $order->get_id(), time() - 600 );
		$this->assertCount( 1, $this->create( $order ), 'a stale lock is taken over' );
		$this->assertFalse( get_option( 'anchor_shipping_lock_' . $order->get_id() ), 'lock released' );
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

	public function test_billed_shipment_with_unusable_label_is_recorded_flagged_and_blocks_a_second_purchase() {
		$this->configure_ups();
		$order = $this->make_order( [], 'processing' );
		$json  = $this->fixture( 'ups-ship-single' );
		unset( $json['ShipmentResponse']['ShipmentResults']['PackageResults']['ShippingLabel']['GraphicImage'] );
		$this->queue_token();
		$this->queue_response( 200, $json );
		reset_phpmailer_instance();
		try {
			Module::instance()->labels->create_for_order( $order, [ $this->parcel() ], 'manual' );
			$this->fail( 'expected LabelNotSaved' );
		} catch ( \Anchor\Shipping\Domain\LabelNotSaved $e ) {
			$this->assertStringContainsString( '1ZK877V90300000001', $e->getMessage() );
			$this->assertInstanceOf( CarrierError::class, $e->getPrevious() );
			$this->assertSame( 'uncertain', $e->getPrevious()->carrier_code );
		}
		$rows = Module::instance()->shipments->for_order( $order->get_id() );
		$this->assertCount( 1, $rows, 'the billed shipment is on record so it can be voided from the panel' );
		$this->assertSame( '1ZK877V90300000001', $rows[0]['shipment_id'] );
		$this->assertSame( '1ZK877V90300000001', $rows[0]['tracking_number'] );
		$this->assertSame( '', $rows[0]['label_path'] );
		$order = wc_get_order( $order->get_id() );
		$this->assertTrue( Module::instance()->labels->is_labelled( $order ) );
		$this->assertSame( 'needs_attention', $order->get_meta( LabelService::STATE_META ) );
		$sent = tests_retrieve_phpmailer_instance()->mock_sent;
		$this->assertCount( 1, $sent );
		$this->assertStringContainsString( 'Shipping problem', $sent[0]['subject'] );
		$this->assertStringNotContainsString( 'Create label', $sent[0]['body'] );
		$this->expectException( \Anchor\Shipping\Domain\AlreadyLabelled::class );
		try {
			Module::instance()->labels->create_for_order( $order, [ $this->parcel() ], 'manual' );
		} finally {
			$this->assertCount( 2, $this->requests, 'no second purchase' );
		}
	}

	public function test_billed_shipment_without_tracking_numbers_leaves_the_unrecorded_marker() {
		$this->configure_ups();
		$order = $this->make_order( [], 'processing' );
		$json  = $this->fixture( 'ups-ship-single' );
		unset( $json['ShipmentResponse']['ShipmentResults']['PackageResults'] );
		$this->queue_token();
		$this->queue_response( 200, $json );
		try {
			Module::instance()->labels->create_for_order( $order, [ $this->parcel() ], 'manual' );
			$this->fail( 'expected LabelNotSaved' );
		} catch ( \Anchor\Shipping\Domain\LabelNotSaved $e ) {
			$this->assertStringContainsString( 'could not be recorded', $e->getMessage() );
		}
		$this->assertSame( [], Module::instance()->shipments->for_order( $order->get_id() ) );
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( '1ZK877V90300000001', $order->get_meta( LabelService::UNRECORDED_META ) );
		$this->assertTrue( Module::instance()->labels->is_labelled( $order ) );
		$panel = ( new \Anchor\Shipping\Admin\OrderPanel( Module::instance()->shipments, Module::instance()->packer, Module::instance()->carriers ) )->render_panel( $order );
		$this->assertStringContainsString( 'was created but not recorded here', $panel );
	}

	/** A billed-maybe answer with no identifiers still blocks the next purchase until a person confirms. */
	public function test_unidentified_uncertain_purchase_blocks_the_next_label_until_confirmed() {
		$this->configure_ups();
		$order = $this->make_order( [], 'processing' );
		$this->queue_token();
		$this->queue_response( 200, '{"ShipmentResponse":{"ShipmentResults":{"Shipment' );
		try {
			Module::instance()->labels->create_for_order( $order, [ $this->parcel() ], 'manual' );
			$this->fail( 'expected an uncertain CarrierError' );
		} catch ( \Anchor\Shipping\Domain\CarrierError $e ) {
			$this->assertSame( 'uncertain', $e->carrier_code );
		}
		$order  = wc_get_order( $order->get_id() );
		$marker = (string) $order->get_meta( LabelService::UNRECORDED_META );
		$this->assertStringStartsWith( 'unidentified (', $marker );
		$this->assertTrue( Module::instance()->labels->is_labelled( $order ) );

		$sent = count( $this->requests );
		try {
			Module::instance()->labels->create_for_order( $order, [ $this->parcel() ], 'manual', [ 'additional' => 1 ] );
			$this->fail( 'expected the void confirmation to be required' );
		} catch ( \Anchor\Shipping\Domain\AlreadyLabelled $e ) {
			$this->assertStringContainsString( 'not recorded here', $e->getMessage() );
		}
		$this->assertCount( $sent, $this->requests, 'no second purchase without the confirmation' );
	}

	/** A confirmed replacement that is itself ambiguous gets a fresh marker, and a manual one alerts the shipping inbox. */
	public function test_repeat_unidentified_purchase_needs_a_fresh_confirmation_and_alerts() {
		$this->configure_ups();
		$order    = $this->make_order( [], 'processing' );
		$problems = 0;
		add_action( 'anchor_shipping_problem', function () use ( &$problems ) { $problems++; } );
		$truncated = '{"ShipmentResponse":{"ShipmentResults":{"Shipment';

		$this->queue_token();
		$this->queue_response( 200, $truncated );
		try {
			Module::instance()->labels->create_for_order( $order, [ $this->parcel() ], 'manual' );
		} catch ( \Anchor\Shipping\Domain\CarrierError $e ) {
			unset( $e );
		}
		$order = wc_get_order( $order->get_id() );
		$first = (string) $order->get_meta( LabelService::UNRECORDED_META );
		$this->assertSame( 1, $problems, 'a manual unidentified purchase alerts the shipping inbox' );

		$this->queue_response( 200, $truncated );
		try {
			Module::instance()->labels->create_for_order( $order, [ $this->parcel() ], 'manual', [ 'unrecorded_voided' => $first ] );
		} catch ( \Anchor\Shipping\Domain\CarrierError $e ) {
			unset( $e );
		}
		$order  = wc_get_order( $order->get_id() );
		$second = (string) $order->get_meta( LabelService::UNRECORDED_META );
		$this->assertStringStartsWith( 'unidentified (', $second );
		$this->assertNotSame( $first, $second, 'each ambiguous purchase gets its own marker' );

		$sent = count( $this->requests );
		try {
			Module::instance()->labels->create_for_order( $order, [ $this->parcel() ], 'manual', [ 'unrecorded_voided' => $first ] );
			$this->fail( 'the stale confirmation must not match the new marker' );
		} catch ( \Anchor\Shipping\Domain\AlreadyLabelled $e ) {
			unset( $e );
		}
		$this->assertCount( $sent, $this->requests, 'no purchase on a stale confirmation' );
	}
}
