<?php
/**
 * @group ajax
 */
use Anchor\Announcements\Suppression\Suppressions;
use Anchor\Announcements\Support\Settings;

class Test_Announcements_Ajax extends WP_Ajax_UnitTestCase {

	public function set_up() {
		parent::set_up();
		// The module only constructs Ajax when is_admin() is true (false under CLI), so register it here.
		new \Anchor\Announcements\Admin\Ajax();
		Settings::save( [ 'footer_address' => '1 Main St' ] );
		add_filter( 'pre_wp_mail', '__return_true' );
	}

	private function call( string $action, array $post ): array {
		$_POST = array_merge( [ 'nonce' => wp_create_nonce( 'anchor_announcements' ) ], $post );
		try {
			$this->_handleAjax( $action );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		} catch ( WPAjaxDieStopException $e ) {
			unset( $e );
		}
		return (array) json_decode( $this->_last_response, true );
	}

	private function as_admin(): void {
		$this->_setRole( 'administrator' );
	}

	public function test_editor_is_refused() {
		$this->_setRole( 'editor' );
		$res = $this->call( 'anchor_announcements_audience', [ 'rules' => '{}' ] );
		$this->assertFalse( $res['success'] );
	}

	public function test_audience_counts_after_suppression() {
		$this->as_admin();
		Suppressions::add( 'b@x.com', 'unsubscribed' );
		$rules = wp_json_encode( [ 'groups' => [ [ 'conditions' => [ [ 'type' => 'specific_people', 'negate' => false, 'params' => [ 'emails' => 'a@x.com,b@x.com' ] ] ] ] ] ] );
		$res   = $this->call( 'anchor_announcements_audience', [ 'rules' => $rules ] );
		$this->assertTrue( $res['success'] );
		$this->assertSame( 1, $res['data']['count'] );
		$this->assertSame( 1, $res['data']['suppressed'] );
		$this->assertSame( 'a@x.com', $res['data']['sample'][0]['email'] );
	}

	public function test_preview_renders_unsaved_content() {
		$this->as_admin();
		$id  = self::factory()->post->create( [ 'post_type' => 'anchor_announcement' ] );
		$res = $this->call( 'anchor_announcements_preview', [ 'post_id' => $id, 'subject' => 'S', 'preheader' => '', 'body' => '<p>Live {site_name}</p>' ] );
		$this->assertTrue( $res['success'] );
		$this->assertStringContainsString( 'Live ' . esc_html( get_bloginfo( 'name' ) ), $res['data']['html'] );
	}

	public function test_search_users() {
		$this->as_admin();
		self::factory()->user->create( [ 'user_email' => 'findme@x.com', 'display_name' => 'Find Me' ] );
		$res = $this->call( 'anchor_announcements_search', [ 'kind' => 'users', 'q' => 'findme' ] );
		$this->assertSame( 'Find Me (findme@x.com)', $res['data'][0]['text'] );
	}

	public function test_test_send() {
		$this->as_admin();
		$id  = self::factory()->post->create( [ 'post_type' => 'anchor_announcement' ] );
		$res = $this->call( 'anchor_announcements_test_send', [ 'post_id' => $id, 'email' => 'me@x.com', 'subject' => 'S', 'preheader' => '', 'body' => '<p>B</p>' ] );
		$this->assertTrue( $res['success'] );
	}
}
