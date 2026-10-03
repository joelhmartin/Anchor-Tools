<?php
/** @var WC_Order $order @var string $reason @var string $create_url @var string $email_heading */
defined( 'ABSPATH' ) || exit;
echo esc_html( wp_strip_all_tags( $email_heading ) ) . "\n\n";
esc_html_e( 'This order is paid and needs a shipping label.', 'anchor-schema' );
echo "\n\n" . ( '' !== $reason ? esc_html( $reason ) . "\n\n" : '' );
echo esc_html( wp_strip_all_tags( str_replace( '<br/>', "\n", (string) ( $order->get_formatted_shipping_address() ?: $order->get_formatted_billing_address() ) ) ) ) . "\n\n";
echo esc_html__( 'Create label:', 'anchor-schema' ) . ' ' . esc_url_raw( $create_url ) . "\n";
