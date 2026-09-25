<?php
/**
 * Anchor Courses - module boot and PSR-4 autoloading.
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Module;
use Anchor\Courses\Support\Clock;
use Anchor\Courses\Support\Uuid;

/** @group courses */
class Test_Courses_Bootstrap extends Anchor_Courses_TestCase {

	public function test_module_boots() {
		$this->assertInstanceOf(
			Module::class,
			Module::instance(),
			'The courses module did not bootstrap - check tests/bootstrap.php enables "courses".'
		);
	}

	public function test_psr4_autoloading_resolves_support_classes() {
		$this->assertTrue( class_exists( Uuid::class ), 'PSR-4 autoload for Anchor\\Courses\\ is not registered - run composer dump-autoload.' );
		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
			Uuid::v4()
		);
		$this->assertNotSame( Uuid::v4(), Uuid::v4(), 'UUIDs must not repeat.' );
	}

	public function test_clock_is_filterable_so_tests_can_freeze_time() {
		add_filter( 'anchor_courses_now', static fn() => 1767225600 ); // 2026-01-01 00:00:00 UTC
		$this->assertSame( 1767225600, Clock::timestamp() );
		$this->assertSame( '2026-01-01 00:00:00', Clock::now() );
		remove_all_filters( 'anchor_courses_now' );
	}

	public function test_module_is_registered_in_the_plugin_registry() {
		$modules = anchor_tools_get_available_modules();
		$this->assertArrayHasKey( 'courses', $modules );
		$this->assertSame( '\\Anchor\\Courses\\Module', $modules['courses']['class'] );
		$this->assertFileExists( $modules['courses']['path'] );
	}
}
