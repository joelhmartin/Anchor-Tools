<?php
/**
 * Pure unit test: certificate numbering (brief 13, design spec 4).
 *
 * @package Anchor\Courses\Tests\Unit
 */

use Anchor\Courses\Services\CertificateService;
use PHPUnit\Framework\TestCase;

/** @group courses-unit */
class Test_Courses_Unit_Certificate_Number extends TestCase {

	public function test_the_documented_format() {
		$this->assertSame( 'AC-2026-00000124', CertificateService::format_number( 124, 2026 ) );
	}

	public function test_the_id_is_zero_padded_to_eight_digits() {
		$this->assertSame( 'AC-2026-00000001', CertificateService::format_number( 1, 2026 ) );
		$this->assertSame( 'AC-2026-12345678', CertificateService::format_number( 12345678, 2026 ) );
	}

	public function test_an_id_longer_than_eight_digits_is_not_truncated() {
		$this->assertSame( 'AC-2026-123456789', CertificateService::format_number( 123456789, 2026 ) );
	}

	public function test_the_year_comes_from_the_caller_not_the_clock() {
		$this->assertSame( 'AC-2031-00000007', CertificateService::format_number( 7, 2031 ) );
	}
}
