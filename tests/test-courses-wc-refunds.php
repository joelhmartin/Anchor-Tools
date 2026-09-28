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
	 * PR36 bot review finding b: enroll_order() persists a line's live-
	 * resolved courses as its snapshot the first time it grants against a
	 * line that lacks one (every fixture in this suite is built by
	 * wc_create_order()/add_product(), not through checkout, so none of
	 * them has a snapshot until paid_order()'s own enroll_order() call
	 * writes one). Removing the mapping afterwards must not make the
	 * already-granted course un-revocable - the refund still reads the
	 * snapshot, not the now-empty live mapping.
	 */
	public function test_removing_the_mapping_after_the_grant_still_revokes_the_snapshotted_course() {
		$order = $this->paid_order();
		$this->assertTrue( Roles::user_has( $this->customer, Roles::access_slug( $this->course ) ) );

		WooCommerce::set_courses_for_product( $this->product, [] );
		$this->assertSame( [], WooCommerce::courses_for_product( $this->product ) );

		$this->assertSame(
			1,
			$this->adapter->revoke_order( $order->get_id() ),
			"The line's own snapshot must still be revocable after the mapping is removed."
		);
		$this->assertFalse( Roles::user_has( $this->customer, Roles::access_slug( $this->course ) ) );
	}

	/* ---------------------------------------------------------------------
	 * PR36 bot review finding a - a `refunded` STATUS TRANSITION must not
	 * revoke a line that was never actually refunded.
	 * ------------------------------------------------------------------- */

	/**
	 * A shop manager (or a gateway) can set an order straight to `refunded`
	 * by hand after only a partial refund. The old code sent every
	 * REVOKE_STATUSES transition, `refunded` included, through
	 * revoke_order() - which revokes every mapped line unconditionally and
	 * ignores refund quantities entirely. The fix routes `refunded` through
	 * the same per-line revoke_refunded_lines() path a real
	 * woocommerce_order_refunded fire uses, so only the line actually
	 * refunded in full loses its course.
	 */
	public function test_a_refunded_status_transition_after_a_partial_refund_revokes_only_that_line() {
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
		$refund     = wc_create_refund(
			[
				'order_id'   => $order->get_id(),
				'amount'     => $line_total,
				'line_items' => [ $refunded_item_id => [ 'qty' => 1, 'refund_total' => $line_total ] ],
			]
		);
		$this->assertNotWPError( $refund );

		// The shop manager (or a gateway) then flips the order's STATUS to
		// `refunded` by hand - a real status transition, not a second
		// refund - after only that one line was actually refunded.
		$this->adapter->on_order_status_changed( $order->get_id(), 'processing', 'refunded', $order );

		$this->assertFalse( Roles::user_has( $this->customer, Roles::access_slug( $this->course ) ), 'The line that was actually refunded in full loses its course.' );
		$this->assertTrue( Roles::user_has( $this->customer, Roles::access_slug( $second_course ) ), 'The untouched line must survive a `refunded` STATUS transition, not just a refund object.' );

		remove_role( Roles::access_slug( $second_course ) );
	}

	/**
	 * When the order carries NO refund records at all - `refunded` set by
	 * hand with no wc_create_refund() behind it - there is no per-line
	 * quantity to read, so every mapped line is treated as fully refunded
	 * (progress.md/PR36 ruling a): the order's own status is the only fact
	 * available.
	 */
	public function test_a_refunded_status_with_no_refund_records_revokes_every_mapped_line() {
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
		$this->assertSame( [], $order->get_refunds(), 'This order must carry no refund records for the test to prove the no-records fallback.' );

		$this->adapter->on_order_status_changed( $order->get_id(), 'processing', 'refunded', $order );

		$this->assertFalse( Roles::user_has( $this->customer, Roles::access_slug( $this->course ) ) );
		$this->assertFalse( Roles::user_has( $this->customer, Roles::access_slug( $second_course ) ), 'With no refund records at all, every mapped line is treated as fully refunded.' );

		remove_role( Roles::access_slug( $second_course ) );
	}

	/* ---------------------------------------------------------------------
	 * PR36 round 2 (Codex + CodeRabbit) - `wc_create_refund()` accepts a
	 * bare `amount` with no `line_items`, which leaves
	 * get_qty_refunded_for_item() at 0 for every line - the quantity check
	 * alone cannot see this refund at all.
	 * ------------------------------------------------------------------- */

	/**
	 * A FULL amount-only refund - the cumulative refunded total already
	 * covers the order's total, which is also what makes WooCommerce itself
	 * flip the order to `refunded` - revokes every mapped line, the same as
	 * the existing no-refund-records fallback.
	 */
	public function test_an_amount_only_full_refund_revokes_every_mapped_line() {
		$second_course  = $this->make_course( [], 'Second Paid Course' );
		$second_product = $this->factory->post->create( [ 'post_type' => 'product', 'post_status' => 'publish' ] );
		WooCommerce::set_courses_for_product( $second_product, [ $second_course ] );

		$order = wc_create_order( [ 'customer_id' => $this->customer ] );
		// These fixtures' products carry no price by default; an explicit
		// total is what gives this order (and the refund below) a real,
		// non-zero amount to work with.
		$order->add_product( wc_get_product( $this->product ), 1, [ 'subtotal' => 20, 'total' => 20 ] );
		$order->add_product( wc_get_product( $second_product ), 1, [ 'subtotal' => 15, 'total' => 15 ] );
		$order->calculate_totals();
		$order->set_status( 'processing' );
		$order->save();
		$this->adapter->enroll_order( $order->get_id() );

		$this->assertTrue( Roles::user_has( $this->customer, Roles::access_slug( $this->course ) ) );
		$this->assertTrue( Roles::user_has( $this->customer, Roles::access_slug( $second_course ) ) );

		$order_total = (float) $order->get_total();
		$this->assertGreaterThan( 0, $order_total );

		// No `line_items` at all - the shape CodeRabbit/Codex flagged.
		$refund = wc_create_refund( [ 'order_id' => $order->get_id(), 'amount' => $order_total ] );
		$this->assertNotWPError( $refund );

		$this->adapter->on_order_refunded( $order->get_id(), $refund->get_id() );

		$this->assertFalse( Roles::user_has( $this->customer, Roles::access_slug( $this->course ) ) );
		$this->assertFalse( Roles::user_has( $this->customer, Roles::access_slug( $second_course ) ) );
		$this->assertCount( 1, $this->revoke_notes( $order->get_id() ), 'One note for the whole revoke pass, not one per course.' );

		remove_role( Roles::access_slug( $second_course ) );
	}

	/**
	 * A PARTIAL amount-only refund cannot be attributed to any one line -
	 * there is no per-line quantity to read, and the cumulative refunded
	 * amount is still below the order total, so treating it as a full
	 * refund (the test above) would be wrong too. The documented, safe
	 * behaviour (COURSES.md) is to revoke nothing until either a real
	 * line-item refund arrives or the cumulative amount reaches the order's
	 * total.
	 */
	public function test_an_amount_only_partial_refund_revokes_nothing() {
		$order = wc_create_order( [ 'customer_id' => $this->customer ] );
		$order->add_product( wc_get_product( $this->product ), 1, [ 'subtotal' => 20, 'total' => 20 ] );
		$order->calculate_totals();
		$order->set_status( 'processing' );
		$order->save();
		$this->adapter->enroll_order( $order->get_id() );
		$this->assertTrue( Roles::user_has( $this->customer, Roles::access_slug( $this->course ) ) );

		$order_total = (float) $order->get_total();
		$this->assertGreaterThan( 0, $order_total );

		$refund = wc_create_refund( [ 'order_id' => $order->get_id(), 'amount' => \round( $order_total / 2, 2 ) ] );
		$this->assertNotWPError( $refund );

		$this->adapter->on_order_refunded( $order->get_id(), $refund->get_id() );

		$this->assertTrue(
			Roles::user_has( $this->customer, Roles::access_slug( $this->course ) ),
			'A partial amount-only refund must not be attributed to any line.'
		);
		$this->assertCount( 0, $this->revoke_notes( $order->get_id() ) );
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

	/* ---------------------------------------------------------------------
	 * PR36 closing pass - revocation is per LINE (or per course-within-
	 * order), not per order: the order-level-only marker used to (a) block
	 * an entire order's retry-on-publish just because a DIFFERENT line was
	 * refunded, and (b) let refunding either of two lines that both grant
	 * the same course strip a role the other line still paid for.
	 * ------------------------------------------------------------------- */

	/**
	 * Defect a: course A (published) and course B (still draft) are on two
	 * different lines of the same order. B is blocked at purchase (no_course)
	 * and stays ungranted. Refunding A's line in full must not stop B's own,
	 * untouched line from being retried once B publishes - the old
	 * order-level-only marker refused the WHOLE order, so B never arrived.
	 */
	public function test_refunding_one_line_does_not_block_retry_on_publish_for_a_different_lines_draft_course() {
		$draft_course  = $this->factory->post->create(
			[ 'post_type' => CoursePostType::CPT, 'post_status' => 'draft', 'post_title' => 'Still Draft' ]
		);
		$draft_product = $this->factory->post->create( [ 'post_type' => 'product', 'post_status' => 'publish' ] );
		WooCommerce::set_courses_for_product( $draft_product, [ $draft_course ] );

		$order = wc_create_order( [ 'customer_id' => $this->customer ] );
		$order->add_product( wc_get_product( $this->product ), 1 );
		$order->add_product( wc_get_product( $draft_product ), 1 );
		$order->set_status( 'processing' );
		$order->save();
		$this->adapter->enroll_order( $order->get_id() );

		$this->assertTrue( Roles::user_has( $this->customer, Roles::access_slug( $this->course ) ) );
		$this->assertFalse( Roles::user_has( $this->customer, Roles::access_slug( $draft_course ) ), 'B is still draft: blocked, not granted.' );

		$refunded_item_id = 0;
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( (int) $item->get_product_id() === $this->product ) {
				$refunded_item_id = $item_id;
				break;
			}
		}
		$this->assertGreaterThan( 0, $refunded_item_id );

		$line_total = (float) $order->get_item_total( $order->get_item( $refunded_item_id ), false );
		$refund     = wc_create_refund(
			[
				'order_id'   => $order->get_id(),
				'amount'     => $line_total,
				'line_items' => [ $refunded_item_id => [ 'qty' => 1, 'refund_total' => $line_total ] ],
			]
		);
		$this->assertNotWPError( $refund );

		$this->adapter->on_order_refunded( $order->get_id(), $refund->get_id() );
		$this->assertFalse( Roles::user_has( $this->customer, Roles::access_slug( $this->course ) ), 'A is revoked.' );

		// Publishing B must retry-grant it through THIS SAME order - the
		// line that was never refunded - even though a different line on
		// the same order was just revoked.
		wp_publish_post( $draft_course );

		$this->assertTrue( Roles::user_has( $this->customer, Roles::access_slug( $draft_course ) ), 'B is retried and granted once published.' );
		$this->assertFalse( Roles::user_has( $this->customer, Roles::access_slug( $this->course ) ), 'A stays revoked.' );

		remove_role( Roles::access_slug( $draft_course ) );
	}

	/**
	 * Defect b: two lines on the same order both grant the SAME course.
	 * Fully refunding one line must not strip the role while the other
	 * line still pays for it; only once BOTH lines are fully refunded does
	 * the course actually go.
	 */
	public function test_two_lines_granting_the_same_course_only_revoke_once_both_are_fully_refunded() {
		$order = wc_create_order( [ 'customer_id' => $this->customer ] );
		$order->add_product( wc_get_product( $this->product ), 1 );
		$order->add_product( wc_get_product( $this->product ), 1 );
		$order->set_status( 'processing' );
		$order->save();
		$this->adapter->enroll_order( $order->get_id() );

		$this->assertTrue( Roles::user_has( $this->customer, Roles::access_slug( $this->course ) ) );

		$item_ids = array_keys( $order->get_items() );
		$this->assertCount( 2, $item_ids, 'Two separate lines for the same product.' );
		[ $first_item_id, $second_item_id ] = $item_ids;

		$first_total = (float) $order->get_item_total( $order->get_item( $first_item_id ), false );
		$refund_one  = wc_create_refund(
			[
				'order_id'   => $order->get_id(),
				'amount'     => $first_total,
				'line_items' => [ $first_item_id => [ 'qty' => 1, 'refund_total' => $first_total ] ],
			]
		);
		$this->assertNotWPError( $refund_one );
		$this->adapter->on_order_refunded( $order->get_id(), $refund_one->get_id() );

		$this->assertTrue(
			Roles::user_has( $this->customer, Roles::access_slug( $this->course ) ),
			'The second, un-refunded line still grants the course - the role must stay.'
		);

		$second_total = (float) $order->get_item_total( $order->get_item( $second_item_id ), false );
		$refund_two   = wc_create_refund(
			[
				'order_id'   => $order->get_id(),
				'amount'     => $second_total,
				'line_items' => [ $second_item_id => [ 'qty' => 1, 'refund_total' => $second_total ] ],
			]
		);
		$this->assertNotWPError( $refund_two );
		$this->adapter->on_order_refunded( $order->get_id(), $refund_two->get_id() );

		$this->assertFalse(
			Roles::user_has( $this->customer, Roles::access_slug( $this->course ) ),
			'Once BOTH lines are fully refunded, the course is finally revoked.'
		);
	}

	/**
	 * clear_revoked_marker() must clear the PER-LINE state on every line,
	 * not only the (legacy) order-level marker - otherwise a cleared order
	 * still refuses every line, one at a time, forever.
	 */
	public function test_clearing_the_revoked_marker_clears_the_per_line_state_too() {
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

		// Cancelled revokes every line at once, marking each one.
		$this->adapter->on_order_status_changed( $order->get_id(), 'processing', 'cancelled', $order );

		$this->assertFalse( Roles::user_has( $this->customer, Roles::access_slug( $this->course ) ) );
		$this->assertFalse( Roles::user_has( $this->customer, Roles::access_slug( $second_course ) ) );

		$this->assertSame( 0, $this->adapter->enroll_order( $order->get_id() ), 'Still marked on every line: enroll_order() must refuse.' );

		$this->assertTrue( WooCommerce::clear_revoked_marker( $order->get_id() ) );

		$this->assertSame( 2, $this->adapter->enroll_order( $order->get_id() ), 'Both lines regrant once every marker - order AND per-line - is cleared.' );
		$this->assertTrue( Roles::user_has( $this->customer, Roles::access_slug( $this->course ) ) );
		$this->assertTrue( Roles::user_has( $this->customer, Roles::access_slug( $second_course ) ) );

		remove_role( Roles::access_slug( $second_course ) );
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
