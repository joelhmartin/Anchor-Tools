<?php
/**
 * The room. Theme-overridable at events/live-event.php (or live-event.php)
 * through Module::locate_template(). Deliberately theme-agnostic: header,
 * one <main>, footer, and the body delegated to the module.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

get_header();

$module = \Anchor\Events\Module::instance();
if ( $module ) {
    $module->enqueue_room_assets();
}
?>
<main class="anchor-event-room">
    <?php
    if ( $module ) {
        echo $module->render_room( get_the_ID() );
    }
    ?>
</main>
<?php
get_footer();
