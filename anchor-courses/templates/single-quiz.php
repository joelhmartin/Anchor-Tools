<?php
/**
 * Quiz step template (/courses/{course}/quiz/{quiz}/, Frontend\QuizStep):
 * the same page shell as a lesson, so a quiz is taken inside the course, with
 * the course outline beside it. The body is Shortcodes::render_quiz_step()
 * (templates/quiz-step.php inside templates/lesson-layout.php).
 *
 * The main query is the course; QuizStep::current_quiz() names the quiz.
 *
 * Theme override: anchor-courses/single-quiz.php
 *
 * @package Anchor\Courses
 */

use Anchor\Courses\Frontend\QuizStep;

if ( ! defined( 'ABSPATH' ) ) { exit; }

get_header();
?>
<main class="anchor-courses-single anchor-courses-single--lesson anchor-courses-single--quiz">
	<div class="anchor-courses-container">
		<?php
		while ( have_posts() ) {
			the_post();
			$anchor_courses_module = \Anchor\Courses\Module::instance();
			if ( $anchor_courses_module ) {
				echo $anchor_courses_module->shortcodes->render_quiz_step( QuizStep::current_quiz(), (int) get_the_ID() ); // phpcs:ignore WordPress.Security.EscapeOutput -- render_quiz_step() escapes internally.
			}
		}
		?>
	</div>
</main>
<?php
get_footer();
