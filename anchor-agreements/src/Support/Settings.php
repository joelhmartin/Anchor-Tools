<?php
declare(strict_types=1);

namespace Anchor\Agreements\Support;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class Settings {

	public const OPTION = 'anchor_agreements_settings';

	/** Every value an admin can change. Edited on Agreements → Settings (Admin\SettingsPage). */
	public const DEFAULTS = [
		'default_agreement_id' => 0,
		'notify_enabled'       => true,
		'notify'               => '',   // comma-separated; empty = site admin email
		'notify_subject'       => 'Signed: {documents} – {name}, order #{order}',
		'customer_email_links' => true, // "Your signed agreements" block in customer order emails
		'label'                => '',   // empty = translated "I have read and signed the {title}"
		'consent_text'         => '',   // empty = translated default consent sentence
		'reuse_hours'          => 24,   // a signature still counts for a retried payment within this window
		'purge_days'           => 30,   // unattached (abandoned-cart) signatures are deleted after this
	];

	public static function get(): array {
		$raw = \get_option( self::OPTION, [] );
		return array_merge( self::DEFAULTS, \is_array( $raw ) ? $raw : [] );
	}

	public static function sanitize( $in ): array {
		$in = \is_array( $in ) ? $in : [];
		return [
			'default_agreement_id' => \absint( $in['default_agreement_id'] ?? 0 ),
			'notify_enabled'       => ! empty( $in['notify_enabled'] ),
			'notify'               => implode( ', ', array_values( array_filter( array_map( static fn( $e ) => \is_email( trim( $e ) ) ? trim( $e ) : '', explode( ',', (string) ( $in['notify'] ?? '' ) ) ) ) ) ),
			'notify_subject'       => \sanitize_text_field( (string) ( $in['notify_subject'] ?? '' ) ) ?: self::DEFAULTS['notify_subject'],
			'customer_email_links' => ! empty( $in['customer_email_links'] ),
			'label'                => \sanitize_text_field( (string) ( $in['label'] ?? '' ) ),
			'consent_text'         => \sanitize_text_field( (string) ( $in['consent_text'] ?? '' ) ),
			'reuse_hours'          => max( 1, min( 168, (int) ( $in['reuse_hours'] ?? 24 ) ) ),
			'purge_days'           => max( 1, min( 365, (int) ( $in['purge_days'] ?? 30 ) ) ),
		];
	}

	public static function default_agreement_id(): int {
		return (int) self::get()['default_agreement_id'];
	}

	public static function notify_enabled(): bool {
		return (bool) self::get()['notify_enabled'];
	}

	/** @return string[] */
	public static function notify_recipients(): array {
		$list = array_values( array_filter( array_map( 'trim', explode( ',', (string) self::get()['notify'] ) ), 'is_email' ) );
		return $list ?: [ (string) \get_option( 'admin_email' ) ];
	}

	public static function notify_subject( string $documents, string $name, int $order_id ): string {
		return strtr( (string) self::get()['notify_subject'], [ '{documents}' => $documents, '{name}' => $name, '{order}' => (string) $order_id ] );
	}

	public static function customer_email_links(): bool {
		return (bool) self::get()['customer_email_links'];
	}

	public static function label_for( string $title ): string {
		$tpl = (string) self::get()['label'];
		$tpl = '' !== $tpl ? $tpl : \__( 'I have read and signed the {title}', 'anchor-schema' );
		return str_replace( '{title}', $title, $tpl );
	}

	public static function consent_text(): string {
		$t = (string) self::get()['consent_text'];
		return '' !== $t ? $t : \__( 'I agree that this electronic signature is the legal equivalent of my handwritten signature.', 'anchor-schema' );
	}

	public static function reuse_seconds(): int {
		return (int) self::get()['reuse_hours'] * HOUR_IN_SECONDS;
	}

	public static function purge_days(): int {
		return (int) self::get()['purge_days'];
	}
}
