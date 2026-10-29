<?php
// tests/test-checkout-questions-matcher.php
require_once __DIR__ . '/class-anchor-events-testcase.php';

use Anchor\CheckoutQuestions\Services\Matcher;
use Anchor\CheckoutQuestions\Support\QuestionSets;

class Test_Checkout_Questions_Matcher extends WP_UnitTestCase {
	use Anchor_Events_Fixtures;

	private function set( array $products = [], array $cats = [], string $status = 'publish' ): int {
		$id = (int) self::factory()->post->create( [ 'post_type' => QuestionSets::CPT, 'post_status' => $status, 'post_title' => 'S' ] );
		update_post_meta( $id, QuestionSets::META_PRODUCTS, $products );
		update_post_meta( $id, QuestionSets::META_CATEGORIES, $cats );
		return $id;
	}

	private function product( array $cats = [] ): int {
		$id = (int) self::factory()->post->create( [ 'post_type' => 'product', 'post_status' => 'publish' ] );
		if ( $cats ) {
			wp_set_object_terms( $id, $cats, 'product_cat' );
		}
		return $id;
	}

	private function cat( int $parent = 0 ): int {
		$t = self::factory()->term->create( [ 'taxonomy' => 'product_cat', 'parent' => $parent ] );
		return (int) $t;
	}

	private function ticket( array $cats = [] ): int {
		$this->require_wc();
		$event = $this->make_event();
		$pid   = $this->product( $cats );
		update_post_meta( $pid, \Anchor\Events\WooCommerce::META_ENABLED, '1' );
		update_post_meta( $pid, \Anchor\Events\WooCommerce::META_EVENT_ID, $event );
		return $pid;
	}

	private function line( int $p, int $v = 0 ): array {
		return [ 'product_id' => $p, 'variation_id' => $v ];
	}

	public function test_product_and_category_match_once() {
		$c = $this->cat();
		$p = $this->product( [ $c ] );
		$s = $this->set( [ $p ], [ $c ] );
		$this->assertSame( [ $s ], Matcher::for_lines( [ $this->line( $p ) ] ) );
	}

	public function test_two_lines_match_once() {
		$p1 = $this->product();
		$p2 = $this->product();
		$s  = $this->set( [ $p1, $p2 ] );
		$this->assertSame( [ $s ], Matcher::for_lines( [ $this->line( $p1 ), $this->line( $p2 ) ] ) );
	}

	public function test_variation_matches_via_parent() {
		$p = $this->product();
		$v = (int) self::factory()->post->create( [ 'post_type' => 'product_variation', 'post_parent' => $p ] );
		$s = $this->set( [ $p ] );
		$this->assertSame( [ $s ], Matcher::for_lines( [ $this->line( $p, $v ) ] ) );
	}

	public function test_child_category_matches_assigned_parent() {
		$parent = $this->cat();
		$child  = $this->cat( $parent );
		$p      = $this->product( [ $child ] );
		$s      = $this->set( [], [ $parent ] );
		$this->assertSame( [ $s ], Matcher::for_lines( [ $this->line( $p ) ] ) );
	}

	public function test_draft_set_never_matches() {
		$p = $this->product();
		$this->set( [ $p ], [], 'draft' );
		$this->assertSame( [], Matcher::for_lines( [ $this->line( $p ) ] ) );
	}

	public function test_ticket_not_matched_by_category_but_course_is() {
		$c      = $this->cat();
		$ticket = $this->ticket( [ $c ] );
		$course = $this->product( [ $c ] );
		$s      = $this->set( [], [ $c ] );
		$this->assertSame( [], Matcher::for_lines( [ $this->line( $ticket ) ] ) );
		$this->assertSame( [ $s ], Matcher::for_lines( [ $this->line( $ticket ), $this->line( $course ) ] ) );
	}

	public function test_ticket_matched_when_assigned_explicitly() {
		$c      = $this->cat();
		$ticket = $this->ticket( [ $c ] );
		$s      = $this->set( [ $ticket ], [ $c ] );
		$this->assertSame( [ $s ], Matcher::for_lines( [ $this->line( $ticket ) ] ) );
	}

	public function test_events_module_disabled_ticket_matches_by_category() {
		$c      = $this->cat();
		$ticket = $this->ticket( [ $c ] );
		$s      = $this->set( [], [ $c ] );
		$opts   = get_option( Anchor_Schema_Admin::OPTION_KEY, [] );
		$off    = $opts;
		$off['modules']['events_manager'] = false;
		update_option( Anchor_Schema_Admin::OPTION_KEY, $off, false );
		$this->assertSame( [ $s ], Matcher::for_lines( [ $this->line( $ticket ) ] ) );
		update_option( Anchor_Schema_Admin::OPTION_KEY, $opts, false );
	}

	public function test_for_order_and_for_cart_build_lines() {
		$this->require_wc();
		$p     = $this->product();
		$s     = $this->set( [ $p ] );
		$order = wc_create_order();
		$item  = new WC_Order_Item_Product();
		$item->set_product_id( $p );
		$order->add_item( $item );
		$order->save();
		$this->assertSame( [ $s ], Matcher::for_order( $order ) );
		$this->assertSame( [], Matcher::for_cart() );
	}

	public function test_results_follow_published_order() {
		$p  = $this->product();
		$s1 = $this->set( [ $p ] );
		$s2 = $this->set( [ $p ] );
		wp_update_post( [ 'ID' => $s1, 'menu_order' => 5 ] );
		wp_update_post( [ 'ID' => $s2, 'menu_order' => 1 ] );
		$this->assertSame( [ $s2, $s1 ], Matcher::for_lines( [ $this->line( $p ) ] ) );
	}
}
