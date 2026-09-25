<?php
/**
 * Anchor Courses - the shared MetaboxSave::authorized_to_save() gate.
 *
 * Fix round 1 (Task 11): extracted from four copies of the same
 * autosave/revision/nonce/capability check in CourseEditor::save(),
 * CourseEditor::save_curriculum(), LessonEditor::save() and
 * QuizEditor::save(). This exercises the trait in isolation via a small
 * fixture class, independent of any one editor.
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Admin\MetaboxSave;

/** @group courses */
class Test_Courses_Metabox_Save extends Anchor_Courses_TestCase {

	private const NONCE = 'anchor_courses_metabox_save_test_nonce';
	private const CAP   = 'edit_posts';

	/** Thin fixture exposing the protected trait method for direct assertions. */
	private function gate(): object {
		return new class() {
			use MetaboxSave;

			public function check( int $post_id, string $nonce_name, string $cap ): bool {
				return $this->authorized_to_save( $post_id, $nonce_name, $cap );
			}
		};
	}

	/**
	 * A second fixture pinning is_doing_autosave() to true, rather than
	 * define()'ing the raw DOING_AUTOSAVE constant: once defined it can never
	 * be unset for the rest of the PHPUnit process, which would permanently
	 * flip this branch for every other save_post-hooked test that runs
	 * afterward. See MetaboxSave::is_doing_autosave()'s docblock.
	 */
	private function gate_during_autosave(): object {
		return new class() {
			use MetaboxSave;

			public function check( int $post_id, string $nonce_name, string $cap ): bool {
				return $this->authorized_to_save( $post_id, $nonce_name, $cap );
			}

			protected function is_doing_autosave(): bool {
				return true;
			}
		};
	}

	public function test_refuses_during_autosave() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$post = $this->make_course();
		$_POST[ self::NONCE ] = wp_create_nonce( self::NONCE );

		$this->assertFalse( $this->gate_during_autosave()->check( $post, self::NONCE, self::CAP ) );
		unset( $_POST[ self::NONCE ] );
	}

	public function test_refuses_a_revision_id() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$post = $this->make_course();
		$revision_id = wp_save_post_revision( $post );
		$this->assertIsInt( $revision_id );
		$this->assertNotSame( 0, $revision_id );

		$_POST[ self::NONCE ] = wp_create_nonce( self::NONCE );
		$this->assertFalse( $this->gate()->check( $revision_id, self::NONCE, self::CAP ) );
		unset( $_POST[ self::NONCE ] );
	}

	public function test_refuses_a_missing_nonce() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$post = $this->make_course();

		$this->assertFalse( $this->gate()->check( $post, self::NONCE, self::CAP ) );
	}

	public function test_refuses_an_invalid_nonce() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$post = $this->make_course();

		$_POST[ self::NONCE ] = 'not-a-real-nonce';
		$this->assertFalse( $this->gate()->check( $post, self::NONCE, self::CAP ) );
		unset( $_POST[ self::NONCE ] );
	}

	public function test_refuses_without_the_capability() {
		wp_set_current_user( $this->make_learner() );
		$post = $this->make_course();

		$_POST[ self::NONCE ] = wp_create_nonce( self::NONCE );
		$this->assertFalse( $this->gate()->check( $post, self::NONCE, self::CAP ) );
		unset( $_POST[ self::NONCE ] );
	}

	public function test_authorizes_a_valid_save() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$post = $this->make_course();

		$_POST[ self::NONCE ] = wp_create_nonce( self::NONCE );
		$this->assertTrue( $this->gate()->check( $post, self::NONCE, self::CAP ) );
		unset( $_POST[ self::NONCE ] );
	}
}
