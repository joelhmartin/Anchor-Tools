<?php
use Anchor\Shipping\Frontend\ConsoleAjax;
use Anchor\Shipping\Frontend\ShopManager;
use Anchor\Shipping\Module;
use Anchor\Shipping\Services\OrderRefund;

/** Gateway double that records refunds instead of calling a processor. */
class Anchor_Test_Refund_Gateway extends WC_Payment_Gateway {
	public static array $refunds = [];
	public static bool $fail     = false;
	public function __construct( string $id = 'anchor_test_refunds', bool $refunds = true ) {
		$this->id       = $id;
		$this->enabled  = 'yes';
		$this->title    = 'Test gateway';
		$this->supports = $refunds ? [ 'products', 'refunds' ] : [ 'products' ];
	}
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		if ( self::$fail ) {
			return new WP_Error( 'declined', 'Processor declined the refund.' );
		}
		self::$refunds[] = [ (int) $order_id, (float) $amount ];
		return true;
	}
}

class Test_Shipping_Console_Refund extends Anchor_Shipping_TestCase {

	private $gateways_filter;

	public function set_up() {
		parent::set_up();
		Anchor_Test_Refund_Gateway::$refunds = [];
		Anchor_Test_Refund_Gateway::$fail    = false;
		$this->gateways_filter = static function ( $g ) {
			$g[] = new Anchor_Test_Refund_Gateway();
			$g[] = new Anchor_Test_Refund_Gateway( 'anchor_test_norefunds', false );
			return $g;
		};
		add_filter( 'woocommerce_payment_gateways', $this->gateways_filter );
		WC()->payment_gateways()->init();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );
	}

	public function tear_down() {
		remove_filter( 'woocommerce_payment_gateways', $this->gateways_filter );
		WC()->payment_gateways()->init();
		parent::tear_down();
	}

	private function paid_order( string $gateway = 'anchor_test_refunds', int $stock = 5 ): WC_Order {
		$pid = $this->make_product( [ 'weight' => 0.5, 'box' => 'small' ] );
		$p   = wc_get_product( $pid );
		$p->set_manage_stock( true );
		$p->set_stock_quantity( $stock );
		$p->save();
		$order = $this->make_order( [ $pid ], 'processing' );
		$order->set_payment_method( $gateway );
		$order->set_date_paid( time() );
		$order->save();
		wc_reduce_stock_levels( $order->get_id() ); // as checkout does; Woo restocks only what an order took
		return wc_get_order( $order->get_id() );
	}

	private function console(): ShopManager {
		$m = Module::instance();
		return new ShopManager( $m->labels, $m->shipments, $m->carriers, $m->packer );
	}

	public function test_refund_in_full_returns_the_money_restocks_and_marks_refunded() {
		$order = $this->paid_order();
		$total = (float) $order->get_total();
		$pid   = current( $order->get_items() )->get_product_id();

		$out = ( new ConsoleAjax() )->handle_status( [ 'order_id' => $order->get_id(), 'to' => 'refunded' ] );

		$fresh = wc_get_order( $order->get_id() );
		$this->assertSame( 'refunded', $out['status'] );
		$this->assertSame( 'refunded', $fresh->get_status() );
		$this->assertSame( [ [ $order->get_id(), $total ] ], Anchor_Test_Refund_Gateway::$refunds, 'the gateway was asked to send the whole total back' );
		$this->assertEqualsWithDelta( $total, (float) $fresh->get_total_refunded(), 0.001 );
		$this->assertSame( 5, wc_get_product( $pid )->get_stock_quantity(), 'the item went back into stock (5 → 4 at checkout → 5)' );
	}

	public function test_processor_decline_leaves_the_order_as_it_was() {
		Anchor_Test_Refund_Gateway::$fail = true;
		$order = $this->paid_order();
		try {
			( new ConsoleAjax() )->handle_status( [ 'order_id' => $order->get_id(), 'to' => 'refunded' ] );
			$this->fail( 'expected the decline to surface' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'declined', $e->getMessage() );
		}
		$fresh = wc_get_order( $order->get_id() );
		$this->assertSame( 'processing', $fresh->get_status() );
		$this->assertSame( 0.0, (float) $fresh->get_total_refunded() );
	}

	public function test_gateway_without_refunds_is_refused_and_offers_no_button() {
		$order = $this->paid_order( 'anchor_test_norefunds' );
		$this->assertSame( 0.0, OrderRefund::refundable_amount( $order ) );
		$this->expectException( RuntimeException::class );
		( new ConsoleAjax() )->handle_status( [ 'order_id' => $order->get_id(), 'to' => 'refunded' ] );
	}

	public function test_unpaid_order_cannot_be_refunded() {
		$order = $this->paid_order();
		$order->set_status( 'pending' );
		$order->save();
		$this->assertSame( 0.0, OrderRefund::refundable_amount( $order ) );
		$this->expectException( RuntimeException::class );
		( new ConsoleAjax() )->handle_status( [ 'order_id' => $order->get_id(), 'to' => 'refunded' ] );
	}

	public function test_paid_order_view_offers_refund_and_says_cancel_keeps_the_money() {
		$order = $this->paid_order();
		$html  = $this->console()->render( [ 'order' => $order->get_id() ] );
		$this->assertStringContainsString( 'data-to="refunded"', $html );
		$this->assertMatchesRegularExpression( '/Refund \$[\d,.]+/', $html );
		$this->assertStringContainsString( 'Cancel without refund', $html );
	}

	public function test_unpaid_order_view_keeps_plain_cancel_and_no_refund() {
		$order = $this->make_order( [], 'pending' );
		$html  = $this->console()->render( [ 'order' => $order->get_id() ] );
		$this->assertStringNotContainsString( 'data-to="refunded"', $html );
		$this->assertStringContainsString( 'Cancel order', $html );
		$this->assertStringNotContainsString( 'Cancel without refund', $html );
	}

	public function test_on_hold_order_without_a_recorded_payment_is_not_refundable() {
		$order = $this->paid_order();
		$order->set_date_paid( null );
		$order->set_status( 'on-hold' );
		$order->save();
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 0.0, OrderRefund::refundable_amount( $order ) );
		$html = $this->console()->render( [ 'order' => $order->get_id() ] );
		$this->assertStringNotContainsString( 'data-to="refunded"', $html );
		$this->assertStringNotContainsString( 'Cancel without refund', $html, 'no payment recorded, so cancel keeps no money' );
		$this->expectException( RuntimeException::class );
		OrderRefund::refund_in_full( $order, 'test' );
	}

	public function test_a_refund_already_in_progress_blocks_a_second_one() {
		global $wpdb;
		$order = $this->paid_order();
		$wpdb->insert( $wpdb->options, [ 'option_name' => 'anchor_shipping_refund_lock_' . $order->get_id(), 'option_value' => (string) time(), 'autoload' => 'off' ] );
		try {
			OrderRefund::refund_in_full( $order, 'test' );
			$this->fail( 'expected the lock to refuse' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'already in progress', $e->getMessage() );
		}
		$this->assertSame( [], Anchor_Test_Refund_Gateway::$refunds, 'the gateway was never called' );
	}

	public function test_a_stale_lock_from_a_dead_request_is_taken_over_and_released() {
		global $wpdb;
		$order = $this->paid_order();
		$name  = 'anchor_shipping_refund_lock_' . $order->get_id();
		$wpdb->insert( $wpdb->options, [ 'option_name' => $name, 'option_value' => (string) ( time() - 600 ), 'autoload' => 'off' ] );
		OrderRefund::refund_in_full( $order, 'test' );
		$this->assertCount( 1, Anchor_Test_Refund_Gateway::$refunds );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) ), 'lock released' );
	}

	public function test_negative_fee_keeps_refund_lines_in_step_with_the_amount() {
		$order = $this->paid_order();
		$fee   = new WC_Order_Item_Fee();
		$fee->set_name( 'Courtesy discount' );
		$fee->set_total( '-10' );
		$order->add_item( $fee );
		$order->calculate_totals( false );
		$order->save();
		$order  = wc_get_order( $order->get_id() );
		$refund = OrderRefund::refund_in_full( $order, 'test' );
		$lines  = 0.0;
		foreach ( $refund->get_items( [ 'line_item', 'fee', 'shipping' ] ) as $item ) {
			$lines += (float) $item->get_total();
		}
		$this->assertEqualsWithDelta( -1 * (float) $refund->get_amount(), $lines, 0.001, 'refund lines add up to the money refunded' );
		$this->assertEqualsWithDelta( (float) $order->get_total(), (float) $refund->get_amount(), 0.001 );
	}
}
