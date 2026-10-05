<?php
declare(strict_types=1);

namespace Anchor\Shipping\Emails;

use Anchor\Shipping\Module;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** To the shipping inbox: something went wrong with an order's label; no Create label button (a retry could double-buy). */
final class ProblemEmail extends OrderAlertEmail {

	public function __construct() {
		$this->id             = 'anchor_shipping_problem';
		$this->title          = \__( 'Shipping problem', 'anchor-schema' );
		$this->description    = \__( 'Sent to the Anchor Shipping inbox when a label purchase, save or void went wrong and a person must look at the order.', 'anchor-schema' );
		$this->template_base  = Module::dir() . '/templates/';
		$this->template_html  = 'emails/problem.php';
		$this->template_plain = 'emails/problem-plain.php';
		$this->placeholders   = [ '{order_number}' => '' ];
		\add_action( 'anchor_shipping_problem_notification', [ $this, 'trigger' ], 10, 2 );
		parent::__construct();
	}

	public function get_default_subject(): string {
		return \__( '[{site_title}] Shipping problem — order #{order_number}', 'anchor-schema' );
	}

	public function get_default_heading(): string {
		return \__( 'Shipping problem on order #{order_number}', 'anchor-schema' );
	}
}
