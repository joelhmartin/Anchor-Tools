<?php
/**
 * Pure unit test: Clock's datetime maths, with no WordPress booted.
 *
 * @package Anchor\Courses\Tests\Unit
 */

use Anchor\Courses\Support\Clock;
use PHPUnit\Framework\TestCase;

/** @group courses-unit */
class Test_Courses_Unit_Clock extends TestCase {

	public function test_offset_adds_seconds_to_now() {
		$GLOBALS['anchor_courses_unit_now'] = 1767225600; // 2026-01-01 00:00:00 UTC
		$this->assertSame( '2026-01-01 00:00:00', Clock::now() );
		$this->assertSame( '2026-01-01 00:01:00', Clock::offset( 60 ) );
		$this->assertSame( '2025-12-31 23:59:00', Clock::offset( -60 ) );
	}

	public function test_to_timestamp_round_trips_and_treats_empty_as_zero() {
		$this->assertSame( 1767225600, Clock::to_timestamp( '2026-01-01 00:00:00' ) );
		$this->assertSame( 0, Clock::to_timestamp( '' ) );
		$this->assertSame( 0, Clock::to_timestamp( null ) );
		$this->assertSame( 0, Clock::to_timestamp( '0000-00-00 00:00:00' ) );
	}

	/** A nullable DATETIME read: null, '' and the legacy zero-date all mean unset. */
	public function test_nullable_maps_unset_forms_to_null() {
		$this->assertNull( Clock::nullable( null ) );
		$this->assertNull( Clock::nullable( '' ) );
		$this->assertNull( Clock::nullable( Clock::ZERO_DATE ) );
		$this->assertSame( '2026-01-01 00:00:00', Clock::nullable( '2026-01-01 00:00:00' ) );
	}
}
