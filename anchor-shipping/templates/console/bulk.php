<?php
/**
 * Bulk labels. Confirm form: $entries (order, url, blocked, box, weight, note), $boxes, $unit, $back.
 * After submit (AJAX): $result (created, skipped, failed, download, merge_error), $back.
 *
 * @package Anchor\Shipping
 */

if ( ! \defined( 'ABSPATH' ) ) { exit; }

$order_link = static function ( int $id ): string {
	$o = \wc_get_order( $id );
	return $o ? '<a href="' . \esc_url( \Anchor\Shipping\Support\Urls::order( $o ) ) . '">#' . \esc_html( $o->get_order_number() ) . '</a>' : '#' . (int) $id;
};
?>
<?php if ( isset( $result ) ) : ?>
	<div class="asm-bulk-result">
		<h2><?php \esc_html_e( 'Labels', 'anchor-schema' ); ?></h2>
		<?php if ( $result['created'] ) : ?>
			<p class="asm-bulk-ok"><?php echo \esc_html( sprintf( /* translators: %d: number of orders */ \_n( 'Created labels for %d order.', 'Created labels for %d orders.', count( $result['created'] ), 'anchor-schema' ), count( $result['created'] ) ) ); ?>
				<?php if ( ! empty( $result['download'] ) ) : ?><a class="asm-bulk-download" href="<?php echo \esc_url( $result['download'] ); ?>" target="_blank" rel="noopener"><?php \esc_html_e( 'Open all labels', 'anchor-schema' ); ?></a><?php endif; ?></p>
			<?php if ( ! empty( $result['merge_error'] ) ) : ?><p class="asm-error"><?php echo \esc_html( sprintf( /* translators: %s: error */ \__( 'The labels were created but could not be combined into one file (%s). Print them from each order.', 'anchor-schema' ), $result['merge_error'] ) ); ?></p><?php endif; ?>
			<ul class="asm-bulk-created">
				<?php foreach ( $result['created'] as $id => $row_ids ) : ?><li><?php echo $order_link( (int) $id ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in $order_link ?></li><?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<?php if ( $result['skipped'] ) : ?>
			<h3><?php \esc_html_e( 'Skipped', 'anchor-schema' ); ?></h3>
			<ul class="asm-bulk-skipped">
				<?php foreach ( $result['skipped'] as $id => $why ) : ?><li><?php echo $order_link( (int) $id ); // phpcs:ignore WordPress.Security.EscapeOutput ?> &mdash; <?php echo \esc_html( $why ); ?></li><?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<?php if ( $result['failed'] ) : ?>
			<h3><?php \esc_html_e( 'Failed', 'anchor-schema' ); ?></h3>
			<ul class="asm-bulk-failed">
				<?php foreach ( $result['failed'] as $id => $why ) : ?><li><?php echo $order_link( (int) $id ); // phpcs:ignore WordPress.Security.EscapeOutput ?> &mdash; <?php echo \esc_html( $why ); ?></li><?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<?php if ( ! empty( $back ) ) : ?><p><a href="<?php echo \esc_url( $back ); ?>"><?php \esc_html_e( 'Back to orders', 'anchor-schema' ); ?></a></p><?php endif; ?>
	</div>
<?php else : ?>
	<div class="asm-bulk">
		<h2><?php \esc_html_e( 'Create labels', 'anchor-schema' ); ?></h2>
		<?php if ( ! $entries ) : ?>
			<p class="asm-empty"><?php \esc_html_e( 'No orders selected.', 'anchor-schema' ); ?></p>
		<?php else : ?>
			<form class="asm-bulk-form">
				<table class="asm-orders">
					<thead>
						<tr>
							<th scope="col"><?php \esc_html_e( 'Order', 'anchor-schema' ); ?></th>
							<th scope="col"><?php \esc_html_e( 'Ship to', 'anchor-schema' ); ?></th>
							<th scope="col"><?php \esc_html_e( 'Box', 'anchor-schema' ); ?></th>
							<th scope="col"><?php echo \esc_html( sprintf( /* translators: %s: weight unit */ \__( 'Weight (%s)', 'anchor-schema' ), $unit ) ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $entries as $e ) :
							$o = $e['order'];
							?>
							<tr class="asm-bulk-row<?php echo '' !== $e['blocked'] ? ' is-blocked' : ''; ?>" data-order-id="<?php echo (int) $o->get_id(); ?>">
								<th scope="row"><a href="<?php echo \esc_url( $e['url'] ); ?>">#<?php echo \esc_html( $o->get_order_number() ); ?></a></th>
								<td><?php echo \esc_html( trim( $o->get_shipping_first_name() . ' ' . $o->get_shipping_last_name() ) ); ?>
									<span class="asm-sub"><?php echo \esc_html( trim( $o->get_shipping_city() . ', ' . $o->get_shipping_state(), ', ' ) ); ?></span></td>
								<?php if ( '' !== $e['blocked'] ) : ?>
									<td colspan="2"><?php echo \esc_html( $e['blocked'] ); ?></td>
								<?php else : ?>
									<td>
										<select data-field="box" aria-label="<?php \esc_attr_e( 'Box', 'anchor-schema' ); ?>">
											<?php foreach ( $boxes as $bid => $b ) : ?><option value="<?php echo \esc_attr( (string) $bid ); ?>" <?php \selected( $e['box'], (string) $bid ); ?>><?php echo \esc_html( $b['name'] ); ?></option><?php endforeach; ?>
											<option value="custom" <?php \selected( ! $boxes || '' === $e['box'] ); ?>><?php \esc_html_e( 'Custom size…', 'anchor-schema' ); ?></option>
										</select>
										<span class="asm-bulk-custom">
											<input type="text" size="3" data-field="length" placeholder="<?php \esc_attr_e( 'L', 'anchor-schema' ); ?>" aria-label="<?php \esc_attr_e( 'Length', 'anchor-schema' ); ?>">
											<input type="text" size="3" data-field="width" placeholder="<?php \esc_attr_e( 'W', 'anchor-schema' ); ?>" aria-label="<?php \esc_attr_e( 'Width', 'anchor-schema' ); ?>">
											<input type="text" size="3" data-field="height" placeholder="<?php \esc_attr_e( 'H', 'anchor-schema' ); ?>" aria-label="<?php \esc_attr_e( 'Height', 'anchor-schema' ); ?>">
										</span>
										<?php if ( '' !== $e['note'] ) : ?><span class="asm-sub"><?php echo \esc_html( $e['note'] ); ?></span><?php endif; ?>
									</td>
									<td><input type="text" size="5" data-field="weight" value="<?php echo \esc_attr( $e['weight'] ); ?>" aria-label="<?php \esc_attr_e( 'Weight', 'anchor-schema' ); ?>"></td>
								<?php endif; ?>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="asm-bulk-bar">
					<button type="submit" class="asm-bulk-create"><?php \esc_html_e( 'Create labels', 'anchor-schema' ); ?></button>
					<?php if ( ! empty( $back ) ) : ?><a href="<?php echo \esc_url( $back ); ?>"><?php \esc_html_e( 'Cancel', 'anchor-schema' ); ?></a><?php endif; ?>
					<span class="asm-bulk-msg" role="status"></span>
				</p>
			</form>
		<?php endif; ?>
	</div>
<?php endif; ?>
