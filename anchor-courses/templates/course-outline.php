<?php
/**
 * The course outline on a lesson page or a quiz step: course title, the learner's progress,
 * and every module with its items, each marked done, current, available or
 * locked.
 *
 * Variables: $outline (array - Frontend\CourseOutline::build(): course_id,
 * course_title, course_url, progress (CourseProgress|null), modules (each
 * with title and items; an item has type, id, title, url ('' when this
 * learner cannot open it), required, current, complete, available and
 * state), previous, next).
 *
 * A quiz links to its step in this course (/courses/{course}/quiz/{quiz}/).
 *
 * Theme override: anchor-courses/course-outline.php
 *
 * @package Anchor\Courses
 */

use Anchor\Courses\Frontend\Shortcodes;

if ( ! defined( 'ABSPATH' ) ) { exit; }

$anchor_courses_states = [
	'current'   => __( 'Current step', 'anchor-schema' ),
	'done'      => __( 'Completed', 'anchor-schema' ),
	'available' => __( 'Not started', 'anchor-schema' ),
	'locked'    => __( 'Locked', 'anchor-schema' ),
];
?>
<nav class="anchor-course-outline" aria-label="<?php esc_attr_e( 'Course outline', 'anchor-schema' ); ?>">
	<p class="anchor-course-outline__course">
		<a href="<?php echo esc_url( $outline['course_url'] ); ?>"><?php echo esc_html( $outline['course_title'] ); ?></a>
	</p>

	<?php if ( null !== $outline['progress'] ) : ?>
		<?php echo Shortcodes::progress_html( $outline['progress'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- progress_html() escapes internally. ?>
	<?php endif; ?>

	<ol class="anchor-course-outline__modules">
		<?php foreach ( $outline['modules'] as $anchor_courses_module ) : ?>
			<?php if ( [] === $anchor_courses_module['items'] ) { continue; } ?>
			<li class="anchor-course-outline__module">
				<?php if ( '' !== $anchor_courses_module['title'] ) : ?>
					<p class="anchor-course-outline__module-title"><?php echo esc_html( $anchor_courses_module['title'] ); ?></p>
				<?php endif; ?>
				<ol class="anchor-course-outline__items">
					<?php foreach ( $anchor_courses_module['items'] as $anchor_courses_item ) : ?>
						<?php
						$anchor_courses_classes = 'anchor-course-outline__item anchor-course-outline__item--' . $anchor_courses_item['type'] . ' is-' . $anchor_courses_item['state'];
						if ( $anchor_courses_item['complete'] ) {
							$anchor_courses_classes .= ' is-complete';
						}
						// The visible marker is decorative; the state is announced as text.
						$anchor_courses_state_text = $anchor_courses_states[ $anchor_courses_item['state'] ] ?? '';
						if ( $anchor_courses_item['current'] && $anchor_courses_item['complete'] ) {
							$anchor_courses_state_text .= ', ' . $anchor_courses_states['done'];
						}
						?>
						<li class="<?php echo esc_attr( $anchor_courses_classes ); ?>">
							<?php if ( '' !== $anchor_courses_item['url'] ) : ?>
								<a class="anchor-course-outline__link" href="<?php echo esc_url( $anchor_courses_item['url'] ); ?>"<?php echo $anchor_courses_item['current'] ? ' aria-current="page"' : ''; ?>>
							<?php else : ?>
								<span class="anchor-course-outline__link">
							<?php endif; ?>
								<span class="anchor-course-outline__marker" aria-hidden="true"></span>
								<span class="anchor-course-outline__title">
									<?php echo esc_html( $anchor_courses_item['title'] ); ?>
									<?php if ( 'quiz' === $anchor_courses_item['type'] ) : ?>
										<span class="anchor-course-outline__tag"><?php esc_html_e( 'Quiz', 'anchor-schema' ); ?></span>
									<?php endif; ?>
									<?php if ( ! $anchor_courses_item['required'] ) : ?>
										<span class="anchor-course-outline__tag"><?php esc_html_e( 'Optional', 'anchor-schema' ); ?></span>
									<?php endif; ?>
								</span>
								<span class="anchor-courses-sr-only">(<?php echo esc_html( $anchor_courses_state_text ); ?>)</span>
							<?php if ( '' !== $anchor_courses_item['url'] ) : ?>
								</a>
							<?php else : ?>
								</span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ol>
			</li>
		<?php endforeach; ?>
	</ol>
</nav>
