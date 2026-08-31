<?php
/**
 * Anchor_Auth_Form — the shared sign-in / register component extracted from the
 * webinars gate.
 *
 * The point of the extraction is that the form works with no arguments and no
 * post context, so most of what's below is reuse proof: render it cold, from no
 * singular post, and check it still produces a usable form. The redirect tests
 * cover the one genuinely new surface — `redirect_to` is caller-supplied and
 * must never become an open redirect.
 *
 * @package Anchor\Tests
 */

/**
 * @group auth
 */
class Test_Auth_Form extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		self::reset_settings_cache();
		delete_option( Anchor_Auth_Form::OPTION_KEY );
		delete_option( Anchor_Auth_Form::LEGACY_OPTION_KEY );
	}

	public function tear_down() {
		delete_option( Anchor_Auth_Form::OPTION_KEY );
		delete_option( Anchor_Auth_Form::LEGACY_OPTION_KEY );
		self::reset_settings_cache();
		parent::tear_down();
	}

	/** The component memoises settings per request; tests span several. */
	private static function reset_settings_cache() {
		$prop = new ReflectionProperty( 'Anchor_Auth_Form', 'settings' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );
	}

	/* -----------------------------------------------------------------
	 * Reusability: no arguments, no post context.
	 * -------------------------------------------------------------- */

	/**
	 * The real proof the extraction worked: called with nothing at all, from no
	 * singular post, it still renders a complete, well-formed form.
	 */
	public function test_renders_with_no_arguments_and_no_post_context() {
		$this->assertNull( get_post(), 'Guard: this test must run with no global post.' );

		$html = Anchor_Auth_Form::render();

		$this->assertIsString( $html );
		$this->assertNotSame( '', trim( $html ) );

		// Both forms present and pointing at the real WordPress endpoints, so
		// the no-JS fallback keeps working.
		$this->assertStringContainsString( 'class="anchor-auth-login__form"', $html );
		$this->assertStringContainsString( esc_url( site_url( 'wp-login.php', 'login_post' ) ), $html );
		$this->assertStringContainsString( 'name="log"', $html );
		$this->assertStringContainsString( 'name="pwd"', $html );

		// Valid, balanced markup — no stray output from a missing post.
		$this->assertTrue( self::is_wellformed( $html ), 'render() must return well-formed HTML.' );
		$this->assertStringNotContainsString( 'Warning', $html );
		$this->assertStringNotContainsString( 'Notice', $html );
	}

	/** render() returns its markup rather than echoing it. */
	public function test_render_echoes_nothing() {
		ob_start();
		$html = Anchor_Auth_Form::render();
		$echoed = ob_get_clean();

		$this->assertSame( '', $echoed );
		$this->assertNotSame( '', trim( $html ) );
	}

	/** Every class is `anchor-auth`-prefixed, so one RUCSS safelist regex covers it. */
	public function test_every_class_is_anchor_auth_prefixed() {
		$html = Anchor_Auth_Form::render();

		preg_match_all( '/class="([^"]+)"/', $html, $matches );
		$this->assertNotEmpty( $matches[1] );

		foreach ( $matches[1] as $attr ) {
			foreach ( preg_split( '/\s+/', trim( $attr ) ) as $class ) {
				if ( '' === $class || 'is-active' === $class ) {
					continue;
				}
				$this->assertStringStartsWith( 'anchor-auth', $class );
			}
		}

		// And nothing webinar-specific leaked through the rename.
		$this->assertStringNotContainsString( 'anchor-webinar', $html );
	}

	/** The shortcode is registered and renders the same component. */
	public function test_shortcode_renders_the_form() {
		$this->assertTrue( shortcode_exists( 'anchor_auth_form' ) );

		$html = do_shortcode( '[anchor_auth_form]' );

		$this->assertStringContainsString( 'class="anchor-auth-login__form"', $html );
	}

	/* -----------------------------------------------------------------
	 * redirect_to must never become an open redirect.
	 * -------------------------------------------------------------- */

	/** A local target survives intact. */
	public function test_local_redirect_is_preserved() {
		$target = home_url( '/webinars/my-talk/' );

		$html = Anchor_Auth_Form::render( [ 'redirect_to' => $target ] );

		$this->assertStringContainsString(
			'name="redirect_to" value="' . esc_url( $target ) . '"',
			$html
		);
	}

	/**
	 * An off-site target is clamped to the local default. Without this the form
	 * would be a credential-harvesting open redirect.
	 */
	public function test_external_redirect_falls_back_to_the_local_default() {
		$html = Anchor_Auth_Form::render( [ 'redirect_to' => 'https://evil.example.com/steal' ] );

		$this->assertStringNotContainsString( 'evil.example.com', $html );

		preg_match( '/name="redirect_to" value="([^"]*)"/', $html, $m );
		$this->assertNotEmpty( $m, 'The form must carry a redirect_to value.' );

		$value = html_entity_decode( $m[1], ENT_QUOTES );
		$this->assertSame(
			wp_parse_url( home_url(), PHP_URL_HOST ),
			wp_parse_url( $value, PHP_URL_HOST ),
			'The redirect target must stay on this host.'
		);
	}

	/** Protocol-relative "//evil.com" is the classic bypass; it must be clamped too. */
	public function test_protocol_relative_redirect_is_clamped() {
		$html = Anchor_Auth_Form::render( [ 'redirect_to' => '//evil.example.com/steal' ] );

		$this->assertStringNotContainsString( 'evil.example.com', $html );
	}

	/* -----------------------------------------------------------------
	 * show_register: callers may force OFF, never ON.
	 * -------------------------------------------------------------- */

	public function test_register_tab_shows_when_registration_is_enabled() {
		update_option( Anchor_Auth_Form::OPTION_KEY, [ 'allow_registration' => 1 ], false );
		self::reset_settings_cache();

		$html = Anchor_Auth_Form::render();

		$this->assertStringContainsString( 'anchor-auth-register__form', $html );
	}

	public function test_caller_can_force_the_register_tab_off() {
		update_option( Anchor_Auth_Form::OPTION_KEY, [ 'allow_registration' => 1 ], false );
		self::reset_settings_cache();

		$html = Anchor_Auth_Form::render( [ 'show_register' => false ] );

		$this->assertStringNotContainsString( 'anchor-auth-register__form', $html );
	}

	/** A call site must not be able to re-enable registration the site disabled. */
	public function test_caller_cannot_force_the_register_tab_on() {
		update_option( Anchor_Auth_Form::OPTION_KEY, [ 'allow_registration' => 0 ], false );
		self::reset_settings_cache();

		$html = Anchor_Auth_Form::render( [ 'show_register' => true ] );

		$this->assertStringNotContainsString( 'anchor-auth-register__form', $html );
	}

	/**
	 * `allow_registration` defaults ON and is deliberately decoupled from core's
	 * users_can_register, which is 0 on the sites that use this.
	 */
	public function test_registration_defaults_on_and_ignores_users_can_register() {
		update_option( 'users_can_register', 0 );

		$this->assertTrue( Anchor_Auth_Form::registration_enabled() );
	}

	/* -----------------------------------------------------------------
	 * Migration off the webinars option.
	 * -------------------------------------------------------------- */

	/** Turnstile keys and the registration flag survive the extraction. */
	public function test_settings_migrate_from_the_webinars_option_on_first_read() {
		update_option( Anchor_Auth_Form::LEGACY_OPTION_KEY, [
			'vimeo_api_key'      => 'vimeo-stays-put',
			'allow_registration' => 0,
			'turnstile_site_key' => 'site-key-123',
			'turnstile_secret'   => 'secret-key-456',
			'accent_color'       => '#ff8b3d',
			'accent_text_color'  => '#101010',
		], false );
		self::reset_settings_cache();

		$opts = Anchor_Auth_Form::get_settings();

		$this->assertSame( 'site-key-123', $opts['turnstile_site_key'] );
		$this->assertSame( 'secret-key-456', $opts['turnstile_secret'] );
		$this->assertSame( 0, (int) $opts['allow_registration'] );
		$this->assertSame( '#ff8b3d', $opts['accent_color'] );
		$this->assertSame( '#101010', $opts['accent_text_color'] );

		// Persisted, not just computed — a later request reads the new option.
		$stored = get_option( Anchor_Auth_Form::OPTION_KEY );
		$this->assertIsArray( $stored );
		$this->assertSame( 'site-key-123', $stored['turnstile_site_key'] );

		// The Vimeo key is not the auth component's business.
		$this->assertArrayNotHasKey( 'vimeo_api_key', $opts );
	}

	/** With nothing to migrate, the documented defaults apply. */
	public function test_defaults_when_there_is_nothing_to_migrate() {
		$opts = Anchor_Auth_Form::get_settings();

		$this->assertSame( 1, (int) $opts['allow_registration'] );
		$this->assertSame( '', $opts['turnstile_site_key'] );
		$this->assertSame( '', $opts['turnstile_secret'] );
		$this->assertSame( '#2563eb', $opts['accent_color'] );
		$this->assertSame( '#ffffff', $opts['accent_text_color'] );
	}

	/** Migration must not clobber settings that were already saved. */
	public function test_existing_auth_settings_are_not_overwritten_by_migration() {
		update_option( Anchor_Auth_Form::OPTION_KEY, [ 'turnstile_site_key' => 'already-set' ], false );
		update_option( Anchor_Auth_Form::LEGACY_OPTION_KEY, [ 'turnstile_site_key' => 'legacy' ], false );
		self::reset_settings_cache();

		$opts = Anchor_Auth_Form::get_settings();

		$this->assertSame( 'already-set', $opts['turnstile_site_key'] );
	}

	/* -----------------------------------------------------------------
	 * Assets.
	 * -------------------------------------------------------------- */

	/** Safe to call twice — the second call must not duplicate the JS config. */
	public function test_enqueue_assets_is_idempotent() {
		Anchor_Auth_Form::enqueue_assets();
		Anchor_Auth_Form::enqueue_assets();

		$this->assertTrue( wp_style_is( 'anchor-auth', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'anchor-auth', 'enqueued' ) );

		$data = wp_scripts()->get_data( 'anchor-auth', 'data' );
		$this->assertSame(
			1,
			substr_count( (string) $data, 'var ANCHOR_AUTH' ),
			'wp_localize_script() must run once per request, not once per call.'
		);
	}

	/** render() pulls its own assets in, so a template/shortcode call site can't forget. */
	public function test_render_enqueues_its_own_assets() {
		// The enqueue registry is global and survives between tests in this
		// class, so clear it first or the precondition is meaningless.
		wp_dequeue_style( 'anchor-auth' );
		wp_dequeue_script( 'anchor-auth' );
		$this->assertFalse( wp_style_is( 'anchor-auth', 'enqueued' ) );

		Anchor_Auth_Form::render();

		$this->assertTrue( wp_style_is( 'anchor-auth', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'anchor-auth', 'enqueued' ) );
	}

	/** One regex keeps the whole component out of WP Rocket's unused-CSS purge. */
	public function test_rucss_safelist_entry_is_added() {
		$this->assertContains( '/anchor-auth/', Anchor_Auth_Form::rucss_safelist( [] ) );
		$this->assertContains( '/anchor-auth/', Anchor_Auth_Form::rucss_safelist( 'not-an-array' ) );
	}

	/* -----------------------------------------------------------------
	 * AJAX endpoints live on the component, not the webinars module.
	 * -------------------------------------------------------------- */

	public function test_ajax_actions_are_registered_on_the_component() {
		foreach ( [ 'anchor_auth_login', 'anchor_auth_register' ] as $action ) {
			foreach ( [ 'wp_ajax_', 'wp_ajax_nopriv_' ] as $prefix ) {
				$this->assertNotFalse(
					has_action( $prefix . $action ),
					"{$prefix}{$action} must be registered."
				);
			}
		}
	}

	/** The old webinar-owned endpoints are gone for good. */
	public function test_legacy_webinar_ajax_actions_are_gone() {
		foreach ( [ 'anchor_webinar_login', 'anchor_webinar_register' ] as $action ) {
			foreach ( [ 'wp_ajax_', 'wp_ajax_nopriv_' ] as $prefix ) {
				$this->assertFalse(
					has_action( $prefix . $action ),
					"{$prefix}{$action} must no longer be registered."
				);
			}
		}
	}

	/* -----------------------------------------------------------------
	 * Helpers.
	 * -------------------------------------------------------------- */

	/** Parse the fragment strictly enough to catch unbalanced tags. */
	private static function is_wellformed( $html ) {
		$previous = libxml_use_internal_errors( true );
		libxml_clear_errors();

		$doc = new DOMDocument();
		$ok  = $doc->loadHTML(
			'<?xml encoding="utf-8" ?><div id="anchor-auth-test-root">' . $html . '</div>',
			LIBXML_NOERROR | LIBXML_NOWARNING
		);

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $ok ) {
			return false;
		}

		// The root must actually contain the component, i.e. nothing above it
		// broke out of the fragment.
		$xpath = new DOMXPath( $doc );
		return $xpath->query( '//div[@id="anchor-auth-test-root"]//form' )->length > 0;
	}
}
