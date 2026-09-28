<?php
/**
 * Anchor Courses - which WooCommerce orders may create a WordPress account,
 * and what a course buyer is left with afterwards (Phase 5 final review C1,
 * I1, I2).
 *
 * C1: only an order that actually carries a mapped course line may create an
 * account - a guest ticket-only (or any unmapped) order, at any status, must
 * never mint one, and the revoke/refund paths never create accounts at all.
 * I1: `anchor_courses_create_account` can decline the create for an order.
 * I2: checkout requires an account when the cart holds a mapped product;
 * an order created outside checkout that still needs an account is linked to
 * it and the NEW account (only) gets WordPress's set-password notice.
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Integrations\WooCommerce;
use Anchor\Courses\Services\EnrollmentService;
use Anchor\Courses\Support\Roles;

/** @group courses @group woocommerce */
class Test_Courses_Wc_Accounts extends Anchor_Courses_TestCase {

	private EnrollmentService $enrollments;
	private int $course;
	private int $product;
	private int $plain;

	/** @var array<int,string> Recipients of wp_new_user_notification()'s user email. */
	private array $notices = [];

	public function set_up() {
		parent::set_up();
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is not active in this run.' );
		}

		$this->enrollments = new EnrollmentService();
		$this->course      = $this->make_course( [], 'Paid Course' );
		$this->product     = $this->factory->post->create( [ 'post_type' => 'product', 'post_status' => 'publish' ] );
		$this->plain       = $this->factory->post->create( [ 'post_type' => 'product', 'post_status' => 'publish' ] );

		WooCommerce::set_courses_for_product( $this->product, [ $this->course ] );

		$this->notices = [];
		add_filter( 'wp_new_user_notification_email', [ $this, 'spy_notice' ], 10, 2 );
	}

	public function tear_down() {
		remove_filter( 'wp_new_user_notification_email', [ $this, 'spy_notice' ], 10 );
		remove_all_filters( 'anchor_courses_create_account' );
		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}
		parent::tear_down();
	}

	/** `wp_new_user_notification_email`: record who the set-password notice went to. */
	public function spy_notice( array $email, $user ): array {
		$this->notices[] = (string) $email['to'];
		return $email;
	}

	private function user_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" );
	}

	/** A guest order (no customer id) for one product, moved through a REAL status transition. */
	private function guest_order( int $product_id, string $email, string $status ) {
		$order = wc_create_order();
		$order->set_billing_email( $email );
		$order->set_billing_first_name( 'Gus' );
		$order->set_billing_last_name( 'Guest' );
		$order->add_product( wc_get_product( $product_id ), 1 );
		$order->save();

		$order->set_status( $status );
		$order->save();

		return wc_get_order( $order->get_id() );
	}

	/* ---------------------------------------------------------------------
	 * C1 - no course line, no account.
	 * ------------------------------------------------------------------- */

	public function test_a_guest_order_with_no_course_line_creates_no_account_at_processing() {
		$before = $this->user_count();

		$this->guest_order( $this->plain, 'ticket-only-processing@example.test', 'processing' );

		$this->assertSame( $before, $this->user_count(), 'An order with no mapped course must never create a WordPress account.' );
		$this->assertFalse( get_user_by( 'email', 'ticket-only-processing@example.test' ) );
	}

	public function test_a_guest_order_with_no_course_line_creates_no_account_at_failed() {
		$before = $this->user_count();

		$this->guest_order( $this->plain, 'ticket-only-failed@example.test', 'failed' );

		$this->assertSame( $before, $this->user_count() );
	}

	/** A failed/cancelled order that DOES carry a course line still has nothing to revoke, so it creates nobody either. */
	public function test_a_failed_or_cancelled_course_order_creates_no_account() {
		$before = $this->user_count();

		$this->guest_order( $this->product, 'course-failed@example.test', 'failed' );
		$this->guest_order( $this->product, 'course-cancelled@example.test', 'cancelled' );

		$this->assertSame( $before, $this->user_count(), 'Revocation must look an account up, never create one.' );
	}

	public function test_revoke_and_refund_policy_never_create_an_account() {
		$order = wc_create_order();
		$order->set_billing_email( 'revoke-only@example.test' );
		$order->add_product( wc_get_product( $this->product ), 1 );
		$order->save();

		$before = $this->user_count();

		$this->assertSame( 0, ( new WooCommerce() )->revoke_order( $order->get_id() ) );
		WooCommerce::refund_policy( $order->get_id(), $this->course );

		$this->assertSame( $before, $this->user_count() );
	}

	/** Revocation still finds a guest order's account by billing email when one exists. */
	public function test_revoke_finds_a_guest_orders_account_by_billing_email() {
		$email = 'revoke-lookup@example.test';
		$order = $this->guest_order( $this->product, $email, 'processing' );
		$user  = get_user_by( 'email', $email );
		$this->assertInstanceOf( WP_User::class, $user );

		// Simulate an order that was never linked (e.g. created before this fix).
		$order->set_customer_id( 0 );
		$order->save();

		$this->assertSame( 1, ( new WooCommerce() )->revoke_order( $order->get_id() ) );
		$this->assertFalse( Roles::user_has( $user->ID, Roles::access_slug( $this->course ) ) );
	}

	/* ---------------------------------------------------------------------
	 * C1 + I2(b) - a course order creates exactly one account, linked + noticed.
	 * ------------------------------------------------------------------- */

	public function test_a_guest_course_order_creates_exactly_one_linked_account_and_sends_the_set_password_notice() {
		$email  = 'new-buyer@example.test';
		$before = $this->user_count();

		$order = $this->guest_order( $this->product, $email, 'processing' );

		$this->assertSame( $before + 1, $this->user_count(), 'Exactly one account for a guest course buyer.' );

		$user = get_user_by( 'email', $email );
		$this->assertInstanceOf( WP_User::class, $user );
		$this->assertTrue( $this->enrollments->is_enrolled( $user->ID, $this->course ) );

		$this->assertSame( $user->ID, wc_get_order( $order->get_id() )->get_customer_id(), 'The order must be linked to the account it enrolled.' );
		$this->assertSame( [ $email ], $this->notices, 'The account this adapter just created gets WordPress\'s set-password notice, once.' );

		// A second qualifying status must not create or notify again.
		$order->set_status( 'completed' );
		$order->save();
		$this->assertSame( $before + 1, $this->user_count() );
		$this->assertCount( 1, $this->notices );
	}

	public function test_an_existing_account_is_linked_but_never_sent_the_set_password_notice() {
		$email    = 'existing-buyer@example.test';
		$existing = $this->make_learner( [ 'user_email' => $email ] );
		$before   = $this->user_count();

		$order = $this->guest_order( $this->product, $email, 'processing' );

		$this->assertSame( $before, $this->user_count() );
		$this->assertTrue( $this->enrollments->is_enrolled( $existing, $this->course ) );
		$this->assertSame( $existing, wc_get_order( $order->get_id() )->get_customer_id() );
		$this->assertSame( [], $this->notices, 'An account that already existed must not be sent a set-password notice.' );
	}

	/* ---------------------------------------------------------------------
	 * I1 - the courses-side opt-out.
	 * ------------------------------------------------------------------- */

	public function test_the_create_account_filter_can_decline_for_an_order() {
		$seen = null;
		add_filter(
			'anchor_courses_create_account',
			static function ( $create, $email, $context = null ) use ( &$seen ) {
				$seen = $context;
				return false;
			},
			10,
			3
		);

		$before = $this->user_count();
		$order  = $this->guest_order( $this->product, 'declined@example.test', 'processing' );

		$this->assertInstanceOf( WC_Order::class, $seen, 'The filter receives the order as its context.' );
		$this->assertSame( $order->get_id(), $seen->get_id() );
		$this->assertSame( $before, $this->user_count(), 'Declined: no account.' );
		$this->assertSame( [], $this->notices );
		$this->assertSame( 0, wc_get_order( $order->get_id() )->get_customer_id() );

		$contents = array_map( static fn( $n ) => (string) $n->content, wc_get_order_notes( [ 'order_id' => $order->get_id() ] ) );
		$this->assertNotEmpty(
			array_filter( $contents, static fn( $c ) => false !== strpos( $c, 'account_not_created' ) ),
			'A declined create is noted on the order so a human can follow up.'
		);
	}

	/* ---------------------------------------------------------------------
	 * I2(a) - checkout requires an account for a course cart.
	 * ------------------------------------------------------------------- */

	private function load_cart(): void {
		if ( function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}
		$this->assertNotNull( WC()->cart, 'Fixture: the cart must load.' );
		WC()->cart->empty_cart();
	}

	public function test_a_cart_with_a_course_product_requires_registration_at_checkout() {
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );
		update_option( 'woocommerce_enable_signup_and_login_from_checkout', 'no' );
		wp_set_current_user( 0 );
		$this->load_cart();

		$product = wc_get_product( $this->product );
		$product->set_regular_price( '10' );
		$product->save();

		WC()->cart->add_to_cart( $this->product, 1 );

		$this->assertTrue( WC()->checkout()->is_registration_required(), 'Guest checkout must be off for a course cart.' );
		$this->assertTrue( WC()->checkout()->is_registration_enabled(), 'Signing up at checkout must be on for a course cart.' );
	}

	public function test_a_cart_with_no_course_product_keeps_the_store_settings() {
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );
		update_option( 'woocommerce_enable_signup_and_login_from_checkout', 'no' );
		wp_set_current_user( 0 );
		$this->load_cart();

		$product = wc_get_product( $this->plain );
		$product->set_regular_price( '10' );
		$product->save();

		WC()->cart->add_to_cart( $this->plain, 1 );

		$this->assertFalse( WC()->checkout()->is_registration_required() );
		$this->assertFalse( WC()->checkout()->is_registration_enabled() );
	}
}
