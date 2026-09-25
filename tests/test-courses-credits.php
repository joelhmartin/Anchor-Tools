<?php
/**
 * Anchor Courses - CE credits (brief 12, 26).
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Domain\Credit;
use Anchor\Courses\Services\CreditService;

/** @group courses */
class Test_Courses_Credits extends Anchor_Courses_TestCase {

	private CreditService $credits;
	private int $user;
	private int $course;

	public function set_up() {
		parent::set_up();
		$this->credits = new CreditService();
		$this->user    = $this->make_learner();
		$this->course  = $this->make_course(
			[ 'ce_credits' => '2', 'ce_type' => 'Dental CE', 'ce_provider_name' => 'DEKA Academy',
			  'ce_provider_number' => 'AGD-1234', 'ce_expires_days' => '365' ]
		);
	}

	public function tear_down() {
		remove_all_actions( 'anchor_courses_ce_credit_awarded' );
		remove_all_filters( 'anchor_courses_ce_credit_amount' );
		remove_all_filters( 'anchor_courses_now' );
		parent::tear_down();
	}

	public function test_award_creates_a_record_and_fires_the_action() {
		$fired = 0;
		add_action( 'anchor_courses_ce_credit_awarded', function () use ( &$fired ) { $fired++; }, 10, 3 );

		$credit = $this->credits->award( $this->user, $this->course );

		$this->assertInstanceOf( Credit::class, $credit );
		$this->assertSame( 2.0, $credit->credits );
		$this->assertSame( 'Dental CE', $credit->credit_type );
		$this->assertSame( 'DEKA Academy', $credit->metadata['provider_name'] );
		$this->assertSame( 'AGD-1234', $credit->metadata['provider_number'] );
		$this->assertSame( 1, $fired );
	}

	/** Brief rule 7 / 26: the whole point of this table's unique key. */
	public function test_awarding_twice_does_not_duplicate_credits() {
		$fired = 0;
		add_action( 'anchor_courses_ce_credit_awarded', function () use ( &$fired ) { $fired++; }, 10, 3 );

		$first  = $this->credits->award( $this->user, $this->course );
		$second = $this->credits->award( $this->user, $this->course );

		$this->assertSame( $first->id, $second->id );
		$this->assertSame( 1, $fired );
		$this->assertSame( 2.0, $this->credits->total_for_user( $this->user ) );
	}

	public function test_a_course_with_no_credits_awards_nothing() {
		$free = $this->make_course( [ 'ce_credits' => '0' ] );
		$this->assertNull( $this->credits->award( $this->user, $free ) );
	}

	public function test_an_explicit_amount_overrides_the_course_setting() {
		$credit = $this->credits->award( $this->user, $this->course, 5.5 );
		$this->assertSame( 5.5, $credit->credits );
	}

	public function test_the_amount_filter_can_adjust_the_award() {
		add_filter( 'anchor_courses_ce_credit_amount', static fn( $credits ) => $credits * 2, 10, 3 );
		$this->assertSame( 4.0, $this->credits->award( $this->user, $this->course )->credits );
	}

	public function test_expiry_is_computed_from_ce_expires_days() {
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-01-01 00:00:00 UTC' ) );
		$credit = $this->credits->award( $this->user, $this->course );
		$this->assertSame( '2027-01-01 00:00:00', $credit->expires_at );
		$this->assertFalse( $credit->is_expired( strtotime( '2026-06-01 UTC' ) ) );
		$this->assertTrue( $credit->is_expired( strtotime( '2027-06-01 UTC' ) ) );
	}

	public function test_zero_expires_days_means_never_expires() {
		$forever = $this->make_course( [ 'ce_credits' => '1', 'ce_expires_days' => '0' ] );
		$credit  = $this->credits->award( $this->user, $forever );
		$this->assertNull( $credit->expires_at );
		$this->assertFalse( $credit->is_expired( strtotime( '2099-01-01 UTC' ) ) );
	}

	public function test_totals_and_listing_per_user() {
		$second = $this->make_course( [ 'ce_credits' => '3' ] );
		$this->credits->award( $this->user, $this->course );
		$this->credits->award( $this->user, $second );

		$this->assertCount( 2, $this->credits->for_user( $this->user ) );
		$this->assertSame( 5.0, $this->credits->total_for_user( $this->user ) );
		$this->assertSame( 0.0, $this->credits->total_for_user( $this->make_learner() ) );
	}

	public function test_the_public_api_function_awards_an_explicit_amount() {
		$credit = anchor_courses_award_ce_credit( $this->user, $this->course, 1.5 );
		$this->assertInstanceOf( Credit::class, $credit );
		$this->assertSame( 1.5, $credit->credits );
	}

	public function test_award_refuses_a_non_course() {
		$this->assertNull( $this->credits->award( $this->user, $this->make_lesson(), 1.0 ) );
	}

	/** Module::__construct() wires the service instance Task 29 will call. */
	public function test_the_module_wires_up_a_credit_service() {
		$this->assertInstanceOf( CreditService::class, $this->courses()->credits );
	}
}
