<?php
/** @var array|null $sig  @var array|null $version  @var string $css */
if ( ! defined( 'ABSPATH' ) ) { exit; }
$tz = wp_timezone();
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="robots" content="noindex, nofollow" />
<title><?php echo esc_html( $version ? $version['title'] : __( 'Signed agreement', 'anchor-schema' ) ); ?> — <?php bloginfo( 'name' ); ?></title>
<link rel="stylesheet" href="<?php echo esc_url( $css ); ?>" />
</head>
<body>
<?php if ( ! $sig || ! $version ) : ?>
	<main class="aagr-copy aagr-copy--missing"><h1><?php esc_html_e( 'Signed agreement not found', 'anchor-schema' ); ?></h1></main>
<?php else : ?>
	<main class="aagr-copy">
		<p class="aagr-copy__site"><?php bloginfo( 'name' ); ?></p>
		<h1><?php echo esc_html( $version['title'] ); ?></h1>
		<p class="aagr-copy__meta">
			<?php
			/* translators: 1: version date */
			printf( esc_html__( 'Version of %s', 'anchor-schema' ), esc_html( wp_date( get_option( 'date_format' ), strtotime( $version['created_at'] . ' UTC' ), $tz ) ) );
			?>
		</p>
		<div class="aagr-copy__doc"><?php echo wp_kses_post( wpautop( $version['content'] ) ); ?></div>
		<section class="aagr-copy__sig">
			<img src="data:image/png;base64,<?php echo esc_attr( base64_encode( $sig['image'] ) ); ?>" alt="<?php esc_attr_e( 'Signature', 'anchor-schema' ); ?>" />
			<dl>
				<dt><?php esc_html_e( 'Signed by', 'anchor-schema' ); ?></dt><dd><?php echo esc_html( $sig['signer_name'] ); ?></dd>
				<dt><?php esc_html_e( 'Date', 'anchor-schema' ); ?></dt><dd><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) . ' T', strtotime( $sig['signed_at'] . ' UTC' ), $tz ) ); ?></dd>
				<dt><?php esc_html_e( 'Order', 'anchor-schema' ); ?></dt><dd>#<?php echo esc_html( (string) $sig['order_id'] ); ?></dd>
				<dt><?php esc_html_e( 'Signature ID', 'anchor-schema' ); ?></dt><dd><?php echo esc_html( strtoupper( substr( $sig['token'], 0, 8 ) ) ); ?></dd>
			</dl>
		</section>
		<button type="button" class="aagr-copy__print" onclick="window.print()"><?php esc_html_e( 'Print / Save as PDF', 'anchor-schema' ); ?></button>
	</main>
<?php endif; ?>
</body>
</html>
