<?php
declare(strict_types=1);

namespace Anchor\Shipping\Frontend;

use Anchor\Shipping\Admin\ProductFields;
use Anchor\Shipping\Support\Settings;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Shop Manager "Products" view: list, search and inline-edit price, sale price, stock, weight and default box.
 * All writes go through WC_Product setters + save(), never raw meta, so Woo keeps _price, stock status and caches right.
 */
final class ConsoleProducts {

	public const PER_PAGE = 50;

	/** Console access AND product-editing rights. */
	public static function can_use(): bool {
		return ShopManager::can_use() && \current_user_can( (string) \apply_filters( 'anchor_shipping_console_products_capability', 'edit_products' ) );
	}

	/**
	 * Top-level published products (simple + variable) for a page, each variable parent followed by its variations.
	 *
	 * @return array{0:\WC_Product[],1:int} rows and page count
	 */
	public function listing( string $search, int $page ): array {
		$page = max( 1, $page );
		$args = [ 'status' => 'publish', 'type' => [ 'simple', 'variable' ], 'orderby' => 'title', 'order' => 'ASC', 'limit' => self::PER_PAGE, 'paginate' => true, 'paged' => $page ];
		if ( '' !== $search ) {
			$ids = $this->search_ids( $search );
			if ( ! $ids ) {
				return [ [], 1 ];
			}
			$args['include'] = $ids;
		}
		$res  = \wc_get_products( $args );
		$rows = [];
		foreach ( (array) $res->products as $product ) {
			$rows[] = $product;
			if ( $product->is_type( 'variable' ) ) {
				foreach ( $product->get_children() as $child_id ) {
					$child = \wc_get_product( $child_id );
					if ( $child instanceof \WC_Product_Variation && 'publish' === $child->get_status() ) {
						$rows[] = $child;
					}
				}
			}
		}
		return [ $rows, max( 1, (int) $res->max_num_pages ) ];
	}

	/** Name or SKU match, with a matching variation promoted to its parent. @return int[] */
	private function search_ids( string $search ): array {
		$found = \WC_Data_Store::load( 'product' )->search_products( $search, '', true, true );
		$ids   = [];
		foreach ( (array) $found as $id ) {
			$product = \wc_get_product( (int) $id );
			if ( $product ) {
				$ids[] = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
			}
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Validates and saves one product or variation.
	 *
	 * @return string re-rendered table row
	 * @throws \RuntimeException with a user-facing message; nothing is saved in that case.
	 */
	public function save( array $post ): string {
		$product = \wc_get_product( (int) ( $post['product_id'] ?? 0 ) );
		if ( ! $product instanceof \WC_Product || ! ( $product->is_type( 'simple' ) || $product->is_type( 'variation' ) ) ) {
			throw new \RuntimeException( \__( 'Product not found, or it cannot be edited here. Variable products are edited through their variations.', 'anchor-schema' ) );
		}

		$regular = $this->price( $post['regular_price'] ?? '', \__( 'Price', 'anchor-schema' ) );
		$sale    = $this->price( $post['sale_price'] ?? '', \__( 'Sale price', 'anchor-schema' ) );
		if ( '' !== $sale && ( '' === $regular || (float) $sale >= (float) $regular ) ) {
			throw new \RuntimeException( \__( 'Sale price must be lower than the regular price. Clear it to end the sale.', 'anchor-schema' ) );
		}
		$weight = $this->number( $post['weight'] ?? '', \__( 'Weight', 'anchor-schema' ) );

		$box = \sanitize_key( (string) ( $post['box'] ?? '' ) );
		if ( '' !== $box && ! isset( Settings::boxes()[ $box ] ) ) {
			throw new \RuntimeException( \__( 'That box does not exist.', 'anchor-schema' ) );
		}

		$manage = ! empty( $post['manage_stock'] ) && '0' !== (string) $post['manage_stock'];
		if ( $manage ) {
			$qty_raw = \trim( (string) ( $post['stock_quantity'] ?? '' ) );
			if ( ! \preg_match( '/^\d+$/', $qty_raw ) ) {
				throw new \RuntimeException( \__( 'Stock quantity must be a whole number, zero or more.', 'anchor-schema' ) );
			}
		} else {
			$status = (string) ( $post['stock_status'] ?? 'instock' );
			if ( ! isset( \wc_get_product_stock_status_options()[ $status ] ) ) {
				throw new \RuntimeException( \__( 'Choose a valid stock status.', 'anchor-schema' ) );
			}
		}

		$product->set_regular_price( $regular );
		$product->set_sale_price( $sale );
		$product->set_weight( $weight );
		$product->update_meta_data( ProductFields::META, $box );
		if ( $manage ) {
			$product->set_manage_stock( true );
			$product->set_stock_quantity( (int) $qty_raw );
		} else {
			$product->set_manage_stock( false );
			$product->set_stock_quantity( null );
			$product->set_stock_status( $status );
		}
		$product->save();

		return $this->row( \wc_get_product( $product->get_id() ) );
	}

	/** Display name: parent name plus the chosen attributes for a variation. */
	public static function display_name( \WC_Product $product ): string {
		if ( $product instanceof \WC_Product_Variation ) {
			$parent = \wc_get_product( $product->get_parent_id() );
			$attrs  = \wc_get_formatted_variation( $product, true, false );
			return trim( ( $parent ? $parent->get_name() : '' ) . ( '' !== $attrs ? ' - ' . $attrs : '' ) );
		}
		return $product->get_name();
	}

	public function row( \WC_Product $product ): string {
		return ShopManager::render_template( 'product-row', [
			'product'  => $product,
			'name'     => self::display_name( $product ),
			'editable' => $product->is_type( 'simple' ) || $product->is_type( 'variation' ),
			'boxes'    => Settings::boxes(),
			'statuses' => \wc_get_product_stock_status_options(),
		] );
	}

	/** Non-negative decimal or empty. */
	private function number( $raw, string $label ): string {
		$raw = \trim( (string) $raw );
		if ( '' === $raw ) {
			return '';
		}
		if ( ! \preg_match( '/^\d*[.,]?\d+$/', $raw ) ) {
			/* translators: %s: field label */
			throw new \RuntimeException( \sprintf( \__( '%s must be a number, zero or more.', 'anchor-schema' ), $label ) );
		}
		return \wc_format_decimal( $raw );
	}

	private function price( $raw, string $label ): string {
		return $this->number( $raw, $label );
	}
}
