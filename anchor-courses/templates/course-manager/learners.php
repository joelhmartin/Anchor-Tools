<?php
/**
 * Course Manager - read-only learners for one course.
 *
 * Deliberately no actions, and no credit type / provider beside the credit figure.
 *
 * @var array<int,array<string,mixed>> $rows
 * @var int                            $total
 * @var int                            $page
 * @var int                            $per_page
 * @var bool                           $has_curriculum
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$fmt_date = static function ( string $mysql ): string {
	$ts = '' === $mysql ? false : strtotime( $mysql );
	return false === $ts ? '-' : date_i18n( get_option( 'date_format' ), $ts );
};
$offset   = ( $page - 1 ) * $per_page;
$shown    = count( $rows );
?>
<section class="acm-learners" aria-label="<?php esc_attr_e( 'Learners', 'anchor-schema' ); ?>">
	<h4><?php esc_html_e( 'Learners', 'anchor-schema' ); ?></h4>
	<?php if ( 0 === $total || 0 === $shown ) : ?>
		<p><?php esc_html_e( 'Nobody is enrolled yet.', 'anchor-schema' ); ?></p>
	<?php else : ?>
		<table class="acm-table">
			<thead><tr>
				<?php foreach ( [ __( 'Learner', 'anchor-schema' ), __( 'Email', 'anchor-schema' ), __( 'Enrolled', 'anchor-schema' ), __( 'Progress', 'anchor-schema' ), __( 'Status', 'anchor-schema' ), __( 'Completed', 'anchor-schema' ), __( 'CE credits', 'anchor-schema' ), __( 'Certificate', 'anchor-schema' ) ] as $heading ) : ?>
					<th><?php echo esc_html( $heading ); ?></th>
				<?php endforeach; ?>
			</tr></thead>
			<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<tr class="acm-learner">
					<td><?php echo esc_html( (string) $row['display_name'] ); ?></td>
					<td><?php echo esc_html( (string) $row['user_email'] ); ?></td>
					<td><?php echo esc_html( $fmt_date( (string) $row['enrolled_at'] ) ); ?></td>
					<td><?php echo $has_curriculum ? esc_html( number_format_i18n( (float) $row['percent'], 0 ) . '%' ) : '&mdash;'; ?></td>
					<td><?php echo esc_html( (string) $row['status'] ); ?></td>
					<td><?php echo esc_html( $fmt_date( (string) $row['completed_at'] ) ); ?></td>
					<td><?php echo esc_html( number_format_i18n( (float) $row['credits'], 1 ) ); ?></td>
					<td>
						<?php if ( '' !== $row['certificate_number'] ) : ?>
							<a href="<?php echo esc_url( (string) $row['certificate_url'] ); ?>"><?php echo esc_html( (string) $row['certificate_number'] ); ?></a>
						<?php else : ?>
							-
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p class="acm-pagination">
			<?php
			printf(
				/* translators: 1: first row number shown, 2: last row number shown, 3: total learners. */
				esc_html__( 'Showing %1$d–%2$d of %3$d', 'anchor-schema' ),
				(int) ( $offset + 1 ),
				(int) ( $offset + $shown ),
				(int) $total
			);
			if ( $page > 1 ) {
				printf( ' <a href="%s">%s</a>', esc_url( add_query_arg( 'acm_page', $page - 1 ) ), esc_html__( 'Previous', 'anchor-schema' ) );
			}
			if ( $offset + $per_page < $total ) {
				printf( ' <a href="%s">%s</a>', esc_url( add_query_arg( 'acm_page', $page + 1 ) ), esc_html__( 'Next', 'anchor-schema' ) );
			}
			?>
		</p>
	<?php endif; ?>
</section>
