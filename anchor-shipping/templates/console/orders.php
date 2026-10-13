<?php
/**
 * Console orders list. Vars: $tabs, $tab, $s, $rows, $page, $pages, $tab_url, $page_url, $action, $bulk (show order checkboxes).
 *
 * @package Anchor\Shipping
 */

if ( ! \defined( 'ABSPATH' ) ) { exit; }
?>
<?php include __DIR__ . '/nav.php'; ?>

<form class="asm-search" method="get" action="<?php echo \esc_url( $action ); ?>" role="search">
	<input type="hidden" name="tab" value="<?php echo \esc_attr( $tab ); ?>">
	<label for="asm-search-input"><?php \esc_html_e( 'Search orders', 'anchor-schema' ); ?></label>
	<input id="asm-search-input" type="search" name="s" value="<?php echo \esc_attr( $s ); ?>" placeholder="<?php \esc_attr_e( 'Order number, email or last name', 'anchor-schema' ); ?>">
	<button type="submit"><?php \esc_html_e( 'Search', 'anchor-schema' ); ?></button>
</form>

<?php if ( ! $rows ) : ?>
	<p class="asm-empty"><?php \esc_html_e( 'No orders here.', 'anchor-schema' ); ?><?php if ( $page > 1 ) : ?> <a href="<?php echo \esc_url( $page_url( 1 ) ); ?>"><?php \esc_html_e( 'Back to page 1', 'anchor-schema' ); ?></a><?php endif; ?></p>
<?php else : ?>
	<form class="asm-bulk-select" method="get" action="<?php echo \esc_url( $action ); ?>">
	<?php if ( ! empty( $bulk ) ) : ?><input type="hidden" name="bulk" value="1"><?php endif; ?>
	<table class="asm-orders">
		<thead>
			<tr>
				<?php if ( ! empty( $bulk ) ) : ?><th scope="col"><input type="checkbox" class="asm-bulk-all" aria-label="<?php \esc_attr_e( 'Select all orders on this page', 'anchor-schema' ); ?>"></th><?php endif; ?>
				<th scope="col"><?php \esc_html_e( 'Order', 'anchor-schema' ); ?></th>
				<th scope="col"><?php \esc_html_e( 'Date', 'anchor-schema' ); ?></th>
				<th scope="col"><?php \esc_html_e( 'Ship to', 'anchor-schema' ); ?></th>
				<th scope="col"><?php \esc_html_e( 'Status', 'anchor-schema' ); ?></th>
				<th scope="col"><?php \esc_html_e( 'Tracking', 'anchor-schema' ); ?></th>
				<th scope="col"><?php \esc_html_e( 'Total', 'anchor-schema' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $rows as $r ) :
				$o = $r['order'];
				?>
				<tr class="asm-order" data-order-id="<?php echo (int) $o->get_id(); ?>">
					<?php if ( ! empty( $bulk ) ) : ?><td><input type="checkbox" class="asm-bulk-pick" name="ids[]" value="<?php echo (int) $o->get_id(); ?>" aria-label="<?php echo \esc_attr( sprintf( /* translators: %s: order number */ \__( 'Select order %s', 'anchor-schema' ), $o->get_order_number() ) ); ?>"></td><?php endif; ?>
					<th scope="row"><a href="<?php echo \esc_url( $r['url'] ); ?>">#<?php echo \esc_html( $o->get_order_number() ); ?></a></th>
					<td><?php echo \esc_html( $o->get_date_created() ? \wc_format_datetime( $o->get_date_created() ) : '' ); ?></td>
					<td>
						<?php echo \esc_html( trim( $o->get_shipping_first_name() . ' ' . $o->get_shipping_last_name() ) ); ?>
						<span class="asm-sub"><?php echo \esc_html( trim( $o->get_shipping_city() . ', ' . $o->get_shipping_state(), ', ' ) ); ?></span>
					</td>
					<td>
						<?php echo \esc_html( \wc_get_order_status_name( $o->get_status() ) ); ?>
						<?php if ( '' !== $r['state'] ) : ?><span class="asm-state asm-state-<?php echo \esc_attr( (string) $o->get_meta( \Anchor\Shipping\Services\LabelService::STATE_META ) ); ?>"><?php echo \esc_html( $r['state'] ); ?></span><?php endif; ?>
					</td>
					<td>
						<?php foreach ( $r['tracking'] as $t ) : ?>
							<span class="asm-track"><?php echo '' !== $t['url'] ? '<a href="' . \esc_url( $t['url'] ) . '" target="_blank" rel="noopener">' . \esc_html( $t['number'] ) . '</a>' : \esc_html( $t['number'] ); ?><?php echo '' !== $t['status'] ? ' <span class="asm-sub">' . \esc_html( $t['status'] ) . '</span>' : ''; ?></span>
						<?php endforeach; ?>
					</td>
					<td><?php echo wp_kses_post( $o->get_formatted_order_total() ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php if ( ! empty( $bulk ) ) : ?><p class="asm-bulk-bar"><button type="submit" class="asm-bulk-go"><?php \esc_html_e( 'Create labels for selected', 'anchor-schema' ); ?></button></p><?php endif; ?>
	</form>

	<?php if ( $pages > 1 ) : ?>
		<nav class="asm-pager" aria-label="<?php \esc_attr_e( 'Pages', 'anchor-schema' ); ?>">
			<?php if ( $page > 1 ) : ?><a rel="prev" href="<?php echo \esc_url( $page_url( $page - 1 ) ); ?>"><?php \esc_html_e( 'Previous', 'anchor-schema' ); ?></a><?php endif; ?>
			<span><?php echo \esc_html( sprintf( /* translators: 1: page, 2: total pages */ \__( 'Page %1$d of %2$d', 'anchor-schema' ), $page, $pages ) ); ?></span>
			<?php if ( $page < $pages ) : ?><a rel="next" href="<?php echo \esc_url( $page_url( $page + 1 ) ); ?>"><?php \esc_html_e( 'Next', 'anchor-schema' ); ?></a><?php endif; ?>
		</nav>
	<?php endif; ?>
<?php endif; ?>
