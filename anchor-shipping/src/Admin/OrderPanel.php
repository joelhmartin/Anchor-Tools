<?php
declare(strict_types=1);

namespace Anchor\Shipping\Admin;

use Anchor\Shipping\Carriers\CarrierRegistry;
use Anchor\Shipping\Database\ShipmentRepository;
use Anchor\Shipping\Packing\Packer;
use Anchor\Shipping\Services\Protection;
use Anchor\Shipping\Support\Settings;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** The Shipping box on the order screen (HPOS and legacy). */
final class OrderPanel {

	public function __construct(
		private ShipmentRepository $shipments,
		private Packer $packer,
		private CarrierRegistry $carriers
	) {
		\add_action( 'add_meta_boxes', [ $this, 'register' ] );
		\add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
	}

	public function register(): void {
		\add_meta_box( 'anchor-shipping', \__( 'Shipping label', 'anchor-schema' ), [ $this, 'metabox' ], \wc_get_page_screen_id( 'shop-order' ), 'side', 'high' );
	}

	public function assets( string $hook ): void {
		$screen = \get_current_screen();
		if ( ! $screen || \wc_get_page_screen_id( 'shop-order' ) !== $screen->id ) {
			return;
		}
		\wp_enqueue_style( 'anchor-shipping-admin', ANCHOR_TOOLS_PLUGIN_URL . 'anchor-shipping/assets/admin.css', [], '1.0.0' );
		\wp_enqueue_script( 'anchor-shipping-admin', ANCHOR_TOOLS_PLUGIN_URL . 'anchor-shipping/assets/admin.js', [ 'jquery' ], '1.0.1', true );
		\wp_localize_script( 'anchor-shipping-admin', 'anchorShipping', [ 'ajax' => \admin_url( 'admin-ajax.php' ), 'nonce' => \wp_create_nonce( Ajax::NONCE ), 'confirmVoid' => \__( 'Void this label? It cannot be used afterwards.', 'anchor-schema' ) ] );
	}

	/** @param \WP_Post|\WC_Order $post_or_order */
	public function metabox( $post_or_order ): void {
		$order = $post_or_order instanceof \WC_Order ? $post_or_order : \wc_get_order( $post_or_order->ID );
		if ( $order ) {
			echo $this->render_panel( $order ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in render_panel()
		}
	}

	public function render_panel( \WC_Order $order ): string {
		$rows       = $this->shipments->for_order( $order->get_id() );
		$active     = array_filter( $rows, static fn( $r ) => 'voided' !== $r['status'] );
		$s          = Settings::all();
		$pack       = $this->packer->pack_order( $order );
		$protection = Protection::for_order( $order, $this->packer->shippable_subtotal( $order ) );
		$boxes      = Settings::boxes();
		$unrecorded = (string) $order->get_meta( '_anchor_shipping_unrecorded' );

		ob_start();
		?>
		<div class="anchor-shipping-panel" data-order="<?php echo (int) $order->get_id(); ?>">
			<?php foreach ( $rows as $r ) :
				$carrier = $this->carriers->get( $r['carrier'] );
				$voided  = 'voided' === $r['status'];
				?>
				<div class="anchor-shipping-row<?php echo $voided ? ' is-voided' : ''; ?>">
					<strong><?php echo \esc_html( $r['tracking_number'] ); ?></strong>
					<span class="anchor-shipping-meta"><?php echo \esc_html( trim( ( $carrier ? $carrier->label() : $r['carrier'] ) . ' · ' . $r['status'] . ( (float) $r['declared_value'] > 0 ? ' · insured' : '' ) . ( '1' === (string) $r['signature'] ? ' · signature' : '' ) ) ); ?></span>
					<?php if ( ! $voided ) : ?>
						<?php if ( '' === (string) $r['label_path'] ) : ?>
							<em class="anchor-shipping-meta"><?php \esc_html_e( 'Label file missing', 'anchor-schema' ); ?></em>
						<?php else : ?>
							<a class="button button-small" target="_blank" href="<?php echo \esc_url( Ajax::download_url( (int) $r['id'] ) ); ?>"><?php \esc_html_e( 'Print', 'anchor-schema' ); ?></a>
						<?php endif; ?>
						<?php if ( $carrier ) : ?><a class="button button-small" target="_blank" rel="noopener" href="<?php echo \esc_url( $carrier->tracking_url( $r['tracking_number'] ) ); ?>"><?php \esc_html_e( 'Track', 'anchor-schema' ); ?></a><?php endif; ?>
						<button type="button" class="button button-small anchor-shipping-void" data-id="<?php echo (int) $r['id']; ?>"><?php \esc_html_e( 'Void', 'anchor-schema' ); ?></button>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>

			<?php if ( ! $pack->ok() && '' !== $pack->reason && ! $active ) : ?>
				<p class="anchor-shipping-note"><?php echo \esc_html( $pack->reason ); ?></p>
			<?php endif; ?>

			<?php if ( '' !== $unrecorded ) : ?>
				<p class="anchor-shipping-note"><?php echo \esc_html( sprintf( /* translators: %s: UPS shipment identifier */ \__( 'UPS shipment %s was created but not recorded here. Void it at ups.com before creating another label.', 'anchor-schema' ), $unrecorded ) ); ?></p>
			<?php endif; ?>

			<div class="anchor-shipping-create">
				<?php if ( '' !== $unrecorded ) : ?>
					<p><label><input type="checkbox" name="unrecorded_voided" value="<?php echo \esc_attr( $unrecorded ); ?>" required> <?php echo \esc_html( sprintf( /* translators: %s: UPS shipment identifier */ \__( 'UPS shipment %s has been voided at ups.com', 'anchor-schema' ), $unrecorded ) ); ?></label></p>
				<?php endif; ?>
				<?php if ( $active ) : ?>
					<label><input type="checkbox" name="additional" value="1"> <?php \esc_html_e( 'Additional package', 'anchor-schema' ); ?></label>
				<?php endif; ?>
				<p><select name="carrier"><?php foreach ( $this->carriers->all() as $id => $c ) : if ( ! $c->supports( 'labels' ) ) { continue; } ?><option value="<?php echo \esc_attr( $id ); ?>" <?php \selected( $s['default_carrier'], $id ); ?>><?php echo \esc_html( $c->label() ); ?></option><?php endforeach; ?></select>
				<select name="service"><?php foreach ( ( $this->carriers->get( $s['default_carrier'] ) ?? $this->carriers->get( 'ups' ) )->services() as $code => $label ) : ?><option value="<?php echo \esc_attr( (string) $code ); ?>" <?php \selected( $s['default_service'], (string) $code ); ?>><?php echo \esc_html( $label ); ?></option><?php endforeach; ?></select></p>
				<p><select name="box">
					<?php foreach ( $boxes as $id => $b ) : ?><option value="<?php echo \esc_attr( $id ); ?>" <?php \selected( $pack->suggestion['box'] ?? '', $id ); ?>><?php echo \esc_html( $b['name'] ); ?></option><?php endforeach; ?>
					<option value="custom" <?php \selected( ! $boxes ); ?>><?php \esc_html_e( 'Custom size…', 'anchor-schema' ); ?></option>
				</select></p>
				<p class="anchor-shipping-custom">
					<?php foreach ( [ 'length', 'width', 'height' ] as $d ) : ?><input type="text" size="3" name="<?php echo \esc_attr( $d ); ?>" placeholder="<?php echo \esc_attr( ucfirst( $d[0] ) ); ?>"><?php endforeach; ?>
					<?php echo \esc_html( (string) \get_option( 'woocommerce_dimension_unit' ) ); ?>
				</p>
				<p><label><?php \esc_html_e( 'Package weight', 'anchor-schema' ); ?> <input type="text" size="5" name="weight" value="<?php echo \esc_attr( isset( $pack->suggestion['weight'] ) ? (string) $pack->suggestion['weight'] : '' ); ?>"> <?php echo \esc_html( (string) \get_option( 'woocommerce_weight_unit' ) ); ?></label></p>
				<p><label><input type="checkbox" name="insure" value="1" <?php \checked( $protection['declared_value'] > 0 ); ?>> <?php \esc_html_e( 'Insure for order value', 'anchor-schema' ); ?></label><br>
				<label><input type="checkbox" name="signature" value="1" <?php \checked( $protection['signature'] ); ?>> <?php \esc_html_e( 'Signature required', 'anchor-schema' ); ?></label></p>
				<p><button type="button" class="button button-primary anchor-shipping-submit"><?php \esc_html_e( 'Create label', 'anchor-schema' ); ?></button></p>
				<p class="anchor-shipping-error" role="alert"></p>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
