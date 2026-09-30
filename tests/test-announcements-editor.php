<?php
use Anchor\Announcements\Admin\Editor;
use Anchor\Announcements\Content\AnnouncementPostType as PT;
use Anchor\Announcements\Module;
use Anchor\Announcements\Support\Settings;

class Test_Announcements_Editor extends Anchor_Announcements_TestCase {

	private function post_save( int $id, array $fields, string $action = 'save', string $schedule = '' ): void {
		$_POST = [
			'aa_editor_nonce'      => wp_create_nonce( 'aa_editor' ),
			'anchor_announcement'  => $fields,
			'aa_action'            => $action,
			'aa_schedule_at'       => $schedule,
		];
		( new Editor() )->save( $id );
		$_POST = [];
	}

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		Settings::save( [ 'footer_address' => '1 Main St' ] );
		add_filter( 'pre_wp_mail', '__return_true' );
	}

	public function test_save_sanitizes_body_and_audience() {
		$id = $this->make_announcement();
		$this->post_save( $id, [
			'subject'   => 'Hi <b>{first_name}</b>',
			'preheader' => 'P',
			'body'      => '<p onclick="x()">Hi</p><script>bad()</script>',
			'audience'  => wp_json_encode( [ 'groups' => [ [ 'conditions' => [ [ 'type' => 'nope' ], [ 'type' => 'user_role', 'params' => [ 'roles' => [ 'subscriber' ] ] ] ] ] ] ] ),
		] );
		$this->assertSame( 'Hi {first_name}', get_post_meta( $id, PT::META_SUBJECT, true ) );
		$this->assertSame( '<p>Hi</p>bad()', get_post_meta( $id, PT::META_BODY, true ) );
		$saved = json_decode( get_post_meta( $id, PT::META_AUDIENCE, true ), true );
		$this->assertSame( 'user_role', $saved['groups'][0]['conditions'][0]['type'] );
		$this->assertCount( 1, $saved['groups'][0]['conditions'] );
	}

	public function test_empty_params_round_trip_as_object() {
		$id = $this->make_announcement();
		$this->post_save( $id, [ 'subject' => 'S', 'preheader' => '', 'body' => '<p>B</p>', 'audience' => wp_json_encode( [ 'groups' => [ [ 'conditions' => [ [ 'type' => 'user_role', 'params' => [] ] ] ] ] ] ) ] );
		$this->assertStringContainsString( '"params":{}', (string) get_post_meta( $id, PT::META_AUDIENCE, true ) );
	}

	public function test_user_without_capability_cannot_save() {
		$id = $this->make_announcement();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->post_save( $id, [ 'subject' => 'Changed', 'body' => '<p>x</p>', 'preheader' => '', 'audience' => '{}' ] );
		$this->assertSame( 'Hello {first_name}', get_post_meta( $id, PT::META_SUBJECT, true ) );
	}

	public function test_send_now_starts_the_queue_and_locks_content() {
		$this->make_user( 'r@x.com' );
		$id  = $this->make_announcement();
		$aud = wp_json_encode( [ 'groups' => [ [ 'conditions' => [ [ 'type' => 'specific_people', 'negate' => false, 'params' => [ 'emails' => 'r@x.com' ] ] ] ] ] ] );
		$this->post_save( $id, [ 'subject' => 'S', 'preheader' => '', 'body' => '<p>B</p>', 'audience' => $aud ], 'send_now' );
		$this->assertSame( PT::STATE_SENDING, PT::state( $id ) );
		$this->post_save( $id, [ 'subject' => 'Too late', 'preheader' => '', 'body' => '<p>B</p>', 'audience' => $aud ] );
		$this->assertSame( 'S', get_post_meta( $id, PT::META_SUBJECT, true ) );
		$this->assertFalse( Editor::editable( $id ) );
	}

	public function test_schedule_parses_site_time() {
		update_option( 'timezone_string', 'America/New_York' );
		$id = $this->make_announcement();
		$this->post_save( $id, [ 'subject' => 'S', 'preheader' => '', 'body' => '<p>B</p>', 'audience' => '{}' ], 'schedule', '2099-01-01T09:00' );
		$this->assertSame( PT::STATE_SCHEDULED, PT::state( $id ) );
		$this->assertSame( strtotime( '2099-01-01 14:00:00 UTC' ), (int) get_post_meta( $id, PT::META_SCHEDULED, true ) );
		update_option( 'timezone_string', '' );
	}

	public function test_errors_become_a_notice() {
		$id = $this->make_announcement();
		$this->post_save( $id, [ 'subject' => 'S', 'preheader' => '', 'body' => '<p>B</p>', 'audience' => '{}' ], 'send_now' );
		$notice = get_transient( 'aa_notice_' . get_current_user_id() );
		$this->assertSame( 'error', $notice['type'] );
		$this->assertSame( PT::STATE_DRAFT, PT::state( $id ) );
	}

	public function test_describe_reads_rules_in_plain_words() {
		$lines = Editor::describe( [ 'groups' => [
			[ 'conditions' => [ [ 'type' => 'user_role', 'negate' => false, 'params' => [ 'roles' => [ 'subscriber' ] ] ], [ 'type' => 'user_registered', 'negate' => true, 'params' => [ 'from' => '2026-01-01', 'to' => '' ] ] ] ],
			[ 'conditions' => [ [ 'type' => 'specific_people', 'negate' => false, 'params' => [ 'emails' => 'a@x.com' ] ] ] ],
		] ] );
		$this->assertSame( 'User role is subscriber AND NOT Account created from 2026-01-01', $lines[0] );
		$this->assertSame( 'OR Specific people is a@x.com', $lines[1] );
	}
}
