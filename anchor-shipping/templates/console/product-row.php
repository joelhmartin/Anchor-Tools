<?php
/**
 * One products-table row (also returned by the save AJAX call). Vars: $product, $name, $editable, $boxes, $statuses.
 *
 * @package Anchor\Shipping
 */

if ( ! \defined( 'ABSPATH' ) ) { exit; }
$pid     = (int) $product->get_id();
$is_var  = $product instanceof \WC_Product_Variation;
$managed = true === $product->get_manage_stock( 'edit' );
$box     = (string) $product->get_meta( \Anchor\Shipping\Admin\ProductFields::META );
?>
<tr class="asm-product<?php echo $is_var ? ' is-variation' : ''; ?>" data-product-id="<?php echo $pid; ?>">
	<th scope="row"><?php echo \esc_html( $name ); ?><?php if ( ! $editable ) : ?> <span class="asm-sub"><?php \esc_html_e( 'Variable product: edit its variations', 'anchor-schema' ); ?></span><?php endif; ?></th>
	<td><?php echo \esc_html( $product->get_sku() ); ?></td>
<?php if ( ! $editable ) : ?>
	<td colspan="6"></td>
<?php else : ?>
	<td><input type="text" inputmode="decimal" data-field="regular_price" value="<?php echo \esc_attr( $product->get_regular_price( 'edit' ) ); ?>" aria-label="<?php \esc_attr_e( 'Price', 'anchor-schema' ); ?>" size="7"></td>
	<td><input type="text" inputmode="decimal" data-field="sale_price" value="<?php echo \esc_attr( $product->get_sale_price( 'edit' ) ); ?>" aria-label="<?php \esc_attr_e( 'Sale price', 'anchor-schema' ); ?>" size="7"></td>
	<td class="asm-stock<?php echo $managed ? ' is-managed' : ''; ?>">
		<label><input type="checkbox" data-field="manage_stock" value="1"<?php \checked( $managed ); ?>> <?php \esc_html_e( 'Track', 'anchor-schema' ); ?></label>
		<input type="number" min="0" step="1" data-field="stock_quantity" class="asm-stock-qty" value="<?php echo \esc_attr( (string) $product->get_stock_quantity( 'edit' ) ); ?>" aria-label="<?php \esc_attr_e( 'Stock quantity', 'anchor-schema' ); ?>">
		<select data-field="stock_status" class="asm-stock-status" aria-label="<?php \esc_attr_e( 'Stock status', 'anchor-schema' ); ?>">
			<?php foreach ( $statuses as $slug => $label ) : ?>
				<option value="<?php echo \esc_attr( (string) $slug ); ?>"<?php \selected( $product->get_stock_status( 'edit' ), $slug ); ?>><?php echo \esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
	</td>
	<td><input type="text" inputmode="decimal" data-field="weight" value="<?php echo \esc_attr( $product->get_weight( 'edit' ) ); ?>" aria-label="<?php echo \esc_attr( sprintf( /* translators: %s: weight unit */ \__( 'Weight (%s)', 'anchor-schema' ), \get_option( 'woocommerce_weight_unit' ) ) ); ?>" size="6"> <span class="asm-sub"><?php echo \esc_html( (string) \get_option( 'woocommerce_weight_unit' ) ); ?></span></td>
	<td>
		<select data-field="box" aria-label="<?php \esc_attr_e( 'Default box', 'anchor-schema' ); ?>">
			<option value=""><?php \esc_html_e( '— none —', 'anchor-schema' ); ?></option>
			<?php foreach ( $boxes as $id => $b ) : ?>
				<option value="<?php echo \esc_attr( (string) $id ); ?>"<?php \selected( $box, (string) $id ); ?>><?php echo \esc_html( (string) $b['name'] ); ?></option>
			<?php endforeach; ?>
		</select>
	</td>
	<td><button type="button" class="asm-product-save"><?php \esc_html_e( 'Save', 'anchor-schema' ); ?></button> <span class="asm-product-msg" role="status"></span></td>
<?php endif; ?>
</tr>
