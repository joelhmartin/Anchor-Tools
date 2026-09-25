<?php
/**
 * Learner dashboard ([anchor_my_courses]).
 *
 * Variables: $user_id, $enrollments (Enrollment[]), $progress (ProgressService).
 *
 * Theme override: anchor-courses/dashboard.php
 *
 * @package Anchor\Courses
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div class="anchor-courses-dashboard">
	<?php if ( empty( $enrollments ) ) : ?>
		<p class="anchor-courses-notice"><?php esc_html_e( 'You are not enrolled in any courses yet.', 'anchor-schema' ); ?></p>
	<?php else : ?>
		<ul class="anchor-courses-dashboard-list">
			<?php foreach ( $enrollments as $enrollment ) : ?>
				<?php $course_progress = $progress->get_course_progress( $user_id, $enrollment->course_id ); ?>
				<li class="anchor-courses-dashboard-item">
					<a href="<?php echo esc_url( (string) get_permalink( $enrollment->course_id ) ); ?>">
						<?php echo esc_html( get_the_title( $enrollment->course_id ) ); ?>
					</a>
					<span class="anchor-courses-status"><?php echo esc_html( $enrollment->status ); ?></span>
					<span class="anchor-courses-percent">
						<?php echo esc_html( number_format_i18n( $course_progress->percent, 0 ) . '%' ); ?>
					</span>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>
