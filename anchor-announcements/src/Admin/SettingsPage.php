<?php
declare(strict_types=1);

namespace Anchor\Announcements\Admin;

use Anchor\Announcements\Content\AnnouncementPostType as PT;
use Anchor\Announcements\Module;
use Anchor\Announcements\Support\Settings;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** Announcements > Settings. */
final class SettingsPage {

	public const SLUG = 'anchor-announcements-settings';

	public function __construct() {
		\add_action( 'admin_menu', [ $this, 'menu' ] );
		\add_action( 'admin_post_anchor_announcements_settings', [ $this, 'save' ] );
	}

	public function menu(): void {
		\add_submenu_page( 'edit.php?post_type=' . PT::CPT, \__( 'Announcement settings', 'anchor-schema' ), \__( 'Settings', 'anchor-schema' ), Module::CAP, self::SLUG, [ $this, 'render' ] );
	}

	public function save(): void {
		if ( ! \current_user_can( Module::CAP ) ) {
			\wp_die( \esc_html__( 'You are not allowed to do that.', 'anchor-schema' ), 403 );
		}
		\check_admin_referer( 'anchor_announcements_settings' );
		Settings::save( (array) \wp_unslash( $_POST['settings'] ?? [] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Settings::save sanitizes each key.
		\wp_safe_redirect( \add_query_arg( [ 'post_type' => PT::CPT, 'page' => self::SLUG, 'updated' => 1 ], \admin_url( 'edit.php' ) ) );
		exit;
	}

	public function render(): void {
		if ( ! \current_user_can( Module::CAP ) ) {
			\wp_die( \esc_html__( 'You are not allowed to do that.', 'anchor-schema' ), 403 );
		}
		$s = Settings::get();
		$fields = [
			'from_name'   => [ \__( 'From name', 'anchor-schema' ), 'text', '' ],
			'from_email'  => [ \__( 'From email', 'anchor-schema' ), 'email', \__( 'Leave empty to use the site default. Must be an address your mail provider can send as.', 'anchor-schema' ) ],
			'reply_to'    => [ \__( 'Reply-to', 'anchor-schema' ), 'email', '' ],
			'brand_color' => [ \__( 'Brand color', 'anchor-schema' ), 'text', \__( 'Hex, e.g. #1a4f48.', 'anchor-schema' ) ],
			'logo_url'    => [ \__( 'Logo URL', 'anchor-schema' ), 'url', '' ],
			'batch_size'  => [ \__( 'Emails per minute', 'anchor-schema' ), 'number', \__( '1 to 500. Keep within your mail provider\'s rate limit.', 'anchor-schema' ) ],
		];
		?>
		<div class="wrap">
			<h1><?php \esc_html_e( 'Announcement settings', 'anchor-schema' ); ?></h1>
			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success"><p><?php \esc_html_e( 'Settings saved.', 'anchor-schema' ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo \esc_url( \admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="anchor_announcements_settings" />
				<?php \wp_nonce_field( 'anchor_announcements_settings' ); ?>
				<table class="form-table" role="presentation">
					<?php foreach ( $fields as $key => $f ) : ?>
						<tr>
							<th scope="row"><label for="aa-<?php echo \esc_attr( $key ); ?>"><?php echo \esc_html( $f[0] ); ?></label></th>
							<td>
								<input class="regular-text" type="<?php echo \esc_attr( $f[1] ); ?>" id="aa-<?php echo \esc_attr( $key ); ?>" name="settings[<?php echo \esc_attr( $key ); ?>]" value="<?php echo \esc_attr( (string) $s[ $key ] ); ?>" />
								<?php if ( '' !== $f[2] ) : ?><p class="description"><?php echo \esc_html( $f[2] ); ?></p><?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					<tr>
						<th scope="row"><label for="aa-footer_address"><?php \esc_html_e( 'Mailing address (required)', 'anchor-schema' ); ?></label></th>
						<td>
							<textarea class="large-text" rows="3" id="aa-footer_address" name="settings[footer_address]"><?php echo \esc_textarea( (string) $s['footer_address'] ); ?></textarea>
							<p class="description"><?php \esc_html_e( 'Printed in every announcement\'s footer. Anti-spam law (CAN-SPAM, CASL) requires a physical postal address; nothing sends until this is filled in.', 'anchor-schema' ); ?></p>
						</td>
					</tr>
				</table>
				<?php \submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
