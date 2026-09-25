<?php
/**
 * Quiz shell. The questions are fetched over REST after "Start", so no answer
 * payload is ever in the page source before an attempt exists (brief 8.3).
 *
 * Variables: $quiz_id, $course_id, $user_id, $can_start (true|WP_Error),
 * $attempts_remaining (int), $best (QuizAttempt|null).
 *
 * Theme override: anchor-courses/quiz.php
 *
 * @package Anchor\Courses
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div class="anchor-quiz" data-quiz="<?php echo esc_attr( (string) $quiz_id ); ?>" data-course="<?php echo esc_attr( (string) $course_id ); ?>">
	<h3 class="anchor-quiz-title"><?php echo esc_html( get_the_title( $quiz_id ) ); ?></h3>

	<?php if ( $best && $best->is_graded() ) : ?>
		<p class="anchor-quiz-best">
			<?php
			printf(
				/* translators: %s: best score as a percentage. */
				esc_html__( 'Best score: %s%%', 'anchor-schema' ),
				esc_html( number_format_i18n( (float) $best->score, 0 ) )
			);
			?>
			<?php if ( $best->passed ) : ?>
				<span class="anchor-quiz-passed"><?php esc_html_e( 'Passed', 'anchor-schema' ); ?></span>
			<?php endif; ?>
		</p>
	<?php endif; ?>

	<?php if ( is_wp_error( $can_start ) ) : ?>
		<p class="anchor-courses-notice"><?php echo esc_html( $can_start->get_error_message() ); ?></p>
	<?php else : ?>
		<?php if ( $attempts_remaining >= 0 ) : ?>
			<p class="anchor-quiz-remaining">
				<?php
				printf(
					/* translators: %d: number of attempts left. */
					esc_html( _n( '%d attempt remaining', '%d attempts remaining', $attempts_remaining, 'anchor-schema' ) ),
					(int) $attempts_remaining
				);
				?>
			</p>
		<?php endif; ?>
		<button type="button" class="anchor-courses-button anchor-quiz-start"><?php esc_html_e( 'Start quiz', 'anchor-schema' ); ?></button>
	<?php endif; ?>

	<div class="anchor-quiz-timer" hidden></div>
	<form class="anchor-quiz-form" hidden></form>
	<div class="anchor-quiz-result" hidden></div>
	<noscript><p><?php esc_html_e( 'This quiz needs JavaScript.', 'anchor-schema' ); ?></p></noscript>
</div>
