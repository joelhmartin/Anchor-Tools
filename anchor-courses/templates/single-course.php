<?php
/**
 * Single course template: the theme's header and footer around a contained,
 * readable-width page. The body is [anchor_course] (templates/course.php).
 *
 * The container width and padding are CSS custom properties on
 * .anchor-courses-single (frontend.css), so a theme usually only needs to
 * set those rather than override this file.
 *
 * Theme override: anchor-courses/single-course.php
 *
 * @package Anchor\Courses
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

get_header();
?>
<main class="anchor-courses-single anchor-courses-single--course">
	<div class="anchor-courses-container">
		<?php
		while ( have_posts() ) {
			the_post();
			echo do_shortcode( '[anchor_course id="' . (int) get_the_ID() . '"]' );
		}
		?>
	</div>
</main>
<?php
get_footer();
