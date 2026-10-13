<?php
/**
 * Console orders list. Vars: $tabs, $tab, $s, $rows, $page, $pages, $tab_url, $page_url, $action.
 *
 * @package Anchor\Shipping
 */

if ( ! \defined( 'ABSPATH' ) ) { exit; }
?>
<nav class="asm-tabs" aria-label="<?php \esc_attr_e( 'Order lists', 'anchor-schema' ); ?>">
	<ul>
		<?php foreach ( $tabs as $slug => $label ) : ?>
			<li class="asm-tab<?php echo $slug === $tab ? ' is-current' : ''; ?>"><a href="<?php echo \esc_url( $tab_url( $slug ) ); ?>"<?php echo $slug === $tab ? ' aria-current="page"' : ''; ?>><?php echo \esc_html( $label ); ?></a></li>
		<?php endforeach; ?>
	</ul>
</nav>

<form class="asm-search" method="get" action="<?php echo \esc_url( $action ); ?>" role="search">
	<input type="hidden" name="tab" value="<?php echo \esc_attr( $tab ); ?>">
	<label for="asm-search-input"><?php \esc_html_e( 'Search orders', 'anchor-schema' ); ?></label>
	<input id="asm-search-input" type="search" name="s" value="<?php echo \esc_attr( $s ); ?>" placeholder="<?php \esc_attr_e( 'Order number, email or last name', 'anchor-schema' ); ?>">
	<button type="submit"><?php \esc_html_e( 'Search', 'anchor-schema' ); ?></button>
</form>

<?php if ( ! $rows ) : ?>
	<p class="asm-empty"><?php \esc_html_e( 'No orders here.', 'anchor-schema' ); ?></p>
<?php else : ?>
	<table class="asm-orders">
		<thead>
			<tr>
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
					<th scope="row"><a href="<?php echo \esc_url( $r['url'] ); ?>">#<?php echo \esc_html( $o->get_order_number() ); ?></a></th>
					<td><?php echo \esc_html( $o->get_date_created() ? \wc_format_datetime( $o->get_date_created() ) : '' ); ?></td>
					<td>
						<?php echo \esc_html( trim( $o->get_shipping_first_name() . ' ' . $o->get_shipping_last_name() ) ); ?>
						<span class="asm-sub"><?php echo \esc_html( trim( $o->get_shipping_city() . ', ' . $o->get_shipping_state(), ', ' ) ); ?></span>
					</td>
					<td>
						<?php echo \esc_html( \wc_get_order_status_name( $o->get_status() ) ); ?>
						<?php if ( '' !== $r['state'] ) : ?><span class="asm-state asm-state-<?php echo \esc_attr( (string) $o->get_meta( '_anchor_shipping_state' ) ); ?>"><?php echo \esc_html( $r['state'] ); ?></span><?php endif; ?>
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

	<?php if ( $pages > 1 ) : ?>
		<nav class="asm-pager" aria-label="<?php \esc_attr_e( 'Pages', 'anchor-schema' ); ?>">
			<?php if ( $page > 1 ) : ?><a rel="prev" href="<?php echo \esc_url( $page_url( $page - 1 ) ); ?>"><?php \esc_html_e( 'Previous', 'anchor-schema' ); ?></a><?php endif; ?>
			<span><?php echo \esc_html( sprintf( /* translators: 1: page, 2: total pages */ \__( 'Page %1$d of %2$d', 'anchor-schema' ), $page, $pages ) ); ?></span>
			<?php if ( $page < $pages ) : ?><a rel="next" href="<?php echo \esc_url( $page_url( $page + 1 ) ); ?>"><?php \esc_html_e( 'Next', 'anchor-schema' ); ?></a><?php endif; ?>
		</nav>
	<?php endif; ?>
<?php endif; ?>
