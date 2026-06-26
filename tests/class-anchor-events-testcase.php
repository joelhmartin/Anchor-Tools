<?php
/**
 * Shared base test case for the Anchor Events Manager integration suite.
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Module;

/**
 * Provides event/seat factories and accessors to the events module singleton.
 */
abstract class Anchor_Events_TestCase extends WP_UnitTestCase {

	/**
	 * The events module singleton, instantiated by the plugin's priority-25
	 * bootstrap during the WP test boot.
	 *
	 * @return Module
	 */
	protected function module() {
		$module = Module::instance();
		$this->assertInstanceOf(
			Module::class,
			$module,
			'The events module did not bootstrap — check that the events_manager module is enabled in tests/bootstrap.php.'
		);
		return $module;
	}

	/** @return \Anchor\Events\Registrations */
	protected function registrations() {
		return $this->module()->registrations;
	}

	/** @return \Anchor\Events\Ticket_Types */
	protected function ticket_types() {
		return $this->module()->ticket_types;
	}

	/** @return \Anchor\Events\Product_Sync|null Null when WooCommerce is inactive. */
	protected function product_sync() {
		return $this->module()->product_sync;
	}

	/** @return \Anchor\Events\WooCommerce|null Null when WooCommerce is inactive. */
	protected function woocommerce() {
		return $this->module()->woocommerce;
	}

	/** Whether WooCommerce is active in this run. */
	protected function wc_active() {
		return class_exists( 'WooCommerce' );
	}

	/** Skip the current test unless WooCommerce is active. */
	protected function require_wc() {
		if ( ! $this->wc_active() ) {
			$this->markTestSkipped( 'WooCommerce is not active in this run.' );
		}
	}

	/**
	 * Create an `event` post with `_anchor_event_*` meta and optional ticket tiers.
	 *
	 * Sensible registration defaults are applied (registration enabled, unlimited
	 * capacity) and can be overridden via $meta. Tiers are persisted through
	 * Ticket_Types::save() so they get stable ids; that save happens AFTER the
	 * insert, so the save_post sync hook (if WC is active) sees no tiers at insert
	 * time and creates no product unless the test later calls sync_event().
	 *
	 * @param array $meta  Event meta overrides (keys WITHOUT the `_anchor_event_` prefix).
	 * @param array $tiers Optional raw ticket-tier rows for Ticket_Types::save().
	 * @return int Event post ID.
	 */
	protected function make_event( array $meta = [], array $tiers = [] ) {
		$event_id = self::factory()->post->create(
			[
				'post_type'   => Module::CPT,
				'post_status' => 'publish',
				'post_title'  => $meta['title'] ?? 'Test Event',
			]
		);
		unset( $meta['title'] );

		$defaults = [
			'registration_enabled' => true,
			'capacity'             => 0, // 0 = unlimited.
			'waitlist'             => false,
		];
		foreach ( array_merge( $defaults, $meta ) as $key => $value ) {
			update_post_meta( $event_id, '_anchor_event_' . $key, $value );
		}

		if ( ! empty( $tiers ) ) {
			$this->ticket_types()->save( $event_id, $tiers );
		}

		return (int) $event_id;
	}

	/**
	 * Convenience: create one seat for an event via the data layer.
	 *
	 * @param int   $event_id
	 * @param array $args     create_seat() arg overrides.
	 * @return int Seat post ID.
	 */
	protected function make_seat( $event_id, array $args = [] ) {
		return $this->registrations()->create_seat(
			array_merge(
				[
					'event_id' => $event_id,
					'name'     => 'Attendee',
					'email'    => 'attendee@example.test',
					'status'   => \Anchor\Events\Registrations::STATUS_CONFIRMED,
				],
				$args
			)
		);
	}

	/**
	 * Count seat posts for an event filtered by status meta (and optionally tier).
	 *
	 * @param int         $event_id
	 * @param string|null $status   Status meta to match (null = any).
	 * @param string|null $tier_id  Tier meta to match (null = any).
	 * @return int
	 */
	protected function count_seats( $event_id, $status = null, $tier_id = null ) {
		$meta_query = [
			[ 'key' => '_anchor_event_id', 'value' => (int) $event_id, 'type' => 'NUMERIC' ],
		];
		if ( null !== $status ) {
			$meta_query[] = [ 'key' => '_anchor_event_reg_status', 'value' => $status ];
		}
		if ( null !== $tier_id ) {
			$meta_query[] = [ 'key' => '_anchor_event_ticket_type_id', 'value' => $tier_id ];
		}
		$q = new WP_Query(
			[
				'post_type'      => Module::REG_CPT,
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'meta_query'     => $meta_query,
			]
		);
		return count( $q->posts );
	}
}
