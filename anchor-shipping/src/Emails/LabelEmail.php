<?php
declare(strict_types=1);

namespace Anchor\Shipping\Emails;

use Anchor\Shipping\Module;
use Anchor\Shipping\Support\Settings;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** To the shipping inbox: "here is the label for order #N", label attached. */
final class LabelEmail extends \WC_Email {

	/** @var array<int, array> shipment rows */
	public array $rows = [];

	public function __construct() {
		$this->id             = 'anchor_shipping_label';
		$this->title          = \__( 'Shipping label created', 'anchor-schema' );
		$this->description    = \__( 'Sent to the Anchor Shipping inbox with the label attached whenever a label is created.', 'anchor-schema' );
		$this->template_base  = Module::dir() . '/templates/';
		$this->template_html  = 'emails/label.php';
		$this->template_plain = 'emails/label-plain.php';
		$this->placeholders   = [ '{order_number}' => '' ];
		\add_action( 'anchor_shipping_label_created_notification', [ $this, 'trigger' ], 10, 2 );
		parent::__construct();
	}

	public function get_default_subject(): string {
		return \__( '[{site_title}] Label ready — order #{order_number}', 'anchor-schema' );
	}

	public function get_default_heading(): string {
		return \__( 'Label ready for order #{order_number}', 'anchor-schema' );
	}

	public function trigger( int $order_id, array $ids ): void {
		$this->setup_locale();
		$this->object    = \wc_get_order( $order_id );
		$this->recipient = Settings::inbox();
		$this->rows      = array_values( array_filter( array_map( [ Module::instance()->shipments, 'find' ], array_map( 'intval', $ids ) ) ) );
		if ( $this->object && $this->rows && $this->is_enabled() && $this->get_recipient() ) {
			$this->placeholders['{order_number}'] = $this->object->get_order_number();
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}
		$this->restore_locale();
	}

	public function get_attachments() {
		$paths = [];
		foreach ( $this->rows as $r ) {
			$path = Module::instance()->store->absolute( (string) $r['label_path'] );
			if ( $path ) {
				$paths[] = $path;
			}
		}
		return \apply_filters( 'woocommerce_email_attachments', $paths, $this->id, $this->object, $this );
	}

	public function get_content_html() {
		return \wc_get_template_html( $this->template_html, [ 'order' => $this->object, 'rows' => $this->rows, 'email_heading' => $this->get_heading(), 'email' => $this ], '', $this->template_base );
	}

	public function get_content_plain() {
		return \wc_get_template_html( $this->template_plain, [ 'order' => $this->object, 'rows' => $this->rows, 'email_heading' => $this->get_heading(), 'email' => $this ], '', $this->template_base );
	}
}
