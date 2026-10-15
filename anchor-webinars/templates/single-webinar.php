<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

get_header();

$module = \Anchor\Webinars\Module::instance();
?>
<main class="anchor-webinar-single">
    <?php while ( have_posts() ) : the_post(); ?>
        <?php
        $webinar_date = get_post_meta( get_the_ID(), '_anchor_webinar_date', true );
        $can_access   = $module ? $module->can_user_access( get_the_ID() ) : true;
        ?>
        <header class="anchor-webinar-header">
            <h1><?php the_title(); ?></h1>
            <?php if ( $webinar_date ) : ?>
                <p class="anchor-webinar-date"><?php echo esc_html( date_i18n( 'F j, Y', strtotime( $webinar_date ) ) ); ?></p>
            <?php endif; ?>
        </header>

        <?php if ( ! $can_access ) : ?>
            <?php echo $module->render_access_gate( get_the_ID() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — markup built internally. ?>
        <?php else : ?>
            <?php
            $has_video  = $module && $module->video_source( get_the_ID() );
            $has_player = $module ? $module->content_has_player_container( get_the_ID() ) : false;
            ?>
            <?php if ( $has_video && ! $has_player ) : ?>
                <?php echo $module->render_player( get_the_ID() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — markup built internally. ?>
            <?php elseif ( ! $has_video && has_post_thumbnail() ) : ?>
                <div class="anchor-webinar-featured-img">
                    <?php the_post_thumbnail( 'large' ); ?>
                </div>
            <?php elseif ( ! $has_video ) : ?>
                <p><?php echo esc_html__( 'No video is set for this webinar.', 'anchor-schema' ); ?></p>
            <?php endif; ?>

            <div class="anchor-webinar-description">
                <?php the_content(); ?>
            </div>
        <?php endif; ?>
    <?php endwhile; ?>
</main>
<?php
get_footer();
