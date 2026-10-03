<?php
declare(strict_types=1);

namespace Anchor\Shipping\Admin;

use Anchor\Shipping\Carriers\CarrierRegistry;
use Anchor\Shipping\Support\Settings;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** WooCommerce > Anchor Shipping. Plain form posted to admin-post.php. */
final class SettingsPage {

	public const SLUG = 'anchor-shipping';
	private const MODES = [ 'off', 'customer', 'auto' ];

	public function __construct( private CarrierRegistry $carriers ) {
		\add_action( 'admin_menu', [ $this, 'menu' ], 60 );
		\add_action( 'admin_post_anchor_shipping_save', [ $this, 'handle_save' ] );
	}

	public function menu(): void {
		\add_submenu_page( 'woocommerce', \__( 'Anchor Shipping', 'anchor-schema' ), \__( 'Anchor Shipping', 'anchor-schema' ), 'manage_woocommerce', self::SLUG, [ $this, 'render' ] );
	}

	public function handle_save(): void {
		if ( ! \current_user_can( 'manage_woocommerce' ) ) {
			\wp_die( \esc_html__( 'You do not have permission to change shipping settings.', 'anchor-schema' ), 403 );
		}
		\check_admin_referer( 'anchor_shipping_settings' );
		$post = isset( $_POST['anchor_shipping'] ) ? \wp_unslash( (array) $_POST['anchor_shipping'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		Settings::save( $this->sanitize( $post, Settings::all() ) );
		\wp_safe_redirect( \add_query_arg( [ 'page' => self::SLUG, 'updated' => 1 ], \admin_url( 'admin.php' ) ) );
		exit;
	}

	public function sanitize( array $post, array $current ): array {
		$out = $current;
		$txt = static fn( $v ): string => \sanitize_text_field( (string) $v );
		$num = static fn( $v ): float => max( 0.0, (float) $v );

		$out['default_carrier'] = isset( $post['default_carrier'] ) && $this->carriers->get( (string) $post['default_carrier'] ) ? (string) $post['default_carrier'] : $current['default_carrier'];
		$out['default_service'] = isset( $post['default_service'] ) ? $txt( $post['default_service'] ) : $current['default_service'];
		$out['label_format']    = in_array( $post['label_format'] ?? '', [ 'GIF', 'ZPL' ], true ) ? $post['label_format'] : 'GIF';
		$out['inbox']           = implode( ', ', array_filter( array_map( 'sanitize_email', array_map( 'trim', explode( ',', (string) ( $post['inbox'] ?? $current['inbox'] ) ) ) ) ) );
		$out['auto_label']      = ! empty( $post['auto_label'] );

		foreach ( array_keys( $current['ship_from'] ) as $k ) {
			$out['ship_from'][ $k ] = $txt( $post['ship_from'][ $k ] ?? $current['ship_from'][ $k ] );
		}

		$boxes = [];
		foreach ( (array) ( $post['boxes'] ?? $current['boxes'] ) as $row ) {
			$name = $txt( $row['name'] ?? '' );
			if ( '' === $name || $num( $row['length'] ?? 0 ) <= 0 || $num( $row['width'] ?? 0 ) <= 0 || $num( $row['height'] ?? 0 ) <= 0 ) {
				continue;
			}
			$boxes[] = [
				'id'           => \sanitize_key( str_replace( ' ', '-', strtolower( $name ) ) ),
				'name'         => $name,
				'length'       => $num( $row['length'] ),
				'width'        => $num( $row['width'] ),
				'height'       => $num( $row['height'] ),
				'empty_weight' => $num( $row['empty_weight'] ?? 0 ),
				'max_weight'   => $num( $row['max_weight'] ?? 0 ),
			];
		}
		$out['boxes'] = $boxes;

		foreach ( $this->carriers->all() as $id => $carrier ) {
			foreach ( $carrier->settings_fields() as $key => $field ) {
				$submitted = $post['carriers'][ $id ][ $key ] ?? null;
				if ( null === $submitted || ( 'secret' === $field['type'] && '' === $submitted ) ) {
					continue; // blank secret = keep what is stored
				}
				if ( 'select' === $field['type'] && ! isset( $field['options'][ $submitted ] ) ) {
					continue;
				}
				$out['carriers'][ $id ][ $key ] = $txt( $submitted );
			}
		}

		$out['insurance']['mode']        = in_array( $post['insurance']['mode'] ?? '', self::MODES, true ) ? $post['insurance']['mode'] : 'off';
		$out['insurance']['threshold']   = $num( $post['insurance']['threshold'] ?? $current['insurance']['threshold'] );
		$out['insurance']['fee_per_100'] = '' === ( $post['insurance']['fee_per_100'] ?? '' ) ? '' : (string) $num( $post['insurance']['fee_per_100'] );
		$out['signature']['mode']        = in_array( $post['signature']['mode'] ?? '', self::MODES, true ) ? $post['signature']['mode'] : 'off';
		$out['signature']['threshold']   = $num( $post['signature']['threshold'] ?? $current['signature']['threshold'] );
		$out['signature']['fee']         = '' === ( $post['signature']['fee'] ?? '' ) ? '' : (string) $num( $post['signature']['fee'] );

		return $out;
	}

	public function render(): void {
		if ( ! \current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$s     = Settings::all();
		$name  = static fn( string $path ): string => 'anchor_shipping' . $path;
		$wu    = \get_option( 'woocommerce_weight_unit' );
		$du    = \get_option( 'woocommerce_dimension_unit' );
		$modes = [ 'off' => \__( 'Off', 'anchor-schema' ), 'customer' => \__( 'Customer chooses at checkout (adds a fee)', 'anchor-schema' ), 'auto' => \__( 'Automatic above a threshold', 'anchor-schema' ) ];
		?>
		<div class="wrap anchor-shipping-settings">
			<h1><?php \esc_html_e( 'Anchor Shipping', 'anchor-schema' ); ?></h1>
			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success"><p><?php \esc_html_e( 'Settings saved.', 'anchor-schema' ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo \esc_url( \admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="anchor_shipping_save">
				<?php \wp_nonce_field( 'anchor_shipping_settings' ); ?>

				<h2><?php \esc_html_e( 'Labels', 'anchor-schema' ); ?></h2>
				<table class="form-table">
					<tr><th><?php \esc_html_e( 'Create labels automatically when an order is paid', 'anchor-schema' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo \esc_attr( $name( '[auto_label]' ) ); ?>" value="1" <?php \checked( $s['auto_label'] ); ?>> <?php \esc_html_e( 'When every item has a weight and a default box. Otherwise, and when this is off, the shipping inbox gets a "Ready to ship" email with a Create label button.', 'anchor-schema' ); ?></label></td></tr>
					<tr><th><?php \esc_html_e( 'Shipping inbox', 'anchor-schema' ); ?></th>
						<td><input type="text" class="regular-text" name="<?php echo \esc_attr( $name( '[inbox]' ) ); ?>" value="<?php echo \esc_attr( $s['inbox'] ); ?>" placeholder="<?php echo \esc_attr( (string) \get_option( 'admin_email' ) ); ?>"><p class="description"><?php \esc_html_e( 'Comma-separate several addresses.', 'anchor-schema' ); ?></p></td></tr>
					<tr><th><?php \esc_html_e( 'Default carrier / service', 'anchor-schema' ); ?></th>
						<td><select name="<?php echo \esc_attr( $name( '[default_carrier]' ) ); ?>"><?php foreach ( $this->carriers->all() as $id => $c ) : ?><option value="<?php echo \esc_attr( $id ); ?>" <?php \selected( $s['default_carrier'], $id ); ?>><?php echo \esc_html( $c->label() ); ?></option><?php endforeach; ?></select>
						<select name="<?php echo \esc_attr( $name( '[default_service]' ) ); ?>"><?php foreach ( $this->carriers->all() as $c ) : foreach ( $c->services() as $code => $label ) : ?><option value="<?php echo \esc_attr( (string) $code ); ?>" <?php \selected( $s['default_service'], (string) $code ); ?>><?php echo \esc_html( $label ); ?></option><?php endforeach; endforeach; ?></select></td></tr>
					<tr><th><?php \esc_html_e( 'Label format', 'anchor-schema' ); ?></th>
						<td><select name="<?php echo \esc_attr( $name( '[label_format]' ) ); ?>"><option value="GIF" <?php \selected( $s['label_format'], 'GIF' ); ?>><?php \esc_html_e( '4×6 PDF (any printer)', 'anchor-schema' ); ?></option><option value="ZPL" <?php \selected( $s['label_format'], 'ZPL' ); ?>><?php \esc_html_e( 'ZPL (Zebra thermal printer)', 'anchor-schema' ); ?></option></select></td></tr>
				</table>

				<h2><?php \esc_html_e( 'Ship from', 'anchor-schema' ); ?></h2>
				<p class="description"><?php \esc_html_e( 'Blank fields use the WooCommerce store address.', 'anchor-schema' ); ?></p>
				<table class="form-table">
					<?php foreach ( [ 'name' => 'Contact name', 'company' => 'Company', 'phone' => 'Phone (required by UPS)', 'line1' => 'Address line 1', 'line2' => 'Address line 2', 'city' => 'City', 'state' => 'State', 'postcode' => 'ZIP', 'country' => 'Country code' ] as $k => $label ) : ?>
						<tr><th><?php echo \esc_html( $label ); ?></th><td><input type="text" class="regular-text" name="<?php echo \esc_attr( $name( "[ship_from][{$k}]" ) ); ?>" value="<?php echo \esc_attr( (string) $s['ship_from'][ $k ] ); ?>"></td></tr>
					<?php endforeach; ?>
				</table>

				<h2><?php \esc_html_e( 'Boxes', 'anchor-schema' ); ?></h2>
				<p class="description"><?php echo \esc_html( sprintf( /* translators: 1: dimension unit, 2: weight unit */ \__( 'Dimensions in %1$s, weights in %2$s. Assign a default box to each product on its Shipping tab.', 'anchor-schema' ), $du, $wu ) ); ?></p>
				<table class="widefat striped anchor-shipping-boxes">
					<thead><tr><th>Name</th><th>Length</th><th>Width</th><th>Height</th><th>Empty weight</th><th>Max weight</th></tr></thead>
					<tbody>
					<?php foreach ( array_merge( $s['boxes'], [ [], [] ] ) as $i => $b ) : ?>
						<tr><?php foreach ( [ 'name', 'length', 'width', 'height', 'empty_weight', 'max_weight' ] as $k ) : ?>
							<td><input type="text" name="<?php echo \esc_attr( $name( "[boxes][{$i}][{$k}]" ) ); ?>" value="<?php echo \esc_attr( (string) ( $b[ $k ] ?? '' ) ); ?>"></td>
						<?php endforeach; ?></tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<h2><?php \esc_html_e( 'Protection', 'anchor-schema' ); ?></h2>
				<table class="form-table">
					<tr><th><?php \esc_html_e( 'Insurance (declared value)', 'anchor-schema' ); ?></th><td>
						<select name="<?php echo \esc_attr( $name( '[insurance][mode]' ) ); ?>"><?php foreach ( $modes as $k => $l ) : ?><option value="<?php echo \esc_attr( $k ); ?>" <?php \selected( $s['insurance']['mode'], $k ); ?>><?php echo \esc_html( $l ); ?></option><?php endforeach; ?></select>
						<p><label><?php \esc_html_e( 'Automatic above order value', 'anchor-schema' ); ?> <input type="text" size="6" name="<?php echo \esc_attr( $name( '[insurance][threshold]' ) ); ?>" value="<?php echo \esc_attr( (string) $s['insurance']['threshold'] ); ?>"></label></p>
						<p><label><?php \esc_html_e( 'Customer fee per $100 over $100', 'anchor-schema' ); ?> <input type="text" size="6" name="<?php echo \esc_attr( $name( '[insurance][fee_per_100]' ) ); ?>" value="<?php echo \esc_attr( (string) $s['insurance']['fee_per_100'] ); ?>"></label></p>
					</td></tr>
					<tr><th><?php \esc_html_e( 'Signature required', 'anchor-schema' ); ?></th><td>
						<select name="<?php echo \esc_attr( $name( '[signature][mode]' ) ); ?>"><?php foreach ( $modes as $k => $l ) : ?><option value="<?php echo \esc_attr( $k ); ?>" <?php \selected( $s['signature']['mode'], $k ); ?>><?php echo \esc_html( $l ); ?></option><?php endforeach; ?></select>
						<p><label><?php \esc_html_e( 'Automatic above order value', 'anchor-schema' ); ?> <input type="text" size="6" name="<?php echo \esc_attr( $name( '[signature][threshold]' ) ); ?>" value="<?php echo \esc_attr( (string) $s['signature']['threshold'] ); ?>"></label></p>
						<p><label><?php \esc_html_e( 'Customer fee', 'anchor-schema' ); ?> <input type="text" size="6" name="<?php echo \esc_attr( $name( '[signature][fee]' ) ); ?>" value="<?php echo \esc_attr( (string) $s['signature']['fee'] ); ?>"></label></p>
					</td></tr>
				</table>

				<?php foreach ( $this->carriers->all() as $id => $carrier ) : ?>
					<h2><?php echo \esc_html( $carrier->label() ); ?></h2>
					<table class="form-table">
						<?php foreach ( $carrier->settings_fields() as $key => $field ) :
							$locked = Settings::is_constant( $id, $key );
							$value  = Settings::carrier( $id )[ $key ] ?? '';
							$fname  = $name( "[carriers][{$id}][{$key}]" );
							?>
							<tr><th><?php echo \esc_html( $field['label'] ); ?></th><td>
								<?php if ( $locked ) : ?>
									<em><?php echo \esc_html( sprintf( /* translators: %s: constant name */ \__( 'Set in wp-config.php (%s)', 'anchor-schema' ), Settings::constant_name( $id, $key ) ) ); ?></em>
								<?php elseif ( 'secret' === $field['type'] ) : ?>
									<input type="password" class="regular-text" autocomplete="off" name="<?php echo \esc_attr( $fname ); ?>" value="" placeholder="<?php echo '' !== $value ? \esc_attr__( '•••••• saved — leave blank to keep', 'anchor-schema' ) : ''; ?>">
								<?php elseif ( 'select' === $field['type'] ) : ?>
									<select name="<?php echo \esc_attr( $fname ); ?>"><?php foreach ( $field['options'] as $ok => $ol ) : ?><option value="<?php echo \esc_attr( (string) $ok ); ?>" <?php \selected( $value, (string) $ok ); ?>><?php echo \esc_html( $ol ); ?></option><?php endforeach; ?></select>
								<?php else : ?>
									<input type="text" class="regular-text" name="<?php echo \esc_attr( $fname ); ?>" value="<?php echo \esc_attr( $value ); ?>">
								<?php endif; ?>
							</td></tr>
						<?php endforeach; ?>
					</table>
				<?php endforeach; ?>

				<?php \submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
