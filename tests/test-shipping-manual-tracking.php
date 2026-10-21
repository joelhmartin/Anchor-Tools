<?php
use Anchor\Shipping\Admin\Ajax;
use Anchor\Shipping\Admin\OrderPanel;
use Anchor\Shipping\Carriers\CarrierRegistry;
use Anchor\Shipping\Domain\TrackingResult;
use Anchor\Shipping\Module;
use Anchor\Shipping\Services\LabelService;
use Anchor\Shipping\Services\ManualTracking;
use Anchor\Shipping\Services\TrackingPoller;
use Anchor\Shipping\Support\Settings;
use Anchor\Shipping\Support\TrackingLinks;

/** UPS stand-in: scripted tracking answers, and it records any void it is asked for. */
class Anchor_Manual_Tracking_Stub_Ups implements \Anchor\Shipping\Carriers\CarrierInterface {
	public array $script = [];
	public array $voids  = [];
	public function id(): string { return 'ups'; }
	public function label(): string { return 'UPS'; }
	public function services(): array { return [ '03' => 'UPS Ground' ]; }
	public function settings_fields(): array { return []; }
	public function create_label( \Anchor\Shipping\Domain\ShipmentRequest $r ): \Anchor\Shipping\Domain\LabelResult { throw new \LogicException(); }
	public function void_label( string $id ): void { $this->voids[] = $id; }
	public function rate( \Anchor\Shipping\Domain\ShipmentRequest $r ): array { return []; }
	public function tracking_url( string $n ): string { return 'https://ups.test/' . $n; }
	public function supports( string $feature ): bool { return in_array( $feature, [ 'labels', 'void', 'track' ], true ); }
	public function track( array $numbers ): array {
		$out = [];
		foreach ( $numbers as $n ) {
			$out[ $n ] = $this->script[ $n ] ?? new TrackingResult( $n, 'unknown', '', null );
		}
		return $out;
	}
}

class Test_Shipping_Manual_Tracking extends Anchor_Shipping_TestCase {

	private Anchor_Manual_Tracking_Stub_Ups $ups;
	private CarrierRegistry $registry;
	private ManualTracking $manual;
	private $carriers_filter;
	private int $first_scans = 0;

	public function set_up() {
		parent::set_up();
		$this->ups = new Anchor_Manual_Tracking_Stub_Ups();
		$ups       = $this->ups;
		$this->carriers_filter = static fn() => [ 'ups' => $ups ];
		add_filter( 'anchor_shipping_carriers', $this->carriers_filter, 99 );
		add_filter( 'anchor_shipping_poll_side_effects_in_sandbox', '__return_true' );
		$this->first_scans = 0;
		add_action( 'anchor_shipping_first_scan', [ $this, 'count_first_scan' ] );
		$m              = Module::instance();
		$this->registry = new CarrierRegistry();
		$labels         = new LabelService( $this->registry, $m->shipments, $m->store, $m->packer );
		$this->manual   = new ManualTracking( $this->registry, $m->shipments, new TrackingPoller( $this->registry, $m->shipments, $labels ) );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	public function tear_down() {
		remove_filter( 'anchor_shipping_carriers', $this->carriers_filter, 99 );
		remove_filter( 'anchor_shipping_poll_side_effects_in_sandbox', '__return_true' );
		remove_action( 'anchor_shipping_first_scan', [ $this, 'count_first_scan' ] );
		Module::instance()->carriers->reset();
		parent::tear_down();
	}

	public function count_first_scan(): void {
		++$this->first_scans;
	}

	private function notes( int $order_id ): string {
		return implode( ' | ', wp_list_pluck( wc_get_order_notes( [ 'order_id' => $order_id ] ), 'content' ) );
	}

	public function test_ups_number_already_moving_is_shipped_at_once() {
		$order = $this->make_order( [], 'processing' );
		$this->ups->script['1Z999AA10123456784'] = new TrackingResult( '1Z999AA10123456784', 'in_transit', 'Departed facility', time() - 600 );

		$id  = $this->manual->add( $order, 'ups', ' 1z999aa1 0123-456784 ' );
		$row = Module::instance()->shipments->find( $id );

		$this->assertSame( '1Z999AA10123456784', $row['tracking_number'], 'normalized: upper case, no spaces or dashes' );
		$this->assertSame( ManualTracking::SOURCE, $row['source'] );
		$this->assertSame( 'in_transit', $row['status'], 'polled straight away' );
		$this->assertSame( 'shipped', wc_get_order( $order->get_id() )->get_meta( LabelService::STATE_META ) );
		$this->assertSame( 1, $this->first_scans, 'the customer\'s shipped email fires now' );
		$this->assertStringContainsString( 'Tracking number added: UPS 1Z999AA10123456784', $this->notes( $order->get_id() ) );
	}

	public function test_ups_number_not_yet_scanned_waits_for_the_first_scan() {
		$order = $this->make_order( [], 'processing' );
		$id    = $this->manual->add( $order, 'ups', '1Z999AA10123456784' );

		$this->assertSame( 'label_created', Module::instance()->shipments->find( $id )['status'] );
		$this->assertSame( 'labelled', wc_get_order( $order->get_id() )->get_meta( LabelService::STATE_META ) );
		$this->assertSame( 0, $this->first_scans );
		$this->assertTrue( Module::instance()->labels->is_labelled( wc_get_order( $order->get_id() ) ), 'leaves the Needs label tab' );
	}

	public function test_tracking_only_carrier_is_shipped_at_once_and_links_to_its_site() {
		$order = $this->make_order( [], 'processing' );
		$id    = $this->manual->add( $order, 'fedex', '123456789012' );

		$this->assertSame( 'shipped', Module::instance()->shipments->find( $id )['status'], 'no progress is known for a carrier we cannot ask' );
		$this->assertSame( 'shipped', wc_get_order( $order->get_id() )->get_meta( LabelService::STATE_META ) );
		$this->assertSame( 1, $this->first_scans );
		$links = ( new TrackingLinks( Module::instance()->shipments, $this->registry ) )->for_order( $order->get_id() );
		$this->assertSame( 'FedEx', $links[0]['carrier'] );
		$this->assertSame( 'https://www.fedex.com/fedextrack/?trknbr=123456789012', $links[0]['url'] );
		$this->assertSame( 'Shipped', $links[0]['status'] );
	}

	public function test_a_second_number_does_not_resend_the_shipped_email() {
		$order = $this->make_order( [], 'processing' );
		$this->manual->add( $order, 'usps', '9400111899223197428490' );
		$this->manual->add( wc_get_order( $order->get_id() ), 'usps', '9400111899223197428491' );
		$this->assertSame( 1, $this->first_scans );
		$this->assertCount( 2, Module::instance()->shipments->active_for_order( $order->get_id() ) );
	}

	/** @dataProvider bad_input */
	public function test_bad_input_is_refused( string $carrier, string $number, string $message ) {
		$order = $this->make_order( [], 'processing' );
		try {
			$this->manual->add( $order, $carrier, $number );
			$this->fail( 'expected a refusal' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( $message, $e->getMessage() );
		}
		$this->assertSame( [], Module::instance()->shipments->for_order( $order->get_id() ) );
	}

	public function bad_input(): array {
		return [
			'unknown carrier' => [ 'dhl', '1234567890', 'Choose a carrier' ],
			'too short'       => [ 'ups', '1Z12', 'Enter the tracking number' ],
			'punctuation'     => [ 'ups', '1Z999AA1/0123456784', 'Enter the tracking number' ],
		];
	}

	public function test_the_same_number_twice_is_refused() {
		$order = $this->make_order( [], 'processing' );
		$this->manual->add( $order, 'ups', '1Z999AA10123456784' );
		$this->expectExceptionMessage( 'already on this order' );
		$this->manual->add( wc_get_order( $order->get_id() ), 'ups', '1z999aa10123456784' );
	}

	public function test_remove_takes_it_off_the_order_without_asking_the_carrier() {
		$order = $this->make_order( [], 'processing' );
		$id    = $this->manual->add( $order, 'ups', '1Z999AA10123456784' );
		$m     = Module::instance();
		$labels = new LabelService( $this->registry, $m->shipments, $m->store, $m->packer );

		$labels->void_shipment( $id );

		$this->assertSame( [], $this->ups->voids, 'nothing was bought here, so nothing is voided at UPS' );
		$this->assertSame( 'voided', $m->shipments->find( $id )['status'] );
		$this->assertStringContainsString( 'Tracking number removed: 1Z999AA10123456784', $this->notes( $order->get_id() ) );
		$this->assertSame( 'needs_attention', wc_get_order( $order->get_id() )->get_meta( LabelService::STATE_META ), 'a processing order with nothing on it needs a label again' );
	}

	public function test_cancelling_keeps_a_hand_entered_number() {
		$order = $this->make_order( [], 'processing' );
		$this->manual->add( $order, 'fedex', '123456789012' );
		wc_get_order( $order->get_id() )->update_status( 'cancelled' );
		$this->assertCount( 1, Module::instance()->shipments->active_for_order( $order->get_id() ) );
		$this->assertStringNotContainsString( 'Shipping problem', $this->notes( $order->get_id() ) );
	}

	public function test_ajax_adds_and_returns_the_panel_with_a_remove_button() {
		$m     = Module::instance();
		$order = $this->make_order( [], 'processing' );
		$ajax  = new Ajax( $m->labels, $m->shipments, $m->store, $m->packer, $this->registry, $this->manual );
		$out   = $ajax->handle_add_tracking( [ 'order_id' => $order->get_id(), 'track_carrier' => 'usps', 'track_number' => '9400111899223197428490' ] );

		$this->assertStringContainsString( '9400111899223197428490', $out['html'] );
		$this->assertStringContainsString( 'entered by hand', $out['html'] );
		$this->assertStringContainsString( '>Remove<', $out['html'] );
		$this->assertStringNotContainsString( 'Label file missing', $out['html'] );
		$this->assertStringContainsString( 'tools.usps.com', $out['html'], 'Track link' );
	}

	public function test_panel_offers_every_carrier_for_tracking_but_labels_only_for_account_carriers() {
		$m    = Module::instance();
		$html = ( new OrderPanel( $m->shipments, $m->packer, $this->registry ) )->render_panel( $this->make_order( [], 'processing' ) );
		$this->assertStringContainsString( 'Add a tracking number', $html );
		$this->assertMatchesRegularExpression( '/name="track_carrier".*value="fedex".*value="usps"/s', $html );
		$this->assertSame( 1, preg_match( '#<select name="carrier">(.*?)</select>#s', $html, $label_carriers ) );
		$this->assertStringNotContainsString( 'fedex', $label_carriers[1], 'the label carrier list has no tracking-only carrier' );
		$this->assertArrayNotHasKey( 'fedex', $this->registry->all() );
	}

	public function test_default_carrier_setting_cannot_be_a_tracking_only_carrier() {
		$page = new \Anchor\Shipping\Admin\SettingsPage( $this->registry );
		$out  = $page->sanitize( [ 'default_carrier' => 'fedex' ], Settings::all() );
		$this->assertSame( Settings::all()['default_carrier'], $out['default_carrier'] );
	}

	public function test_an_unscanned_hand_entered_ups_number_is_never_auto_voided() {
		$order = $this->make_order( [], 'processing' );
		$id    = $this->manual->add( $order, 'ups', '1Z999AA10123456784' );
		$m     = Module::instance();
		$m->shipments->update( $id, [ 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 11 * DAY_IN_SECONDS ) ] );
		( new TrackingPoller( $this->registry, $m->shipments ) )->poll();
		$this->assertSame( 'label_created', $m->shipments->find( $id )['status'], 'UPS voids unused labels bought here, not numbers entered by hand' );
		$this->assertStringNotContainsString( 'auto-voided', $this->notes( $order->get_id() ) );
	}

	public function test_poller_never_spends_a_batch_slot_on_rows_it_cannot_ask_about() {
		$order = $this->make_order( [], 'processing' );
		$fedex = $this->manual->add( $order, 'fedex', '123456789012' );
		$ups   = $this->manual->add( wc_get_order( $order->get_id() ), 'ups', '1Z999AA10123456784' );
		$ids   = array_map( 'intval', wp_list_pluck( Module::instance()->shipments->pollable( 50, [ 'ups' ] ), 'id' ) );
		$this->assertSame( [ $ups ], $ids );
		$this->assertNotContains( $fedex, $ids );
		$this->assertSame( [], Module::instance()->shipments->pollable( 50, [] ) );
	}

	private function poller(): TrackingPoller {
		return new TrackingPoller( $this->registry, Module::instance()->shipments );
	}

	public function test_mixed_order_completes_when_the_ups_package_is_delivered() {
		$order = $this->make_order( [], 'processing' );
		$this->manual->add( $order, 'fedex', '123456789012' );
		$ups = $this->manual->add( wc_get_order( $order->get_id() ), 'ups', '1Z999AA10123456784' );
		$this->ups->script['1Z999AA10123456784'] = new TrackingResult( '1Z999AA10123456784', 'delivered', 'Delivered', time() - 60 );
		$this->poller()->poll();
		$this->assertSame( 'delivered', Module::instance()->shipments->find( $ups )['status'] );
		$this->assertSame( 'completed', wc_get_order( $order->get_id() )->get_status(), 'a FedEx number entered by hand neither blocks nor proves delivery' );
	}

	public function test_order_with_only_untrackable_numbers_is_never_auto_completed() {
		$order = $this->make_order( [], 'processing' );
		$this->manual->add( $order, 'usps', '9400111899223197428490' );
		$this->poller()->poll();
		$this->assertSame( 'processing', wc_get_order( $order->get_id() )->get_status() );
	}

	public function test_correcting_a_mistyped_number_reaches_shipped_and_emails_the_right_one() {
		$m     = Module::instance();
		$order = $this->make_order( [], 'processing' );
		$bad   = $this->manual->add( $order, 'fedex', '123456789012' );
		( new LabelService( $this->registry, $m->shipments, $m->store, $m->packer ) )->void_shipment( $bad );
		$this->manual->add( wc_get_order( $order->get_id() ), 'fedex', '123456789013' );
		$this->assertSame( 'shipped', wc_get_order( $order->get_id() )->get_meta( LabelService::STATE_META ) );
		$this->assertSame( 2, $this->first_scans, 'the corrected number gets its own shipped email' );
	}

	/** @dataProvider closed_statuses */
	public function test_back_filling_a_closed_order_never_emails_the_customer( string $status ) {
		$order = $this->make_order( [], $status );
		$this->manual->add( $order, 'fedex', '123456789012' );
		$this->ups->script['1Z999AA10123456784'] = new TrackingResult( '1Z999AA10123456784', 'delivered', 'Delivered', time() - 60 );
		$this->manual->add( wc_get_order( $order->get_id() ), 'ups', '1Z999AA10123456784' );
		$this->assertSame( 0, $this->first_scans );
		$this->assertCount( 2, Module::instance()->shipments->active_for_order( $order->get_id() ), 'still recorded' );
		$this->assertSame( $status, wc_get_order( $order->get_id() )->get_status() );
		$this->assertSame( 'delivered', wc_get_order( $order->get_id() )->get_meta( LabelService::STATE_META ), 'the delivered UPS package settles the state, quietly' );
	}

	public function closed_statuses(): array {
		return [ 'completed' => [ 'completed' ], 'cancelled' => [ 'cancelled' ], 'refunded' => [ 'refunded' ] ];
	}

	public function test_a_problem_flag_survives_adding_a_number() {
		$order = $this->make_order( [], 'cancelled' );
		$order->update_meta_data( LabelService::STATE_META, 'needs_attention' );
		$order->save();
		$this->manual->add( $order, 'fedex', '123456789012' );
		$this->assertSame( 'needs_attention', wc_get_order( $order->get_id() )->get_meta( LabelService::STATE_META ) );
	}

	public function test_a_processing_order_waiting_for_a_label_stops_needing_one() {
		$order = $this->make_order( [], 'processing' );
		$order->update_meta_data( LabelService::STATE_META, 'needs_attention' );
		$order->save();
		$this->manual->add( $order, 'ups', '1Z999AA10123456784' );
		$this->assertSame( 'labelled', wc_get_order( $order->get_id() )->get_meta( LabelService::STATE_META ) );
	}

	public function test_customer_card_for_a_tracking_only_number_has_no_progress_bar() {
		$html = \Anchor\Shipping\Support\Template::render( 'account/tracking', [ 'rows' => [ [
			'carrier' => 'FedEx', 'number' => '123456789012', 'url' => 'https://www.fedex.com/x', 'status' => 'Shipped', 'status_key' => 'shipped',
			'detail' => '', 'location' => '', 'event_at' => null, 'expected' => '', 'delivered_at' => null,
		] ] ] );
		$this->assertStringContainsString( '>Shipped<', $html );
		$this->assertStringNotContainsString( 'ast-steps', $html );
		$this->assertStringContainsString( 'Follow this package on the FedEx website', $html );
	}
}
