<?php
/**
 * The previous / next bar under a lesson or a quiz step.
 *
 * It walks every step, lessons and quizzes, in curriculum order; a quiz
 * neighbour is tagged "Quiz". Next is the primary action; after the last
 * step it becomes "Back to course". A neighbour this learner cannot open yet
 * (sequential progression) is shown but not linked.
 *
 * Variables: $outline (array - Frontend\CourseOutline::build(); this reads
 * previous, next (each with type, title, url, available) and course_url).
 *
 * Theme override: anchor-courses/lesson-nav.php
 *
 * @package Anchor\Courses
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$anchor_courses_prev = $outline['previous'];
$anchor_courses_next = $outline['next'];
?>
<nav class="anchor-lesson-nav" aria-label="<?php esc_attr_e( 'Lesson navigation', 'anchor-schema' ); ?>">
	<?php if ( null !== $anchor_courses_prev ) : ?>
		<?php if ( $anchor_courses_prev['available'] ) : ?>
			<a class="anchor-lesson-nav__link anchor-lesson-nav__link--prev anchor-lesson-prev" href="<?php echo esc_url( $anchor_courses_prev['url'] ); ?>" rel="prev">
		<?php else : ?>
			<span class="anchor-lesson-nav__link anchor-lesson-nav__link--prev anchor-lesson-prev is-locked" aria-disabled="true">
		<?php endif; ?>
			<span class="anchor-lesson-nav__label"><?php esc_html_e( 'Previous:', 'anchor-schema' ); ?></span>
			<span class="anchor-lesson-nav__title"><?php echo esc_html( $anchor_courses_prev['title'] ); ?><?php echo 'quiz' === ( $anchor_courses_prev['type'] ?? '' ) ? ' <span class="anchor-lesson-nav__tag">' . esc_html__( 'Quiz', 'anchor-schema' ) . '</span>' : ''; ?></span>
		<?php if ( $anchor_courses_prev['available'] ) : ?>
			</a>
		<?php else : ?>
			<span class="anchor-courses-sr-only">(<?php esc_html_e( 'Locked', 'anchor-schema' ); ?>)</span></span>
		<?php endif; ?>
	<?php endif; ?>

	<?php if ( null !== $anchor_courses_next ) : ?>
		<?php if ( $anchor_courses_next['available'] ) : ?>
			<a class="anchor-courses-button anchor-lesson-nav__link anchor-lesson-nav__link--next anchor-lesson-next" href="<?php echo esc_url( $anchor_courses_next['url'] ); ?>" rel="next">
		<?php else : ?>
			<span class="anchor-courses-button anchor-lesson-nav__link anchor-lesson-nav__link--next anchor-lesson-next is-locked" aria-disabled="true">
		<?php endif; ?>
			<span class="anchor-lesson-nav__label"><?php esc_html_e( 'Next:', 'anchor-schema' ); ?></span>
			<span class="anchor-lesson-nav__title"><?php echo esc_html( $anchor_courses_next['title'] ); ?><?php echo 'quiz' === ( $anchor_courses_next['type'] ?? '' ) ? ' <span class="anchor-lesson-nav__tag">' . esc_html__( 'Quiz', 'anchor-schema' ) . '</span>' : ''; ?></span>
		<?php if ( $anchor_courses_next['available'] ) : ?>
			</a>
		<?php else : ?>
			<span class="anchor-courses-sr-only">(<?php esc_html_e( 'Locked', 'anchor-schema' ); ?>)</span></span>
		<?php endif; ?>
	<?php else : ?>
		<a class="anchor-courses-button anchor-lesson-nav__link anchor-lesson-nav__link--next anchor-lesson-nav__link--course" href="<?php echo esc_url( $outline['course_url'] ); ?>">
			<span class="anchor-lesson-nav__title"><?php esc_html_e( 'Back to course', 'anchor-schema' ); ?></span>
		</a>
	<?php endif; ?>
</nav>
