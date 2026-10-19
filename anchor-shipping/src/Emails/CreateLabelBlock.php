<?php
declare(strict_types=1);

namespace Anchor\Shipping\Emails;

use Anchor\Shipping\Module;
use Anchor\Shipping\Services\LabelService;
use Anchor\Shipping\Support\Settings;
use Anchor\Shipping\Support\Urls;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** Adds a "Create shipping label" button to WooCommerce's admin New order email for shippable orders. */
final class CreateLabelBlock {

	public function __construct( private LabelService $labels ) {
		\add_action( 'woocommerce_email_after_order_table', [ $this, 'render' ], 20, 4 );
	}

	public function render( $order, $sent_to_admin = false, $plain_text = false, $email = null ): void {
		if ( ! $order instanceof \WC_Order || ! $email instanceof \WC_Email || 'new_order' !== $email->id ) {
			return;
		}
		if ( ! Settings::all()['new_order_button'] || ! $this->labels->is_shippable( $order ) ) {
			return;
		}
		\wc_get_template(
			$plain_text ? 'emails/create-label-block-plain.php' : 'emails/create-label-block.php',
			[ 'url' => Urls::order( $order ) ],
			'',
			Module::dir() . '/templates/'
		);
	}
}
