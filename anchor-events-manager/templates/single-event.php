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
                echo $module->render_event_gallery( get_the_ID() );
            }
            the_content();
            ?>
        </div>
        <?php
        if ( $module ) {
            $event_id = get_the_ID();
            if ( $module->occurrences->is_group_parent( $event_id ) ) {
                // Task 2.4: a group parent is a container, not directly
                // bookable — the "choose a date" picker over its live
                // children REPLACES the (already-suppressed) registration form.
                echo $module->render_choose_date_list( $event_id );
            } else {
                echo $module->render_registration_form( $event_id );
                if ( $module->occurrences->is_group_child( $event_id ) ) {
                    // Task 2.4: sibling-date nav, shown for both live and
                    // soft-closed children so a closed child's own page still
                    // offers a way to find a live date.
                    echo $module->render_sibling_dates( $event_id );
                }
            }
        }
        ?>
    <?php endwhile; ?>
</main>
<?php
get_footer();
