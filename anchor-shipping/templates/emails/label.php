<?php
/** @var WC_Order $order @var array $rows @var string $email_heading @var WC_Email $email */
defined( 'ABSPATH' ) || exit;
do_action( 'woocommerce_email_header', $email_heading, $email );
?>
<p><?php echo esc_html( sprintf( /* translators: %s: order number */ __( 'The shipping label for order #%s is attached. Print it, pack the box and hand it to the carrier.', 'anchor-schema' ), $order->get_order_number() ) ); ?></p>
<ul>
	<?php foreach ( $rows as $r ) : ?>
		<li><strong><?php echo esc_html( $r['tracking_number'] ); ?></strong><?php echo (float) $r['declared_value'] > 0 ? esc_html__( ' — insured', 'anchor-schema' ) : ''; ?><?php echo '1' === (string) $r['signature'] ? esc_html__( ' — signature required', 'anchor-schema' ) : ''; ?></li>
	<?php endforeach; ?>
</ul>
<p><strong><?php esc_html_e( 'Ship to', 'anchor-schema' ); ?></strong><br><?php echo wp_kses_post( $order->get_formatted_shipping_address() ?: $order->get_formatted_billing_address() ); ?></p>
<?php do_action( 'woocommerce_email_order_details', $order, true, false, $email ); ?>
<p><a href="<?php echo esc_url( $order->get_edit_order_url() . '#anchor-shipping' ); ?>"><?php esc_html_e( 'Open the order to reprint or void the label', 'anchor-schema' ); ?></a></p>
<?php
do_action( 'woocommerce_email_footer', $email );
