<?php
declare(strict_types=1);

namespace Anchor\Shipping\Emails;

use Anchor\Shipping\Support\Settings;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Shared behaviour of the emails that go to the shipping inbox about one order and one
 * reason (ready to ship / shipping problem). Subclasses set id, titles, templates and
 * hook their own `anchor_shipping_*_notification` action to trigger().
 */
abstract class OrderAlertEmail extends \WC_Email {

	public string $reason = '';

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
		return \wc_get_template_html( $this->template_html, $this->template_args(), '', $this->template_base );
	}

	public function get_content_plain() {
		return \wc_get_template_html( $this->template_plain, $this->template_args(), '', $this->template_base );
	}

	private function template_args(): array {
		return [ 'order' => $this->object, 'reason' => $this->reason, 'create_url' => $this->create_url(), 'email_heading' => $this->get_heading(), 'email' => $this ];
	}
}
