<?php
/**
 * Minimal fallback single template for the anchor_speaker CPT, used only
 * when the active theme has no single-anchor_speaker.php of its own (see
 * Anchor_Speakers_Module::single_template()). Kept theme-agnostic: it only
 * calls get_header()/get_footer() and does not assume any theme markup.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

get_header();

while ( have_posts() ) :
	the_post();

	$meta = class_exists( 'Anchor_Speaker_Meta' ) ? Anchor_Speaker_Meta::get( get_the_ID() ) : [
		'credentials' => '',
		'title'       => '',
	];
	?>
	<main class="anchor-speaker-single">
		<article <?php post_class(); ?>>
			<?php if ( has_post_thumbnail() ) : ?>
				<div class="anchor-speaker-single__photo"><?php the_post_thumbnail( 'large' ); ?></div>
			<?php endif; ?>

			<h1 class="anchor-speaker-single__name"><?php the_title(); ?></h1>

			<?php if ( ! empty( $meta['credentials'] ) ) : ?>
				<p class="anchor-speaker-single__credentials"><?php echo esc_html( $meta['credentials'] ); ?></p>
			<?php endif; ?>

			<?php if ( ! empty( $meta['title'] ) ) : ?>
				<p class="anchor-speaker-single__title"><?php echo esc_html( $meta['title'] ); ?></p>
			<?php endif; ?>

			<div class="anchor-speaker-single__content">
				<?php the_content(); ?>
			</div>
		</article>

		<?php if ( class_exists( 'Anchor_Testimonials_Module' ) ) : ?>
			<?php echo do_shortcode( '[anchor_testimonials related="current" fallback="none"]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- shortcode output escapes its own fields. ?>
		<?php endif; ?>
	</main>
	<?php
endwhile;

get_footer();
