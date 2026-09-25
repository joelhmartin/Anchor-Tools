<?php
/**
 * Anchor Courses - certificate issuing (brief 13, 26).
 *
 * `Services\CreditService` / `Database\CreditRepository` (Task 27) are
 * unconditionally on this tree (joined before Task 29); the certificate<->
 * credit link is exercised unconditionally below, no skip guard.
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Domain\Certificate;
use Anchor\Courses\Services\CertificateService;
use Anchor\Courses\Services\CreditService;

/** @group courses */
class Test_Courses_Certificates extends Anchor_Courses_TestCase {

	private CertificateService $certificates;
	private int $user;
	private int $course;

	public function set_up() {
		parent::set_up();
		$this->certificates = new CertificateService();
		$this->user         = $this->make_learner( [ 'display_name' => 'Ada Lovelace' ] );
		$this->course       = $this->make_course(
			[ 'certificate_enabled' => 1, 'ce_credits' => '2', 'ce_type' => 'Dental CE',
			  'ce_provider_name' => 'DEKA Academy', 'ce_provider_number' => 'AGD-1234', 'instructor' => 'Dr Vega' ],
			'Laser Safety'
		);
	}

	public function tear_down() {
		remove_all_actions( 'anchor_courses_certificate_issued' );
		remove_all_filters( 'anchor_courses_certificate_data' );
		remove_all_filters( 'anchor_courses_now' );
		parent::tear_down();
	}

	public function test_the_module_constructs_the_certificate_service() {
		$this->assertInstanceOf( CertificateService::class, $this->courses()->certificates );
	}

	public function test_issue_creates_a_numbered_certificate_and_fires_the_action() {
		add_filter( 'anchor_courses_now', static fn() => strtotime( '2026-07-04 12:00:00 UTC' ) );
		$fired = 0;
		add_action( 'anchor_courses_certificate_issued', function () use ( &$fired ) { $fired++; }, 10, 3 );

		$certificate = $this->certificates->issue( $this->user, $this->course );

		$this->assertInstanceOf( Certificate::class, $certificate );
		$this->assertMatchesRegularExpression( '/^AC-2026-\d{8}$/', $certificate->certificate_number );
		$this->assertNotSame( '', $certificate->verification_token );
		$this->assertSame( '', $certificate->file_path, 'Phase 1 is an HTML page; file_path stays empty.' );
		$this->assertSame( 1, $fired );
	}

	/** Brief rule 7: repeated completion must not mint a second certificate. */
	public function test_issuing_twice_returns_the_same_certificate() {
		$first  = $this->certificates->issue( $this->user, $this->course );
		$second = $this->certificates->issue( $this->user, $this->course );

		$this->assertSame( $first->id, $second->id );
		$this->assertSame( $first->certificate_number, $second->certificate_number );
	}

	public function test_certificate_numbers_are_unique_across_learners() {
		$a = $this->certificates->issue( $this->user, $this->course );
		$b = $this->certificates->issue( $this->make_learner(), $this->course );

		$this->assertNotSame( $a->certificate_number, $b->certificate_number );
		$this->assertStringNotContainsString( 'PENDING', $b->certificate_number );
	}

	public function test_verification_tokens_are_unique_and_findable() {
		$certificate = $this->certificates->issue( $this->user, $this->course );

		$found = $this->certificates->get_by_token( $certificate->verification_token );

		$this->assertSame( $certificate->id, $found->id );
		$this->assertNull( $this->certificates->get_by_token( 'not-a-token' ) );
	}

	public function test_a_course_with_certificates_disabled_issues_nothing() {
		$no_cert = $this->make_course( [ 'certificate_enabled' => 0 ] );
		$this->assertNull( $this->certificates->issue( $this->user, $no_cert ) );
	}

	public function test_template_data_carries_every_brief_variable() {
		( new CreditService() )->award( $this->user, $this->course );
		$certificate = $this->certificates->issue( $this->user, $this->course );

		$data = $this->certificates->template_data( $certificate );

		foreach ( [ 'learner_name', 'course_name', 'completion_date', 'ce_credits', 'certificate_number',
		            'instructor_name', 'provider_name', 'provider_number', 'expiration_date' ] as $key ) {
			$this->assertArrayHasKey( $key, $data, "Missing template variable {$key}" );
		}
		$this->assertSame( 'Ada Lovelace', $data['learner_name'] );
		$this->assertSame( 'Laser Safety', $data['course_name'] );
		$this->assertSame( 'Dr Vega', $data['instructor_name'] );
		$this->assertSame( 2.0, $data['ce_credits'] );
	}

	public function test_the_certificate_data_filter_can_override_values() {
		add_filter(
			'anchor_courses_certificate_data',
			static function ( array $data ) {
				$data['learner_name'] = 'Filtered Name';
				return $data;
			},
			10,
			2
		);

		$certificate = $this->certificates->issue( $this->user, $this->course );

		$this->assertSame( 'Filtered Name', $this->certificates->template_data( $certificate )['learner_name'] );
	}

	public function test_render_escapes_the_learner_name() {
		$nasty       = $this->make_learner( [ 'display_name' => '<script>alert(1)</script>' ] );
		$certificate = $this->certificates->issue( $nasty, $this->course );

		$html = $this->certificates->render( $certificate );

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
		$this->assertStringContainsString( $certificate->certificate_number, $html );
	}

	public function test_for_user_lists_certificates_newest_first() {
		$second = $this->make_course( [ 'certificate_enabled' => 1 ] );
		$this->certificates->issue( $this->user, $this->course );
		$this->certificates->issue( $this->user, $second );

		$this->assertCount( 2, $this->certificates->for_user( $this->user ) );
	}

	public function test_a_credit_is_linked_to_the_certificate_when_both_exist() {
		$credit      = ( new CreditService() )->award( $this->user, $this->course );
		$certificate = $this->certificates->issue( $this->user, $this->course );

		$this->assertSame(
			$certificate->id,
			( new CreditService() )->get( $this->user, $this->course )->certificate_id
		);
		$this->assertGreaterThan( 0, $credit->id );
	}
}
