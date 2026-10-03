<?php
/** @var WC_Order $order @var array $rows @var string $email_heading */
defined( 'ABSPATH' ) || exit;
echo esc_html( wp_strip_all_tags( $email_heading ) ) . "\n\n";
echo esc_html( sprintf( /* translators: %s: order number */ __( 'The shipping label for order #%s is attached.', 'anchor-schema' ), $order->get_order_number() ) ) . "\n\n";
foreach ( $rows as $r ) {
	echo esc_html( $r['tracking_number'] ) . "\n";
}
echo "\n" . esc_html( wp_strip_all_tags( str_replace( '<br/>', "\n", (string) ( $order->get_formatted_shipping_address() ?: $order->get_formatted_billing_address() ) ) ) ) . "\n\n";
echo esc_url_raw( $order->get_edit_order_url() . '#anchor-shipping' ) . "\n";
