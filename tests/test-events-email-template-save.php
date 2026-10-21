<?php
/**
 * Email template save: an unchanged template is never stored, whatever line
 * endings the browser posts, and a responsive <style> block survives.
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Module;

/**
 * @group email
 * @group event-save
 */
class Test_Events_Email_Template_Save extends Anchor_Events_TestCase {

	const TPL = '_anchor_event_email_tpl_';

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	public function tear_down() {
		unset( $_POST );
		parent::tear_down();
	}

	private function console_save( $event_id, array $post ) {
		$_POST  = wp_slash( $post );
		$method = new ReflectionMethod( Module::class, 'save_event_manager_fields' );
		$method->setAccessible( true );
		$method->invoke( $this->module(), $event_id, '2027-05-04', $this->module()->registration_mode( $event_id ) );
		unset( $_POST );
	}

	private function crlf( $s ) {
		return str_replace( "\n", "\r\n", str_replace( "\r\n", "\n", $s ) ) . "  \r\n";
	}

	private function date_of_group() {
		$main = $this->make_event( [ 'title' => 'Workshop', 'timezone' => 'UTC' ] );
		update_post_meta( $main, '_anchor_event_offering_dates', [ [ 'date' => '2027-05-04', 'start_time' => '09:00', 'end_time' => '11:00', 'label' => 'S', 'capacity' => 10 ] ] );
		$dates = array_map( 'intval', $this->module()->occurrences->reconcile( $main ) );
		return [ $main, $dates[0] ];
	}

	public function test_crlf_unchanged_default_is_not_stored_on_a_main_event() {
		$event = $this->make_event();
		foreach ( [ 'confirmation', 'reminder', 'cancellation', 'roster' ] as $type ) {
			$default = $this->module()->resolve_email_template( $type, $event );
			$this->assertStringContainsString( "\n", $default );
			$this->console_save( $event, [ 'anchor_event_registration_mode' => 'free', 'anchor_email_tpl_' . $type => $this->crlf( $default ) ] );
			$this->assertSame( '', get_post_meta( $event, self::TPL . $type, true ), $type . ' default re-posted with CRLF must not become an override.' );
		}
	}

	public function test_crlf_unchanged_default_is_not_stored_on_a_date() {
		list( , $date ) = $this->date_of_group();
		$default = $this->module()->resolve_email_template( 'reminder', $date );
		$this->console_save( $date, [ 'anchor_event_registration_mode' => 'free', 'anchor_email_tpl_reminder' => $this->crlf( $default ) ] );
		$this->assertFalse( metadata_exists( 'post', $date, self::TPL . 'reminder' ) );
	}

	public function test_crlf_unchanged_inherited_template_is_not_stored_on_a_date() {
		list( $main, $date ) = $this->date_of_group();
		update_post_meta( $main, self::TPL . 'confirmation', "<div id=\"main-tpl\">\n  {intro}\n</div>" );
		$inherited = $this->module()->resolve_email_template( 'confirmation', $date );
		$this->assertStringContainsString( 'main-tpl', $inherited );
		$this->console_save( $date, [ 'anchor_event_registration_mode' => 'free', 'anchor_email_tpl_confirmation' => $this->crlf( $inherited ) ] );
		$this->assertFalse( metadata_exists( 'post', $date, self::TPL . 'confirmation' ) );
	}

	public function test_a_real_edit_is_still_stored() {
		$event = $this->make_event();
		$this->console_save( $event, [ 'anchor_event_registration_mode' => 'free', 'anchor_email_tpl_confirmation' => "<p>Custom\r\n{event_title}</p>" ] );
		$this->assertStringContainsString( 'Custom', get_post_meta( $event, self::TPL . 'confirmation', true ) );
	}

	public function test_a_field_not_posted_leaves_the_stored_template_alone() {
		$event = $this->make_event();
		update_post_meta( $event, self::TPL . 'roster', '<p>Keep me</p>' );
		$this->console_save( $event, [ 'anchor_event_registration_mode' => 'free' ] );
		$this->assertSame( '<p>Keep me</p>', get_post_meta( $event, self::TPL . 'roster', true ) );
	}

	private function saved_confirmation( $html ) {
		$event = $this->make_event();
		$this->console_save( $event, [ 'anchor_event_registration_mode' => 'free', 'anchor_email_tpl_confirmation' => $html ] );
		return (string) get_post_meta( $event, self::TPL . 'confirmation', true );
	}

	public function test_style_block_is_kept_with_media_attribute() {
		$saved = $this->saved_confirmation( '<html><head><style type="text/css" media="screen">@media (max-width:480px){.btn{width:100%}}</style></head><body><p>Hi</p></body></html>' );
		$this->assertStringContainsString( '<style type="text/css" media="screen">', $saved );
		$this->assertStringContainsString( '@media (max-width:480px){.btn{width:100%}}', $saved );
		$this->assertStringContainsString( '</style>', $saved );
	}

	public function test_style_block_keeps_child_selectors_and_drops_other_attributes() {
		$saved = $this->saved_confirmation( '<style onload="x()" src="//evil">td > a{color:red}</style><p>x</p>' );
		$this->assertStringContainsString( 'td > a{color:red}', $saved );
		$this->assertStringNotContainsString( 'onload', $saved );
		$this->assertStringNotContainsString( 'evil', $saved );
	}

	public function test_script_after_or_inside_style_is_stripped() {
		$saved = $this->saved_confirmation( '<style>a{}</style><script>alert(1)</script><p>x</p>' );
		$this->assertStringNotContainsString( '<script', $saved );
		$this->assertStringContainsString( '<style>a{}</style>', $saved );

		$saved = $this->saved_confirmation( '<style>a{}</style><script>alert(2)</script><style>b{}</style><img src=x onerror=alert(3)>' );
		$this->assertStringNotContainsString( '<script', $saved );
		$this->assertStringNotContainsString( 'onerror', $saved );

		// A closing tag in the CSS text cannot smuggle markup: it is parsed as a tag and filtered.
		$saved = $this->saved_confirmation( '<style>a{} </style><script>alert(4)</script><style> b{}</style>' );
		$this->assertStringNotContainsString( '<script', $saved );
		$saved = $this->saved_confirmation( '<style>a{content:"</style><iframe src=//evil></iframe><style>"}</style>' );
		$this->assertStringNotContainsString( '<iframe', $saved );
	}

	public function test_preview_keeps_the_style_element() {
		$event = $this->make_event();
		$html  = $this->module()->render_email_preview_html(
			$event,
			'confirmation',
			'<html><head><meta charset="utf-8"><style media="screen">@media (max-width:480px){.pv{width:100%}}</style></head><body><div class="pv">{event_title}</div></body></html>'
		);
		$this->assertStringContainsString( '<style media="screen">@media (max-width:480px){.pv{width:100%}}</style>', $html );
		$this->assertLessThan( stripos( $html, '<body' ), stripos( $html, '<style media' ), 'The style stays in the head.' );
	}
}
