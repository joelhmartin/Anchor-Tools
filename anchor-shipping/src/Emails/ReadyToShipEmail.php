<?php
declare(strict_types=1);

namespace Anchor\Shipping\Emails;

use Anchor\Shipping\Module;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** To the shipping inbox: "order #N needs a label", with a Create label button. */
final class ReadyToShipEmail extends OrderAlertEmail {

	public function __construct() {
		$this->id             = 'anchor_shipping_ready_to_ship';
		$this->title          = \__( 'Ready to ship', 'anchor-schema' );
		$this->description    = \__( 'Sent to the Anchor Shipping inbox when a paid order needs someone to create its label.', 'anchor-schema' );
		$this->template_base  = Module::dir() . '/templates/';
		$this->template_html  = 'emails/ready-to-ship.php';
		$this->template_plain = 'emails/ready-to-ship-plain.php';
		$this->placeholders   = [ '{order_number}' => '' ];
		\add_action( 'anchor_shipping_needs_attention_notification', [ $this, 'trigger' ], 10, 2 );
		parent::__construct();
	}

	public function get_default_subject(): string {
		return \__( '[{site_title}] Ready to ship — order #{order_number}', 'anchor-schema' );
	}

	public function get_default_heading(): string {
		return \__( 'Order #{order_number} is ready to ship', 'anchor-schema' );
	}
}
