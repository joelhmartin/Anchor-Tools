<?php
/**
 * Anchor Courses - WooCommerce refund and cancellation handling
 * (Task 40, brief 18, design spec 4).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Content\CoursePostType;
use Anchor\Courses\Integrations\WooCommerce;
use Anchor\Courses\Services\EnrollmentService;
use Anchor\Courses\Support\Roles;

/** @group courses @group woocommerce */
class Test_Courses_Wc_Refunds extends Anchor_Courses_TestCase {

	private EnrollmentService $enrollments;
	private WooCommerce $adapter;
	private int $customer;
	private int $course;
	private int $product;

	public function set_up() {
		parent::set_up();
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is not active in this run.' );
		}

		$this->enrollments = new EnrollmentService();
		$this->adapter      = new WooCommerce();
		$this->customer     = $this->make_learner( [ 'role' => 'customer' ] );
		$this->course       = $this->make_course( [], 'Paid Course' );
		$this->product      = $this->factory->post->create( [ 'post_type' => 'product', 'post_status' => 'publish' ] );

		WooCommerce::set_courses_for_product( $this->product, [ $this->course ] );
	}

	public function tear_down() {
		remove_all_filters( 'anchor_courses_wc_refund_policy' );
		remove_all_filters( 'anchor_courses_role_loss_policy' );
		remove_role( Roles::access_slug( $this->course ) );
		parent::tear_down();
	}

	/**
	 * This module's own "access revoked" notes on an order - WooCommerce adds
	 * its own status-change/email notes to every order, so assertions must
	 * filter to the ones this adapter wrote, not the note count overall.
	 *
	 * @return string[]
	 */
	private function revoke_notes( int $order_id ): array {
		$notes = wc_get_order_notes( [ 'order_id' => $order_id ] );
		return array_values(
			array_filter(
				array_map( static fn( $note ) => (string) $note->content, $notes ),
				static fn( string $content ) => false !== strpos( $content, 'Course access revoked' )
			)
		);
	}

	/** @return \WC_Order */
	private function paid_order() {
		$order = wc_create_order( [ 'customer_id' => $this->customer ] );
		$order->add_product( wc_get_product( $this->product ), 1 );
		$order->set_status( 'processing' );
		$order->save();
		$this->adapter->enroll_order( $order->get_id() );
		return $order;
	}

	public function test_the_default_policy_is_remove_role() {
		$order = $this->paid_order();
		$this->assertSame( 'remove_role', WooCommerce::refund_policy( $order->get_id(), $this->course ) );
	}

	/**
	 * The default pairing: access goes, progress stays. Re-granting the role
	 * resumes the learner exactly where they stopped.
	 */
	public function test_a_full_refund_removes_access_and_keeps_the_progress_row() {
		$order = $this->paid_order();

		$this->assertSame( 1, $this->adapter->revoke_order( $order->get_id() ) );

		$this->assertFalse( Roles::user_has( $this->customer, Roles::access_slug( $this->course ) ) );
		$this->assertSame(
			'enrolled',
			$this->enrollments->get( $this->customer, $this->course )->status,
			'anchor_courses_role_loss_policy defaults to keep (design spec 3.1).'
		);

		$this->assertCount( 1, $this->revoke_notes( $order->get_id() ) );
	}

	public function test_the_refund_policy_filter_can_keep_access() {
		$order = $this->paid_order();
		add_filter( 'anchor_courses_wc_refund_policy', static fn() => 'keep', 10, 4 );

		$this->assertSame( 0, $this->adapter->revoke_order( $order->get_id() ) );
		$this->assertTrue( Roles::user_has( $this->customer, Roles::access_slug( $this->course ) ) );
		$this->assertSame( 'enrolled', $this->enrollments->get( $this->customer, $this->course )->status );
	}

	/** The second policy decides what the role loss means for the row. */
	public function test_the_role_loss_policy_can_cancel_the_row_too() {
		$order = $this->paid_order();
		add_filter( 'anchor_courses_role_loss_policy', static fn() => 'cancel', 10, 4 );

		$this->adapter->revoke_order( $order->get_id() );

		$this->assertFalse( Roles::user_has( $this->customer, Roles::access_slug( $this->course ) ) );
		$this->assertSame( 'cancelled', $this->enrollments->get( $this->customer, $this->course )->status );
	}

	/**
	 * A manual comp granted on top of the purchase upgrades the grants map's
	 * record (Support\Roles::record_grant()) to `manual`; revoke_order()
	 * reads that record, not the enrolment row's frozen first source, so the
	 * refund correctly finds nothing of ITS to take back.
	 */
	public function test_a_manual_grant_on_the_same_course_survives_the_refund() {
		$order = $this->paid_order();
		Roles::grant_access( $this->customer, $this->course, 'manual' );

		$this->assertSame( 0, $this->adapter->revoke_order( $order->get_id() ) );
		$this->assertTrue( Roles::user_has( $this->customer, Roles::access_slug( $this->course ) ) );
	}

	public function test_a_cancelled_order_status_also_revokes() {
		$order = $this->paid_order();

		$this->adapter->on_order_status_changed( $order->get_id(), 'processing', 'cancelled', $order );

		$this->assertFalse( Roles::user_has( $this->customer, Roles::access_slug( $this->course ) ) );
	}

	public function test_a_failed_order_status_also_revokes() {
		$order = $this->paid_order();

		$this->adapter->on_order_status_changed( $order->get_id(), 'processing', 'failed', $order );

		$this->assertFalse( Roles::user_has( $this->customer, Roles::access_slug( $this->course ) ) );
	}

	/**
	 * The grants map holds ONE record per course (Roles::GRANTS_META); when a
	 * second order grants a course the learner already holds, grant_access()
	 * still calls record_grant(), but record_grant() keeps the FIRST
	 * non-manual reason - so the map goes on crediting the first order.
	 * Refunding the second order must therefore be a no-op: its source_id
	 * never matches the record revoke_order() reads (progress.md T40
	 * ruling 2 / recommendation: "revoke only if the record's source_id is
	 * this order").
	 */
	public function test_a_second_order_that_granted_the_same_course_does_not_revoke_the_first_orders_access() {
		$first = $this->paid_order();

		$second = wc_create_order( [ 'customer_id' => $this->customer ] );
		$second->add_product( wc_get_product( $this->product ), 1 );
		$second->set_status( 'processing' );
		$second->save();
		$this->adapter->enroll_order( $second->get_id() ); // No-op: role already held; grants map still credits $first.

		$this->assertSame( 0, $this->adapter->revoke_order( $second->get_id() ) );
		$this->assertTrue( Roles::user_has( $this->customer, Roles::access_slug( $this->course ) ) );

		// The first order still owns the record, so it can still revoke.
		$this->assertSame( 1, $this->adapter->revoke_order( $first->get_id() ) );
		$this->assertFalse( Roles::user_has( $this->customer, Roles::access_slug( $this->course ) ) );
	}

	public function test_a_refund_on_an_unrelated_order_changes_nothing() {
		$this->paid_order();

		$other = wc_create_order( [ 'customer_id' => $this->customer ] );
		$other->set_status( 'processing' );
		$other->save();

		$this->assertSame( 0, $this->adapter->revoke_order( $other->get_id() ) );
		$this->assertTrue( Roles::user_has( $this->customer, Roles::access_slug( $this->course ) ) );
	}

	public function test_revoking_twice_is_harmless_and_notes_once() {
		$order = $this->paid_order();

		$this->assertSame( 1, $this->adapter->revoke_order( $order->get_id() ) );
		$this->assertSame( 0, $this->adapter->revoke_order( $order->get_id() ) );

		$this->assertCount( 1, $this->revoke_notes( $order->get_id() ), 'A repeat revoke must not add a second note.' );
	}

	public function test_credits_and_certificates_already_earned_are_never_deleted() {
		$order  = $this->paid_order();
		$credit = ( new \Anchor\Courses\Services\CreditService() )->award( $this->customer, $this->course, 1.0 );

		$this->adapter->revoke_order( $order->get_id() );

		$this->assertNotNull( \Anchor\Courses\Database\CreditRepository::find( $this->customer, $this->course ) );
		$this->assertGreaterThan( 0, $credit->id );
	}

	/**
	 * A refund against an order whose grant was blocked (missing
	 * prerequisite) never granted anything: no role to take back, and no
	 * misleading "access revoked" note either.
	 */
	public function test_a_refund_on_an_order_that_never_granted_is_a_silent_no_op() {
		add_role( 'anchor_course_998877_completed', 'Completed: Required First', [] );
		update_post_meta( $this->course, '_anchor_course_prerequisites', [ 'anchor_course_998877_completed' ] );

		$order = wc_create_order( [ 'customer_id' => $this->customer ] );
		$order->add_product( wc_get_product( $this->product ), 1 );
		$order->set_status( 'processing' );
		$order->save();

		$this->assertFalse( Roles::user_has( $this->customer, Roles::access_slug( $this->course ) ) );
		// A note was left by the blocked GRANT (blocked_prerequisite), not by any revoke.
		$this->assertCount( 0, $this->revoke_notes( $order->get_id() ) );

		$this->assertSame( 0, $this->adapter->revoke_order( $order->get_id() ) );

		$this->assertCount( 0, $this->revoke_notes( $order->get_id() ), 'A revoke that took nothing back must not add its own note.' );

		remove_role( 'anchor_course_998877_completed' );
	}

	/**
	 * Only the line refunded in full loses its course; the other, untouched
	 * line's course survives (progress.md T40 ruling 3).
	 */
	public function test_a_partial_refund_revokes_only_the_fully_refunded_lines_course() {
		$second_course  = $this->make_course( [], 'Second Paid Course' );
		$second_product = $this->factory->post->create( [ 'post_type' => 'product', 'post_status' => 'publish' ] );
		WooCommerce::set_courses_for_product( $second_product, [ $second_course ] );

		$order = wc_create_order( [ 'customer_id' => $this->customer ] );
		$order->add_product( wc_get_product( $this->product ), 1 );
		$order->add_product( wc_get_product( $second_product ), 1 );
		$order->set_status( 'processing' );
		$order->save();
		$this->adapter->enroll_order( $order->get_id() );

		$this->assertTrue( Roles::user_has( $this->customer, Roles::access_slug( $this->course ) ) );
		$this->assertTrue( Roles::user_has( $this->customer, Roles::access_slug( $second_course ) ) );

		$refunded_item_id = 0;
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( (int) $item->get_product_id() === $this->product ) {
				$refunded_item_id = $item_id;
				break;
			}
		}
		$this->assertGreaterThan( 0, $refunded_item_id );

		$line_total = (float) $order->get_item_total( $order->get_item( $refunded_item_id ), false );

		$refund = wc_create_refund(
			[
				'order_id'   => $order->get_id(),
				'amount'     => $line_total,
				'line_items' => [
					$refunded_item_id => [
						'qty'          => 1,
						'refund_total' => $line_total,
					],
				],
			]
		);
		$this->assertNotWPError( $refund );

		$this->adapter->on_order_refunded( $order->get_id(), $refund->get_id() );

		$this->assertFalse( Roles::user_has( $this->customer, Roles::access_slug( $this->course ) ), 'The fully-refunded line loses its course.' );
		$this->assertTrue( Roles::user_has( $this->customer, Roles::access_slug( $second_course ) ), 'The untouched line keeps its course.' );

		remove_role( Roles::access_slug( $second_course ) );
	}

	/**
	 * The order-level marker: a revoke must not be silently undone by a
	 * duplicate/stray re-fire of a qualifying status on the same order.
	 */
	public function test_after_a_revoke_a_stray_qualifying_refire_does_not_regrant() {
		$order = $this->paid_order();

		$this->adapter->revoke_order( $order->get_id() );
		$this->assertFalse( Roles::user_has( $this->customer, Roles::access_slug( $this->course ) ) );

		// A stray duplicate fire of a qualifying status on the same order.
		$this->adapter->on_order_status_changed( $order->get_id(), 'refunded', 'completed', $order );

		$this->assertFalse(
			Roles::user_has( $this->customer, Roles::access_slug( $this->course ) ),
			'The revoked marker must block a re-grant until a human clears it.'
		);
		$this->assertNull(
			\Anchor\Courses\Support\Roles::grant_record( $this->customer, $this->course )['source'] ?? null
		);
	}

	public function test_clearing_the_revoked_marker_allows_a_regrant() {
		$order = $this->paid_order();
		$this->adapter->revoke_order( $order->get_id() );

		$this->assertSame( 0, $this->adapter->enroll_order( $order->get_id() ), 'Still marked: enroll_order() must refuse.' );

		$this->assertTrue( WooCommerce::clear_revoked_marker( $order->get_id() ) );

		$this->assertSame( 1, $this->adapter->enroll_order( $order->get_id() ) );
		$this->assertTrue( Roles::user_has( $this->customer, Roles::access_slug( $this->course ) ) );
	}

	/** Wiring: the refund listener runs on the real module singleton, not only an ad hoc `new WooCommerce()`. */
	public function test_wiring_the_refund_listener() {
		$module = $this->courses();
		$this->assertNotNull( $module->woocommerce );

		$this->assertNotFalse(
			has_action( 'woocommerce_order_refunded', [ $module->woocommerce, 'on_order_refunded' ] )
		);
	}
}
