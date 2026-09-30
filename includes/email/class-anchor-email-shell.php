<?php
/**
 * The branded, table-based email layout every Anchor Tools email can share: a 600px
 * card on a tinted background, optional logo, body, footer. Ported from the events
 * module's default shell.
 *
 * @package AnchorTools
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Anchor_Email_Shell {

	/**
	 * @param array $args {
	 *     @type string $title       Document title.
	 *     @type string $preheader   Hidden inbox preview text.
	 *     @type string $body        Body HTML (already sanitized and token-expanded).
	 *     @type string $footer      Footer HTML (address, unsubscribe).
	 *     @type string $brand_color Hex color for the header rule and links.
	 *     @type string $logo_url    Optional logo URL.
	 * }
	 * @return string Full HTML document.
	 */
	public static function render( array $args ) {
		$args  = wp_parse_args(
			$args,
			array( 'title' => '', 'preheader' => '', 'body' => '', 'footer' => '', 'brand_color' => '#1a4f48', 'logo_url' => '' )
		);
		$color = sanitize_hex_color( (string) $args['brand_color'] );
		$color = $color ? $color : '#1a4f48';

		$preheader = '' !== $args['preheader']
			? '<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;">' . esc_html( $args['preheader'] ) . '</div>'
			: '';
		$logo      = '' !== $args['logo_url']
			? '<tr><td style="padding:24px 32px 0;"><img src="' . esc_url( $args['logo_url'] ) . '" alt="' . esc_attr( get_bloginfo( 'name' ) ) . '" style="max-width:200px;height:auto;border:0;" /></td></tr>'
			: '';

		return '<!DOCTYPE html><html><head><meta charset="UTF-8" /><meta name="viewport" content="width=device-width,initial-scale=1" /><title>' . esc_html( $args['title'] ) . '</title></head>'
			. '<body style="margin:0;padding:0;background:#f4f5f5;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;">'
			. $preheader
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f5;padding:24px 12px;"><tr><td align="center">'
			. '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:8px;overflow:hidden;border-top:4px solid ' . $color . ';">'
			. $logo
			. '<tr><td style="padding:24px 32px;font-size:16px;line-height:1.6;color:#1f2a28;">' . $args['body'] . '</td></tr>'
			. '<tr><td style="padding:16px 32px 24px;border-top:1px solid #eeeeee;font-size:12px;line-height:1.5;color:#777777;">' . $args['footer'] . '</td></tr>'
			. '</table></td></tr></table></body></html>';
	}
}
