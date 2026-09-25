<?php
/**
 * Anchor Courses - the HTML certificate page and verification route
 * (task-30-brief.md; design spec 1, progress.md).
 *
 * The public route is `/certificate/{token}/`, keyed on the verification
 * token alone (`Domain\Certificate::url()`, `CertificateService::get_by_token()`).
 * progress.md's ledger describes verification by "certificate number +
 * verification token" via a `CertificateService::verify()` method; no such
 * method exists anywhere in this codebase (grep-verified) and the concrete
 * task-30-brief.md interfaces - which this file implements - only ever
 * specify a single-segment token URL. This suite follows the code; see
 * task-30-report.md for the full discrepancy note.
 *
 * Similarly, progress.md/orchestrator notes mention a "revoked certificate"
 * state. `docs/superpowers/specs/2026-09-23-anchor-courses-design.md` and
 * `.superpowers/sdd/2026-09-23-anchor-courses/task-29-brief.md` (:315) are
 * explicit that "Credits and certificates are NOT revoked: they are a record
 * of something [completed]" - there is no `revoked` column on the
 * certificates table (`Database\Migrations::install()`) and no revoke method
 * on `CertificateService`. Implementing one is out of this task's scope (it
 * would touch Services/CertificateService.php and the schema, both owned
 * elsewhere) and contradicts the design. Not implemented; see the report.
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Frontend\CertificatePage;
use Anchor\Courses\Services\CertificateService;
use Anchor\Courses\Services\CreditService;

/** @group courses */
class Test_Courses_Certificate_Page extends Anchor_Courses_TestCase {

	private CertificateService $certificates;
	private int $user;
	private int $course;

	public function set_up() {
		parent::set_up();
		$this->certificates = new CertificateService();
		$this->user         = $this->make_learner( [ 'display_name' => 'Ada Lovelace' ] );
		$this->course       = $this->make_course(
			[ 'certificate_enabled' => 1, 'ce_credits' => '2', 'ce_provider_name' => 'DEKA Academy' ],
			'Laser Safety'
		);
	}

	public function tear_down() {
		remove_all_actions( 'anchor_courses_certificate_issued' );
		delete_option( CertificatePage::REWRITE_OPTION );
		parent::tear_down();
	}

	/* -----------------------------------------------------------------
	 * Wiring: the route the module actually bootstraps, and the method-
	 * level registration the brief specifies.
	 * --------------------------------------------------------------- */

	public function test_the_module_wires_the_route_into_the_real_bootstrap() {
		// Module::__construct() already ran for this whole test process and
		// already called new Frontend\CertificatePage(...); this is the global
		// state that leaves behind, with no manual instantiation here. The
		// query var only shows up in $wp->public_query_vars once
		// WP::parse_request() re-applies the 'query_vars' filter, so go_to()
		// (which calls it) is the real proof the filter is still hooked.
		$this->assertArrayHasKey( '^certificate/([^/]+)/?$', $GLOBALS['wp_rewrite']->extra_rules_top );

		$this->go_to( home_url( '/?' . CertificatePage::QUERY_VAR . '=abc-123' ) );
		$this->assertSame( 'abc-123', get_query_var( CertificatePage::QUERY_VAR ) );
	}

	public function test_the_rewrite_rule_is_registered() {
		( new CertificatePage( $this->certificates ) )->add_rewrite();
		$this->assertIsArray( $GLOBALS['wp_rewrite']->extra_rules_top );
		$this->assertArrayHasKey( '^certificate/([^/]+)/?$', $GLOBALS['wp_rewrite']->extra_rules_top );
	}

	public function test_the_query_var_is_registered() {
		$vars = ( new CertificatePage( $this->certificates ) )->register_query_var( [] );
		$this->assertContains( CertificatePage::QUERY_VAR, $vars );
	}

	public function test_flush_if_needed_stamps_the_version_option_once() {
		delete_option( CertificatePage::REWRITE_OPTION );

		( new CertificatePage( $this->certificates ) )->flush_if_needed();

		$this->assertSame( CertificatePage::REWRITE_VERSION, get_option( CertificatePage::REWRITE_OPTION ) );
	}

	/* -----------------------------------------------------------------
	 * header_list() - the pure header/found decision, unit-tested without
	 * a real HTTP response (the same split anchor-events-manager's
	 * room_header_list() uses).
	 * --------------------------------------------------------------- */

	public function test_header_list_for_a_real_certificate() {
		$certificate = $this->certificates->issue( $this->user, $this->course );
		$page        = new CertificatePage( $this->certificates );

		$decision = $page->header_list( $certificate );

		$this->assertTrue( $decision['found'] );
		$this->assertTrue( $decision['nocache'] );
		$this->assertSame( 'private, no-store', $decision['headers']['Cache-Control'] );
		$this->assertSame( 'noindex, nofollow', $decision['headers']['X-Robots-Tag'] );
	}

	/** An unknown token gets the SAME headers as a real one - no timing/caching tell. */
	public function test_header_list_for_an_unknown_token_matches_a_real_one() {
		$page = new CertificatePage( $this->certificates );

		$decision = $page->header_list( null );

		$this->assertFalse( $decision['found'] );
		$this->assertTrue( $decision['nocache'] );
		$this->assertSame( 'private, no-store', $decision['headers']['Cache-Control'] );
		$this->assertSame( 'noindex, nofollow', $decision['headers']['X-Robots-Tag'] );
	}

	/* -----------------------------------------------------------------
	 * maybe_render() - the thin wrapper, exercised through the real
	 * query-var route. The found/print branch calls exit() after echoing
	 * (see maybe_render()), which a PHPUnit process cannot survive, so it is
	 * exercised at the render()/template level below instead - the same
	 * split the room's own suite uses (its redirect branch is trapped
	 * before its exit; the print branch is never invoked end-to-end either).
	 * --------------------------------------------------------------- */

	public function test_an_unknown_token_dies_with_a_404_and_leaks_no_data() {
		$this->go_to( home_url( '/?' . CertificatePage::QUERY_VAR . '=not-a-real-token' ) );
		$page = new CertificatePage( $this->certificates );

		try {
			$page->maybe_render();
			$this->fail( 'Expected wp_die() for an unknown token.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 404, $e->getCode() );
			$this->assertStringNotContainsString( 'Ada Lovelace', $e->getMessage() );
			$this->assertStringNotContainsString( 'Laser Safety', $e->getMessage() );
		}

		$this->assertTrue( $GLOBALS['wp_query']->is_404() );
	}

	public function test_a_request_with_no_token_does_nothing() {
		$this->go_to( home_url( '/' ) );
		$page = new CertificatePage( $this->certificates );

		// No exception, no exit: an ordinary page is untouched.
		$page->maybe_render();
		$this->assertFalse( $GLOBALS['wp_query']->is_404() );
	}

	/* -----------------------------------------------------------------
	 * The rendered certificate: template variables, escaping, the assets
	 * it links, and what it deliberately never shows.
	 * --------------------------------------------------------------- */

	public function test_the_certificate_html_carries_every_template_variable() {
		( new CreditService() )->award( $this->user, $this->course );
		$certificate = $this->certificates->issue( $this->user, $this->course );

		$html = $this->certificates->render( $certificate );

		$this->assertStringContainsString( 'Ada Lovelace', $html );
		$this->assertStringContainsString( 'Laser Safety', $html );
		$this->assertStringContainsString( 'DEKA Academy', $html );
		$this->assertStringContainsString( $certificate->certificate_number, $html );
	}

	public function test_the_certificate_url_uses_the_verification_token() {
		$certificate = $this->certificates->issue( $this->user, $this->course );
		$this->assertStringContainsString( '/certificate/' . $certificate->verification_token . '/', $certificate->url() );
	}

	public function test_an_unknown_token_resolves_to_nothing() {
		$this->assertNull( $this->certificates->get_by_token( 'nope' ) );
	}

	/** design spec 1 / progress.md: never the learner's email or user id. */
	public function test_the_certificate_page_never_shows_email_or_user_id() {
		$certificate = $this->certificates->issue( $this->user, $this->course );
		$user        = get_userdata( $this->user );

		$html = $this->certificates->render( $certificate );
		$data = $this->certificates->template_data( $certificate );

		$this->assertStringNotContainsString( $user->user_email, $html );
		$this->assertArrayNotHasKey( 'email', $data );
		$this->assertArrayNotHasKey( 'user_id', $data );
	}

	/** The page is a standalone document; it links its own CSS, not the frontend bundle. */
	public function test_the_certificate_page_links_only_its_own_stylesheet() {
		$certificate = $this->certificates->issue( $this->user, $this->course );

		$html = $this->certificates->render( $certificate );

		$this->assertStringContainsString( 'certificate.css', $html );
		$this->assertStringNotContainsString( 'frontend.css', $html );
		$this->assertStringNotContainsString( 'frontend.js', $html );
	}

	public function test_the_certificate_page_escapes_a_hostile_course_title() {
		$nasty       = $this->make_course( [ 'certificate_enabled' => 1 ], '<script>alert(1)</script>' );
		$certificate = $this->certificates->issue( $this->user, $nasty );

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $this->certificates->render( $certificate ) );
	}

	/* -----------------------------------------------------------------
	 * The learner-facing shortcodes: [anchor_my_credits], [anchor_my_certificates].
	 * --------------------------------------------------------------- */

	public function test_my_certificates_lists_the_learners_certificates() {
		$certificate = $this->certificates->issue( $this->user, $this->course );
		wp_set_current_user( $this->user );

		$html = do_shortcode( '[anchor_my_certificates]' );

		$this->assertStringContainsString( $certificate->certificate_number, $html );
		$this->assertStringContainsString( 'Laser Safety', $html );
		$this->assertStringContainsString( esc_url( $certificate->url() ), $html );
	}

	public function test_my_certificates_never_shows_another_learners_records() {
		$this->certificates->issue( $this->user, $this->course );
		wp_set_current_user( $this->make_learner() );

		$html = do_shortcode( '[anchor_my_certificates]' );

		$this->assertStringNotContainsString( 'Laser Safety', $html );
	}

	public function test_my_certificates_shows_a_notice_with_none_issued() {
		wp_set_current_user( $this->make_learner() );
		$this->assertStringContainsString( 'No certificates yet', do_shortcode( '[anchor_my_certificates]' ) );
	}

	public function test_my_credits_lists_totals_for_the_signed_in_learner() {
		( new CreditService() )->award( $this->user, $this->course );
		wp_set_current_user( $this->user );

		$html = do_shortcode( '[anchor_my_credits]' );

		$this->assertStringContainsString( 'Laser Safety', $html );
		$this->assertStringContainsString( '2', $html );
	}

	public function test_my_credits_never_shows_another_learners_records() {
		( new CreditService() )->award( $this->user, $this->course );
		wp_set_current_user( $this->make_learner() );

		$html = do_shortcode( '[anchor_my_credits]' );

		$this->assertStringNotContainsString( 'Laser Safety', $html );
	}

	public function test_my_credits_shows_a_notice_with_none_awarded() {
		wp_set_current_user( $this->make_learner() );
		$this->assertStringContainsString( 'No CE credits yet', do_shortcode( '[anchor_my_credits]' ) );
	}

	public function test_both_learner_shortcodes_require_login() {
		wp_set_current_user( 0 );
		$this->assertStringContainsString( 'sign in', strtolower( do_shortcode( '[anchor_my_credits]' ) ) );
		$this->assertStringContainsString( 'sign in', strtolower( do_shortcode( '[anchor_my_certificates]' ) ) );
	}
}
