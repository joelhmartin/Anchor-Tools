<?php
declare(strict_types=1);

namespace Anchor\Courses\Integrations;

use Anchor\Courses\Content\CoursePostType;
use Anchor\Courses\Support\Capabilities;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * The WooCommerce adapter (brief 18, rule 2).
 *
 * Every mention of WooCommerce in this module lives in this file, and the class
 * is only constructed when class_exists('WooCommerce'). Removing WooCommerce
 * removes the adapter and nothing else.
 *
 * This task (38) is the mapping only: which course(s) a product grants, and the
 * admin UI that sets it. Tasks 39 (grant on a qualifying order status) and 40
 * (refund/cancellation policy) extend this same class - they will grant and
 * remove the course's ACCESS ROLE through `Support\Roles::grant_access()` /
 * `revoke_access()`; this class never writes an enrolment row itself.
 */
final class WooCommerce {

	/** Product meta: the courses this product grants. */
	public const META_KEY = '_anchor_course_ids';

	public const NONCE = 'anchor_courses_product_nonce';

	public function __construct() {
		\add_filter( 'woocommerce_product_data_tabs', [ $this, 'add_product_tab' ] );
		\add_action( 'woocommerce_product_data_panels', [ $this, 'render_product_panel' ] );
		\add_action( 'woocommerce_process_product_meta', [ $this, 'save_product' ] );

		// Turn the course page's "Ask us about access" line into a real link
		// when something on this store sells the course.
		\add_filter( 'anchor_courses_access_cta', [ $this, 'filter_access_cta' ], 10, 3 );
	}

	public static function available(): bool {
		return \class_exists( 'WooCommerce' );
	}

	/** @return int[] */
	public static function courses_for_product( int $product_id ): array {
		$stored = \get_post_meta( $product_id, self::META_KEY, true );
		if ( ! \is_array( $stored ) ) {
			return [];
		}
		return \array_values( \array_map( 'intval', $stored ) );
	}

	/**
	 * Store the mapping, keeping only ids that are really courses (any status - a mapping may be staged before the course publishes).
	 *
	 * @param int[] $course_ids
	 * @return int[] What was actually stored.
	 */
	public static function set_courses_for_product( int $product_id, array $course_ids ): array {
		$clean = [];

		foreach ( $course_ids as $course_id ) {
			$course_id = (int) $course_id;
			if ( $course_id <= 0 || \in_array( $course_id, $clean, true ) ) {
				continue;
			}
			if ( CoursePostType::CPT !== \get_post_type( $course_id ) ) {
				continue;
			}
			$clean[] = $course_id;
		}

		if ( [] === $clean ) {
			\delete_post_meta( $product_id, self::META_KEY );
		} else {
			\update_post_meta( $product_id, self::META_KEY, $clean );
		}

		return $clean;
	}

	/** `woocommerce_product_data_tabs`: add the "Courses" tab to the product data metabox. */
	public function add_product_tab( array $tabs ): array {
		$tabs['anchor_courses'] = [
			'label'    => \__( 'Courses', 'anchor-schema' ),
			'target'   => 'anchor_courses_product_data',
			'class'    => [],
			'priority' => 65,
		];
		return $tabs;
	}

	/** `woocommerce_product_data_panels`: the tab's panel, a multi-select of published/private courses. */
	public function render_product_panel(): void {
		global $post;

		$product_id = $post instanceof \WP_Post ? (int) $post->ID : 0;
		$selected   = self::courses_for_product( $product_id );

		echo '<div id="anchor_courses_product_data" class="panel woocommerce_options_panel">';
		\wp_nonce_field( self::NONCE, self::NONCE );
		echo '<div class="options_group">';
		echo '<p class="form-field"><label for="anchor_course_ids">' . \esc_html__( 'Grant these courses', 'anchor-schema' ) . '</label>';
		echo '<select id="anchor_course_ids" name="anchor_course_ids[]" multiple size="8" style="width:100%;max-width:26em;">';

		$courses = \get_posts(
			[
				'post_type'      => CoursePostType::CPT,
				'post_status'    => [ 'publish', 'private' ],
				'posts_per_page' => 200,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			]
		);

		foreach ( $courses as $course ) {
			\printf(
				'<option value="%d"%s>%s</option>',
				(int) $course->ID,
				\in_array( (int) $course->ID, $selected, true ) ? ' selected="selected"' : '',
				\esc_html( (string) $course->post_title )
			);
		}

		echo '</select>';
		echo '<span class="description">' . \esc_html__( 'Buyers are enrolled automatically when the order reaches a qualifying status.', 'anchor-schema' ) . '</span>';
		echo '</p></div></div>';
	}

	/** `woocommerce_process_product_meta`: save the mapping, gated by nonce + capability. */
	public function save_product( $product_id ): void {
		$product_id = (int) $product_id;

		$nonce = isset( $_POST[ self::NONCE ] ) ? \sanitize_text_field( \wp_unslash( (string) $_POST[ self::NONCE ] ) ) : '';
		if ( '' === $nonce || ! \wp_verify_nonce( $nonce, self::NONCE ) ) {
			return;
		}
		if ( ! \current_user_can( 'edit_post', $product_id ) && ! Capabilities::current_user_can( 'manage' ) ) {
			return;
		}

		$ids = isset( $_POST['anchor_course_ids'] ) && \is_array( $_POST['anchor_course_ids'] )
			? \wp_unslash( $_POST['anchor_course_ids'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			: [];

		self::set_courses_for_product( $product_id, (array) $ids );
	}

	/**
	 * Which products grant this course?
	 *
	 * The reverse of the mapping, by meta query. Purchasable products come
	 * first, so the course page links at something the visitor can actually
	 * buy rather than a draft or an out-of-stock variant.
	 *
	 * @return int[] Product ids.
	 */
	public static function products_for_course( int $course_id ): array {
		if ( ! self::available() || $course_id <= 0 ) {
			return [];
		}

		$products = \get_posts(
			[
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => 50,
				'no_found_rows'  => true,
				// The mapping is a serialised int[], so LIKE on the serialised
				// integer is the only index-friendly test. The exact id is
				// re-checked in PHP below, which is what makes a substring
				// match (12 inside 123) harmless.
				'meta_query'     => [
					[
						'key'     => self::META_KEY,
						'value'   => ';i:' . $course_id . ';',
						'compare' => 'LIKE',
					],
				],
			]
		);

		$matches = [];
		foreach ( $products as $product_id ) {
			if ( \in_array( $course_id, self::courses_for_product( (int) $product_id ), true ) ) {
				$matches[] = (int) $product_id;
			}
		}

		\usort(
			$matches,
			static function ( int $a, int $b ): int {
				$pa = \function_exists( 'wc_get_product' ) ? \wc_get_product( $a ) : null;
				$pb = \function_exists( 'wc_get_product' ) ? \wc_get_product( $b ) : null;
				$sa = ( $pa && $pa->is_purchasable() ) ? 0 : 1;
				$sb = ( $pb && $pb->is_purchasable() ) ? 0 : 1;
				return $sa <=> $sb;
			}
		);

		return $matches;
	}

	/**
	 * Supply the course page's access CTA (Task 18).
	 *
	 * An "Enrol" button appears on a course page for exactly one reason: a
	 * product on this store grants it. No product, no button - the visitor
	 * gets the "ask us" line instead, because there is nothing for them to
	 * click that would work.
	 *
	 * @param array{url:string,label:string,message:string} $cta
	 * @return array{url:string,label:string,message:string}
	 */
	public function filter_access_cta( array $cta, int $course_id, int $user_id = 0 ): array {
		$products = self::products_for_course( $course_id );
		if ( [] === $products ) {
			return $cta;
		}

		$permalink = (string) \get_permalink( $products[0] );
		if ( '' === $permalink ) {
			return $cta;
		}

		return [
			'url'     => $permalink,
			'label'   => \__( 'Enrol', 'anchor-schema' ),
			'message' => '',
		];
	}
}
