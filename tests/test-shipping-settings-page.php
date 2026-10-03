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
}
