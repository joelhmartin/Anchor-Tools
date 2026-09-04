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
            // RENDER-D33: render_single_event_body() runs the post's content
            // through the 'the_content' filter exactly once (which is what
            // actually executes an [event_registration] shortcode — auto-
            // appended by maybe_append_registration_shortcode(), OR injected
            // at render time by a builder/another content filter) and knows
            // afterward whether that pass already rendered the registration
            // notice/form, instead of grepping the raw stored post_content
            // string for the shortcode tag (which misses the render-time
            // case and re-duplicates the block from the other direction).
            if ( $module ) {
                echo $module->render_single_event_body( get_the_ID() );
            }
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
                if ( ! $module->content_already_rendered_registration( $event_id ) ) {
                    echo $module->render_registration_form( $event_id );
                }
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
