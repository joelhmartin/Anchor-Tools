<?php
/**
 * Both authoring surfaces: the checkbox, the fields it reveals, and the
 * native-only sections it hides — which must still be RENDERED (hidden by
 * JS) so their stored values round-trip through every save.
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Module;

/**
 * @group event-save
 */
class Test_External_Signup_Ui extends Anchor_Events_TestCase {

	const STREAM = [ 'provider' => 'vimeo', 'kind' => 'iframe', 'src' => 'https://player.vimeo.com/video/76979871', 'raw' => 'https://vimeo.com/76979871' ];

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	public function tear_down() {
		$_POST = [];
		parent::tear_down();
	}

	private function external_event() {
		return $this->make_event( [
			'registration_mode'        => 'external',
			'native_registration_mode' => 'wc',
			'external_embed'           => '<iframe src="https://form.example/1"></iframe>',
			'capacity'                 => 25,
			'stream_embed'             => self::STREAM,
		] );
	}

	private function metabox( $id ) {
		ob_start();
		$this->module()->render_meta_box( get_post( $id ) );
		return (string) ob_get_clean();
	}

	private function console( $id ) {
		$m = new ReflectionMethod( Module::class, 'render_event_manager_form' );
		$m->setAccessible( true );
		return (string) $m->invoke( $this->module(), $id, true );
	}

	public function test_checkbox_reflects_the_mode_and_external_is_no_longer_a_select_option() {
		foreach ( [ 'metabox', 'console' ] as $surface ) {
			$ext  = $this->$surface( $this->external_event() );
			$free = $this->$surface( $this->make_event( [ 'registration_mode' => 'free' ] ) );
			$this->assertMatchesRegularExpression( '/id="anchor_event_external_signup"[^>]*checked/', $ext, $surface );
			$this->assertDoesNotMatchRegularExpression( '/id="anchor_event_external_signup"[^>]*checked/', $free, $surface );
			$this->assertStringContainsString( 'name="anchor_event_external_signup_present" value="1"', $ext, $surface );
			$this->assertStringNotContainsString( '<option value="external"', $ext, $surface );
			$this->assertMatchesRegularExpression( '/data-when-mode="wc free"[^>]*>\s*<label for="anchor_event_registration_mode"/', $ext, $surface );
			$this->assertMatchesRegularExpression( '/<option value="wc"[^>]*selected/', $ext, "$surface preselects the remembered native mode" );
		}
	}

	public function test_external_section_sits_under_the_checkbox_on_both_surfaces() {
		foreach ( [ 'metabox', 'console' ] as $surface ) {
			$html = $this->$surface( $this->external_event() );
			$this->assertSame( 1, preg_match_all( '/class="[^"]*anchor-event-external-signup"[^>]*data-when-mode="external"[^>]*data-step="2"/', $html ), $surface );
			$this->assertStringContainsString( '&lt;iframe src=&quot;https://form.example/1&quot;&gt;', $html, "$surface textarea carries the stored embed" );
			$this->assertLessThan( strpos( $html, 'anchor_event_start_date' ), strpos( $html, 'anchor-event-external-signup"' ), "$surface: the fields appear right below the checkbox, before Date & Time" );
		}
	}

	public function test_external_metabox_still_carries_every_native_setting() {
		$html = $this->metabox( $this->external_event() );
		$this->assertStringContainsString( 'https://vimeo.com/76979871', $html, 'The stored stream is rendered (hidden), not dropped.' );
		$this->assertMatchesRegularExpression( '/anchor-event-livestream[^"]*"[^>]*data-when-mode="wc free"/', $html );
		$this->assertMatchesRegularExpression( '/anchor-event-access[^"]*"[^>]*data-when-mode="wc free"/', $html );
		$this->assertMatchesRegularExpression( '/name="anchor_event_capacity"[^>]*value="25"/', $html );
	}

	public function test_console_hides_questions_and_emails_and_explains_step_five() {
		$html = $this->console( $this->external_event() );
		$this->assertMatchesRegularExpression( '/data-when-mode="wc free"[^>]*data-step="4">\s*<h3>Attendee questions/', $html );
		$this->assertMatchesRegularExpression( '/data-when-mode="wc free"[^>]*data-step="5">\s*<h3>Email Settings/', $html );
		$this->assertMatchesRegularExpression( '/anchor-event-external-signup-notice[^>]*data-when-mode="external"[^>]*data-step="5"/', $html );
	}

	public function test_external_save_keeps_the_stored_stream() {
		$id    = $this->external_event();
		$_POST = [
			Module::NONCE                          => wp_create_nonce( Module::NONCE ),
			'anchor_event_start_date'              => '2026-11-01',
			'anchor_event_external_signup_present' => '1',
			'anchor_event_external_signup'         => '1',
			'anchor_event_registration_mode'       => 'wc',
			// What the now-always-rendered (hidden) livestream textarea posts:
			'anchor_event_stream_embed'            => 'https://vimeo.com/76979871',
			'anchor_event_capacity'                => '25',
		];
		$this->module()->save_meta( $id );
		$stream = get_post_meta( $id, '_anchor_event_stream_embed', true );
		$this->assertSame( 'https://player.vimeo.com/video/76979871', $stream['src'] ?? '' );
		$this->assertSame( '25', (string) get_post_meta( $id, '_anchor_event_capacity', true ) );
	}
}
