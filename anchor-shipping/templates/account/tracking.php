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
		$latest   = array_filter( [ $detail, $r['location'], $r['event_at'] ? $moment( $r['event_at'] ) : '' ] );
		?>
		<div class="ast-card ast-is-<?php echo \esc_attr( $key ); ?>">
			<div class="ast-head">
				<p class="ast-headline"><?php echo \esc_html( $headline ); ?></p>
				<?php if ( $expected ) : ?>
					<p class="ast-expected"><?php echo \esc_html( sprintf( /* translators: %s: expected delivery day */ \__( 'Expected %s', 'anchor-schema' ), \wp_date( 'l, F j', $expected, new \DateTimeZone( 'UTC' ) ) ) ); ?></p>
				<?php elseif ( 'label_created' === $key ) : ?>
					<p class="ast-expected"><?php \esc_html_e( 'The label is ready. Tracking updates once the carrier scans the package.', 'anchor-schema' ); ?></p>
				<?php endif; ?>
			</div>
			<?php if ( ! $alert ) : ?>
				<ol class="ast-steps">
					<?php $i = 0; foreach ( $steps as $step => $label ) : ?>
						<li class="ast-step<?php echo $i < $at ? ' is-done' : ( $i === $at ? ' is-current' : '' ); ?>"<?php echo $i === $at ? ' aria-current="step"' : ''; ?>><span><?php echo \esc_html( $label ); ?></span></li>
					<?php ++$i; endforeach; ?>
				</ol>
			<?php endif; ?>
			<?php if ( $latest && 'label_created' !== $key ) : ?>
				<p class="ast-latest"><span class="ast-label"><?php \esc_html_e( 'Latest update', 'anchor-schema' ); ?></span> <?php echo \esc_html( implode( ' · ', $latest ) ); ?></p>
			<?php endif; ?>
			<p class="ast-number">
				<span class="ast-label"><?php echo \esc_html( $r['carrier'] ); ?></span>
				<?php if ( '' !== $r['url'] ) : ?>
					<a href="<?php echo \esc_url( $r['url'] ); ?>" target="_blank" rel="noopener"><?php echo \esc_html( $r['number'] ); ?><span class="ast-sr"> <?php \esc_html_e( '(opens the carrier\'s tracking page)', 'anchor-schema' ); ?></span></a>
				<?php else : ?>
					<?php echo \esc_html( $r['number'] ); ?>
				<?php endif; ?>
			</p>
		</div>
	<?php endforeach; ?>
</section>
