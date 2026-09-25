<?php
declare(strict_types=1);

namespace Anchor\Courses\Admin;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * The autosave + revision + nonce + capability gate every metabox save
 * handler must clear before it is allowed to write meta.
 *
 * Extracted from four call sites that had copied it verbatim:
 * CourseEditor::save(), CourseEditor::save_curriculum(), LessonEditor::save()
 * and QuizEditor::save() (fix round 1). Task 12's save_questions() will be
 * the fifth user.
 */
trait MetaboxSave {

	/**
	 * @param int    $post_id    The post being saved.
	 * @param string $nonce_name The nonce field name read from $_POST, and
	 *                           the wp_verify_nonce() action string - the two
	 *                           are always the same value in this plugin
	 *                           (each editor's own NONCE constant).
	 * @param string $cap        The already-resolved WordPress capability to
	 *                           require, e.g. Capabilities::cap( 'edit_quizzes' ).
	 */
	protected function authorized_to_save( int $post_id, string $nonce_name, string $cap ): bool {
		if ( $this->is_doing_autosave() ) {
			return false;
		}
		// WordPress's own update_post_meta() redirects a revision id to its
		// parent, so without this bail a revision id here would silently
		// overwrite the real post's meta instead.
		if ( \wp_is_post_revision( $post_id ) ) {
			return false;
		}

		$nonce = isset( $_POST[ $nonce_name ] ) ? \sanitize_text_field( \wp_unslash( (string) $_POST[ $nonce_name ] ) ) : '';
		if ( '' === $nonce || ! \wp_verify_nonce( $nonce, $nonce_name ) ) {
			return false;
		}

		return \current_user_can( $cap );
	}

	/**
	 * Whether this request is a WP-Cron autosave.
	 *
	 * A separate, overridable method rather than an inline
	 * `\defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE` check: DOING_AUTOSAVE
	 * is a raw PHP constant with no WP-core filter wrapper, and once
	 * define()'d it can never be unset for the rest of the PHPUnit process -
	 * permanently flipping this branch for every other save_post-hooked test
	 * that runs afterward (the same hazard Anchor_Compliance_Snippets_Bridge
	 * ::is_autosave() exists to avoid). Pin it via a test-only override
	 * instead of defining the constant.
	 */
	protected function is_doing_autosave(): bool {
		return \defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE;
	}
}
