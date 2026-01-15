<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

get_header();

$module = \Anchor\Events\Module::instance();
if ( $module ) {
    $module->enqueue_frontend_assets();
}
?>
<main class="anchor-event-single">
    <?php
    while ( have_posts() ) :
        the_post();
        ?>
        <header class="anchor-event-hero">
            <h1><?php the_title(); ?></h1>
            <?php if ( has_post_thumbnail() ) : ?>
                <div class="anchor-event-hero-media">
                    <?php the_post_thumbnail( 'large' ); ?>
                </div>
            <?php endif; ?>
        </header>
        <div class="anchor-event-content">
            <?php
            if ( $module ) {
                echo $module->render_registration_notice();
                echo $module->render_single_content( get_the_ID() );
            }
            the_content();
            ?>
        </div>
        <?php
        if ( $module ) {
            echo $module->render_registration_form( get_the_ID() );
        }
        ?>
    <?php endwhile; ?>
</main>
<?php
get_footer();
