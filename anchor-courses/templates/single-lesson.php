<?php
/**
 * Single lesson template: the theme's header and footer around a contained
 * page. The body, including the course outline beside it, is
 * Shortcodes::render_lesson() (templates/lesson-layout.php).
 *
 * The container width and padding are CSS custom properties on
 * .anchor-courses-single (frontend.css), so a theme usually only needs to
 * set those rather than override this file.
 *
 * Theme override: anchor-courses/single-lesson.php
 *
 * @package Anchor\Courses
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

get_header();
?>
<main class="anchor-courses-single anchor-courses-single--lesson">
	<div class="anchor-courses-container">
		<?php
		while ( have_posts() ) {
			the_post();
			$anchor_courses_module = \Anchor\Courses\Module::instance();
			if ( $anchor_courses_module ) {
				echo $anchor_courses_module->shortcodes->render_lesson( (int) get_the_ID() ); // phpcs:ignore WordPress.Security.EscapeOutput -- render_lesson() escapes internally.
			}
		}
		?>
	</div>
</main>
<?php
get_footer();
