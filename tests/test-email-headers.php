<?php
/**
 * send_html_email() Content-Type header tests (Task 0.3).
 *
 * Lifecycle emails are full HTML but were being sent without a
 * `Content-Type: text/html` header, so clients rendered raw markup.
 * These tests assert the header is always present, exactly once, and
 * that caller-supplied headers (From / Reply-To / BCC / etc.) are never
 * clobbered.
 *
 * @package Anchor\Events\Tests
 */

/**
 * @group email
 */
class Test_Email_Headers extends Anchor_Events_TestCase {

	/** Captured `headers` arg from the most recent wp_mail() call. */
	private $captured_headers;

	public function set_up() {
		parent::set_up();
		$this->captured_headers = null;
		add_filter( 'wp_mail', [ $this, 'capture_wp_mail_args' ] );
	}

	public function tear_down() {
		remove_filter( 'wp_mail', [ $this, 'capture_wp_mail_args' ] );
		parent::tear_down();
	}

	/** wp_mail filter callback: record the headers arg, then pass through unchanged. */
	public function capture_wp_mail_args( $args ) {
		$this->captured_headers = $args['headers'];
		return $args;
	}

	/** Normalize a string|array headers value to a single string for assertions. */
	private function headers_to_string( $headers ) {
		if ( is_array( $headers ) ) {
			return implode( "\n", $headers );
		}
		return (string) $headers;
	}

	/** No caller headers: the Content-Type header must still be added. */
	public function test_default_call_includes_html_content_type() {
		$sent = $this->module()->send_html_email( 'x@example.com', 'subj', '<b>hi</b>' );

		$this->assertTrue( $sent );
		$this->assertNotNull( $this->captured_headers, 'wp_mail was not invoked.' );

		$headers = $this->headers_to_string( $this->captured_headers );
		$this->assertStringContainsString( 'Content-Type: text/html', $headers );
	}

	/** Caller-supplied headers (e.g. Bcc) must survive alongside the Content-Type header. */
	public function test_caller_headers_are_preserved_alongside_content_type() {
		$sent = $this->module()->send_html_email(
			'x@example.com',
			's',
			'<b>h</b>',
			[ 'Bcc: boss@example.com' ]
		);

		$this->assertTrue( $sent );
		$this->assertNotNull( $this->captured_headers, 'wp_mail was not invoked.' );

		$headers = $this->headers_to_string( $this->captured_headers );
		$this->assertStringContainsString( 'Content-Type: text/html', $headers );
		$this->assertStringContainsString( 'Bcc: boss@example.com', $headers );
	}

	/** A caller-supplied Content-Type header must not be duplicated. */
	public function test_caller_supplied_content_type_is_not_duplicated() {
		$sent = $this->module()->send_html_email(
			'x@example.com',
			's',
			'<b>h</b>',
			[ 'Content-Type: text/html; charset=UTF-8' ]
		);

		$this->assertTrue( $sent );
		$headers = $this->headers_to_string( $this->captured_headers );
		$this->assertSame( 1, substr_count( $headers, 'Content-Type:' ) );
	}
}
