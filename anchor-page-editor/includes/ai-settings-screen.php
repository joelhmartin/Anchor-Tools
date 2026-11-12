<?php
/**
 * Anchor Page Editor: AI provider settings screen.
 *
 * In scope: $saved, $providers, $active.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<h1><?php esc_html_e( 'AI Page Assistant', 'anchor-schema' ); ?></h1>
	<p><?php esc_html_e( 'Configure the AI provider used by the inline page editor. Keys are stored on the server and never sent to the browser.', 'anchor-schema' ); ?></p>
	<form method="post" action="options.php">
		<?php settings_fields( 'anchor_page_editor_ai_group' ); ?>
		<table class="form-table" role="presentation"><tbody>
			<tr>
				<th scope="row"><label for="ac-ai-active"><?php esc_html_e( 'Active provider', 'anchor-schema' ); ?></label></th>
				<td>
					<select id="ac-ai-active" name="ac_ai_settings[active]">
						<option value="" <?php selected( $active, '' ); ?>><?php esc_html_e( '— none —', 'anchor-schema' ); ?></option>
						<?php foreach ( $providers as $key => $def ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $active, $key ); ?>><?php echo esc_html( $def[0] ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<?php foreach ( $providers as $key => $def ) :
				$has_key = ! empty( $saved[ $key ]['api_key'] );
				$model   = isset( $saved[ $key ]['model'] ) && '' !== $saved[ $key ]['model'] ? $saved[ $key ]['model'] : $def[1];
				?>
				<tr>
					<th scope="row"><?php echo esc_html( $def[0] ); ?></th>
					<td>
						<p>
							<label><?php esc_html_e( 'API key', 'anchor-schema' ); ?><br>
							<input type="password" class="regular-text" name="ac_ai_settings[<?php echo esc_attr( $key ); ?>][api_key]" value="" autocomplete="off"
								placeholder="<?php echo $has_key ? esc_attr__( '•••••••• (saved — leave blank to keep)', 'anchor-schema' ) : esc_attr__( 'Paste API key', 'anchor-schema' ); ?>" /></label>
						</p>
						<p>
							<label><?php esc_html_e( 'Model', 'anchor-schema' ); ?><br>
							<input type="text" class="regular-text" name="ac_ai_settings[<?php echo esc_attr( $key ); ?>][model]" value="<?php echo esc_attr( $model ); ?>" /></label>
						</p>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody></table>
		<?php submit_button(); ?>
	</form>
</div>
