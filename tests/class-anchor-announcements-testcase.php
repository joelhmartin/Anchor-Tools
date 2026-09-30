<?php
/**
 * Shared base case for the Anchor Announcements suite.
 *
 * @package Anchor\Announcements\Tests
 */

use Anchor\Announcements\Content\AnnouncementPostType as PT;
use Anchor\Announcements\Module;

abstract class Anchor_Announcements_TestCase extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		// Tables are DDL (outside the per-test transaction); empty them per test.
		global $wpdb;
		foreach ( [ 'sends', 'events', 'suppressions' ] as $t ) {
			$wpdb->query( 'DELETE FROM ' . \Anchor\Announcements\Database\Migrations::table( $t ) ); // phpcs:ignore
		}
	}

	protected function module(): Module {
		$m = Module::instance();
		$this->assertInstanceOf( Module::class, $m, 'announcements module did not boot: enable it in tests/bootstrap.php' );
		return $m;
	}

	protected function make_announcement( array $meta = [] ): int {
		$id   = self::factory()->post->create( [ 'post_type' => PT::CPT, 'post_status' => 'publish', 'post_title' => 'Test announcement' ] );
		$meta = array_merge(
			[
				PT::META_SUBJECT   => 'Hello {first_name}',
				PT::META_PREHEADER => 'Preview',
				PT::META_BODY      => '<p>Hi {first_name}, <a href="https://example.com/page?a=1&amp;b=2">read</a></p>',
				PT::META_AUDIENCE  => wp_json_encode( [ 'groups' => [] ] ),
				PT::META_STATE     => PT::STATE_DRAFT,
			],
			$meta
		);
		foreach ( $meta as $k => $v ) {
			update_post_meta( $id, $k, $v );
		}
		return $id;
	}

	protected function make_user( string $email, array $args = [] ): int {
		return self::factory()->user->create( array_merge( [ 'user_email' => $email, 'role' => 'subscriber' ], $args ) );
	}
}
