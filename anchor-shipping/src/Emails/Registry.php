<?php
declare(strict_types=1);

namespace Anchor\Shipping\Emails;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class Registry {

	public const ACTIONS = [ 'anchor_shipping_label_created', 'anchor_shipping_needs_attention', 'anchor_shipping_problem' ];

	public static function register( array $emails ): array {
		$emails['Anchor_Shipping_Label_Email']         = new LabelEmail();
		$emails['Anchor_Shipping_Ready_To_Ship_Email'] = new ReadyToShipEmail();
		$emails['Anchor_Shipping_Problem_Email']       = new ProblemEmail();
		return $emails;
	}

	public static function actions( array $actions ): array {
		return array_values( array_unique( array_merge( $actions, self::ACTIONS ) ) );
	}
}
