<?php
/**
 * Single lesson template. Theme override: anchor-courses/single-lesson.php
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
		$anchor_courses_module = \Anchor\Courses\Module::instance();
		if ( $anchor_courses_module ) {
			echo $anchor_courses_module->shortcodes->render_lesson( (int) get_the_ID() ); // phpcs:ignore WordPress.Security.EscapeOutput -- render_lesson() escapes internally.
		}
	}
	?>
</main>
<?php
get_footer();
