<?php
/**
 * Pure unit test: the completion percentage formula (brief section 10).
 *
 * @package Anchor\Courses\Tests\Unit
 */

use Anchor\Courses\Services\ProgressService;
use PHPUnit\Framework\TestCase;

/** @group courses-unit */
class Test_Courses_Unit_Progress_Math extends TestCase {

	public function test_percent_is_completed_over_total_times_one_hundred() {
		$this->assertSame( 0.0, ProgressService::percent( 0, 4 ) );
		$this->assertSame( 25.0, ProgressService::percent( 1, 4 ) );
		$this->assertSame( 100.0, ProgressService::percent( 4, 4 ) );
	}

	public function test_percent_rounds_to_two_decimals() {
		$this->assertSame( 33.33, ProgressService::percent( 1, 3 ) );
		$this->assertSame( 66.67, ProgressService::percent( 2, 3 ) );
	}

	/** A course with no required items is 100% by definition, never a divide by zero. */
	public function test_zero_required_items_is_one_hundred_percent() {
		$this->assertSame( 100.0, ProgressService::percent( 0, 0 ) );
	}

	public function test_percent_never_exceeds_one_hundred_or_drops_below_zero() {
		$this->assertSame( 100.0, ProgressService::percent( 9, 4 ) );
		$this->assertSame( 0.0, ProgressService::percent( -3, 4 ) );
	}
}
