<?php
/** @var WC_Order $order @var string $reason @var string $create_url @var string $email_heading @var WC_Email $email */
defined( 'ABSPATH' ) || exit;
do_action( 'woocommerce_email_header', $email_heading, $email );
?>
<p><?php esc_html_e( 'This order is paid and needs a shipping label.', 'anchor-schema' ); ?></p>
<?php if ( '' !== $reason ) : ?>
	<p style="background:#fcf9e8;border-left:3px solid #dba617;padding:8px 10px;"><?php echo esc_html( $reason ); ?></p>
<?php endif; ?>
<p><strong><?php esc_html_e( 'Ship to', 'anchor-schema' ); ?></strong><br><?php echo wp_kses_post( $order->get_formatted_shipping_address() ?: $order->get_formatted_billing_address() ); ?></p>
<?php do_action( 'woocommerce_email_order_details', $order, true, false, $email ); ?>
<p style="margin:24px 0;"><a href="<?php echo esc_url( $create_url ); ?>" style="background:#040541;color:#ffffff;padding:12px 20px;text-decoration:none;border-radius:4px;display:inline-block;"><?php esc_html_e( 'Create label', 'anchor-schema' ); ?></a></p>
<p style="font-size:12px;color:#646970;"><?php esc_html_e( 'You will be asked to log in if you are not already.', 'anchor-schema' ); ?></p>
<?php
do_action( 'woocommerce_email_footer', $email );
