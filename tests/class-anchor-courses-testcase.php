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

	/** @var int[] Courses made by make_course(), whose roles must be removed. */
	protected array $minted_courses = [];

	public function tear_down() {
		// Strip every course role, not only those make_course() tracked: any
		// test that publishes an anchor_course through the raw factory mints
		// roles too, and a leak here bloats wp_user_roles for the whole run.
		foreach ( array_keys( wp_roles()->roles ) as $slug ) {
			if ( preg_match( '/^anchor_course_\d+(_completed)?$/', (string) $slug ) ) {
				remove_role( (string) $slug );
			}
		}
		$this->minted_courses = [];
		// Context-less role losses queue for shutdown (Support\Roles, final
		// review I8); a test that never resolves them must not hand them to
		// the next test - or to the process's own shutdown.
		if ( property_exists( \Anchor\Courses\Support\Roles::class, 'pending_losses' ) ) {
			$pending = new ReflectionProperty( \Anchor\Courses\Support\Roles::class, 'pending_losses' );
			$pending->setAccessible( true );
			$pending->setValue( null, [] );
		}
		parent::tear_down();
	}

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

	/** Create a published course with `_anchor_course_*` meta (keys WITHOUT the prefix). */
	protected function make_course( array $meta = [], string $title = 'Test Course' ): int {
		return $this->make_content( \Anchor\Courses\Content\CoursePostType::CPT, '_anchor_course_', $meta, $title );
	}

	/** Create a published lesson with `_anchor_lesson_*` meta. */
	protected function make_lesson( array $meta = [], string $title = 'Test Lesson' ): int {
		return $this->make_content( \Anchor\Courses\Content\LessonPostType::CPT, '_anchor_lesson_', $meta, $title );
	}

	/** Create a published quiz with `_anchor_quiz_*` meta. */
	protected function make_quiz( array $meta = [], string $title = 'Test Quiz' ): int {
		return $this->make_content( \Anchor\Courses\Content\QuizPostType::CPT, '_anchor_quiz_', $meta, $title );
	}

	private function make_content( string $cpt, string $prefix, array $meta, string $title ): int {
		$id = (int) self::factory()->post->create(
			[ 'post_type' => $cpt, 'post_status' => 'publish', 'post_title' => $title ]
		);
		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, $prefix . $key, $value );
		}
		if ( \Anchor\Courses\Content\CoursePostType::CPT === $cpt ) {
			$this->minted_courses[] = $id;
		}
		return $id;
	}
}
