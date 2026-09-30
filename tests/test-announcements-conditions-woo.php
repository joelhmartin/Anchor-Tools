<?php
use Anchor\Announcements\Audience\Conditions\WcOrderCount;
use Anchor\Announcements\Audience\Conditions\WcPurchased;
use Anchor\Announcements\Audience\Conditions\WcTotalSpent;

class Test_Announcements_Conditions_Woo extends Anchor_Announcements_TestCase {

	public function set_up() {
		parent::set_up();
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce not installed in this run.' );
		}
		// Default run exercises the HPOS tables; WC_HPOS=0 reruns the suite on legacy posts storage.
		update_option( 'woocommerce_custom_orders_table_enabled', '0' === getenv( 'WC_HPOS' ) ? 'no' : 'yes' );
	}

	private function product( string $name ): int {
		$p = new WC_Product_Simple();
		$p->set_name( $name );
		$p->set_regular_price( '10' );
		return $p->save();
	}

	private function order( int $product_id, string $date, string $status = 'completed', int $customer_id = 0, string $email = 'guest@x.com', int $qty = 1 ): int {
		$o = wc_create_order( [ 'customer_id' => $customer_id ] );
		$o->add_product( wc_get_product( $product_id ), $qty );
		$o->set_billing_email( $email );
		$o->set_billing_first_name( 'Gina' );
		$o->set_billing_last_name( 'Guest' );
		$o->calculate_totals();
		$o->set_date_created( strtotime( $date . ' 12:00:00 UTC' ) );
		$o->set_status( $status );
		return $o->save();
	}

	public function test_orders_land_in_the_storage_the_queries_read() {
		global $wpdb;
		$hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		$this->assertSame( '0' !== getenv( 'WC_HPOS' ), $hpos );
		$id = $this->order( $this->product( 'A' ), '2026-02-10' );
		$this->assertSame( $hpos ? 1 : 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders WHERE id = %d", $id ) ) );
		$this->assertSame( $hpos ? 0 : 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID = %d AND post_type = 'shop_order'", $id ) ) );
	}

	public function test_purchased_product_in_date_range_includes_guests() {
		$a = $this->product( 'A' );
		$b = $this->product( 'B' );
		$this->order( $a, '2026-02-10', 'completed', 0, 'guest@x.com' );
		$this->order( $a, '2025-02-10', 'completed', 0, 'old@x.com' );
		$this->order( $b, '2026-02-10', 'completed', 0, 'other@x.com' );
		$set = ( new WcPurchased() )->match( [ 'products' => [ $a ], 'from' => '2026-01-01', 'to' => '2026-12-31', 'statuses' => [] ] );
		$this->assertSame( [ 'guest@x.com' ], array_column( $set->all(), 'email' ) );
		$this->assertSame( 'Gina Guest', $set->get( 'guest@x.com' )['name'] );
	}

	public function test_status_filter_excludes_refunded_by_default() {
		$a = $this->product( 'A' );
		$this->order( $a, '2026-02-10', 'refunded', 0, 'refund@x.com' );
		$this->assertSame( 0, ( new WcPurchased() )->match( [ 'products' => [ $a ] ] )->count() );
		$this->assertSame( 1, ( new WcPurchased() )->match( [ 'products' => [ $a ], 'statuses' => [ 'refunded' ] ] )->count() );
	}

	public function test_registered_customer_uses_account_email_and_merges_with_guest_orders() {
		$a    = $this->product( 'A' );
		$user = $this->make_user( 'member@x.com', [ 'display_name' => 'Mem Ber' ] );
		$this->order( $a, '2026-02-10', 'completed', $user, 'billing-alias@x.com' );
		$this->order( $a, '2026-02-11', 'completed', 0, 'member@x.com' );
		$set = ( new WcPurchased() )->match( [ 'products' => [ $a ] ] );
		$this->assertSame( [ 'member@x.com' ], array_column( $set->all(), 'email' ) );
		$this->assertSame( $user, $set->get( 'member@x.com' )['user_id'] );
	}

	public function test_any_product_when_products_empty() {
		$a = $this->product( 'A' );
		$this->order( $a, '2026-02-10' );
		$this->assertSame( 1, ( new WcPurchased() )->match( [ 'products' => [] ] )->count() );
	}

	public function test_variation_id_matches() {
		$parent = new WC_Product_Variable();
		$parent->set_name( 'V' );
		$pid = $parent->save();
		$v   = new WC_Product_Variation();
		$v->set_parent_id( $pid );
		$v->set_regular_price( '5' );
		$vid = $v->save();
		$this->order( $vid, '2026-02-10', 'completed', 0, 'var@x.com' );
		$this->assertTrue( ( new WcPurchased() )->match( [ 'products' => [ $vid ] ] )->has( 'var@x.com' ) );
		$this->assertTrue( ( new WcPurchased() )->match( [ 'products' => [ $pid ] ] )->has( 'var@x.com' ) );
	}

	public function test_order_count_and_total_spent() {
		$a = $this->product( 'A' );
		$this->order( $a, '2026-02-10', 'completed', 0, 'two@x.com' );
		$this->order( $a, '2026-03-10', 'completed', 0, 'two@x.com', 3 );
		$this->order( $a, '2026-03-10', 'completed', 0, 'one@x.com' );
		$this->assertSame( [ 'two@x.com' ], array_column( ( new WcOrderCount() )->match( [ 'compare' => '>=', 'number' => 2 ] )->all(), 'email' ) );
		$this->assertSame( [ 'two@x.com' ], array_column( ( new WcTotalSpent() )->match( [ 'compare' => '>=', 'amount' => 35 ] )->all(), 'email' ) );
		$this->assertSame( [ 'one@x.com' ], array_column( ( new WcTotalSpent() )->match( [ 'compare' => '<=', 'amount' => 10 ] )->all(), 'email' ) );
	}

	public function test_guest_buyers_join_the_universe() {
		$a = $this->product( 'A' );
		$this->order( $a, '2026-02-10', 'completed', 0, 'guest@x.com' );
		$this->assertTrue( $this->module()->resolver()->universe()->has( 'guest@x.com' ) );
	}
}
