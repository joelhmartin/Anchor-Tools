<?php
declare(strict_types=1);

namespace Anchor\CheckoutQuestions\Services;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Prints "Include in admin email" answers into WooCommerce's New order email via
 * the shared Anchor_Questions_Email renderer. Reads the order's own snapshot, so
 * a resent email matches what was asked at the time, whatever happened to the set.
 */
final class OrderEmail {

	public const META_KEY = '_anchor_cq_answers';

	public function __construct() {
		// Events prints at 20; later priority keeps event groups first.
		\add_action( 'woocommerce_email_order_meta', [ $this, 'render' ], 30, 4 );
	}

	/** @param mixed $email WC_Email (only ->id is read). */
	public function render( $order, $sent_to_admin = false, $plain_text = false, $email = null ): void {
		if ( ! $order instanceof \WC_Order || ! \is_object( $email ) || ( $email->id ?? '' ) !== 'new_order' ) {
			return;
		}
		$stored = $order->get_meta( self::META_KEY );
		if ( ! \is_array( $stored ) ) {
			return;
		}
		$groups = [];
		foreach ( $stored as $set ) {
			if ( ! \is_array( $set ) ) {
				continue;
			}
			$questions = \is_array( $set['questions'] ?? null ) ? $set['questions'] : [];
			$answers   = \is_array( $set['answers'] ?? null ) ? $set['answers'] : [];
			$rows      = \Anchor_Questions_Email::rows_for( $questions, $answers );
			if ( $rows ) {
				$groups[] = [ 'heading' => (string) ( $set['set_title'] ?? '' ), 'entries' => [ [ 'name' => null, 'rows' => $rows ] ] ];
			}
		}
		if ( $groups ) {
			echo \Anchor_Questions_Email::render_order_groups( $groups, (bool) $plain_text ); // phpcs:ignore WordPress.Security.EscapeOutput -- renderer escapes HTML.
		}
	}
}
