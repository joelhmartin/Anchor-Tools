<?php
/**
 * Unsubscribe page. Variables: $send (row|null), $done (bool), $email (string).
 *
 * @package Anchor\Announcements
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }
$aa_site = get_bloginfo( 'name' );
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width,initial-scale=1" />
	<meta name="robots" content="noindex" />
	<title><?php echo esc_html( sprintf( __( 'Unsubscribe from %s', 'anchor-schema' ), $aa_site ) ); ?></title>
	<style>body{margin:0;background:#f4f5f5;font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:#1f2a28}.card{max-width:480px;margin:10vh auto;background:#fff;border-radius:8px;padding:32px;box-shadow:0 2px 8px rgba(0,0,0,.06)}button{font:inherit;padding:10px 20px;border:0;border-radius:6px;background:#1a4f48;color:#fff;cursor:pointer}a{color:#1a4f48}</style>
</head>
<body>
	<div class="card">
		<?php if ( ! $send ) : ?>
			<h1><?php esc_html_e( 'Link not recognised', 'anchor-schema' ); ?></h1>
			<p><?php esc_html_e( 'This unsubscribe link is not valid. Please use the link in the email you received.', 'anchor-schema' ); ?></p>
		<?php elseif ( $done ) : ?>
			<h1><?php esc_html_e( 'You are unsubscribed', 'anchor-schema' ); ?></h1>
			<p><?php echo esc_html( sprintf( __( '%1$s will no longer receive announcements from %2$s.', 'anchor-schema' ), $email, $aa_site ) ); ?></p>
		<?php else : ?>
			<h1><?php esc_html_e( 'Unsubscribe?', 'anchor-schema' ); ?></h1>
			<p><?php echo esc_html( sprintf( __( 'Stop sending announcements from %2$s to %1$s?', 'anchor-schema' ), $email, $aa_site ) ); ?></p>
			<form method="post"><button type="submit"><?php esc_html_e( 'Unsubscribe', 'anchor-schema' ); ?></button></form>
		<?php endif; ?>
		<p><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo esc_html( $aa_site ); ?></a></p>
	</div>
</body>
</html>
