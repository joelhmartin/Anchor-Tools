<?php
declare(strict_types=1);

namespace Anchor\Announcements\Support;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** The module's one option: sender identity, required footer address, brand, batch size. */
final class Settings {

	public const OPTION = 'anchor_announcements_settings';

	public static function defaults(): array {
		return [
			'from_name'      => (string) \get_bloginfo( 'name' ),
			'from_email'     => '',
			'reply_to'       => '',
			'footer_address' => '',
			'brand_color'    => '#1a4f48',
			'logo_url'       => '',
			'batch_size'     => 50,
		];
	}

	public static function get(): array {
		$stored = \get_option( self::OPTION, [] );
		return \array_merge( self::defaults(), \is_array( $stored ) ? $stored : [] );
	}

	public static function save( array $raw ): array {
		$color = \sanitize_hex_color( (string) ( $raw['brand_color'] ?? '' ) );
		$clean = [
			'from_name'      => \sanitize_text_field( (string) ( $raw['from_name'] ?? '' ) ),
			'from_email'     => (string) \sanitize_email( (string) ( $raw['from_email'] ?? '' ) ),
			'reply_to'       => (string) \sanitize_email( (string) ( $raw['reply_to'] ?? '' ) ),
			'footer_address' => \sanitize_textarea_field( (string) ( $raw['footer_address'] ?? '' ) ),
			'brand_color'    => $color ? $color : '#1a4f48',
			'logo_url'       => \esc_url_raw( (string) ( $raw['logo_url'] ?? '' ) ),
			'batch_size'     => \max( 1, \min( 500, (int) ( $raw['batch_size'] ?? 50 ) ) ),
		];
		if ( '' === $clean['from_name'] ) {
			$clean['from_name'] = (string) \get_bloginfo( 'name' );
		}
		\update_option( self::OPTION, $clean, false );
		return $clean;
	}
}
