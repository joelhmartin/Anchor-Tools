<?php
declare(strict_types=1);

namespace Anchor\Announcements\Audience\Conditions;

use Anchor\Announcements\Audience\Condition;
use Anchor\Announcements\Audience\RecipientSet;
use Anchor\Announcements\Audience\WooOrders;
use Anchor\Announcements\Support\Dates;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class WcPurchased implements Condition {
	public function key(): string { return 'wc_purchased'; }
	public function label(): string { return \__( 'Purchased a product', 'anchor-schema' ); }
	public function group(): string { return 'WooCommerce'; }
	public function available(): bool { return WooOrders::available(); }
	public function fields(): array {
		return [
			[ 'key' => 'products', 'type' => 'search', 'search' => 'products', 'label' => \__( 'Any of these products (empty: any product)', 'anchor-schema' ) ],
			[ 'key' => 'from', 'type' => 'date', 'label' => \__( 'Ordered from', 'anchor-schema' ) ],
			[ 'key' => 'to', 'type' => 'date', 'label' => \__( 'Ordered to', 'anchor-schema' ) ],
			[ 'key' => 'statuses', 'type' => 'multiselect', 'label' => \__( 'Order status', 'anchor-schema' ), 'options' => WooOrders::available() ? \wc_get_order_statuses() : [], 'default' => [ 'wc-completed', 'wc-processing' ] ],
		];
	}
	public function match( array $params ): RecipientSet {
		[ $from, $to ] = Dates::gmt_range( (string) ( $params['from'] ?? '' ), (string) ( $params['to'] ?? '' ) );
		$set = new RecipientSet();
		foreach ( WooOrders::orders( WooOrders::statuses( (array) ( $params['statuses'] ?? [] ) ), $from, $to, (array) ( $params['products'] ?? [] ) ) as $row ) {
			WooOrders::add_to( $set, $row );
		}
		return $set;
	}
}
