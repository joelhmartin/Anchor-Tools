<?php
/**
 * The email builder UI any module can mount: subject and preheader fields, a Design
 * tab (WordPress's TinyMCE) and an HTML tab (the shared Monaco editor) over one body
 * textarea, a token palette, and a live preview the consuming module renders through
 * its own AJAX action (so the preview is always that module's real renderer).
 *
 * @package AnchorTools
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Anchor_Email_Kit {

	const VERSION = '1.0.0';

	/**
	 * @param array $args id, name, subject, preheader, body.
	 * @return string
	 */
	public static function builder_markup( array $args ) {
		$args = wp_parse_args( $args, array( 'id' => 'anchor-email', 'name' => 'anchor_email', 'subject' => '', 'preheader' => '', 'body' => '' ) );
		$id   = sanitize_html_class( $args['id'] );
		$name = preg_replace( '/[^a-z0-9_\-]/i', '', (string) $args['name'] );

		$groups = array();
		foreach ( Anchor_Email_Tokens::registered() as $key => $token ) {
			$groups[ $token['group'] ][ $key ] = $token['label'];
		}
		$palette = '';
		foreach ( $groups as $group => $tokens ) {
			$palette .= '<div class="anchor-email-builder__token-group"><span class="anchor-email-builder__token-heading">' . esc_html( $group ) . '</span>';
			foreach ( $tokens as $key => $label ) {
				$palette .= '<button type="button" class="button button-small anchor-email-builder__token" data-token="{' . esc_attr( $key ) . '}" title="{' . esc_attr( $key ) . '}">' . esc_html( $label ) . '</button>';
			}
			$palette .= '</div>';
		}

		ob_start();
		?>
		<div class="anchor-email-builder" data-anchor-email-builder id="<?php echo esc_attr( $id ); ?>">
			<div class="anchor-email-builder__edit">
				<p><label for="<?php echo esc_attr( $id ); ?>-subject"><strong><?php esc_html_e( 'Subject', 'anchor-schema' ); ?></strong></label>
				<input type="text" class="widefat anchor-email-builder__subject" id="<?php echo esc_attr( $id ); ?>-subject" name="<?php echo esc_attr( $name ); ?>[subject]" value="<?php echo esc_attr( $args['subject'] ); ?>" /></p>
				<p><label for="<?php echo esc_attr( $id ); ?>-preheader"><strong><?php esc_html_e( 'Preview text', 'anchor-schema' ); ?></strong> <span class="description"><?php esc_html_e( 'Shown after the subject in most inboxes.', 'anchor-schema' ); ?></span></label>
				<input type="text" class="widefat anchor-email-builder__preheader" id="<?php echo esc_attr( $id ); ?>-preheader" name="<?php echo esc_attr( $name ); ?>[preheader]" value="<?php echo esc_attr( $args['preheader'] ); ?>" /></p>
				<div class="anchor-email-builder__tabs" role="tablist">
					<button type="button" class="anchor-email-builder__tab is-active" data-view="design" role="tab" aria-selected="true"><?php esc_html_e( 'Design', 'anchor-schema' ); ?></button>
					<button type="button" class="anchor-email-builder__tab" data-view="html" role="tab" aria-selected="false"><?php esc_html_e( 'HTML', 'anchor-schema' ); ?></button>
				</div>
				<div class="anchor-email-builder__palette"><?php echo $palette; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?></div>
				<div class="anchor-email-builder__design">
					<textarea class="anchor-email-builder__body" id="<?php echo esc_attr( $id ); ?>-body" name="<?php echo esc_attr( $name ); ?>[body]" rows="18"><?php echo esc_textarea( $args['body'] ); ?></textarea>
				</div>
				<div class="anchor-email-builder__html" hidden>
					<div class="anchor-monaco" data-anchor-monaco='[{"id":"<?php echo esc_attr( $id ); ?>-source","label":"HTML","lang":"html"}]'>
						<textarea id="<?php echo esc_attr( $id ); ?>-source" class="anchor-email-builder__source" rows="18"></textarea>
					</div>
				</div>
			</div>
			<div class="anchor-email-builder__preview">
				<div class="anchor-email-builder__preview-head">
					<strong><?php esc_html_e( 'Preview', 'anchor-schema' ); ?></strong>
					<span class="anchor-email-builder__status" aria-live="polite"></span>
				</div>
				<iframe class="anchor-email-builder__frame" title="<?php esc_attr_e( 'Email preview', 'anchor-schema' ); ?>" sandbox=""></iframe>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Enqueue the builder on the current admin screen (caller guards the screen).
	 *
	 * @param array  $config ajaxUrl, nonce, previewAction, tokens, emptyTokens.
	 * @param string $cpt    Post type, for Anchor_Monaco.
	 */
	public static function enqueue( array $config, $cpt ) {
		Anchor_Monaco::enqueue( $cpt );
		wp_enqueue_editor();
		wp_enqueue_style( 'anchor-email-kit', Anchor_Asset_Loader::url( 'assets/email-kit/builder.css' ), array(), self::VERSION );
		wp_enqueue_script( 'anchor-email-kit', Anchor_Asset_Loader::url( 'assets/email-kit/builder.js' ), array( 'jquery', 'editor' ), self::VERSION, true );
		wp_localize_script( 'anchor-email-kit', 'ANCHOR_EMAIL_KIT', $config );
	}
}
