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
                <article class="anchor-webinar-card">
                    <h2><a href="<?php echo esc_url( get_permalink() ); ?>"><?php the_title(); ?></a></h2>
                    <div class="anchor-webinar-excerpt">
                        <?php the_excerpt(); ?>
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
