<?php
/**
 * Course Manager - course list.
 *
 * @var \WP_Post[] $courses
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$status_labels = [
	'publish' => __( 'Published', 'anchor-schema' ),
	'future'  => __( 'Scheduled', 'anchor-schema' ),
	'draft'   => __( 'Draft', 'anchor-schema' ),
	'private' => __( 'Private', 'anchor-schema' ),
	'pending' => __( 'Pending', 'anchor-schema' ),
];
?>
<div class="acm acm-list">
	<?php if ( empty( $courses ) ) : ?>
		<p><?php esc_html_e( 'No courses yet.', 'anchor-schema' ); ?></p>
	<?php else : ?>
		<table class="acm-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Course', 'anchor-schema' ); ?></th>
					<th><?php esc_html_e( 'Status', 'anchor-schema' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $courses as $course ) : ?>
				<tr>
					<td><a href="<?php echo esc_url( add_query_arg( 'acm_course', (int) $course->ID ) ); ?>"><?php echo esc_html( get_the_title( $course ) ); ?></a></td>
					<td><?php echo esc_html( $status_labels[ $course->post_status ] ?? $course->post_status ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
