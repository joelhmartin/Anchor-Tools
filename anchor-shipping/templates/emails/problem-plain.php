<?php
/** @var WC_Order $order @var string $reason @var string $create_url @var string $email_heading */
defined( 'ABSPATH' ) || exit;
echo esc_html( wp_strip_all_tags( $email_heading ) ) . "\n\n";
esc_html_e( 'Something went wrong with the shipping label on this order and a person needs to look at it. Nothing has been retried automatically.', 'anchor-schema' );
echo "\n\n" . ( '' !== $reason ? esc_html( $reason ) . "\n\n" : '' );
echo esc_html__( 'Open the order:', 'anchor-schema' ) . ' ' . esc_url_raw( $create_url ) . "\n";
