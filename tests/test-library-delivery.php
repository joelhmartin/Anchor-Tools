<?php
/**
 * Library file delivery: Content-Disposition, the response header plan for streamed files, and zip entry names.
 *
 * @package Anchor\Library\Tests
 * @group library
 */

use Anchor\Library\Ajax\Folders;
use Anchor\Library\Http;
use Anchor\Library\Storage;

class Anchor_Library_Delivery_Test extends Anchor_Library_TestCase {

	// ---- Content-Disposition (one helper for REST, AJAX and the zip) -----------------------------------------

	public function test_content_disposition_ascii_name_has_fallback_and_extended_form() {
		$this->assertSame(
			"Content-Disposition: attachment; filename=\"my-doc.txt\"; filename*=UTF-8''my%20doc.txt",
			Http::content_disposition( 'attachment', 'my doc.txt' )
		);
	}

	public function test_content_disposition_non_ascii_name_is_percent_encoded_with_ascii_fallback() {
		$line = Http::content_disposition( 'inline', "R\u{e9}sum\u{e9} \"final\".pdf" );
		$this->assertStringStartsWith( 'Content-Disposition: inline; filename="', $line );
		$this->assertMatchesRegularExpression( '/^[\x20-\x7e]+$/', $line, 'the whole header line is printable ASCII' );
		$this->assertSame( 1, substr_count( $line, '"' ) - 1, 'only the two delimiting quotes' );
		$this->assertStringContainsString( "filename*=UTF-8''R%C3%A9sum%C3%A9%20%22final%22.pdf", $line );
	}

	public function test_content_disposition_cannot_be_split_by_control_characters_and_never_empty() {
		$line = Http::content_disposition( 'attachment', "a\r\nSet-Cookie: x=1.txt" );
		$this->assertStringNotContainsString( "\r", $line );
		$this->assertStringNotContainsString( "\n", $line );
		$this->assertStringContainsString( 'filename="', Http::content_disposition( 'attachment', '' ) );
		$this->assertStringContainsString( 'attachment', Http::content_disposition( 'bogus', 'a.txt' ), 'anything but inline is an attachment' );
	}

	// ---- Response header plan --------------------------------------------------------------------------------

	private function plan_headers( string $mime, string $disp, bool $strict, string $range = '' ): array {
		return Storage::response_headers( $mime, $disp, 'f.bin', Storage::plan( $range, 100 ), $strict );
	}

	public function test_strict_headers_are_private_no_store_no_referrer_nosniff() {
		$h = $this->plan_headers( 'application/pdf', 'attachment', true );
		$this->assertContains( 'Cache-Control: private, no-store', $h );
		$this->assertContains( 'Referrer-Policy: no-referrer', $h );
		$this->assertContains( 'X-Content-Type-Options: nosniff', $h );
		$this->assertContains( 'Accept-Ranges: bytes', $h );
		$this->assertContains( 'Content-Length: 100', $h );
		$this->assertNotContains( 'Content-Security-Policy: sandbox; default-src \'none\'; img-src data:; media-src \'self\'; style-src \'unsafe-inline\'', $h, 'attachments need no CSP' );
	}

	public function test_strict_inline_safe_types_stay_inline_with_a_sandbox_csp() {
		foreach ( [ 'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'video/mp4', 'audio/mpeg', 'text/plain', 'text/csv', 'IMAGE/PNG', 'text/plain; charset=utf-8' ] as $mime ) {
			$h = $this->plan_headers( $mime, 'inline', true );
			$this->assertTrue( 0 === strpos( implode( "\n", $h ), 'Content-Type: ' ) || in_array( 'Content-Type: ' . $mime, $h, true ), $mime );
			$this->assertNotEmpty( preg_grep( '/^Content-Disposition: inline;/', $h ), $mime . ' stays inline' );
			$this->assertContains( "Content-Security-Policy: sandbox; default-src 'none'; img-src data:; media-src 'self'; style-src 'unsafe-inline'", $h, $mime );
		}
	}

	public function test_inline_pdf_stays_inline_without_a_csp_but_keeps_the_other_hardening() {
		// A sandbox CSP breaks Chrome's built-in PDF viewer; PDFs are not scriptable from this origin (nosniff, own type).
		foreach ( [ 'application/pdf', 'Application/PDF; x=y' ] as $mime ) {
			$h = $this->plan_headers( $mime, 'inline', true );
			$this->assertNotEmpty( preg_grep( '/^Content-Disposition: inline;/', $h ), 'pdf stays inline' );
			$this->assertEmpty( preg_grep( '/^Content-Security-Policy:/', $h ), 'no CSP on inline pdf' );
			$this->assertContains( 'X-Content-Type-Options: nosniff', $h );
			$this->assertContains( 'Cache-Control: private, no-store', $h );
			$this->assertContains( 'Referrer-Policy: no-referrer', $h );
		}
	}

	public function test_strict_forces_attachment_for_active_or_unknown_types() {
		foreach ( [ 'image/svg+xml', 'text/html', 'application/xhtml+xml', 'text/xml', 'application/xml', 'application/octet-stream', 'image/bmp', 'application/javascript', '' ] as $mime ) {
			$h = $this->plan_headers( $mime, 'inline', true );
			$this->assertNotEmpty( preg_grep( '/^Content-Disposition: attachment;/', $h ), "'" . $mime . "' is forced to attachment" );
			$this->assertEmpty( preg_grep( '/^Content-Security-Policy:/', $h ), 'no CSP needed on an attachment' );
		}
	}

	public function test_lenient_mode_keeps_the_portal_behaviour() {
		$h = $this->plan_headers( 'image/bmp', 'inline', false );
		$this->assertNotEmpty( preg_grep( '/^Content-Disposition: inline;/', $h ), 'portal viewer keeps inline for bmp' );
		$this->assertEmpty( preg_grep( '/^Content-Security-Policy:/', $h ) );
		$this->assertNotContains( 'Referrer-Policy: no-referrer', $h );
	}

	public function test_header_plan_carries_the_range_headers() {
		$h = $this->plan_headers( 'video/mp4', 'inline', true, 'bytes=0-9' );
		$this->assertContains( 'Content-Range: bytes 0-9/100', $h );
		$this->assertContains( 'Content-Length: 10', $h );
		$bad = $this->plan_headers( 'video/mp4', 'inline', true, 'bytes=500-' );
		$this->assertContains( 'Content-Range: bytes */100', $bad );
	}

	// ---- Zip entry names -------------------------------------------------------------------------------------

	public function test_zip_entry_names_are_sanitized_and_cannot_traverse() {
		$used = [];
		$this->assertSame( 'Reports/Q1/my-file.pdf', Folders::zip_entry_path( [ 'Reports', 'Q1' ], 'my file.pdf', $used ) );
		$evil = Folders::zip_entry_path( [ '..', 'a/../b' ], '../../etc/passwd', $used );
		$this->assertStringNotContainsString( '..', $evil );
		$this->assertStringStartsNotWith( '/', $evil );
		$this->assertSame( 0, preg_match( '#(^|/)\.\.?(/|$)#', $evil ) );
		$this->assertStringNotContainsString( "\0", Folders::zip_entry_path( [ 'x' ], "a\0b.txt", $used ) );
	}

	public function test_zip_entry_names_that_collide_get_a_suffix() {
		$used = [];
		$a    = Folders::zip_entry_path( [ 'D' ], 'a b.txt', $used );
		$b    = Folders::zip_entry_path( [ 'D' ], 'a-b.txt', $used );
		$this->assertSame( 'D/a-b.txt', $a );
		$this->assertSame( 'D/a-b-2.txt', $b );
	}
}
