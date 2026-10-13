<?php
/**
 * Console products list. Vars: $tabs, $tab, $s, $rows, $products, $page, $pages, $tab_url, $page_url, $action.
 *
 * @package Anchor\Shipping
 */

if ( ! \defined( 'ABSPATH' ) ) { exit; }
include __DIR__ . '/nav.php';
?>
<form class="asm-search" method="get" action="<?php echo \esc_url( $action ); ?>" role="search">
	<input type="hidden" name="tab" value="products">
	<label for="asm-search-input"><?php \esc_html_e( 'Search products', 'anchor-schema' ); ?></label>
	<input id="asm-search-input" type="search" name="s" value="<?php echo \esc_attr( $s ); ?>" placeholder="<?php \esc_attr_e( 'Name or SKU', 'anchor-schema' ); ?>">
	<button type="submit"><?php \esc_html_e( 'Search', 'anchor-schema' ); ?></button>
</form>

<?php if ( ! $rows ) : ?>
	<p class="asm-empty"><?php \esc_html_e( 'No products here.', 'anchor-schema' ); ?><?php if ( $page > 1 ) : ?> <a href="<?php echo \esc_url( $page_url( 1 ) ); ?>"><?php \esc_html_e( 'Back to page 1', 'anchor-schema' ); ?></a><?php endif; ?></p>
<?php else : ?>
	<table class="asm-products">
		<thead>
			<tr>
				<th scope="col"><?php \esc_html_e( 'Product', 'anchor-schema' ); ?></th>
				<th scope="col"><?php \esc_html_e( 'SKU', 'anchor-schema' ); ?></th>
				<th scope="col"><?php \esc_html_e( 'Price', 'anchor-schema' ); ?></th>
				<th scope="col"><?php \esc_html_e( 'Sale price', 'anchor-schema' ); ?></th>
				<th scope="col"><?php \esc_html_e( 'Stock', 'anchor-schema' ); ?></th>
				<th scope="col"><?php \esc_html_e( 'Weight', 'anchor-schema' ); ?></th>
				<th scope="col"><?php \esc_html_e( 'Default box', 'anchor-schema' ); ?></th>
				<th scope="col"><span class="screen-reader-text"><?php \esc_html_e( 'Save', 'anchor-schema' ); ?></span></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $rows as $product ) { echo $products->row( $product ); } // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the row template ?>
		</tbody>
	</table>

	<?php if ( $pages > 1 ) : ?>
		<nav class="asm-pager" aria-label="<?php \esc_attr_e( 'Pages', 'anchor-schema' ); ?>">
			<?php if ( $page > 1 ) : ?><a rel="prev" href="<?php echo \esc_url( $page_url( $page - 1 ) ); ?>"><?php \esc_html_e( 'Previous', 'anchor-schema' ); ?></a><?php endif; ?>
			<span><?php echo \esc_html( sprintf( /* translators: 1: page, 2: total pages */ \__( 'Page %1$d of %2$d', 'anchor-schema' ), $page, $pages ) ); ?></span>
			<?php if ( $page < $pages ) : ?><a rel="next" href="<?php echo \esc_url( $page_url( $page + 1 ) ); ?>"><?php \esc_html_e( 'Next', 'anchor-schema' ); ?></a><?php endif; ?>
		</nav>
	<?php endif; ?>
<?php endif; ?>
