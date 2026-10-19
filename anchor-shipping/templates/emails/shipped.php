<?php
/** @var WC_Order $order @var array $tracking @var string $email_heading @var WC_Email $email */
defined( 'ABSPATH' ) || exit;
do_action( 'woocommerce_email_header', $email_heading, $email );
?>
<p><?php esc_html_e( 'Good news: your order has been picked up by the carrier. You can follow it here:', 'anchor-schema' ); ?></p>
<ul>
<?php foreach ( $tracking as $t ) : ?>
	<li><?php echo esc_html( $t['carrier'] ); ?>
		<?php if ( '' !== $t['url'] ) : ?><a href="<?php echo esc_url( $t['url'] ); ?>"><?php echo esc_html( $t['number'] ); ?></a><?php else : echo esc_html( $t['number'] ); endif; ?>
	</li>
<?php endforeach; ?>
</ul>
<p><strong><?php esc_html_e( 'Shipping to', 'anchor-schema' ); ?></strong><br><?php echo wp_kses_post( $order->get_formatted_shipping_address() ?: $order->get_formatted_billing_address() ); ?></p>
<?php
do_action( 'woocommerce_email_order_details', $order, false, false, $email );
do_action( 'woocommerce_email_footer', $email );
