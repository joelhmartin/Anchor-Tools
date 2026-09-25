<?php
declare(strict_types=1);

namespace Anchor\Courses\Admin;

use Anchor\Courses\Content\LessonPostType;
use Anchor\Courses\Content\QuizPostType;
use Anchor\Courses\Support\Capabilities;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** Lesson settings metabox: completion mode (brief 9) and live-session fields (design spec 3.3). */
final class LessonEditor {
	use MetaboxSave;

	public const NONCE = 'anchor_courses_lesson_nonce';

	public function __construct() {
		\add_action( 'add_meta_boxes', [ $this, 'add_metaboxes' ] );
		\add_action( 'save_post_' . LessonPostType::CPT, [ $this, 'save' ] );
	}

	public static function defaults(): array {
		return [
			'completion_mode'     => 'manual',
			'required'            => 1,
			'quiz_id'             => 0,
			'type'                => 'content',
			'event_id'            => 0,
			'session_index'       => 0,
			'require_prior_items' => 0,
		];
	}

	/** @return mixed */
	public static function setting( int $lesson_id, string $key ) {
		$defaults = self::defaults();
		$stored   = \get_post_meta( $lesson_id, LessonPostType::meta_key( $key ), true );
		return ( '' === $stored || null === $stored ) ? ( $defaults[ $key ] ?? '' ) : $stored;
	}

	public function add_metaboxes(): void {
		\add_meta_box(
			'anchor_courses_lesson',
			\__( 'Lesson Settings', 'anchor-schema' ),
			[ $this, 'render' ],
			LessonPostType::CPT,
			'side',
			'high'
		);
	}

	public function render( \WP_Post $post ): void {
		\wp_nonce_field( self::NONCE, self::NONCE );
		$id = (int) $post->ID;

		$this->select(
			'type',
			\__( 'Lesson type', 'anchor-schema' ),
			[ 'content' => \__( 'Content', 'anchor-schema' ), 'live_session' => \__( 'Live session', 'anchor-schema' ) ],
			(string) self::setting( $id, 'type' )
		);

		$this->select(
			'completion_mode',
			\__( 'Completion', 'anchor-schema' ),
			[
				'manual'    => \__( 'Learner marks complete', 'anchor-schema' ),
				'view'      => \__( 'Complete on view', 'anchor-schema' ),
				'quiz_pass' => \__( 'Complete when its quiz passes', 'anchor-schema' ),
			],
			(string) self::setting( $id, 'completion_mode' )
		);

		echo '<p><label><strong>' . \esc_html__( 'Quiz', 'anchor-schema' ) . '</strong><br />';
		echo '<select name="anchor_lesson[quiz_id]"><option value="0">'
			. \esc_html__( '- none -', 'anchor-schema' ) . '</option>';
		$quizzes = \get_posts(
			[
				'post_type'      => QuizPostType::CPT,
				'post_status'    => [ 'publish', 'draft' ],
				'posts_per_page' => 100,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			]
		);
		foreach ( $quizzes as $quiz ) {
			\printf(
				'<option value="%d"%s>%s</option>',
				(int) $quiz->ID,
				\selected( (int) self::setting( $id, 'quiz_id' ), (int) $quiz->ID, false ),
				\esc_html( (string) $quiz->post_title )
			);
		}
		echo '</select></label></p>';

		\printf(
			'<p><label><input type="checkbox" name="anchor_lesson[required]" value="1"%s /> %s</label></p>',
			\checked( (int) self::setting( $id, 'required' ), 1, false ),
			\esc_html__( 'Required for course completion', 'anchor-schema' )
		);

		$this->render_live_session_fields( $id );
	}

	/**
	 * The live-session fields, shown DISABLED with an inline notice (audit UI
	 * item): nothing reads them yet - the live-session adapter and the
	 * stream prerequisite veto are plan Phase 5 - so an enabled "Block stream
	 * access" checkbox would promise protection that does not exist.
	 *
	 * The stored values still round-trip: disabled inputs are never posted,
	 * so each value also rides in a hidden input of the same name and
	 * save() writes it back unchanged (a programmatic save can still set
	 * them). When Phase 5 ships, drop the hidden mirrors and `disabled`.
	 */
	private function render_live_session_fields( int $id ): void {
		$event_id      = (int) self::setting( $id, 'event_id' );
		$session_index = (int) self::setting( $id, 'session_index' );
		$require_prior = 1 === (int) self::setting( $id, 'require_prior_items' );

		echo '<h4>' . \esc_html__( 'Live session', 'anchor-schema' ) . '</h4>';
		echo '<p class="description anchor-courses-inactive-notice"><em>'
			. \esc_html__( 'Not active until the live-session adapter ships (plan Phase 5). These settings are kept but do not affect access yet.', 'anchor-schema' )
			. '</em></p>';

		\printf( '<input type="hidden" name="anchor_lesson[event_id]" value="%d" />', $event_id );
		\printf( '<input type="hidden" name="anchor_lesson[session_index]" value="%d" />', $session_index );
		if ( $require_prior ) {
			echo '<input type="hidden" name="anchor_lesson[require_prior_items]" value="1" />';
		}

		\printf(
			'<p><label>%s<br /><input type="number" min="0" name="anchor_lesson[event_id]" value="%d" class="small-text" disabled="disabled" /></label></p>',
			\esc_html__( 'Event ID', 'anchor-schema' ),
			$event_id
		);
		\printf(
			'<p><label>%s<br /><input type="number" min="0" name="anchor_lesson[session_index]" value="%d" class="small-text" disabled="disabled" /></label></p>',
			\esc_html__( 'Session index', 'anchor-schema' ),
			$session_index
		);
		\printf(
			'<p><label><input type="checkbox" name="anchor_lesson[require_prior_items]" value="1"%s disabled="disabled" /> %s</label></p>',
			\checked( $require_prior, true, false ),
			\esc_html__( 'Block stream access until earlier items are complete', 'anchor-schema' )
		);
	}

	private function select( string $key, string $label, array $options, string $current ): void {
		\printf(
			'<p><label><strong>%s</strong><br /><select name="anchor_lesson[%s]">',
			\esc_html( $label ),
			\esc_attr( $key )
		);
		foreach ( $options as $value => $text ) {
			\printf(
				'<option value="%s"%s>%s</option>',
				\esc_attr( (string) $value ),
				\selected( $current, (string) $value, false ),
				\esc_html( (string) $text )
			);
		}
		echo '</select></label></p>';
	}

	public function save( int $post_id ): void {
		if ( ! $this->authorized_to_save( $post_id, self::NONCE, Capabilities::cap( 'edit_lessons' ) ) ) {
			return;
		}

		$input = isset( $_POST['anchor_lesson'] ) && \is_array( $_POST['anchor_lesson'] )
			? \wp_unslash( $_POST['anchor_lesson'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			: [];

		$mode = \sanitize_key( (string) ( $input['completion_mode'] ?? '' ) );
		if ( ! \in_array( $mode, LessonPostType::COMPLETION_MODES, true ) ) {
			$mode = 'manual';
		}

		$type = \sanitize_key( (string) ( $input['type'] ?? '' ) );
		if ( ! \in_array( $type, LessonPostType::TYPES, true ) ) {
			$type = 'content';
		}

		// A quiz link must name a real quiz post; anything else stores 0.
		$quiz_id = \absint( $input['quiz_id'] ?? 0 );
		if ( $quiz_id > 0 && QuizPostType::CPT !== \get_post_type( $quiz_id ) ) {
			$quiz_id = 0;
		}

		$values = [
			'completion_mode'     => $mode,
			'type'                => $type,
			'quiz_id'             => $quiz_id,
			'required'            => empty( $input['required'] ) ? 0 : 1,
			'event_id'            => \absint( $input['event_id'] ?? 0 ),
			'session_index'       => \absint( $input['session_index'] ?? 0 ),
			'require_prior_items' => empty( $input['require_prior_items'] ) ? 0 : 1,
		];

		foreach ( $values as $key => $value ) {
			\update_post_meta( $post_id, LessonPostType::meta_key( $key ), $value );
		}
	}
}
