<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

get_header();

$module = \Anchor\Webinars\Module::instance();
?>
<main class="anchor-webinar-single">
    <?php while ( have_posts() ) : the_post(); ?>
        <?php
        $vimeo_id    = get_post_meta( get_the_ID(), '_anchor_webinar_vimeo_id', true );
        $webinar_date = get_post_meta( get_the_ID(), '_anchor_webinar_date', true );
        $has_player  = $module ? $module->content_has_player_container( get_the_ID() ) : false;
        ?>
        <header class="anchor-webinar-header">
            <h1><?php the_title(); ?></h1>
            <?php if ( $webinar_date ) : ?>
                <p class="anchor-webinar-date"><?php echo esc_html( date_i18n( 'F j, Y', strtotime( $webinar_date ) ) ); ?></p>
            <?php endif; ?>
        </header>

        <?php if ( $vimeo_id && ! $has_player ) : ?>
            <div class="anchor-webinar-player-wrap">
                <div id="anchor-webinar-player"></div>
            </div>
        <?php elseif ( ! $vimeo_id && has_post_thumbnail() ) : ?>
            <div class="anchor-webinar-featured-img">
                <?php the_post_thumbnail( 'large' ); ?>
            </div>
        <?php elseif ( ! $vimeo_id ) : ?>
            <p><?php echo esc_html__( 'Vimeo video ID is missing for this webinar.', 'anchor-schema' ); ?></p>
        <?php endif; ?>

        <div class="anchor-webinar-description">
            <?php the_content(); ?>
        </div>
    <?php endwhile; ?>
</main>
<?php
get_footer();
