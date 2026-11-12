<?php
/**
 * Anchor Page Editor: the Monaco editor screen (markup identical to Anchor Framework so editor.js works unchanged).
 *
 * In scope: $post, $css, $js, $active.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="ac-monaco-wrap">
	<div class="ac-monaco-toolbar">
		<div class="ac-tabs" role="tablist">
			<button type="button" class="ac-tab is-active" data-tab="html"><?php esc_html_e( 'HTML', 'anchor-schema' ); ?></button>
			<button type="button" class="ac-tab" data-tab="css"><?php esc_html_e( 'CSS', 'anchor-schema' ); ?></button>
			<button type="button" class="ac-tab" data-tab="js"><?php esc_html_e( 'JS', 'anchor-schema' ); ?></button>
		</div>
		<button type="button" class="button" id="ac-format-code" title="<?php esc_attr_e( 'Re-indent / beautify the active tab', 'anchor-schema' ); ?>">
			<span class="dashicons dashicons-editor-code"></span>
			<?php esc_html_e( 'Format Code', 'anchor-schema' ); ?>
		</button>
		<button type="button" class="button" id="ac-media-button">
			<span class="dashicons dashicons-admin-media"></span>
			<?php esc_html_e( 'Insert from Media Library', 'anchor-schema' ); ?>
		</button>
		<button type="button" class="button" id="ac-ai-toggle">
			<span class="dashicons dashicons-superhero"></span>
			<?php esc_html_e( 'AI Assistant', 'anchor-schema' ); ?>
		</button>
		<span class="ac-monaco-hint"><?php esc_html_e( 'Editing raw page HTML, CSS, and JS.', 'anchor-schema' ); ?></span>
	</div>

	<div id="ac-monaco-editor" class="ac-monaco-editor"></div>

	<div class="ac-ai-panel" id="ac-ai-panel" hidden>
		<?php if ( $active ) : ?>
			<div class="ac-ai-row">
				<textarea id="ac-ai-prompt" class="ac-ai-prompt" rows="2" placeholder="<?php esc_attr_e( 'Describe the change… (e.g. "make the hero heading larger and use the accent color")', 'anchor-schema' ); ?>"></textarea>
				<button type="button" class="button button-primary" id="ac-ai-send"><?php esc_html_e( 'Ask AI', 'anchor-schema' ); ?></button>
			</div>
			<div class="ac-ai-meta"><?php echo esc_html( sprintf( /* translators: provider name */ __( 'Provider: %s', 'anchor-schema' ), $active ) ); ?></div>
			<div class="ac-ai-status" id="ac-ai-status" aria-live="polite"></div>
			<div class="ac-ai-results" id="ac-ai-results"></div>
		<?php else : ?>
			<p class="ac-ai-meta">
				<?php
				printf(
					/* translators: %s settings page link */
					esc_html__( 'No AI provider configured. Set one up in %s.', 'anchor-schema' ),
					'<a href="' . esc_url( Anchor_Page_Editor_Module::ai_settings_url() ) . '">' . esc_html__( 'AI Assistant settings', 'anchor-schema' ) . '</a>'
				);
				?>
			</p>
		<?php endif; ?>
	</div>

	<textarea id="content" name="content" class="ac-monaco-hidden"><?php echo esc_textarea( $post->post_content ); ?></textarea>
	<textarea id="ac_custom_css" name="ac_custom_css" class="ac-monaco-hidden"><?php echo esc_textarea( $css ); ?></textarea>
	<textarea id="ac_custom_js" name="ac_custom_js" class="ac-monaco-hidden"><?php echo esc_textarea( $js ); ?></textarea>
</div>
