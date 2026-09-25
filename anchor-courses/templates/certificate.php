<?php
/**
 * The HTML certificate (brief 13). A complete document: this is served
 * standalone at /certificate/{token}/ and printed from the browser.
 *
 * Variables: $certificate (Certificate), $data (template_data()).
 *
 * Theme override: anchor-courses/certificate.php
 *
 * Created by Task 28 (CertificateService::render() needs it to exist) with
 * the body Task 30 specifies for it; Task 30 owns this file going forward.
 *
 * @package Anchor\Courses
 */

use Anchor\Courses\Module;

if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<meta name="robots" content="noindex, nofollow" />
	<title><?php echo esc_html( $data['certificate_number'] . ' - ' . $data['course_name'] ); ?></title>
	<link rel="stylesheet" href="<?php echo esc_url( Module::assets_url() . 'certificate.css' ); ?>" />
</head>
<body class="anchor-certificate-page">
	<main class="anchor-certificate">
		<header class="anchor-certificate-header">
			<p class="anchor-certificate-eyebrow"><?php esc_html_e( 'Certificate of Completion', 'anchor-schema' ); ?></p>
			<p class="anchor-certificate-site"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></p>
		</header>

		<p class="anchor-certificate-presented"><?php esc_html_e( 'This certifies that', 'anchor-schema' ); ?></p>
		<p class="anchor-certificate-learner"><?php echo esc_html( $data['learner_name'] ); ?></p>
		<p class="anchor-certificate-presented"><?php esc_html_e( 'has completed', 'anchor-schema' ); ?></p>
		<p class="anchor-certificate-course"><?php echo esc_html( $data['course_name'] ); ?></p>

		<dl class="anchor-certificate-details">
			<dt><?php esc_html_e( 'Completed', 'anchor-schema' ); ?></dt>
			<dd><?php echo esc_html( mysql2date( (string) get_option( 'date_format' ), $data['completion_date'] ) ); ?></dd>

			<?php if ( (float) $data['ce_credits'] > 0 ) : ?>
				<dt><?php esc_html_e( 'CE credits', 'anchor-schema' ); ?></dt>
				<dd><?php echo esc_html( number_format_i18n( (float) $data['ce_credits'], 1 ) ); ?></dd>
			<?php endif; ?>

			<?php if ( '' !== $data['instructor_name'] ) : ?>
				<dt><?php esc_html_e( 'Instructor', 'anchor-schema' ); ?></dt>
				<dd><?php echo esc_html( $data['instructor_name'] ); ?></dd>
			<?php endif; ?>

			<?php if ( '' !== $data['provider_name'] ) : ?>
				<dt><?php esc_html_e( 'Provider', 'anchor-schema' ); ?></dt>
				<dd>
					<?php echo esc_html( $data['provider_name'] ); ?>
					<?php if ( '' !== $data['provider_number'] ) : ?>
						<span class="anchor-certificate-provider-number"><?php echo esc_html( $data['provider_number'] ); ?></span>
					<?php endif; ?>
				</dd>
			<?php endif; ?>

			<?php if ( '' !== $data['expiration_date'] ) : ?>
				<dt><?php esc_html_e( 'Credits expire', 'anchor-schema' ); ?></dt>
				<dd><?php echo esc_html( mysql2date( (string) get_option( 'date_format' ), $data['expiration_date'] ) ); ?></dd>
			<?php endif; ?>

			<dt><?php esc_html_e( 'Certificate number', 'anchor-schema' ); ?></dt>
			<dd class="anchor-certificate-number"><?php echo esc_html( $data['certificate_number'] ); ?></dd>
		</dl>

		<footer class="anchor-certificate-footer">
			<p class="anchor-certificate-verify">
				<?php esc_html_e( 'Verify this certificate at', 'anchor-schema' ); ?>
				<span><?php echo esc_html( $data['verification_url'] ); ?></span>
			</p>
			<p class="anchor-certificate-print no-print">
				<button type="button" onclick="window.print()"><?php esc_html_e( 'Print', 'anchor-schema' ); ?></button>
			</p>
		</footer>
	</main>
</body>
</html>
