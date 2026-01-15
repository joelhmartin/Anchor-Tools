<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

get_header();

$module = \Anchor\Events\Module::instance();
if ( $module ) {
    $module->enqueue_frontend_assets();
}
?>
<main class="anchor-events-archive">
    <header class="anchor-events-header">
        <h1><?php echo esc_html( post_type_archive_title( '', false ) ); ?></h1>
    </header>
    <div class="anchor-events-list">
        <?php if ( have_posts() ) : ?>
            <?php while ( have_posts() ) : the_post(); ?>
                <?php
                if ( $module ) {
                    echo $module->render_event_card( get_the_ID(), 'archive' );
                }
                ?>
            <?php endwhile; ?>
        <?php else : ?>
            <div class="anchor-events-empty"><?php echo esc_html__( 'No events found.', 'anchor-schema' ); ?></div>
        <?php endif; ?>
    </div>
</main>
<?php
get_footer();
