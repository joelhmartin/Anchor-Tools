<?php
/**
 * Course Manager - settings form for one course.
 *
 * @var \WP_Post            $course
 * @var array<string,mixed> $values
 * @var bool                $saved
 */
use Anchor\Courses\Frontend\CourseManager;

if ( ! defined( 'ABSPATH' ) ) { exit; }

$field = static function ( string $key, string $label, string $type = 'text' ) use ( $values ): void {
	printf(
		'<p class="acm-field"><label for="acm-%1$s">%2$s</label><input type="%4$s" id="acm-%1$s" name="anchor_course_manager[%1$s]" value="%3$s" /></p>',
		esc_attr( $key ),
		esc_html( $label ),
		esc_attr( (string) ( $values[ $key ] ?? '' ) ),
		esc_attr( $type )
	);
};
?>
<div class="acm acm-edit">
	<p class="acm-back"><a href="<?php echo esc_url( remove_query_arg( CourseManager::QUERY_ARGS ) ); ?>"><?php esc_html_e( 'Back to courses', 'anchor-schema' ); ?></a></p>
	<h3><?php echo esc_html( get_the_title( $course ) ); ?></h3>

	<?php if ( $saved ) : ?>
		<p class="acm-notice" role="status"><?php esc_html_e( 'Course settings saved.', 'anchor-schema' ); ?></p>
	<?php endif; ?>

	<form method="post" action="">
		<?php wp_nonce_field( CourseManager::NONCE ); ?>
		<input type="hidden" name="acm_course" value="<?php echo esc_attr( (string) (int) $course->ID ); ?>" />

		<fieldset>
			<legend><?php esc_html_e( 'CE credits', 'anchor-schema' ); ?></legend>
			<?php
			$field( 'ce_credits', __( 'Credits awarded', 'anchor-schema' ) );
			$field( 'ce_type', __( 'Credit type', 'anchor-schema' ) );
			$field( 'ce_provider_name', __( 'Provider name', 'anchor-schema' ) );
			$field( 'ce_provider_number', __( 'Provider number', 'anchor-schema' ) );
			$field( 'ce_expires_days', __( 'Credits expire after (days, 0 = never)', 'anchor-schema' ) );
			?>
		</fieldset>

		<fieldset>
			<legend><?php esc_html_e( 'Certificate', 'anchor-schema' ); ?></legend>
			<input type="hidden" name="anchor_course_manager[certificate_enabled]" value="0" />
			<p class="acm-field acm-check"><label><input type="checkbox" name="anchor_course_manager[certificate_enabled]" value="1"<?php checked( (string) $values['certificate_enabled'], '1' ); ?> /> <?php esc_html_e( 'Issue a certificate on completion', 'anchor-schema' ); ?></label></p>
			<?php $field( 'certificate_template', __( 'Certificate template slug', 'anchor-schema' ) ); ?>
		</fieldset>

		<fieldset>
			<legend><?php esc_html_e( 'Availability', 'anchor-schema' ); ?></legend>
			<?php
			$field( 'instructor', __( 'Instructor', 'anchor-schema' ) );
			$field( 'available_from', __( 'Available from (YYYY-MM-DD)', 'anchor-schema' ) );
			$field( 'available_until', __( 'Available until (YYYY-MM-DD)', 'anchor-schema' ) );
			$field( 'expiration_days', __( 'Enrolment expires after (days, 0 = never)', 'anchor-schema' ) );
			?>
		</fieldset>

		<p><button type="submit" class="acm-button"><?php esc_html_e( 'Save settings', 'anchor-schema' ); ?></button></p>
	</form>
</div>
