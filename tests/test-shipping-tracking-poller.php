<?php
use Anchor\Shipping\Carriers\CarrierRegistry;
use Anchor\Shipping\Domain\CarrierError;
use Anchor\Shipping\Domain\TrackingResult;
use Anchor\Shipping\Module;
use Anchor\Shipping\Services\TrackingPoller;
use Anchor\Shipping\Support\Settings;

/** Tracking numbers → scripted result (TrackingResult) or CarrierError. */
class Anchor_Shipping_Stub_Tracker implements \Anchor\Shipping\Carriers\CarrierInterface {
	public array $script = [];
	public array $asked = [];
	public function id(): string { return 'ups'; }
	public function label(): string { return 'Stub'; }
	public function services(): array { return []; }
	public function settings_fields(): array { return []; }
	public function create_label( \Anchor\Shipping\Domain\ShipmentRequest $r ): \Anchor\Shipping\Domain\LabelResult { throw new \LogicException(); }
	public function void_label( string $id ): void {}
	public function rate( \Anchor\Shipping\Domain\ShipmentRequest $r ): array { return []; }
	public function tracking_url( string $n ): string { return ''; }
	public function supports( string $feature ): bool {
		return 'track' === $feature;
	}
	public function track( array $numbers ): array {
		$out = [];
		foreach ( $numbers as $n ) {
			$this->asked[] = $n;
			$r = $this->script[ $n ] ?? new TrackingResult( $n, 'unknown', '', null );
			if ( $r instanceof \Throwable ) {
				throw $r;
			}
			$out[ $n ] = $r;
		}
		return $out;
	}
}

class Test_Shipping_Tracking_Poller extends Anchor_Shipping_TestCase {

	private Anchor_Shipping_Stub_Tracker $carrier;
	private TrackingPoller $poller;
	private int $seq = 0;

	public function set_up() {
		parent::set_up();
		$this->carrier = new Anchor_Shipping_Stub_Tracker();
		$carrier       = $this->carrier;
		add_filter( 'anchor_shipping_carriers', static fn() => [ 'ups' => $carrier ], 99 );
		// Tests run in sandbox: opt in to customer-facing side effects (see the sandbox tests below).
		add_filter( 'anchor_shipping_poll_side_effects_in_sandbox', '__return_true' );
		$this->poller = new TrackingPoller( new CarrierRegistry(), Module::instance()->shipments );
	}

	private function row( int $order_id, array $over = [] ): int {
		return Module::instance()->shipments->insert( $over + [
			'order_id'        => $order_id,
			'carrier'         => 'ups',
			'shipment_id'     => 'S' . $order_id,
			'tracking_number' => '1ZT' . $order_id . '-' . ++$this->seq,
			'created_at'      => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
		] );
	}

	private function say( int $row_id, string $status, string $detail = 'x', ?int $at = null ): void {
		$n                        = Module::instance()->shipments->find( $row_id )['tracking_number'];
		$this->carrier->script[ $n ] = new TrackingResult( $n, $status, $detail, $at ?? time() - 60 );
	}

	private function r( int $id ): array {
		return Module::instance()->shipments->find( $id );
	}

	public function test_constants() {
		$this->assertSame( 'anchor_shipping_poll_tracking', TrackingPoller::ACTION );
	}

	public function test_pollable_filters_orders_and_limits() {
		$repo = Module::instance()->shipments;
		$o    = $this->make_order();
		$keep = $this->row( $o->get_id(), [ 'status' => 'in_transit', 'last_polled_at' => '2026-10-01 00:00:00' ] );
		$new  = $this->row( $o->get_id() );
		$old  = $this->row( $o->get_id(), [ 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 31 * DAY_IN_SECONDS ) ] );
		foreach ( [ 'voided', 'delivered', 'returned' ] as $s ) {
			$this->row( $o->get_id(), [ 'status' => $s ] );
		}
		$older = $this->row( $o->get_id(), [ 'status' => 'in_transit', 'last_polled_at' => '2026-09-30 00:00:00' ] );
		$ids   = wp_list_pluck( $repo->pollable( 50 ), 'id' );
		$this->assertSame( [ $new, $older, $keep ], array_map( 'intval', $ids ), 'never-polled first, then oldest poll; no voided/delivered/returned/30d+' );
		$this->assertNotContains( (string) $old, $ids );
		$this->assertCount( 2, $repo->pollable( 2 ) );
	}

	public function test_transition_updates_the_row() {
		$o  = $this->make_order( [], 'processing' );
		$id = $this->row( $o->get_id() );
		$at = time() - 3600;
		$this->say( $id, 'in_transit', 'Departed facility', $at );
		$this->poller->poll();
		$r = $this->r( $id );
		$this->assertSame( 'in_transit', $r['status'] );
		$this->assertSame( 'Departed facility', $r['status_detail'] );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', $at ), $r['last_event_at'] );
		$this->assertNotNull( $r['last_polled_at'] );
	}

	public function test_unknown_only_bumps_last_polled_at() {
		$o  = $this->make_order( [], 'processing' );
		$id = $this->row( $o->get_id(), [ 'status' => 'in_transit', 'status_detail' => 'keep me' ] );
		$this->poller->poll();
		$r = $this->r( $id );
		$this->assertSame( 'in_transit', $r['status'] );
		$this->assertSame( 'keep me', $r['status_detail'] );
		$this->assertNotNull( $r['last_polled_at'] );
	}

	public function test_first_scan_fires_once_and_marks_shipped() {
		$o  = $this->make_order( [], 'processing' );
		$id = $this->row( $o->get_id() );
		$n  = 0;
		$seen = null;
		add_action( 'anchor_shipping_first_scan', static function ( $oid, $row ) use ( &$n, &$seen ) {
			++$n;
			$seen = [ $oid, $row ];
		}, 10, 2 );
		$this->poller->poll(); // unknown: nothing
		$this->say( $id, 'label_created' );
		$this->poller->poll(); // still label created: nothing
		$this->assertSame( 0, $n );
		$this->say( $id, 'in_transit' );
		$this->poller->poll();
		$this->say( $id, 'out_for_delivery' );
		$this->poller->poll();
		$this->assertSame( 1, $n );
		$this->assertSame( $o->get_id(), $seen[0] );
		$this->assertSame( (string) $id, (string) $seen[1]['id'] );
		$order = wc_get_order( $o->get_id() );
		$this->assertSame( 'shipped', $order->get_meta( \Anchor\Shipping\Services\LabelService::STATE_META ) );
		$this->assertNotSame( '', $order->get_meta( '_anchor_shipping_shipped_sent' ) );
	}

	public function test_first_scan_once_per_order_across_packages() {
		$o = $this->make_order( [], 'processing' );
		$a = $this->row( $o->get_id() );
		$b = $this->row( $o->get_id() );
		$n = 0;
		add_action( 'anchor_shipping_first_scan', static function () use ( &$n ) { ++$n; } );
		$this->say( $a, 'in_transit' );
		$this->say( $b, 'in_transit' );
		$this->poller->poll();
		$this->assertSame( 1, $n );
	}

	public function test_all_delivered_completes_a_processing_order() {
		$o = $this->make_order( [], 'processing' );
		$a = $this->row( $o->get_id() );
		$b = $this->row( $o->get_id() );
		$this->say( $a, 'delivered', 'Delivered' );
		$this->poller->poll();
		$order = wc_get_order( $o->get_id() );
		$this->assertSame( 'processing', $order->get_status(), 'one package still out' );
		$this->assertNotNull( $this->r( $a )['delivered_at'] );
		$this->say( $b, 'delivered', 'Delivered' );
		$this->poller->poll();
		$order = wc_get_order( $o->get_id() );
		$this->assertSame( 'completed', $order->get_status() );
		$this->assertSame( 'delivered', $order->get_meta( \Anchor\Shipping\Services\LabelService::STATE_META ) );
		$this->assertStringContainsString( 'delivered', strtolower( implode( ' ', wp_list_pluck( wc_get_order_notes( [ 'order_id' => $o->get_id() ] ), 'content' ) ) ) );
	}

	public function test_voided_packages_do_not_block_delivery() {
		$o = $this->make_order( [], 'processing' );
		$this->row( $o->get_id(), [ 'status' => 'voided' ] );
		$a = $this->row( $o->get_id() );
		$this->say( $a, 'delivered' );
		$this->poller->poll();
		$this->assertSame( 'completed', wc_get_order( $o->get_id() )->get_status() );
	}

	public function test_setting_off_or_not_processing_leaves_status_alone() {
		$s = Settings::all();
		$s['complete_on_delivery'] = false;
		Settings::save( $s );
		$o = $this->make_order( [], 'processing' );
		$a = $this->row( $o->get_id() );
		$this->say( $a, 'delivered' );
		$this->poller->poll();
		$order = wc_get_order( $o->get_id() );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertSame( 'delivered', $order->get_meta( \Anchor\Shipping\Services\LabelService::STATE_META ) );

		Settings::save( [ 'complete_on_delivery' => true ] );
		$o2 = $this->make_order( [], 'on-hold' );
		$b  = $this->row( $o2->get_id() );
		$this->say( $b, 'delivered' );
		$this->poller->poll();
		$this->assertSame( 'on-hold', wc_get_order( $o2->get_id() )->get_status() );
	}

	public function test_complete_on_delivery_defaults_on() {
		$this->assertTrue( Settings::all()['complete_on_delivery'] );
	}

	public function test_exception_notes_and_alerts_once_without_touching_state() {
		$o = $this->make_order( [], 'processing' );
		$o->update_meta_data( \Anchor\Shipping\Services\LabelService::STATE_META, 'labelled' );
		$o->update_meta_data( '_anchor_shipping_shipped_sent', '1' ); // first scan already happened
		$o->save();
		$id = $this->row( $o->get_id(), [ 'status' => 'in_transit' ] );
		$got = [];
		add_action( 'anchor_shipping_problem', static function ( $oid, $detail ) use ( &$got ) { $got[] = [ $oid, $detail ]; }, 10, 2 );
		reset_phpmailer_instance();
		$this->say( $id, 'exception', 'Address problem' );
		$this->poller->poll();
		$this->poller->poll(); // same exception again: no second alert
		$this->assertSame( [ [ $o->get_id(), 'Address problem' ] ], $got );
		$notes = implode( ' ', wp_list_pluck( wc_get_order_notes( [ 'order_id' => $o->get_id() ] ), 'content' ) );
		$this->assertStringContainsString( 'Address problem', $notes );
		$this->assertSame( 'labelled', wc_get_order( $o->get_id() )->get_meta( \Anchor\Shipping\Services\LabelService::STATE_META ) );
		$this->assertCount( 1, array_filter( tests_retrieve_phpmailer_instance()->mock_sent, static fn( $m ) => str_contains( $m['subject'], 'Shipping problem' ) ) );
	}

	public function test_never_scanned_label_is_auto_voided_after_ten_days() {
		$o   = $this->make_order( [], 'processing' );
		$old = $this->row( $o->get_id(), [ 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 11 * DAY_IN_SECONDS ) ] );
		$new = $this->row( $o->get_id(), [ 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 9 * DAY_IN_SECONDS ) ] );
		$this->say( $old, 'label_created' );
		$this->say( $new, 'label_created' );
		$this->poller->poll();
		$this->assertSame( 'voided', $this->r( $old )['status'] );
		$this->assertNotNull( $this->r( $old )['voided_at'] );
		$this->assertSame( 'label_created', $this->r( $new )['status'] );
		$notes = implode( ' ', wp_list_pluck( wc_get_order_notes( [ 'order_id' => $o->get_id() ] ), 'content' ) );
		$this->assertStringContainsString( 'UPS auto-voided (never scanned)', $notes );
	}

	public function test_auto_void_of_the_last_open_package_completes_the_order() {
		$o = $this->make_order( [], 'processing' );
		$a = $this->row( $o->get_id() );
		$b = $this->row( $o->get_id(), [ 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 11 * DAY_IN_SECONDS ) ] );
		$this->say( $a, 'delivered' );
		$this->say( $b, 'label_created' );
		$this->carrier->script[ $this->r( $b )['tracking_number'] ] = new TrackingResult( $this->r( $b )['tracking_number'], 'unknown', '', null );
		// A is delivered while B is still inside its window.
		global $wpdb;
		$wpdb->update( \Anchor\Shipping\Database\Migrations::table(), [ 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 5 * DAY_IN_SECONDS ) ], [ 'id' => $b ] );
		$this->poller->poll();
		$this->assertSame( 'processing', wc_get_order( $o->get_id() )->get_status() );
		$wpdb->update( \Anchor\Shipping\Database\Migrations::table(), [ 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 11 * DAY_IN_SECONDS ) ], [ 'id' => $b ] );
		$this->poller->poll();
		$this->assertSame( 'voided', $this->r( $b )['status'] );
		$this->assertSame( 'completed', wc_get_order( $o->get_id() )->get_status() );
	}

	public function test_unknown_result_still_auto_voids_an_old_label_created_row() {
		$o  = $this->make_order( [], 'processing' );
		$id = $this->row( $o->get_id(), [ 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 11 * DAY_IN_SECONDS ) ] );
		$young = $this->row( $o->get_id() );
		$this->poller->poll(); // both unknown
		$this->assertSame( 'voided', $this->r( $id )['status'] );
		$this->assertSame( 'label_created', $this->r( $young )['status'] );
	}

	public function test_a_row_voided_mid_run_is_not_overwritten() {
		$o  = $this->make_order( [], 'processing' );
		$id = $this->row( $o->get_id() );
		$this->say( $id, 'in_transit' );
		Module::instance()->shipments->update( $id, [ 'status' => 'voided' ] );
		$this->poller->poll(); // pollable() excludes it; simulate the race by re-reading
		$this->assertSame( 'voided', $this->r( $id )['status'] );
	}

	public function test_a_carrier_error_does_not_stop_the_others() {
		$o = $this->make_order( [], 'processing' );
		$a = $this->row( $o->get_id() );
		$b = $this->row( $o->get_id() );
		$na = $this->r( $a )['tracking_number'];
		$this->carrier->script[ $na ] = new CarrierError( 'network', 'network down', true );
		$this->say( $b, 'in_transit' );
		$this->poller->poll();
		$this->assertSame( 'label_created', $this->r( $a )['status'] );
		$this->assertSame( 'in_transit', $this->r( $b )['status'] );
		$this->assertCount( 2, $this->carrier->asked );
	}

	public function test_scheduled_on_admin_init_every_two_hours() {
		$this->assertNotFalse( has_action( 'admin_init', [ Module::instance()->tracking, 'schedule' ] ) );
		$this->assertFalse( has_action( 'init', [ Module::instance()->tracking, 'schedule' ] ), 'not on every front-end request' );
		$this->poller->schedule();
		$this->poller->schedule();
		$actions = as_get_scheduled_actions( [ 'hook' => TrackingPoller::ACTION, 'group' => 'anchor-shipping', 'status' => ActionScheduler_Store::STATUS_PENDING ] );
		$this->assertCount( 1, $actions );
		$this->assertSame( 7200, (int) reset( $actions )->get_schedule()->get_recurrence() );
	}

	public function test_sandbox_updates_rows_but_sends_no_shipped_email_and_completes_nothing() {
		remove_filter( 'anchor_shipping_poll_side_effects_in_sandbox', '__return_true' );
		$o  = $this->make_order( [], 'processing' );
		$id = $this->row( $o->get_id() );
		$n  = 0;
		add_action( 'anchor_shipping_first_scan', static function () use ( &$n ) { ++$n; } );
		$this->say( $id, 'in_transit' );
		$this->poller->poll();
		$this->assertSame( 'in_transit', $this->r( $id )['status'], 'rows still track' );
		$this->assertSame( 0, $n, 'no first-scan (customer Shipped email) from a sandbox label' );
		$this->say( $id, 'delivered' );
		$this->poller->poll();
		$order = wc_get_order( $o->get_id() );
		$this->assertSame( 'delivered', $this->r( $id )['status'] );
		$this->assertSame( 'processing', $order->get_status(), 'a sandbox delivery never completes a real order' );
		$this->assertSame( '', (string) $order->get_meta( '_anchor_shipping_shipped_sent' ) );
	}

	public function test_sandbox_exception_updates_row_but_adds_no_note_or_alert() {
		remove_filter( 'anchor_shipping_poll_side_effects_in_sandbox', '__return_true' );
		$o   = $this->make_order( [], 'processing' );
		$id  = $this->row( $o->get_id(), [ 'status' => 'in_transit' ] );
		$got = [];
		add_action( 'anchor_shipping_problem', static function ( $oid ) use ( &$got ) { $got[] = $oid; } );
		$this->say( $id, 'exception', 'Address problem' );
		$this->poller->poll();
		$this->assertSame( 'exception', $this->r( $id )['status'] );
		$this->assertSame( [], $got );
		$notes = implode( ' ', wp_list_pluck( wc_get_order_notes( [ 'order_id' => $o->get_id() ] ), 'content' ) );
		$this->assertStringNotContainsString( 'Address problem', $notes );
	}

	public function test_production_environment_still_fires_side_effects() {
		remove_filter( 'anchor_shipping_poll_side_effects_in_sandbox', '__return_true' );
		add_filter( 'anchor_shipping_environment_type', static fn() => 'production' );
		$this->configure_ups( [ 'carriers' => [ 'ups' => [ 'environment' => 'production' ] ] ] );
		$o  = $this->make_order( [], 'processing' );
		$id = $this->row( $o->get_id() );
		$n  = 0;
		add_action( 'anchor_shipping_first_scan', static function () use ( &$n ) { ++$n; } );
		$this->say( $id, 'delivered' );
		$this->poller->poll();
		$this->assertSame( 1, $n );
		$this->assertSame( 'completed', wc_get_order( $o->get_id() )->get_status() );
	}

	public function test_auto_void_of_the_only_label_puts_a_processing_order_back_to_needs_attention() {
		$o = $this->make_order( [], 'processing' );
		$o->update_meta_data( \Anchor\Shipping\Services\LabelService::STATE_META, 'labelled' );
		$o->save();
		$id = $this->row( $o->get_id(), [ 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 11 * DAY_IN_SECONDS ) ] );
		$this->say( $id, 'label_created' );
		$this->poller->poll();
		$this->assertSame( 'voided', $this->r( $id )['status'] );
		$this->assertSame( 'needs_attention', wc_get_order( $o->get_id() )->get_meta( \Anchor\Shipping\Services\LabelService::STATE_META ) );
	}

	public function test_auto_void_keeps_state_while_another_label_is_active() {
		$o = $this->make_order( [], 'processing' );
		$o->update_meta_data( \Anchor\Shipping\Services\LabelService::STATE_META, 'labelled' );
		$o->save();
		$old = $this->row( $o->get_id(), [ 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 11 * DAY_IN_SECONDS ) ] );
		$this->row( $o->get_id() );
		$this->say( $old, 'label_created' );
		$this->poller->poll();
		$this->assertSame( 'labelled', wc_get_order( $o->get_id() )->get_meta( \Anchor\Shipping\Services\LabelService::STATE_META ) );
	}

	public function test_an_unmapped_status_type_never_auto_voids() {
		$o  = $this->make_order( [], 'processing' );
		$id = $this->row( $o->get_id(), [ 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 11 * DAY_IN_SECONDS ) ] );
		$this->say( $id, 'unknown_type', 'Something new' );
		$this->poller->poll();
		$this->assertSame( 'label_created', $this->r( $id )['status'] );
		$this->assertNotNull( $this->r( $id )['last_polled_at'] );
	}

	public function test_a_carrier_reported_void_voids_the_row_straight_away() {
		$o  = $this->make_order( [], 'processing' );
		$id = $this->row( $o->get_id() );
		$this->say( $id, 'voided', 'Billing information voided' );
		$this->poller->poll();
		$this->assertSame( 'voided', $this->r( $id )['status'] );
		$this->assertSame( 'needs_attention', wc_get_order( $o->get_id() )->get_meta( \Anchor\Shipping\Services\LabelService::STATE_META ) );
	}

	public function test_core_clears_the_poll_when_the_module_is_switched_off() {
		$this->poller->schedule();
		$q = [ 'hook' => TrackingPoller::ACTION, 'group' => 'anchor-shipping', 'status' => ActionScheduler_Store::STATUS_PENDING ];
		$this->assertCount( 1, as_get_scheduled_actions( $q ) );
		anchor_tools_shipping_maybe_clear_poll( [ 'modules' => [ 'shipping' => 1 ] ], [ 'modules' => [] ] );
		$this->assertCount( 0, as_get_scheduled_actions( $q ) );
	}

	public function test_poll_stores_expected_delivery_and_location() {
		$o  = $this->make_order( [], 'processing' );
		$id = $this->row( $o->get_id() );
		$n  = Module::instance()->shipments->find( $id )['tracking_number'];
		$this->carrier->script[ $n ] = new TrackingResult( $n, 'in_transit', 'Departed from facility', time() - 60, '2026-10-16', 'Nashville, TN' );
		$this->poller->poll();
		$this->assertSame( '2026-10-16', $this->r( $id )['expected_delivery'] );
		$this->assertSame( 'Nashville, TN', $this->r( $id )['last_location'] );
	}
}
