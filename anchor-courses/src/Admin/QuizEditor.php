<?php
declare(strict_types=1);

namespace Anchor\Courses\Admin;

use Anchor\Courses\Content\Questions;
use Anchor\Courses\Content\QuizPostType;
use Anchor\Courses\Module;
use Anchor\Courses\Support\Capabilities;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** Quiz configuration (brief 8.1) and timer-expiry policy (brief 8.5). */
final class QuizEditor {
	use MetaboxSave;

	public const NONCE = 'anchor_courses_quiz_nonce';

	/** Integer settings and their inclusive clamp range. */
	private const INT_RANGES = [
		'passing_score'       => [ 1, 100 ],
		'max_attempts'        => [ 0, 1000 ],     // 0 = unlimited
		'time_limit_seconds'  => [ 0, 86400 ],    // 0 = untimed
		'retry_delay_seconds' => [ 0, 2592000 ],  // 0 = retry immediately
	];

	private const BOOLS = [
		'shuffle_questions', 'shuffle_answers', 'show_correct_answers',
		'show_score', 'allow_review', 'required',
	];

	public const TIMER_POLICIES = [ 'auto_submit', 'expire' ];

	public function __construct() {
		\add_action( 'add_meta_boxes', [ $this, 'add_metaboxes' ] );
		\add_action( 'save_post_' . QuizPostType::CPT, [ $this, 'save' ] );
		// One tick after save(), same convention as CourseEditor::save_curriculum()
		// (progress.md: settings first, structure second).
		\add_action( 'save_post_' . QuizPostType::CPT, [ $this, 'save_questions' ], 11 );
		\add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
	}

	public static function defaults(): array {
		return [
			'passing_score'        => 80,
			'max_attempts'         => 0,
			'time_limit_seconds'   => 0,
			'shuffle_questions'    => 0,
			'shuffle_answers'      => 0,
			'show_correct_answers' => 1,
			'show_score'           => 1,
			'allow_review'         => 1,
			'retry_delay_seconds'  => 0,
			'required'             => 1,
			'on_timer_expiry'      => 'auto_submit',
		];
	}

	public static function settings( int $quiz_id ): array {
		$stored = \get_post_meta( $quiz_id, QuizPostType::meta_key( 'settings' ), true );
		return self::coerce( \array_merge( self::defaults(), \is_array( $stored ) ? $stored : [] ) );
	}

	/** Force stored values back into their declared types. */
	private static function coerce( array $s ): array {
		foreach ( \array_keys( self::INT_RANGES ) as $key ) {
			$s[ $key ] = (int) $s[ $key ];
		}
		foreach ( self::BOOLS as $key ) {
			$s[ $key ] = empty( $s[ $key ] ) ? 0 : 1;
		}
		$s['on_timer_expiry'] = \in_array( (string) $s['on_timer_expiry'], self::TIMER_POLICIES, true )
			? (string) $s['on_timer_expiry']
			: 'auto_submit';
		return $s;
	}

	public static function sanitize_settings( array $input ): array {
		$clean = [];

		foreach ( self::INT_RANGES as $key => $range ) {
			// Blank or garbage input is not "as little as possible" - it is
			// unset, so it falls back to the authored default, not the
			// absint('nope') === 0 floor a clamp would otherwise produce.
			// An explicit numeric 0 is still honoured where the range allows
			// it (unlimited / untimed / immediate). Mirrors
			// CourseEditor::sanitize_value()'s completion_percentage rule.
			$raw   = $input[ $key ] ?? '';
			$value = ( '' === $raw || ! \is_numeric( $raw ) ) ? self::defaults()[ $key ] : \absint( $raw );
			$clean[ $key ] = \max( $range[0], \min( $range[1], $value ) );
		}

		// Checkboxes: absent means off. Never fall back to the default here, or an
		// author could never turn one off.
		foreach ( self::BOOLS as $key ) {
			$clean[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
		}

		$policy                   = \sanitize_key( (string) ( $input['on_timer_expiry'] ?? '' ) );
		$clean['on_timer_expiry'] = \in_array( $policy, self::TIMER_POLICIES, true ) ? $policy : 'auto_submit';

		// Preserve declared key order so the stored array is diffable.
		$ordered = [];
		foreach ( \array_keys( self::defaults() ) as $key ) {
			$ordered[ $key ] = $clean[ $key ];
		}
		return $ordered;
	}

	public function add_metaboxes(): void {
		\add_meta_box(
			'anchor_courses_quiz_settings',
			\__( 'Quiz Settings', 'anchor-schema' ),
			[ $this, 'render_settings' ],
			QuizPostType::CPT,
			'side',
			'high'
		);
		\add_meta_box(
			'anchor_courses_quiz_questions',
			\__( 'Questions', 'anchor-schema' ),
			[ $this, 'render_questions' ],
			QuizPostType::CPT,
			'normal',
			'high'
		);
	}

	public function render_settings( \WP_Post $post ): void {
		\wp_nonce_field( self::NONCE, self::NONCE );
		$s = self::settings( (int) $post->ID );

		$numbers = [
			'passing_score'       => \__( 'Passing score (%)', 'anchor-schema' ),
			'max_attempts'        => \__( 'Max attempts (0 = unlimited)', 'anchor-schema' ),
			'time_limit_seconds'  => \__( 'Time limit in seconds (0 = untimed)', 'anchor-schema' ),
			'retry_delay_seconds' => \__( 'Wait between attempts, seconds', 'anchor-schema' ),
		];
		foreach ( $numbers as $key => $label ) {
			\printf(
				'<p><label>%1$s<br /><input type="number" min="0" name="anchor_quiz[%2$s]" value="%3$d" class="small-text" /></label></p>',
				\esc_html( $label ),
				\esc_attr( $key ),
				(int) $s[ $key ]
			);
		}

		$checks = [
			'shuffle_questions'    => \__( 'Shuffle questions', 'anchor-schema' ),
			'shuffle_answers'      => \__( 'Shuffle answers', 'anchor-schema' ),
			'show_correct_answers' => \__( 'Show correct answers after grading', 'anchor-schema' ),
			'show_score'           => \__( 'Show the score', 'anchor-schema' ),
			'allow_review'         => \__( 'Allow reviewing a graded attempt', 'anchor-schema' ),
			'required'             => \__( 'Required for course completion', 'anchor-schema' ),
		];
		foreach ( $checks as $key => $label ) {
			\printf(
				'<p><label><input type="checkbox" name="anchor_quiz[%1$s]" value="1"%2$s /> %3$s</label></p>',
				\esc_attr( $key ),
				\checked( (int) $s[ $key ], 1, false ),
				\esc_html( $label )
			);
		}

		echo '<p><label>' . \esc_html__( 'When the timer expires', 'anchor-schema' ) . '<br />';
		echo '<select name="anchor_quiz[on_timer_expiry]">';
		$policies = [
			'auto_submit' => \__( 'Auto-submit saved answers', 'anchor-schema' ),
			'expire'      => \__( 'Mark the attempt expired', 'anchor-schema' ),
		];
		foreach ( $policies as $value => $label ) {
			\printf(
				'<option value="%s"%s>%s</option>',
				\esc_attr( (string) $value ),
				\selected( (string) $s['on_timer_expiry'], (string) $value, false ),
				\esc_html( (string) $label )
			);
		}
		echo '</select></label></p>';
	}

	public function save( int $post_id ): void {
		if ( ! $this->authorized_to_save( $post_id, self::NONCE, Capabilities::cap( 'edit_quizzes' ) ) ) {
			return;
		}

		$input = isset( $_POST['anchor_quiz'] ) && \is_array( $_POST['anchor_quiz'] )
			? \wp_unslash( $_POST['anchor_quiz'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			: [];

		\update_post_meta( $post_id, QuizPostType::meta_key( 'settings' ), self::sanitize_settings( $input ) );
	}

	public function render_questions( \WP_Post $post ): void {
		// Reprint the nonce so question saving still works when the settings box
		// is hidden through Screen Options.
		\wp_nonce_field( self::NONCE, self::NONCE );

		echo '<div class="anchor-courses-questions">';
		echo '<ul class="anchor-courses-question-list"></ul>';
		\printf(
			'<p><button type="button" class="button anchor-courses-add-question" data-type="single_choice">%s</button> '
			. '<button type="button" class="button anchor-courses-add-question" data-type="multiple_choice">%s</button> '
			. '<button type="button" class="button anchor-courses-add-question" data-type="true_false">%s</button></p>',
			\esc_html__( 'Add single choice', 'anchor-schema' ),
			\esc_html__( 'Add multiple choice', 'anchor-schema' ),
			\esc_html__( 'Add true/false', 'anchor-schema' )
		);
		\printf(
			'<input type="hidden" name="anchor_quiz_questions" class="anchor-courses-questions-data" value="%s" />',
			\esc_attr( (string) \wp_json_encode( Questions::get( (int) $post->ID ) ) )
		);
		echo '</div>';
	}

	/**
	 * Persist authored questions. Gated through the shared MetaboxSave trait
	 * (progress.md ruling: this is the fifth of five call sites, and must not
	 * be a copy of the nonce/cap/autosave/revision check).
	 */
	public function save_questions( int $post_id ): void {
		if ( ! $this->authorized_to_save( $post_id, self::NONCE, Capabilities::cap( 'edit_quizzes' ) ) ) {
			return;
		}
		if ( ! isset( $_POST['anchor_quiz_questions'] ) ) {
			return;
		}

		$decoded = \json_decode( \wp_unslash( (string) $_POST['anchor_quiz_questions'] ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		// A decode failure means a broken editor, not "the author deleted every question".
		if ( ! \is_array( $decoded ) ) {
			return;
		}

		Questions::save( $post_id, $decoded );
	}

	public function assets( string $hook = '' ): void {
		if ( ! \in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}
		$screen = \get_current_screen();
		if ( ! $screen || QuizPostType::CPT !== $screen->post_type ) {
			return;
		}

		\wp_enqueue_style( 'anchor-courses-admin', Module::assets_url() . 'admin.css', [], Module::VERSION );
		\wp_enqueue_script(
			'anchor-courses-admin-common',
			Module::assets_url() . 'admin-common.js',
			[ 'jquery' ],
			Module::VERSION,
			true
		);
		\wp_enqueue_script(
			'anchor-courses-quiz-admin',
			Module::assets_url() . 'admin-quiz.js',
			[ 'jquery', 'jquery-ui-sortable', 'anchor-courses-admin-common' ],
			Module::VERSION,
			true
		);
		\wp_localize_script(
			'anchor-courses-quiz-admin',
			'anchorCoursesQuiz',
			[
				'strings' => [
					'prompt'     => \__( 'Question prompt', 'anchor-schema' ),
					'points'     => \__( 'Points', 'anchor-schema' ),
					'answer'     => \__( 'Answer text', 'anchor-schema' ),
					'correct'    => \__( 'Correct', 'anchor-schema' ),
					'addAnswer'  => \__( 'Add answer', 'anchor-schema' ),
					'remove'     => \__( 'Remove', 'anchor-schema' ),
					'confirmDel' => \__( 'Remove this question?', 'anchor-schema' ),
				],
			]
		);
	}
}
