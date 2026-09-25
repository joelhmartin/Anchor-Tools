<?php
/**
 * Lesson page body.
 *
 * Variables: $lesson_id, $course_id, $user_id, $available (bool),
 * $complete (bool), $previous (int), $next (int) - the neighbouring LESSON
 * ids (quizzes are skipped: they have no URL), 0 when there is none.
 * Every lesson link carries the course context (Access::lesson_url()).
 *
 * Theme override: anchor-courses/lesson.php
 *
 * @package Anchor\Courses
 */

use Anchor\Courses\Frontend\Access;
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

	<?php
	/*
	 * The body always goes through the_content - never a second
	 * `if ( ! $available )` branch here. Frontend\ContentGuard hooks that
	 * same filter and substitutes the access notice for anyone
	 * Access::can_view_lesson() refuses, so this template, a feed, and a
	 * search excerpt can never disagree about who sees the real content
	 * (Task 18 review fix round, ruling (b)).
	 *
	 * Echoed as the_content returns it, NOT through wp_kses_post() (final
	 * review I3): kses strips <iframe>, so every Vimeo/YouTube embed in a
	 * lesson vanished. The body was authored by somebody allowed to edit
	 * lessons and is already filtered exactly as core filters any post
	 * body; ContentGuard is the gate.
	 */
	?>
	<div class="anchor-lesson-content"><?php echo apply_filters( 'the_content', get_post_field( 'post_content', $lesson_id ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- the_content output, gated by ContentGuard. ?></div>

	<?php if ( $available ) : ?>
		<?php if ( ! $complete ) : ?>
			<form class="anchor-lesson-complete" method="post" action="<?php echo esc_url( Actions::complete_url( $course_id, $lesson_id ) ); ?>">
				<?php wp_nonce_field( Actions::NONCE_COMPLETE . '_' . $lesson_id ); ?>
				<input type="hidden" name="course_id" value="<?php echo esc_attr( (string) $course_id ); ?>" />
				<input type="hidden" name="lesson_id" value="<?php echo esc_attr( (string) $lesson_id ); ?>" />
				<input type="hidden" name="_redirect" value="<?php echo esc_url( Access::lesson_url( $lesson_id, $course_id ) ); ?>" />
				<button type="submit" class="anchor-courses-button"><?php esc_html_e( 'Mark complete', 'anchor-schema' ); ?></button>
			</form>
		<?php else : ?>
			<p class="anchor-lesson-done"><?php esc_html_e( 'Completed', 'anchor-schema' ); ?></p>
		<?php endif; ?>
	<?php endif; ?>

	<nav class="anchor-lesson-nav">
		<?php if ( $previous > 0 ) : ?>
			<a class="anchor-lesson-prev" href="<?php echo esc_url( Access::lesson_url( $previous, $course_id ) ); ?>"><?php esc_html_e( 'Previous', 'anchor-schema' ); ?></a>
		<?php endif; ?>
		<?php if ( $next > 0 ) : ?>
			<a class="anchor-lesson-next" href="<?php echo esc_url( Access::lesson_url( $next, $course_id ) ); ?>"><?php esc_html_e( 'Next', 'anchor-schema' ); ?></a>
		<?php endif; ?>
	</nav>

	<?php echo do_shortcode( '[anchor_course_progress course_id="' . (int) $course_id . '"]' ); ?>
</div>
