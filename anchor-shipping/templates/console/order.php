<?php
/**
 * Console order view. Vars: $order, $back, $state, $tracking, $notes, $panel, $label_url, $label_pdf, $can_complete, $can_cancel.
 *
 * @package Anchor\Shipping
 */

if ( ! \defined( 'ABSPATH' ) ) { exit; }
?>
<p class="asm-back"><a href="<?php echo \esc_url( $back ); ?>">&larr; <?php \esc_html_e( 'Back to orders', 'anchor-schema' ); ?></a></p>

<article class="asm-order-view" data-order-id="<?php echo (int) $order->get_id(); ?>">
	<header class="asm-order-head">
		<h2><?php echo \esc_html( sprintf( /* translators: %s: order number */ \__( 'Order #%s', 'anchor-schema' ), $order->get_order_number() ) ); ?></h2>
		<p>
			<span class="asm-status"><?php echo \esc_html( \wc_get_order_status_name( $order->get_status() ) ); ?></span>
			<?php if ( '' !== $state ) : ?><span class="asm-state"><?php echo \esc_html( $state ); ?></span><?php endif; ?>
			<?php echo $order->get_date_created() ? \esc_html( \wc_format_datetime( $order->get_date_created() ) ) : ''; ?>
		</p>
		<p class="asm-actions" data-order="<?php echo (int) $order->get_id(); ?>">
			<?php if ( $can_complete ) : ?><button type="button" class="asm-status-btn" data-to="completed"><?php \esc_html_e( 'Mark completed', 'anchor-schema' ); ?></button><?php endif; ?>
			<?php if ( $can_cancel ) : ?><button type="button" class="asm-status-btn asm-danger" data-to="cancelled"><?php \esc_html_e( 'Cancel order', 'anchor-schema' ); ?></button><?php endif; ?>
			<span class="asm-status-error" role="alert"></span>
		</p>
	</header>

	<section class="asm-ship-to">
		<h3><?php \esc_html_e( 'Ship to', 'anchor-schema' ); ?></h3>
		<address><?php echo wp_kses_post( $order->get_formatted_shipping_address( \esc_html__( 'No shipping address', 'anchor-schema' ) ) ); ?></address>
		<?php if ( '' !== $order->get_billing_phone() ) : ?><p><?php echo \esc_html( $order->get_billing_phone() ); ?></p><?php endif; ?>
		<?php if ( '' !== $order->get_billing_email() ) : ?><p><a href="mailto:<?php echo \esc_attr( $order->get_billing_email() ); ?>"><?php echo \esc_html( $order->get_billing_email() ); ?></a></p><?php endif; ?>
		<?php if ( '' !== $order->get_customer_note() ) : ?><p class="asm-customer-note"><strong><?php \esc_html_e( 'Customer note:', 'anchor-schema' ); ?></strong> <?php echo \esc_html( $order->get_customer_note() ); ?></p><?php endif; ?>
	</section>

	<section class="asm-items">
		<h3><?php \esc_html_e( 'Items', 'anchor-schema' ); ?></h3>
		<table>
			<thead><tr><th scope="col"><?php \esc_html_e( 'Item', 'anchor-schema' ); ?></th><th scope="col"><?php \esc_html_e( 'Qty', 'anchor-schema' ); ?></th><th scope="col"><?php \esc_html_e( 'Total', 'anchor-schema' ); ?></th></tr></thead>
			<tbody>
				<?php foreach ( $order->get_items() as $item ) :
					$product = $item instanceof \WC_Order_Item_Product ? $item->get_product() : null;
					?>
					<tr>
						<th scope="row"><?php echo \esc_html( $item->get_name() ); ?><?php echo $product && '' !== $product->get_sku() ? ' <span class="asm-sub">' . \esc_html( $product->get_sku() ) . '</span>' : ''; ?></th>
						<td><?php echo (int) $item->get_quantity(); ?></td>
						<td><?php echo wp_kses_post( $order->get_formatted_line_subtotal( $item ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
			<tfoot><tr><th scope="row" colspan="2"><?php \esc_html_e( 'Order total', 'anchor-schema' ); ?></th><td><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></td></tr></tfoot>
		</table>
	</section>

	<section class="asm-label">
		<h3><?php \esc_html_e( 'Shipping label', 'anchor-schema' ); ?></h3>
		<?php echo $panel; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in OrderPanel::render_panel() ?>
		<?php if ( '' !== $label_url ) : ?>
			<p><a class="asm-print" target="_blank" href="<?php echo \esc_url( $label_url ); ?>"><?php \esc_html_e( 'Open latest label', 'anchor-schema' ); ?></a></p>
			<?php if ( $label_pdf ) : ?><iframe class="asm-label-frame" title="<?php \esc_attr_e( 'Latest shipping label', 'anchor-schema' ); ?>" src="<?php echo \esc_url( $label_url ); ?>" loading="lazy"></iframe><?php endif; ?>
		<?php endif; ?>
	</section>

	<?php if ( $tracking ) : ?>
		<section class="asm-tracking">
			<h3><?php \esc_html_e( 'Tracking', 'anchor-schema' ); ?></h3>
			<ul>
				<?php foreach ( $tracking as $t ) : ?>
					<li><?php echo \esc_html( $t['carrier'] ); ?> <?php echo '' !== $t['url'] ? '<a href="' . \esc_url( $t['url'] ) . '" target="_blank" rel="noopener">' . \esc_html( $t['number'] ) . '</a>' : \esc_html( $t['number'] ); ?><?php echo '' !== $t['status'] ? ' &mdash; ' . \esc_html( $t['status'] ) : ''; ?></li>
				<?php endforeach; ?>
			</ul>
		</section>
	<?php endif; ?>

	<section class="asm-notes">
		<h3><?php \esc_html_e( 'Order notes', 'anchor-schema' ); ?></h3>
		<?php if ( ! $notes ) : ?>
			<p><?php \esc_html_e( 'No notes.', 'anchor-schema' ); ?></p>
		<?php else : ?>
			<ul>
				<?php foreach ( $notes as $n ) : ?>
					<li class="asm-note<?php echo $n->customer_note ? ' is-customer' : ''; ?>"><span class="asm-sub"><?php echo \esc_html( \wc_format_datetime( $n->date_created ) ); ?></span> <?php echo \esc_html( \wp_strip_all_tags( $n->content ) ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</section>
</article>
