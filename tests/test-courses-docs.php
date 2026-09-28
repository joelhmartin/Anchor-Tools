<?php
/**
 * Anchor Courses - the documentation is part of the build (brief 16 "Document
 * every public hook", brief 36 "public APIs are documented").
 *
 * This test greps the source for public extension points and fails when one is
 * missing from COURSES.md, so the docs cannot silently rot.
 *
 * @package Anchor\Courses\Tests
 */

use Anchor\Courses\Rest\Routes;

/** @group courses */
class Test_Courses_Docs extends Anchor_Courses_TestCase {

	private string $docs;

	public function set_up() {
		parent::set_up();
		$this->docs = (string) file_get_contents( ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-courses/COURSES.md' );
	}

	/**
	 * Every hook name passed to do_action()/apply_filters() in src/ and
	 * api.php - including a hook name chosen by a ternary at the call site
	 * (`do_action( $passed ? 'anchor_courses_quiz_passed' : 'anchor_courses_quiz_failed', ... )`
	 * in QuizService::submit_tracked()), which a naive "quote directly after
	 * the opening paren" regex would miss.
	 *
	 * @return string[]
	 */
	private function hooks_in_source(): array {
		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-courses' )
		);

		$found = [];
		foreach ( $files as $file ) {
			if ( 'php' !== strtolower( $file->getExtension() ) ) {
				continue;
			}
			$source = (string) file_get_contents( $file->getPathname() );

			// The common case: do_action( 'name', ... ) / apply_filters( 'name', ... ).
			if ( preg_match_all( "/(?:do_action|apply_filters)\(\s*'(anchor_courses_[a-z0-9_]+)'/", $source, $matches ) ) {
				$found = array_merge( $found, $matches[1] );
			}

			// The ternary case: do_action( $cond ? 'a' : 'b', ... ). Both
			// branch names are extension points and must both be documented.
			if ( preg_match_all( "/'(anchor_courses_[a-z0-9_]+)'\s*:\s*'(anchor_courses_[a-z0-9_]+)'/", $source, $matches ) ) {
				$found = array_merge( $found, $matches[1], $matches[2] );
			}
		}

		return array_values( array_unique( $found ) );
	}

	public function test_every_hook_fired_in_the_module_is_documented() {
		$undocumented = [];
		foreach ( $this->hooks_in_source() as $hook ) {
			if ( ! str_contains( $this->docs, $hook ) ) {
				$undocumented[] = $hook;
			}
		}

		$this->assertSame( [], $undocumented, 'Undocumented hooks: ' . implode( ', ', $undocumented ) );
	}

	/**
	 * The inverse of the test above: every hook name COURSES.md's "## Hooks"
	 * section (Actions + Filters tables only - NOT the whole document, which
	 * also names things that merely share the `anchor_courses_` prefix, like
	 * API functions and table names) claims to document must actually be
	 * fired somewhere in the source, so a renamed or removed hook can't leave
	 * a stale row behind. `anchor_events_can_access_stream` is out of scope
	 * here on purpose - it belongs to the events module; courses only
	 * listens to it, never fires it.
	 */
	public function test_every_documented_hook_is_actually_fired() {
		if ( ! preg_match( '/^## Hooks$(.*?)^## /ms', $this->docs, $section ) ) {
			$this->fail( 'COURSES.md has no "## Hooks" section to check.' );
		}

		if ( ! preg_match_all( "/`(anchor_courses_[a-z0-9_]+)`/", $section[1], $matches ) ) {
			$this->fail( 'No anchor_courses_* hook names found in the "## Hooks" section.' );
		}

		$documented = array_values( array_unique( $matches[1] ) );
		$in_source  = $this->hooks_in_source();

		$stale = array_diff( $documented, $in_source );
		$this->assertSame( [], $stale, 'COURSES.md "## Hooks" names a hook that is not fired anywhere: ' . implode( ', ', $stale ) );
	}

	public function test_every_public_api_function_is_documented() {
		foreach ( [
			'anchor_courses_enroll_user',
			'anchor_courses_complete_lesson',
			'anchor_courses_get_progress',
			'anchor_courses_award_ce_credit',
		] as $function ) {
			$this->assertTrue( function_exists( $function ), "Missing API function {$function}" );
			$this->assertStringContainsString( $function, $this->docs, "Undocumented API function {$function}" );
		}
	}

	public function test_every_rest_route_is_documented() {
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		$undocumented = [];
		foreach ( array_keys( $wp_rest_server->get_routes() ) as $route ) {
			if ( 0 !== strpos( $route, '/' . Routes::NAMESPACE ) ) {
				continue;
			}
			// Document the readable form, e.g. /quizzes/{id}/attempts.
			$readable = str_replace( '/' . Routes::NAMESPACE, '', $route );
			$readable = (string) preg_replace( '/\(\?P<(\w+)>[^)]+\)/', '{$1}', $readable );
			if ( '' === $readable ) {
				continue;
			}
			if ( ! str_contains( $this->docs, $readable ) ) {
				$undocumented[] = $readable;
			}
		}

		$this->assertSame( [], $undocumented, 'Undocumented routes: ' . implode( ', ', $undocumented ) );
	}

	public function test_every_shortcode_is_documented() {
		foreach ( [ 'anchor_courses', 'anchor_course', 'anchor_course_progress',
		            'anchor_my_courses', 'anchor_my_credits', 'anchor_my_certificates' ] as $tag ) {
			$this->assertStringContainsString( "[{$tag}]", $this->docs, "Undocumented shortcode [{$tag}]" );
		}
	}

	public function test_every_table_is_documented() {
		foreach ( \Anchor\Courses\Database\Migrations::TABLES as $table ) {
			$this->assertStringContainsString(
				'anchor_courses_' . $table,
				$this->docs,
				"Undocumented table anchor_courses_{$table}"
			);
		}
	}

	/**
	 * Every error code Routes::error_response() maps to an HTTP status must
	 * appear in COURSES.md's own copy of that map, so the two can't drift.
	 */
	public function test_every_error_response_code_is_documented() {
		$reflection = new ReflectionMethod( Routes::class, 'error_response' );
		$source     = file_get_contents( (string) $reflection->getFileName() );

		$this->assertNotFalse( $source, 'Could not read Routes.php source.' );
		$this->assertTrue(
			(bool) preg_match_all( "/'([a-z_]+)'\s*=>\s*\d{3}/", $source, $matches ),
			'No error->status map entries found in Routes::error_response().'
		);

		$undocumented = [];
		foreach ( array_unique( $matches[1] ) as $code ) {
			if ( ! str_contains( $this->docs, $code ) ) {
				$undocumented[] = $code;
			}
		}

		$this->assertSame( [], $undocumented, 'Undocumented REST error codes: ' . implode( ', ', $undocumented ) );
	}

	public function test_the_module_is_listed_in_the_repo_module_tables() {
		$claude = (string) file_get_contents( ANCHOR_TOOLS_PLUGIN_DIR . 'CLAUDE.md' );
		$adding = (string) file_get_contents( ANCHOR_TOOLS_PLUGIN_DIR . 'ADDING-MODULES.md' );

		$this->assertStringContainsString( '`courses`', $claude );
		$this->assertStringContainsString( '\\Anchor\\Courses\\Module', $claude );
		$this->assertStringContainsString( '`courses`', $adding );
	}
}
