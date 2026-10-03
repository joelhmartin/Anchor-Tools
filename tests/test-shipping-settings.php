<?php
use Anchor\Shipping\Support\Settings;

class Test_Shipping_Settings extends Anchor_Shipping_TestCase {

	public function test_defaults_are_safe() {
		$s = Settings::all();
		$this->assertFalse( $s['auto_label'] );
		$this->assertSame( 'sandbox', $s['carriers']['ups']['environment'] );
		$this->assertSame( 'off', $s['insurance']['mode'] );
		$this->assertSame( 'off', $s['signature']['mode'] );
		$this->assertSame( 'GIF', $s['label_format'] );
	}

	public function test_saved_boxes_replace_defaults_and_are_keyed_by_id() {
		$this->configure_ups();
		$this->assertSame( [ 'small' ], array_keys( Settings::boxes() ) );
	}

	public function test_ship_from_falls_back_to_the_woo_store_address() {
		update_option( 'woocommerce_store_address', '400 North Ashley Drive' );
		update_option( 'woocommerce_store_city', 'Tampa' );
		update_option( 'woocommerce_store_postcode', '33602' );
		update_option( 'woocommerce_default_country', 'US:FL' );
		update_option( 'blogname', 'DEKA Dental Lasers' );
		$a = Settings::ship_from();
		$this->assertSame( '400 North Ashley Drive', $a->line1 );
		$this->assertSame( 'FL', $a->state );
		$this->assertSame( 'US', $a->country );
		$this->assertSame( 'DEKA Dental Lasers', $a->company );
	}

	public function test_constants_override_saved_credentials() {
		// A throwaway carrier id: constants can't be undefined, so never define a real UPS one in tests.
		$this->assertSame( 'ANCHOR_SHIPPING_UPS_CLIENT_SECRET', Settings::constant_name( 'ups', 'client_secret' ) );
		Settings::save( [ 'carriers' => [ 'zzconst' => [ 'account' => 'SAVED' ] ] ] );
		$this->assertFalse( Settings::is_constant( 'zzconst', 'account' ) );
		$this->assertSame( 'SAVED', Settings::carrier( 'zzconst' )['account'] );
		if ( ! defined( 'ANCHOR_SHIPPING_ZZCONST_ACCOUNT' ) ) {
			define( 'ANCHOR_SHIPPING_ZZCONST_ACCOUNT', 'FROMCONST' );
		}
		$this->assertTrue( Settings::is_constant( 'zzconst', 'account' ) );
		$this->assertSame( 'FROMCONST', Settings::carrier( 'zzconst' )['account'] );
	}

	public function test_inbox_falls_back_to_admin_email() {
		update_option( 'admin_email', 'admin@example.com' );
		$this->assertSame( 'admin@example.com', Settings::inbox() );
		$this->configure_ups();
		$this->assertSame( 'shipping@example.com', Settings::inbox() );
	}
}
