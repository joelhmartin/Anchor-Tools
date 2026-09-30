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
		foreach ( [ new Audience\Conditions\UserRole(), new Audience\Conditions\UserRegistered(), new Audience\Conditions\UserField(), new Audience\Conditions\SpecificPeople() ] as $condition ) {
			$this->conditions->register( $condition );
		}
		foreach ( [ new Audience\Conditions\WcPurchased(), new Audience\Conditions\WcOrderCount(), new Audience\Conditions\WcTotalSpent() ] as $condition ) {
			$this->conditions->register( $condition );
		}
		\add_filter(
			'anchor_announcements_universe',
			static function ( Audience\RecipientSet $set ) {
				if ( Audience\WooOrders::available() ) {
					$all = \array_diff( \array_keys( \wc_get_order_statuses() ), [ 'wc-failed', 'wc-cancelled', 'wc-checkout-draft' ] );
					foreach ( Audience\WooOrders::orders( \array_values( $all ), null, null ) as $row ) {
						Audience\WooOrders::add_to( $set, $row );
					}
				}
				return $set;
			}
		);
		foreach ( [ new Audience\Conditions\CourseEnrolled(), new Audience\Conditions\CourseCompleted(), new Audience\Conditions\EventRegistered() ] as $condition ) {
			$this->conditions->register( $condition );
		}
		\add_filter(
			'anchor_announcements_universe',
			static function ( Audience\RecipientSet $set ) {
				return \class_exists( '\Anchor\Events\Module' ) ? $set->union( Audience\Conditions\EventRegistered::seats( [], true ) ) : $set;
			}
		);
		\add_action( 'init', [ Content\AnnouncementPostType::class, 'register' ] );
		\add_action( 'init', [ $this, 'register_tokens' ] );
		\add_action( 'anchor_announcements_suppress', [ Suppression\Suppressions::class, 'add' ], 10, 3 );
		new Tracking\Endpoints();
		Sending\Queue::register();
		if ( \is_admin() ) {
			new Admin\SettingsPage();
			new Admin\Editor();
			new Admin\Ajax();
			new Admin\Reports();
			new Admin\ListColumns();
			new Admin\SuppressionsPage();
		}
	}

	public function register_tokens(): void {
		$recipient = \__( 'Recipient', 'anchor-schema' );
		$site      = \__( 'Site', 'anchor-schema' );
		foreach ( [
			'first_name' => [ \__( 'First name', 'anchor-schema' ), $recipient ],
			'last_name' => [ \__( 'Last name', 'anchor-schema' ), $recipient ],
			'display_name' => [ \__( 'Display name', 'anchor-schema' ), $recipient ],
			'username' => [ \__( 'Username', 'anchor-schema' ), $recipient ],
			'email' => [ \__( 'Email', 'anchor-schema' ), $recipient ],
			'site_name' => [ \__( 'Site name', 'anchor-schema' ), $site ],
			'site_url' => [ \__( 'Site link', 'anchor-schema' ), $site ],
			'login_url' => [ \__( 'Login link', 'anchor-schema' ), $site ],
			'account_url' => [ \__( 'Account link', 'anchor-schema' ), $site ],
			'unsubscribe_url' => [ \__( 'Unsubscribe link', 'anchor-schema' ), $site ],
		] as $key => $t ) {
			\Anchor_Email_Tokens::register( $key, $t[0], $t[1] );
		}
	}
}
