<?php
/**
 * Anchor Store Locator front-end module: localized location data and the
 * [anchor_store_locator card="..."] compact-card attribute.
 *
 * Owner feedback was that the default results card is "too large and
 * obnoxious" (portrait photo, distance, address). The fix is a compact card
 * option (title, owner, "View location" only) selected per shortcode via a
 * `card` attribute, with the chosen mode handed to assets/frontend.js as a
 * data attribute so the same location data (now including owner) can render
 * either card shape client-side.
 *
 * @package Anchor\StoreLocator\Tests
 */

class Test_Store_Locator_Module extends WP_UnitTestCase {

	/** @var \Anchor\StoreLocator\Module */
	private $module;

	public function set_up() {
		parent::set_up();

		require_once dirname( __DIR__ ) . '/anchor-store-locator/anchor-store-locator.php';

		$this->module = new \Anchor\StoreLocator\Module();
		$this->module->register_cpt();

		$this->set_api_key();
	}

	private function set_api_key() {
		$settings = get_option( 'anchor_schema_settings', [] );
		if ( ! is_array( $settings ) ) {
			$settings = [];
		}
		$settings['google_api_key'] = 'test-key';
		update_option( 'anchor_schema_settings', $settings, false );
	}

	private function make_store( array $args = [], array $meta = [] ) {
		$post_id = self::factory()->post->create(
			array_merge(
				[
					'post_type'   => \Anchor\StoreLocator\Module::CPT,
					'post_status' => 'publish',
					'post_title'  => 'Test Store',
				],
				$args
			)
		);

		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, '_anchor_store_' . $key, $value );
		}

		return $post_id;
	}

	/* ─── Localized location data includes owner ─── */

	public function test_localized_locations_include_the_owner() {
		$this->make_store(
			[ 'post_title' => 'TMJ Test Centre' ],
			[
				'owner' => 'Dr. Erica Sok',
				'lat'   => 39.1,
				'lng'   => -84.5,
			]
		);

		do_shortcode( '[anchor_store_locator]' );

		$data = (string) wp_scripts()->get_data( 'anchor-store-locator', 'data' );
		$this->assertStringContainsString( '"owner":"Dr. Erica Sok"', $data );
	}

	public function test_localized_locations_owner_is_empty_string_when_unset() {
		$this->make_store( [ 'post_title' => 'No Owner Store' ], [ 'lat' => 39.1, 'lng' => -84.5 ] );

		do_shortcode( '[anchor_store_locator]' );

		$data = (string) wp_scripts()->get_data( 'anchor-store-locator', 'data' );
		$this->assertStringContainsString( '"owner":""', $data );
	}

	/* ─── card attribute ─── */

	public function test_default_card_mode_is_full_and_unaffected() {
		$out = do_shortcode( '[anchor_store_locator]' );

		$this->assertStringContainsString( 'data-anchor-store-card="full"', $out );
		$this->assertStringContainsString( 'data-anchor-store-results', $out );
		$this->assertStringNotContainsString( 'anchor-store-results--compact', $out );
	}

	public function test_card_compact_is_passed_to_the_front_end_as_a_data_attribute() {
		$out = do_shortcode( '[anchor_store_locator card="compact"]' );

		$this->assertStringContainsString( 'data-anchor-store-card="compact"', $out );
		$this->assertStringContainsString( 'anchor-store-results--compact', $out );
	}

	public function test_unrecognised_card_value_falls_back_to_full() {
		$out = do_shortcode( '[anchor_store_locator card="bogus"]' );

		$this->assertStringContainsString( 'data-anchor-store-card="full"', $out );
		$this->assertStringNotContainsString( 'anchor-store-results--compact', $out );
	}

	public function test_missing_api_key_still_shows_the_missing_key_notice_regardless_of_card() {
		delete_option( 'anchor_schema_settings' );

		$out = do_shortcode( '[anchor_store_locator card="compact"]' );

		$this->assertStringContainsString( 'anchor-store-missing-key', $out );
		$this->assertStringNotContainsString( 'data-anchor-store-card', $out );
	}
}
