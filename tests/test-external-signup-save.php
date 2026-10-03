<?php
/**
 * The "Use external signup form" checkbox on the save path.
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Module;
use Anchor\Events\Occurrences;

/**
 * @group event-save
 */
class Test_External_Signup_Save extends Anchor_Events_TestCase {

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	public function tear_down() {
		$_POST = [];
		parent::tear_down();
	}

	private function save( $event_id, array $fields ) {
		$_POST = array_merge( [
			Module::NONCE             => wp_create_nonce( Module::NONCE ),
			'anchor_event_start_date' => '2026-11-01',
			'anchor_event_registration_enabled' => '1',
		], $fields );
		$this->module()->save_meta( $event_id );
		return get_post_meta( $event_id, '_anchor_event_registration_mode', true );
	}

	public function test_ticked_box_saves_external_and_remembers_the_native_choice() {
		$id = $this->make_event( [ 'registration_mode' => 'free' ] );
		$this->assertSame( 'external', $this->save( $id, [
			'anchor_event_external_signup_present' => '1',
			'anchor_event_external_signup'         => '1',
			'anchor_event_registration_mode'       => 'wc',
		] ) );
		$this->assertSame( 'wc', get_post_meta( $id, '_anchor_event_native_registration_mode', true ) );
		$this->assertSame( 'wc', $this->module()->native_registration_mode( $id ) );
	}

	public function test_unticking_hands_control_back_to_the_select() {
		$id = $this->make_event( [ 'registration_mode' => 'external', 'native_registration_mode' => 'wc' ] );
		$this->assertSame( 'free', $this->save( $id, [
			'anchor_event_external_signup_present' => '1',
			'anchor_event_registration_mode'       => 'free',
		] ) );
	}

	public function test_unticked_with_no_usable_select_value_restores_the_remembered_mode() {
		// A disabled "WooCommerce ticketed" option posts nothing, and an old
		// cached form may still post 'external' — neither is a native choice.
		$id = $this->make_event( [ 'registration_mode' => 'external', 'native_registration_mode' => 'wc' ] );
		$this->assertSame( 'wc', $this->save( $id, [
			'anchor_event_external_signup_present' => '1',
			'anchor_event_registration_mode'       => 'external',
		] ) );
	}

	public function test_form_without_the_checkbox_never_flips_an_external_event() {
		$id = $this->make_event( [ 'registration_mode' => 'external' ] );
		$this->assertSame( 'external', $this->save( $id, [] ), 'No marker, no select: keep what is stored.' );
	}

	public function test_legacy_select_posting_external_still_works() {
		$id = $this->make_event( [ 'registration_mode' => 'free' ] );
		$this->assertSame( 'external', $this->save( $id, [ 'anchor_event_registration_mode' => 'external' ] ) );
		$this->assertSame( 'free', get_post_meta( $id, '_anchor_event_native_registration_mode', true ), 'The native mode it came from is remembered.' );
	}

	public function test_native_registration_mode_accessor() {
		$this->assertSame( 'free', $this->module()->native_registration_mode( $this->make_event( [ 'registration_mode' => 'free' ] ) ) );
		$this->assertSame( 'free', $this->module()->native_registration_mode( $this->make_event( [ 'registration_mode' => 'external' ] ) ), 'Nothing remembered → free.' );
	}

	public function test_dates_of_an_offering_inherit_the_remembered_mode() {
		$this->assertContains( 'native_registration_mode', Occurrences::INHERITED_KEYS );
	}
}
