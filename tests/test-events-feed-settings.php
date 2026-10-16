<?php
/**
 * Events feed — the App categories setting (spec "App categories setting").
 *
 * @package Anchor\Events\Tests
 */

/** @group events-feed */
class Test_Events_Feed_Settings extends Anchor_Events_TestCase {

	public function test_default_is_empty() {
		delete_option( 'anchor_events_settings' );
		$this->assertSame( [], $this->module()->get_settings()['app_categories'] );
		$this->assertSame( [], $this->module()->feed->app_term_ids() );
	}

	public function test_sanitize_keeps_only_real_event_category_ids() {
		$cat   = self::factory()->term->create( [ 'taxonomy' => 'event_category' ] );
		$other = self::factory()->term->create( [ 'taxonomy' => 'category' ] );
		$out = $this->module()->sanitize_settings( [ 'app_categories' => [ (string) $cat, (string) $other, 'abc', '999999' ] ] );
		$this->assertSame( [ $cat ], $out['app_categories'] );
	}

	public function test_sanitize_missing_key_saves_empty() {
		$out = $this->module()->sanitize_settings( [] );
		$this->assertSame( [], $out['app_categories'] );
	}

	public function test_deleted_term_is_ignored_at_read_time() {
		$keep = self::factory()->term->create( [ 'taxonomy' => 'event_category' ] );
		$gone = self::factory()->term->create( [ 'taxonomy' => 'event_category' ] );
		update_option( 'anchor_events_settings', [ 'app_categories' => [ $keep, $gone ] ], false );
		wp_delete_term( $gone, 'event_category' );
		$this->assertSame( [ $keep ], $this->module()->feed->app_term_ids() );
	}
}
