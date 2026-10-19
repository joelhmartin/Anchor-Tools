<?php
declare(strict_types=1);

namespace Anchor\Shipping\Emails;

use Anchor\Shipping\Module;
use Anchor\Shipping\Support\TrackingLinks;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** To the customer, once, when the carrier first scans a package: carrier, tracking links, address, items. */
final class ShippedEmail extends \WC_Email {

	/** @var array<int, array> */
	public array $tracking = [];

	public function __construct() {
		$this->id             = 'anchor_shipping_shipped';
		$this->customer_email = true;
		$this->title          = \__( 'Order shipped', 'anchor-schema' );
		$this->description    = \__( 'Sent to the customer when the carrier first scans their package, with tracking links.', 'anchor-schema' );
		$this->template_base  = Module::dir() . '/templates/';
		$this->template_html  = 'emails/shipped.php';
		$this->template_plain = 'emails/shipped-plain.php';
		$this->placeholders   = [ '{order_number}' => '' ];
		\add_action( 'anchor_shipping_first_scan_notification', [ $this, 'trigger' ], 10, 1 );
		parent::__construct();
	}

	public function get_default_subject(): string {
		return \__( 'Your order #{order_number} has shipped', 'anchor-schema' );
	}

	public function get_default_heading(): string {
		return \__( 'Your order is on its way', 'anchor-schema' );
	}

	public function trigger( int $order_id ): void {
		$this->setup_locale();
		$this->object = \wc_get_order( $order_id );
		if ( $this->object ) {
			$this->recipient                      = $this->object->get_billing_email();
			$this->placeholders['{order_number}'] = $this->object->get_order_number();
			$module                               = Module::instance();
			$this->tracking                       = $module ? ( new TrackingLinks( $module->shipments, $module->carriers ) )->for_order( $order_id ) : [];
		}
		if ( $this->object && $this->tracking && $this->is_enabled() && $this->get_recipient() ) {
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}
		$this->restore_locale();
	}

	public function get_content_html() {
		return \wc_get_template_html( $this->template_html, $this->template_args(), '', $this->template_base );
	}

	public function get_content_plain() {
		return \wc_get_template_html( $this->template_plain, $this->template_args(), '', $this->template_base );
	}

	private function template_args(): array {
		return [ 'order' => $this->object, 'tracking' => $this->tracking, 'email_heading' => $this->get_heading(), 'email' => $this ];
	}
}
