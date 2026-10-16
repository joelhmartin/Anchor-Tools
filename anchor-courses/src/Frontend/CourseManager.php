<?php
declare(strict_types=1);

namespace Anchor\Courses\Frontend;

use Anchor\Courses\Admin\CourseEditor;
use Anchor\Courses\Content\CoursePostType;
use Anchor\Courses\Module;
use Anchor\Courses\Support\Capabilities;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * `[anchor_course_manager]` - a front-end list of courses and a small settings
 * form (CE credits, certificate, availability) for the one a staff member picks.
 *
 * Saving goes through CourseEditor::save_settings(), the same writer the
 * wp-admin metabox uses, so sanitising lives in one place. It never touches
 * the post row itself.
 */
final class CourseManager {

	public const NONCE = 'anchor_course_manager';

	/** Query args this screen owns; a host (tab bar) strips them when switching away. */
	public const QUERY_ARGS = [ 'acm_course', 'acm_saved', 'acm_page' ];

	/** The only settings this form may write. Anything else posted is dropped. */
	public const FRONTEND_KEYS = [
		'ce_credits', 'ce_type', 'ce_provider_name', 'ce_provider_number', 'ce_expires_days',
		'certificate_enabled', 'certificate_template',
		'available_from', 'available_until', 'expiration_days',
		'instructor',
	];

	public function __construct() {
		\add_shortcode( 'anchor_course_manager', [ $this, 'render' ] );
		\add_action( 'template_redirect', [ $this, 'handle_save' ] );
	}

	/** The course being edited, or 0 for the list (absent, wrong type or trashed). */
	public static function current_course_id(): int {
		$id = isset( $_GET['acm_course'] ) ? \absint( \wp_unslash( $_GET['acm_course'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $id <= 0 ) {
			return 0;
		}
		$post = \get_post( $id );
		if ( ! $post instanceof \WP_Post || CoursePostType::CPT !== $post->post_type || 'trash' === $post->post_status ) {
			return 0;
		}
		return $id;
	}

	public function render( $atts = [] ): string {
		if ( ! \current_user_can( Capabilities::cap( 'edit_courses' ) ) ) {
			return '';
		}
		$this->enqueue();

		$id = self::current_course_id();
		if ( 0 === $id ) {
			return Templates::render( 'course-manager/list', [ 'courses' => $this->courses() ] );
		}

		$values = [];
		foreach ( self::FRONTEND_KEYS as $key ) {
			$values[ $key ] = CourseEditor::setting( $id, $key );
		}
		return Templates::render(
			'course-manager/edit',
			[
				'course' => \get_post( $id ),
				'values' => $values,
				'saved'  => isset( $_GET['acm_saved'] ), // phpcs:ignore WordPress.Security.NonceVerification
			]
		);
	}

	public function handle_save(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['acm_course'] ) ) {
			return;
		}
		$id = \absint( \wp_unslash( $_POST['acm_course'] ) );
		if ( $id <= 0 || CoursePostType::CPT !== \get_post_type( $id ) ) {
			return;
		}
		$nonce = isset( $_POST['_wpnonce'] ) ? (string) \wp_unslash( $_POST['_wpnonce'] ) : '';
		if ( ! \wp_verify_nonce( $nonce, self::NONCE ) || ! \current_user_can( Capabilities::cap( 'edit_courses' ), $id ) ) {
			return;
		}

		$posted = isset( $_POST['anchor_course_manager'] ) && \is_array( $_POST['anchor_course_manager'] )
			? \wp_unslash( $_POST['anchor_course_manager'] )
			: [];
		CourseEditor::save_settings( $id, \array_intersect_key( $posted, \array_flip( self::FRONTEND_KEYS ) ) );

		\wp_safe_redirect( \add_query_arg( [ 'acm_course' => $id, 'acm_saved' => 1 ], \wp_get_referer() ?: \home_url() ) );
		exit;
	}

	/** @return \WP_Post[] */
	private function courses(): array {
		return \get_posts(
			[
				'post_type'      => CoursePostType::CPT,
				'post_status'    => [ 'publish', 'draft', 'private', 'pending' ],
				'orderby'        => 'date',
				'order'          => 'DESC',
				'posts_per_page' => -1,
			]
		);
	}

	private function enqueue(): void {
		$file = Module::dir() . 'assets/course-manager.css';
		\wp_enqueue_style(
			'anchor-course-manager',
			Module::assets_url() . 'course-manager.css',
			[],
			\file_exists( $file ) ? (string) \filemtime( $file ) : Module::VERSION
		);
	}
}
