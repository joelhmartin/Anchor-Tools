<?php
/**
 * Series archive template (spec §6). Lists a series' session-events with date,
 * "from $X", and availability. Routed via Module::template_include() when
 * is_tax('event_series'); respects the theme-override locate_template pattern.
 *
 * Plain PHP template — global WP functions used directly (matches archive-event.php).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

get_header();

$module = \Anchor\Events\Module::instance();
if ( $module ) {
    $module->enqueue_frontend_assets();
}
?>
<main class="anchor-events-archive anchor-events-series-archive">
    <?php
    if ( $module && $module->series ) {
        echo $module->series->render_archive();
    }
    ?>
</main>
<?php
get_footer();
