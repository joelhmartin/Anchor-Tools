<?php
/** @var WC_Order $order @var array $tracking @var string $email_heading */
defined( 'ABSPATH' ) || exit;
echo esc_html( wp_strip_all_tags( $email_heading ) ) . "\n\n";
esc_html_e( 'Good news: your order has been picked up by the carrier. You can follow it here:', 'anchor-schema' );
echo "\n\n";
foreach ( $tracking as $t ) {
	echo esc_html( $t['carrier'] . ' ' . $t['number'] ) . ( '' !== $t['url'] ? ' ' . esc_url_raw( $t['url'] ) : '' ) . "\n";
}
echo "\n" . esc_html__( 'Shipping to', 'anchor-schema' ) . "\n";
echo esc_html( wp_strip_all_tags( str_replace( '<br/>', "\n", (string) ( $order->get_formatted_shipping_address() ?: $order->get_formatted_billing_address() ) ) ) ) . "\n\n";
do_action( 'woocommerce_email_order_details', $order, false, true, $email );
