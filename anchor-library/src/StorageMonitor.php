<?php
/**
 * Library storage exposure monitor: runs the daily check, remembers its result, pauses uploads while the store is
 * publicly downloadable, and tells administrators.
 *
 * Ported from Anchor Private File Manager 2.17.0 (run_storage_check, require_storage_not_exposed,
 * handle_storage_check_request, render_storage_notice, render_storage_status). The probe itself is StorageGuard.
 *
 * @package Anchor\Library
 */

namespace Anchor\Library;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

class StorageMonitor {

	/** Hook the daily event, the "Check now" button and the admin notice. */
	public static function register(): void {
		\add_action( Schema::CRON_STORAGE_CHECK, [ self::class, 'run_check' ] );
		\add_action( 'admin_post_anchor_fm_storage_check', [ self::class, 'handle_request' ] );
		\add_action( 'admin_notices', [ self::class, 'render_notice' ] );
	}

	public static function run_check() {
		Storage::ensure();
		$result = StorageGuard::check(Storage::dirs());
		// Autoloaded: the admin notice reads it on every wp-admin page.
		\update_option(Schema::OPT_STORAGE_CHECK, $result, true);
		return $result;
	}

	public static function result() {
		$r = \get_option(Schema::OPT_STORAGE_CHECK, []);
		return \is_array($r) ? $r : [];
	}

	/** True only when the last check actually downloaded a stored file anonymously. */
	public static function is_exposed() {
		$r = self::result();
		return isset($r['status']) && $r['status'] === StorageGuard::STATUS_EXPOSED;
	}

	/** Refuse new uploads while the store is confirmed public, so nothing new lands there. */
	public static function require_not_exposed() {
		if (self::is_exposed()) {
			Http::error('Uploads are paused: the private file store can be downloaded without logging in. An administrator must fix this (Settings → Anchor File Manager) before new files can be added.', 503);
		}
	}

	public static function handle_request() {
		if (!\current_user_can('manage_options')) \wp_die('Forbidden', 403);
		\check_admin_referer('anchor_fm_storage_check');
		self::run_check();
		\wp_safe_redirect(\admin_url('options-general.php?page=anchor-private-file-manager'));
		Http::halt();
	}

	public static function render_notice() {
		if (!\current_user_can('manage_options')) return;
		$r = self::result();
		$status = isset($r['status']) ? $r['status'] : '';
		$url = \admin_url('options-general.php?page=anchor-private-file-manager');
		if ($status === StorageGuard::STATUS_EXPOSED) {
			echo '<div class="notice notice-error"><p><strong>Anchor File Manager:</strong> private files can be downloaded without logging in, and uploads are paused. <a href="' . \esc_url($url) . '">See details</a>.</p></div>';
		} elseif ($status === StorageGuard::STATUS_UNKNOWN) {
			// Uploads stay open — a host that blocks loopback requests would
			// otherwise lose uploads with no exposure found — but it is shown.
			echo '<div class="notice notice-warning"><p><strong>Anchor File Manager:</strong> could not confirm that private files are protected from public download. <a href="' . \esc_url($url) . '">Check it</a>.</p></div>';
		}
	}

	public static function render_status() {
		$r = self::result();
		$status = isset($r['status']) ? $r['status'] : '';
		$labels = [
			StorageGuard::STATUS_PROTECTED => ['Protected', '#1a7f37'],
			StorageGuard::STATUS_EXPOSED   => ['EXPOSED — files are publicly downloadable', '#cf222e'],
			StorageGuard::STATUS_UNKNOWN   => ['Could not verify', '#9a6700'],
		];
		[$label, $color] = isset($labels[$status]) ? $labels[$status] : ['Not checked yet', '#57606a'];
		?>
		<h2>Private storage</h2>
		<p>
			<strong style="color: <?php echo \esc_attr($color); ?>"><?php echo \esc_html($label); ?></strong>
			<?php if (!empty($r['checked_at'])) : ?>
				— checked <?php echo \esc_html($r['checked_at']); ?>
			<?php endif; ?>
		</p>
		<?php if (!empty($r['details'])) : ?>
			<ul style="list-style: disc; margin-left: 20px;">
				<?php foreach ((array) $r['details'] as $d) : ?>
					<li><code><?php echo \esc_html($d['dir']); ?></code> — <?php echo \esc_html($d['note']); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<p class="description">
			The plugin puts a random test file in each storage folder and requests it from this server without logging in.
			"Protected" means the origin refused it; a CDN in front of the site is not covered and should be checked separately.
			If this says exposed, block the folder in the web-server config and check again. Nginx: <code>location ~ /\.anchor-private-files { deny all; return 403; }</code> and <code>location ~ /anchor-private-files { deny all; return 403; }</code> (the leading dot is already denied by Kinsta; the second covers the legacy folder). Also keep the folder at mode 0700. Full notes: <a href="https://github.com/joelhmartin/Anchor-Tools/blob/main/anchor-library/PRIVATE-STORAGE.md" target="_blank" rel="noopener">PRIVATE-STORAGE.md</a>.
		</p>
		<form method="post" action="<?php echo \esc_url(\admin_url('admin-post.php')); ?>">
			<input type="hidden" name="action" value="anchor_fm_storage_check">
			<?php \wp_nonce_field('anchor_fm_storage_check'); ?>
			<?php \submit_button('Check now', 'secondary', 'submit', false); ?>
		</form>
		<?php
	}
}
