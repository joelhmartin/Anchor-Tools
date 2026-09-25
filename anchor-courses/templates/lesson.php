<?php
/**
 * Lesson page body.
 *
 * Variables: $lesson_id, $course_id, $user_id, $available (bool),
 * $complete (bool), $previous (int), $next (int).
 *
 * Theme override: anchor-courses/lesson.php
 *
 * @package Anchor\Courses
 */

use Anchor\Courses\Frontend\Actions;

if ( ! defined( 'ABSPATH' ) ) { exit; }

$notice = Actions::notice();
?>
<div class="anchor-lesson" data-lesson="<?php echo esc_attr( (string) $lesson_id ); ?>" data-course="<?php echo esc_attr( (string) $course_id ); ?>">

	<?php if ( '' !== $notice ) : ?>
		<p class="anchor-courses-notice"><?php echo esc_html( Actions::notice_text( $notice ) ); ?></p>
	<?php endif; ?>

	<nav class="anchor-lesson-breadcrumb">
		<a href="<?php echo esc_url( (string) get_permalink( $course_id ) ); ?>"><?php echo esc_html( get_the_title( $course_id ) ); ?></a>
		<span class="anchor-lesson-breadcrumb-sep">/</span>
		<span><?php echo esc_html( get_the_title( $lesson_id ) ); ?></span>
	</nav>

	<?php if ( ! $available ) : ?>
		<p class="anchor-courses-notice"><?php esc_html_e( 'Finish the earlier lessons to unlock this one.', 'anchor-schema' ); ?></p>
	<?php else : ?>
		<div class="anchor-lesson-content"><?php echo wp_kses_post( apply_filters( 'the_content', get_post_field( 'post_content', $lesson_id ) ) ); ?></div>

		<?php if ( ! $complete ) : ?>
			<form class="anchor-lesson-complete" method="post" action="<?php echo esc_url( Actions::complete_url( $course_id, $lesson_id ) ); ?>">
				<?php wp_nonce_field( Actions::NONCE_COMPLETE . '_' . $lesson_id ); ?>
				<input type="hidden" name="course_id" value="<?php echo esc_attr( (string) $course_id ); ?>" />
				<input type="hidden" name="lesson_id" value="<?php echo esc_attr( (string) $lesson_id ); ?>" />
				<input type="hidden" name="_redirect" value="<?php echo esc_url( (string) get_permalink( $lesson_id ) ); ?>" />
				<button type="submit" class="anchor-courses-button"><?php esc_html_e( 'Mark complete', 'anchor-schema' ); ?></button>
			</form>
		<?php else : ?>
			<p class="anchor-lesson-done"><?php esc_html_e( 'Completed', 'anchor-schema' ); ?></p>
		<?php endif; ?>
	<?php endif; ?>

	<nav class="anchor-lesson-nav">
		<?php if ( $previous > 0 ) : ?>
			<a class="anchor-lesson-prev" href="<?php echo esc_url( (string) get_permalink( $previous ) ); ?>"><?php esc_html_e( 'Previous', 'anchor-schema' ); ?></a>
		<?php endif; ?>
		<?php if ( $next > 0 ) : ?>
			<a class="anchor-lesson-next" href="<?php echo esc_url( (string) get_permalink( $next ) ); ?>"><?php esc_html_e( 'Next', 'anchor-schema' ); ?></a>
		<?php endif; ?>
	</nav>

	<?php echo do_shortcode( '[anchor_course_progress course_id="' . (int) $course_id . '"]' ); ?>
</div>
