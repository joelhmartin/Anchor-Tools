<?php
declare(strict_types=1);

namespace Anchor\Shipping\Emails;

use Anchor\Shipping\Module;
use Anchor\Shipping\Support\Settings;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** To the shipping inbox: "order #N needs a label", with a Create label button. */
final class ReadyToShipEmail extends \WC_Email {

	public string $reason = '';

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

	public function trigger( int $order_id, string $reason ): void {
		$this->setup_locale();
		$this->object    = \wc_get_order( $order_id );
		$this->recipient = Settings::inbox();
		$this->reason    = $reason;
		if ( $this->object && $this->is_enabled() && $this->get_recipient() ) {
			$this->placeholders['{order_number}'] = $this->object->get_order_number();
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}
		$this->restore_locale();
	}

	public function create_url(): string {
		return $this->object ? $this->object->get_edit_order_url() . '#anchor-shipping' : '';
	}

	public function get_content_html() {
		return \wc_get_template_html( $this->template_html, [ 'order' => $this->object, 'reason' => $this->reason, 'create_url' => $this->create_url(), 'email_heading' => $this->get_heading(), 'email' => $this ], '', $this->template_base );
	}

	public function get_content_plain() {
		return \wc_get_template_html( $this->template_plain, [ 'order' => $this->object, 'reason' => $this->reason, 'create_url' => $this->create_url(), 'email_heading' => $this->get_heading(), 'email' => $this ], '', $this->template_base );
	}
}
