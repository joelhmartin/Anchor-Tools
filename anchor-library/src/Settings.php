<?php
/**
 * Library settings: the Settings -> Anchor File Manager page and the option fallbacks that depend on it.
 *
 * Ported from Anchor Private File Manager 2.17.0. Option keys, the settings group (anchor_private_file_manager),
 * the page slug (anchor-private-file-manager) and the page's HTML are unchanged.
 *
 * @package Anchor\Library
 */

namespace Anchor\Library;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

class Settings {

	public const GROUP = 'anchor_private_file_manager';

	public static function register(): void {
		\add_action( 'admin_menu', [ self::class, 'register_page' ] );
		\add_action( 'admin_init', [ self::class, 'register_settings' ] );
	}

	public static function register_page() {
		\add_options_page(
			'Anchor Private File Manager',
			'Anchor File Manager',
			'manage_options',
			'anchor-private-file-manager',
			[self::class, 'render_settings_page']
		);
	}

	public static function register_settings() {
		\register_setting(self::GROUP, Schema::OPT_EMAIL_ON_UPLOAD, [
			'type' => 'boolean',
			'sanitize_callback' => function ($value) {
				return (int) (bool) $value;
			},
			'default' => 0,
		]);
		// anchor_fm_vimeo_token is deliberately NOT registered: the field was dead (nothing reads it) and options.php
		// would blank the stored value on save if it were registered without a form field. The option stays in the DB.
		// Write-only: the form never carries the saved value, so a blank submit must keep it (see sanitize_vimeo_token).
		\register_setting(self::GROUP, \Anchor_Vimeo_Api::OPTION, [
			'type' => 'string',
			'sanitize_callback' => [ self::class, 'sanitize_vimeo_token' ],
			'default' => '',
		]);
		\register_setting(self::GROUP, Schema::OPT_REQUEST_ACCESS_EMAIL, [
			'type' => 'string',
			'sanitize_callback' => function ($v) {
				// Blank is allowed and means "use the admin email at send time",
				// so a later admin_email change still takes effect.
				return \sanitize_email((string) $v);
			},
			'default' => '',
		]);
		\register_setting(self::GROUP, Schema::OPT_PORTAL_LOGO, [
			'type' => 'string',
			'sanitize_callback' => function ($v) { return \esc_url_raw(\trim((string) $v)); },
			'default' => '',
		]);
	}

	/**
	 * Blank keeps the saved token, the Clear box empties it, anything else replaces it. Must stay free of option writes:
	 * add_option() runs this sanitizer too, so writing here recurses forever on the first save. Autoload-off is
	 * Anchor_Vimeo_Api::default_autoload().
	 *
	 * Only the form's Clear box removes a saved token: a programmatic update_option( OPTION, '' ) also lands here and
	 * keeps it. Use delete_option() or WP-CLI `option delete` to remove it from code.
	 */
	public static function sanitize_vimeo_token( $value ) {
		$opt = \Anchor_Vimeo_Api::OPTION;
		if ( ! empty( $_POST[ $opt . '_clear' ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- options.php verified the nonce.
			return '';
		}
		$value = \trim( \sanitize_text_field( (string) $value ) );
		return '' === $value ? (string) \get_option( $opt, '' ) : $value;
	}

	public static function render_settings_page() {
		if (!\current_user_can('manage_options')) return;
		?>
		<div class="wrap">
			<h1>Anchor File Manager</h1>
			<form method="post" action="options.php">
				<?php
				\settings_fields('anchor_private_file_manager');
				?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Upload email notifications</th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo \esc_attr(Schema::OPT_EMAIL_ON_UPLOAD); ?>" value="1" <?php \checked((int) \get_option(Schema::OPT_EMAIL_ON_UPLOAD, 0), 1); ?>>
								Send an email to administrators when a file is uploaded
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row">Portal logo URL</th>
						<td>
							<input type="url" class="regular-text" name="<?php echo \esc_attr(Schema::OPT_PORTAL_LOGO); ?>" value="<?php echo \esc_attr(\get_option(Schema::OPT_PORTAL_LOGO, '')); ?>">
							<p class="description">Shown in the portal sidebar. Leave blank to use the site icon (Settings → General); if there is no site icon, no logo is shown.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Vimeo token for app downloads</th>
						<td>
							<?php $vimeo_set = '' !== (string) \get_option(\Anchor_Vimeo_Api::OPTION, ''); ?>
							<p><strong><?php echo $vimeo_set ? 'Set' : 'Not set'; ?></strong></p>
							<input type="password" class="regular-text" autocomplete="new-password" name="<?php echo \esc_attr(\Anchor_Vimeo_Api::OPTION); ?>" value="" placeholder="<?php echo $vimeo_set ? 'Enter a new token to replace it' : ''; ?>">
							<?php if ($vimeo_set) : ?>
								<label style="display:block;margin-top:6px;">
									<input type="checkbox" name="<?php echo \esc_attr(\Anchor_Vimeo_Api::OPTION . '_clear'); ?>" value="1"> Clear the saved token
								</label>
							<?php endif; ?>
							<p class="description">A Vimeo access token with only the public, private and video_files scopes (Vimeo needs all three for download links). It lets the member app save videos for offline viewing. Leave blank to keep the current token. If empty, the Webinars Vimeo API key is used.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Request-access recipient</th>
						<td>
							<input type="email" class="regular-text" name="<?php echo \esc_attr(Schema::OPT_REQUEST_ACCESS_EMAIL); ?>" value="<?php echo \esc_attr(\get_option(Schema::OPT_REQUEST_ACCESS_EMAIL, '')); ?>" placeholder="<?php echo \esc_attr(\get_option('admin_email')); ?>">
							<p class="description">Where "Request access" messages are sent. Leave blank to use the site admin email.</p>
						</td>
					</tr>
				</table>
				<?php \submit_button(); ?>
			</form>
			<?php StorageMonitor::render_status(); ?>
		</div>
		<?php
	}

	/** Where "Request access" messages go: the saved address, else the site admin email. */
	public static function request_access_email() {
		$admin = (string) \get_option('admin_email');
		$email = \sanitize_email((string) \get_option(Schema::OPT_REQUEST_ACCESS_EMAIL, $admin));
		return $email ?: $admin;
	}

	/** Portal sidebar logo: the saved URL, else the site icon, else nothing. */
	public static function portal_logo_url() {
		$url = (string) \get_option(Schema::OPT_PORTAL_LOGO, '');
		if ($url !== '') return $url;
		return (string) \get_site_icon_url(96);
	}
}
