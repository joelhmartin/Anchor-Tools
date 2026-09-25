<?php
/**
 * Single course template. Theme override: anchor-courses/single-course.php
 *
 * @package Anchor\Courses
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

get_header();
?>
<main class="anchor-courses-single">
	<?php
	while ( have_posts() ) {
		the_post();
		echo do_shortcode( '[anchor_course id="' . (int) get_the_ID() . '"]' );
	}
	?>
</main>
<?php
get_footer();
