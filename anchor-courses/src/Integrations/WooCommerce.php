<?php
declare(strict_types=1);

namespace Anchor\Courses\Integrations;

use Anchor\Courses\Content\CoursePostType;
use Anchor\Courses\Support\Accounts;
use Anchor\Courses\Support\Capabilities;
use Anchor\Courses\Support\Clock;
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

	/**
	 * Default bound on retry-on-publish: how many of the newest orders whose
	 * LINE ITEMS name a product mapped to the course are re-run (final review
	 * I6). Filterable via `anchor_courses_wc_retry_order_limit`.
	 */
	public const RETRY_ORDER_LIMIT = 500;

	public function __construct() {
		\add_filter( 'woocommerce_product_data_tabs', [ $this, 'add_product_tab' ] );
		\add_action( 'woocommerce_product_data_panels', [ $this, 'render_product_panel' ] );
		\add_action( 'woocommerce_process_product_meta', [ $this, 'save_product' ] );

		// Turn the course page's "Ask us about access" line into a real link
		// when something on this store sells the course.
		\add_filter( 'anchor_courses_access_cta', [ $this, 'filter_access_cta' ], 10, 3 );

		// Grant access the moment a qualifying order lands (Task 39, brief 18).
		\add_action( 'woocommerce_order_status_changed', [ $this, 'on_order_status_changed' ], 10, 4 );

		// Take it back on a refund/cancellation (Task 40, brief 18).
		\add_action( 'woocommerce_order_refunded', [ $this, 'on_order_refunded' ], 10, 2 );

		// Retry-on-publish (progress.md T39 ruling 2): Task 38's mapping
		// deliberately allows staging a draft/private course, so an order that
		// already paid while the course was unpublished never got the grant.
		// The moment the course publishes, re-run the grant for every
		// already-qualifying order whose lines map to it.
		\add_action( 'transition_post_status', [ $this, 'on_course_published' ], 10, 3 );

		// A course buyer must end up with a login (final review I2): when the
		// cart holds a mapped product, guest checkout is off and sign-up on.
		// Both the classic shortcode checkout and the block/Store API
		// checkout read these two through WC_Checkout, so one pair covers
		// both.
		\add_filter( 'woocommerce_checkout_registration_required', [ $this, 'require_registration_for_courses' ], 20 );
		\add_filter( 'woocommerce_checkout_registration_enabled', [ $this, 'require_registration_for_courses' ], 20 );

		// Snapshot the resolved course ids onto the order line at checkout
		// (PR36 finding b), before a later remap of the product/variation
		// mapping can change what this specific order already decided.
		\add_action( 'woocommerce_checkout_create_order_line_item', [ $this, 'snapshot_courses_for_line_item' ], 10, 4 );

		// A variation's own opt-out of its parent's mapping (PR36 finding c).
		\add_action( 'woocommerce_product_after_variable_attributes', [ $this, 'render_variation_fields' ], 10, 3 );
		\add_action( 'woocommerce_save_product_variation', [ $this, 'save_variation_courses' ], 10, 2 );
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
	 * Sentinel meta value: a variation explicitly grants NO courses,
	 * overriding its parent's mapping (PR36 finding c) - distinct from
	 * unmapped (meta absent), which INHERITS the parent's mapping
	 * (`courses_for_ids()` below). Only ever written to a variation; a
	 * simple/parent product has no parent to inherit from, so the
	 * distinction is moot there.
	 */
	public const NONE_OVERRIDE = 'none';

	/** Does this product/variation carry the explicit "no courses" override? */
	private static function has_none_override( int $product_id ): bool {
		return self::NONE_OVERRIDE === \get_post_meta( $product_id, self::META_KEY, true );
	}

	/**
	 * Store the mapping, keeping only ids that are really courses (any status - a mapping may be staged before the course publishes).
	 *
	 * @param int[] $course_ids
	 * @param bool  $override_empty When $course_ids is empty, store the
	 *                              explicit NONE_OVERRIDE sentinel instead of
	 *                              deleting the meta - "this variation grants
	 *                              nothing, do not inherit the parent" rather
	 *                              than "unmapped" (PR36 finding c).
	 * @return int[] What was actually stored (the sentinel itself is never
	 *               returned here - callers that care check
	 *               has_none_override() or courses_for_ids()).
	 */
	public static function set_courses_for_product( int $product_id, array $course_ids, bool $override_empty = false ): array {
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
			if ( $override_empty ) {
				\update_post_meta( $product_id, self::META_KEY, self::NONE_OVERRIDE );
			} else {
				\delete_post_meta( $product_id, self::META_KEY );
			}
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

	/** Course statuses a mapping may be staged against (Task 38: a mapping may exist before the course publishes). */
	public const MAPPABLE_STATUSES = [ 'publish', 'private', 'draft', 'pending', 'future' ];

	/**
	 * `woocommerce_product_data_panels`: the tab's panel, a multi-select of
	 * every mappable course. Unpublished ones are labelled with their status
	 * ("(draft)"), and every course already mapped is always offered - even
	 * past the list bound - so saving the product never drops a mapping
	 * merely because the course is not published (final review I4).
	 */
	public function render_product_panel(): void {
		global $post;

		$product_id = $post instanceof \WP_Post ? (int) $post->ID : 0;
		$selected   = self::courses_for_product( $product_id );

		echo '<div id="anchor_courses_product_data" class="panel woocommerce_options_panel">';
		\wp_nonce_field( self::NONCE, self::NONCE );
		echo '<div class="options_group">';
		echo '<p class="form-field"><label for="anchor_course_ids">' . \esc_html__( 'Grant these courses', 'anchor-schema' ) . '</label>';
		echo '<select id="anchor_course_ids" name="anchor_course_ids[]" multiple size="8" style="width:100%;max-width:26em;">';

		$query = [
			'post_type'      => CoursePostType::CPT,
			'post_status'    => self::MAPPABLE_STATUSES,
			'posts_per_page' => 200,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		];
		$courses = \get_posts( $query );

		$listed  = \array_map( static fn( $c ) => (int) $c->ID, $courses );
		$missing = \array_values( \array_diff( $selected, $listed ) );
		if ( [] !== $missing ) {
			$courses = \array_merge( $courses, \get_posts( [ 'post__in' => $missing, 'posts_per_page' => \count( $missing ) ] + $query ) );
		}

		foreach ( $courses as $course ) {
			$label = (string) $course->post_title;
			if ( 'publish' !== $course->post_status ) {
				$status = \get_post_status_object( $course->post_status );
				$label .= ' (' . \strtolower( $status ? (string) $status->label : (string) $course->post_status ) . ')';
			}

			\printf(
				'<option value="%d"%s>%s</option>',
				(int) $course->ID,
				\in_array( (int) $course->ID, $selected, true ) ? ' selected="selected"' : '',
				\esc_html( $label )
			);
		}

		echo '</select>';
		echo '<span class="description">' . \esc_html__( 'Buyers are enrolled automatically when the order reaches a qualifying status. An unpublished course is granted once it is published.', 'anchor-schema' ) . '</span>';
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

	/** Order-item meta key posted for the variation opt-out checkbox below. */
	private const VARIATION_NONE_FIELD = '_anchor_courses_variation_none';

	/**
	 * `woocommerce_product_after_variable_attributes`: the minimal per-
	 * variation field this task adds (PR36 finding c) - a single opt-out
	 * checkbox, not a full course picker. Setting specific courses on a
	 * variation (distinct from its parent's) stays code-only
	 * (`set_courses_for_product( $variation_id, [ ... ] )`), documented in
	 * COURSES.md; this checkbox only lets a variation say "grant nothing",
	 * which the multi-select on the parent panel has no way to express for
	 * one variation.
	 *
	 * @param int      $loop
	 * @param array    $variation_data
	 * @param \WP_Post $variation
	 */
	public function render_variation_fields( $loop, $variation_data, $variation ): void {
		$variation_id = (int) $variation->ID;
		$checked      = self::has_none_override( $variation_id );

		echo '<div class="form-row form-row-full anchor-courses-variation-override">';
		echo '<label><input type="checkbox" name="' . \esc_attr( self::VARIATION_NONE_FIELD . '[' . (int) $loop . ']' ) . '" value="1"'
			. \checked( $checked, true, false ) . ' /> '
			. \esc_html__( 'No courses (override the parent)', 'anchor-schema' ) . '</label>';
		echo '</div>';
	}

	/**
	 * `woocommerce_save_product_variation` (WooCommerce verifies the nonce
	 * for this hook itself - see anchor-events-manager's own use of it).
	 * Checking the box stores the NONE_OVERRIDE sentinel; unchecking it
	 * clears that sentinel back to unmapped (inherit the parent) - it never
	 * touches a real per-variation mapping set in code, since this panel has
	 * no field for one.
	 *
	 * @param int $variation_id
	 * @param int $loop
	 */
	public function save_variation_courses( $variation_id, $loop ): void {
		$variation_id = (int) $variation_id;
		// 'edit_products', not a per-post meta-cap check: the same capability
		// anchor-events-manager's own save_variation_link() gates on for this
		// same hook, and the one WC itself grants to shop_manager/admin.
		if ( ! \current_user_can( 'edit_products' ) ) {
			return;
		}

		$loop     = (int) $loop;
		$override = isset( $_POST[ self::VARIATION_NONE_FIELD ][ $loop ] ) && '1' === $_POST[ self::VARIATION_NONE_FIELD ][ $loop ]; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( $override ) {
			self::set_courses_for_product( $variation_id, [], true );
			return;
		}

		if ( self::has_none_override( $variation_id ) ) {
			\delete_post_meta( $variation_id, self::META_KEY );
		}
	}

	/**
	 * Which products grant this course?
	 *
	 * The reverse of the mapping, by meta query. A course mapped only on a
	 * `product_variation` is included by resolving to its PARENT product
	 * (PR36 finding d) - a visitor cannot add a bare variation to the cart,
	 * only the product it belongs to - deduplicated against any product that
	 * also matches directly. Purchasable products come first, so the course
	 * page links at something the visitor can actually buy rather than a
	 * draft or an out-of-stock variant.
	 *
	 * @return int[] Product ids.
	 */
	public static function products_for_course( int $course_id ): array {
		if ( ! self::available() || $course_id <= 0 ) {
			return [];
		}

		$posts = \get_posts(
			[
				'post_type'      => [ 'product', 'product_variation' ],
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
		foreach ( $posts as $post_id ) {
			$post_id = (int) $post_id;
			if ( ! \in_array( $course_id, self::courses_for_product( $post_id ), true ) ) {
				continue;
			}

			$product_id = 'product_variation' === \get_post_type( $post_id )
				? (int) \wp_get_post_parent_id( $post_id )
				: $post_id;

			if ( $product_id > 0 && ! \in_array( $product_id, $matches, true ) ) {
				$matches[] = $product_id;
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
		$to_status = \sanitize_key( (string) $to );

		if ( \in_array( $to_status, self::REVOKE_STATUSES, true ) ) {
			if ( 'refunded' === $to_status ) {
				// Only the line(s) actually refunded IN FULL lose their
				// course (PR36 finding a) - unlike cancelled/failed below,
				// `refunded` can follow a PARTIAL refund (a shop manager, or
				// some gateways, set the order to `refunded` by hand), and
				// the unconditional revoke_order() used to take back every
				// mapped line regardless of what was actually refunded.
				$order_obj = $order instanceof \WC_Order
					? $order
					: ( \function_exists( 'wc_get_order' ) ? \wc_get_order( (int) $order_id ) : null );
				if ( $order_obj instanceof \WC_Order ) {
					$this->revoke_refunded_lines( (int) $order_id, $order_obj );
				}
			} else {
				// Cancelled/failed carry no refund object at all, so this is
				// the only signal they ever give - the whole order's grants
				// go back, not only the lines a (nonexistent) refund would
				// name.
				$this->revoke_order( (int) $order_id );
			}
			return;
		}

		if ( ! \in_array( $to_status, self::qualifying_statuses(), true ) ) {
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
		if ( ! $order instanceof \WC_Order ) {
			return 0;
		}

		if ( self::is_marked_revoked( $order ) ) {
			// A refund already took access back from this order (Task 40); a
			// stray re-fire of a qualifying status - the same hazard Task 39
			// already guards enrol against - must not silently undo that.
			// clear_revoked_marker() is the way back in.
			return 0;
		}

		// Courses FIRST (final review C1): only an order that actually
		// carries a mapped course line may go on to resolve - and possibly
		// create - an account. A ticket-only or any other unmapped order is
		// none of this adapter's business, at any status. resolve_item_courses()
		// reads the line's own snapshot first (PR36 finding b) and, for a
		// line that still lacks one (a legacy order, or one created outside
		// checkout), persists whatever it resolves live right here - so a
		// later remap of the product can never change what THIS line grants
		// once a grant has been attempted against it.
		$lines = [];
		foreach ( $order->get_items() as $item ) {
			$courses = self::resolve_item_courses( $item, true );
			if ( [] !== $courses ) {
				$lines[] = $courses;
			}
		}
		if ( [] === $lines ) {
			return 0;
		}

		$user_id = self::account_for_order( $order, $order_id );
		if ( $user_id <= 0 ) {
			// A guest order with no resolvable/creatable account: progress
			// needs an identity, and account_for_order() already tried.
			Log::write( 'wc_order_no_customer', [ 'order' => $order_id ] );
			return 0;
		}

		$granted = 0;

		foreach ( $lines as $courses ) {
			foreach ( $courses as $course_id ) {
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
	 * The account this order ALREADY belongs to - its customer id, else an
	 * existing user with the billing email. Never creates one: this is what
	 * the revoke and refund-policy paths use (final review C1), because
	 * taking access back from somebody who has no account means there is
	 * nothing to take.
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

		$email = \method_exists( $order, 'get_billing_email' ) ? \sanitize_email( (string) $order->get_billing_email() ) : '';
		if ( '' === $email ) {
			return 0;
		}

		$user = \get_user_by( 'email', $email );
		return $user instanceof \WP_User ? (int) $user->ID : 0;
	}

	/**
	 * The user a COURSE order enrols, resolving or creating one for a guest
	 * order. Only enroll_order() calls this, and only once it has found a
	 * mapped course line (final review C1).
	 *
	 * Checkout already requires an account for a course cart
	 * (require_registration_for_courses()), so reaching the create branch
	 * means the order came from somewhere else - an admin-created order, the
	 * REST API, or a guest that slipped through. That buyer still needs a
	 * way in (final review I2), so:
	 *   - the order is linked to the account (set_customer_id + save), so
	 *     My Account shows it and later revokes find it directly;
	 *   - an account created JUST NOW gets WordPress's own set-password
	 *     notice (`wp_new_user_notification( $id, null, 'user' )`); an
	 *     account that already existed gets nothing.
	 *
	 * `anchor_courses_create_account` (Accounts::resolve()) receives the
	 * order as its context and may decline; that is logged and noted on the
	 * order, and nothing is granted.
	 */
	private static function account_for_order( \WC_Order $order, int $order_id ): int {
		$user_id = (int) $order->get_customer_id();
		if ( $user_id > 0 ) {
			return $user_id;
		}

		$email = (string) $order->get_billing_email();
		$name  = \trim( (string) $order->get_billing_first_name() . ' ' . (string) $order->get_billing_last_name() );

		$result = Accounts::resolve( $name, $email, $order );

		if ( $result['declined'] ) {
			Log::write( 'wc_account_declined', [ 'order' => $order_id ] );
			// One note per order, not one per status transition.
			if ( '' === (string) $order->get_meta( '_anchor_courses_wc_account_declined', true ) ) {
				$order->add_order_note(
					\__( 'account_not_created: this order grants a course, but the anchor_courses_create_account filter declined to create an account for the buyer, so no access was granted.', 'anchor-schema' )
				);
				$order->update_meta_data( '_anchor_courses_wc_account_declined', '1' );
				$order->save();
			}
			return 0;
		}

		$user_id = $result['user_id'];
		if ( $user_id <= 0 ) {
			return 0;
		}

		$order->set_customer_id( $user_id );
		$order->save();

		if ( $result['created'] ) {
			\wp_new_user_notification( $user_id, null, 'user' );
			Log::write( 'wc_account_created', [ 'order' => $order_id, 'user' => $user_id ] );
		}

		return $user_id;
	}

	/**
	 * `woocommerce_checkout_registration_required` and
	 * `woocommerce_checkout_registration_enabled`: true whenever the cart
	 * holds a product that grants a course (final review I2), so the buyer
	 * always leaves checkout with a login; otherwise the store's own setting
	 * passes through untouched.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	public function require_registration_for_courses( $value ) {
		return self::cart_has_course() ? true : $value;
	}

	/** Does the current cart hold a line that grants a course? Same resolver as orders. */
	public static function cart_has_course(): bool {
		if ( ! \function_exists( 'WC' ) ) {
			return false;
		}
		$cart = \WC()->cart;
		if ( ! $cart instanceof \WC_Cart ) {
			return false;
		}

		foreach ( $cart->get_cart() as $line ) {
			$courses = self::courses_for_ids( (int) ( $line['product_id'] ?? 0 ), (int) ( $line['variation_id'] ?? 0 ) );
			if ( [] !== $courses ) {
				return true;
			}
		}

		return false;
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

		return self::courses_for_ids( (int) $item->get_product_id(), $variation_id );
	}

	/**
	 * The one product -> courses resolver, shared by order lines and cart
	 * lines: the variation's own mapping wins when non-empty, else the
	 * parent product's.
	 *
	 * @return int[]
	 */
	private static function courses_for_ids( int $product_id, int $variation_id = 0 ): array {
		if ( $variation_id > 0 ) {
			if ( self::has_none_override( $variation_id ) ) {
				// The variation explicitly grants nothing (PR36 finding c) -
				// this is NOT "unmapped", so it must not fall through to the
				// parent's mapping below.
				return [];
			}
			$courses = self::courses_for_product( $variation_id );
			if ( [] !== $courses ) {
				return $courses;
			}
		}

		return $product_id > 0 ? self::courses_for_product( $product_id ) : [];
	}

	/**
	 * `woocommerce_checkout_create_order_line_item`: snapshot the line's
	 * live-resolved courses onto the order item itself, before checkout ever
	 * saves it (PR36 finding b) - nothing else can remap the product faster
	 * than this.
	 *
	 * Written UNCONDITIONALLY, even when it resolves to `[]` (PR36 round 2):
	 * a line that resolves to no courses - an unmapped product, or a
	 * variation's explicit NONE_OVERRIDE opt-out - is just as much a real
	 * decision as a non-empty one, and must be frozen the same way. Only
	 * skipping the empty case (the old behaviour) left such a line with NO
	 * marker at all, which resolve_item_courses() cannot tell apart from a
	 * line that predates snapshotting entirely - so a later remap of the
	 * product onto a real course would grant a course this specific line
	 * never sold. "No snapshot" (legacy) is meta ABSENT; "[] snapshot"
	 * (resolved to nothing, frozen) is meta present and an empty array -
	 * resolve_item_courses()'s `is_array()` check already tells the two
	 * apart correctly once this is always written.
	 *
	 * @param \WC_Order_Item_Product $item
	 * @param string                 $cart_item_key
	 * @param array                  $values
	 * @param \WC_Order              $order
	 */
	public function snapshot_courses_for_line_item( $item, $cart_item_key, $values, $order ): void {
		if ( ! \is_object( $item ) || ! \method_exists( $item, 'update_meta_data' ) ) {
			return;
		}

		$item->update_meta_data( self::COURSE_IDS_META, self::courses_for_item( $item ) );
	}

	/** Order-item meta: the course ids THIS line resolved to (PR36 finding b). */
	public const COURSE_IDS_META = '_anchor_courses_course_ids';

	/**
	 * Which courses does this order line grant - the line's own frozen
	 * snapshot first, falling back to the live product/variation mapping
	 * only when no snapshot exists (a legacy line placed before this
	 * snapshot existed, or an order created outside checkout - PR36 finding
	 * b). Everywhere an order line's grant is decided (enrol, retry-on-
	 * publish, revoke) reads through here instead of courses_for_item()
	 * directly, so a later remap - or an outright removal - of the
	 * product's mapping can never change what an order that already
	 * resolved its lines goes on to grant or revoke.
	 *
	 * @param mixed $item    A WC_Order_Item_Product.
	 * @param bool  $persist When true and no snapshot exists, a freshly
	 *                       resolved NON-EMPTY result is written back onto
	 *                       the item as its snapshot immediately (the "first
	 *                       qualifying grant" persistence point - only
	 *                       enroll_order() passes true).
	 * @return int[]
	 */
	private static function resolve_item_courses( $item, bool $persist = false ): array {
		if ( ! \is_object( $item ) || ! \method_exists( $item, 'get_meta' ) ) {
			return self::courses_for_item( $item );
		}

		$snapshot = $item->get_meta( self::COURSE_IDS_META, true );
		if ( \is_array( $snapshot ) ) {
			return \array_values( \array_map( 'intval', $snapshot ) );
		}

		$live = self::courses_for_item( $item );

		// Persist UNCONDITIONALLY, even when $live is `[]` (PR36 round 3,
		// Codex): an unmapped or opted-out line resolving to nothing is a
		// real decision, exactly like the checkout snapshot hook's own
		// unconditional write above - skipping the empty case here left a
		// non-checkout order's line with NO snapshot at all until its NEXT
		// qualifying status, by which point a remap could hand it a course
		// it never sold. The `[] !== $live` guard this used to carry is
		// gone on purpose.
		if ( $persist && \method_exists( $item, 'update_meta_data' ) && \method_exists( $item, 'save' ) ) {
			$item->update_meta_data( self::COURSE_IDS_META, $live );
			$item->save();
		}

		return $live;
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
	 * Orders are selected BY LINE ITEM (final review I6), not by scanning the
	 * store's newest orders, and by the UNION of two lookups (PR36 round 2,
	 * Codex): order_ids_for_products() finds orders whose items name a
	 * product or variation CURRENTLY mapped to this course; order_ids_by_
	 * snapshot() separately finds orders whose line already froze this
	 * course into its own `_anchor_courses_course_ids` snapshot, whatever
	 * the product maps to NOW - the case the first lookup alone misses: a
	 * course bought while still a draft, then its product remapped to
	 * something else entirely before it published, would otherwise never be
	 * found at all (the line's own resolve_item_courses() snapshot-first
	 * read below is what re-verifies each candidate either lookup finds).
	 * Both are newest first, each bounded to
	 * `anchor_courses_wc_retry_order_limit` (default RETRY_ORDER_LIMIT) - so
	 * the bound counts course orders only. Each candidate is then checked
	 * for a qualifying status and re-checked through resolve_item_courses()
	 * (a variation's own mapping can override its parent's; a snapshotted
	 * line is matched against ITS snapshot, not the live mapping). Hitting
	 * either bound is logged.
	 *
	 * @return int How many access roles were newly granted across all matching orders.
	 */
	public function retry_orders_for_course( int $course_id ): int {
		if ( ! \function_exists( 'wc_get_order' ) || $course_id <= 0 ) {
			return 0;
		}

		$limit = \max( 1, (int) \apply_filters( 'anchor_courses_wc_retry_order_limit', self::RETRY_ORDER_LIMIT, $course_id ) );

		$by_mapping  = self::order_ids_for_products( self::mapped_product_ids( $course_id ), $limit );
		$by_snapshot = self::order_ids_by_snapshot( $course_id, $limit );
		$order_ids   = \array_values( \array_unique( \array_merge( $by_mapping, $by_snapshot ) ) );

		if ( \count( $by_mapping ) >= $limit || \count( $by_snapshot ) >= $limit ) {
			// Not silently dropped: a course sold on more orders than the bound
			// needs a human to notice and, if it matters, resync by hand.
			Log::write( 'wc_retry_capped', [ 'course' => $course_id, 'limit' => $limit ] );
		}

		$statuses = self::qualifying_statuses();
		$granted  = 0;

		foreach ( $order_ids as $order_id ) {
			$order = \wc_get_order( $order_id );
			if ( ! $order instanceof \WC_Order || ! $order->has_status( $statuses ) ) {
				continue;
			}

			$matches = false;
			foreach ( $order->get_items() as $item ) {
				// resolve_item_courses() (PR36 finding b): a line that
				// already snapshotted its courses is matched against THAT,
				// not whatever the product maps to right now - the actual
				// grant enroll_order() makes below reads the same snapshot.
				if ( \in_array( $course_id, self::resolve_item_courses( $item ), true ) ) {
					$matches = true;
					break;
				}
			}
			if ( ! $matches ) {
				continue;
			}

			$granted += $this->enroll_order( $order_id );
		}

		return $granted;
	}

	/**
	 * Every product or variation - any status - whose mapping includes this
	 * course. Same serialised-int LIKE + exact PHP re-check as
	 * products_for_course(), without that method's publish/purchasable
	 * shaping: an order may have bought a product that is now a draft.
	 *
	 * @return int[]
	 */
	private static function mapped_product_ids( int $course_id ): array {
		$ids = \get_posts(
			[
				'post_type'      => [ 'product', 'product_variation' ],
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery
					[ 'key' => self::META_KEY, 'value' => ';i:' . $course_id . ';', 'compare' => 'LIKE' ],
				],
			]
		);

		return \array_values(
			\array_filter(
				\array_map( 'intval', $ids ),
				static fn( int $id ): bool => \in_array( $course_id, self::courses_for_product( $id ), true )
			)
		);
	}

	/**
	 * Newest order ids (at most $limit) with a line item for any of these
	 * products/variations.
	 *
	 * Reads WooCommerce's order ITEM tables (`woocommerce_order_items` +
	 * `woocommerce_order_itemmeta` `_product_id`/`_variation_id`), which both
	 * the posts and the HPOS order stores use, so this is HPOS-safe. Not
	 * `wc_order_product_lookup`: that analytics table is filled by a
	 * scheduled Action Scheduler import, so a just-paid order may not be in
	 * it yet. Refund ids can appear (refunds carry items too); the caller's
	 * instanceof WC_Order check drops them.
	 *
	 * @param int[] $product_ids
	 * @return int[]
	 */
	private static function order_ids_for_products( array $product_ids, int $limit ): array {
		global $wpdb;

		$product_ids = \array_values( \array_unique( \array_filter( \array_map( 'intval', $product_ids ) ) ) );
		if ( [] === $product_ids ) {
			return [];
		}

		// meta_value is text: compare as strings, so MySQL never casts every row.
		$in  = \implode( ',', \array_fill( 0, \count( $product_ids ), '%s' ) );
		$sql = "SELECT DISTINCT oi.order_id FROM {$wpdb->prefix}woocommerce_order_items oi
			INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta im ON im.order_item_id = oi.order_item_id
			WHERE oi.order_item_type = 'line_item'
			AND im.meta_key IN ('_product_id','_variation_id')
			AND im.meta_value IN ($in)
			ORDER BY oi.order_id DESC
			LIMIT %d";

		$ids = $wpdb->get_col( $wpdb->prepare( $sql, \array_merge( \array_map( 'strval', $product_ids ), [ $limit ] ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		return \array_map( 'intval', (array) $ids );
	}

	/**
	 * Newest order ids (at most $limit) whose LINE ITEM SNAPSHOT
	 * (`COURSE_IDS_META`) names this course - found even when the product's
	 * CURRENT mapping no longer does (PR36 round 2: a draft course bought,
	 * then its product remapped elsewhere before the course published, must
	 * still be found by publishing the ORIGINAL course).
	 *
	 * Same table, same serialised-int LIKE + exact PHP re-check pattern as
	 * order_ids_for_products()/mapped_product_ids(): the snapshot is a
	 * serialised int[] order-item meta value, so `;i:$course_id;` matches
	 * it exactly the way it matches the product mapping's own serialised
	 * int[]; retry_orders_for_course()'s own resolve_item_courses() loop is
	 * what re-verifies the exact value, so a substring false-positive here
	 * (12 inside 123) is harmless.
	 *
	 * @return int[]
	 */
	private static function order_ids_by_snapshot( int $course_id, int $limit ): array {
		global $wpdb;

		$sql = "SELECT DISTINCT oi.order_id FROM {$wpdb->prefix}woocommerce_order_items oi
			INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta im ON im.order_item_id = oi.order_item_id
			WHERE oi.order_item_type = 'line_item'
			AND im.meta_key = %s
			AND im.meta_value LIKE %s
			ORDER BY oi.order_id DESC
			LIMIT %d";

		$ids = $wpdb->get_col( $wpdb->prepare( $sql, self::COURSE_IDS_META, '%;i:' . $course_id . ';%', $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		return \array_map( 'intval', (array) $ids );
	}

	/* ---------------------------------------------------------------------
	 * Refund and cancellation (Task 40, brief 18, design spec 4).
	 * ------------------------------------------------------------------- */

	/** Order statuses that withdraw access. */
	public const REVOKE_STATUSES = [ 'refunded', 'cancelled', 'failed' ];

	/**
	 * Order meta: once a revoke has actually taken something back from this
	 * order, it is marked and enroll_order() refuses to re-grant from it - a
	 * stray duplicate status fire (the same "WooCommerce is known to fire
	 * twice" hazard Task 39 already works around for grants) must not
	 * silently undo a deliberate refund. A human clears it with
	 * clear_revoked_marker() before the order can grant again (progress.md
	 * T40 ruling 1; documented in COURSES.md).
	 *
	 * HPOS-safe: read and written only through WC_Order's own meta API
	 * ($order->update_meta_data()/get_meta()/save()), never
	 * update_post_meta()/get_post_meta() - those bypass HPOS entirely when
	 * orders are stored in the custom order tables.
	 */
	public const REVOKED_META = '_anchor_courses_wc_revoked';

	/**
	 * What a refund does to the ACCESS ROLE for one order/course pair.
	 *
	 * Default `remove_role` (design spec 4): the learner paid, then un-paid,
	 * so the thing the payment bought goes away. What that loss then means
	 * for their progress row is a separate, already-answered question:
	 * `anchor_courses_role_loss_policy` (default `keep`) is consulted inside
	 * Roles::revoke_access() exactly as it is for any other revocation - so
	 * the out-of-the-box behaviour is "access gone, progress preserved", and
	 * re-granting resumes the learner where they were.
	 *
	 * @return string remove_role|keep
	 */
	public static function refund_policy( int $order_id, int $course_id ): string {
		$user_id = 0;
		if ( \function_exists( 'wc_get_order' ) ) {
			$order   = \wc_get_order( $order_id );
			$user_id = $order ? self::customer_for_order( $order ) : 0;
		}

		/**
		 * Filter the refund policy for one order and course.
		 *
		 * @param string $policy    remove_role|keep. Default 'remove_role'.
		 * @param int    $order_id
		 * @param int    $course_id
		 * @param int    $user_id
		 */
		$policy = (string) \apply_filters( 'anchor_courses_wc_refund_policy', 'remove_role', $order_id, $course_id, $user_id );

		return \in_array( $policy, [ 'remove_role', 'keep' ], true ) ? $policy : 'remove_role';
	}

	/**
	 * `woocommerce_order_refunded`: fires for EVERY refund, full or partial,
	 * whether or not the refund also moves the order into a REVOKE_STATUSES
	 * status.
	 *
	 * Only the line(s) refunded IN FULL lose their course (progress.md T40
	 * ruling 3) - a partial refund of one item among several must not touch
	 * the others. A full refund happens to refund every line in full, so
	 * this one pass covers that case too; on_order_status_changed()'s revoke
	 * branch is still what handles cancelled/failed, which carry no refund
	 * object at all.
	 *
	 * @param int $order_id
	 * @param int $refund_id
	 */
	public function on_order_refunded( $order_id, $refund_id = 0 ): void {
		if ( ! \function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = \wc_get_order( (int) $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$this->revoke_refunded_lines( (int) $order_id, $order );
	}

	/**
	 * Take back the access THIS order granted - every mapped course, from
	 * every line, regardless of any refund record.
	 *
	 * The unconditional revoke: used for a whole-order REVOKE_STATUSES
	 * transition (cancelled/failed carry no refund object at all, so there
	 * is nothing else to check per line). A fully-refunded order's lines are
	 * covered the same way, redundantly and harmlessly, by
	 * on_order_refunded()'s per-line pass.
	 *
	 * @return int How many access roles were removed.
	 */
	public function revoke_order( int $order_id ): int {
		if ( ! \function_exists( 'wc_get_order' ) ) {
			return 0;
		}

		$order = \wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return 0;
		}

		return $this->revoke_items( $order, $order_id, \array_values( $order->get_items() ) );
	}

	/**
	 * The refund half of revocation: only a line refunded in full loses its
	 * course. Reads get_qty_refunded_for_item(), which is cumulative across
	 * every refund on the order (like the events module's own use of it), so
	 * a second partial refund that finally reaches the full quantity is
	 * picked up too, and a repeat pass over an already-fully-refunded line
	 * is a harmless no-op.
	 *
	 * When the order carries NO refund records at all (PR36 finding a) -
	 * `refunded` set by hand, or by a gateway that skips WooCommerce's own
	 * refund flow - get_qty_refunded_for_item() would read 0 for every line,
	 * which is indistinguishable from "nothing was refunded" and would
	 * revoke nothing. The order's own status is then the only fact
	 * available, and it says the whole order is refunded, so every mapped
	 * line is treated as fully refunded instead.
	 *
	 * The same blindness happens with a real refund RECORD that simply
	 * carries no line items - `wc_create_refund()` accepts a bare `amount`
	 * (PR36 round 2, Codex + CodeRabbit): the events module's own
	 * `reconcile_line()` recognises this exact shape as a valid, amount-only
	 * refund. get_qty_refunded_for_item() stays at 0 for every line in that
	 * case too, so a FULL amount-only refund - the order's cumulative
	 * refunded total already covers its total, which is also what makes
	 * WooCommerce itself flip the order to `refunded` - is treated the same
	 * as the no-refund-records case above: every mapped line is fully
	 * refunded. A PARTIAL amount-only refund (cumulative total still under
	 * the order's total) is a different story: there is no line quantity
	 * AND no full-order signal to attribute it to any one line, so it
	 * revokes nothing rather than guess (documented in COURSES.md).
	 *
	 * @return int How many access roles were removed.
	 */
	private function revoke_refunded_lines( int $order_id, \WC_Order $order ): int {
		$has_refund_records = \method_exists( $order, 'get_refunds' ) && [] !== $order->get_refunds();

		$order_total    = (float) $order->get_total();
		$total_refunded = $has_refund_records ? (float) $order->get_total_refunded() : 0.0;

		// A refund record with no line quantities leaves every per-item
		// check below at 0; once the cumulative refunded amount reaches the
		// order's total, treat every mapped line as fully refunded rather
		// than silently revoking nothing.
		$fully_refunded_by_amount = $has_refund_records
			&& $order_total > 0
			&& \round( $total_refunded, 2 ) >= \round( $order_total, 2 );

		$fully_refunded = [];

		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! \is_object( $item ) || ! \method_exists( $item, 'get_product_id' ) || (int) $item->get_product_id() <= 0 ) {
				continue;
			}

			if ( ! $has_refund_records || $fully_refunded_by_amount ) {
				$fully_refunded[] = $item;
				continue;
			}

			// get_qty_refunded_for_item() is negative (refund line items
			// carry a negative quantity); abs() the same way the events
			// module does (class-woocommerce.php reconcile_line()).
			$refunded = \abs( (int) $order->get_qty_refunded_for_item( (int) $item_id ) );
			if ( $refunded < (int) $item->get_quantity() ) {
				continue; // Not refunded in full (yet), and no amount-only signal either.
			}

			$fully_refunded[] = $item;
		}

		if ( [] === $fully_refunded ) {
			return 0;
		}

		return $this->revoke_items( $order, $order_id, $fully_refunded );
	}

	/**
	 * The shared core of both revoke paths: take back only what THIS order
	 * granted (progress.md T40 ruling 2).
	 *
	 * Matched through Roles::grant_record() - what the grants map says is
	 * held NOW - not the enrolment row's source, which keeps the FIRST
	 * source forever and never changes again (EnrollmentService docblock;
	 * Roles::GRANTS_META docblock: "Contract for revokers ... read
	 * grant_record() before revoke_access() and leave a `manual` grant in
	 * place"). Two consequences fall out of that, both load-bearing:
	 *
	 *   - A manual comp granted on top of a purchase survives the refund:
	 *     record_grant() upgrades a non-manual record to `manual` the
	 *     moment Roles::grant_access( ..., 'manual' ) runs against an
	 *     already-held role, so the record this method reads no longer
	 *     says `woocommerce`.
	 *   - When a second order grants a course the learner already holds
	 *     (from a first order), grant_access()'s "already in" branch still
	 *     calls record_grant() with the second order's source/source_id,
	 *     but record_grant() keeps the FIRST reason for anything but a
	 *     manual upgrade - so the grants map goes on crediting the first
	 *     order. Refunding the SECOND order is therefore correctly a no-op
	 *     here (its source_id never matched); refunding the FIRST order
	 *     still revokes the course even though the second order also paid
	 *     for it - the grants map holds only one record per course, so it
	 *     cannot know the second order would still justify access. That is
	 *     a known limit of the single-record grants map, not something this
	 *     task redesigns; see COURSES.md.
	 *
	 * @param \WC_Order $order
	 * @param int       $order_id
	 * @param array     $items Order items already resolved to the ones to check.
	 * @return int How many access roles were removed.
	 */
	private function revoke_items( \WC_Order $order, int $order_id, array $items ): int {
		$user_id = self::customer_for_order( $order );
		if ( $user_id <= 0 ) {
			return 0;
		}

		$revoked = 0;

		foreach ( $items as $item ) {
			// resolve_item_courses() (PR36 finding b): revoke what THIS
			// line's snapshot says it granted, not whatever the product
			// maps to now - removing the mapping entirely before a refund
			// must not leave the already-granted course un-revocable.
			foreach ( self::resolve_item_courses( $item ) as $course_id ) {
				$record = Roles::grant_record( $user_id, $course_id );
				if ( 'woocommerce' !== ( $record['source'] ?? '' ) || (string) $order_id !== ( $record['source_id'] ?? '' ) ) {
					continue; // Not this order's grant to take back (or already taken).
				}

				if ( 'keep' === self::refund_policy( $order_id, $course_id ) ) {
					continue;
				}

				if ( Roles::revoke_access( $user_id, $course_id, 'woocommerce', (string) $order_id ) ) {
					$revoked++;
				}
			}
		}

		if ( $revoked > 0 ) {
			self::mark_revoked( $order, $order_id, $revoked );
		}

		return $revoked;
	}

	/** Has a revoke already taken something back from this order? */
	private static function is_marked_revoked( \WC_Order $order ): bool {
		return '' !== (string) $order->get_meta( self::REVOKED_META, true );
	}

	/**
	 * Stamp the order-level marker and leave one note per revoke (not per
	 * course - "notes once", progress.md T40). HPOS-safe: WC_Order's own
	 * meta API, saved once together with the note.
	 */
	private static function mark_revoked( \WC_Order $order, int $order_id, int $count ): void {
		$order->update_meta_data( self::REVOKED_META, Clock::now() );

		if ( \method_exists( $order, 'add_order_note' ) ) {
			$order->add_order_note(
				\sprintf(
					/* translators: %d: number of courses whose access was revoked. */
					\_n(
						'Course access revoked: %d course this order granted was taken back.',
						'Course access revoked: %d courses this order granted were taken back.',
						$count,
						'anchor-schema'
					),
					$count
				)
			);
		}

		$order->save();

		Log::write( 'wc_order_revoked', [ 'order' => $order_id, 'count' => $count ] );
	}

	/**
	 * Clear the revoked marker so the order can grant again (progress.md T40
	 * ruling 1). No admin UI ships with this task; call it from WP-CLI or
	 * PHP - `WooCommerce::clear_revoked_marker( $order_id )` - then re-run
	 * the order through enroll_order() (or re-fire its qualifying status) to
	 * restore access. Documented in COURSES.md.
	 *
	 * @param \WC_Order|int $order
	 */
	public static function clear_revoked_marker( $order ): bool {
		if ( ! \is_object( $order ) ) {
			if ( ! \function_exists( 'wc_get_order' ) ) {
				return false;
			}
			$order = \wc_get_order( (int) $order );
		}
		if ( ! $order instanceof \WC_Order ) {
			return false;
		}

		$order->delete_meta_data( self::REVOKED_META );
		$order->save();

		return true;
	}
}
