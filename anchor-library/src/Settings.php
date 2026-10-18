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
		\register_setting(self::GROUP, Schema::OPT_VIMEO_TOKEN, [
			'type' => 'string',
			'sanitize_callback' => function ($v) { return \sanitize_text_field((string) $v); },
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
						<th scope="row">Vimeo access token</th>
						<td>
							<input type="text" class="regular-text" name="<?php echo \esc_attr(Schema::OPT_VIMEO_TOKEN); ?>" value="<?php echo \esc_attr(\get_option(Schema::OPT_VIMEO_TOKEN, '')); ?>" autocomplete="off">
							<p class="description">Optional. Used only for future aggregate Vimeo stats; per-user watch history works without it. A <code>VIMEO_ACCESS_TOKEN</code> environment variable or constant overrides this field.</p>
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

	/** Vimeo token: the environment, then a constant, then the saved option. */
	public static function vimeo_token() {
		$env = \getenv('VIMEO_ACCESS_TOKEN');
		if (!empty($env)) return (string) $env;
		if (\defined('VIMEO_ACCESS_TOKEN') && VIMEO_ACCESS_TOKEN) return (string) VIMEO_ACCESS_TOKEN;
		return (string) \get_option(Schema::OPT_VIMEO_TOKEN, '');
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
