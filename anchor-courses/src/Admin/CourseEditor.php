<?php
declare(strict_types=1);

namespace Anchor\Courses\Admin;

use Anchor\Courses\Content\CoursePostType;
use Anchor\Courses\Module;
use Anchor\Courses\Support\Capabilities;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** Course settings metabox: brief 6.3 authored meta, 12 CE fields, 21.1 grouping. */
final class CourseEditor {

	public const NONCE = 'anchor_courses_course_nonce';

	public function __construct() {
		\add_action( 'add_meta_boxes', [ $this, 'add_metaboxes' ] );
		\add_action( 'save_post_' . CoursePostType::CPT, [ $this, 'save' ] );
		\add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
	}

	/** Authored defaults (design spec section 4). */
	public static function defaults(): array {
		return [
			'duration' => '', 'difficulty' => '', 'instructor' => '',
			'ce_credits' => 0.0, 'ce_type' => '', 'ce_provider_name' => '', 'ce_provider_number' => '',
			'ce_expires_days' => 0,
			'prerequisites' => [],
			'completion_mode' => 'all_required_items', 'completion_percentage' => 100,
			'progression_mode' => 'sequential',
			'certificate_enabled' => 1, 'certificate_template' => 'default',
			'expiration_days' => 0, 'available_from' => '', 'available_until' => '',
		];
	}

	/**
	 * Read one setting with the default applied.
	 *
	 * @return mixed
	 */
	public static function setting( int $course_id, string $key ) {
		$defaults = self::defaults();
		$stored   = \get_post_meta( $course_id, CoursePostType::meta_key( $key ), true );

		if ( '' === $stored || null === $stored ) {
			return $defaults[ $key ] ?? '';
		}
		if ( \is_array( $defaults[ $key ] ?? null ) && ! \is_array( $stored ) ) {
			return $defaults[ $key ];
		}
		return $stored;
	}

	public function add_metaboxes(): void {
		\add_meta_box(
			'anchor_courses_settings',
			\__( 'Course Settings', 'anchor-schema' ),
			[ $this, 'render_settings' ],
			CoursePostType::CPT,
			'normal',
			'high'
		);
	}

	public function assets( string $hook = '' ): void {
		if ( ! \in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}
		$screen = \get_current_screen();
		if ( ! $screen || CoursePostType::CPT !== $screen->post_type ) {
			return;
		}

		\wp_enqueue_style( 'anchor-courses-admin', Module::assets_url() . 'admin.css', [], Module::VERSION );
	}

	public function render_settings( \WP_Post $post ): void {
		\wp_nonce_field( self::NONCE, self::NONCE );
		$id = (int) $post->ID;

		echo '<div class="anchor-courses-settings">';

		echo '<h4>' . \esc_html__( 'Details', 'anchor-schema' ) . '</h4>';
		$this->text_field( $id, 'duration', \__( 'Duration', 'anchor-schema' ) );
		$this->text_field( $id, 'difficulty', \__( 'Difficulty', 'anchor-schema' ) );
		$this->text_field( $id, 'instructor', \__( 'Instructor', 'anchor-schema' ) );

		echo '<h4>' . \esc_html__( 'Access', 'anchor-schema' ) . '</h4>';
		echo '<p class="description">' . \esc_html__(
			'Access is the course role, shown in the Course Role panel. Add someone on the Learners tab, or sell a product mapped to this course.',
			'anchor-schema'
		) . '</p>';
		$this->prerequisite_picker( $id );
		$this->text_field( $id, 'available_from', \__( 'Available from (YYYY-MM-DD)', 'anchor-schema' ) );
		$this->text_field( $id, 'available_until', \__( 'Available until (YYYY-MM-DD)', 'anchor-schema' ) );
		$this->text_field( $id, 'expiration_days', \__( 'Enrolment expires after (days, 0 = never)', 'anchor-schema' ) );

		echo '<h4>' . \esc_html__( 'Progression and completion', 'anchor-schema' ) . '</h4>';
		$this->select_field(
			$id,
			'progression_mode',
			\__( 'Progression', 'anchor-schema' ),
			[ 'sequential' => \__( 'Sequential', 'anchor-schema' ), 'free' => \__( 'Free', 'anchor-schema' ) ]
		);
		$this->select_field(
			$id,
			'completion_mode',
			\__( 'Completion rule', 'anchor-schema' ),
			[
				'all_required_items' => \__( 'All required items', 'anchor-schema' ),
				'minimum_percentage' => \__( 'Minimum percentage', 'anchor-schema' ),
				'manual'             => \__( 'Manual only', 'anchor-schema' ),
			]
		);
		$this->text_field( $id, 'completion_percentage', \__( 'Minimum percentage', 'anchor-schema' ) );

		echo '<h4>' . \esc_html__( 'CE credits', 'anchor-schema' ) . '</h4>';
		$this->text_field( $id, 'ce_credits', \__( 'Credits awarded', 'anchor-schema' ) );
		$this->text_field( $id, 'ce_type', \__( 'Credit type', 'anchor-schema' ) );
		$this->text_field( $id, 'ce_provider_name', \__( 'Provider name', 'anchor-schema' ) );
		$this->text_field( $id, 'ce_provider_number', \__( 'Provider number', 'anchor-schema' ) );
		$this->text_field( $id, 'ce_expires_days', \__( 'Credits expire after (days, 0 = never)', 'anchor-schema' ) );

		echo '<h4>' . \esc_html__( 'Certificate', 'anchor-schema' ) . '</h4>';
		\printf(
			'<p><label><input type="checkbox" name="anchor_course[certificate_enabled]" value="1"%s /> %s</label></p>',
			\checked( (string) self::setting( $id, 'certificate_enabled' ), '1', false ),
			\esc_html__( 'Issue a certificate on completion', 'anchor-schema' )
		);
		$this->text_field( $id, 'certificate_template', \__( 'Certificate template slug', 'anchor-schema' ) );

		echo '</div>';
	}

	private function text_field( int $course_id, string $key, string $label ): void {
		\printf(
			'<p class="anchor-courses-field"><label for="ac-%1$s"><strong>%2$s</strong></label><br />'
			. '<input type="text" class="regular-text" id="ac-%1$s" name="anchor_course[%1$s]" value="%3$s" /></p>',
			\esc_attr( $key ),
			\esc_html( $label ),
			\esc_attr( (string) self::setting( $course_id, $key ) )
		);
	}

	private function select_field( int $course_id, string $key, string $label, array $options ): void {
		\printf(
			'<p class="anchor-courses-field"><label for="ac-%1$s"><strong>%2$s</strong></label><br />'
			. '<select id="ac-%1$s" name="anchor_course[%1$s]">',
			\esc_attr( $key ),
			\esc_html( $label )
		);
		$current = (string) self::setting( $course_id, $key );
		foreach ( $options as $value => $text ) {
			\printf(
				'<option value="%s"%s>%s</option>',
				\esc_attr( (string) $value ),
				\selected( $current, (string) $value, false ),
				\esc_html( (string) $text )
			);
		}
		echo '</select></p>';
	}

	/**
	 * The module's ONE role picker, and it picks prerequisites only.
	 *
	 * There is no auto-enrol picker and no access-type select: access is the
	 * course's own role (design spec 3.1). This list answers a different
	 * question - "what must they already have finished?" - so it offers only
	 * things that can be finished.
	 */
	private function prerequisite_picker( int $course_id ): void {
		$selected = \array_map( 'strval', (array) self::setting( $course_id, 'prerequisites' ) );
		$choices  = self::prerequisite_role_choices( $course_id );

		\printf(
			'<p class="anchor-courses-field"><label for="ac-prerequisites"><strong>%s</strong></label><br />',
			\esc_html__( 'Prerequisites (completed courses / attended events)', 'anchor-schema' )
		);

		if ( [] === $choices ) {
			echo \esc_html__( 'No completed-course or event roles exist yet.', 'anchor-schema' ) . '</p>';
			return;
		}

		echo '<select multiple size="6" id="ac-prerequisites" name="anchor_course[prerequisites][]" class="anchor-courses-roles">';
		foreach ( $choices as $slug => $role ) {
			\printf(
				'<option value="%s"%s>%s</option>',
				\esc_attr( (string) $slug ),
				\in_array( (string) $slug, $selected, true ) ? ' selected="selected"' : '',
				\esc_html( (string) ( $role['name'] ?? $slug ) )
			);
		}
		echo '</select></p>';
	}

	/**
	 * Slug shapes that may be a prerequisite.
	 *
	 * A course's COMPLETION role, or an event's role. Deliberately not an
	 * exclusion list: `customer`, `subscriber` and anything else a plugin
	 * invents simply never match, and neither does a course's ACCESS role
	 * (`anchor_course_{id}` with no `_completed`), because "must already be
	 * enrolled" is not a prerequisite - it is a circular one.
	 */
	private const PREREQUISITE_PATTERNS = [
		'/^anchor_course_\d+_completed$/',
		'/^anchor_event_\d+$/',
	];

	/** @return bool Whether this slug may be stored as a prerequisite. */
	public static function is_prerequisite_slug( string $slug ): bool {
		foreach ( self::PREREQUISITE_PATTERNS as $pattern ) {
			if ( 1 === \preg_match( $pattern, $slug ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The roles the prerequisites picker may offer and a save may store.
	 *
	 * Controller ruling (Task 8 fix round 1): a course must never be offered -
	 * or allowed to save - its OWN completion role as a prerequisite. That role
	 * can never be satisfied by anyone, since it is only granted on completing
	 * this course, so the course would silently lock itself. $exclude_course_id,
	 * when given, drops that one course's completion role; every other
	 * course's is still offered. Mirrors the events module's
	 * prerequisite_role_choices( $exclude_event_id ).
	 *
	 * @param int $exclude_course_id The course this picker/save is for, or 0
	 *                                to exclude nothing (e.g. a brand-new,
	 *                                not-yet-saved course has no own role yet).
	 * @return array<string,array> slug => role definition
	 */
	public static function prerequisite_role_choices( int $exclude_course_id = 0 ): array {
		$choices   = [];
		$own_slug  = $exclude_course_id > 0 ? 'anchor_course_' . $exclude_course_id . '_completed' : '';
		foreach ( \wp_roles()->roles as $slug => $role ) {
			$slug = (string) $slug;
			if ( '' !== $own_slug && $slug === $own_slug ) {
				continue;
			}
			if ( self::is_prerequisite_slug( $slug ) ) {
				$choices[ $slug ] = $role;
			}
		}
		/**
		 * Filter the prerequisite roles offered on the course editor.
		 *
		 * Anything added here is also accepted by the sanitiser, so a site can
		 * widen the list deliberately - but never by accident.
		 *
		 * @param array<string,array> $choices           slug => role definition
		 * @param int                 $exclude_course_id The course being edited, or 0.
		 */
		return (array) \apply_filters( 'anchor_courses_prerequisite_role_choices', $choices, $exclude_course_id );
	}

	public function save( int $post_id ): void {
		if ( \defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		$nonce = isset( $_POST[ self::NONCE ] ) ? \sanitize_text_field( \wp_unslash( (string) $_POST[ self::NONCE ] ) ) : '';
		if ( '' === $nonce || ! \wp_verify_nonce( $nonce, self::NONCE ) ) {
			return;
		}
		if ( ! \current_user_can( Capabilities::cap( 'edit_courses' ) ) ) {
			return;
		}

		$input = isset( $_POST['anchor_course'] ) && \is_array( $_POST['anchor_course'] )
			? \wp_unslash( $_POST['anchor_course'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			: [];

		foreach ( \array_keys( self::defaults() ) as $key ) {
			// An unposted checkbox means "off", so certificate_enabled always writes.
			$raw = $input[ $key ] ?? ( 'certificate_enabled' === $key ? '' : null );
			if ( null === $raw ) {
				continue;
			}
			\update_post_meta( $post_id, CoursePostType::meta_key( $key ), self::sanitize_value( $key, $raw, $post_id ) );
		}
	}

	/**
	 * @param mixed $value
	 * @param int   $post_id The course being saved, or 0 outside a save (e.g.
	 *                       a caller with no course yet). Only 'prerequisites'
	 *                       uses it, to drop the course's own completion role.
	 * @return mixed
	 */
	public static function sanitize_value( string $key, $value, int $post_id = 0 ) {
		$defaults = self::defaults();

		switch ( $key ) {
			case 'progression_mode':
				$v = \sanitize_key( (string) $value );
				return \in_array( $v, CoursePostType::PROGRESSION_MODES, true ) ? $v : $defaults['progression_mode'];

			case 'completion_mode':
				$v = \sanitize_key( (string) $value );
				return \in_array( $v, CoursePostType::COMPLETION_MODES, true ) ? $v : $defaults['completion_mode'];

			case 'prerequisites':
				// Only roles the picker could have offered. A crafted POST naming
				// `customer` - or this course's own completion role - is dropped
				// here, not just hidden in the UI.
				$allowed = \array_keys( self::prerequisite_role_choices( $post_id ) );
				return \array_values( \array_filter(
					\array_map( 'sanitize_key', (array) $value ),
					static fn( string $slug ): bool => '' !== $slug && \in_array( $slug, $allowed, true )
				) );

			case 'ce_credits':
				return (float) $value;

			case 'ce_expires_days':
			case 'expiration_days':
				return \absint( $value );

			case 'completion_percentage':
				// Garbage or empty input is not "as little as possible" - it is
				// unset, so it falls back to the authored default (100), not the
				// 1-floor a clamp would otherwise produce for absint('nope') === 0.
				$n = \absint( $value );
				return $n > 0 ? \max( 1, \min( 100, $n ) ) : $defaults['completion_percentage'];

			case 'certificate_enabled':
				return '' === $value ? 0 : 1;

			case 'available_from':
			case 'available_until':
				$v = \sanitize_text_field( (string) $value );
				if ( 1 !== \preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m ) ) {
					return '';
				}
				return \checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? $v : '';

			default:
				return \sanitize_text_field( (string) $value );
		}
	}
}
