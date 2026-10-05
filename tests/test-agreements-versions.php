<?php
// tests/test-agreements-versions.php
use Anchor\Agreements\Content\AgreementPostType;
use Anchor\Agreements\Database\VersionRepository;
use Anchor\Agreements\Support\Settings;

class Test_Agreements_Versions extends WP_UnitTestCase {
	private function agreement( string $content = '<p>No refunds within 30 days.</p>' ): int {
		return self::factory()->post->create( [ 'post_type' => AgreementPostType::CPT, 'post_title' => 'Cancellation Policy', 'post_content' => $content, 'post_status' => 'publish' ] );
	}

	public function test_same_content_reuses_version() {
		$repo = new VersionRepository();
		$id   = $this->agreement();
		$a    = $repo->current_for( $id );
		$b    = $repo->current_for( $id );
		$this->assertSame( $a['id'], $b['id'] );
		$this->assertSame( 'Cancellation Policy', $a['title'] );
	}

	public function test_edit_creates_new_version_and_keeps_old() {
		$repo = new VersionRepository();
		$id   = $this->agreement();
		$v1   = $repo->current_for( $id );
		wp_update_post( [ 'ID' => $id, 'post_content' => '<p>Changed.</p>' ] );
		$v2 = $repo->current_for( $id );
		$this->assertNotSame( $v1['id'], $v2['id'] );
		$this->assertStringContainsString( 'No refunds', $repo->get( $v1['id'] )['content'] );
	}

	public function test_unusable_agreement_returns_null() {
		$repo  = new VersionRepository();
		$draft = self::factory()->post->create( [ 'post_type' => AgreementPostType::CPT, 'post_status' => 'draft' ] );
		$this->assertNull( $repo->current_for( $draft ) );
		$this->assertNull( $repo->current_for( 999999 ) );
	}

	public function test_settings_defaults() {
		delete_option( Settings::OPTION );
		$this->assertSame( 0, Settings::default_agreement_id() );
		$this->assertSame( [ get_option( 'admin_email' ) ], Settings::notify_recipients() );
		$this->assertSame( 'I have read and signed the Cancellation Policy', Settings::label_for( 'Cancellation Policy' ) );
		$this->assertTrue( Settings::notify_enabled() );
		$this->assertTrue( Settings::customer_email_links() );
		$this->assertSame( DAY_IN_SECONDS, Settings::reuse_seconds() );
		$this->assertSame( 30, Settings::purge_days() );
		$this->assertSame( 'Signed: Cancellation Policy – Pari Example, order #12', Settings::notify_subject( 'Cancellation Policy', 'Pari Example', 12 ) );
	}

	public function test_settings_sanitize_clamps_and_cleans() {
		$out = Settings::sanitize( [ 'notify' => 'a@x.com, not-an-email ,b@y.com', 'reuse_hours' => '9999', 'purge_days' => '0', 'notify_enabled' => '', 'label' => '<b>Sign</b> {title}' ] );
		$this->assertSame( 'a@x.com, b@y.com', $out['notify'] );
		$this->assertSame( 168, $out['reuse_hours'] );
		$this->assertSame( 1, $out['purge_days'] );
		$this->assertFalse( $out['notify_enabled'] );
		$this->assertSame( 'Sign {title}', $out['label'] );
		update_option( Settings::OPTION, [ 'notify' => 'a@x.com, b@y.com' ] );
		$this->assertSame( [ 'a@x.com', 'b@y.com' ], Settings::notify_recipients() );
	}
}
