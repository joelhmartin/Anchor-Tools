<?php
/** @var WC_Order $order @var string $reason @var string $create_url @var string $email_heading @var WC_Email $email */
defined( 'ABSPATH' ) || exit;
do_action( 'woocommerce_email_header', $email_heading, $email );
?>
<p><?php esc_html_e( 'Something went wrong with the shipping label on this order and a person needs to look at it. Nothing has been retried automatically.', 'anchor-schema' ); ?></p>
<?php if ( '' !== $reason ) : ?>
	<p style="background:#fcf0f1;border-left:3px solid #d63638;padding:8px 10px;"><?php echo esc_html( $reason ); ?></p>
<?php endif; ?>
<p style="margin:24px 0;"><a href="<?php echo esc_url( $create_url ); ?>" style="background:#040541;color:#ffffff;padding:12px 20px;text-decoration:none;border-radius:4px;display:inline-block;"><?php esc_html_e( 'Open the order', 'anchor-schema' ); ?></a></p>
<p style="font-size:12px;color:#646970;"><?php esc_html_e( 'You will be asked to log in if you are not already.', 'anchor-schema' ); ?></p>
<?php
do_action( 'woocommerce_email_footer', $email );
