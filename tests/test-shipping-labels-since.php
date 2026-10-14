<?php
use Anchor\Shipping\Admin\OrderPanel;
use Anchor\Shipping\Admin\OrdersColumn;
use Anchor\Shipping\Admin\SettingsPage;
use Anchor\Shipping\Frontend\ShopManager;
use Anchor\Shipping\Module;
use Anchor\Shipping\Support\Settings;
use Anchor\Shipping\Support\TrackingLinks;

/** Orders placed before label printing started are presumed shipped some other way. */
class Test_Shipping_Labels_Since extends Anchor_Shipping_TestCase {

	public function tear_down() {
		unset( $_GET[ OrderPanel::OPEN_ARG ] );
		parent::tear_down();
	}

	private function cutoff( string $gmt ): void {
		$s = Settings::all();
		$s['labels_since'] = $gmt;
		Settings::save( $s );
	}

	private function order_at( string $gmt ): WC_Order {
		$o = $this->make_order( [], 'processing' );
		$o->set_date_created( strtotime( $gmt . ' UTC' ) );
		$o->save();
		return wc_get_order( $o->get_id() );
	}

	private function listed( string $tab ): array {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );
		$m    = Module::instance();
		$html = ( new ShopManager( $m->labels, $m->shipments, $m->carriers, $m->packer ) )->render( [ 'tab' => $tab ] );
		preg_match_all( '/data-order-id="(\d+)"/', $html, $found );
		return array_map( 'intval', $found[1] );
	}

	private function panel(): OrderPanel {
		$m = Module::instance();
		return new OrderPanel( $m->shipments, $m->packer, $m->carriers );
	}

	public function test_no_cutoff_means_every_open_order_awaits_a_label() {
		$this->cutoff( '' );
		$old = $this->order_at( '2023-01-05 12:00:00' );
		$this->assertTrue( Module::instance()->labels->awaits_label( $old ) );
	}

	public function test_older_orders_leave_needs_label_and_the_column_but_stay_under_all() {
		$this->cutoff( '2026-10-13 21:28:00' );
		$old = $this->order_at( '2026-10-12 15:00:00' );
		$new = $this->order_at( '2026-10-13 21:28:00' );

		$this->assertFalse( Module::instance()->labels->awaits_label( $old ) );
		$this->assertTrue( Module::instance()->labels->awaits_label( $new ), 'an order placed at the cutoff counts' );

		$needs = $this->listed( 'needs-label' );
		$this->assertNotContains( $old->get_id(), $needs );
		$this->assertContains( $new->get_id(), $needs );
		$this->assertContains( $old->get_id(), $this->listed( 'all' ) );

		$m   = Module::instance();
		$col = new OrdersColumn( new TrackingLinks( $m->shipments, $m->carriers ), $m->labels );
		$this->assertSame( '', $col->render_cell( $old ) );
		$this->assertStringContainsString( 'Needs label', $col->render_cell( $new ) );
	}

	public function test_older_order_panel_is_a_note_until_asked_and_makes_no_ups_call() {
		$this->configure_ups();
		$this->cutoff( '2026-10-13 21:28:00' );
		$old  = $this->order_at( '2026-10-12 15:00:00' );
		$html = $this->panel()->render_panel( $old );
		$this->assertStringContainsString( 'anchor-shipping-predates', $html );
		$this->assertStringContainsString( 'Create a label anyway', $html );
		$this->assertStringContainsString( 'show_label_form=1', $html );
		$this->assertStringNotContainsString( 'anchor-shipping-create', $html );
		$this->assertSame( [], $this->requests, 'no address validation for an order nobody is labelling' );

		$_GET[ OrderPanel::OPEN_ARG ] = '1';
		$this->assertStringContainsString( 'anchor-shipping-create', $this->panel()->render_panel( $old ) );
	}

	public function test_older_order_with_a_label_shows_the_full_panel() {
		$this->cutoff( '2026-10-13 21:28:00' );
		$old = $this->order_at( '2026-10-12 15:00:00' );
		Module::instance()->shipments->insert( [ 'order_id' => $old->get_id(), 'carrier' => 'ups', 'shipment_id' => 'S1', 'tracking_number' => '1ZOLD' ] );
		$html = $this->panel()->render_panel( $old );
		$this->assertStringNotContainsString( 'anchor-shipping-predates', $html );
		$this->assertStringContainsString( '1ZOLD', $html );
	}

	public function test_first_run_records_the_cutoff_once() {
		$s = Settings::all();
		unset( $s['labels_since'] );
		Settings::save( $s ); // never established
		Settings::ensure_labels_since();
		$first = Settings::all()['labels_since'];
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $first );
		$this->cutoff( '2026-10-13 21:28:00' );
		Settings::ensure_labels_since();
		$this->assertSame( '2026-10-13 21:28:00', Settings::all()['labels_since'] );
	}

	public function test_settings_field_keeps_time_on_same_date_sets_midnight_on_new_date_and_blank_clears() {
		update_option( 'timezone_string', 'America/Chicago' );
		$page    = new SettingsPage( Module::instance()->carriers );
		$current = array_merge( Settings::all(), [ 'labels_since' => '2026-10-13 21:28:00' ] ); // 4:28 PM Central

		$this->assertSame( '2026-10-13 21:28:00', $page->sanitize( [ 'labels_since' => '2026-10-13' ], $current )['labels_since'] );
		$this->assertSame( '2026-10-01 05:00:00', $page->sanitize( [ 'labels_since' => '2026-10-01' ], $current )['labels_since'] );
		$this->assertSame( '', $page->sanitize( [ 'labels_since' => '' ], $current )['labels_since'] );
		$this->assertSame( '2026-10-13 21:28:00', $page->sanitize( [ 'labels_since' => 'nonsense' ], $current )['labels_since'] );
		$this->assertSame( '2026-10-13 21:28:00', $page->sanitize( [], $current )['labels_since'] );
	}

	public function test_a_deliberately_blank_cutoff_is_not_restamped() {
		$this->cutoff( '' );
		Settings::ensure_labels_since();
		$this->assertSame( '', Settings::all()['labels_since'] );
	}

	public function test_paid_order_automation_skips_older_orders() {
		$this->cutoff( '2026-10-13 21:28:00' );
		$handler = Module::instance()->paid_orders;
		$this->assertFalse( $handler->eligible( $this->order_at( '2026-10-12 15:00:00' ) ) );
		$this->assertTrue( $handler->eligible( $this->order_at( '2026-10-14 15:00:00' ) ) );
	}

	public function test_older_order_with_an_unrecorded_shipment_keeps_the_full_panel() {
		$this->cutoff( '2026-10-13 21:28:00' );
		$old = $this->order_at( '2026-10-12 15:00:00' );
		$old->update_meta_data( '_anchor_shipping_unrecorded', 'SHIPX' );
		$old->save();
		$html = $this->panel()->render_panel( $old );
		$this->assertStringNotContainsString( 'anchor-shipping-predates', $html );
		$this->assertStringContainsString( 'SHIPX', $html );
	}
}
