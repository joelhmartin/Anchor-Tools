<?php
/**
 * Console order view. Vars: $order, $back, $state, $notes, $panel, $label_url, $label_pdf, $can_complete, $can_cancel.
 *
 * Two independent columns (.asm-order-main / .asm-order-side) so a tall label preview never
 * pushes the notes down. Tracking lives in the label panel's rows, not a separate card.
 *
 * @package Anchor\Shipping
 */

if ( ! \defined( 'ABSPATH' ) ) { exit; }
?>
<p class="asm-back"><a href="<?php echo \esc_url( $back ); ?>">&larr; <?php \esc_html_e( 'Back to orders', 'anchor-schema' ); ?></a></p>

<article class="asm-order-view" data-order-id="<?php echo (int) $order->get_id(); ?>">
	<header class="asm-order-head">
		<div class="asm-order-title">
			<h2><?php echo \esc_html( sprintf( /* translators: %s: order number */ \__( 'Order #%s', 'anchor-schema' ), $order->get_order_number() ) ); ?></h2>
			<span class="asm-status asm-status-<?php echo \esc_attr( $order->get_status() ); ?>"><?php echo \esc_html( \wc_get_order_status_name( $order->get_status() ) ); ?></span>
		</div>
		<p class="asm-order-meta">
			<?php if ( '' !== $state ) : ?><span class="asm-state"><?php echo \esc_html( $state ); ?></span><?php endif; ?>
			<?php echo $order->get_date_created() ? \esc_html( \wc_format_datetime( $order->get_date_created() ) ) : ''; ?>
		</p>
		<p class="asm-admin-link"><a href="<?php echo \esc_url( $order->get_edit_order_url() ); ?>"><?php \esc_html_e( 'Open in WordPress admin', 'anchor-schema' ); ?></a></p>
		<p class="asm-actions" data-order="<?php echo (int) $order->get_id(); ?>" data-status="<?php echo \esc_attr( $order->get_status() ); ?>">
			<?php if ( $can_complete ) : ?><button type="button" class="asm-status-btn" data-to="completed"><?php \esc_html_e( 'Mark completed', 'anchor-schema' ); ?></button><?php endif; ?>
			<?php if ( $can_cancel ) : ?><button type="button" class="asm-status-btn asm-danger" data-to="cancelled"><?php \esc_html_e( 'Cancel order', 'anchor-schema' ); ?></button><?php endif; ?>
			<span class="asm-status-error" role="alert"></span>
		</p>
	</header>

	<div class="asm-order-main">
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
			<tfoot>
				<?php // Woo's own breakdown (subtotal, discount, shipping, fees such as insurance/signature, payment method, total). ?>
				<?php foreach ( $order->get_order_item_totals() as $key => $row ) : ?>
					<tr class="asm-total asm-total-<?php echo \esc_attr( $key ); ?>"><th scope="row" colspan="2"><?php echo \esc_html( rtrim( \wp_strip_all_tags( $row['label'] ), ':' ) ); ?></th><td><?php echo wp_kses_post( $row['value'] ); ?></td></tr>
				<?php endforeach; ?>
			</tfoot>
		</table>
	</section>

	<section class="asm-notes">
		<h3><?php \esc_html_e( 'Order notes', 'anchor-schema' ); ?></h3>
		<?php if ( ! $notes ) : ?>
			<p><?php \esc_html_e( 'No notes.', 'anchor-schema' ); ?></p>
		<?php else : ?>
			<ul>
				<?php foreach ( $notes as $n ) : ?>
					<li class="asm-note<?php echo $n->customer_note ? ' is-customer' : ''; ?>"><span class="asm-sub"><?php echo \esc_html( \wc_format_datetime( $n->date_created ) ); ?></span> <?php echo $n->customer_note ? '<strong class="asm-to-customer">' . \esc_html__( 'To customer:', 'anchor-schema' ) . '</strong> ' : ''; ?><?php echo \esc_html( \wp_strip_all_tags( $n->content ) ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</section>
	</div>

	<div class="asm-order-side">
	<section class="asm-label">
		<h3><?php \esc_html_e( 'Shipping label', 'anchor-schema' ); ?></h3>
		<?php echo $panel; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in OrderPanel::render_panel() ?>
		<?php if ( '' !== $label_url && $label_pdf ) : ?>
			<?php // Preview only: the panel's Print button prints this frame (console.js). The fragment hides the browser's PDF toolbar. ?>
			<iframe class="asm-label-frame" title="<?php \esc_attr_e( 'Latest shipping label', 'anchor-schema' ); ?>" src="<?php echo \esc_url( $label_url ); ?>#toolbar=0&amp;navpanes=0&amp;scrollbar=0&amp;view=Fit" data-label-url="<?php echo \esc_url( $label_url ); ?>" loading="lazy"></iframe>
		<?php endif; ?>
	</section>
	</div>
</article>
