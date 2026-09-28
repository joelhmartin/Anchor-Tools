<?php
declare(strict_types=1);

namespace Anchor\Courses\Admin;

use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Content\LessonPostType;
use Anchor\Courses\Content\QuizPostType;
use Anchor\Courses\Integrations\Events;
use Anchor\Courses\Services\ProgressService;
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

		// Audit finding d, 2026-09-25: this metabox used to carry its own
		// "Required for course completion" checkbox, but nothing ever read
		// it - requiredness lives on the CURRICULUM ITEM that links to this
		// lesson (Curriculum::required_items()), not on the lesson post. The
		// control was removed rather than left to silently do nothing.
		echo '<p class="description">' . \esc_html__(
			'Whether this lesson is required for course completion is set on its curriculum item, in the course\'s Curriculum builder.',
			'anchor-schema'
		) . '</p>';

		$this->render_live_session_fields( $id );
	}

	/**
	 * The live-session fields (design spec 3.3).
	 *
	 * The event picker and session index are ACTIVE (Task 36 - the
	 * live-session adapter, `Integrations\Events` and
	 * `Shortcodes::render_live_session()`, now reads them for real). The
	 * audit's blanket "not active" notice is gone for those two; `save()`
	 * validates both - a picked event must exist, and the session index
	 * must be in range for that event's real schedule
	 * (`Events::sessions()`).
	 *
	 * "Block stream access until earlier items are complete" is ACTIVE
	 * (Task 37): `Integrations\Events::veto_stream_access()` is attached to
	 * the events module's `anchor_events_can_access_stream` filter and reads
	 * `require_prior_items` for real, so the checkbox no longer promises
	 * protection that does not exist - the failure mode the original audit
	 * item called out, and the reason Task 36 shipped it disabled.
	 */
	private function render_live_session_fields( int $id ): void {
		$event_id      = (int) self::setting( $id, 'event_id' );
		$session_index = (int) self::setting( $id, 'session_index' );
		$require_prior = 1 === (int) self::setting( $id, 'require_prior_items' );

		echo '<h4>' . \esc_html__( 'Live session', 'anchor-schema' ) . '</h4>';

		echo '<p><label><strong>' . \esc_html__( 'Event', 'anchor-schema' ) . '</strong><br />';
		echo '<select name="anchor_lesson[event_id]"><option value="0">' . \esc_html__( '- none -', 'anchor-schema' ) . '</option>';

		$events = Events::available() ? \get_posts(
			[
				'post_type'      => 'event',
				'post_status'    => [ 'publish', 'draft' ],
				'posts_per_page' => 200,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			]
		) : [];

		$listed = false;
		foreach ( $events as $event ) {
			if ( (int) $event->ID === $event_id ) {
				$listed = true;
			}
			\printf(
				'<option value="%d"%s>%s</option>',
				(int) $event->ID,
				\selected( $event_id, (int) $event->ID, false ),
				\esc_html( (string) $event->post_title )
			);
		}
		// A stored event that does not appear above - deleted, or the events
		// module happens to be inactive on this admin request - still
		// round-trips: shown as its own option rather than silently swapping
		// the select to "- none -" under the author's feet.
		if ( $event_id > 0 && ! $listed ) {
			\printf(
				'<option value="%1$d" selected="selected">%2$s (#%1$d)</option>',
				$event_id,
				\esc_html__( 'Unknown event', 'anchor-schema' )
			);
		}
		echo '</select></label></p>';

		if ( ! Events::available() ) {
			echo '<p class="description">' . \esc_html__( 'The events module is not active, so events cannot be listed here right now.', 'anchor-schema' ) . '</p>';
		}

		$session_count = $event_id > 0 ? \count( Events::sessions( $event_id ) ) : 0;
		\printf(
			'<p><label>%s<br /><input type="number" min="0" name="anchor_lesson[session_index]" value="%d" class="small-text" /></label>%s</p>',
			\esc_html__( 'Session index', 'anchor-schema' ),
			$session_index,
			$session_count > 0
				? ' <span class="description">' . \esc_html(
					\sprintf(
						/* translators: %d: number of sessions this event resolves to. */
						\__( '(this event has %d session(s), indexed from 0)', 'anchor-schema' ),
						$session_count
					)
				) . '</span>'
				: ''
		);

		\printf(
			'<p><label><input type="checkbox" name="anchor_lesson[require_prior_items]" value="1"%s /> %s</label></p>',
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

		// A picked event must name a real event post; anything else (a
		// deleted event, or a fabricated id) stores 0 - never trusted
		// unchecked (Task 36).
		$event_id = \absint( $input['event_id'] ?? 0 );
		if ( $event_id > 0 && ! Events::event_exists( $event_id ) ) {
			$event_id = 0;
		}

		$session_index = \absint( $input['session_index'] ?? 0 );
		if ( $event_id <= 0 ) {
			$session_index = 0; // No event: the index means nothing.
		} else {
			// Events::sessions() already degrades to [] when the events
			// module cannot resolve the event right now (module inactive) -
			// $session_count stays 0 and the posted value is kept rather
			// than guessed at, so a value authored while the module was
			// active is never silently clobbered by a request where it
			// happens not to be.
			$session_count = \count( Events::sessions( $event_id ) );
			if ( $session_count > 0 && $session_index >= $session_count ) {
				$session_index = 0;
			}
		}

		$values = [
			'completion_mode'     => $mode,
			'type'                => $type,
			'quiz_id'             => $quiz_id,
			'event_id'            => $event_id,
			'session_index'       => $session_index,
			'require_prior_items' => empty( $input['require_prior_items'] ) ? 0 : 1,
		];

		foreach ( $values as $key => $value ) {
			\update_post_meta( $post_id, LessonPostType::meta_key( $key ), $value );
		}

		// A saved lesson can change event_id, session_index or
		// require_prior_items out from under Events::live_lessons_for_event()'s
		// per-request memo (Task 37 fix round 1).
		Events::flush();

		$this->warn_courses_with_quiz_link_problems( $post_id );
	}

	/**
	 * Audit F04 used to check for the `[lesson, X, quiz]` deadlock only when
	 * a COURSE's curriculum was saved (`CourseEditor::save_curriculum()`).
	 * Flipping a lesson's `completion_mode` to `quiz_pass`, or repointing its
	 * `quiz_id`, here can create that same deadlock without ever re-saving
	 * any course's curriculum - silently, with no warning anywhere (Round 8,
	 * Codex, PR #32 finding 3). Re-checked here for every PUBLISHED course
	 * this lesson actually belongs to (`Curriculum::courses_for_item()`), and
	 * reported with the SAME check CourseEditor uses
	 * (`ProgressService::quiz_link_problems()`) and the same notice family,
	 * naming which course(s) - never blocking the save.
	 *
	 * `quiz_link_problems()` returns every problem in the course, not only
	 * ones involving THIS lesson - a course can carry an unrelated problem on
	 * some other lesson entirely. A course only counts as "affected" here
	 * when one of its returned problems names THIS lesson
	 * (`lesson_id === $lesson_id`) - otherwise saving a perfectly sound
	 * lesson B would warn about a problem lesson A created, that this save
	 * had nothing to do with (Round 9, PR #32 finding 3).
	 */
	private function warn_courses_with_quiz_link_problems( int $lesson_id ): void {
		$affected = [];
		foreach ( Curriculum::courses_for_item( $lesson_id, 'lesson' ) as $course_id ) {
			foreach ( ProgressService::quiz_link_problems( $course_id ) as $problem ) {
				if ( $lesson_id === (int) ( $problem['lesson_id'] ?? 0 ) ) {
					$affected[] = (string) \get_the_title( $course_id );
					break;
				}
			}
		}
		if ( [] === $affected ) {
			return;
		}

		$courses = \implode( ', ', $affected );
		\add_filter(
			'redirect_post_location',
			static function ( $location, $redirect_post_id = 0 ) use ( $lesson_id, $courses ) {
				if ( (int) $redirect_post_id !== $lesson_id ) {
					return $location;
				}
				$location = \add_query_arg( Notices::QUERY_ARG, 'lesson_quiz_link', (string) $location );
				return \add_query_arg( Notices::COURSES_QUERY_ARG, $courses, $location );
			},
			10,
			2
		);
	}
}
