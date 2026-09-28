<?php
declare(strict_types=1);

namespace Anchor\Courses\Integrations;

use Anchor\Courses\Content\CoursePostType;
use Anchor\Courses\Support\Accounts;
use Anchor\Courses\Support\Capabilities;
use Anchor\Courses\Support\Log;
use Anchor\Courses\Support\Roles;

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

	/**
	 * Order statuses that grant access.
	 *
	 * Both `processing` and `completed` by default: this store confirms event
	 * tickets on `processing`, and a learner who has paid should not wait for a
	 * human to tick "completed" (design spec 4).
	 */
	public const ENROLL_STATUSES = [ 'processing', 'completed' ];

	/** Bound on the retry-on-publish order scan (progress.md T39 ruling 2). */
	public const RETRY_ORDER_LIMIT = 200;

	public function __construct() {
		\add_filter( 'woocommerce_product_data_tabs', [ $this, 'add_product_tab' ] );
		\add_action( 'woocommerce_product_data_panels', [ $this, 'render_product_panel' ] );
		\add_action( 'woocommerce_process_product_meta', [ $this, 'save_product' ] );

		// Turn the course page's "Ask us about access" line into a real link
		// when something on this store sells the course.
		\add_filter( 'anchor_courses_access_cta', [ $this, 'filter_access_cta' ], 10, 3 );

		// Grant access the moment a qualifying order lands (Task 39, brief 18).
		\add_action( 'woocommerce_order_status_changed', [ $this, 'on_order_status_changed' ], 10, 4 );

		// Retry-on-publish (progress.md T39 ruling 2): Task 38's mapping
		// deliberately allows staging a draft/private course, so an order that
		// already paid while the course was unpublished never got the grant.
		// The moment the course publishes, re-run the grant for every
		// already-qualifying order whose lines map to it.
		\add_action( 'transition_post_status', [ $this, 'on_course_published' ], 10, 3 );
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

	/* ---------------------------------------------------------------------
	 * Order-driven enrolment (Task 39, brief 18, design spec 4).
	 * ------------------------------------------------------------------- */

	/** @return string[] */
	public static function qualifying_statuses(): array {
		/**
		 * Filter the order statuses that grant course access.
		 *
		 * @param string[] $statuses
		 */
		$statuses = (array) \apply_filters( 'anchor_courses_wc_enroll_statuses', self::ENROLL_STATUSES );

		return \array_values( \array_filter( \array_map( 'sanitize_key', $statuses ) ) );
	}

	/** `woocommerce_order_status_changed`. */
	public function on_order_status_changed( $order_id, $from, $to, $order = null ): void {
		if ( ! \in_array( \sanitize_key( (string) $to ), self::qualifying_statuses(), true ) ) {
			return;
		}

		$this->enroll_order( (int) $order_id );
	}

	/**
	 * Give the order's customer the access role for every course its lines
	 * grant. Support\Roles' listener turns each grant into an enrolment row
	 * (source `woocommerce`, source_id the order id) - this method never
	 * writes an enrolment row itself, and never calls EnrollmentService::enroll()
	 * directly: `Roles::grant_access()` is the only enrol path (progress.md).
	 *
	 * Idempotent: a course the customer already holds the access role for is
	 * skipped, so a duplicate `woocommerce_order_status_changed` fire (or a
	 * later retry-on-publish pass) grants nothing new.
	 *
	 * @return int How many access roles were NEWLY granted.
	 */
	public function enroll_order( int $order_id ): int {
		if ( ! \function_exists( 'wc_get_order' ) ) {
			return 0;
		}

		$order = \wc_get_order( $order_id );
		if ( ! $order ) {
			return 0;
		}

		$user_id = self::customer_for_order( $order );
		if ( $user_id <= 0 ) {
			// A guest order with no resolvable/creatable account: progress
			// needs an identity, and Accounts::ensure_user() already tried.
			Log::write( 'wc_order_no_customer', [ 'order' => $order_id ] );
			return 0;
		}

		$granted = 0;

		foreach ( $order->get_items() as $item ) {
			if ( ! \is_object( $item ) || ! \method_exists( $item, 'get_product_id' ) ) {
				continue;
			}
			if ( (int) $item->get_product_id() <= 0 ) {
				continue;
			}

			foreach ( self::courses_for_item( $item ) as $course_id ) {
				if ( Roles::user_has( $user_id, Roles::access_slug( $course_id ) ) ) {
					continue; // Already has access: nothing new (brief 26).
				}

				// The grant - not enroll(). The role is the enrolment, and
				// grant_access() carries the source through to the listener.
				$result = Roles::grant_access( $user_id, $course_id, 'woocommerce', (string) $order_id );

				if ( \is_wp_error( $result ) ) {
					$code = (string) $result->get_error_code();

					Log::write( 'wc_grant_failed', [ 'order' => $order_id, 'course' => $course_id, 'code' => $code ] );

					// missing_prerequisite: the buyer does not hold a required
					// role yet. no_course: the mapped course is still
					// draft/private (Task 38 deliberately allows staging such a
					// mapping) - both are refusals a human should see on the
					// order (progress.md T39 ruling 1), and neither refunds,
					// cancels or fails the order: money already changed hands,
					// and that decision belongs to a shop manager, not this
					// adapter.
					$note_codes = [
						'missing_prerequisite' => 'blocked_prerequisite',
						'no_course'            => 'blocked_no_course',
					];

					if ( isset( $note_codes[ $code ] ) && \method_exists( $order, 'add_order_note' ) ) {
						$order->add_order_note(
							\sprintf(
								/* translators: 1: internal reason code, 2: course title, 3: human-readable reason. */
								\__( '%1$s: access to "%2$s" was not granted. %3$s', 'anchor-schema' ),
								$note_codes[ $code ],
								(string) \get_the_title( $course_id ),
								(string) $result->get_error_message()
							)
						);
					}

					continue;
				}

				$granted++;
			}
		}

		return $granted;
	}

	/**
	 * The user this order enrols. A guest order (no customer_id) resolves or
	 * creates an account from the billing details, exactly as the events
	 * module does for a seat with no attendee data of its own
	 * (`Entitlements::create_account()`) - `Accounts::ensure_user()` sends no
	 * welcome email, because staff/checkout added this person, they did not
	 * sign themselves up.
	 *
	 * @param mixed $order A WC_Order.
	 */
	public static function customer_for_order( $order ): int {
		if ( ! \is_object( $order ) || ! \method_exists( $order, 'get_customer_id' ) ) {
			return 0;
		}

		$user_id = (int) $order->get_customer_id();
		if ( $user_id > 0 ) {
			return $user_id;
		}

		if ( ! \method_exists( $order, 'get_billing_email' ) ) {
			return 0;
		}

		$email = (string) $order->get_billing_email();
		$first = \method_exists( $order, 'get_billing_first_name' ) ? (string) $order->get_billing_first_name() : '';
		$last  = \method_exists( $order, 'get_billing_last_name' ) ? (string) $order->get_billing_last_name() : '';
		$name  = \trim( $first . ' ' . $last );

		return Accounts::ensure_user( $name, $email );
	}

	/**
	 * Which courses does this order line grant?
	 *
	 * The variation's own mapping wins when it is non-empty; only when a
	 * variation has no mapping of its own do we fall back to its parent
	 * product's (progress.md T39 ruling 3) - a variation is free to grant
	 * nothing even when its parent grants something.
	 *
	 * @param mixed $item A WC_Order_Item_Product.
	 * @return int[]
	 */
	private static function courses_for_item( $item ): array {
		if ( ! \is_object( $item ) || ! \method_exists( $item, 'get_product_id' ) ) {
			return [];
		}

		$variation_id = \method_exists( $item, 'get_variation_id' ) ? (int) $item->get_variation_id() : 0;
		if ( $variation_id > 0 ) {
			$courses = self::courses_for_product( $variation_id );
			if ( [] !== $courses ) {
				return $courses;
			}
		}

		return self::courses_for_product( (int) $item->get_product_id() );
	}

	/**
	 * `transition_post_status`: retry-on-publish (progress.md T39 ruling 2).
	 *
	 * @param mixed $post A WP_Post.
	 */
	public function on_course_published( string $new_status, string $old_status, $post ): void {
		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}
		if ( ! $post instanceof \WP_Post || CoursePostType::CPT !== $post->post_type ) {
			return;
		}

		$this->retry_orders_for_course( (int) $post->ID );
	}

	/**
	 * Re-run the grant for every already-qualifying order whose lines map to
	 * this course. Idempotent (grant_access() no-ops for a role already held),
	 * which is what makes it safe to run on every publish, not only the first.
	 *
	 * Bounded to the most recent RETRY_ORDER_LIMIT qualifying orders: this is a
	 * retry for orders that paid while a mapped course was still draft/private,
	 * not an unbounded historical backfill.
	 *
	 * @return int How many access roles were newly granted across all matching orders.
	 */
	public function retry_orders_for_course( int $course_id ): int {
		if ( ! \function_exists( 'wc_get_orders' ) || $course_id <= 0 ) {
			return 0;
		}

		$order_ids = \wc_get_orders(
			[
				'status'  => self::qualifying_statuses(),
				'limit'   => self::RETRY_ORDER_LIMIT,
				'return'  => 'ids',
				'orderby' => 'date',
				'order'   => 'DESC',
			]
		);
		$order_ids = \is_array( $order_ids ) ? $order_ids : [];

		if ( \count( $order_ids ) >= self::RETRY_ORDER_LIMIT ) {
			// Not silently dropped: a store with more qualifying orders than the
			// bound needs a human to notice and, if it matters, resync by hand.
			Log::write( 'wc_retry_capped', [ 'course' => $course_id, 'limit' => self::RETRY_ORDER_LIMIT ] );
		}

		$granted = 0;

		foreach ( $order_ids as $order_id ) {
			$order = \wc_get_order( (int) $order_id );
			if ( ! $order ) {
				continue;
			}

			$matches = false;
			foreach ( $order->get_items() as $item ) {
				if ( \in_array( $course_id, self::courses_for_item( $item ), true ) ) {
					$matches = true;
					break;
				}
			}
			if ( ! $matches ) {
				continue;
			}

			$granted += $this->enroll_order( (int) $order_id );
		}

		return $granted;
	}
}
