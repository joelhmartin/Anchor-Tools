<?php
declare(strict_types=1);

namespace Anchor\Agreements\Services;

use Anchor\Agreements\Database\SignatureRepository;
use Anchor\Agreements\Database\VersionRepository;
use Anchor\Agreements\Frontend\SignedCopyPage;
use Anchor\Agreements\Support\Settings;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** One staff email per order, sent only once the order is paid/held (never for a failed attempt). */
final class Notifier {

	public function __construct() {
		foreach ( [ 'processing', 'completed', 'on-hold' ] as $status ) {
			\add_action( 'woocommerce_order_status_' . $status, [ self::class, 'maybe_send' ] );
		}
	}

	public static function subject( \WC_Order $order, array $rows ): string {
		$versions = new VersionRepository();
		$titles   = array_unique( array_map( fn( $r ) => ( $versions->get( (int) $r['version_id'] )['title'] ?? '' ), $rows ) );
		$subject  = Settings::notify_subject( implode( ', ', $titles ), (string) $rows[0]['signer_name'], $order->get_id() );
		// A subject is plain text, but post titles may be entity-encoded ("&#8211;", "&amp;"); decode once here
		// (same house pattern as the events module's mail sender).
		return \html_entity_decode( $subject, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	public static function maybe_send( $order_id ): bool {
		$order = \wc_get_order( (int) $order_id );
		if ( ! Settings::notify_enabled() || ! $order || 'yes' === $order->get_meta( '_anchor_agreements_notified' ) ) {
			return false;
		}
		$rows = ( new SignatureRepository() )->for_order( $order->get_id() );
		if ( ! $rows ) {
			return false;
		}
		$body = '<p>' . \esc_html( sprintf( 'Order #%d — %s', $order->get_id(), $order->get_formatted_billing_full_name() ) ) . '</p><ul>';
		foreach ( $rows as $r ) {
			$body .= sprintf( '<li>%s — %s (%s) · <a href="%s">View signed copy</a></li>',
				\esc_html( ( new VersionRepository() )->get( (int) $r['version_id'] )['title'] ?? '' ),
				\esc_html( $r['signer_name'] ),
				\esc_html( $r['method'] ),
				\esc_url( SignedCopyPage::url( $r['token'] ) )
			);
		}
		$body .= '</ul><p><a href="' . \esc_url( $order->get_edit_order_url() ) . '">Open order</a></p>';
		$subject = self::subject( $order, $rows );
		$html    = class_exists( '\Anchor_Email_Shell' ) ? \Anchor_Email_Shell::render( [ 'title' => $subject, 'preheader' => $subject, 'body' => $body ] ) : $body;
		$sent    = \wp_mail( Settings::notify_recipients(), $subject, $html, [ 'Content-Type: text/html; charset=UTF-8' ] );
		if ( $sent ) { // A failed send is retried on the next status change instead of being lost.
			$order->update_meta_data( '_anchor_agreements_notified', 'yes' );
			$order->save_meta_data();
		}
		return (bool) $sent;
	}
}
