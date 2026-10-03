<?php
declare(strict_types=1);

namespace Anchor\Agreements\Admin;

use Anchor\Agreements\Content\AgreementPostType;
use Anchor\Agreements\Support\Settings;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class SettingsPage {

	public function __construct() {
		\add_action( 'admin_menu', [ $this, 'menu' ] );
		\add_action( 'admin_init', [ $this, 'register' ] );
	}

	public function menu(): void {
		\add_submenu_page( 'edit.php?post_type=' . AgreementPostType::CPT, \__( 'Agreement settings', 'anchor-schema' ), \__( 'Settings', 'anchor-schema' ), 'manage_woocommerce', 'anchor-agreements-settings', [ $this, 'render' ] );
	}

	public function register(): void {
		\register_setting( 'anchor_agreements', Settings::OPTION, [
			'type'              => 'array',
			'sanitize_callback' => [ Settings::class, 'sanitize' ],
		] );
	}

	private function row( string $label, string $field, string $help = '' ): void {
		echo '<tr><th scope="row">' . \esc_html( $label ) . '</th><td>' . $field; // $field is built escaped below.
		if ( '' !== $help ) {
			echo '<p class="description">' . \esc_html( $help ) . '</p>';
		}
		echo '</td></tr>';
	}

	private function text( string $key, string $value, string $placeholder = '', string $class = 'regular-text' ): string {
		return sprintf( '<input type="text" class="%s" name="%s[%s]" value="%s" placeholder="%s" />', \esc_attr( $class ), \esc_attr( Settings::OPTION ), \esc_attr( $key ), \esc_attr( $value ), \esc_attr( $placeholder ) );
	}

	private function number( string $key, int $value, int $min, int $max ): string {
		return sprintf( '<input type="number" class="small-text" min="%d" max="%d" name="%s[%s]" value="%d" />', $min, $max, \esc_attr( Settings::OPTION ), \esc_attr( $key ), $value );
	}

	private function toggle( string $key, bool $on, string $label ): string {
		return sprintf( '<label><input type="checkbox" name="%s[%s]" value="1" %s /> %s</label>', \esc_attr( Settings::OPTION ), \esc_attr( $key ), \checked( $on, true, false ), \esc_html( $label ) );
	}

	public function render(): void {
		$s   = Settings::get();
		$opt = '<option value="0">' . \esc_html__( '— None —', 'anchor-schema' ) . '</option>';
		foreach ( \get_posts( [ 'post_type' => AgreementPostType::CPT, 'post_status' => 'publish', 'numberposts' => -1 ] ) as $p ) {
			$opt .= '<option value="' . (int) $p->ID . '"' . \selected( (int) $s['default_agreement_id'], $p->ID, false ) . '>' . \esc_html( $p->post_title ) . '</option>';
		}
		echo '<div class="wrap"><h1>' . \esc_html__( 'Agreement settings', 'anchor-schema' ) . '</h1><form method="post" action="options.php">';
		\settings_fields( 'anchor_agreements' );

		echo '<h2>' . \esc_html__( 'Documents', 'anchor-schema' ) . '</h2><table class="form-table">';
		$this->row( \__( 'Default agreement', 'anchor-schema' ), '<select name="' . \esc_attr( Settings::OPTION ) . '[default_agreement_id]">' . $opt . '</select>', \__( 'Used by any product that requires a signature but does not pick its own document.', 'anchor-schema' ) );
		echo '</table>';

		echo '<h2>' . \esc_html__( 'Staff notifications', 'anchor-schema' ) . '</h2><table class="form-table">';
		$this->row( \__( 'Send "Signed" emails', 'anchor-schema' ), $this->toggle( 'notify_enabled', (bool) $s['notify_enabled'], \__( 'Email staff when a paid order has signed agreements', 'anchor-schema' ) ) );
		$this->row( \__( 'Recipients', 'anchor-schema' ), $this->text( 'notify', (string) $s['notify'], (string) \get_option( 'admin_email' ), 'large-text' ), \__( 'Comma-separated email addresses. Leave empty to use the site admin email.', 'anchor-schema' ) );
		$this->row( \__( 'Subject', 'anchor-schema' ), $this->text( 'notify_subject', (string) $s['notify_subject'], Settings::DEFAULTS['notify_subject'], 'large-text' ), \__( 'Placeholders: {documents}, {name}, {order}', 'anchor-schema' ) );
		echo '</table>';

		echo '<h2>' . \esc_html__( 'Customer', 'anchor-schema' ) . '</h2><table class="form-table">';
		$this->row( \__( 'Links in order emails', 'anchor-schema' ), $this->toggle( 'customer_email_links', (bool) $s['customer_email_links'], \__( 'Add "Your signed agreements" links to customer order emails', 'anchor-schema' ) ) );
		$this->row( \__( 'Checkout checkbox label', 'anchor-schema' ), $this->text( 'label', (string) $s['label'], 'I have read and signed the {title}', 'large-text' ), \__( 'Placeholder: {title}. Used when one agreement is required.', 'anchor-schema' ) );
		$this->row( \__( 'Consent sentence', 'anchor-schema' ), $this->text( 'consent_text', (string) $s['consent_text'], Settings::consent_text(), 'large-text' ), \__( 'Shown next to the box the signer must tick. Have your attorney approve any change.', 'anchor-schema' ) );
		echo '</table>';

		echo '<h2>' . \esc_html__( 'Timing', 'anchor-schema' ) . '</h2><table class="form-table">';
		$this->row( \__( 'Reuse a signature for (hours)', 'anchor-schema' ), $this->number( 'reuse_hours', (int) $s['reuse_hours'], 1, 168 ), \__( 'If a payment fails, the buyer does not have to sign again within this window.', 'anchor-schema' ) );
		$this->row( \__( 'Delete abandoned signatures after (days)', 'anchor-schema' ), $this->number( 'purge_days', (int) $s['purge_days'], 1, 365 ), \__( 'Signatures never attached to an order. Signatures on orders are kept forever.', 'anchor-schema' ) );
		echo '</table>';
		\submit_button();
		echo '</form></div>';
	}
}
