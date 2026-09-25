<?php
/**
 * Course page body.
 *
 * Variables: $course_id, $user_id, $modules, $progress (CourseProgress|null),
 * $is_enrolled (bool - true only for an ACTIVE enrolment; a cancelled or
 * expired row reads false, same as no row at all), $service (ProgressService).
 *
 * Theme override: anchor-courses/course.php
 *
 * @package Anchor\Courses
 */

use Anchor\Courses\Admin\CourseEditor;
use Anchor\Courses\Frontend\Access;
use Anchor\Courses\Frontend\Actions;

if ( ! defined( 'ABSPATH' ) ) { exit; }

$notice = Actions::notice();
?>
<div class="anchor-course" data-course="<?php echo esc_attr( (string) $course_id ); ?>">

	<?php if ( '' !== $notice ) : ?>
		<p class="anchor-courses-notice"><?php echo esc_html( Actions::notice_text( $notice ) ); ?></p>
	<?php endif; ?>

	<h2 class="anchor-course-title"><?php echo esc_html( get_the_title( $course_id ) ); ?></h2>

	<div class="anchor-course-meta">
		<?php $instructor = (string) CourseEditor::setting( $course_id, 'instructor' ); ?>
		<?php if ( '' !== $instructor ) : ?>
			<span class="anchor-course-instructor"><?php echo esc_html( $instructor ); ?></span>
		<?php endif; ?>

		<?php $duration = (string) CourseEditor::setting( $course_id, 'duration' ); ?>
		<?php if ( '' !== $duration ) : ?>
			<span class="anchor-course-duration"><?php echo esc_html( $duration ); ?></span>
		<?php endif; ?>

		<?php $credits = (float) CourseEditor::setting( $course_id, 'ce_credits' ); ?>
		<?php if ( $credits > 0 ) : ?>
			<span class="anchor-course-credits">
				<?php
				printf(
					/* translators: %s: number of CE credits. */
					esc_html__( '%s CE credits', 'anchor-schema' ),
					esc_html( number_format_i18n( $credits, 1 ) )
				);
				?>
			</span>
		<?php endif; ?>
	</div>

	<div class="anchor-course-description"><?php echo wp_kses_post( get_the_excerpt( $course_id ) ); ?></div>

	<?php if ( $progress && $is_enrolled ) : ?>
		<?php echo do_shortcode( '[anchor_course_progress course_id="' . (int) $course_id . '"]' ); ?>
	<?php endif; ?>

	<ol class="anchor-course-curriculum">
		<?php foreach ( $modules as $module ) : ?>
			<li class="anchor-course-module">
				<h3 class="anchor-course-module-title"><?php echo esc_html( $module['title'] ); ?></h3>
				<?php if ( '' !== $module['description'] ) : ?>
					<div class="anchor-course-module-description"><?php echo wp_kses_post( $module['description'] ); ?></div>
				<?php endif; ?>
				<ul class="anchor-course-items">
					<?php foreach ( $module['items'] as $item ) : ?>
						<?php
						$available = $user_id > 0 && $service->is_item_available( $user_id, $course_id, (int) $item['id'], $item['type'] );
						$done      = $progress && in_array( $item['type'] . ':' . $item['id'], $progress->completed_item_keys, true );
						?>
						<li class="anchor-course-item anchor-course-item--<?php echo esc_attr( $item['type'] ); ?><?php echo $done ? ' is-complete' : ''; ?><?php echo $available ? '' : ' is-locked'; ?>">
							<?php if ( $available && 'lesson' === $item['type'] ) : ?>
								<a href="<?php echo esc_url( (string) get_permalink( (int) $item['id'] ) ); ?>"><?php echo esc_html( get_the_title( (int) $item['id'] ) ); ?></a>
							<?php else : ?>
								<span><?php echo esc_html( get_the_title( (int) $item['id'] ) ); ?></span>
							<?php endif; ?>
							<?php if ( ! $item['required'] ) : ?>
								<em class="anchor-course-optional"><?php esc_html_e( 'Optional', 'anchor-schema' ); ?></em>
							<?php endif; ?>
							<?php if ( 'quiz' === $item['type'] && $available ) : ?>
								<?php
								// $available already required enrolment + progression
								// (ProgressService::is_item_available(), the same
								// authority Frontend\Access delegates to for a lesson)
								// - a non-enrolled or locked learner never reaches this
								// branch at all. render_quiz() escapes its own output.
								$anchor_courses_module = \Anchor\Courses\Module::instance();
								echo $anchor_courses_module ? $anchor_courses_module->shortcodes->render_quiz( (int) $item['id'] ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput -- render_quiz() escapes internally.
								?>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</li>
		<?php endforeach; ?>
	</ol>

	<?php
	/*
	 * Access CTA. Never a self-enrol form: either a link an integration
	 * supplied (a WooCommerce product that grants this course), or a line of
	 * text telling the visitor how to ask. See Frontend\Access.
	 */
	if ( ! $is_enrolled ) :
		$cta = Access::cta( $course_id, $user_id );
		?>
		<?php if ( '' !== $cta['url'] ) : ?>
			<p class="anchor-course-cta">
				<a class="anchor-courses-button" href="<?php echo esc_url( $cta['url'] ); ?>">
					<?php echo esc_html( $cta['label'] ); ?>
				</a>
			</p>
		<?php elseif ( '' !== $cta['message'] ) : ?>
			<p class="anchor-course-cta anchor-course-cta--ask"><?php echo esc_html( $cta['message'] ); ?></p>
		<?php endif; ?>
	<?php endif; ?>
</div>
