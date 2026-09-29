<?php
/**
 * A quiz step's body, inside templates/lesson-layout.php (the course outline
 * beside it): breadcrumb, the quiz title as the page's H1, the quiz box (which
 * therefore does not repeat the title), and the same footer a lesson has,
 * with the previous/next bar.
 *
 * Variables: $quiz_id, $course_id, $user_id, $denial ('' when this learner may
 * open the quiz, else Access::DENIED_NOT_ENROLLED or Access::DENIED_LOCKED),
 * $notice (string - escaped HTML, Access::denial_notice(), '' when allowed),
 * $quiz (string - escaped HTML from Shortcodes::render_quiz(), '' when
 * denied), $complete (bool - the quiz is passed in this course), $outline
 * (array - Frontend\CourseOutline::build()).
 *
 * A learner who may not open the quiz sees exactly what a locked lesson shows
 * in place of its body: the notice, never the quiz box.
 *
 * Theme override: anchor-courses/quiz-step.php
 *
 * @package Anchor\Courses
 */

use Anchor\Courses\Frontend\CourseOutline;
use Anchor\Courses\Frontend\Templates;

if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div class="anchor-lesson anchor-quiz-step" data-quiz="<?php echo esc_attr( (string) $quiz_id ); ?>" data-course="<?php echo esc_attr( (string) $course_id ); ?>">

	<nav class="anchor-lesson-breadcrumb" aria-label="<?php esc_attr_e( 'Breadcrumb', 'anchor-schema' ); ?>">
		<a href="<?php echo esc_url( (string) get_permalink( $course_id ) ); ?>"><?php echo esc_html( get_the_title( $course_id ) ); ?></a>
		<span class="anchor-lesson-breadcrumb-sep">/</span>
		<span aria-current="page"><?php echo esc_html( get_the_title( $quiz_id ) ); ?></span>
	</nav>

	<h1 class="anchor-lesson-title anchor-quiz-step-title"><?php echo esc_html( get_the_title( $quiz_id ) ); ?></h1>

	<div class="anchor-lesson-content anchor-quiz-step-content">
		<?php
		echo '' !== $denial ? $notice : $quiz; // phpcs:ignore WordPress.Security.EscapeOutput -- both are escaped HTML (Access::denial_notice(), templates/quiz.php).
		?>
	</div>

	<div class="anchor-lesson-footer" id="<?php echo esc_attr( CourseOutline::FOOTER_ID ); ?>">
		<?php if ( $complete ) : ?>
			<p class="anchor-lesson-done"><?php esc_html_e( 'Passed', 'anchor-schema' ); ?></p>
		<?php endif; ?>

		<?php echo Templates::render( 'lesson-nav', [ 'outline' => $outline ] ); // phpcs:ignore WordPress.Security.EscapeOutput -- the template escapes its own output. ?>
	</div>
</div>
