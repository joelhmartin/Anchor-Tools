<?php
declare(strict_types=1);

namespace Anchor\Shipping\Admin;

use Anchor\Shipping\Carriers\AddressValidatingCarrier;
use Anchor\Shipping\Carriers\CarrierRegistry;
use Anchor\Shipping\Domain\Address;
use Anchor\Shipping\Database\ShipmentRepository;
use Anchor\Shipping\Packing\Packer;
use Anchor\Shipping\Services\Protection;
use Anchor\Shipping\Support\Settings;
use Anchor\Shipping\Support\TrackingLinks;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** The Shipping box on the order screen (HPOS and legacy). */
final class OrderPanel {

	/** Seconds before retrying address validation for an address whose last check failed. */
	private const XAV_RETRY_AFTER = 300;

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
		self::enqueue();
	}

	/** Styles + script + the `anchorShipping` object the panel's JS needs; callable from wp-admin and the front-end console. */
	public static function enqueue(): void {
		\wp_enqueue_style( 'anchor-shipping-admin', ANCHOR_TOOLS_PLUGIN_URL . 'anchor-shipping/assets/admin.css', [], '1.0.2' );
		\wp_enqueue_script( 'anchor-shipping-admin', ANCHOR_TOOLS_PLUGIN_URL . 'anchor-shipping/assets/admin.js', [ 'jquery' ], '1.0.2', true );
		\wp_localize_script( 'anchor-shipping-admin', 'anchorShipping', [ 'ajax' => \admin_url( 'admin-ajax.php' ), 'nonce' => \wp_create_nonce( Ajax::NONCE ), 'confirmVoid' => \__( 'Click again to void', 'anchor-schema' ) ] );
	}

	/** Short note in place of the label form; the link reloads the page with the full panel. */
	private function render_predates_note( \WC_Order $order ): string {
		$date = \wp_date( (string) \get_option( 'date_format' ), Settings::labels_since() );
		$url  = \add_query_arg( self::OPEN_ARG, '1' );
		return '<div class="anchor-shipping-panel anchor-shipping-predates" data-order="' . (int) $order->get_id() . '">'
			. '<p class="anchor-shipping-note">' . \esc_html( sprintf( /* translators: %s: date label printing started */ \__( 'Placed before label printing started on %s, so it was most likely shipped another way.', 'anchor-schema' ), $date ) ) . '</p>'
			. '<p><a class="anchor-shipping-open" href="' . \esc_url( $url ) . '">' . \esc_html__( 'Create a label anyway', 'anchor-schema' ) . '</a></p>'
			. '</div>';
	}

	/** @param \WP_Post|\WC_Order $post_or_order */
	public function metabox( $post_or_order ): void {
		$order = $post_or_order instanceof \WC_Order ? $post_or_order : \wc_get_order( $post_or_order->ID );
		if ( $order ) {
			echo $this->render_panel( $order ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in render_panel()
		}
	}

	/** Query arg that opens the full panel on an order placed before label printing started. */
	public const OPEN_ARG = 'show_label_form';

	public function render_panel( \WC_Order $order ): string {
		$rows       = $this->shipments->for_order( $order->get_id() );
		if ( ! $rows && '' === (string) $order->get_meta( '_anchor_shipping_unrecorded' ) && Settings::order_predates_labels( $order ) && empty( $_GET[ self::OPEN_ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display toggle only
			return $this->render_predates_note( $order );
		}
		$active     = array_filter( $rows, static fn( $r ) => 'voided' !== $r['status'] );
		$s          = Settings::all();
		$pack       = $this->packer->pack_order( $order );
		$protection = Protection::for_order( $order, $this->packer->shippable_subtotal( $order ) );
		$boxes      = Settings::boxes();
		$unrecorded = (string) $order->get_meta( '_anchor_shipping_unrecorded' );
		$xav        = $this->address_check( $order );

		ob_start();
		?>
		<div class="anchor-shipping-panel" data-order="<?php echo (int) $order->get_id(); ?>">
			<?php foreach ( $rows as $r ) :
				$carrier = $this->carriers->get( $r['carrier'] );
				$voided  = 'voided' === $r['status'];
				?>
				<div class="anchor-shipping-row<?php echo $voided ? ' is-voided' : ''; ?>">
					<strong><?php echo \esc_html( $r['tracking_number'] ); ?></strong>
					<span class="anchor-shipping-meta"><?php echo \esc_html( trim( ( $carrier ? $carrier->label() : $r['carrier'] ) . ' · ' . ( TrackingLinks::status_label( (string) $r['status'] ) ?: $r['status'] ) . ( (float) $r['declared_value'] > 0 ? ' · insured' : '' ) . ( '1' === (string) $r['signature'] ? ' · signature' : '' ) ) ); ?></span>
					<?php if ( ! $voided ) : ?>
						<?php if ( '' === (string) $r['label_path'] ) : ?>
							<em class="anchor-shipping-meta"><?php \esc_html_e( 'Label file missing', 'anchor-schema' ); ?></em>
						<?php else : ?>
							<a class="button button-small anchor-shipping-print" target="_blank" href="<?php echo \esc_url( Ajax::download_url( (int) $r['id'] ) ); ?>"><?php \esc_html_e( 'Print', 'anchor-schema' ); ?></a>
						<?php endif; ?>
						<?php if ( $carrier ) : ?><a class="button button-small" target="_blank" rel="noopener" href="<?php echo \esc_url( $carrier->tracking_url( $r['tracking_number'] ) ); ?>"><?php \esc_html_e( 'Track', 'anchor-schema' ); ?></a><?php endif; ?>
						<button type="button" class="button button-small anchor-shipping-void" data-id="<?php echo (int) $r['id']; ?>"><?php \esc_html_e( 'Void', 'anchor-schema' ); ?></button>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>

			<?php if ( ! $pack->ok() && '' !== $pack->reason && ! $active ) : ?>
				<p class="anchor-shipping-note"><?php echo \esc_html( $pack->reason ); ?></p>
			<?php endif; ?>

			<?php if ( $xav && ! $xav['valid'] ) : ?>
				<p class="anchor-shipping-note anchor-shipping-address-warning">
					<?php echo \esc_html( $xav['ambiguous'] ? \__( 'UPS could not confirm this address exactly. Check it before shipping; suggestions:', 'anchor-schema' ) : \__( 'UPS does not recognise this address. Check it before shipping.', 'anchor-schema' ) ); ?>
					<?php foreach ( $xav['candidates'] as $c ) : ?><br><?php echo \esc_html( $c ); ?><?php endforeach; ?>
				</p>
			<?php endif; ?>

			<?php if ( '' !== $unrecorded ) : ?>
				<p class="anchor-shipping-note"><?php echo \esc_html( sprintf( /* translators: %s: UPS shipment identifier */ \__( 'UPS shipment %s was created but not recorded here. Void it at ups.com before creating another label.', 'anchor-schema' ), $unrecorded ) ); ?></p>
			<?php endif; ?>

			<?php
			// Once a label exists the form is only for an extra parcel, so it folds away behind a
			// toggle and always posts additional=1 instead of asking for a checkbox.
			if ( $active ) :
				?>
			<details class="anchor-shipping-more"><summary><?php \esc_html_e( 'Create another label (additional package)', 'anchor-schema' ); ?></summary>
			<?php endif; ?>
			<div class="anchor-shipping-create">
				<?php if ( '' !== $unrecorded ) : ?>
					<p><label><input type="checkbox" name="unrecorded_voided" value="<?php echo \esc_attr( $unrecorded ); ?>" required> <?php echo \esc_html( sprintf( /* translators: %s: UPS shipment identifier */ \__( 'UPS shipment %s has been voided at ups.com', 'anchor-schema' ), $unrecorded ) ); ?></label></p>
				<?php endif; ?>
				<?php if ( $active ) : ?><input type="hidden" name="additional" value="1"><?php endif; ?>
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
				<p class="anchor-shipping-protection-opts">
					<?php foreach ( [ 'insurance' => [ 'insure', \__( 'Insure for order value', 'anchor-schema' ), $protection['declared_value'] > 0 ], 'signature' => [ 'signature', \__( 'Signature required', 'anchor-schema' ), $protection['signature'] ] ] as $opt => [ $field, $label, $on ] ) :
						$reason = Protection::locked( $opt, $order ) ? Protection::lock_reason( $opt, $order ) : '';
						?>
						<label><input type="checkbox" name="<?php echo \esc_attr( $field ); ?>" value="1" <?php \checked( $on ); ?><?php \disabled( '' !== $reason ); ?>> <?php echo \esc_html( $label ); ?><?php if ( '' !== $reason ) : ?> <small class="anchor-shipping-meta">(<?php echo \esc_html( $reason ); ?>)</small><?php endif; ?></label><br>
					<?php endforeach; ?>
				</p>
				<p><button type="button" class="button button-primary anchor-shipping-submit"><?php \esc_html_e( 'Create label', 'anchor-schema' ); ?></button></p>
			</div>
			<?php if ( $active ) : ?></details><?php endif; ?>
			<?php // Outside the toggle: void errors land here too and must not hide in a closed <details>. ?>
			<p class="anchor-shipping-error" role="alert"></p>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Cached UPS address check for the order's ship-to, or null when it can't or shouldn't be checked.
	 * Advisory only: any carrier failure yields null so the panel and Create label are never blocked.
	 *
	 * @return array{valid:bool, ambiguous:bool, candidates:string[]}|null
	 */
	private function address_check( \WC_Order $order ): ?array {
		try {
			$to      = Address::from_order_shipping( $order );
			$id      = (string) ( Settings::all()['default_carrier'] ?? 'ups' );
			$carrier = $this->carriers->get( $id ) ?? $this->carriers->get( 'ups' );
			if ( ! \in_array( $to->country, [ 'US', 'PR' ], true ) || ! $to->is_complete() || ! $carrier instanceof AddressValidatingCarrier || ! $carrier->supports( 'address_validation' ) ) {
				return null;
			}
			$hash   = md5( \wp_json_encode( [ $carrier->id(), $to->line1, $to->line2, $to->city, $to->state, $to->postcode, $to->country ] ) );
			$cached = $order->get_meta( '_anchor_shipping_xav' );
			if ( is_array( $cached ) && ( $cached['hash'] ?? '' ) === $hash ) {
				if ( is_array( $cached['result'] ?? null ) ) {
					return $cached['result'];
				}
				if ( time() - (int) ( $cached['failed_at'] ?? 0 ) < self::XAV_RETRY_AFTER ) {
					return null; // recent failure: don't hammer a struggling carrier
				}
			}
			$r      = $carrier->validate_address( $to );
			$result = [
				'valid'      => (bool) $r['valid'],
				'ambiguous'  => (bool) $r['ambiguous'],
				'candidates' => array_map( static fn( Address $c ): string => trim( $c->line1 . ' ' . $c->line2 . ', ' . $c->city . ', ' . $c->state . ' ' . $c->postcode, ' ,' ), $r['candidates'] ),
			];
			$order->update_meta_data( '_anchor_shipping_xav', [ 'hash' => $hash, 'result' => $result ] );
			$order->save_meta_data();
			return $result;
		} catch ( \Throwable $e ) {
			if ( isset( $hash ) ) {
				try {
					$order->update_meta_data( '_anchor_shipping_xav', [ 'hash' => $hash, 'result' => null, 'failed_at' => time() ] );
					$order->save_meta_data();
				} catch ( \Throwable $ignored ) {} // phpcs:ignore
			}
			return null;
		}
	}
}
