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

	/**
	 * Final review I7: the public verification page vouches for what was
	 * true at issue. A learner renaming themselves afterwards (or the course
	 * being retitled, its credits or provider changed) must not rewrite a
	 * certificate already issued.
	 */
	public function test_a_certificate_renders_the_snapshot_taken_at_issue() {
		( new CreditService() )->award( $this->user, $this->course );
		$certificate = $this->certificates->issue( $this->user, $this->course );

		wp_update_user( [ 'ID' => $this->user, 'display_name' => 'Somebody Else' ] );
		wp_update_post( [ 'ID' => $this->course, 'post_title' => 'A Different Course' ] );
		update_post_meta( $this->course, '_anchor_course_ce_provider_name', 'Other Provider' );
		update_post_meta( $this->course, '_anchor_course_ce_credits', '9' );
		update_post_meta( $this->course, '_anchor_course_instructor', 'Dr Nobody' );

		$data = $this->certificates->template_data( $this->certificates->get_by_token( $certificate->verification_token ) );

		$this->assertSame( 'Ada Lovelace', $data['learner_name'] );
		$this->assertSame( 'Laser Safety', $data['course_name'] );
		$this->assertSame( 'DEKA Academy', $data['provider_name'] );
		$this->assertSame( 'AGD-1234', $data['provider_number'] );
		$this->assertSame( 'Dr Vega', $data['instructor_name'] );
		$this->assertSame( 2.0, $data['ce_credits'] );

		$html = $this->certificates->render( $certificate );
		$this->assertStringContainsString( 'Ada Lovelace', $html );
		$this->assertStringNotContainsString( 'Somebody Else', $html );
	}

	/** The course's template slug, frozen at issue, picks certificate-{slug}.php from the theme; a missing file falls back. */
	public function test_the_certificate_template_slug_selects_a_theme_template_or_falls_back() {
		update_post_meta( $this->course, '_anchor_course_certificate_template', 'gold' );
		$certificate = $this->certificates->issue( $this->user, $this->course );
		$this->assertSame( 'gold', $certificate->metadata['template'] ?? null, 'the slug is frozen into the certificate at issue' );

		// No certificate-gold.php anywhere yet: the default renders (a typo never blanks a certificate).
		$this->assertSame( 'certificate', $this->certificates->template_name( $certificate ) );
		$this->assertStringContainsString( 'Ada Lovelace', $this->certificates->render( $certificate ) );

		$theme_dir = get_stylesheet_directory() . '/anchor-courses';
		if ( ! is_dir( $theme_dir ) ) {
			mkdir( $theme_dir, 0755, true ); // plain mkdir: see test-courses-shortcodes.php for why not wp_mkdir_p()
		}
		$file = $theme_dir . '/certificate-gold.php';
		file_put_contents( $file, '<p class="gold">GOLD <?php echo esc_html( $data[\'learner_name\'] ); ?></p>' );
		try {
			$this->assertSame( 'certificate-gold', $this->certificates->template_name( $certificate ) );
			$html = $this->certificates->render( $certificate );
			$this->assertStringContainsString( 'GOLD Ada Lovelace', $html );
		} finally {
			unlink( $file );
		}
	}

	/** A row issued before snapshots existed still renders, from live values. */
	public function test_a_pre_snapshot_certificate_falls_back_to_live_values() {
		$certificate = $this->certificates->issue( $this->user, $this->course );
		global $wpdb;
		$wpdb->update( \Anchor\Courses\Database\Migrations::table( 'certificates' ), [ 'metadata' => '{"template":"default"}' ], [ 'id' => $certificate->id ] );

		$data = $this->certificates->template_data( $this->certificates->get_by_token( $certificate->verification_token ) );

		$this->assertSame( 'Ada Lovelace', $data['learner_name'] );
		$this->assertSame( 'Laser Safety', $data['course_name'] );
	}

	/**
	 * award() returns null (no Credit row written) when a course has no
	 * credits configured, or when `anchor_courses_ce_credit_amount` filters
	 * the amount to <= 0 (CompletionService::complete() always calls award()
	 * before issue()). The certificate must vouch for what was actually
	 * awarded - nothing - not silently freeze the course's CONFIGURED
	 * ce_credits into a snapshot the public verification page then shows as
	 * real (CodeRabbit PR #29).
	 */
	public function test_issue_snapshots_zero_credits_when_none_were_awarded() {
		// This course's fixture sets ce_credits=2, but no CreditService::award()
		// call ever ran, so no Credit row exists for this user+course.
		$certificate = $this->certificates->issue( $this->user, $this->course );

		$this->assertSame( 0.0, $this->certificates->template_data( $certificate )['ce_credits'] );
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
