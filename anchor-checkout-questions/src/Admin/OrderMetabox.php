<?php
declare(strict_types=1);

namespace Anchor\CheckoutQuestions\Admin;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Shows the answers stored on an order. Renders from the order's own snapshot
 * (labels, set title), never the live set, so edits and deletes do not rewrite history.
 */
final class OrderMetabox {

	public const BOX_ID   = 'anchor-cq-answers';
	public const META_KEY = '_anchor_cq_answers';

	public function __construct() {
		\add_action( 'add_meta_boxes', [ $this, 'register' ], 30 );
	}

	/** Only orders that actually hold answers get a box. */
	public function register(): void {
		$order = $this->current_order();
		if ( ! $order || ! self::answers( $order ) ) {
			return;
		}
		$screen = \function_exists( 'wc_get_page_screen_id' ) ? \wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		\add_meta_box( self::BOX_ID, \__( 'Checkout questions', 'anchor-schema' ), [ $this, 'render' ], $screen, 'normal', 'default' );
	}

	/** HPOS passes the order id as ?id=; legacy uses the global $post / ?post=. */
	private function current_order(): ?\WC_Order {
		$id = 0;
		if ( isset( $_GET['id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$id = (int) $_GET['id']; // phpcs:ignore WordPress.Security.NonceVerification
		} elseif ( isset( $GLOBALS['post']->ID ) ) {
			$id = (int) $GLOBALS['post']->ID;
		} elseif ( isset( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$id = (int) $_GET['post']; // phpcs:ignore WordPress.Security.NonceVerification
		}
		$order = $id > 0 ? \wc_get_order( $id ) : null;
		return $order instanceof \WC_Order ? $order : null;
	}

	/** @return array[] */
	private static function answers( \WC_Order $order ): array {
		$raw = $order->get_meta( self::META_KEY );
		return \is_array( $raw ) ? \array_values( \array_filter( $raw, 'is_array' ) ) : [];
	}

	public function render( $post_or_order ): void {
		$order = $post_or_order instanceof \WC_Order ? $post_or_order : \wc_get_order( $post_or_order->ID ?? 0 );
		$sets  = $order instanceof \WC_Order ? self::answers( $order ) : [];
		if ( ! $sets ) {
			echo '<p>' . \esc_html__( 'No checkout questions were answered on this order.', 'anchor-schema' ) . '</p>';
			return;
		}
		foreach ( $sets as $set ) {
			echo '<h4>' . \esc_html( (string) ( $set['set_title'] ?? '' ) ) . '</h4>';
			echo '<table class="widefat striped"><tbody>';
			$answers = \is_array( $set['answers'] ?? null ) ? $set['answers'] : [];
			foreach ( (array) ( $set['questions'] ?? [] ) as $q ) {
				$key   = (string) ( $q['key'] ?? '' );
				$value = \trim( (string) ( $answers[ $key ] ?? '' ) );
				echo '<tr><th style="width:35%">' . \esc_html( (string) ( $q['label'] ?? $key ) ) . '</th><td>'
					. ( '' === $value ? '—' : \nl2br( \esc_html( $value ) ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
	}
}
