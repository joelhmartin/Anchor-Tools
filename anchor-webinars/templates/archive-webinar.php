<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

get_header();
?>
<main class="anchor-webinar-archive">
    <header class="anchor-webinar-header">
        <h1><?php echo esc_html( post_type_archive_title( '', false ) ); ?></h1>
    </header>
    <div class="anchor-webinar-list">
        <?php if ( have_posts() ) : ?>
            <?php while ( have_posts() ) : the_post(); ?>
                <?php $webinar_date = get_post_meta( get_the_ID(), '_anchor_webinar_date', true ); ?>
                <article class="anchor-webinar-card">
                    <?php if ( has_post_thumbnail() ) : ?>
                        <a href="<?php echo esc_url( get_permalink() ); ?>" class="anchor-webinar-card__thumb">
                            <?php the_post_thumbnail( 'medium_large' ); ?>
                        </a>
                    <?php endif; ?>
                    <div class="anchor-webinar-card__body">
                        <h2><a href="<?php echo esc_url( get_permalink() ); ?>"><?php the_title(); ?></a></h2>
                        <?php if ( $webinar_date ) : ?>
                            <p class="anchor-webinar-card__date"><?php echo esc_html( date_i18n( 'F j, Y', strtotime( $webinar_date ) ) ); ?></p>
                        <?php endif; ?>
                        <?php if ( has_excerpt() ) : ?>
                            <p class="anchor-webinar-card__excerpt"><?php echo esc_html( get_the_excerpt() ); ?></p>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endwhile; ?>
        <?php else : ?>
            <p><?php echo esc_html__( 'No webinars found.', 'anchor-schema' ); ?></p>
        <?php endif; ?>
    </div>
</main>
<?php
get_footer();
