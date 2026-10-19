<?php
/**
 * Customer tracking card(s) under the order details (My Account → view order, thank-you page).
 * Vars: $rows — TrackingLinks::for_order() output, one per live package.
 *
 * @package Anchor\Shipping
 */

if ( ! \defined( 'ABSPATH' ) ) { exit; }

$steps = [
	'label_created'    => \__( 'Label created', 'anchor-schema' ),
	'in_transit'       => \__( 'In transit', 'anchor-schema' ),
	'out_for_delivery' => \__( 'Out for delivery', 'anchor-schema' ),
	'delivered'        => \__( 'Delivered', 'anchor-schema' ),
];
$reached = [ 'label_created' => 0, 'in_transit' => 1, 'exception' => 1, 'returned' => 1, 'out_for_delivery' => 2, 'delivered' => 3 ];
$day     = static fn( int $ts ): string => \wp_date( 'l, F j', $ts );
$moment  = static fn( int $ts ): string => \wp_date( \get_option( 'date_format' ) . ', ' . \get_option( 'time_format' ), $ts );
$count   = count( $rows );
?>
<section class="anchor-shipping-tracking">
	<h2 class="woocommerce-order-details__title"><?php echo \esc_html( 1 === $count ? \__( 'Tracking', 'anchor-schema' ) : sprintf( /* translators: %d: number of packages */ \__( 'Tracking (%d packages)', 'anchor-schema' ), $count ) ); ?></h2>
	<?php foreach ( $rows as $r ) :
		$key   = $r['status_key'];
		$at    = $reached[ $key ] ?? 0;
		$alert = in_array( $key, [ 'exception', 'returned' ], true );
		if ( 'delivered' === $key ) {
			$headline = $r['delivered_at'] ? sprintf( /* translators: %s: delivery day */ \__( 'Delivered %s', 'anchor-schema' ), $day( $r['delivered_at'] ) ) : \__( 'Delivered', 'anchor-schema' );
		} elseif ( $alert ) {
			$headline = '' !== $r['status'] ? $r['status'] : \__( 'Delivery problem', 'anchor-schema' );
		} elseif ( 'label_created' === $key ) {
			$headline = \__( 'Preparing to ship', 'anchor-schema' );
		} else {
			$headline = '' !== $r['status'] ? $r['status'] : \__( 'In transit', 'anchor-schema' );
		}
		$expected = ( '' !== $r['expected'] && ! in_array( $key, [ 'delivered', 'returned' ], true ) ) ? strtotime( $r['expected'] . ' 12:00:00 UTC' ) : 0;
		$detail   = trim( $r['detail'] );
		$detail   = ( '' !== $detail && \strtoupper( $detail ) === $detail ) ? \ucfirst( \strtolower( $detail ) ) : $detail; // UPS often shouts: "DEPARTED FROM FACILITY"
		?>
		<div class="ast-card ast-is-<?php echo \esc_attr( $key ); ?>">
			<p class="ast-headline"><?php echo \esc_html( $headline ); ?></p>
			<?php if ( ! $alert ) : ?>
				<ol class="ast-steps">
					<?php $i = 0; foreach ( $steps as $step => $label ) : ?>
						<li class="ast-step<?php echo $i < $at ? ' is-done' : ( $i === $at ? ' is-current' : '' ); ?>"<?php echo $i === $at ? ' aria-current="step"' : ''; ?>><span><?php echo \esc_html( $label ); ?></span></li>
					<?php ++$i; endforeach; ?>
				</ol>
			<?php endif; ?>
			<dl class="ast-rows">
				<?php if ( $expected ) : ?>
					<div class="ast-row"><dt><?php \esc_html_e( 'Expected delivery', 'anchor-schema' ); ?></dt><dd><?php echo \esc_html( \wp_date( 'l, F j', $expected, new \DateTimeZone( 'UTC' ) ) ); ?></dd></div>
				<?php endif; ?>
				<?php if ( 'label_created' === $key ) : ?>
					<div class="ast-row"><dt><?php \esc_html_e( 'Status', 'anchor-schema' ); ?></dt><dd><?php \esc_html_e( 'Label created. Tracking updates once the carrier scans the package.', 'anchor-schema' ); ?></dd></div>
				<?php elseif ( '' !== $detail || '' !== $r['location'] ) : ?>
					<div class="ast-row"><dt><?php \esc_html_e( 'Latest update', 'anchor-schema' ); ?></dt><dd><?php echo \esc_html( implode( ' — ', array_filter( [ $detail, $r['location'] ] ) ) ); ?></dd></div>
				<?php endif; ?>
				<?php if ( $r['event_at'] && 'label_created' !== $key ) : ?>
					<div class="ast-row"><dt><?php \esc_html_e( 'Updated', 'anchor-schema' ); ?></dt><dd><?php echo \esc_html( $moment( $r['event_at'] ) ); ?></dd></div>
				<?php endif; ?>
				<div class="ast-row"><dt><?php echo \esc_html( sprintf( /* translators: %s: carrier name */ \__( '%s tracking', 'anchor-schema' ), $r['carrier'] ) ); ?></dt><dd>
					<?php if ( '' !== $r['url'] ) : ?>
						<a href="<?php echo \esc_url( $r['url'] ); ?>" target="_blank" rel="noopener"><?php echo \esc_html( $r['number'] ); ?><span class="ast-sr"> <?php \esc_html_e( '(opens the carrier\'s tracking page)', 'anchor-schema' ); ?></span></a>
					<?php else : ?>
						<?php echo \esc_html( $r['number'] ); ?>
					<?php endif; ?>
				</dd></div>
			</dl>
		</div>
	<?php endforeach; ?>
</section>
