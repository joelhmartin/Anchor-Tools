<?php
use Anchor\Shipping\Frontend\ConsoleAjax;
use Anchor\Shipping\Frontend\ShopManager;
use Anchor\Shipping\Module;
use Anchor\Shipping\Services\LabelService;

class Test_Shipping_Shop_Manager extends Anchor_Shipping_TestCase {

	private function as_role( string $role ): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => $role ] ) );
	}

	private function console(): ShopManager {
		$m = Module::instance();
		return new ShopManager( $m->labels, $m->shipments, $m->carriers, $m->packer );
	}

	private function ajax(): ConsoleAjax {
		return new ConsoleAjax();
	}

	private function with_state( WC_Order $order, string $state ): WC_Order {
		$order->update_meta_data( LabelService::STATE_META, $state );
		$order->save();
		return $order;
	}

	/** Numbers listed by a tab (orders are identified by "#id" in the markup). */
	private function listed( string $tab, array $extra = [] ): array {
		$html = $this->console()->render( [ 'tab' => $tab ] + $extra );
		preg_match_all( '/data-order-id="(\d+)"/', $html, $m );
		return array_map( 'intval', $m[1] );
	}

	public function test_registers_shortcode_and_logged_out_gets_a_login_link() {
		$this->console();
		$this->assertTrue( shortcode_exists( 'shop_manager' ) );
		wp_set_current_user( 0 );
		$html = $this->console()->render();
		$this->assertStringContainsString( 'wp-login.php', $html );
	}

	public function test_customers_are_not_permitted_and_see_no_orders() {
		$order = $this->make_order( [], 'processing' );
		$this->as_role( 'customer' );
		$html = $this->console()->render();
		$this->assertStringContainsString( 'not permitted', $html );
		$this->assertStringNotContainsString( 'data-order-id', $html );
		$this->assertStringNotContainsString( 'Doe', $html );
		$this->assertStringNotContainsString( 'order=' . $order->get_id(), $this->console()->render( [ 'order' => $order->get_id() ] ) );
	}

	public function test_capability_filter_is_honoured() {
		$this->as_role( 'customer' );
		add_filter( 'anchor_shipping_console_capability', static fn() => 'read' );
		$this->assertTrue( ShopManager::can_use() );
		remove_all_filters( 'anchor_shipping_console_capability' );
		$this->assertFalse( ShopManager::can_use() );
	}

	public function test_needs_label_tab_membership() {
		$this->as_role( 'shop_manager' );
		$fresh      = $this->make_order( [], 'processing' );
		$attention  = $this->with_state( $this->make_order( [], 'on-hold' ), 'needs_attention' );
		$labelled   = $this->with_state( $this->make_order( [], 'processing' ), 'labelled' );
		$no_ship    = $this->make_order( [], 'processing', false );
		$pending    = $this->make_order( [], 'pending' );
		$with_row   = $this->make_order( [], 'processing' );
		Module::instance()->shipments->insert( [ 'order_id' => $with_row->get_id(), 'carrier' => 'ups', 'shipment_id' => 'S9', 'tracking_number' => '1Z9' ] );

		$ids = $this->listed( 'needs-label' );
		$this->assertContains( $fresh->get_id(), $ids );
		$this->assertContains( $attention->get_id(), $ids );
		$this->assertNotContains( $labelled->get_id(), $ids );
		$this->assertNotContains( $no_ship->get_id(), $ids );
		$this->assertNotContains( $pending->get_id(), $ids );
		$this->assertNotContains( $with_row->get_id(), $ids );
		$this->assertSame( $ids, $this->listed( '' ), 'needs-label is the default tab' );
	}

	public function test_state_tabs_and_all() {
		$this->as_role( 'shop_manager' );
		$l = $this->with_state( $this->make_order( [], 'processing' ), 'labelled' );
		$s = $this->with_state( $this->make_order( [], 'processing' ), 'shipped' );
		$d = $this->with_state( $this->make_order( [], 'completed' ), 'delivered' );
		$this->assertSame( [ $l->get_id() ], $this->listed( 'labelled' ) );
		$this->assertSame( [ $s->get_id() ], $this->listed( 'shipped' ) );
		$this->assertSame( [ $d->get_id() ], $this->listed( 'delivered' ) );
		$all = $this->listed( 'all' );
		foreach ( [ $l, $s, $d ] as $o ) {
			$this->assertContains( $o->get_id(), $all );
		}
	}

	public function test_search_by_number_email_and_last_name() {
		$this->as_role( 'shop_manager' );
		$a = $this->make_order( [], 'processing' );
		$b = $this->make_order( [], 'processing' );
		$b->set_billing_last_name( 'Zebrowski' );
		$b->set_billing_email( 'zed@example.org' );
		$b->save();
		$this->assertSame( [ $b->get_id() ], $this->listed( 'all', [ 's' => 'Zebrowski' ] ) );
		$this->assertSame( [ $b->get_id() ], $this->listed( 'all', [ 's' => 'zed@example.org' ] ) );
		$this->assertSame( [ $a->get_id() ], $this->listed( 'needs-label', [ 's' => (string) $a->get_id() ] ) );
	}

	public function test_order_view_shows_ship_to_items_notes_panel_and_label() {
		update_option( 'woocommerce_weight_unit', 'kg' );
		update_option( 'woocommerce_dimension_unit', 'cm' );
		$this->configure_ups();
		$this->as_role( 'shop_manager' );
		$order = $this->make_order( [], 'processing' );
		$order->add_order_note( 'Leave at dock' );
		$order->set_customer_note( 'Ring twice' );
		$order->save();
		$this->queue_token();
		$this->queue_response( 200, $this->fixture( 'ups-ship-single' ) );
		Module::instance()->labels->create_for_order( $order, [ new \Anchor\Shipping\Domain\Parcel( 1, 10, 10, 10 ) ], 'manual' );

		$html = $this->console()->render( [ 'order' => $order->get_id() ] );
		$this->assertStringContainsString( '1 Infinite Loop', $html );
		$this->assertStringContainsString( 'Tip pack', $html );
		$this->assertStringContainsString( 'Leave at dock', $html );
		$this->assertStringContainsString( 'Ring twice', $html );
		$this->assertStringContainsString( 'anchor-shipping-panel', $html );
		$this->assertStringContainsString( '1ZK877V90300000001', $html );
		$this->assertStringContainsString( 'action=anchor_shipping_label', $html );
		$this->assertMatchesRegularExpression( '/<iframe[^>]+anchor_shipping_label/', $html );
		$this->assertStringContainsString( 'data-to="completed"', $html );
		$this->assertStringContainsString( 'data-to="cancelled"', $html );
	}

	public function test_unknown_order_is_reported() {
		$this->as_role( 'shop_manager' );
		$this->assertStringContainsString( 'Order not found', $this->console()->render( [ 'order' => 999999 ] ) );
	}

	public function test_render_marks_the_page_private() {
		$this->as_role( 'shop_manager' );
		remove_all_filters( 'wp_robots' );
		$this->console()->render();
		$this->assertArrayHasKey( 'noindex', apply_filters( 'wp_robots', [] ) );
	}

	public function test_status_to_completed_and_cancelled() {
		$this->as_role( 'shop_manager' );
		$done = $this->make_order( [], 'processing' );
		$out  = $this->ajax()->handle_status( [ 'order_id' => $done->get_id(), 'to' => 'completed' ] );
		$this->assertSame( 'completed', wc_get_order( $done->get_id() )->get_status() );
		$this->assertSame( 'completed', $out['status'] );

		$fired = 0;
		add_action( 'woocommerce_order_status_cancelled', static function () use ( &$fired ) { $fired++; }, 5 );
		$gone = $this->make_order( [], 'processing' );
		$this->ajax()->handle_status( [ 'order_id' => $gone->get_id(), 'to' => 'cancelled' ] );
		$this->assertSame( 'cancelled', wc_get_order( $gone->get_id() )->get_status() );
		$this->assertSame( 1, $fired );
	}

	public function test_cancel_runs_void_on_cancel() {
		$this->configure_ups();
		$this->as_role( 'shop_manager' );
		$order = $this->make_order( [], 'processing' );
		$this->queue_token();
		$this->queue_response( 200, $this->fixture( 'ups-ship-single' ) );
		Module::instance()->labels->create_for_order( $order, [ new \Anchor\Shipping\Domain\Parcel( 1, 10, 10, 10 ) ], 'manual' );
		$this->queue_response( 200, $this->fixture( 'ups-void-ok' ) );
		$this->ajax()->handle_status( [ 'order_id' => $order->get_id(), 'to' => 'cancelled' ] );
		$this->assertSame( [], Module::instance()->shipments->active_for_order( $order->get_id() ) );
	}

	public function test_status_rejects_unknown_target_and_missing_order() {
		$this->as_role( 'shop_manager' );
		$order = $this->make_order( [], 'processing' );
		foreach ( [ [ 'order_id' => $order->get_id(), 'to' => 'refunded' ], [ 'order_id' => $order->get_id(), 'to' => '' ], [ 'order_id' => 999999, 'to' => 'completed' ] ] as $bad ) {
			try {
				$this->ajax()->handle_status( $bad );
				$this->fail( 'expected RuntimeException' );
			} catch ( RuntimeException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
		$this->assertSame( 'processing', wc_get_order( $order->get_id() )->get_status() );
	}

	public function test_status_endpoint_enforces_nonce_and_capability() {
		$order = $this->make_order( [], 'processing' );
		$this->ajax(); // registers hooks
		$this->assertNotFalse( has_action( 'wp_ajax_anchor_shipping_console_status' ) );

		$call = function ( array $post ): array {
			$_POST    = $post;
			$_REQUEST = $post;
			add_filter( 'wp_doing_ajax', '__return_true' );
			add_filter( 'wp_die_ajax_handler', fn() => static function () { throw new WPDieException( 'die' ); } );
			ob_start();
			try {
				$this->ajax()->ajax_status();
			} catch ( WPDieException $e ) {
				// wp_send_json dies after printing.
			}
			return json_decode( (string) ob_get_clean(), true ) ?: [];
		};

		$this->as_role( 'customer' );
		$res = $call( [ 'order_id' => $order->get_id(), 'to' => 'completed', 'nonce' => wp_create_nonce( 'anchor_shipping' ) ] );
		$this->assertFalse( $res['success'] ?? true );
		$this->as_role( 'shop_manager' );
		$res = $call( [ 'order_id' => $order->get_id(), 'to' => 'completed', 'nonce' => 'bad' ] );
		$this->assertFalse( $res['success'] ?? true );
		$this->assertSame( 'processing', wc_get_order( $order->get_id() )->get_status() );
		$res = $call( [ 'order_id' => $order->get_id(), 'to' => 'completed', 'nonce' => wp_create_nonce( 'anchor_shipping' ) ] );
		$this->assertTrue( $res['success'] ?? false );
		$this->assertSame( 'completed', wc_get_order( $order->get_id() )->get_status() );
		$_POST = $_REQUEST = [];
	}

	public function test_panel_and_ajax_are_available_outside_wp_admin() {
		$this->assertNotFalse( has_action( 'wp_ajax_anchor_shipping_create_label' ) );
		$this->assertFalse( is_admin() );
		$this->assertTrue( method_exists( \Anchor\Shipping\Admin\OrderPanel::class, 'enqueue' ) );
	}
}
