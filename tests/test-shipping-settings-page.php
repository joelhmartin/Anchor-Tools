<?php
use Anchor\Shipping\Admin\SettingsPage;
use Anchor\Shipping\Module;
use Anchor\Shipping\Support\Settings;

class Test_Shipping_Settings_Page extends Anchor_Shipping_TestCase {

	private function page(): SettingsPage {
		return new SettingsPage( Module::instance()->carriers );
	}

	public function test_blank_secret_keeps_the_stored_secret() {
		$this->configure_ups();
		$out = $this->page()->sanitize( [ 'carriers' => [ 'ups' => [ 'client_secret' => '', 'client_id' => 'new-id' ] ] ], Settings::all() );
		$this->assertSame( 'csecret', $out['carriers']['ups']['client_secret'] );
		$this->assertSame( 'new-id', $out['carriers']['ups']['client_id'] );
	}

	public function test_boxes_rows_are_parsed_and_blank_rows_dropped() {
		$out = $this->page()->sanitize(
			[ 'boxes' => [
				[ 'name' => 'Small Box', 'length' => '25.4', 'width' => '20.3', 'height' => '10.2', 'empty_weight' => '0.2', 'max_weight' => '10' ],
				[ 'name' => '', 'length' => '', 'width' => '', 'height' => '' ],
			] ],
			Settings::all()
		);
		$this->assertCount( 1, $out['boxes'] );
		$this->assertSame( 'small-box', $out['boxes'][0]['id'] );
		$this->assertSame( 25.4, $out['boxes'][0]['length'] );
	}

	public function test_renaming_a_box_keeps_its_id() {
		$current = Settings::all();
		$current['boxes'] = [ [ 'id' => 'small-box', 'name' => 'Small Box', 'length' => 25.4, 'width' => 20.3, 'height' => 10.2, 'empty_weight' => 0.2, 'max_weight' => 10 ] ];
		$out = $this->page()->sanitize(
			[ 'boxes' => [
				[ 'id' => 'small-box', 'name' => 'Small mailer', 'length' => '25.4', 'width' => '20.3', 'height' => '10.2' ],
				[ 'id' => '', 'name' => 'Small Box', 'length' => '30', 'width' => '20', 'height' => '10' ], // new row reusing the old name
			] ],
			$current
		);
		$this->assertSame( [ 'small-box', 'small-box-2' ], wp_list_pluck( $out['boxes'], 'id' ) );
		$this->assertSame( 'Small mailer', $out['boxes'][0]['name'] );
	}

	public function test_names_differing_only_in_case_get_distinct_ids() {
		$out = $this->page()->sanitize(
			[ 'boxes' => [
				[ 'name' => 'Small Box', 'length' => '1', 'width' => '1', 'height' => '1' ],
				[ 'name' => 'small box', 'length' => '2', 'width' => '2', 'height' => '2' ],
				[ 'name' => 'Custom', 'length' => '3', 'width' => '3', 'height' => '3' ],
			] ],
			Settings::all()
		);
		$ids = wp_list_pluck( $out['boxes'], 'id' );
		$this->assertSame( [ 'small-box', 'small-box-2', 'custom-2' ], $ids, "unique, and never the order panel's 'custom'" );
		Settings::save( $out );
		$this->assertCount( 3, Settings::boxes() );
	}

	public function test_render_posts_each_box_id_back() {
		$this->configure_ups();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		get_user_by( 'id', get_current_user_id() )->add_cap( 'manage_woocommerce' );
		ob_start();
		$this->page()->render();
		$html = ob_get_clean();
		$box  = Settings::all()['boxes'][0];
		$this->assertStringContainsString( 'name="anchor_shipping[boxes][0][id]" value="' . esc_attr( $box['id'] ) . '"', $html );
	}

	public function test_modes_and_flags_are_whitelisted() {
		$out = $this->page()->sanitize( [ 'insurance' => [ 'mode' => 'evil' ], 'signature' => [ 'mode' => 'auto' ], 'auto_label' => '1', 'label_format' => 'PDF' ], Settings::all() );
		$this->assertSame( 'off', $out['insurance']['mode'] );
		$this->assertSame( 'auto', $out['signature']['mode'] );
		$this->assertTrue( $out['auto_label'] );
		$this->assertSame( 'GIF', $out['label_format'] );
		$this->assertFalse( $this->page()->sanitize( [], Settings::all() )['auto_label'], 'unticked checkbox posts nothing' );
	}

	public function test_render_never_prints_the_secret() {
		$this->configure_ups( [ 'carriers' => [ 'ups' => [ 'client_secret' => 'TOP-SECRET-VALUE' ] ] ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		ob_start();
		$this->page()->render();
		$html = ob_get_clean();
		$this->assertStringNotContainsString( 'TOP-SECRET-VALUE', $html );
		$this->assertStringContainsString( 'name="anchor_shipping[carriers][ups][client_secret]"', $html );
		$this->assertStringContainsString( 'K877V9', $html, 'non-secret values are shown' );
	}

	public function test_render_explains_a_forced_sandbox() {
		$this->configure_ups();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$staging = static fn() => 'staging';
		add_filter( 'anchor_shipping_environment_type', $staging );
		ob_start();
		$this->page()->render();
		$html = ob_get_clean();
		remove_filter( 'anchor_shipping_environment_type', $staging );
		$this->assertStringContainsString( "Forced to Sandbox because this site&#039;s environment type is &#039;staging&#039;", $html );
		$this->assertStringContainsString( 'ANCHOR_SHIPPING_UPS_ENVIRONMENT', $html );
	}
}
