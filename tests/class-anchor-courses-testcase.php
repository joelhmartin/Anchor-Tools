<?php
/**
 * Shared base test case for the Anchor Courses suite.
 *
 * Both `courses` and `events_manager` are enabled in tests/bootstrap.php so the
 * Phase 5 integration tests can exercise the real events surface when it exists.
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Module;

abstract class Anchor_Courses_TestCase extends WP_UnitTestCase {

	/** The courses module singleton, instantiated by the priority-25 bootstrap. */
	protected function courses(): Module {
		$module = Module::instance();
		$this->assertInstanceOf(
			Module::class,
			$module,
			'The courses module did not bootstrap - check that "courses" is enabled in tests/bootstrap.php.'
		);
		return $module;
	}

	/** Whether the events module booted in this run (it is optional for courses). */
	protected function events_active(): bool {
		return class_exists( '\\Anchor\\Events\\Module' )
			&& null !== \Anchor\Events\Module::instance();
	}

	/** Skip unless the events module booted. */
	protected function require_events(): void {
		if ( ! $this->events_active() ) {
			$this->markTestSkipped( 'The events module is not active in this run.' );
		}
	}

	/** A learner account with the default role. */
	protected function make_learner( array $args = [] ): int {
		return (int) self::factory()->user->create( array_merge( [ 'role' => 'subscriber' ], $args ) );
	}
}
