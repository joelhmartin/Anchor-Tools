<?php
declare(strict_types=1);

namespace Anchor\Announcements\Audience\Conditions;

use Anchor\Announcements\Audience\Condition;
use Anchor\Announcements\Audience\RecipientSet;
use Anchor\Announcements\Audience\WooOrders;
use Anchor\Announcements\Support\Dates;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class WcTotalSpent implements Condition {
	public function key(): string { return 'wc_total_spent'; }
	public function label(): string { return \__( 'Total spent', 'anchor-schema' ); }
	public function group(): string { return 'WooCommerce'; }
	public function available(): bool { return WooOrders::available(); }
	public function fields(): array {
		return [
			[ 'key' => 'compare', 'type' => 'select', 'label' => \__( 'Compare', 'anchor-schema' ), 'options' => [ '>=' => 'at least', '<=' => 'at most', '=' => 'exactly' ], 'default' => '>=' ],
			[ 'key' => 'amount', 'type' => 'number', 'label' => \__( 'Amount', 'anchor-schema' ), 'default' => 100 ],
			[ 'key' => 'from', 'type' => 'date', 'label' => \__( 'From', 'anchor-schema' ) ],
			[ 'key' => 'to', 'type' => 'date', 'label' => \__( 'To', 'anchor-schema' ) ],
		];
	}
	public function match( array $params ): RecipientSet {
		[ $from, $to ] = Dates::gmt_range( (string) ( $params['from'] ?? '' ), (string) ( $params['to'] ?? '' ) );
		$set = new RecipientSet();
		foreach ( WooOrders::aggregate( 'total', WooOrders::statuses( [] ), $from, $to ) as $agg ) {
			if ( WooOrders::compare( $agg['value'], (string) ( $params['compare'] ?? '>=' ), (float) ( $params['amount'] ?? 0 ) ) ) {
				WooOrders::add_to( $set, $agg['row'] );
			}
		}
		return $set;
	}
}
