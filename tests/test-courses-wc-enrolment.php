<?php
/**
 * Anchor Courses - WooCommerce order-driven enrolment (Task 39, brief 18, design spec 4).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Content\CoursePostType;
use Anchor\Courses\Integrations\WooCommerce;
use Anchor\Courses\Module;
use Anchor\Courses\Services\EnrollmentService;
use Anchor\Courses\Support\Roles;

/** @group courses @group woocommerce */
class Test_Courses_Wc_Enrolment extends Anchor_Courses_TestCase {

	private EnrollmentService $enrollments;
	private int $customer;
	private int $course;
	private int $product;

	public function set_up() {
		parent::set_up();
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is not active in this run.' );
		}

		$this->enrollments = new EnrollmentService();
		$this->customer    = $this->make_learner( [ 'role' => 'customer' ] );
		$this->course      = $this->make_course( [], 'Paid Course' );
		$this->product     = $this->factory->post->create( [ 'post_type' => 'product', 'post_status' => 'publish' ] );

		WooCommerce::set_courses_for_product( $this->product, [ $this->course ] );
	}

	public function tear_down() {
		remove_all_filters( 'anchor_courses_wc_enroll_statuses' );
		remove_role( Roles::access_slug( $this->course ) );
		parent::tear_down();
	}

	/** @return \WC_Order */
	private function make_order( string $status = 'pending' ) {
		$order = wc_create_order( [ 'customer_id' => $this->customer ] );
		$order->add_product( wc_get_product( $this->product ), 1 );
		$order->set_status( $status );
		$order->save();
		return $order;
	}

	public function test_the_default_qualifying_statuses() {
		$this->assertSame( [ 'processing', 'completed' ], WooCommerce::qualifying_statuses() );
	}

	public function test_the_statuses_filter_is_respected() {
		add_filter( 'anchor_courses_wc_enroll_statuses', static fn() => [ 'completed' ] );
		$this->assertSame( [ 'completed' ], WooCommerce::qualifying_statuses() );
	}

	/**
	 * The adapter grants the ROLE; the listener writes the row. Asserting on
	 * the row is what proves the two halves are still joined up - and that
	 * this adapter never calls EnrollmentService::enroll() directly.
	 */
	public function test_processing_grants_the_role_and_the_listener_records_the_order() {
		$order = $this->make_order( 'pending' );

		( new WooCommerce() )->on_order_status_changed( $order->get_id(), 'pending', 'processing', $order );

		$this->assertTrue( Roles::user_has( $this->customer, Roles::access_slug( $this->course ) ) );

		$enrollment = $this->enrollments->get( $this->customer, $this->course );
		$this->assertNotNull( $enrollment, 'The role listener must have created the enrolment row.' );
		$this->assertSame( 'woocommerce', $enrollment->source );
		$this->assertSame( (string) $order->get_id(), $enrollment->source_id );
	}

	public function test_completed_also_grants() {
		$order = $this->make_order( 'pending' );

		( new WooCommerce() )->on_order_status_changed( $order->get_id(), 'pending', 'completed', $order );

		$this->assertTrue( $this->enrollments->is_enrolled( $this->customer, $this->course ) );
	}

	/** An unmet prerequisite refuses the grant and says so on the order. */
	public function test_an_unmet_prerequisite_blocks_the_grant_and_notes_the_order() {
		add_role( 'anchor_course_555_completed', 'Completed: Required First', [] );
		update_post_meta( $this->course, '_anchor_course_prerequisites', [ 'anchor_course_555_completed' ] );

		// A real status transition, so the module's own live listener does the
		// work end to end (it is already wired - see the wiring test below).
		$order = $this->make_order( 'pending' );
		$order->set_status( 'processing' );
		$order->save();

		$this->assertFalse( Roles::user_has( $this->customer, Roles::access_slug( $this->course ) ) );
		$this->assertNull( $this->enrollments->get( $this->customer, $this->course ) );

		$notes = wc_get_order_notes( [ 'order_id' => $order->get_id() ] );
		$this->assertNotEmpty( $notes );
		$this->assertStringContainsString( 'blocked_prerequisite', $notes[0]->content );

		// The order itself is untouched: refunding is a human decision.
		$this->assertSame( 'processing', wc_get_order( $order->get_id() )->get_status() );

		remove_role( 'anchor_course_555_completed' );
	}

	/**
	 * A draft/private course's mapping is allowed to exist (Task 38), so a
	 * buyer can pay before the course is published. grant_access() refuses
	 * with `no_course`; that refusal gets the same treatment as a missing
	 * prerequisite - logged and noted, not silently dropped (progress.md T39
	 * ruling 1) - and publishing the course later grants it retroactively
	 * (progress.md T39 ruling 2).
	 */
	public function test_a_draft_course_blocks_the_grant_and_notes_the_order_then_publishing_retries_it() {
		$draft = self::factory()->post->create(
			[ 'post_type' => CoursePostType::CPT, 'post_status' => 'draft', 'post_title' => 'Unpublished Course' ]
		);
		WooCommerce::set_courses_for_product( $this->product, [ $draft ] );

		// A real status transition, so retry_orders_for_course()'s own bounded
		// order query (which reads the real DB status) can find this order.
		$order = $this->make_order( 'pending' );
		$order->set_status( 'processing' );
		$order->save();

		$this->assertFalse( Roles::user_has( $this->customer, Roles::access_slug( $draft ) ) );
		$this->assertNull( $this->enrollments->get( $this->customer, $draft ) );

		$notes = wc_get_order_notes( [ 'order_id' => $order->get_id() ] );
		$this->assertNotEmpty( $notes );
		$this->assertStringContainsString( 'blocked_no_course', $notes[0]->content );

		// Publishing the course re-runs the grant against the already-paid order.
		wp_publish_post( $draft );

		$this->assertTrue( Roles::user_has( $this->customer, Roles::access_slug( $draft ) ) );
		$enrollment = $this->enrollments->get( $this->customer, $draft );
		$this->assertNotNull( $enrollment );
		$this->assertSame( 'woocommerce', $enrollment->source );
		$this->assertSame( (string) $order->get_id(), $enrollment->source_id );

		remove_role( Roles::access_slug( $draft ) );
	}

	/**
	 * The retry-on-publish listener is wired on the real module singleton, not
	 * only on an ad hoc `new WooCommerce()` used elsewhere in this file.
	 */
	public function test_wiring_the_status_and_publish_listeners() {
		$module = $this->courses();
		$this->assertNotNull( $module->woocommerce, 'The WooCommerce adapter must be constructed when WooCommerce is active.' );

		$this->assertNotFalse(
			has_action( 'woocommerce_order_status_changed', [ $module->woocommerce, 'on_order_status_changed' ] )
		);
		$this->assertNotFalse(
			has_action( 'transition_post_status', [ $module->woocommerce, 'on_course_published' ] )
		);
	}

	public function test_a_non_qualifying_status_enrols_nobody() {
		$order = $this->make_order( 'pending' );

		( new WooCommerce() )->on_order_status_changed( $order->get_id(), 'pending', 'on-hold', $order );

		$this->assertFalse( $this->enrollments->is_enrolled( $this->customer, $this->course ) );
	}

	/** Brief 26: processing then completed must not enrol twice. */
	public function test_enrolment_is_idempotent_across_status_changes() {
		$order   = $this->make_order( 'pending' );
		$adapter = new WooCommerce();

		$first  = $adapter->enroll_order( $order->get_id() );
		$order->set_status( 'completed' );
		$order->save();
		$second = $adapter->enroll_order( $order->get_id() );

		$this->assertSame( 1, $first );
		$this->assertSame( 0, $second, 'A repeat pass must create nothing new.' );
	}

	/**
	 * WooCommerce is known to fire `woocommerce_order_status_changed` more
	 * than once for the same transition (e.g. a gateway IPN racing the
	 * checkout redirect). The listener itself, not just enroll_order(), must
	 * absorb the duplicate.
	 */
	public function test_a_duplicate_status_changed_callback_grants_only_once() {
		global $wpdb;

		$order   = $this->make_order( 'pending' );
		$adapter = new WooCommerce();

		$adapter->on_order_status_changed( $order->get_id(), 'pending', 'processing', $order );
		$adapter->on_order_status_changed( $order->get_id(), 'pending', 'processing', $order );

		$this->assertTrue( Roles::user_has( $this->customer, Roles::access_slug( $this->course ) ) );

		$table = \Anchor\Courses\Database\Migrations::table( 'enrollments' );
		$rows  = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND course_id = %d",
				$this->customer,
				$this->course
			)
		);
		$this->assertSame( 1, $rows, 'Firing the same transition twice must produce exactly one enrolment row.' );

		// No spurious refusal note from the second, no-op pass.
		$this->assertCount( 0, wc_get_order_notes( [ 'order_id' => $order->get_id() ] ) );
	}

	public function test_an_order_with_no_mapped_products_does_nothing() {
		$plain = $this->factory->post->create( [ 'post_type' => 'product', 'post_status' => 'publish' ] );
		$order = wc_create_order( [ 'customer_id' => $this->customer ] );
		$order->add_product( wc_get_product( $plain ), 1 );
		$order->set_status( 'processing' );
		$order->save();

		$this->assertSame( 0, ( new WooCommerce() )->enroll_order( $order->get_id() ) );
	}

	public function test_a_guest_order_with_no_billing_email_enrols_nobody() {
		$order = wc_create_order();
		$order->add_product( wc_get_product( $this->product ), 1 );
		$order->set_status( 'processing' );
		$order->save();

		$this->assertSame( 0, ( new WooCommerce() )->enroll_order( $order->get_id() ) );
	}

	/**
	 * Guest checkout: no customer_id, but the billing details resolve or
	 * create an account (Support\Accounts::ensure_user(), no mail), the same
	 * shape as the events module's Entitlements::create_account() for a seat
	 * with no attendee data.
	 */
	public function test_a_guest_order_resolves_or_creates_an_account_from_billing_details() {
		$email = 'guest-' . wp_generate_password( 8, false, false ) . '@example.test';

		// The "no welcome email" behaviour of Accounts::ensure_user() itself is
		// covered by tests/test-courses-learners.php; a wp_mail spy here would
		// also catch WooCommerce's own (expected, wanted) order-processing
		// notification, so this test sticks to what Task 39 owns: that a guest
		// order resolves/creates the account and grants through it.
		//
		// A real status transition: the module's own live listener (already
		// proven wired) does the granting here, exactly as it would in
		// production - this is what actually exercises Accounts::ensure_user().
		$order = wc_create_order();
		$order->set_billing_email( $email );
		$order->set_billing_first_name( 'Jane' );
		$order->set_billing_last_name( 'Guest' );
		$order->add_product( wc_get_product( $this->product ), 1 );
		$order->set_status( 'processing' );
		$order->save();

		$user = get_user_by( 'email', $email );
		$this->assertInstanceOf( WP_User::class, $user );
		$this->assertTrue( Roles::user_has( $user->ID, Roles::access_slug( $this->course ) ) );

		$enrollment = $this->enrollments->get( $user->ID, $this->course );
		$this->assertNotNull( $enrollment );
		$this->assertSame( 'woocommerce', $enrollment->source );
		$this->assertSame( (string) $order->get_id(), $enrollment->source_id );
	}

	/** A second guest order from the same email must reuse the same account, not create a second one. */
	public function test_a_second_guest_order_from_the_same_email_reuses_the_account() {
		$email  = 'repeat-guest@example.test';
		$second = $this->make_course( [], 'Second Guest Course' );
		WooCommerce::set_courses_for_product( $this->product, [ $this->course, $second ] );

		$make = function () use ( $email ) {
			$order = wc_create_order();
			$order->set_billing_email( $email );
			$order->add_product( wc_get_product( $this->product ), 1 );
			$order->set_status( 'processing' );
			$order->save();
			return $order;
		};

		$order_a = $make();
		( new WooCommerce() )->enroll_order( $order_a->get_id() );

		$user_a = get_user_by( 'email', $email );
		$this->assertInstanceOf( WP_User::class, $user_a );

		$order_b = $make();
		( new WooCommerce() )->enroll_order( $order_b->get_id() );

		$user_b = get_user_by( 'email', $email );
		$this->assertSame( $user_a->ID, $user_b->ID );

		remove_role( Roles::access_slug( $second ) );
	}

	public function test_one_product_may_grant_several_courses() {
		$second = $this->make_course( [], 'Second' );
		WooCommerce::set_courses_for_product( $this->product, [ $this->course, $second ] );
		$order = $this->make_order( 'pending' );

		$this->assertSame( 2, ( new WooCommerce() )->enroll_order( $order->get_id() ) );
		$this->assertTrue( $this->enrollments->is_enrolled( $this->customer, $second ) );

		remove_role( Roles::access_slug( $second ) );
	}

	/** progress.md T39 ruling 3: the variation's own mapping wins over the parent's. */
	public function test_a_variations_own_mapping_takes_precedence_over_the_parents() {
		$variation_course = $this->make_course( [], 'Variation-Only Course' );

		$parent = new WC_Product_Variable();
		$parent->set_name( 'Variable Product' );
		$parent_id = $parent->save();

		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent_id );
		$variation_id = $variation->save();

		WooCommerce::set_courses_for_product( $parent_id, [ $this->course ] );
		WooCommerce::set_courses_for_product( $variation_id, [ $variation_course ] );

		// Left at 'pending' and granted via a direct enroll_order() call, not a
		// real transition to a qualifying status - this isolates the test from
		// the module's own live order-status listener (already covered by the
		// wiring test), so the return value asserted below is unambiguous.
		$order = wc_create_order( [ 'customer_id' => $this->customer ] );
		$item  = new WC_Order_Item_Product();
		$item->set_product( wc_get_product( $variation_id ) );
		$item->set_quantity( 1 );
		$order->add_item( $item );
		$order->set_status( 'pending' );
		$order->save();

		$granted = ( new WooCommerce() )->enroll_order( $order->get_id() );

		$this->assertSame( 1, $granted );
		$this->assertTrue( $this->enrollments->is_enrolled( $this->customer, $variation_course ) );
		$this->assertFalse( $this->enrollments->is_enrolled( $this->customer, $this->course ) );

		remove_role( Roles::access_slug( $variation_course ) );
	}

	/** progress.md T39 ruling 3: an unmapped variation falls back to the parent's mapping. */
	public function test_an_unmapped_variation_falls_back_to_the_parents_mapping() {
		$parent = new WC_Product_Variable();
		$parent->set_name( 'Variable Product' );
		$parent_id = $parent->save();

		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent_id );
		$variation_id = $variation->save();

		WooCommerce::set_courses_for_product( $parent_id, [ $this->course ] );
		// The variation itself is left unmapped.

		$order = wc_create_order( [ 'customer_id' => $this->customer ] );
		$item  = new WC_Order_Item_Product();
		$item->set_product( wc_get_product( $variation_id ) );
		$item->set_quantity( 1 );
		$order->add_item( $item );
		$order->set_status( 'pending' );
		$order->save();

		$granted = ( new WooCommerce() )->enroll_order( $order->get_id() );

		$this->assertSame( 1, $granted );
		$this->assertTrue( $this->enrollments->is_enrolled( $this->customer, $this->course ) );
	}

	/* ---------------------------------------------------------------------
	 * Phase 5 final review I6 - retry-on-publish selects orders by line item.
	 * ------------------------------------------------------------------- */

	private function draft_course_order( int $customer, string $status = 'processing' ): array {
		$draft = self::factory()->post->create( [ 'post_type' => 'anchor_course', 'post_status' => 'draft', 'post_title' => 'Staged' ] );
		$product = $this->factory->post->create( [ 'post_type' => 'product', 'post_status' => 'publish' ] );
		WooCommerce::set_courses_for_product( $product, [ $draft ] );

		$order = wc_create_order( [ 'customer_id' => $customer ] );
		$order->add_product( wc_get_product( $product ), 1 );
		$order->set_status( $status );
		// Backdated a day: the retry test needs this order to be OLDER than the
		// filler orders, or a newest-first scan would still find it by tie order
		// and the test could not distinguish the two implementations.
		$order->set_date_created( time() - DAY_IN_SECONDS );
		$order->save();

		return [ $draft, $product, $order ];
	}

	/**
	 * The old scan read the store's newest qualifying orders and filtered in
	 * PHP, so a course order older than that window was never retried. It
	 * must be found by its line item however many other orders came after.
	 */
	public function test_retry_on_publish_finds_a_course_order_behind_many_newer_orders() {
		[ $draft, , $order ] = $this->draft_course_order( $this->customer );

		for ( $i = 0; $i < 201; $i++ ) {
			wc_create_order( [ 'status' => 'processing' ] );
		}

		wp_publish_post( $draft );

		$this->assertTrue(
			Roles::user_has( $this->customer, Roles::access_slug( $draft ) ),
			'The course order must be found by its line item, not by being among the newest orders.'
		);
		remove_role( Roles::access_slug( $draft ) );
	}

	public function test_retry_on_publish_skips_a_course_order_in_a_non_qualifying_status() {
		[ $draft ] = $this->draft_course_order( $this->customer, 'pending' );

		wp_publish_post( $draft );

		$this->assertFalse( Roles::user_has( $this->customer, Roles::access_slug( $draft ) ) );
		remove_role( Roles::access_slug( $draft ) );
	}

	public function test_retry_on_publish_is_bounded_to_the_newest_matching_orders() {
		add_filter( 'anchor_courses_wc_retry_order_limit', static fn() => 1 );

		$older = $this->make_learner( [ 'role' => 'customer' ] );
		[ $draft, $product ] = $this->draft_course_order( $older );

		$newer = wc_create_order( [ 'customer_id' => $this->customer ] );
		$newer->add_product( wc_get_product( $product ), 1 );
		$newer->set_status( 'processing' );
		$newer->save();

		$granted = ( new WooCommerce() )->retry_orders_for_course( $draft );
		$this->assertSame( 0, $granted, 'Still a draft: nothing can be granted yet.' );

		wp_update_post( [ 'ID' => $draft, 'post_status' => 'publish' ] );

		$this->assertTrue( Roles::user_has( $this->customer, Roles::access_slug( $draft ) ), 'The newest matching order is inside the bound.' );
		$this->assertFalse( Roles::user_has( $older, Roles::access_slug( $draft ) ), 'The bound applies to matching orders only; the older one is past it.' );

		remove_all_filters( 'anchor_courses_wc_retry_order_limit' );
		remove_role( Roles::access_slug( $draft ) );
	}
}
