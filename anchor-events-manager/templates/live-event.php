<?php
/**
 * The room. Theme-overridable at events/live-event.php (or live-event.php)
 * through Module::locate_template(). Deliberately theme-agnostic: header,
 * one <main>, footer, and the body delegated to the module.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

// room.css/room.js are enqueued from Module::frontend_assets() on
// wp_enqueue_scripts (is_room_request()), not here — get_header() below
// already runs wp_head(), so an enqueue at this point would land after the
// normal head pass has printed and only show up via the late/footer
// fallback (audit finding b, 2026-09-25).
get_header();

$module = \Anchor\Events\Module::instance();
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
