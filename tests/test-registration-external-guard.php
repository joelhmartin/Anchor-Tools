<?php
/**
 * handle_registration() must refuse external-mode events.
 *
 * The renderer routes on registration_mode() and shows an outbound link for an
 * external event, but the POST handler used to check only the LEGACY
 * registration_type meta. An event authored through the mode select stores
 * registration_mode and deliberately never writes registration_type, so the
 * two disagreed for exactly those events — the visitor sees a link, while a
 * direct POST still claimed a seat and sent a confirmation email.
 *
 * It is reachable because REG_NONCE is a bare action nonce, not bound to
 * event_id: a nonce rendered by any internal event's form verifies fine
 * against an external event's id.
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Module;

/** Thrown from the wp_redirect filter so handle_registration()'s exit never runs. */
class Anchor_Registration_Redirected extends \Exception {}

/**
 * @group registration
 */
class Test_Registration_External_Guard extends Anchor_Events_TestCase {

	public function set_up() {
		parent::set_up();
		add_filter( 'wp_redirect', [ $this, 'trap_redirect' ] );
	}

	public function tear_down() {
		remove_filter( 'wp_redirect', [ $this, 'trap_redirect' ] );
		$_POST    = [];
		$_REQUEST = [];
		parent::tear_down();
	}

	public function trap_redirect( $location ) {
		throw new Anchor_Registration_Redirected( (string) $location );
	}

	/** Post a well-formed internal registration and return where it redirected. */
	private function submit_registration( $event_id ) {
		$_POST = [
			Module::REG_NONCE    => wp_create_nonce( Module::REG_NONCE ),
			'event_id'           => $event_id,
			'redirect_to'        => 'https://example.org/events/',
			'anchor_event_name'  => 'Jane Doe',
			'anchor_event_email' => 'jane@example.org',
		];
		$_REQUEST = $_POST;

		try {
			$this->module()->handle_registration();
		} catch ( Anchor_Registration_Redirected $e ) {
			return $e->getMessage();
		}

		$this->fail( 'handle_registration() returned without redirecting.' );
	}

	/**
	 * The regression: mode set the modern way, legacy registration_type absent.
	 * This is what the front-end manager form and the metabox both write today.
	 */
	public function test_external_mode_event_refuses_internal_registration() {
		$event_id = $this->make_event(
			[
				'title'             => 'External Mode Event',
				'registration_mode' => 'external',
				'external_url'      => 'https://tickets.example.test/buy',
			]
		);

		$this->assertSame( 'external', $this->module()->registration_mode( $event_id ) );
		$this->assertNotSame(
			'external',
			get_post_meta( $event_id, '_anchor_event_registration_type', true ),
			'Fixture invalid: the legacy field must be unset, or the old guard would catch this.'
		);

		$location = $this->submit_registration( $event_id );

		$this->assertStringContainsString( 'registration_closed', $location );
		$this->assertSame(
			0,
			$this->module()->get_registration_count( $event_id ),
			'A seat was claimed on an external-registration event.'
		);
	}

	/** The legacy signal (a registration_url with no explicit mode) too. */
	public function test_legacy_external_url_event_refuses_internal_registration() {
		$event_id = $this->make_event(
			[
				'title'            => 'Legacy External Event',
				'registration_url' => 'https://tickets.example.test/legacy',
			]
		);

		$this->assertSame( 'external', $this->module()->registration_mode( $event_id ) );

		$location = $this->submit_registration( $event_id );

		$this->assertStringContainsString( 'registration_closed', $location );
		$this->assertSame( 0, $this->module()->get_registration_count( $event_id ) );
	}

	/**
	 * The control. A guard that blocks everything would pass the two tests
	 * above, so pin that an ordinary free event still registers.
	 */
	public function test_free_event_still_registers() {
		$event_id = $this->make_event( [ 'title' => 'Free Event' ] );

		$this->assertSame( 'free', $this->module()->registration_mode( $event_id ) );

		$location = $this->submit_registration( $event_id );

		$this->assertStringNotContainsString( 'registration_closed', $location );
		$this->assertSame( 1, $this->module()->get_registration_count( $event_id ) );
	}
}
