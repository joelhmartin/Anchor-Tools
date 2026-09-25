<?php
declare(strict_types=1);

namespace Anchor\Courses\Admin;

use Anchor\Courses\Content\Curriculum;
use Anchor\Courses\Content\CoursePostType;
use Anchor\Courses\Content\LessonPostType;
use Anchor\Courses\Content\QuizPostType;
use Anchor\Courses\Module;
use Anchor\Courses\Support\Capabilities;
use Anchor\Courses\Support\Roles;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** Course settings metabox: brief 6.3 authored meta, 12 CE fields, 21.1 grouping. */
final class CourseEditor {
	use MetaboxSave;

	public const NONCE = 'anchor_courses_course_nonce';

	public function __construct() {
		\add_action( 'add_meta_boxes', [ $this, 'add_metaboxes' ] );
		\add_action( 'save_post_' . CoursePostType::CPT, [ $this, 'save' ] );
		\add_action( 'save_post_' . CoursePostType::CPT, [ $this, 'save_curriculum' ], 11 );
		\add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
		\add_action( 'wp_ajax_anchor_courses_search_items', [ $this, 'ajax_search_items' ] );
		\add_action( 'wp_ajax_anchor_courses_create_item', [ $this, 'ajax_create_item' ] );
		\add_action( 'admin_post_anchor_courses_delete_role', [ $this, 'handle_delete_role' ] );
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
		\add_meta_box(
			'anchor_courses_curriculum',
			\__( 'Curriculum', 'anchor-schema' ),
			[ $this, 'render_curriculum' ],
			CoursePostType::CPT,
			'normal',
			'high'
		);
		\add_meta_box(
			'anchor_courses_role',
			\__( 'Course Role', 'anchor-schema' ),
			[ $this, 'render_role_panel' ],
			CoursePostType::CPT,
			'side',
			'default'
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

		\wp_enqueue_script(
			'anchor-courses-admin-common',
			Module::assets_url() . 'admin-common.js',
			[ 'jquery' ],
			Module::VERSION,
			true
		);
		\wp_enqueue_script(
			'anchor-courses-curriculum',
			Module::assets_url() . 'admin-curriculum.js',
			[ 'jquery', 'jquery-ui-sortable', 'anchor-courses-admin-common' ],
			Module::VERSION,
			true
		);
		\wp_localize_script(
			'anchor-courses-curriculum',
			'anchorCoursesCurriculum',
			[
				'ajaxUrl' => \admin_url( 'admin-ajax.php' ),
				'nonce'   => \wp_create_nonce( self::NONCE ),
				'strings' => [
					'newModule'     => \__( 'New module', 'anchor-schema' ),
					'moduleTitle'   => \__( 'Module title', 'anchor-schema' ),
					'removeModule'  => \__( 'Remove module', 'anchor-schema' ),
					'removeItem'    => \__( 'Remove', 'anchor-schema' ),
					'addLesson'     => \__( 'Add lesson', 'anchor-schema' ),
					'addQuiz'       => \__( 'Add quiz', 'anchor-schema' ),
					'createLesson'  => \__( 'Create lesson', 'anchor-schema' ),
					'createQuiz'    => \__( 'Create quiz', 'anchor-schema' ),
					'search'        => \__( 'Search by title...', 'anchor-schema' ),
					'required'      => \__( 'Required', 'anchor-schema' ),
					'noResults'     => \__( 'No matches.', 'anchor-schema' ),
					'confirmModule' => \__( 'Remove this module? The lessons and quizzes themselves are not deleted.', 'anchor-schema' ),
				],
			]
		);
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

		// An empty <select multiple> posts no `anchor_course[prerequisites]`
		// key at all, so save() cannot tell "clear everything" apart from
		// "this field wasn't in the form" and skips the meta update. This
		// sentinel guarantees the key is always present; sanitize_value()
		// already drops the empty string, so "nothing selected" stores [].
		echo '<input type="hidden" name="anchor_course[prerequisites][]" value="" />';
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
		if ( ! $this->authorized_to_save( $post_id, self::NONCE, Capabilities::cap( 'edit_courses' ) ) ) {
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

	public function render_curriculum( \WP_Post $post ): void {
		$modules = Curriculum::get( (int) $post->ID );

		echo '<div class="anchor-courses-curriculum" data-course="' . \esc_attr( (string) $post->ID ) . '">';
		echo '<ul class="anchor-courses-modules"></ul>';
		\printf(
			'<p><button type="button" class="button anchor-courses-add-module">%s</button></p>',
			\esc_html__( 'Add module', 'anchor-schema' )
		);
		\printf(
			'<input type="hidden" name="anchor_course_curriculum" class="anchor-courses-curriculum-data" value="%s" />',
			\esc_attr( (string) \wp_json_encode( $this->decorate( $modules ) ) )
		);
		echo '<noscript><p>' . \esc_html__( 'The curriculum builder needs JavaScript. Existing curriculum is preserved.', 'anchor-schema' ) . '</p></noscript>';
		echo '</div>';
	}

	/** Attach display titles so the builder renders without a second request. */
	private function decorate( array $modules ): array {
		foreach ( $modules as $m => $module ) {
			foreach ( $module['items'] as $i => $item ) {
				$modules[ $m ]['items'][ $i ]['title'] = (string) \get_the_title( $item['id'] );
			}
		}
		return $modules;
	}

	public function save_curriculum( int $post_id ): void {
		if ( ! $this->authorized_to_save( $post_id, self::NONCE, Capabilities::cap( 'edit_courses' ) ) ) {
			return;
		}
		if ( ! isset( $_POST['anchor_course_curriculum'] ) ) {
			return;
		}

		$decoded = \json_decode( \wp_unslash( (string) $_POST['anchor_course_curriculum'] ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		// A decode failure means a broken editor, not "the author deleted everything".
		if ( ! \is_array( $decoded ) ) {
			return;
		}

		Curriculum::save( $post_id, $decoded );
	}

	/** @return array<int,array{id:int,title:string,type:string}> */
	public static function search_items( string $term, string $type ): array {
		$map = [ 'lesson' => LessonPostType::CPT, 'quiz' => QuizPostType::CPT ];
		if ( ! isset( $map[ $type ] ) ) {
			return [];
		}

		$posts = \get_posts(
			[
				'post_type'      => $map[ $type ],
				'post_status'    => [ 'publish', 'draft', 'private', 'pending' ],
				's'              => $term,
				'posts_per_page' => 20,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			]
		);

		return \array_map(
			static fn( \WP_Post $p ): array => [
				'id'    => (int) $p->ID,
				'title' => (string) $p->post_title,
				'type'  => $type,
			],
			$posts
		);
	}

	/** @return array{id:int,title:string,type:string,edit_url:string}|array{} */
	public static function create_item( string $title, string $type ): array {
		$map = [ 'lesson' => LessonPostType::CPT, 'quiz' => QuizPostType::CPT ];
		if ( ! isset( $map[ $type ] ) ) {
			return [];
		}
		$cap = 'quiz' === $type ? 'edit_quizzes' : 'edit_lessons';
		if ( ! \current_user_can( Capabilities::cap( $cap ) ) ) {
			return [];
		}

		$title = \sanitize_text_field( $title );
		$id    = \wp_insert_post(
			[
				'post_type'   => $map[ $type ],
				'post_status' => 'draft',
				'post_title'  => '' === $title ? \__( 'Untitled', 'anchor-schema' ) : $title,
			],
			true
		);
		if ( \is_wp_error( $id ) ) {
			return [];
		}

		return [
			'id'       => (int) $id,
			'title'    => (string) \get_the_title( (int) $id ),
			'type'     => $type,
			'edit_url' => (string) \get_edit_post_link( (int) $id, 'raw' ),
		];
	}

	public function ajax_search_items(): void {
		\check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! \current_user_can( Capabilities::cap( 'edit_courses' ) ) ) {
			\wp_send_json_error( [ 'message' => \__( 'Not allowed.', 'anchor-schema' ) ], 403 );
		}
		$term = \sanitize_text_field( \wp_unslash( (string) ( $_REQUEST['term'] ?? '' ) ) );
		$type = \sanitize_key( \wp_unslash( (string) ( $_REQUEST['type'] ?? 'lesson' ) ) );
		\wp_send_json_success( self::search_items( $term, $type ) );
	}

	public function ajax_create_item(): void {
		\check_ajax_referer( self::NONCE, 'nonce' );
		$title  = \sanitize_text_field( \wp_unslash( (string) ( $_REQUEST['title'] ?? '' ) ) );
		$type   = \sanitize_key( \wp_unslash( (string) ( $_REQUEST['type'] ?? '' ) ) );
		$result = self::create_item( $title, $type );
		if ( [] === $result ) {
			\wp_send_json_error( [ 'message' => \__( 'Could not create that item.', 'anchor-schema' ) ], 400 );
		}
		\wp_send_json_success( $result );
	}

	/**
	 * The Course Role panel (design spec 3.1).
	 *
	 * Read-only except for one destructive action per role, which is why each
	 * is a POST with its own nonce and a confirm dialog rather than a link.
	 */
	public function render_role_panel( \WP_Post $post ): void {
		$course_id = (int) $post->ID;

		$this->role_row(
			$course_id,
			Roles::access_slug( $course_id ),
			\__( 'Access - holding this role IS enrolment.', 'anchor-schema' ),
			\__( 'Not created yet. It is minted when the course is published.', 'anchor-schema' )
		);

		$this->role_row(
			$course_id,
			Roles::completion_slug( $course_id ),
			\__( 'Completion - what another course or event can require.', 'anchor-schema' ),
			\__( 'Not created yet. It is minted the first time someone completes this course.', 'anchor-schema' )
		);
	}

	private function role_row( int $course_id, string $slug, string $blurb, string $absent ): void {
		\printf( '<p><code>%s</code><br /><span class="description">%s</span></p>', \esc_html( $slug ), \esc_html( $blurb ) );

		if ( ! Roles::exists( $slug ) ) {
			\printf( '<p>%s</p>', \esc_html( $absent ) );
			return;
		}

		$holders = Roles::holders( $slug );

		\printf(
			'<p>%s<br /><strong>%s</strong></p>',
			\esc_html( (string) ( \wp_roles()->roles[ $slug ]['name'] ?? $slug ) ),
			\esc_html(
				\sprintf(
					/* translators: %d: number of users holding the role. */
					\_n( '%d holder', '%d holders', $holders, 'anchor-schema' ),
					$holders
				)
			)
		);

		// This metabox body renders inside WordPress's own post-edit <form>,
		// so only the visible button prints here; the real admin-post <form>
		// (its own nonce + onsubmit confirm) prints from admin_footer, bound
		// by id (CodeRabbit PR #29 - a nested <form> is dropped by the
		// browser, and its own _wpnonce field can shadow the post form's).
		$form_id = 'anchor-courses-delete-role-' . $course_id . '-' . \sanitize_key( $slug );
		\printf(
			'<button type="submit" class="button button-link-delete" form="%s">%s</button>',
			\esc_attr( $form_id ),
			\esc_html__( 'Delete role', 'anchor-schema' )
		);

		MetaboxForms::queue_form(
			$form_id,
			'anchor_courses_delete_role',
			[ 'course_id' => (string) $course_id, 'role' => $slug ],
			'anchor_courses_delete_role_' . $course_id,
			\sprintf(
				'onsubmit="return confirm(%s);"',
				\esc_attr( (string) \wp_json_encode( \__( 'Delete this role and strip it from every holder? This cannot be undone.', 'anchor-schema' ) ) )
			)
		);
	}

	public function handle_delete_role(): void {
		$course_id = \absint( $_POST['course_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		$slug      = \sanitize_key( \wp_unslash( (string) ( $_POST['role'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification

		// A bad nonce and a missing capability both read `forbidden` here.
		if ( ! Notices::authorise( 'anchor_courses_delete_role_' . $course_id, 'manage' ) ) {
			Notices::redirect( 'forbidden', Notices::course_url( $course_id ) );
		}

		// Only this course's own two roles, so a crafted POST cannot delete
		// `administrator`.
		$allowed = [ Roles::access_slug( $course_id ), Roles::completion_slug( $course_id ) ];
		if ( ! \in_array( $slug, $allowed, true ) ) {
			Notices::redirect( 'error', Notices::course_url( $course_id ) );
		}

		Roles::delete_role( $slug );

		Notices::redirect( 'role_deleted', Notices::course_url( $course_id ) );
	}
}
