<?php
/**
 * The lesson page frame, shared by both lesson types: the course outline
 * beside the lesson on wide screens (sticky), and above it on phones, where
 * it collapses into a "Course outline" disclosure.
 *
 * Variables: $outline (array - Frontend\CourseOutline::build()), $content
 * (string - the already-escaped lesson body from lesson.php or
 * live-session.php).
 *
 * The <details> is rendered open so the outline is there without
 * JavaScript; frontend.js closes it on narrow screens and keeps it open on
 * wide ones, where its summary is hidden.
 *
 * Theme override: anchor-courses/lesson-layout.php
 *
 * @package Anchor\Courses
 */

use Anchor\Courses\Frontend\Templates;

if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div class="anchor-lesson-layout">
	<aside class="anchor-lesson-layout__outline">
		<details class="anchor-course-outline-toggle" open>
			<summary class="anchor-course-outline-toggle__summary"><?php esc_html_e( 'Course outline', 'anchor-schema' ); ?></summary>
			<?php echo Templates::render( 'course-outline', [ 'outline' => $outline ] ); // phpcs:ignore WordPress.Security.EscapeOutput -- the template escapes its own output. ?>
		</details>
	</aside>
	<div class="anchor-lesson-layout__main">
		<?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput -- rendered and escaped by lesson.php / live-session.php. ?>
	</div>
</div>
