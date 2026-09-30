<?php
/**
 * Anchor Tools module: Anchor Announcements.
 *
 * Compose a branded email with the shared email kit, pick recipients with AND/OR
 * rules, send through wp_mail() in background batches, and see opens, clicks and
 * unsubscribes per recipient on any mail provider. See ANNOUNCEMENTS.md.
 *
 * @package Anchor\Announcements
 */

namespace Anchor\Announcements;

use Anchor\Announcements\Database\Migrations;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

class Module {

	public const VERSION = '1.0.0';
	public const CAP     = 'anchor_send_announcements';

	public Audience\Registry $conditions;

	private static ?Module $instance = null;

	public static function instance(): ?Module {
		return self::$instance;
	}

	public function resolver(): Audience\Resolver {
		return new Audience\Resolver( $this->conditions );
	}

	public function __construct() {
		self::$instance = $this;
		// No per-module activation hook: converge on load and again on admin_init.
		Migrations::maybe_migrate();
		\add_action( 'admin_init', [ Migrations::class, 'maybe_migrate' ] );
		$this->conditions = new Audience\Registry();
		\add_action( 'init', [ Content\AnnouncementPostType::class, 'register' ] );
		if ( \is_admin() ) {
			new Admin\SettingsPage();
		}
	}
}
