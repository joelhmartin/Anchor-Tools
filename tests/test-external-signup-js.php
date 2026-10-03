<?php
/**
 * The one visibility rule both authoring forms use (registration-mode.js),
 * and that both forms actually use it.
 *
 * @package Anchor\Events\Tests
 */

/**
 * @group assets
 */
class Test_External_Signup_Js extends Anchor_Events_TestCase {

	private function run_cases( array $cases ) {
		$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
		if ( $node === '' ) {
			$this->markTestSkipped( 'node is not installed.' );
		}
		$root = dirname( __DIR__ );
		$cmd  = escapeshellarg( $node ) . ' ' . escapeshellarg( $root . '/tests/js/registration-mode-harness.js' )
			. ' ' . escapeshellarg( $root . '/anchor-events-manager/assets/registration-mode.js' )
			. ' ' . escapeshellarg( wp_json_encode( $cases ) ) . ' 2>&1';
		$out  = (string) shell_exec( $cmd );
		$data = json_decode( $out, true );
		$this->assertIsArray( $data, 'Harness output: ' . $out );
		return $data;
	}

	public function test_checkbox_wins_then_the_select() {
		$this->assertSame( [ 'external', 'wc', 'free', 'free' ], $this->run_cases( [
			[ 'fn' => 'effective', 'args' => [ true, 'wc' ] ],
			[ 'fn' => 'effective', 'args' => [ false, 'wc' ] ],
			[ 'fn' => 'effective', 'args' => [ false, 'free' ] ],
			[ 'fn' => 'effective', 'args' => [ false, '' ] ],
		] ) );
	}

	public function test_when_mode_lists() {
		$this->assertSame( [ true, false, true, true, false ], $this->run_cases( [
			[ 'fn' => 'matches', 'args' => [ 'wc free', 'free' ] ],
			[ 'fn' => 'matches', 'args' => [ 'wc free', 'external' ] ],
			[ 'fn' => 'matches', 'args' => [ 'external', 'external' ] ],
			[ 'fn' => 'matches', 'args' => [ null, 'external' ] ],
			[ 'fn' => 'matches', 'args' => [ 'wc', 'free' ] ],
		] ) );
	}

	public function test_both_forms_use_the_shared_rule_and_watch_the_checkbox() {
		$base = dirname( __DIR__ ) . '/anchor-events-manager/assets/';
		foreach ( [ 'admin.js', 'manager.js' ] as $file ) {
			$src = file_get_contents( $base . $file );
			$this->assertStringContainsString( 'AnchorEventsRegistrationMode', $src, $file );
			$this->assertStringContainsString( '#anchor_event_external_signup', $src, $file );
		}
		$shared = file_get_contents( $base . 'registration-mode.js' );
		$this->assertStringNotContainsString( 'export ', $shared );
		$this->assertStringNotContainsString( 'import ', $shared );
	}
}
