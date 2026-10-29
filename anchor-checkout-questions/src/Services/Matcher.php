<?php
declare(strict_types=1);

namespace Anchor\CheckoutQuestions\Services;

use Anchor\CheckoutQuestions\Support\QuestionSets;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Decides which published question sets apply to a set of purchased lines.
 * Explicit product assignment always matches; category assignment matches
 * (including child categories) except for event-ticket lines, which collect
 * their own registration questions.
 */
final class Matcher {

	/** @return int[] Set ids for the current WooCommerce cart. */
	public static function for_cart(): array {
		$lines = [];
		if ( \function_exists( 'WC' ) && \WC()->cart ) {
			foreach ( \WC()->cart->get_cart() as $item ) {
				$lines[] = [
					'product_id'   => (int) ( $item['product_id'] ?? 0 ),
					'variation_id' => (int) ( $item['variation_id'] ?? 0 ),
				];
			}
		}
		return self::for_lines( $lines );
	}

	/** @return int[] Set ids for an order's product lines. */
	public static function for_order( \WC_Order $order ): array {
		$lines = [];
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof \WC_Order_Item_Product ) {
				$lines[] = [
					'product_id'   => (int) $item->get_product_id(),
					'variation_id' => (int) $item->get_variation_id(),
				];
			}
		}
		return self::for_lines( $lines );
	}

	/**
	 * @param array[] $lines Each ['product_id' => int, 'variation_id' => int].
	 * @return int[] Unique set ids in published_set_ids() order.
	 */
	public static function for_lines( array $lines ): array {
		$set_ids = QuestionSets::published_set_ids();
		if ( ! $set_ids || ! $lines ) {
			return [];
		}
		$matched = [];
		foreach ( $lines as $line ) {
			$product_id = (int) ( $line['product_id'] ?? 0 );
			if ( $product_id <= 0 ) {
				continue;
			}
			$cats = null; // Resolved lazily: only when a set has categories.
			foreach ( $set_ids as $set_id ) {
				if ( isset( $matched[ $set_id ] ) ) {
					continue;
				}
				if ( \in_array( $product_id, QuestionSets::product_ids( $set_id ), true ) ) {
					$matched[ $set_id ] = true;
					continue;
				}
				$set_cats = QuestionSets::category_ids( $set_id );
				if ( ! $set_cats ) {
					continue;
				}
				if ( null === $cats ) {
					$cats = self::is_event_ticket( $product_id, (int) ( $line['variation_id'] ?? 0 ) )
						? []
						: self::product_category_ids( $product_id );
				}
				if ( \array_intersect( $set_cats, $cats ) ) {
					$matched[ $set_id ] = true;
				}
			}
		}
		return \array_values( \array_filter( $set_ids, static fn( $id ) => isset( $matched[ $id ] ) ) );
	}

	/** The product's categories plus all their ancestors. @return int[] */
	private static function product_category_ids( int $product_id ): array {
		$terms = \wp_get_post_terms( $product_id, 'product_cat', [ 'fields' => 'ids' ] );
		if ( \is_wp_error( $terms ) ) {
			return [];
		}
		$all = [];
		foreach ( $terms as $term_id ) {
			$all[] = (int) $term_id;
			foreach ( \get_ancestors( (int) $term_id, 'product_cat', 'taxonomy' ) as $a ) {
				$all[] = (int) $a;
			}
		}
		return \array_values( \array_unique( $all ) );
	}

	private static function is_event_ticket( int $product_id, int $variation_id ): bool {
		if ( ! \function_exists( 'anchor_tools_is_module_enabled' ) || ! \anchor_tools_is_module_enabled( 'events_manager' ) ) {
			return false;
		}
		if ( ! \class_exists( '\Anchor\Events\Module', false ) ) {
			return false;
		}
		$events = \Anchor\Events\Module::instance();
		return $events && $events->product_is_event_ticket( $product_id, $variation_id );
	}
}
