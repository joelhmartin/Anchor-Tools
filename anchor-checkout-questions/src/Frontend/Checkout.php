<?php
declare(strict_types=1);

namespace Anchor\CheckoutQuestions\Frontend;

use Anchor\CheckoutQuestions\Services\Matcher;
use Anchor\CheckoutQuestions\Support\QuestionSets;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Classic checkout: ask each applicable question set, validate required answers,
 * and snapshot questions + answers onto the order. The block checkout (Store API)
 * cannot render these fields, so a required set fails closed there.
 *
 * Answers are never logged (health information).
 */
class Checkout {

	public const ORDER_META = '_anchor_cq_answers';
	public const FIELD      = 'anchor_cq';

	public function __construct() {
		\add_action( 'woocommerce_after_order_notes', [ $this, 'render' ] );
		\add_action( 'woocommerce_after_checkout_validation', [ $this, 'validate' ], 10, 2 );
		\add_action( 'woocommerce_checkout_create_order', [ $this, 'store' ], 10, 2 );
		\add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ $this, 'guard_block_checkout' ], 10, 2 );
		// Older WooCommerce Blocks builds fire this hook instead.
		\add_action( 'woocommerce_blocks_checkout_order_processed', [ $this, 'guard_block_checkout' ], 10, 1 );
		\add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	/** Controls stylesheet, on the checkout page only and only when a set applies. */
	public function enqueue(): void {
		if ( ! \function_exists( 'is_checkout' ) || ! \is_checkout() || ! Matcher::for_cart() ) {
			return;
		}
		$file = \ANCHOR_TOOLS_PLUGIN_DIR . 'includes/questions/assets/questions-controls.css';
		\wp_enqueue_style(
			'anchor-questions-controls',
			\ANCHOR_TOOLS_PLUGIN_URL . 'includes/questions/assets/questions-controls.css',
			[],
			(string) @\filemtime( $file )
		);
	}

	public function render(): void {
		$posted = $this->posted();
		foreach ( Matcher::for_cart() as $set_id ) {
			$questions = QuestionSets::questions( $set_id );
			if ( ! $questions ) {
				continue;
			}
			$values = \Anchor_Questions::sanitize_answers( $questions, $posted[ $set_id ] ?? [] )['answers'];
			echo '<div class="anchor-cq-set">';
			echo '<h3>' . \esc_html( \get_the_title( $set_id ) ) . '</h3>';
			foreach ( $questions as $q ) {
				$req_mark = $q['required'] ? ' <abbr class="required" title="required">*</abbr>' : '';
				// A <div>, not a <p>: a multiselect is a <details>, which a <p> cannot contain.
				echo '<div class="form-row form-row-wide anchor-cq-question anchor-cq-question--' . \esc_attr( $q['type'] ) . '">';
				echo '<label>' . \esc_html( $q['label'] ) . $req_mark . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput -- $req_mark is a literal.
				echo \Anchor_Questions::render_control( $q, [ // phpcs:ignore WordPress.Security.EscapeOutput -- the renderer escapes.
					'name'           => self::FIELD . '[' . $set_id . '][' . $q['key'] . ']',
					'value'          => (string) ( $values[ $q['key'] ] ?? '' ),
					'class'          => self::control_class( $q['type'] ),
					'checkbox_label' => \__( 'Yes', 'anchor-schema' ),
				] );
				echo '</div>';
			}
			echo '</div>';
		}
	}

	/**
	 * @param array     $data   Posted checkout data (unused).
	 * @param \WP_Error $errors
	 */
	public function validate( $data, $errors ): void {
		$posted = $this->posted();
		foreach ( Matcher::for_cart() as $set_id ) {
			$missing = \Anchor_Questions::sanitize_answers( QuestionSets::questions( $set_id ), $posted[ $set_id ] ?? [] )['missing'];
			foreach ( $missing as $q ) {
				$errors->add(
					'validation',
					\sprintf(
						/* translators: %s: question label. */
						\__( '%s is a required field.', 'anchor-schema' ),
						'<strong>' . \esc_html( $q['label'] ) . '</strong>'
					)
				);
			}
		}
	}

	/**
	 * Snapshot every applicable set as asked (title + questions + answers), so a
	 * later edit or delete of the set never rewrites what the buyer was shown.
	 *
	 * @param \WC_Order $order
	 * @param mixed     $data
	 */
	public function store( $order, $data = [] ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$posted  = $this->posted();
		$entries = [];
		foreach ( Matcher::for_cart() as $set_id ) {
			$questions = QuestionSets::questions( $set_id );
			if ( ! $questions ) {
				continue;
			}
			$entries[] = [
				'set_id'    => $set_id,
				'set_title' => \html_entity_decode( (string) \get_post_field( 'post_title', $set_id ), ENT_QUOTES, 'UTF-8' ), // not get_the_title(): it texturizes, and WP stores &amp; in post_title; either would print as entities in the plain-text email.
				'questions' => $questions,
				'answers'   => \Anchor_Questions::sanitize_answers( $questions, $posted[ $set_id ] ?? [] )['answers'],
			];
		}
		if ( $entries ) {
			$order->update_meta_data( self::ORDER_META, $entries );
		}
	}

	/**
	 * Store API (block checkout) never shows these fields: refuse when a set with a
	 * required question applies, rather than take an order missing mandatory answers.
	 *
	 * @param mixed $order
	 * @param mixed $request
	 */
	public function guard_block_checkout( $order, $request = null ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$labels = [];
		foreach ( Matcher::for_order( $order ) as $set_id ) {
			foreach ( QuestionSets::questions( $set_id ) as $q ) {
				if ( ! empty( $q['required'] ) ) {
					$labels[] = (string) $q['label'];
				}
			}
		}
		if ( ! $labels ) {
			return;
		}
		if ( $this->store_api_exception_available() ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
				'anchor_cq_block_checkout',
				\esc_html__( 'This order has required questions that the block checkout cannot show. Please use the classic checkout.', 'anchor-schema' ),
				400
			);
		}
		// No exception class to refuse with: fail closed visibly instead. Hold the
		// order and leave a private note naming the unanswered questions (labels only).
		$order->add_order_note(
			\sprintf(
				/* translators: %s: comma-separated question labels. */
				\__( 'Placed through the block checkout without these required questions being asked: %s. Contact the buyer before fulfilling.', 'anchor-schema' ),
				\implode( ', ', $labels )
			)
		);
		$order->update_status( 'on-hold' );
		$order->save();
	}

	/** Test seam: the Store API exception class may be absent on old WooCommerce builds. */
	protected function store_api_exception_available(): bool {
		return \class_exists( '\\Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException' );
	}

	/** Woo's own field classes; none on a checkbox (Storefront would stretch it). */
	private static function control_class( string $type ): string {
		if ( $type === 'checkbox' ) {
			return '';
		}
		return $type === 'select' ? 'select' : 'input-text';
	}

	/** @return array<int|string,mixed> Raw posted answers keyed by set id. */
	private function posted(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC verifies the checkout nonce before these hooks run.
		$raw = $_POST[ self::FIELD ] ?? [];
		return \is_array( $raw ) ? \wp_unslash( $raw ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitize_answers() sanitizes.
	}
}
