# Anchor Shipping (Phases 1–2) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A carrier-agnostic Anchor-Tools module that creates, stores, emails and voids UPS labels for WooCommerce orders — manually, or automatically on payment behind a settings checkbox — with optional insurance/signature that a store can offer at checkout or apply automatically.

**Architecture:** New PSR-4 module `anchor-shipping/` (`Anchor\Shipping\`). Carriers implement `CarrierInterface` and register through the `anchor_shipping_carriers` filter; UPS is the first adapter. Core services (LabelService, PaidOrderHandler, VoidOnCancel, Protection) own Woo integration and never see carrier JSON. One table, `{prefix}anchor_shipments`, one row per package; order notes + order meta mirror state.

**Tech Stack:** PHP 8.1+, WordPress, WooCommerce (Action Scheduler, WC_Email), UPS REST APIs (OAuth client-credentials), `setasign/fpdf` (label PDF), PHPUnit 9 + WP test lib.

**Spec:** `docs/superpowers/specs/2026-10-03-anchor-shipping-design.md` — read it before starting any task.

**Out of this plan (later plans):** orders-list shipping-status column, tracking poller + customer "shipped" email + My Account tracking + complete-on-delivery + bulk labels + address validation (phase 3); live checkout rates (phase 4).

## Global Constraints

- PHP files under `src/` start with `declare(strict_types=1);`; the module bootstrap file does not (matches `anchor-agreements`).
- Namespace `Anchor\Shipping\`, PSR-4 root `anchor-shipping/src/`. Registry key `shipping`, class `\Anchor\Shipping\Module`.
- Text domain `'anchor-schema'`. Options via `update_option( $k, $v, false )` (autoload off).
- Assets: enqueue source `.css`/`.js` via `ANCHOR_TOOLS_PLUGIN_URL . 'anchor-shipping/assets/…'`; jQuery IIFE; never commit `*.min.*`.
- AJAX actions prefixed `anchor_shipping_`. Capability for all label actions: `edit_shop_orders`; settings: `manage_woocommerce`.
- Default carrier environment is **sandbox** (`https://wwwcie.ups.com`). Production host `https://onlinetools.ups.com`.
- Credential constants override saved settings: `ANCHOR_SHIPPING_UPS_CLIENT_ID`, `ANCHOR_SHIPPING_UPS_CLIENT_SECRET`, `ANCHOR_SHIPPING_UPS_ACCOUNT`, `ANCHOR_SHIPPING_UPS_ENVIRONMENT`.
- Secrets are never rendered into HTML, never logged, never put in exception messages. Carrier HTTP bodies are never logged.
- UPS must never email the customer: no `ShipTo.EMailAddress`, no `ShipmentServiceOptions.Notification`.
- Customer gets **no** email at label creation (the "shipped" email is phase 3, on first carrier scan).
- Never block checkout: paid-order work runs in Action Scheduler (`as_enqueue_async_action`, group `anchor-shipping`).
- HPOS-compatible: read/write orders only through `WC_Order` (`get_meta`/`update_meta_data`/`save`), never `get_post_meta` on orders.
- Internal units: kg and cm (`Parcel`); adapters convert. Store settings are in the store's units (DEKA: kg/cm).
- Auto-label checkbox default **off**. Insurance/signature modes default **off**; customer-mode checkboxes are hidden until a fee is set.
- Commit trailer on every commit: `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>` (implementers on another model use their own model name).
- Never `git push` without `origin feat/anchor-shipping` spelled out. Do not touch the `feat/anchor-agreements` checkout at `~/Developer/anchor-os/Anchor-Tools`.

## Review Focus

1. **Double-click / double-run creates two billed labels.** Expected: a second create on an order with an active (non-voided) shipment is refused unless "additional package" is explicitly ticked; a paid-order job that runs twice creates one label. → Task 9 test `test_second_create_is_refused_without_additional_flag`, Task 12 test `test_running_the_job_twice_creates_one_label`.
2. **Unit conversion (store kg/cm → UPS LBS/IN).** Expected: 1 kg → 2.2 lb; 25.4 cm → 10 in (not 11); weights never below 0.1 lb. → Task 2 test `test_parcel_converts_to_pounds_and_inches`.
3. **Secrets leak into the page or logs.** Expected: saved client secret never appears in settings HTML; empty submit keeps the stored secret; a constant greys the field out. → Task 4 tests.
4. **Label files reachable without permission.** Expected: download by a user without `edit_shop_orders`, or with a bad nonce, is refused; paths cannot escape the label dir. → Task 7 + Task 10 tests.
5. **Expired/revoked token mid-day.** Expected: a 401 clears the cached token, fetches a new one and retries once; a second 401 surfaces as a non-retryable auth error. → Task 5 test `test_401_refreshes_token_and_retries_once`.

---

## Test harness (every task)

The harness lives in `/tmp` and macOS purges it after ~3 days. Lane name for this branch: `shipping`.

```bash
cd ~/Developer/anchor-os/Anchor-Tools-shipping
# Once per session (rebuild if /tmp/wordpress or the lib is missing):
test -f /tmp/wordpress/wp-settings.php || {
  rm -rf /tmp/wordpress /tmp/wordpress-tests-lib
  WP_CORE_DIR=/tmp/wordpress WP_TESTS_DIR=/tmp/wordpress-tests-lib \
    bash bin/install-wp-tests.sh wordpress_test root root 127.0.0.1:3307 latest true
}
test -d /tmp/wordpress/wp-content/plugins/woocommerce || {
  curl -sL https://downloads.wordpress.org/plugin/woocommerce.latest-stable.zip -o /tmp/woocommerce.zip
  unzip -q -o /tmp/woocommerce.zip -d /tmp/wordpress/wp-content/plugins
}
test -d /tmp/wordpress-tests-lib-shipping || {
  cp -R /tmp/wordpress-tests-lib /tmp/wordpress-tests-lib-shipping
  sed -i '' "s/'wordpress_test'/'wordpress_test_shipping'/" /tmp/wordpress-tests-lib-shipping/wp-tests-config.php
  docker exec anchor-tools-mysql mysql -uroot -proot -e 'CREATE DATABASE IF NOT EXISTS wordpress_test_shipping'
}
composer install          # dev deps for phpunit (vendor/phpunit is gitignored)
export WP_TESTS_DIR=/tmp/wordpress-tests-lib-shipping WP_CORE_DIR=/tmp/wordpress
vendor/bin/phpunit tests/test-shipping-<name>.php
```

MySQL is docker container `anchor-tools-mysql` on port 3307 (root/root); start it with `docker start anchor-tools-mysql` if it is stopped. If PHPUnit dies on `wp-settings.php` or "Class PHPUnit\TextUI\Command not found", it is the environment — rebuild, don't debug the branch.

**Before every commit:** `composer dump-autoload --no-dev` (the repo tracks prod-only autoload maps), then stage only the files the task names plus `vendor/composer/*` if the task changed `composer.json`. Re-run `composer dump-autoload` before testing again.

---

### Task 1: Module scaffold, table, repository

**Files:**
- Create: `anchor-shipping/anchor-shipping.php`
- Create: `anchor-shipping/src/Database/Migrations.php`
- Create: `anchor-shipping/src/Database/ShipmentRepository.php`
- Create: `tests/class-anchor-shipping-testcase.php`
- Create: `tests/test-shipping-repository.php`
- Modify: `anchor-tools.php` (module registry, after the `'announcements'` entry ~line 323)
- Modify: `composer.json` (`autoload.psr-4`)
- Modify: `tests/bootstrap.php` (enable module, require testcase)

**Interfaces:**
- Produces: `Anchor\Shipping\Module` with `instance(): ?Module`, `dir(): string`, `url(string): string`, public property `ShipmentRepository $shipments`.
- Produces: `Migrations::table(): string`, `Migrations::maybe_migrate(): void`.
- Produces: `ShipmentRepository` — `insert(array $row): int`, `find(int $id): ?array`, `for_order(int $order_id): array`, `active_for_order(int $order_id): array`, `by_shipment_id(string $carrier, string $shipment_id): array`, `update(int $id, array $fields): void`. Rows are associative arrays with string values as returned by `$wpdb` (`ARRAY_A`).
- Produces: `Anchor_Shipping_TestCase` with `fake_http`, `queue_response(int $code, array|string $body)`, `requests` array, `configure_ups()`, `make_product(array $args = []): int`, `make_order(array $product_ids = [], string $status = 'pending', bool $with_shipping_line = true): WC_Order`, `queue_token()`, `fixture(string $name): array`.

- [ ] **Step 1: Register the module, autoload and bootstrap**

In `anchor-tools.php`, add after the `'announcements'` entry:

```php
            'shipping' => [
                'label'       => __( 'Anchor Shipping', 'anchor-schema' ),
                'description' => __( 'Carrier labels and tracking for WooCommerce orders (UPS first).', 'anchor-schema' ),
                'path'        => ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-shipping/anchor-shipping.php',
                'class'       => '\\Anchor\\Shipping\\Module',
            ],
```

In `composer.json` `autoload.psr-4` add `"Anchor\\Shipping\\": "anchor-shipping/src/"`, then run `composer dump-autoload`.

In `tests/bootstrap.php`: add `'shipping' => true` to the `modules` array in the `plugins_loaded` priority-1 filter, and add `require __DIR__ . '/class-anchor-shipping-testcase.php';` after the announcements testcase require.

- [ ] **Step 2: Write the module bootstrap**

`anchor-shipping/anchor-shipping.php`:

```php
<?php
/**
 * Anchor Tools module: Anchor Shipping.
 *
 * Carrier labels for WooCommerce orders. Carriers are adapters behind
 * Carriers\CarrierInterface (UPS first); everything Woo-facing lives in
 * Services\ and Admin\. Bootstrap only; see the spec in docs/superpowers/specs.
 *
 * @package Anchor\Shipping
 */

namespace Anchor\Shipping;

use Anchor\Shipping\Database\Migrations;
use Anchor\Shipping\Database\ShipmentRepository;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

class Module {

	public const VERSION = '1.0.0';

	public ShipmentRepository $shipments;

	private static ?Module $instance = null;

	public static function instance(): ?Module {
		return self::$instance;
	}

	public static function url( string $path ): string {
		return \plugins_url( $path, __FILE__ );
	}

	public static function dir(): string {
		return __DIR__;
	}

	public function __construct() {
		self::$instance = $this;
		Migrations::maybe_migrate();
		\add_action( 'admin_init', [ Migrations::class, 'maybe_migrate' ] );
		$this->shipments = new ShipmentRepository();

		if ( ! \class_exists( 'WooCommerce' ) ) {
			\add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-warning"><p>' . \esc_html__( 'Anchor Shipping needs WooCommerce to be active.', 'anchor-schema' ) . '</p></div>';
				}
			);
			return;
		}
		// Later tasks register their services below this line.
	}
}
```

- [ ] **Step 3: Write Migrations**

`anchor-shipping/src/Database/Migrations.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Shipping\Database;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** The shipments table, created or upgraded when the stored version is behind. */
final class Migrations {

	public const VERSION = '1';
	public const OPTION  = 'anchor_shipping_db_version';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'anchor_shipments';
	}

	public static function maybe_migrate(): void {
		if ( self::VERSION === (string) \get_option( self::OPTION ) ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		\dbDelta(
			'CREATE TABLE ' . self::table() . " (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				order_id BIGINT UNSIGNED NOT NULL,
				carrier VARCHAR(32) NOT NULL,
				service VARCHAR(32) NOT NULL DEFAULT '',
				shipment_id VARCHAR(64) NOT NULL DEFAULT '',
				tracking_number VARCHAR(64) NOT NULL DEFAULT '',
				status VARCHAR(20) NOT NULL DEFAULT 'label_created',
				status_detail VARCHAR(255) NOT NULL DEFAULT '',
				last_event_at DATETIME NULL,
				delivered_at DATETIME NULL,
				cost DECIMAL(10,2) NULL,
				currency CHAR(3) NOT NULL DEFAULT '',
				declared_value DECIMAL(10,2) NOT NULL DEFAULT 0,
				signature TINYINT(1) NOT NULL DEFAULT 0,
				label_path VARCHAR(255) NOT NULL DEFAULT '',
				label_format VARCHAR(8) NOT NULL DEFAULT '',
				box VARCHAR(64) NOT NULL DEFAULT '',
				weight_kg DECIMAL(10,3) NOT NULL DEFAULT 0,
				dims_cm VARCHAR(64) NOT NULL DEFAULT '',
				source VARCHAR(10) NOT NULL DEFAULT 'manual',
				created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL,
				voided_at DATETIME NULL,
				last_polled_at DATETIME NULL,
				PRIMARY KEY  (id),
				KEY order_id (order_id),
				KEY tracking_number (tracking_number),
				KEY status (status),
				KEY carrier_shipment (carrier,shipment_id)
			) $charset;"
		);
		\update_option( self::OPTION, self::VERSION, false );
	}
}
```

- [ ] **Step 4: Write the shared test case**

`tests/class-anchor-shipping-testcase.php`:

```php
<?php
/**
 * Shared base case for the Anchor Shipping suite: a fake HTTP layer, a configured
 * UPS sandbox account, and product/order factories.
 *
 * @package Anchor\Shipping\Tests
 */

use Anchor\Shipping\Database\Migrations;

abstract class Anchor_Shipping_TestCase extends WP_UnitTestCase {

	/** @var array<int, array{code:int, body:string}> */
	protected array $http_queue = [];

	/** @var array<int, array{url:string, args:array}> */
	protected array $requests = [];

	public function set_up() {
		parent::set_up();
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is not installed in the test environment.' );
		}
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Migrations::table() ); // phpcs:ignore
		delete_option( 'anchor_shipping_settings' );
		foreach ( [ 'sandbox', 'production' ] as $env ) {
			delete_transient( 'anchor_shipping_ups_token_' . md5( $env . '|cid' ) );
		}
		$this->http_queue = [];
		$this->requests   = [];
		add_filter( 'pre_http_request', [ $this, 'fake_http' ], 10, 3 );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', [ $this, 'fake_http' ], 10 );
		parent::tear_down();
	}

	/** @param array|string $body */
	protected function queue_response( int $code, $body ): void {
		$this->http_queue[] = [ 'code' => $code, 'body' => is_string( $body ) ? $body : wp_json_encode( $body ) ];
	}

	protected function queue_token(): void {
		$this->queue_response( 200, [ 'access_token' => 'tok-' . count( $this->requests ), 'expires_in' => '14399', 'status' => 'approved' ] );
	}

	/** @return array|WP_Error */
	public function fake_http( $pre, $args, $url ) {
		$this->requests[] = [ 'url' => $url, 'args' => $args ];
		$next             = array_shift( $this->http_queue );
		if ( null === $next ) {
			return new WP_Error( 'unexpected_http', 'No fake response queued for ' . $url );
		}
		return [
			'headers'  => [],
			'body'     => $next['body'],
			'response' => [ 'code' => $next['code'], 'message' => '' ],
			'cookies'  => [],
			'filename' => null,
		];
	}

	protected function fixture( string $name ): array {
		return json_decode( (string) file_get_contents( __DIR__ . '/fixtures/shipping/' . $name . '.json' ), true );
	}

	/** Saves a complete sandbox UPS setup with one box preset. Requires Task 3. */
	protected function configure_ups( array $overrides = [] ): void {
		\Anchor\Shipping\Support\Settings::save(
			array_replace_recursive(
				[
					'carriers'  => [ 'ups' => [ 'environment' => 'sandbox', 'client_id' => 'cid', 'client_secret' => 'csecret', 'account' => 'K877V9' ] ],
					'ship_from' => [ 'name' => 'Shipping Dept', 'company' => 'DEKA Test', 'phone' => '8133208285', 'line1' => '400 North Ashley Drive', 'line2' => '', 'city' => 'Tampa', 'state' => 'FL', 'postcode' => '33602', 'country' => 'US' ],
					'boxes'     => [ [ 'id' => 'small', 'name' => 'Small', 'length' => 25.4, 'width' => 20.32, 'height' => 10.16, 'empty_weight' => 0.2, 'max_weight' => 10 ] ],
					'inbox'     => 'shipping@example.com',
				],
				$overrides
			)
		);
	}

	protected function make_product( array $args = [] ): int {
		$p = new WC_Product_Simple();
		$p->set_name( $args['name'] ?? 'Tip pack' );
		$p->set_regular_price( (string) ( $args['price'] ?? '110' ) );
		$p->set_virtual( (bool) ( $args['virtual'] ?? false ) );
		if ( isset( $args['weight'] ) ) {
			$p->set_weight( (string) $args['weight'] );
		}
		$p->save();
		if ( isset( $args['box'] ) ) {
			update_post_meta( $p->get_id(), '_anchor_shipping_box', $args['box'] );
		}
		return $p->get_id();
	}

	/** Order with a US shipping address and a flat-rate shipping line. */
	protected function make_order( array $product_ids = [], string $status = 'pending', bool $with_shipping_line = true ): WC_Order {
		$order = wc_create_order();
		foreach ( $product_ids ?: [ $this->make_product( [ 'weight' => 0.5, 'box' => 'small' ] ) ] as $pid ) {
			$order->add_product( wc_get_product( $pid ), 1 );
		}
		$order->set_address(
			[ 'first_name' => 'Pat', 'last_name' => 'Doe', 'company' => 'Doe Dental', 'address_1' => '1 Infinite Loop', 'city' => 'Cupertino', 'state' => 'CA', 'postcode' => '95014', 'country' => 'US', 'phone' => '5555555555', 'email' => 'pat@example.com' ],
			'billing'
		);
		$order->set_address(
			[ 'first_name' => 'Pat', 'last_name' => 'Doe', 'company' => 'Doe Dental', 'address_1' => '1 Infinite Loop', 'city' => 'Cupertino', 'state' => 'CA', 'postcode' => '95014', 'country' => 'US' ],
			'shipping'
		);
		if ( $with_shipping_line ) {
			$rate = new WC_Shipping_Rate( 'flat_rate:2', 'Flat rate', '19.95', [], 'flat_rate', 2 );
			$item = new WC_Order_Item_Shipping();
			$item->set_shipping_rate( $rate );
			$order->add_item( $item );
		}
		$order->calculate_totals();
		$order->set_status( $status );
		$order->save();
		return $order;
	}
}
```

- [ ] **Step 5: Write the failing repository test**

`tests/test-shipping-repository.php`:

```php
<?php
use Anchor\Shipping\Database\Migrations;
use Anchor\Shipping\Module;

class Test_Shipping_Repository extends Anchor_Shipping_TestCase {

	public function test_module_boots_and_creates_the_table() {
		global $wpdb;
		$this->assertInstanceOf( Module::class, Module::instance() );
		$this->assertSame( Migrations::table(), $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Migrations::table() ) ) );
	}

	public function test_insert_find_and_active_for_order() {
		$repo = Module::instance()->shipments;
		$a    = $repo->insert( [ 'order_id' => 10, 'carrier' => 'ups', 'shipment_id' => 'S1', 'tracking_number' => '1Z1' ] );
		$b    = $repo->insert( [ 'order_id' => 10, 'carrier' => 'ups', 'shipment_id' => 'S2', 'tracking_number' => '1Z2' ] );
		$repo->update( $b, [ 'status' => 'voided', 'voided_at' => current_time( 'mysql', true ) ] );

		$this->assertSame( '1Z1', $repo->find( $a )['tracking_number'] );
		$this->assertSame( 'label_created', $repo->find( $a )['status'] );
		$this->assertCount( 2, $repo->for_order( 10 ) );
		$this->assertSame( [ (string) $a ], array_column( $repo->active_for_order( 10 ), 'id' ) );
		$this->assertCount( 1, $repo->by_shipment_id( 'ups', 'S2' ) );
		$this->assertNull( $repo->find( 999999 ) );
	}
}
```

- [ ] **Step 6: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/test-shipping-repository.php`
Expected: FAIL — `Class "Anchor\Shipping\Database\ShipmentRepository" not found` (fatal during module boot).

- [ ] **Step 7: Write the repository**

`anchor-shipping/src/Database/ShipmentRepository.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Shipping\Database;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** One row per package. Voided rows are kept for the audit trail. */
final class ShipmentRepository {

	public function insert( array $row ): int {
		global $wpdb;
		$row += [
			'status'     => 'label_created',
			'created_at' => \current_time( 'mysql', true ),
			'created_by' => \get_current_user_id(),
		];
		$wpdb->insert( Migrations::table(), $row );
		return (int) $wpdb->insert_id;
	}

	public function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Migrations::table() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore
		return $row ?: null;
	}

	public function for_order( int $order_id ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Migrations::table() . ' WHERE order_id = %d ORDER BY id', $order_id ), ARRAY_A ) ?: []; // phpcs:ignore
	}

	public function active_for_order( int $order_id ): array {
		return array_values( array_filter( $this->for_order( $order_id ), static fn( array $r ) => 'voided' !== $r['status'] ) );
	}

	public function by_shipment_id( string $carrier, string $shipment_id ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Migrations::table() . ' WHERE carrier = %s AND shipment_id = %s ORDER BY id', $carrier, $shipment_id ), ARRAY_A ) ?: []; // phpcs:ignore
	}

	public function update( int $id, array $fields ): void {
		global $wpdb;
		$wpdb->update( Migrations::table(), $fields, [ 'id' => $id ] );
	}
}
```

- [ ] **Step 8: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/test-shipping-repository.php`
Expected: PASS (2 tests).

- [ ] **Step 9: Commit**

```bash
composer dump-autoload --no-dev
git add anchor-shipping/ tests/class-anchor-shipping-testcase.php tests/test-shipping-repository.php tests/bootstrap.php anchor-tools.php composer.json vendor/composer/
git commit -m "feat(shipping): module scaffold, shipments table and repository

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Domain objects, carrier contract, registry

**Files:**
- Create: `anchor-shipping/src/Domain/Address.php`, `Parcel.php`, `ShipmentRequest.php`, `PackageLabel.php`, `LabelResult.php`, `CarrierError.php`
- Create: `anchor-shipping/src/Carriers/CarrierInterface.php`, `CarrierRegistry.php`
- Modify: `anchor-shipping/anchor-shipping.php` (add `$carriers`)
- Test: `tests/test-shipping-domain.php`

**Interfaces:**
- Produces: `Address(name, company, line1, line2, city, state, postcode, country, phone = '')` (all `public readonly string`), `Address::from_order_shipping(\WC_Order): Address`, `is_complete(): bool`.
- Produces: `Parcel(float $weight_kg, float $length_cm, float $width_cm, float $height_cm, string $box_id = '')`, `Parcel::from_store_units(float $weight, float $l, float $w, float $h, string $box_id = ''): Parcel`, `weight_lb(): float`, `dims_in(): array{0:int,1:int,2:int}`, `dims_label(): string` ("25.4×20.3×10.2").
- Produces: `ShipmentRequest(Address $from, Address $to, array $parcels, string $service, string $reference, float $declared_value = 0.0, bool $signature = false, string $currency = 'USD')`.
- Produces: `PackageLabel(string $tracking_number, string $bytes, string $format)`; `LabelResult(string $shipment_id, array $packages /* PackageLabel[] */, ?float $cost, string $currency)`.
- Produces: `CarrierError extends \RuntimeException` with `public readonly string $carrier_code`, `public readonly bool $retryable`; constructor `(string $carrier_code, string $message, bool $retryable = false)`.
- Produces: `CarrierInterface` (below) and `CarrierRegistry::all(): array<string, CarrierInterface>`, `get(string $id): ?CarrierInterface`, `reset(): void`. Filter `anchor_shipping_carriers`.
- Produces: `Module::$carriers` (`CarrierRegistry`).

- [ ] **Step 1: Write the failing tests**

`tests/test-shipping-domain.php`:

```php
<?php
use Anchor\Shipping\Carriers\CarrierInterface;
use Anchor\Shipping\Domain\Address;
use Anchor\Shipping\Domain\Parcel;
use Anchor\Shipping\Module;

class Test_Shipping_Domain extends Anchor_Shipping_TestCase {

	public function test_parcel_converts_to_pounds_and_inches() {
		$p = new Parcel( 1.0, 25.4, 20.32, 10.16 );
		$this->assertSame( 2.2, $p->weight_lb() );
		$this->assertSame( [ 10, 8, 4 ], $p->dims_in() );
		$this->assertSame( 0.1, ( new Parcel( 0.001, 1, 1, 1 ) )->weight_lb(), 'UPS minimum is 0.1 lb' );
	}

	public function test_parcel_rejects_zero_weight() {
		$this->expectException( InvalidArgumentException::class );
		new Parcel( 0.0, 10, 10, 10 );
	}

	public function test_from_store_units_honours_store_units() {
		update_option( 'woocommerce_weight_unit', 'lbs' );
		update_option( 'woocommerce_dimension_unit', 'in' );
		$p = Parcel::from_store_units( 2.2, 10, 8, 4 );
		$this->assertEqualsWithDelta( 0.998, $p->weight_kg, 0.01 );
		$this->assertEqualsWithDelta( 25.4, $p->length_cm, 0.01 );
	}

	public function test_address_from_order_prefers_shipping_and_falls_back_to_billing_phone() {
		$a = Address::from_order_shipping( $this->make_order() );
		$this->assertSame( 'Pat Doe', $a->name );
		$this->assertSame( '1 Infinite Loop', $a->line1 );
		$this->assertSame( '5555555555', $a->phone );
		$this->assertTrue( $a->is_complete() );
	}

	public function test_registry_ships_ups_and_accepts_third_party_carriers() {
		$registry = Module::instance()->carriers;
		$this->assertInstanceOf( CarrierInterface::class, $registry->get( 'ups' ) );
		$this->assertNull( $registry->get( 'nope' ) );

		$fake = $this->createMock( CarrierInterface::class );
		add_filter( 'anchor_shipping_carriers', static fn( $c ) => $c + [ 'fake' => $fake, 'junk' => new stdClass() ] );
		$registry->reset();
		$this->assertSame( $fake, $registry->get( 'fake' ) );
		$this->assertNull( $registry->get( 'junk' ), 'non-carriers are dropped' );
		$registry->reset();
	}
}
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit tests/test-shipping-domain.php`
Expected: FAIL — `Class "Anchor\Shipping\Domain\Parcel" not found`.

- [ ] **Step 3: Write the value objects**

`anchor-shipping/src/Domain/Address.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Shipping\Domain;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class Address {

	public function __construct(
		public readonly string $name,
		public readonly string $company,
		public readonly string $line1,
		public readonly string $line2,
		public readonly string $city,
		public readonly string $state,
		public readonly string $postcode,
		public readonly string $country,
		public readonly string $phone = ''
	) {}

	/** Shipping address, or billing when the order has none. Phone falls back to billing. */
	public static function from_order_shipping( \WC_Order $o ): self {
		$type = '' !== $o->get_shipping_address_1() ? 'shipping' : 'billing';
		$get  = static fn( string $f ): string => (string) $o->{"get_{$type}_{$f}"}();
		return new self(
			trim( $get( 'first_name' ) . ' ' . $get( 'last_name' ) ),
			$get( 'company' ),
			$get( 'address_1' ),
			$get( 'address_2' ),
			$get( 'city' ),
			$get( 'state' ),
			$get( 'postcode' ),
			$get( 'country' ),
			(string) ( $o->get_shipping_phone() ?: $o->get_billing_phone() )
		);
	}

	public function is_complete(): bool {
		return '' !== $this->name && '' !== $this->line1 && '' !== $this->city && '' !== $this->postcode && '' !== $this->country;
	}
}
```

`anchor-shipping/src/Domain/Parcel.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Shipping\Domain;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** One physical package, in kg/cm. Adapters convert to their carrier's units. */
final class Parcel {

	public function __construct(
		public readonly float $weight_kg,
		public readonly float $length_cm,
		public readonly float $width_cm,
		public readonly float $height_cm,
		public readonly string $box_id = ''
	) {
		if ( $weight_kg <= 0 ) {
			throw new \InvalidArgumentException( 'Parcel weight must be greater than zero.' );
		}
	}

	/** Values in the store's configured weight/dimension units. */
	public static function from_store_units( float $weight, float $l, float $w, float $h, string $box_id = '' ): self {
		return new self(
			(float) \wc_get_weight( $weight, 'kg' ),
			(float) \wc_get_dimension( $l, 'cm' ),
			(float) \wc_get_dimension( $w, 'cm' ),
			(float) \wc_get_dimension( $h, 'cm' ),
			$box_id
		);
	}

	public function weight_lb(): float {
		return max( 0.1, round( $this->weight_kg * 2.20462262, 1 ) );
	}

	/** @return array{0:int,1:int,2:int} whole inches, rounded up (rounded to 0.01 first so 25.4cm is 10in, not 11). */
	public function dims_in(): array {
		return array_map(
			static fn( float $cm ): int => (int) max( 1, ceil( round( $cm / 2.54, 2 ) ) ),
			[ $this->length_cm, $this->width_cm, $this->height_cm ]
		);
	}

	public function dims_label(): string {
		return implode( '×', array_map( static fn( float $v ) => (string) round( $v, 1 ), [ $this->length_cm, $this->width_cm, $this->height_cm ] ) );
	}
}
```

`anchor-shipping/src/Domain/ShipmentRequest.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Shipping\Domain;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class ShipmentRequest {

	/** @param Parcel[] $parcels */
	public function __construct(
		public readonly Address $from,
		public readonly Address $to,
		public readonly array $parcels,
		public readonly string $service,
		public readonly string $reference,
		public readonly float $declared_value = 0.0,
		public readonly bool $signature = false,
		public readonly string $currency = 'USD'
	) {
		if ( ! $parcels ) {
			throw new \InvalidArgumentException( 'A shipment needs at least one parcel.' );
		}
	}
}
```

`anchor-shipping/src/Domain/PackageLabel.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Shipping\Domain;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class PackageLabel {

	/** @param string $format 'GIF' or 'ZPL' — what the carrier returned, before LabelStore converts it. */
	public function __construct(
		public readonly string $tracking_number,
		public readonly string $bytes,
		public readonly string $format
	) {}
}
```

`anchor-shipping/src/Domain/LabelResult.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Shipping\Domain;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class LabelResult {

	/** @param PackageLabel[] $packages same order as ShipmentRequest::$parcels */
	public function __construct(
		public readonly string $shipment_id,
		public readonly array $packages,
		public readonly ?float $cost,
		public readonly string $currency
	) {}
}
```

`anchor-shipping/src/Domain/CarrierError.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Shipping\Domain;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** Anything a carrier refused or failed. The message is safe to show to staff. */
final class CarrierError extends \RuntimeException {

	public function __construct(
		public readonly string $carrier_code,
		string $message,
		public readonly bool $retryable = false
	) {
		parent::__construct( $message );
	}
}
```

- [ ] **Step 4: Write the carrier contract and registry**

`anchor-shipping/src/Carriers/CarrierInterface.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Shipping\Carriers;

use Anchor\Shipping\Domain\LabelResult;
use Anchor\Shipping\Domain\ShipmentRequest;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * A shipping carrier. Register more with the `anchor_shipping_carriers` filter:
 *   add_filter( 'anchor_shipping_carriers', fn( $c ) => $c + [ 'fedex' => new FedexCarrier() ] );
 * Methods throw Domain\CarrierError on any carrier-side failure.
 */
interface CarrierInterface {

	public function id(): string;

	public function label(): string;

	/** 'labels' | 'void' | 'track' | 'rates' */
	public function supports( string $feature ): bool;

	/** @return array<string, string> service code => label */
	public function services(): array;

	/**
	 * Fields for the settings page, keyed by setting name.
	 * Each: [ 'label' => string, 'type' => 'text'|'secret'|'select', 'options' => array (select only) ].
	 *
	 * @return array<string, array>
	 */
	public function settings_fields(): array;

	public function create_label( ShipmentRequest $request ): LabelResult;

	public function void_label( string $shipment_id ): void;

	/** Phase 3. @return array */
	public function track( array $tracking_numbers ): array;

	/** Phase 4. @return array */
	public function rate( ShipmentRequest $request ): array;

	/** Public tracking page for a number. */
	public function tracking_url( string $tracking_number ): string;
}
```

`anchor-shipping/src/Carriers/CarrierRegistry.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Shipping\Carriers;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class CarrierRegistry {

	/** @var array<string, CarrierInterface>|null */
	private ?array $carriers = null;

	/** @return array<string, CarrierInterface> */
	public function all(): array {
		if ( null === $this->carriers ) {
			$list           = (array) \apply_filters( 'anchor_shipping_carriers', [ 'ups' => new Ups\UpsCarrier() ] );
			$this->carriers = array_filter( $list, static fn( $c ) => $c instanceof CarrierInterface );
		}
		return $this->carriers;
	}

	public function get( string $id ): ?CarrierInterface {
		return $this->all()[ $id ] ?? null;
	}

	public function reset(): void {
		$this->carriers = null;
	}
}
```

Create a stub `anchor-shipping/src/Carriers/Ups/UpsCarrier.php` so the registry resolves (Task 6 replaces the bodies):

```php
<?php
declare(strict_types=1);

namespace Anchor\Shipping\Carriers\Ups;

use Anchor\Shipping\Carriers\CarrierInterface;
use Anchor\Shipping\Domain\CarrierError;
use Anchor\Shipping\Domain\LabelResult;
use Anchor\Shipping\Domain\ShipmentRequest;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class UpsCarrier implements CarrierInterface {
	public function id(): string { return 'ups'; }
	public function label(): string { return 'UPS'; }
	public function supports( string $feature ): bool { return false; }
	public function services(): array { return []; }
	public function settings_fields(): array { return []; }
	public function create_label( ShipmentRequest $request ): LabelResult { throw new CarrierError( 'unsupported', 'Not implemented yet.' ); }
	public function void_label( string $shipment_id ): void { throw new CarrierError( 'unsupported', 'Not implemented yet.' ); }
	public function track( array $tracking_numbers ): array { throw new CarrierError( 'unsupported', 'Tracking arrives in phase 3.' ); }
	public function rate( ShipmentRequest $request ): array { throw new CarrierError( 'unsupported', 'Rates arrive in phase 4.' ); }
	public function tracking_url( string $tracking_number ): string { return 'https://www.ups.com/track?tracknum=' . rawurlencode( $tracking_number ); }
}
```

- [ ] **Step 5: Wire it**

In `Module`: add `public Carriers\CarrierRegistry $carriers;` and, below the "Later tasks" line, `$this->carriers = new Carriers\CarrierRegistry();`.

- [ ] **Step 6: Run tests**

Run: `vendor/bin/phpunit tests/test-shipping-domain.php`
Expected: PASS (5 tests).

- [ ] **Step 7: Commit**

```bash
composer dump-autoload --no-dev
git add anchor-shipping/ tests/test-shipping-domain.php
git commit -m "feat(shipping): domain value objects, carrier contract and registry

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Settings store

**Files:**
- Create: `anchor-shipping/src/Support/Settings.php`
- Test: `tests/test-shipping-settings.php`

**Interfaces:**
- Consumes: `Address` (Task 2).
- Produces: `Settings::OPTION = 'anchor_shipping_settings'`; `defaults(): array`; `all(): array`; `save(array $s): void`; `carrier(string $id): array` (constants applied); `constant_name(string $carrier, string $key): string`; `is_constant(string $carrier, string $key): bool`; `ship_from(): Address`; `boxes(): array<string, array{id,name,length,width,height,empty_weight,max_weight}>` keyed by id; `auto_label(): bool`; `inbox(): string`.
- Settings keys (exact): `default_carrier`, `default_service`, `label_format` (`GIF`|`ZPL`), `inbox`, `auto_label` (bool), `ship_from` (name, company, phone, line1, line2, city, state, postcode, country), `boxes` (list), `carriers` (`ups` => environment, client_id, client_secret, account), `insurance` (mode `off`|`customer`|`auto`, threshold float, fee_per_100 string), `signature` (mode `off`|`customer`|`auto`, threshold float, fee string).

- [ ] **Step 1: Write the failing tests**

`tests/test-shipping-settings.php`:

```php
<?php
use Anchor\Shipping\Support\Settings;

class Test_Shipping_Settings extends Anchor_Shipping_TestCase {

	public function test_defaults_are_safe() {
		$s = Settings::all();
		$this->assertFalse( $s['auto_label'] );
		$this->assertSame( 'sandbox', $s['carriers']['ups']['environment'] );
		$this->assertSame( 'off', $s['insurance']['mode'] );
		$this->assertSame( 'off', $s['signature']['mode'] );
		$this->assertSame( 'GIF', $s['label_format'] );
	}

	public function test_saved_boxes_replace_defaults_and_are_keyed_by_id() {
		$this->configure_ups();
		$this->assertSame( [ 'small' ], array_keys( Settings::boxes() ) );
	}

	public function test_ship_from_falls_back_to_the_woo_store_address() {
		update_option( 'woocommerce_store_address', '400 North Ashley Drive' );
		update_option( 'woocommerce_store_city', 'Tampa' );
		update_option( 'woocommerce_store_postcode', '33602' );
		update_option( 'woocommerce_default_country', 'US:FL' );
		update_option( 'blogname', 'DEKA Dental Lasers' );
		$a = Settings::ship_from();
		$this->assertSame( '400 North Ashley Drive', $a->line1 );
		$this->assertSame( 'FL', $a->state );
		$this->assertSame( 'US', $a->country );
		$this->assertSame( 'DEKA Dental Lasers', $a->company );
	}

	public function test_constants_override_saved_credentials() {
		// A throwaway carrier id: constants can't be undefined, so never define a real UPS one in tests.
		$this->assertSame( 'ANCHOR_SHIPPING_UPS_CLIENT_SECRET', Settings::constant_name( 'ups', 'client_secret' ) );
		Settings::save( [ 'carriers' => [ 'zzconst' => [ 'account' => 'SAVED' ] ] ] );
		$this->assertFalse( Settings::is_constant( 'zzconst', 'account' ) );
		$this->assertSame( 'SAVED', Settings::carrier( 'zzconst' )['account'] );
		if ( ! defined( 'ANCHOR_SHIPPING_ZZCONST_ACCOUNT' ) ) {
			define( 'ANCHOR_SHIPPING_ZZCONST_ACCOUNT', 'FROMCONST' );
		}
		$this->assertTrue( Settings::is_constant( 'zzconst', 'account' ) );
		$this->assertSame( 'FROMCONST', Settings::carrier( 'zzconst' )['account'] );
	}

	public function test_inbox_falls_back_to_admin_email() {
		update_option( 'admin_email', 'admin@example.com' );
		$this->assertSame( 'admin@example.com', Settings::inbox() );
		$this->configure_ups();
		$this->assertSame( 'shipping@example.com', Settings::inbox() );
	}
}
```

(Tests never define a real `ANCHOR_SHIPPING_UPS_*` constant — constants can't be undefined and would leak into every later test in the process.)

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit tests/test-shipping-settings.php`
Expected: FAIL — `Class "Anchor\Shipping\Support\Settings" not found`.

- [ ] **Step 3: Implement**

`anchor-shipping/src/Support/Settings.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Shipping\Support;

use Anchor\Shipping\Domain\Address;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * The module's single option. Carrier credentials can be pinned in wp-config.php
 * as ANCHOR_SHIPPING_{CARRIER}_{KEY}; a defined constant always wins.
 */
final class Settings {

	public const OPTION = 'anchor_shipping_settings';

	public static function defaults(): array {
		return [
			'default_carrier' => 'ups',
			'default_service' => '03',
			'label_format'    => 'GIF',
			'inbox'           => '',
			'auto_label'      => false,
			'ship_from'       => [ 'name' => '', 'company' => '', 'phone' => '', 'line1' => '', 'line2' => '', 'city' => '', 'state' => '', 'postcode' => '', 'country' => '' ],
			'boxes'           => [],
			'carriers'        => [ 'ups' => [ 'environment' => 'sandbox', 'client_id' => '', 'client_secret' => '', 'account' => '' ] ],
			'insurance'       => [ 'mode' => 'off', 'threshold' => 100.0, 'fee_per_100' => '' ],
			'signature'       => [ 'mode' => 'off', 'threshold' => 0.0, 'fee' => '' ],
		];
	}

	public static function all(): array {
		$stored = \get_option( self::OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];
		$all    = array_replace_recursive( self::defaults(), $stored );
		if ( isset( $stored['boxes'] ) ) {
			$all['boxes'] = array_values( (array) $stored['boxes'] ); // a list: never merge by index
		}
		$all['auto_label'] = (bool) $all['auto_label'];
		return $all;
	}

	public static function save( array $settings ): void {
		\update_option( self::OPTION, $settings, false );
	}

	public static function constant_name( string $carrier, string $key ): string {
		return strtoupper( "ANCHOR_SHIPPING_{$carrier}_{$key}" );
	}

	public static function is_constant( string $carrier, string $key ): bool {
		return \defined( self::constant_name( $carrier, $key ) );
	}

	public static function carrier( string $id ): array {
		$c = self::all()['carriers'][ $id ] ?? [];
		foreach ( array_keys( $c + [ 'environment' => '', 'client_id' => '', 'client_secret' => '', 'account' => '' ] ) as $key ) {
			if ( self::is_constant( $id, $key ) ) {
				$c[ $key ] = (string) \constant( self::constant_name( $id, $key ) );
			}
		}
		return array_map( 'strval', $c );
	}

	/** Configured ship-from, each blank field filled from the Woo store address. */
	public static function ship_from(): Address {
		$s                 = self::all()['ship_from'];
		[ $country, $state ] = array_pad( explode( ':', (string) \get_option( 'woocommerce_default_country', '' ) ), 2, '' );
		$pick              = static fn( string $k, string $fallback ): string => '' !== (string) ( $s[ $k ] ?? '' ) ? (string) $s[ $k ] : $fallback;
		return new Address(
			$pick( 'name', 'Shipping' ),
			$pick( 'company', (string) \get_option( 'blogname', '' ) ),
			$pick( 'line1', (string) \get_option( 'woocommerce_store_address', '' ) ),
			$pick( 'line2', (string) \get_option( 'woocommerce_store_address_2', '' ) ),
			$pick( 'city', (string) \get_option( 'woocommerce_store_city', '' ) ),
			$pick( 'state', $state ),
			$pick( 'postcode', (string) \get_option( 'woocommerce_store_postcode', '' ) ),
			$pick( 'country', $country ),
			$pick( 'phone', '' )
		);
	}

	/** @return array<string, array> keyed by box id */
	public static function boxes(): array {
		$out = [];
		foreach ( self::all()['boxes'] as $b ) {
			if ( ! empty( $b['id'] ) ) {
				$out[ (string) $b['id'] ] = $b;
			}
		}
		return $out;
	}

	public static function auto_label(): bool {
		return self::all()['auto_label'];
	}

	public static function inbox(): string {
		$inbox = trim( (string) self::all()['inbox'] );
		return '' !== $inbox ? $inbox : (string) \get_option( 'admin_email' );
	}
}
```

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit tests/test-shipping-settings.php`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
composer dump-autoload --no-dev
git add anchor-shipping/src/Support/Settings.php tests/test-shipping-settings.php
git commit -m "feat(shipping): settings store with wp-config constant overrides

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Settings admin page

**Files:**
- Create: `anchor-shipping/src/Admin/SettingsPage.php`
- Modify: `anchor-shipping/src/Carriers/Ups/UpsCarrier.php` (`settings_fields()`, `services()` real values — the rest stays stubbed until Task 6)
- Modify: `anchor-shipping/anchor-shipping.php` (instantiate in `is_admin()`)
- Test: `tests/test-shipping-settings-page.php`

**Interfaces:**
- Consumes: `Settings` (Task 3), `CarrierRegistry` (Task 2).
- Produces: `SettingsPage::SLUG = 'anchor-shipping'`; `SettingsPage::sanitize(array $post, array $current): array` (pure, testable); `render(): void`; `handle_save(): void` on `admin_post_anchor_shipping_save`. Page lives at `admin.php?page=anchor-shipping` under the WooCommerce menu.

- [ ] **Step 1: Fill in UPS services and fields**

In `UpsCarrier` replace `services()` and `settings_fields()`:

```php
	public function services(): array {
		return [
			'03' => 'UPS Ground',
			'12' => 'UPS 3 Day Select',
			'02' => 'UPS 2nd Day Air',
			'13' => 'UPS Next Day Air Saver',
			'01' => 'UPS Next Day Air',
			'14' => 'UPS Next Day Air Early',
		];
	}

	public function settings_fields(): array {
		return [
			'environment'   => [ 'label' => 'Environment', 'type' => 'select', 'options' => [ 'sandbox' => 'Sandbox (UPS test server — no charges)', 'production' => 'Production (real labels, billed)' ] ],
			'client_id'     => [ 'label' => 'Client ID', 'type' => 'text' ],
			'client_secret' => [ 'label' => 'Client Secret', 'type' => 'secret' ],
			'account'       => [ 'label' => 'Account (shipper) number', 'type' => 'text' ],
		];
	}
```

- [ ] **Step 2: Write the failing tests**

`tests/test-shipping-settings-page.php`:

```php
<?php
use Anchor\Shipping\Admin\SettingsPage;
use Anchor\Shipping\Module;
use Anchor\Shipping\Support\Settings;

class Test_Shipping_Settings_Page extends Anchor_Shipping_TestCase {

	private function page(): SettingsPage {
		return new SettingsPage( Module::instance()->carriers );
	}

	public function test_blank_secret_keeps_the_stored_secret() {
		$this->configure_ups();
		$out = $this->page()->sanitize( [ 'carriers' => [ 'ups' => [ 'client_secret' => '', 'client_id' => 'new-id' ] ] ], Settings::all() );
		$this->assertSame( 'csecret', $out['carriers']['ups']['client_secret'] );
		$this->assertSame( 'new-id', $out['carriers']['ups']['client_id'] );
	}

	public function test_boxes_rows_are_parsed_and_blank_rows_dropped() {
		$out = $this->page()->sanitize(
			[ 'boxes' => [
				[ 'name' => 'Small Box', 'length' => '25.4', 'width' => '20.3', 'height' => '10.2', 'empty_weight' => '0.2', 'max_weight' => '10' ],
				[ 'name' => '', 'length' => '', 'width' => '', 'height' => '' ],
			] ],
			Settings::all()
		);
		$this->assertCount( 1, $out['boxes'] );
		$this->assertSame( 'small-box', $out['boxes'][0]['id'] );
		$this->assertSame( 25.4, $out['boxes'][0]['length'] );
	}

	public function test_modes_and_flags_are_whitelisted() {
		$out = $this->page()->sanitize( [ 'insurance' => [ 'mode' => 'evil' ], 'signature' => [ 'mode' => 'auto' ], 'auto_label' => '1', 'label_format' => 'PDF' ], Settings::all() );
		$this->assertSame( 'off', $out['insurance']['mode'] );
		$this->assertSame( 'auto', $out['signature']['mode'] );
		$this->assertTrue( $out['auto_label'] );
		$this->assertSame( 'GIF', $out['label_format'] );
		$this->assertFalse( $this->page()->sanitize( [], Settings::all() )['auto_label'], 'unticked checkbox posts nothing' );
	}

	public function test_render_never_prints_the_secret() {
		$this->configure_ups( [ 'carriers' => [ 'ups' => [ 'client_secret' => 'TOP-SECRET-VALUE' ] ] ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		ob_start();
		$this->page()->render();
		$html = ob_get_clean();
		$this->assertStringNotContainsString( 'TOP-SECRET-VALUE', $html );
		$this->assertStringContainsString( 'name="anchor_shipping[carriers][ups][client_secret]"', $html );
		$this->assertStringContainsString( 'K877V9', $html, 'non-secret values are shown' );
	}
}
```

- [ ] **Step 3: Run to verify failure**

Run: `vendor/bin/phpunit tests/test-shipping-settings-page.php`
Expected: FAIL — `Class "Anchor\Shipping\Admin\SettingsPage" not found`.

- [ ] **Step 4: Implement**

`anchor-shipping/src/Admin/SettingsPage.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Shipping\Admin;

use Anchor\Shipping\Carriers\CarrierRegistry;
use Anchor\Shipping\Support\Settings;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** WooCommerce > Anchor Shipping. Plain form posted to admin-post.php. */
final class SettingsPage {

	public const SLUG = 'anchor-shipping';
	private const MODES = [ 'off', 'customer', 'auto' ];

	public function __construct( private CarrierRegistry $carriers ) {
		\add_action( 'admin_menu', [ $this, 'menu' ], 60 );
		\add_action( 'admin_post_anchor_shipping_save', [ $this, 'handle_save' ] );
	}

	public function menu(): void {
		\add_submenu_page( 'woocommerce', \__( 'Anchor Shipping', 'anchor-schema' ), \__( 'Anchor Shipping', 'anchor-schema' ), 'manage_woocommerce', self::SLUG, [ $this, 'render' ] );
	}

	public function handle_save(): void {
		if ( ! \current_user_can( 'manage_woocommerce' ) ) {
			\wp_die( \esc_html__( 'You do not have permission to change shipping settings.', 'anchor-schema' ), 403 );
		}
		\check_admin_referer( 'anchor_shipping_settings' );
		$post = isset( $_POST['anchor_shipping'] ) ? \wp_unslash( (array) $_POST['anchor_shipping'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		Settings::save( $this->sanitize( $post, Settings::all() ) );
		\wp_safe_redirect( \add_query_arg( [ 'page' => self::SLUG, 'updated' => 1 ], \admin_url( 'admin.php' ) ) );
		exit;
	}

	public function sanitize( array $post, array $current ): array {
		$out = $current;
		$txt = static fn( $v ): string => \sanitize_text_field( (string) $v );
		$num = static fn( $v ): float => max( 0.0, (float) $v );

		$out['default_carrier'] = isset( $post['default_carrier'] ) && $this->carriers->get( (string) $post['default_carrier'] ) ? (string) $post['default_carrier'] : $current['default_carrier'];
		$out['default_service'] = isset( $post['default_service'] ) ? $txt( $post['default_service'] ) : $current['default_service'];
		$out['label_format']    = in_array( $post['label_format'] ?? '', [ 'GIF', 'ZPL' ], true ) ? $post['label_format'] : 'GIF';
		$out['inbox']           = implode( ', ', array_filter( array_map( 'sanitize_email', array_map( 'trim', explode( ',', (string) ( $post['inbox'] ?? $current['inbox'] ) ) ) ) ) );
		$out['auto_label']      = ! empty( $post['auto_label'] );

		foreach ( array_keys( $current['ship_from'] ) as $k ) {
			$out['ship_from'][ $k ] = $txt( $post['ship_from'][ $k ] ?? $current['ship_from'][ $k ] );
		}

		$boxes = [];
		foreach ( (array) ( $post['boxes'] ?? $current['boxes'] ) as $row ) {
			$name = $txt( $row['name'] ?? '' );
			if ( '' === $name || $num( $row['length'] ?? 0 ) <= 0 || $num( $row['width'] ?? 0 ) <= 0 || $num( $row['height'] ?? 0 ) <= 0 ) {
				continue;
			}
			$boxes[] = [
				'id'           => \sanitize_key( str_replace( ' ', '-', strtolower( $name ) ) ),
				'name'         => $name,
				'length'       => $num( $row['length'] ),
				'width'        => $num( $row['width'] ),
				'height'       => $num( $row['height'] ),
				'empty_weight' => $num( $row['empty_weight'] ?? 0 ),
				'max_weight'   => $num( $row['max_weight'] ?? 0 ),
			];
		}
		$out['boxes'] = $boxes;

		foreach ( $this->carriers->all() as $id => $carrier ) {
			foreach ( $carrier->settings_fields() as $key => $field ) {
				$submitted = $post['carriers'][ $id ][ $key ] ?? null;
				if ( null === $submitted || ( 'secret' === $field['type'] && '' === $submitted ) ) {
					continue; // blank secret = keep what is stored
				}
				if ( 'select' === $field['type'] && ! isset( $field['options'][ $submitted ] ) ) {
					continue;
				}
				$out['carriers'][ $id ][ $key ] = $txt( $submitted );
			}
		}

		$out['insurance']['mode']        = in_array( $post['insurance']['mode'] ?? '', self::MODES, true ) ? $post['insurance']['mode'] : 'off';
		$out['insurance']['threshold']   = $num( $post['insurance']['threshold'] ?? $current['insurance']['threshold'] );
		$out['insurance']['fee_per_100'] = '' === ( $post['insurance']['fee_per_100'] ?? '' ) ? '' : (string) $num( $post['insurance']['fee_per_100'] );
		$out['signature']['mode']        = in_array( $post['signature']['mode'] ?? '', self::MODES, true ) ? $post['signature']['mode'] : 'off';
		$out['signature']['threshold']   = $num( $post['signature']['threshold'] ?? $current['signature']['threshold'] );
		$out['signature']['fee']         = '' === ( $post['signature']['fee'] ?? '' ) ? '' : (string) $num( $post['signature']['fee'] );

		return $out;
	}

	public function render(): void {
		if ( ! \current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$s     = Settings::all();
		$name  = static fn( string $path ): string => 'anchor_shipping' . $path;
		$wu    = \get_option( 'woocommerce_weight_unit' );
		$du    = \get_option( 'woocommerce_dimension_unit' );
		$modes = [ 'off' => \__( 'Off', 'anchor-schema' ), 'customer' => \__( 'Customer chooses at checkout (adds a fee)', 'anchor-schema' ), 'auto' => \__( 'Automatic above a threshold', 'anchor-schema' ) ];
		?>
		<div class="wrap anchor-shipping-settings">
			<h1><?php \esc_html_e( 'Anchor Shipping', 'anchor-schema' ); ?></h1>
			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success"><p><?php \esc_html_e( 'Settings saved.', 'anchor-schema' ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo \esc_url( \admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="anchor_shipping_save">
				<?php \wp_nonce_field( 'anchor_shipping_settings' ); ?>

				<h2><?php \esc_html_e( 'Labels', 'anchor-schema' ); ?></h2>
				<table class="form-table">
					<tr><th><?php \esc_html_e( 'Create labels automatically when an order is paid', 'anchor-schema' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo \esc_attr( $name( '[auto_label]' ) ); ?>" value="1" <?php \checked( $s['auto_label'] ); ?>> <?php \esc_html_e( 'When every item has a weight and a default box. Otherwise, and when this is off, the shipping inbox gets a "Ready to ship" email with a Create label button.', 'anchor-schema' ); ?></label></td></tr>
					<tr><th><?php \esc_html_e( 'Shipping inbox', 'anchor-schema' ); ?></th>
						<td><input type="text" class="regular-text" name="<?php echo \esc_attr( $name( '[inbox]' ) ); ?>" value="<?php echo \esc_attr( $s['inbox'] ); ?>" placeholder="<?php echo \esc_attr( (string) \get_option( 'admin_email' ) ); ?>"><p class="description"><?php \esc_html_e( 'Comma-separate several addresses.', 'anchor-schema' ); ?></p></td></tr>
					<tr><th><?php \esc_html_e( 'Default carrier / service', 'anchor-schema' ); ?></th>
						<td><select name="<?php echo \esc_attr( $name( '[default_carrier]' ) ); ?>"><?php foreach ( $this->carriers->all() as $id => $c ) : ?><option value="<?php echo \esc_attr( $id ); ?>" <?php \selected( $s['default_carrier'], $id ); ?>><?php echo \esc_html( $c->label() ); ?></option><?php endforeach; ?></select>
						<select name="<?php echo \esc_attr( $name( '[default_service]' ) ); ?>"><?php foreach ( $this->carriers->all() as $c ) : foreach ( $c->services() as $code => $label ) : ?><option value="<?php echo \esc_attr( (string) $code ); ?>" <?php \selected( $s['default_service'], (string) $code ); ?>><?php echo \esc_html( $label ); ?></option><?php endforeach; endforeach; ?></select></td></tr>
					<tr><th><?php \esc_html_e( 'Label format', 'anchor-schema' ); ?></th>
						<td><select name="<?php echo \esc_attr( $name( '[label_format]' ) ); ?>"><option value="GIF" <?php \selected( $s['label_format'], 'GIF' ); ?>><?php \esc_html_e( '4×6 PDF (any printer)', 'anchor-schema' ); ?></option><option value="ZPL" <?php \selected( $s['label_format'], 'ZPL' ); ?>><?php \esc_html_e( 'ZPL (Zebra thermal printer)', 'anchor-schema' ); ?></option></select></td></tr>
				</table>

				<h2><?php \esc_html_e( 'Ship from', 'anchor-schema' ); ?></h2>
				<p class="description"><?php \esc_html_e( 'Blank fields use the WooCommerce store address.', 'anchor-schema' ); ?></p>
				<table class="form-table">
					<?php foreach ( [ 'name' => 'Contact name', 'company' => 'Company', 'phone' => 'Phone (required by UPS)', 'line1' => 'Address line 1', 'line2' => 'Address line 2', 'city' => 'City', 'state' => 'State', 'postcode' => 'ZIP', 'country' => 'Country code' ] as $k => $label ) : ?>
						<tr><th><?php echo \esc_html( $label ); ?></th><td><input type="text" class="regular-text" name="<?php echo \esc_attr( $name( "[ship_from][{$k}]" ) ); ?>" value="<?php echo \esc_attr( (string) $s['ship_from'][ $k ] ); ?>"></td></tr>
					<?php endforeach; ?>
				</table>

				<h2><?php \esc_html_e( 'Boxes', 'anchor-schema' ); ?></h2>
				<p class="description"><?php echo \esc_html( sprintf( /* translators: 1: dimension unit, 2: weight unit */ \__( 'Dimensions in %1$s, weights in %2$s. Assign a default box to each product on its Shipping tab.', 'anchor-schema' ), $du, $wu ) ); ?></p>
				<table class="widefat striped anchor-shipping-boxes">
					<thead><tr><th>Name</th><th>Length</th><th>Width</th><th>Height</th><th>Empty weight</th><th>Max weight</th></tr></thead>
					<tbody>
					<?php foreach ( array_merge( $s['boxes'], [ [], [] ] ) as $i => $b ) : ?>
						<tr><?php foreach ( [ 'name', 'length', 'width', 'height', 'empty_weight', 'max_weight' ] as $k ) : ?>
							<td><input type="text" name="<?php echo \esc_attr( $name( "[boxes][{$i}][{$k}]" ) ); ?>" value="<?php echo \esc_attr( (string) ( $b[ $k ] ?? '' ) ); ?>"></td>
						<?php endforeach; ?></tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<h2><?php \esc_html_e( 'Protection', 'anchor-schema' ); ?></h2>
				<table class="form-table">
					<tr><th><?php \esc_html_e( 'Insurance (declared value)', 'anchor-schema' ); ?></th><td>
						<select name="<?php echo \esc_attr( $name( '[insurance][mode]' ) ); ?>"><?php foreach ( $modes as $k => $l ) : ?><option value="<?php echo \esc_attr( $k ); ?>" <?php \selected( $s['insurance']['mode'], $k ); ?>><?php echo \esc_html( $l ); ?></option><?php endforeach; ?></select>
						<p><label><?php \esc_html_e( 'Automatic above order value', 'anchor-schema' ); ?> <input type="text" size="6" name="<?php echo \esc_attr( $name( '[insurance][threshold]' ) ); ?>" value="<?php echo \esc_attr( (string) $s['insurance']['threshold'] ); ?>"></label></p>
						<p><label><?php \esc_html_e( 'Customer fee per $100 over $100', 'anchor-schema' ); ?> <input type="text" size="6" name="<?php echo \esc_attr( $name( '[insurance][fee_per_100]' ) ); ?>" value="<?php echo \esc_attr( (string) $s['insurance']['fee_per_100'] ); ?>"></label></p>
					</td></tr>
					<tr><th><?php \esc_html_e( 'Signature required', 'anchor-schema' ); ?></th><td>
						<select name="<?php echo \esc_attr( $name( '[signature][mode]' ) ); ?>"><?php foreach ( $modes as $k => $l ) : ?><option value="<?php echo \esc_attr( $k ); ?>" <?php \selected( $s['signature']['mode'], $k ); ?>><?php echo \esc_html( $l ); ?></option><?php endforeach; ?></select>
						<p><label><?php \esc_html_e( 'Automatic above order value', 'anchor-schema' ); ?> <input type="text" size="6" name="<?php echo \esc_attr( $name( '[signature][threshold]' ) ); ?>" value="<?php echo \esc_attr( (string) $s['signature']['threshold'] ); ?>"></label></p>
						<p><label><?php \esc_html_e( 'Customer fee', 'anchor-schema' ); ?> <input type="text" size="6" name="<?php echo \esc_attr( $name( '[signature][fee]' ) ); ?>" value="<?php echo \esc_attr( (string) $s['signature']['fee'] ); ?>"></label></p>
					</td></tr>
				</table>

				<?php foreach ( $this->carriers->all() as $id => $carrier ) : ?>
					<h2><?php echo \esc_html( $carrier->label() ); ?></h2>
					<table class="form-table">
						<?php foreach ( $carrier->settings_fields() as $key => $field ) :
							$locked = Settings::is_constant( $id, $key );
							$value  = Settings::carrier( $id )[ $key ] ?? '';
							$fname  = $name( "[carriers][{$id}][{$key}]" );
							?>
							<tr><th><?php echo \esc_html( $field['label'] ); ?></th><td>
								<?php if ( $locked ) : ?>
									<em><?php echo \esc_html( sprintf( /* translators: %s: constant name */ \__( 'Set in wp-config.php (%s)', 'anchor-schema' ), Settings::constant_name( $id, $key ) ) ); ?></em>
								<?php elseif ( 'secret' === $field['type'] ) : ?>
									<input type="password" class="regular-text" autocomplete="off" name="<?php echo \esc_attr( $fname ); ?>" value="" placeholder="<?php echo '' !== $value ? \esc_attr__( '•••••• saved — leave blank to keep', 'anchor-schema' ) : ''; ?>">
								<?php elseif ( 'select' === $field['type'] ) : ?>
									<select name="<?php echo \esc_attr( $fname ); ?>"><?php foreach ( $field['options'] as $ok => $ol ) : ?><option value="<?php echo \esc_attr( (string) $ok ); ?>" <?php \selected( $value, (string) $ok ); ?>><?php echo \esc_html( $ol ); ?></option><?php endforeach; ?></select>
								<?php else : ?>
									<input type="text" class="regular-text" name="<?php echo \esc_attr( $fname ); ?>" value="<?php echo \esc_attr( $value ); ?>">
								<?php endif; ?>
							</td></tr>
						<?php endforeach; ?>
					</table>
				<?php endforeach; ?>

				<?php \submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
```

- [ ] **Step 5: Wire it**

In `Module` below the carriers line: `if ( \is_admin() ) { new Admin\SettingsPage( $this->carriers ); }`

- [ ] **Step 6: Run tests**

Run: `vendor/bin/phpunit tests/test-shipping-settings-page.php`
Expected: PASS (4 tests).

- [ ] **Step 7: Commit**

```bash
composer dump-autoload --no-dev
git add anchor-shipping/ tests/test-shipping-settings-page.php
git commit -m "feat(shipping): settings page — labels, ship-from, boxes, protection, carrier credentials

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: UPS HTTP client

**Files:**
- Create: `anchor-shipping/src/Carriers/Ups/UpsClient.php`
- Test: `tests/test-shipping-ups-client.php`

**Interfaces:**
- Consumes: `CarrierError` (Task 2).
- Produces: `UpsClient::HOSTS` (`sandbox` / `production`); `__construct(string $client_id, string $client_secret, string $environment)`; `request(string $method, string $path, ?array $body = null): array` (decoded JSON); `host(): string`; `forget_token(): void`. Token cached in transient `anchor_shipping_ups_token_{md5(env|client_id)}` for `expires_in − 300` s (minimum 60).
- Error mapping: `WP_Error` → `CarrierError('http', …, retryable: true)`; 5xx/429 → retryable; 401 twice → `CarrierError('auth', …, false)`; other 4xx → `CarrierError(<ups code>, <ups message>, false)`.

- [ ] **Step 1: Write the failing tests**

`tests/test-shipping-ups-client.php`:

```php
<?php
use Anchor\Shipping\Carriers\Ups\UpsClient;
use Anchor\Shipping\Domain\CarrierError;

class Test_Shipping_Ups_Client extends Anchor_Shipping_TestCase {

	private function client( string $env = 'sandbox' ): UpsClient {
		return new UpsClient( 'cid', 'csecret', $env );
	}

	public function test_fetches_and_caches_a_token_against_the_sandbox_host() {
		$this->queue_token();
		$this->queue_response( 200, [ 'ok' => 1 ] );
		$this->queue_response( 200, [ 'ok' => 2 ] );
		$c = $this->client();
		$this->assertSame( [ 'ok' => 1 ], $c->request( 'GET', '/api/x' ) );
		$this->assertSame( [ 'ok' => 2 ], $c->request( 'GET', '/api/x' ) );
		$this->assertCount( 3, $this->requests, 'one token call, two API calls' );
		$this->assertSame( 'https://wwwcie.ups.com/security/v1/oauth/token', $this->requests[0]['url'] );
		$this->assertSame( 'Basic ' . base64_encode( 'cid:csecret' ), $this->requests[0]['args']['headers']['Authorization'] );
		$this->assertSame( 'https://wwwcie.ups.com/api/x', $this->requests[1]['url'] );
		$this->assertSame( 'Bearer tok-0', $this->requests[1]['args']['headers']['Authorization'] );
	}

	public function test_production_uses_the_production_host() {
		$this->assertSame( 'https://onlinetools.ups.com', $this->client( 'production' )->host() );
		$this->assertSame( 'https://wwwcie.ups.com', $this->client( 'bogus' )->host(), 'unknown env falls back to sandbox' );
	}

	public function test_401_refreshes_token_and_retries_once() {
		$this->queue_token();
		$this->queue_response( 401, [ 'response' => [ 'errors' => [ [ 'code' => '250002', 'message' => 'Invalid Authentication Information.' ] ] ] ] );
		$this->queue_token();
		$this->queue_response( 200, [ 'ok' => true ] );
		$this->assertSame( [ 'ok' => true ], $this->client()->request( 'POST', '/api/y', [ 'a' => 1 ] ) );
		$this->assertCount( 4, $this->requests );
	}

	public function test_second_401_is_a_non_retryable_auth_error() {
		$this->queue_token();
		$this->queue_response( 401, '{}' );
		$this->queue_token();
		$this->queue_response( 401, '{}' );
		try {
			$this->client()->request( 'GET', '/api/z' );
			$this->fail( 'expected CarrierError' );
		} catch ( CarrierError $e ) {
			$this->assertSame( 'auth', $e->carrier_code );
			$this->assertFalse( $e->retryable );
			$this->assertStringNotContainsString( 'csecret', $e->getMessage() );
		}
	}

	public function test_ups_error_body_becomes_a_carrier_error_and_5xx_is_retryable() {
		$this->queue_token();
		$this->queue_response( 400, [ 'response' => [ 'errors' => [ [ 'code' => '120100', 'message' => 'Missing or invalid shipper number' ] ] ] ] );
		try {
			$this->client()->request( 'POST', '/api/ship', [] );
			$this->fail( 'expected CarrierError' );
		} catch ( CarrierError $e ) {
			$this->assertSame( '120100', $e->carrier_code );
			$this->assertSame( 'Missing or invalid shipper number', $e->getMessage() );
			$this->assertFalse( $e->retryable );
		}
		$this->queue_response( 503, 'Service Unavailable' );
		try {
			$this->client()->request( 'POST', '/api/ship', [] ); // token still cached
			$this->fail( 'expected CarrierError' );
		} catch ( CarrierError $e ) {
			$this->assertTrue( $e->retryable );
		}
	}

	public function test_failed_token_call_is_an_auth_error() {
		$this->queue_response( 401, [ 'response' => [ 'errors' => [ [ 'code' => '10401', 'message' => 'ClientId is Invalid' ] ] ] ] );
		$this->expectException( CarrierError::class );
		$this->expectExceptionMessage( 'ClientId is Invalid' );
		$this->client()->request( 'GET', '/api/x' );
	}
}
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit tests/test-shipping-ups-client.php`
Expected: FAIL — `Class "Anchor\Shipping\Carriers\Ups\UpsClient" not found`.

- [ ] **Step 3: Implement**

`anchor-shipping/src/Carriers/Ups/UpsClient.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Shipping\Carriers\Ups;

use Anchor\Shipping\Domain\CarrierError;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** OAuth client-credentials + JSON over wp_remote_request. Never logs bodies or credentials. */
final class UpsClient {

	public const HOSTS = [
		'sandbox'    => 'https://wwwcie.ups.com',
		'production' => 'https://onlinetools.ups.com',
	];

	public function __construct(
		private string $client_id,
		private string $client_secret,
		private string $environment
	) {}

	public function host(): string {
		return self::HOSTS[ $this->environment ] ?? self::HOSTS['sandbox'];
	}

	public function request( string $method, string $path, ?array $body = null ): array {
		$res = $this->send( $method, $path, $body, $this->token() );
		if ( 401 === $res['code'] ) {
			$this->forget_token();
			$res = $this->send( $method, $path, $body, $this->token() );
			if ( 401 === $res['code'] ) {
				throw new CarrierError( 'auth', \__( 'UPS rejected the API credentials. Check the Client ID and Secret.', 'anchor-schema' ) );
			}
		}
		if ( $res['code'] >= 400 ) {
			throw $this->error( $res );
		}
		return $res['json'];
	}

	public function forget_token(): void {
		\delete_transient( $this->token_key() );
	}

	private function token_key(): string {
		return 'anchor_shipping_ups_token_' . md5( $this->environment . '|' . $this->client_id );
	}

	private function token(): string {
		$cached = \get_transient( $this->token_key() );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}
		$raw = \wp_remote_post(
			$this->host() . '/security/v1/oauth/token',
			[
				'timeout' => 20,
				'headers' => [
					'Authorization' => 'Basic ' . base64_encode( $this->client_id . ':' . $this->client_secret ),
					'Content-Type'  => 'application/x-www-form-urlencoded',
				],
				'body'    => [ 'grant_type' => 'client_credentials' ],
			]
		);
		$res = $this->normalize( $raw, '/security/v1/oauth/token' );
		if ( 200 !== $res['code'] || empty( $res['json']['access_token'] ) ) {
			$e = $this->error( $res );
			throw new CarrierError( 'auth', $e->getMessage(), $e->retryable );
		}
		$ttl = max( 60, (int) ( $res['json']['expires_in'] ?? 3600 ) - 300 );
		\set_transient( $this->token_key(), (string) $res['json']['access_token'], $ttl );
		return (string) $res['json']['access_token'];
	}

	private function send( string $method, string $path, ?array $body, string $token ): array {
		$args = [
			'method'  => $method,
			'timeout' => 30,
			'headers' => [
				'Authorization'  => 'Bearer ' . $token,
				'Content-Type'   => 'application/json',
				'transId'        => \wp_generate_password( 16, false ),
				'transactionSrc' => 'anchor-shipping',
			],
		];
		if ( null !== $body ) {
			$args['body'] = \wp_json_encode( $body );
		}
		return $this->normalize( \wp_remote_request( $this->host() . $path, $args ), $path );
	}

	/** @param array|\WP_Error $raw */
	private function normalize( $raw, string $path ): array {
		if ( \is_wp_error( $raw ) ) {
			$this->log( 'error', $path . ' transport error: ' . $raw->get_error_message() );
			throw new CarrierError( 'http', \__( 'Could not reach UPS. Try again in a few minutes.', 'anchor-schema' ), true );
		}
		$code = (int) \wp_remote_retrieve_response_code( $raw );
		$json = json_decode( (string) \wp_remote_retrieve_body( $raw ), true );
		$this->log( $code >= 400 ? 'warning' : 'debug', $path . ' → HTTP ' . $code );
		return [ 'code' => $code, 'json' => is_array( $json ) ? $json : [] ];
	}

	private function error( array $res ): CarrierError {
		$first     = $res['json']['response']['errors'][0] ?? [];
		$code      = (string) ( $first['code'] ?? 'http_' . $res['code'] );
		$message   = (string) ( $first['message'] ?? sprintf( /* translators: %d: HTTP status */ \__( 'UPS returned HTTP %d.', 'anchor-schema' ), $res['code'] ) );
		$retryable = $res['code'] >= 500 || 429 === $res['code'];
		return new CarrierError( $code, $message, $retryable );
	}

	private function log( string $level, string $message ): void {
		if ( \function_exists( 'wc_get_logger' ) ) {
			\wc_get_logger()->log( $level, '[ups ' . $this->environment . '] ' . $message, [ 'source' => 'anchor-shipping' ] );
		}
	}
}
```

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit tests/test-shipping-ups-client.php`
Expected: PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
composer dump-autoload --no-dev
git add anchor-shipping/src/Carriers/Ups/UpsClient.php tests/test-shipping-ups-client.php
git commit -m "feat(shipping): UPS OAuth client with token cache, 401 refresh and typed errors

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: UPS labels and void

**Files:**
- Modify: `anchor-shipping/src/Carriers/Ups/UpsCarrier.php` (replace stub bodies)
- Create: `tests/fixtures/shipping/ups-ship-single.json`, `ups-ship-multi.json`, `ups-void-ok.json`
- Test: `tests/test-shipping-ups-carrier.php`

**Interfaces:**
- Consumes: `UpsClient` (Task 5), `Settings::carrier('ups')`, `Settings::all()['label_format']` (Task 3), domain objects (Task 2).
- Produces: `UpsCarrier::__construct(?UpsClient $client = null)`; `supports()` true for `labels`, `void`; `create_label()` returns `LabelResult` with one `PackageLabel` per parcel (bytes = decoded GraphicImage; format `GIF`|`ZPL`); `void_label()`; `build_ship_body(ShipmentRequest, string $account, string $format): array` (public for tests).

- [ ] **Step 1: Write the fixtures**

`tests/fixtures/shipping/ups-ship-single.json`:

```json
{"ShipmentResponse":{"Response":{"ResponseStatus":{"Code":"1","Description":"Success"}},"ShipmentResults":{"ShipmentCharges":{"TotalCharges":{"CurrencyCode":"USD","MonetaryValue":"22.58"}},"NegotiatedRateCharges":{"TotalCharge":{"CurrencyCode":"USD","MonetaryValue":"11.66"}},"ShipmentIdentificationNumber":"1ZK877V90300000001","PackageResults":{"TrackingNumber":"1ZK877V90300000001","ShippingLabel":{"ImageFormat":{"Code":"GIF"},"GraphicImage":"R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7"}}}}}
```

`tests/fixtures/shipping/ups-ship-multi.json`:

```json
{"ShipmentResponse":{"Response":{"ResponseStatus":{"Code":"1","Description":"Success"}},"ShipmentResults":{"ShipmentCharges":{"TotalCharges":{"CurrencyCode":"USD","MonetaryValue":"40.10"}},"ShipmentIdentificationNumber":"1ZK877V90300000010","PackageResults":[{"TrackingNumber":"1ZK877V90300000010","ShippingLabel":{"ImageFormat":{"Code":"GIF"},"GraphicImage":"R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7"}},{"TrackingNumber":"1ZK877V90300000028","ShippingLabel":{"ImageFormat":{"Code":"GIF"},"GraphicImage":"R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7"}}]}}}
```

`tests/fixtures/shipping/ups-void-ok.json`:

```json
{"VoidShipmentResponse":{"Response":{"ResponseStatus":{"Code":"1","Description":"Success"}},"SummaryResult":{"Status":{"Code":"1","Description":"Voided"}}}}
```

- [ ] **Step 2: Write the failing tests**

`tests/test-shipping-ups-carrier.php`:

```php
<?php
use Anchor\Shipping\Carriers\Ups\UpsCarrier;
use Anchor\Shipping\Domain\Address;
use Anchor\Shipping\Domain\CarrierError;
use Anchor\Shipping\Domain\Parcel;
use Anchor\Shipping\Domain\ShipmentRequest;
use Anchor\Shipping\Support\Settings;

class Test_Shipping_Ups_Carrier extends Anchor_Shipping_TestCase {

	private function request( array $parcels = null, float $declared = 0.0, bool $signature = false ): ShipmentRequest {
		$from = new Address( 'Shipping', 'DEKA Test', '400 North Ashley Drive', '', 'Tampa', 'FL', '33602', 'US', '8133208285' );
		$to   = new Address( 'Pat Doe', 'Doe Dental', '1 Infinite Loop', '', 'Cupertino', 'CA', '95014', 'US', '5555555555' );
		return new ShipmentRequest( $from, $to, $parcels ?? [ new Parcel( 1.0, 25.4, 20.32, 10.16, 'small' ) ], '03', 'Order 123', $declared, $signature );
	}

	public function test_ship_body_bills_the_account_and_never_emails_the_customer() {
		$this->configure_ups();
		$account = Settings::carrier( 'ups' )['account'];
		$body    = ( new UpsCarrier() )->build_ship_body( $this->request(), $account, 'GIF' );
		$ship    = $body['ShipmentRequest']['Shipment'];

		$this->assertSame( $account, $ship['Shipper']['ShipperNumber'] );
		$this->assertSame( $account, $ship['PaymentInformation']['ShipmentCharge']['BillShipper']['AccountNumber'] );
		$this->assertSame( '03', $ship['Service']['Code'] );
		$this->assertSame( '2.2', $ship['Package'][0]['PackageWeight']['Weight'] );
		$this->assertSame( [ '10', '8', '4' ], [ $ship['Package'][0]['Dimensions']['Length'], $ship['Package'][0]['Dimensions']['Width'], $ship['Package'][0]['Dimensions']['Height'] ] );
		$this->assertSame( 'Order 123', $ship['Package'][0]['ReferenceNumber']['Value'] );
		$this->assertArrayNotHasKey( 'EMailAddress', $ship['ShipTo'] );
		$this->assertArrayNotHasKey( 'ShipmentServiceOptions', $ship );
		$this->assertArrayHasKey( 'NegotiatedRatesIndicator', $ship['ShipmentRatingOptions'] );
		$this->assertSame( 'GIF', $body['ShipmentRequest']['LabelSpecification']['LabelImageFormat']['Code'] );
	}

	public function test_declared_value_and_signature_become_package_service_options() {
		$body = ( new UpsCarrier() )->build_ship_body( $this->request( null, 1250.0, true ), 'K877V9', 'ZPL' );
		$pso  = $body['ShipmentRequest']['Shipment']['Package'][0]['PackageServiceOptions'];
		$this->assertSame( '1250.00', $pso['DeclaredValue']['MonetaryValue'] );
		$this->assertSame( '2', $pso['DeliveryConfirmation']['DCISType'] );
		$this->assertSame( 'ZPL', $body['ShipmentRequest']['LabelSpecification']['LabelImageFormat']['Code'] );
		$this->assertSame( [ 'Height' => '6', 'Width' => '4' ], $body['ShipmentRequest']['LabelSpecification']['LabelStockSize'] );
	}

	public function test_declared_value_on_multi_parcel_is_split_across_packages() {
		$parcels = [ new Parcel( 1, 10, 10, 10 ), new Parcel( 1, 10, 10, 10 ) ];
		$body    = ( new UpsCarrier() )->build_ship_body( $this->request( $parcels, 1001.0 ), 'K877V9', 'GIF' );
		$this->assertSame( '500.50', $body['ShipmentRequest']['Shipment']['Package'][0]['PackageServiceOptions']['DeclaredValue']['MonetaryValue'] );
		$this->assertSame( '500.50', $body['ShipmentRequest']['Shipment']['Package'][1]['PackageServiceOptions']['DeclaredValue']['MonetaryValue'] );
	}

	public function test_create_label_parses_single_package_with_negotiated_cost() {
		$this->configure_ups();
		$this->queue_token();
		$this->queue_response( 200, $this->fixture( 'ups-ship-single' ) );
		$r = ( new UpsCarrier() )->create_label( $this->request() );
		$this->assertSame( '1ZK877V90300000001', $r->shipment_id );
		$this->assertCount( 1, $r->packages );
		$this->assertSame( 'GIF', $r->packages[0]->format );
		$this->assertStringStartsWith( 'GIF89a', $r->packages[0]->bytes );
		$this->assertSame( 11.66, $r->cost );
		$this->assertStringEndsWith( '/api/shipments/v2409/ship', $this->requests[1]['url'] );
	}

	public function test_create_label_parses_multi_package_list() {
		$this->configure_ups();
		$this->queue_token();
		$this->queue_response( 200, $this->fixture( 'ups-ship-multi' ) );
		$r = ( new UpsCarrier() )->create_label( $this->request( [ new Parcel( 1, 10, 10, 10 ), new Parcel( 1, 10, 10, 10 ) ] ) );
		$this->assertSame( [ '1ZK877V90300000010', '1ZK877V90300000028' ], array_map( static fn( $p ) => $p->tracking_number, $r->packages ) );
		$this->assertSame( 40.10, $r->cost, 'falls back to published total when no negotiated rate' );
	}

	public function test_missing_credentials_fail_before_any_http() {
		$this->expectException( CarrierError::class );
		try {
			( new UpsCarrier() )->create_label( $this->request() );
		} finally {
			$this->assertCount( 0, $this->requests );
		}
	}

	public function test_void_calls_the_cancel_endpoint() {
		$this->configure_ups();
		$this->queue_token();
		$this->queue_response( 200, $this->fixture( 'ups-void-ok' ) );
		( new UpsCarrier() )->void_label( '1ZK877V90300000001' );
		$this->assertSame( 'DELETE', $this->requests[1]['args']['method'] );
		$this->assertStringEndsWith( '/api/shipments/v2409/void/cancel/1ZK877V90300000001', $this->requests[1]['url'] );
	}
}
```

- [ ] **Step 3: Run to verify failure**

Run: `vendor/bin/phpunit tests/test-shipping-ups-carrier.php`
Expected: FAIL — `Call to undefined method …UpsCarrier::build_ship_body()`.

- [ ] **Step 4: Implement**

Replace `anchor-shipping/src/Carriers/Ups/UpsCarrier.php` with:

```php
<?php
declare(strict_types=1);

namespace Anchor\Shipping\Carriers\Ups;

use Anchor\Shipping\Carriers\CarrierInterface;
use Anchor\Shipping\Domain\Address;
use Anchor\Shipping\Domain\CarrierError;
use Anchor\Shipping\Domain\LabelResult;
use Anchor\Shipping\Domain\PackageLabel;
use Anchor\Shipping\Domain\ShipmentRequest;
use Anchor\Shipping\Support\Settings;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** UPS REST (Shipping v2409). Never sends the customer's email or UPS notifications. */
final class UpsCarrier implements CarrierInterface {

	public function __construct( private ?UpsClient $client = null ) {}

	public function id(): string {
		return 'ups';
	}

	public function label(): string {
		return 'UPS';
	}

	public function supports( string $feature ): bool {
		return in_array( $feature, [ 'labels', 'void' ], true );
	}

	public function services(): array {
		return [
			'03' => 'UPS Ground',
			'12' => 'UPS 3 Day Select',
			'02' => 'UPS 2nd Day Air',
			'13' => 'UPS Next Day Air Saver',
			'01' => 'UPS Next Day Air',
			'14' => 'UPS Next Day Air Early',
		];
	}

	public function settings_fields(): array {
		return [
			'environment'   => [ 'label' => 'Environment', 'type' => 'select', 'options' => [ 'sandbox' => 'Sandbox (UPS test server — no charges)', 'production' => 'Production (real labels, billed)' ] ],
			'client_id'     => [ 'label' => 'Client ID', 'type' => 'text' ],
			'client_secret' => [ 'label' => 'Client Secret', 'type' => 'secret' ],
			'account'       => [ 'label' => 'Account (shipper) number', 'type' => 'text' ],
		];
	}

	public function tracking_url( string $tracking_number ): string {
		return 'https://www.ups.com/track?tracknum=' . rawurlencode( $tracking_number );
	}

	public function create_label( ShipmentRequest $request ): LabelResult {
		$creds  = Settings::carrier( 'ups' );
		$format = 'ZPL' === Settings::all()['label_format'] ? 'ZPL' : 'GIF';
		$json   = $this->client()->request( 'POST', '/api/shipments/v2409/ship', $this->build_ship_body( $request, $creds['account'], $format ) );

		$results  = $json['ShipmentResponse']['ShipmentResults'] ?? null;
		if ( ! is_array( $results ) || empty( $results['ShipmentIdentificationNumber'] ) ) {
			throw new CarrierError( 'parse', \__( 'UPS returned an unexpected response for the label.', 'anchor-schema' ) );
		}
		$pkgs     = $results['PackageResults'] ?? [];
		$pkgs     = isset( $pkgs['TrackingNumber'] ) ? [ $pkgs ] : (array) $pkgs;
		$packages = [];
		foreach ( $pkgs as $p ) {
			$packages[] = new PackageLabel(
				(string) $p['TrackingNumber'],
				(string) base64_decode( (string) ( $p['ShippingLabel']['GraphicImage'] ?? '' ), true ),
				(string) ( $p['ShippingLabel']['ImageFormat']['Code'] ?? $format )
			);
		}
		$charge = $results['NegotiatedRateCharges']['TotalCharge'] ?? $results['ShipmentCharges']['TotalCharges'] ?? null;

		return new LabelResult(
			(string) $results['ShipmentIdentificationNumber'],
			$packages,
			isset( $charge['MonetaryValue'] ) ? (float) $charge['MonetaryValue'] : null,
			(string) ( $charge['CurrencyCode'] ?? 'USD' )
		);
	}

	public function void_label( string $shipment_id ): void {
		$this->client()->request( 'DELETE', '/api/shipments/v2409/void/cancel/' . rawurlencode( $shipment_id ) );
	}

	public function track( array $tracking_numbers ): array {
		throw new CarrierError( 'unsupported', \__( 'Tracking arrives in phase 3.', 'anchor-schema' ) );
	}

	public function rate( ShipmentRequest $request ): array {
		throw new CarrierError( 'unsupported', \__( 'Rates arrive in phase 4.', 'anchor-schema' ) );
	}

	public function build_ship_body( ShipmentRequest $r, string $account, string $format ): array {
		$per_pkg_value = $r->declared_value > 0 ? round( $r->declared_value / count( $r->parcels ), 2 ) : 0.0;
		$packages      = [];
		foreach ( $r->parcels as $parcel ) {
			[ $l, $w, $h ] = $parcel->dims_in();
			$pkg           = [
				'Packaging'       => [ 'Code' => '02' ],
				'Dimensions'      => [ 'UnitOfMeasurement' => [ 'Code' => 'IN' ], 'Length' => (string) $l, 'Width' => (string) $w, 'Height' => (string) $h ],
				'PackageWeight'   => [ 'UnitOfMeasurement' => [ 'Code' => 'LBS' ], 'Weight' => (string) $parcel->weight_lb() ],
				'ReferenceNumber' => [ 'Value' => substr( $r->reference, 0, 35 ) ],
			];
			$options = [];
			if ( $per_pkg_value > 0 ) {
				$options['DeclaredValue'] = [ 'CurrencyCode' => $r->currency, 'MonetaryValue' => number_format( $per_pkg_value, 2, '.', '' ) ];
			}
			if ( $r->signature ) {
				$options['DeliveryConfirmation'] = [ 'DCISType' => '2' ];
			}
			if ( $options ) {
				$pkg['PackageServiceOptions'] = $options;
			}
			$packages[] = $pkg;
		}

		$label = 'ZPL' === $format
			? [ 'LabelImageFormat' => [ 'Code' => 'ZPL' ], 'LabelStockSize' => [ 'Height' => '6', 'Width' => '4' ] ]
			: [ 'LabelImageFormat' => [ 'Code' => 'GIF' ], 'HTTPUserAgent' => 'Mozilla/4.5' ];

		return [
			'ShipmentRequest' => [
				'Request'            => [ 'RequestOption' => 'nonvalidate' ],
				'Shipment'           => [
					'Description'           => substr( $r->reference, 0, 50 ),
					'Shipper'               => $this->party( $r->from ) + [ 'ShipperNumber' => $account ],
					'ShipFrom'              => $this->party( $r->from ),
					'ShipTo'                => $this->party( $r->to ),
					'PaymentInformation'    => [ 'ShipmentCharge' => [ 'Type' => '01', 'BillShipper' => [ 'AccountNumber' => $account ] ] ],
					'Service'               => [ 'Code' => $r->service ],
					'ShipmentRatingOptions' => [ 'NegotiatedRatesIndicator' => '' ],
					'Package'               => $packages,
				],
				'LabelSpecification' => $label,
			],
		];
	}

	/** Name + address + phone. Deliberately no EMailAddress (UPS would email the recipient). */
	private function party( Address $a ): array {
		$party = [
			'Name'    => substr( '' !== $a->company ? $a->company : $a->name, 0, 35 ),
			'Address' => [
				'AddressLine'       => array_values( array_filter( [ $a->line1, $a->line2 ] ) ),
				'City'              => $a->city,
				'StateProvinceCode' => $a->state,
				'PostalCode'        => $a->postcode,
				'CountryCode'       => $a->country,
			],
		];
		if ( '' !== $a->company && '' !== $a->name ) {
			$party['AttentionName'] = substr( $a->name, 0, 35 );
		}
		if ( '' !== $a->phone ) {
			$party['Phone'] = [ 'Number' => preg_replace( '/\D+/', '', $a->phone ) ];
		}
		return $party;
	}

	private function client(): UpsClient {
		if ( $this->client ) {
			return $this->client;
		}
		$c = Settings::carrier( 'ups' );
		if ( '' === ( $c['client_id'] ?? '' ) || '' === ( $c['client_secret'] ?? '' ) || '' === ( $c['account'] ?? '' ) ) {
			throw new CarrierError( 'not_configured', \__( 'UPS is not set up: add the Client ID, Secret and account number under WooCommerce > Anchor Shipping.', 'anchor-schema' ) );
		}
		return new UpsClient( $c['client_id'], $c['client_secret'], $c['environment'] ?? 'sandbox' );
	}
}
```

- [ ] **Step 5: Run tests**

Run: `vendor/bin/phpunit tests/test-shipping-ups-carrier.php`
Expected: PASS (7 tests).

- [ ] **Step 6: Commit**

```bash
composer dump-autoload --no-dev
git add anchor-shipping/src/Carriers/Ups/UpsCarrier.php tests/fixtures/shipping tests/test-shipping-ups-carrier.php
git commit -m "feat(shipping): UPS label creation and void, with declared value and signature

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Label storage (4×6 PDF) and protected download

**Files:**
- Modify: `composer.json` (`require` `"setasign/fpdf": "^1.8"`), `composer.lock`, `vendor/setasign/`, `vendor/composer/`
- Create: `anchor-shipping/src/Services/LabelStore.php`
- Test: `tests/test-shipping-label-store.php`

**Interfaces:**
- Consumes: `PackageLabel` (Task 2).
- Produces: `LabelStore::__construct(?string $base_dir = null)` (default `uploads/anchor-shipping`); `dir(): string`; `save(int $order_id, PackageLabel $label): array{path:string, format:string}` (relative path; format `PDF`|`ZPL`); `absolute(string $relative): ?string` (null when missing or outside the dir); `delete(string $relative): void`; `gif_to_pdf(string $gif): string`; `content_type(string $format): string`.

- [ ] **Step 1: Add the dependency**

Run: `composer require setasign/fpdf:^1.8` (then `composer dump-autoload` for tests). Confirm `vendor/setasign/fpdf/fpdf.php` exists. On Kinsta, GD is required for GIF decoding — Task 15 verifies `function_exists( 'imagecreatefromstring' )` on staging.

- [ ] **Step 2: Write the failing tests**

`tests/test-shipping-label-store.php`:

```php
<?php
use Anchor\Shipping\Domain\PackageLabel;
use Anchor\Shipping\Services\LabelStore;

class Test_Shipping_Label_Store extends Anchor_Shipping_TestCase {

	private string $base;

	public function set_up() {
		parent::set_up();
		$this->base = get_temp_dir() . 'anchor-shipping-test-' . wp_generate_password( 6, false );
	}

	public function tear_down() {
		array_map( 'unlink', glob( $this->base . '/{,.}*', GLOB_BRACE ) ?: [] );
		@rmdir( $this->base );
		parent::tear_down();
	}

	private function landscape_gif(): string {
		$im = imagecreatetruecolor( 1400, 800 );
		imagefill( $im, 0, 0, imagecolorallocate( $im, 255, 255, 255 ) );
		ob_start();
		imagegif( $im );
		return (string) ob_get_clean();
	}

	public function test_gif_label_becomes_a_portrait_4x6_pdf() {
		$store = new LabelStore( $this->base );
		$saved = $store->save( 42, new PackageLabel( '1ZTEST', $this->landscape_gif(), 'GIF' ) );
		$this->assertSame( 'PDF', $saved['format'] );
		$this->assertMatchesRegularExpression( '/^42-1ZTEST-[a-zA-Z0-9]{32}\.pdf$/', $saved['path'] );
		$pdf = (string) file_get_contents( $store->absolute( $saved['path'] ) );
		$this->assertStringStartsWith( '%PDF', $pdf );
		$this->assertMatchesRegularExpression( '/MediaBox \[0 0 288\.00 432\.00\]/', $pdf, '4in x 6in portrait' );
	}

	public function test_zpl_is_stored_verbatim() {
		$store = new LabelStore( $this->base );
		$saved = $store->save( 7, new PackageLabel( '1ZZPL', "^XA^FDtest^FS^XZ", 'ZPL' ) );
		$this->assertSame( 'ZPL', $saved['format'] );
		$this->assertSame( "^XA^FDtest^FS^XZ", file_get_contents( $store->absolute( $saved['path'] ) ) );
		$this->assertSame( 'application/pdf', $store->content_type( 'PDF' ) );
		$this->assertSame( 'text/plain; charset=utf-8', $store->content_type( 'ZPL' ) );
	}

	public function test_dir_is_locked_down_and_paths_cannot_escape() {
		$store = new LabelStore( $this->base );
		$store->dir();
		$this->assertFileExists( $this->base . '/.htaccess' );
		$this->assertFileExists( $this->base . '/index.php' );
		$this->assertNull( $store->absolute( '../../wp-config.php' ) );
		$this->assertNull( $store->absolute( 'missing.pdf' ) );
	}
}
```

- [ ] **Step 3: Run to verify failure**

Run: `vendor/bin/phpunit tests/test-shipping-label-store.php`
Expected: FAIL — `Class "Anchor\Shipping\Services\LabelStore" not found`.

- [ ] **Step 4: Implement**

`anchor-shipping/src/Services/LabelStore.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Shipping\Services;

use Anchor\Shipping\Domain\PackageLabel;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Label files under uploads/anchor-shipping. Apache is denied by .htaccess; nginx
 * (Kinsta) ignores it, so names carry a 32-char random token and the only
 * supported way to fetch a label is the admin download handler.
 */
final class LabelStore {

	public function __construct( private ?string $base_dir = null ) {}

	public function dir(): string {
		$dir = $this->base_dir ?? \wp_upload_dir()['basedir'] . '/anchor-shipping';
		if ( ! is_dir( $dir ) ) {
			\wp_mkdir_p( $dir );
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" );
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" );
		}
		return $dir;
	}

	/** @return array{path:string, format:string} */
	public function save( int $order_id, PackageLabel $label ): array {
		$is_zpl = 'ZPL' === strtoupper( $label->format );
		$bytes  = $is_zpl ? $label->bytes : $this->gif_to_pdf( $label->bytes );
		$name   = sprintf( '%d-%s-%s.%s', $order_id, preg_replace( '/[^A-Za-z0-9]/', '', $label->tracking_number ), \wp_generate_password( 32, false ), $is_zpl ? 'zpl' : 'pdf' );
		file_put_contents( $this->dir() . '/' . $name, $bytes );
		return [ 'path' => $name, 'format' => $is_zpl ? 'ZPL' : 'PDF' ];
	}

	public function absolute( string $relative ): ?string {
		if ( '' === $relative || basename( $relative ) !== $relative ) {
			return null;
		}
		$path = $this->dir() . '/' . $relative;
		return is_file( $path ) ? $path : null;
	}

	public function delete( string $relative ): void {
		$path = $this->absolute( $relative );
		if ( $path ) {
			unlink( $path );
		}
	}

	public function content_type( string $format ): string {
		return 'ZPL' === $format ? 'text/plain; charset=utf-8' : 'application/pdf';
	}

	/**
	 * UPS returns a landscape GIF with the label in the left part. Rotate it to
	 * portrait, crop to 2:3 from the top, and place it on one 4x6in page.
	 */
	public function gif_to_pdf( string $gif ): string {
		$im = \imagecreatefromstring( $gif );
		if ( false === $im ) {
			throw new \RuntimeException( 'The carrier returned a label image that could not be read.' );
		}
		if ( \imagesx( $im ) > \imagesy( $im ) ) {
			$im = \imagerotate( $im, 270, 0 ); // 270° counter-clockwise = 90° clockwise
		}
		$w      = \imagesx( $im );
		$target = (int) round( $w * 1.5 );
		if ( \imagesy( $im ) > $target ) {
			$im = \imagecrop( $im, [ 'x' => 0, 'y' => 0, 'width' => $w, 'height' => $target ] );
		}
		$tmp = \wp_tempnam( 'anchor-shipping-label' ) . '.png';
		\imagepng( $im, $tmp );

		$pdf = new \FPDF( 'P', 'in', [ 4, 6 ] );
		$pdf->SetMargins( 0, 0, 0 );
		$pdf->SetAutoPageBreak( false );
		$pdf->AddPage();
		$pdf->Image( $tmp, 0, 0, 4, 0, 'PNG' );
		$out = $pdf->Output( 'S' );
		@unlink( $tmp );
		return $out;
	}
}
```

- [ ] **Step 5: Run tests**

Run: `vendor/bin/phpunit tests/test-shipping-label-store.php`
Expected: PASS (3 tests). If the MediaBox regex fails, open the generated PDF bytes and match FPDF's actual page-size string for a 288×432 pt page — the assertion is that the page is 4×6 portrait; adjust only the regex syntax, not the size.

- [ ] **Step 6: Commit**

```bash
composer dump-autoload --no-dev
git add composer.json composer.lock vendor/setasign vendor/composer anchor-shipping/src/Services/LabelStore.php tests/test-shipping-label-store.php
git commit -m "feat(shipping): label store — 4x6 portrait PDF from UPS GIF, ZPL passthrough, locked-down dir

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Packer and the product box field

**Files:**
- Create: `anchor-shipping/src/Packing/Packer.php`, `anchor-shipping/src/Packing/PackResult.php`
- Create: `anchor-shipping/src/Admin/ProductFields.php`
- Modify: `anchor-shipping/anchor-shipping.php` (ProductFields in `is_admin()`)
- Test: `tests/test-shipping-packer.php`

**Interfaces:**
- Consumes: `Settings::boxes()` (Task 3), `Parcel` (Task 2).
- Produces: `PackResult(array $parcels, string $reason, ?array $suggestion)` — `ok(): bool` (no reason and ≥1 parcel); `$suggestion` = `['box' => id, 'weight' => float store units]` whenever a box could be chosen (used to prefill manual forms even when not ok).
- Produces: `Packer::__construct(?array $boxes = null)` (null → `Settings::boxes()` read at pack time); `pack_order(\WC_Order): PackResult`; `shippable_items(\WC_Order): array` of `['product' => \WC_Product, 'qty' => int, 'total' => float]`; `shippable_subtotal(\WC_Order): float`.
- Produces: product meta key `ProductFields::META = '_anchor_shipping_box'` (on the parent product; variations inherit).

- [ ] **Step 1: Write the failing tests**

`tests/test-shipping-packer.php`:

```php
<?php
use Anchor\Shipping\Packing\Packer;

class Test_Shipping_Packer extends Anchor_Shipping_TestCase {

	public function set_up() {
		parent::set_up();
		update_option( 'woocommerce_weight_unit', 'kg' );
		update_option( 'woocommerce_dimension_unit', 'cm' );
		$this->configure_ups( [ 'boxes' => [
			[ 'id' => 'small', 'name' => 'Small', 'length' => 25.4, 'width' => 20.32, 'height' => 10.16, 'empty_weight' => 0.2, 'max_weight' => 2 ],
			[ 'id' => 'large', 'name' => 'Large', 'length' => 50, 'width' => 40, 'height' => 30, 'empty_weight' => 0.8, 'max_weight' => 20 ],
		] ] );
	}

	public function test_sizes_one_parcel_from_weights_and_the_largest_default_box() {
		$a     = $this->make_product( [ 'weight' => 0.5, 'box' => 'small' ] );
		$b     = $this->make_product( [ 'weight' => 1.0, 'box' => 'large' ] );
		$r     = ( new Packer() )->pack_order( $this->make_order( [ $a, $b ] ) );
		$this->assertTrue( $r->ok(), $r->reason );
		$this->assertCount( 1, $r->parcels );
		$this->assertSame( 'large', $r->parcels[0]->box_id );
		$this->assertEqualsWithDelta( 2.3, $r->parcels[0]->weight_kg, 0.001, 'items + empty box' );
		$this->assertEqualsWithDelta( 50.0, $r->parcels[0]->length_cm, 0.001 );
	}

	public function test_missing_weight_is_not_sizeable_but_still_suggests_a_box() {
		$a = $this->make_product( [ 'name' => 'Banner Stand', 'box' => 'large' ] );
		$r = ( new Packer() )->pack_order( $this->make_order( [ $a ] ) );
		$this->assertFalse( $r->ok() );
		$this->assertStringContainsString( 'Banner Stand', $r->reason );
		$this->assertSame( 'large', $r->suggestion['box'] );
	}

	public function test_missing_or_unknown_box_is_not_sizeable() {
		$a = $this->make_product( [ 'name' => 'Tip', 'weight' => 0.1 ] );
		$b = $this->make_product( [ 'name' => 'Odd', 'weight' => 0.1, 'box' => 'deleted-box' ] );
		$this->assertStringContainsString( 'Tip', ( new Packer() )->pack_order( $this->make_order( [ $a ] ) )->reason );
		$this->assertStringContainsString( 'Odd', ( new Packer() )->pack_order( $this->make_order( [ $b ] ) )->reason );
	}

	public function test_too_heavy_for_the_box_is_not_sizeable() {
		$a = $this->make_product( [ 'weight' => 1.9, 'box' => 'small' ] );
		$r = ( new Packer() )->pack_order( $this->make_order( [ $a ] ) );
		$this->assertFalse( $r->ok() );
		$this->assertStringContainsString( 'Small', $r->reason );
	}

	public function test_virtual_items_are_ignored_and_subtotal_counts_only_shippable_lines() {
		$ship   = $this->make_product( [ 'weight' => 0.5, 'box' => 'small', 'price' => 110 ] );
		$ticket = $this->make_product( [ 'virtual' => true, 'price' => 2500 ] );
		$order  = $this->make_order( [ $ship, $ticket ] );
		$this->assertTrue( ( new Packer() )->pack_order( $order )->ok() );
		$this->assertSame( 110.0, ( new Packer() )->shippable_subtotal( $order ) );
	}
}
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit tests/test-shipping-packer.php`
Expected: FAIL — `Class "Anchor\Shipping\Packing\Packer" not found`.

- [ ] **Step 3: Implement**

`anchor-shipping/src/Packing/PackResult.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Shipping\Packing;

use Anchor\Shipping\Domain\Parcel;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class PackResult {

	/**
	 * @param Parcel[]   $parcels
	 * @param string     $reason     why the order cannot be sized automatically ('' when it can)
	 * @param array|null $suggestion ['box' => id, 'weight' => float in store units] for prefilling forms
	 */
	public function __construct(
		public readonly array $parcels,
		public readonly string $reason,
		public readonly ?array $suggestion
	) {}

	public function ok(): bool {
		return '' === $this->reason && [] !== $this->parcels;
	}
}
```

`anchor-shipping/src/Packing/Packer.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Shipping\Packing;

use Anchor\Shipping\Admin\ProductFields;
use Anchor\Shipping\Domain\Parcel;
use Anchor\Shipping\Support\Settings;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * One box per order: items' weights + the largest of their default boxes. Anything
 * it can't size safely returns a reason instead of a guess.
 */
final class Packer {

	/** @param array|null $boxes null = read Settings::boxes() at pack time, so settings saved later in the request apply */
	public function __construct( private ?array $boxes = null ) {}

	/** @return array<int, array{product:\WC_Product, qty:int, total:float}> */
	public function shippable_items( \WC_Order $order ): array {
		$out = [];
		foreach ( $order->get_items() as $item ) {
			$product = $item instanceof \WC_Order_Item_Product ? $item->get_product() : null;
			if ( $product && $product->needs_shipping() ) {
				$out[] = [ 'product' => $product, 'qty' => (int) $item->get_quantity(), 'total' => (float) $item->get_total() ];
			}
		}
		return $out;
	}

	public function shippable_subtotal( \WC_Order $order ): float {
		return round( array_sum( array_column( $this->shippable_items( $order ), 'total' ) ), 2 );
	}

	public function pack_order( \WC_Order $order ): PackResult {
		$boxes    = $this->boxes ?? Settings::boxes();
		$items    = $this->shippable_items( $order );
		$problems = [];
		$weight   = 0.0; // store units
		$box      = null;

		foreach ( $items as $it ) {
			$product = $it['product'];
			$name    = $product->get_name();
			$w       = (float) $product->get_weight();
			$box_id  = (string) \get_post_meta( $product->get_parent_id() ?: $product->get_id(), ProductFields::META, true );

			if ( $w <= 0 ) {
				/* translators: %s: product name */
				$problems[] = sprintf( \__( '“%s” has no weight.', 'anchor-schema' ), $name );
			}
			if ( '' === $box_id ) {
				/* translators: %s: product name */
				$problems[] = sprintf( \__( '“%s” has no default box.', 'anchor-schema' ), $name );
			} elseif ( ! isset( $boxes[ $box_id ] ) ) {
				/* translators: %s: product name */
				$problems[] = sprintf( \__( '“%s” uses a box that no longer exists.', 'anchor-schema' ), $name );
			} elseif ( ! $box || $this->volume( $boxes[ $box_id ] ) > $this->volume( $box ) ) {
				$box = $boxes[ $box_id ];
			}
			$weight += $w * $it['qty'];
		}

		if ( ! $items ) {
			return new PackResult( [], \__( 'Nothing in this order needs shipping.', 'anchor-schema' ), null );
		}

		$suggestion = $box ? [ 'box' => (string) $box['id'], 'weight' => round( $weight + (float) $box['empty_weight'], 3 ) ] : null;

		if ( $box && ! $problems && (float) $box['max_weight'] > 0 && $weight + (float) $box['empty_weight'] > (float) $box['max_weight'] ) {
			/* translators: %s: box name */
			$problems[] = sprintf( \__( 'Too heavy for one %s box.', 'anchor-schema' ), $box['name'] );
		}
		if ( $problems ) {
			return new PackResult( [], implode( ' ', $problems ), $suggestion );
		}

		return new PackResult(
			[ Parcel::from_store_units( $suggestion['weight'], (float) $box['length'], (float) $box['width'], (float) $box['height'], (string) $box['id'] ) ],
			'',
			$suggestion
		);
	}

	private function volume( array $b ): float {
		return (float) $b['length'] * (float) $b['width'] * (float) $b['height'];
	}
}
```

`anchor-shipping/src/Admin/ProductFields.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Shipping\Admin;

use Anchor\Shipping\Support\Settings;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** "Default box" select on the product Shipping tab. Weight uses Woo's own field. */
final class ProductFields {

	public const META = '_anchor_shipping_box';

	public function __construct() {
		\add_action( 'woocommerce_product_options_shipping', [ $this, 'render' ] );
		\add_action( 'woocommerce_admin_process_product_object', [ $this, 'save' ] );
	}

	public function render(): void {
		$options = [ '' => \__( '— none —', 'anchor-schema' ) ];
		foreach ( Settings::boxes() as $id => $b ) {
			$options[ $id ] = $b['name'];
		}
		\woocommerce_wp_select(
			[
				'id'          => self::META,
				'label'       => \__( 'Default box', 'anchor-schema' ),
				'options'     => $options,
				'desc_tip'    => true,
				'description' => \__( 'Used to size labels automatically. Set the weight above too.', 'anchor-schema' ),
			]
		);
	}

	public function save( \WC_Product $product ): void {
		if ( isset( $_POST[ self::META ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- Woo verified the product save nonce.
			$product->update_meta_data( self::META, \sanitize_key( \wp_unslash( $_POST[ self::META ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}
	}
}
```

Note: `Packer` reads the box via `get_post_meta` on the **product** (products are posts in every Woo storage mode — only orders moved to HPOS), which is what the test factory writes.

- [ ] **Step 4: Wire it**

In `Module`'s `is_admin()` block: `new Admin\ProductFields();`

- [ ] **Step 5: Run tests**

Run: `vendor/bin/phpunit tests/test-shipping-packer.php`
Expected: PASS (5 tests).

- [ ] **Step 6: Commit**

```bash
composer dump-autoload --no-dev
git add anchor-shipping/ tests/test-shipping-packer.php
git commit -m "feat(shipping): one-box packer with reasons and suggestions, product default-box field

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Protection resolver and LabelService

**Files:**
- Create: `anchor-shipping/src/Services/Protection.php`
- Create: `anchor-shipping/src/Services/LabelService.php`
- Modify: `anchor-shipping/anchor-shipping.php`
- Test: `tests/test-shipping-label-service.php`

**Interfaces:**
- Consumes: `CarrierRegistry`, `ShipmentRepository`, `LabelStore`, `Packer`, `Settings`, domain objects.
- Produces: `Protection::META_INSURE = '_anchor_shipping_insure'`, `Protection::META_SIGNATURE = '_anchor_shipping_signature'`; `Protection::for_order(\WC_Order $order, float $shippable_subtotal): array{declared_value: float, signature: bool}`; `Protection::insurance_fee(float $subtotal): float`; `Protection::signature_fee(): float`; `Protection::offered(string $option): bool` (customer mode AND fee set).
- Produces: `LabelService::STATE_META = '_anchor_shipping_state'`; states `needs_attention`, `labelled`, `''`.
- Produces: `LabelService::__construct(CarrierRegistry, ShipmentRepository, LabelStore, Packer)`; `create_for_order(\WC_Order $order, array $parcels, string $source, array $opts = []): array` → shipment row ids. `$opts`: `carrier` (default setting), `service` (default setting), `additional` (bool, default false), `declared_value` (float|null → Protection), `signature` (bool|null → Protection). Throws `\LogicException` when already labelled and not additional; `CarrierError` from the carrier. Fires `do_action( 'anchor_shipping_label_created', int $order_id, int[] $ids )`.
- Produces: `void_shipment(int $row_id): void` (voids every row sharing the carrier shipment id; idempotent; fires `anchor_shipping_label_voided`); `is_labelled(\WC_Order): bool`; `mark_needs_attention(\WC_Order, string $reason): void` (state + note; fires `do_action( 'anchor_shipping_needs_attention', int $order_id, string $reason )`).
- Produces: `Module::$labels` (`LabelService`), `Module::$store` (`LabelStore`), `Module::$packer` (`Packer`).

- [ ] **Step 1: Write the failing tests**

`tests/test-shipping-label-service.php`:

```php
<?php
use Anchor\Shipping\Domain\CarrierError;
use Anchor\Shipping\Domain\Parcel;
use Anchor\Shipping\Module;
use Anchor\Shipping\Services\LabelService;
use Anchor\Shipping\Services\Protection;
use Anchor\Shipping\Support\Settings;

class Test_Shipping_Label_Service extends Anchor_Shipping_TestCase {

	private function parcel(): Parcel {
		return new Parcel( 1.0, 25.4, 20.32, 10.16, 'small' );
	}

	private function create( WC_Order $order, array $opts = [] ): array {
		$this->queue_token();
		$this->queue_response( 200, $this->fixture( 'ups-ship-single' ) );
		return Module::instance()->labels->create_for_order( $order, [ $this->parcel() ], 'manual', $opts );
	}

	public function test_create_stores_rows_label_note_and_state() {
		$this->configure_ups();
		$order = $this->make_order( [], 'processing' );
		$fired = null;
		add_action( 'anchor_shipping_label_created', static function ( $id, $ids ) use ( &$fired ) { $fired = [ $id, $ids ]; }, 10, 2 );

		$ids = $this->create( $order );

		$row = Module::instance()->shipments->find( $ids[0] );
		$this->assertSame( '1ZK877V90300000001', $row['tracking_number'] );
		$this->assertSame( '1ZK877V90300000001', $row['shipment_id'] );
		$this->assertSame( '11.66', $row['cost'] );
		$this->assertSame( 'PDF', $row['label_format'] );
		$this->assertNotNull( Module::instance()->store->absolute( $row['label_path'] ) );
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'labelled', $order->get_meta( LabelService::STATE_META ) );
		$this->assertStringContainsString( '1ZK877V90300000001', implode( ' ', wp_list_pluck( wc_get_order_notes( [ 'order_id' => $order->get_id() ] ), 'content' ) ) );
		$this->assertSame( [ $order->get_id(), $ids ], $fired );
	}

	public function test_second_create_is_refused_without_additional_flag() {
		$this->configure_ups();
		$order = $this->make_order( [], 'processing' );
		$this->create( $order );
		try {
			Module::instance()->labels->create_for_order( $order, [ $this->parcel() ], 'manual' );
			$this->fail( 'expected LogicException' );
		} catch ( LogicException $e ) {
			$this->assertCount( 2, $this->requests, 'no second carrier call' );
		}
		$this->assertCount( 1, $this->create( $order, [ 'additional' => true ] ), 'an explicit additional package is allowed' );
	}

	public function test_carrier_failure_writes_nothing() {
		$this->configure_ups();
		$order = $this->make_order( [], 'processing' );
		$this->queue_token();
		$this->queue_response( 400, [ 'response' => [ 'errors' => [ [ 'code' => '120100', 'message' => 'Missing or invalid shipper number' ] ] ] ] );
		$this->expectException( CarrierError::class );
		try {
			Module::instance()->labels->create_for_order( $order, [ $this->parcel() ], 'manual' );
		} finally {
			$this->assertSame( [], Module::instance()->shipments->for_order( $order->get_id() ) );
		}
	}

	public function test_void_marks_every_package_of_the_shipment_and_is_idempotent() {
		$this->configure_ups();
		$order = $this->make_order( [], 'processing' );
		$this->queue_token();
		$this->queue_response( 200, $this->fixture( 'ups-ship-multi' ) );
		$ids = Module::instance()->labels->create_for_order( $order, [ $this->parcel(), $this->parcel() ], 'manual' );

		$this->queue_response( 200, $this->fixture( 'ups-void-ok' ) ); // token cached
		Module::instance()->labels->void_shipment( $ids[0] );
		Module::instance()->labels->void_shipment( $ids[1] ); // already voided: no HTTP

		foreach ( $ids as $id ) {
			$this->assertSame( 'voided', Module::instance()->shipments->find( $id )['status'] );
		}
		$this->assertCount( 3, $this->requests );
		$this->assertSame( '', wc_get_order( $order->get_id() )->get_meta( LabelService::STATE_META ) );
	}

	public function test_protection_auto_mode_uses_threshold_and_customer_mode_uses_order_meta() {
		$order = $this->make_order( [ $this->make_product( [ 'price' => 450, 'weight' => 0.5, 'box' => 'small' ] ) ] );
		$this->configure_ups( [ 'insurance' => [ 'mode' => 'auto', 'threshold' => 100 ], 'signature' => [ 'mode' => 'auto', 'threshold' => 500 ] ] );
		$this->assertSame( [ 'declared_value' => 450.0, 'signature' => false ], Protection::for_order( $order, 450.0 ) );

		$this->configure_ups( [ 'insurance' => [ 'mode' => 'customer', 'fee_per_100' => '1.25' ], 'signature' => [ 'mode' => 'customer', 'fee' => '7.70' ] ] );
		$this->assertSame( [ 'declared_value' => 0.0, 'signature' => false ], Protection::for_order( $order, 450.0 ) );
		$order->update_meta_data( Protection::META_INSURE, 'yes' );
		$order->update_meta_data( Protection::META_SIGNATURE, 'yes' );
		$this->assertSame( [ 'declared_value' => 450.0, 'signature' => true ], Protection::for_order( $order, 450.0 ) );
	}

	public function test_insurance_fee_rule() {
		$this->configure_ups( [ 'insurance' => [ 'mode' => 'customer', 'fee_per_100' => '1.25' ] ] );
		$this->assertSame( 0.0, Protection::insurance_fee( 100.0 ) );
		$this->assertSame( 1.25, Protection::insurance_fee( 100.01 ) );
		$this->assertSame( 5.0, Protection::insurance_fee( 450.0 ) );
		$this->assertTrue( Protection::offered( 'insurance' ) );
		$this->configure_ups( [ 'insurance' => [ 'mode' => 'customer', 'fee_per_100' => '' ] ] );
		$this->assertFalse( Protection::offered( 'insurance' ), 'hidden until a fee is set' );
	}

	public function test_explicit_protection_opts_override_and_reach_the_carrier() {
		$this->configure_ups();
		$order = $this->make_order( [], 'processing' );
		$ids   = $this->create( $order, [ 'declared_value' => 900.0, 'signature' => true ] );
		$body  = json_decode( $this->requests[1]['args']['body'], true );
		$this->assertSame( '900.00', $body['ShipmentRequest']['Shipment']['Package'][0]['PackageServiceOptions']['DeclaredValue']['MonetaryValue'] );
		$row = Module::instance()->shipments->find( $ids[0] );
		$this->assertSame( '900.00', $row['declared_value'] );
		$this->assertSame( '1', $row['signature'] );
	}
}
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit tests/test-shipping-label-service.php`
Expected: FAIL — `Undefined property: Anchor\Shipping\Module::$labels`.

- [ ] **Step 3: Implement Protection**

`anchor-shipping/src/Services/Protection.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Shipping\Services;

use Anchor\Shipping\Support\Settings;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** Declared value + signature: which mode applies, what the customer pays, what the label carries. */
final class Protection {

	public const META_INSURE    = '_anchor_shipping_insure';
	public const META_SIGNATURE = '_anchor_shipping_signature';

	/** @return array{declared_value: float, signature: bool} */
	public static function for_order( \WC_Order $order, float $shippable_subtotal ): array {
		$s       = Settings::all();
		$insured = match ( $s['insurance']['mode'] ) {
			'auto'     => $shippable_subtotal > (float) $s['insurance']['threshold'],
			'customer' => 'yes' === $order->get_meta( self::META_INSURE ),
			default    => false,
		};
		$sign    = match ( $s['signature']['mode'] ) {
			'auto'     => $shippable_subtotal > (float) $s['signature']['threshold'],
			'customer' => 'yes' === $order->get_meta( self::META_SIGNATURE ),
			default    => false,
		};
		return [ 'declared_value' => $insured ? $shippable_subtotal : 0.0, 'signature' => $sign ];
	}

	public static function offered( string $option ): bool {
		$s   = Settings::all()[ $option ] ?? [];
		$fee = 'insurance' === $option ? ( $s['fee_per_100'] ?? '' ) : ( $s['fee'] ?? '' );
		return 'customer' === ( $s['mode'] ?? 'off' ) && '' !== (string) $fee;
	}

	/** rate × each started $100 above the first $100 (which UPS covers free). */
	public static function insurance_fee( float $subtotal ): float {
		$rate = (float) Settings::all()['insurance']['fee_per_100'];
		return $subtotal <= 100 ? 0.0 : round( $rate * ceil( ( $subtotal - 100 ) / 100 ), 2 );
	}

	public static function signature_fee(): float {
		return round( (float) Settings::all()['signature']['fee'], 2 );
	}
}
```

- [ ] **Step 4: Implement LabelService**

`anchor-shipping/src/Services/LabelService.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Shipping\Services;

use Anchor\Shipping\Carriers\CarrierRegistry;
use Anchor\Shipping\Database\ShipmentRepository;
use Anchor\Shipping\Domain\Address;
use Anchor\Shipping\Domain\CarrierError;
use Anchor\Shipping\Domain\Parcel;
use Anchor\Shipping\Domain\ShipmentRequest;
use Anchor\Shipping\Packing\Packer;
use Anchor\Shipping\Support\Settings;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** The one place labels are created and voided. Every path (manual, auto, bulk) goes through here. */
final class LabelService {

	public const STATE_META = '_anchor_shipping_state';

	public function __construct(
		private CarrierRegistry $carriers,
		private ShipmentRepository $shipments,
		private LabelStore $store,
		private Packer $packer
	) {}

	public function is_labelled( \WC_Order $order ): bool {
		return [] !== $this->shipments->active_for_order( $order->get_id() );
	}

	/**
	 * @param Parcel[] $parcels
	 * @return int[] shipment row ids
	 */
	public function create_for_order( \WC_Order $order, array $parcels, string $source, array $opts = [] ): array {
		if ( empty( $opts['additional'] ) && $this->is_labelled( $order ) ) {
			throw new \LogicException( \__( 'This order already has a label. Void it first, or tick "additional package".', 'anchor-schema' ) );
		}
		$s          = Settings::all();
		$carrier_id = (string) ( $opts['carrier'] ?? $s['default_carrier'] );
		$service    = (string) ( $opts['service'] ?? $s['default_service'] );
		$carrier    = $this->carriers->get( $carrier_id );
		if ( ! $carrier || ! $carrier->supports( 'labels' ) ) {
			throw new CarrierError( 'unknown_carrier', \__( 'That carrier is not available for labels.', 'anchor-schema' ) );
		}
		$to = Address::from_order_shipping( $order );
		if ( ! $to->is_complete() ) {
			throw new CarrierError( 'address', \__( 'The order is missing part of its shipping address.', 'anchor-schema' ) );
		}

		$protection = Protection::for_order( $order, $this->packer->shippable_subtotal( $order ) );
		$declared   = isset( $opts['declared_value'] ) ? max( 0.0, (float) $opts['declared_value'] ) : $protection['declared_value'];
		$signature  = isset( $opts['signature'] ) ? (bool) $opts['signature'] : $protection['signature'];

		$result = $carrier->create_label(
			new ShipmentRequest(
				Settings::ship_from(),
				$to,
				array_values( $parcels ),
				$service,
				sprintf( 'Order %s', $order->get_order_number() ),
				$declared,
				$signature,
				$order->get_currency() ?: 'USD'
			)
		);

		$ids = [];
		foreach ( $result->packages as $i => $pkg ) {
			$parcel = $parcels[ $i ] ?? end( $parcels );
			$saved  = $this->store->save( $order->get_id(), $pkg );
			$ids[]  = $this->shipments->insert(
				[
					'order_id'        => $order->get_id(),
					'carrier'         => $carrier_id,
					'service'         => $service,
					'shipment_id'     => $result->shipment_id,
					'tracking_number' => $pkg->tracking_number,
					'cost'            => 0 === $i ? $result->cost : null,
					'currency'        => $result->currency,
					'declared_value'  => count( $result->packages ) ? round( $declared / count( $result->packages ), 2 ) : 0,
					'signature'       => $signature ? 1 : 0,
					'label_path'      => $saved['path'],
					'label_format'    => $saved['format'],
					'box'             => $parcel->box_id,
					'weight_kg'       => $parcel->weight_kg,
					'dims_cm'         => $parcel->dims_label(),
					'source'          => $source,
				]
			);
		}

		$services = $carrier->services();
		$order->add_order_note(
			sprintf(
				/* translators: 1: carrier, 2: tracking numbers, 3: service, 4: cost */
				\__( '%1$s label created: %2$s (%3$s%4$s).', 'anchor-schema' ),
				$carrier->label(),
				implode( ', ', array_map( static fn( $p ) => $p->tracking_number, $result->packages ) ),
				$services[ $service ] ?? $service,
				null !== $result->cost ? ', ' . \wp_strip_all_tags( \wc_price( $result->cost, [ 'currency' => $result->currency ] ) ) : ''
			)
		);
		$order->update_meta_data( self::STATE_META, 'labelled' );
		$order->save();

		\do_action( 'anchor_shipping_label_created', $order->get_id(), $ids );
		return $ids;
	}

	public function void_shipment( int $row_id ): void {
		$row = $this->shipments->find( $row_id );
		if ( ! $row || 'voided' === $row['status'] ) {
			return;
		}
		$carrier = $this->carriers->get( $row['carrier'] );
		if ( ! $carrier || ! $carrier->supports( 'void' ) ) {
			throw new CarrierError( 'unsupported', \__( 'This carrier cannot void labels here.', 'anchor-schema' ) );
		}
		$carrier->void_label( $row['shipment_id'] );

		$now = \current_time( 'mysql', true );
		foreach ( $this->shipments->by_shipment_id( $row['carrier'], $row['shipment_id'] ) as $r ) {
			$this->shipments->update( (int) $r['id'], [ 'status' => 'voided', 'voided_at' => $now ] );
		}

		$order = \wc_get_order( (int) $row['order_id'] );
		if ( $order ) {
			/* translators: 1: carrier, 2: shipment id */
			$order->add_order_note( sprintf( \__( '%1$s label voided: %2$s.', 'anchor-schema' ), $carrier->label(), $row['shipment_id'] ) );
			if ( ! $this->is_labelled( $order ) ) {
				$order->update_meta_data( self::STATE_META, '' );
			}
			$order->save();
		}
		\do_action( 'anchor_shipping_label_voided', (int) $row['order_id'], $row['shipment_id'] );
	}

	public function mark_needs_attention( \WC_Order $order, string $reason ): void {
		$order->update_meta_data( self::STATE_META, 'needs_attention' );
		/* translators: %s: reason */
		$order->add_order_note( sprintf( \__( 'Shipping label needed: %s', 'anchor-schema' ), $reason ) );
		$order->save();
		\do_action( 'anchor_shipping_needs_attention', $order->get_id(), $reason );
	}
}
```

- [ ] **Step 5: Wire it**

In `Module` add properties `public Services\LabelStore $store; public Packing\Packer $packer; public Services\LabelService $labels;` and below the carriers line:

```php
		$this->store  = new Services\LabelStore();
		$this->packer = new Packing\Packer();
		$this->labels = new Services\LabelService( $this->carriers, $this->shipments, $this->store, $this->packer );
```

- [ ] **Step 6: Run tests**

Run: `vendor/bin/phpunit tests/test-shipping-label-service.php tests/test-shipping-packer.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
composer dump-autoload --no-dev
git add anchor-shipping/ tests/test-shipping-label-service.php
git commit -m "feat(shipping): LabelService (create/void/needs-attention) and protection resolver

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 10: Order panel and admin AJAX

**Files:**
- Create: `anchor-shipping/src/Admin/OrderPanel.php`
- Create: `anchor-shipping/src/Admin/Ajax.php`
- Create: `anchor-shipping/assets/admin.js`, `anchor-shipping/assets/admin.css`
- Modify: `anchor-shipping/anchor-shipping.php`
- Test: `tests/test-shipping-admin-ajax.php`

**Interfaces:**
- Consumes: `LabelService`, `ShipmentRepository`, `LabelStore`, `Packer`, `CarrierRegistry`, `Settings`, `Protection`.
- Produces: metabox id `anchor-shipping` on `wc_get_page_screen_id( 'shop-order' )` (works for HPOS and legacy). `OrderPanel::render_panel(\WC_Order $order): string` (HTML, used by the metabox and returned by AJAX after each action).
- Produces: `Ajax::NONCE = 'anchor_shipping'`; actions `anchor_shipping_create_label`, `anchor_shipping_void_label`, `anchor_shipping_label` (download). Pure handlers: `handle_create(array $post): array{html:string}`, `handle_void(array $post): array{html:string}`, `handle_download(int $row_id): array{path:string, type:string, filename:string}`; all throw `\RuntimeException` with a staff-safe message on failure. `authorized(): bool` = `current_user_can( 'edit_shop_orders' )`.
- Produces: `Ajax::download_url(int $row_id): string` (admin-ajax URL with nonce).

- [ ] **Step 1: Write the failing tests**

`tests/test-shipping-admin-ajax.php`:

```php
<?php
use Anchor\Shipping\Admin\Ajax;
use Anchor\Shipping\Module;

class Test_Shipping_Admin_Ajax extends Anchor_Shipping_TestCase {

	private function ajax(): Ajax {
		$m = Module::instance();
		return new Ajax( $m->labels, $m->shipments, $m->store, $m->packer, $m->carriers );
	}

	private function as_role( string $role ): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => $role ] ) );
	}

	public function test_create_with_a_box_preset_builds_the_parcel_and_returns_the_panel() {
		$this->configure_ups();
		$this->as_role( 'shop_manager' );
		$order = $this->make_order( [], 'processing' );
		$this->queue_token();
		$this->queue_response( 200, $this->fixture( 'ups-ship-single' ) );

		$out = $this->ajax()->handle_create( [ 'order_id' => $order->get_id(), 'carrier' => 'ups', 'service' => '03', 'box' => 'small', 'weight' => '1' ] );

		$this->assertStringContainsString( '1ZK877V90300000001', $out['html'] );
		$body = json_decode( $this->requests[1]['args']['body'], true );
		$this->assertSame( '2.2', $body['ShipmentRequest']['Shipment']['Package'][0]['PackageWeight']['Weight'] );
		$this->assertSame( '10', $body['ShipmentRequest']['Shipment']['Package'][0]['Dimensions']['Length'] );
	}

	public function test_create_rejects_missing_weight_and_custom_dims_without_values() {
		$this->configure_ups();
		$this->as_role( 'shop_manager' );
		$order = $this->make_order( [], 'processing' );
		foreach ( [ [ 'box' => 'small', 'weight' => '0' ], [ 'box' => 'custom', 'weight' => '1', 'length' => '', 'width' => '1', 'height' => '1' ] ] as $bad ) {
			try {
				$this->ajax()->handle_create( [ 'order_id' => $order->get_id() ] + $bad );
				$this->fail( 'expected RuntimeException' );
			} catch ( RuntimeException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
		$this->assertCount( 0, $this->requests );
	}

	public function test_customers_are_not_authorized() {
		$this->as_role( 'customer' );
		$this->assertFalse( $this->ajax()->authorized() );
		$this->as_role( 'shop_manager' );
		$this->assertTrue( $this->ajax()->authorized() );
	}

	public function test_download_resolves_the_file_and_rejects_unknown_rows() {
		$this->configure_ups();
		$this->as_role( 'shop_manager' );
		$order = $this->make_order( [], 'processing' );
		$this->queue_token();
		$this->queue_response( 200, $this->fixture( 'ups-ship-single' ) );
		$ids = Module::instance()->labels->create_for_order( $order, [ new \Anchor\Shipping\Domain\Parcel( 1, 10, 10, 10 ) ], 'manual' );

		$dl = $this->ajax()->handle_download( $ids[0] );
		$this->assertFileExists( $dl['path'] );
		$this->assertSame( 'application/pdf', $dl['type'] );
		$this->assertSame( 'label-1ZK877V90300000001.pdf', $dl['filename'] );

		$this->expectException( RuntimeException::class );
		$this->ajax()->handle_download( 999999 );
	}

	public function test_panel_shows_protection_defaults_and_hides_create_when_labelled() {
		$this->configure_ups( [ 'insurance' => [ 'mode' => 'auto', 'threshold' => 50 ] ] );
		$this->as_role( 'shop_manager' );
		$order = $this->make_order( [], 'processing' );
		$m     = Module::instance();
		$panel = ( new \Anchor\Shipping\Admin\OrderPanel( $m->shipments, $m->packer, $m->carriers ) )->render_panel( $order );
		$this->assertMatchesRegularExpression( '/name="insure" value="1"\s+checked=/', $panel );
		$this->assertStringContainsString( 'anchor-shipping-create', $panel );
	}
}
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit tests/test-shipping-admin-ajax.php`
Expected: FAIL — `Class "Anchor\Shipping\Admin\Ajax" not found`.

- [ ] **Step 3: Implement OrderPanel**

`anchor-shipping/src/Admin/OrderPanel.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Shipping\Admin;

use Anchor\Shipping\Carriers\CarrierRegistry;
use Anchor\Shipping\Database\ShipmentRepository;
use Anchor\Shipping\Packing\Packer;
use Anchor\Shipping\Services\Protection;
use Anchor\Shipping\Support\Settings;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** The Shipping box on the order screen (HPOS and legacy). */
final class OrderPanel {

	public function __construct(
		private ShipmentRepository $shipments,
		private Packer $packer,
		private CarrierRegistry $carriers
	) {
		\add_action( 'add_meta_boxes', [ $this, 'register' ] );
		\add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
	}

	public function register(): void {
		\add_meta_box( 'anchor-shipping', \__( 'Shipping label', 'anchor-schema' ), [ $this, 'metabox' ], \wc_get_page_screen_id( 'shop-order' ), 'side', 'high' );
	}

	public function assets( string $hook ): void {
		$screen = \get_current_screen();
		if ( ! $screen || \wc_get_page_screen_id( 'shop-order' ) !== $screen->id ) {
			return;
		}
		\wp_enqueue_style( 'anchor-shipping-admin', ANCHOR_TOOLS_PLUGIN_URL . 'anchor-shipping/assets/admin.css', [], '1.0.0' );
		\wp_enqueue_script( 'anchor-shipping-admin', ANCHOR_TOOLS_PLUGIN_URL . 'anchor-shipping/assets/admin.js', [ 'jquery' ], '1.0.0', true );
		\wp_localize_script( 'anchor-shipping-admin', 'anchorShipping', [ 'ajax' => \admin_url( 'admin-ajax.php' ), 'nonce' => \wp_create_nonce( Ajax::NONCE ), 'confirmVoid' => \__( 'Void this label? It cannot be used afterwards.', 'anchor-schema' ) ] );
	}

	/** @param \WP_Post|\WC_Order $post_or_order */
	public function metabox( $post_or_order ): void {
		$order = $post_or_order instanceof \WC_Order ? $post_or_order : \wc_get_order( $post_or_order->ID );
		if ( $order ) {
			echo $this->render_panel( $order ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in render_panel()
		}
	}

	public function render_panel( \WC_Order $order ): string {
		$rows       = $this->shipments->for_order( $order->get_id() );
		$active     = array_filter( $rows, static fn( $r ) => 'voided' !== $r['status'] );
		$s          = Settings::all();
		$pack       = $this->packer->pack_order( $order );
		$protection = Protection::for_order( $order, $this->packer->shippable_subtotal( $order ) );
		$boxes      = Settings::boxes();

		ob_start();
		?>
		<div class="anchor-shipping-panel" data-order="<?php echo (int) $order->get_id(); ?>">
			<?php foreach ( $rows as $r ) :
				$carrier = $this->carriers->get( $r['carrier'] );
				$voided  = 'voided' === $r['status'];
				?>
				<div class="anchor-shipping-row<?php echo $voided ? ' is-voided' : ''; ?>">
					<strong><?php echo \esc_html( $r['tracking_number'] ); ?></strong>
					<span class="anchor-shipping-meta"><?php echo \esc_html( trim( ( $carrier ? $carrier->label() : $r['carrier'] ) . ' · ' . $r['status'] . ( (float) $r['declared_value'] > 0 ? ' · insured' : '' ) . ( '1' === (string) $r['signature'] ? ' · signature' : '' ) ) ); ?></span>
					<?php if ( ! $voided ) : ?>
						<a class="button button-small" target="_blank" href="<?php echo \esc_url( Ajax::download_url( (int) $r['id'] ) ); ?>"><?php \esc_html_e( 'Print', 'anchor-schema' ); ?></a>
						<?php if ( $carrier ) : ?><a class="button button-small" target="_blank" rel="noopener" href="<?php echo \esc_url( $carrier->tracking_url( $r['tracking_number'] ) ); ?>"><?php \esc_html_e( 'Track', 'anchor-schema' ); ?></a><?php endif; ?>
						<button type="button" class="button button-small anchor-shipping-void" data-id="<?php echo (int) $r['id']; ?>"><?php \esc_html_e( 'Void', 'anchor-schema' ); ?></button>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>

			<?php if ( ! $pack->ok() && '' !== $pack->reason && ! $active ) : ?>
				<p class="anchor-shipping-note"><?php echo \esc_html( $pack->reason ); ?></p>
			<?php endif; ?>

			<div class="anchor-shipping-create">
				<?php if ( $active ) : ?>
					<label><input type="checkbox" name="additional" value="1"> <?php \esc_html_e( 'Additional package', 'anchor-schema' ); ?></label>
				<?php endif; ?>
				<p><select name="carrier"><?php foreach ( $this->carriers->all() as $id => $c ) : if ( ! $c->supports( 'labels' ) ) { continue; } ?><option value="<?php echo \esc_attr( $id ); ?>" <?php \selected( $s['default_carrier'], $id ); ?>><?php echo \esc_html( $c->label() ); ?></option><?php endforeach; ?></select>
				<select name="service"><?php foreach ( ( $this->carriers->get( $s['default_carrier'] ) ?? $this->carriers->get( 'ups' ) )->services() as $code => $label ) : ?><option value="<?php echo \esc_attr( (string) $code ); ?>" <?php \selected( $s['default_service'], (string) $code ); ?>><?php echo \esc_html( $label ); ?></option><?php endforeach; ?></select></p>
				<p><select name="box">
					<?php foreach ( $boxes as $id => $b ) : ?><option value="<?php echo \esc_attr( $id ); ?>" <?php \selected( $pack->suggestion['box'] ?? '', $id ); ?>><?php echo \esc_html( $b['name'] ); ?></option><?php endforeach; ?>
					<option value="custom" <?php \selected( ! $boxes ); ?>><?php \esc_html_e( 'Custom size…', 'anchor-schema' ); ?></option>
				</select></p>
				<p class="anchor-shipping-custom">
					<?php foreach ( [ 'length', 'width', 'height' ] as $d ) : ?><input type="text" size="3" name="<?php echo \esc_attr( $d ); ?>" placeholder="<?php echo \esc_attr( ucfirst( $d[0] ) ); ?>"><?php endforeach; ?>
					<?php echo \esc_html( (string) \get_option( 'woocommerce_dimension_unit' ) ); ?>
				</p>
				<p><label><?php \esc_html_e( 'Package weight', 'anchor-schema' ); ?> <input type="text" size="5" name="weight" value="<?php echo \esc_attr( isset( $pack->suggestion['weight'] ) ? (string) $pack->suggestion['weight'] : '' ); ?>"> <?php echo \esc_html( (string) \get_option( 'woocommerce_weight_unit' ) ); ?></label></p>
				<p><label><input type="checkbox" name="insure" value="1" <?php \checked( $protection['declared_value'] > 0 ); ?>> <?php \esc_html_e( 'Insure for order value', 'anchor-schema' ); ?></label><br>
				<label><input type="checkbox" name="signature" value="1" <?php \checked( $protection['signature'] ); ?>> <?php \esc_html_e( 'Signature required', 'anchor-schema' ); ?></label></p>
				<p><button type="button" class="button button-primary anchor-shipping-submit"><?php \esc_html_e( 'Create label', 'anchor-schema' ); ?></button></p>
				<p class="anchor-shipping-error" role="alert"></p>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
```

- [ ] **Step 4: Implement Ajax**

`anchor-shipping/src/Admin/Ajax.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Shipping\Admin;

use Anchor\Shipping\Carriers\CarrierRegistry;
use Anchor\Shipping\Database\ShipmentRepository;
use Anchor\Shipping\Domain\CarrierError;
use Anchor\Shipping\Domain\Parcel;
use Anchor\Shipping\Packing\Packer;
use Anchor\Shipping\Services\LabelService;
use Anchor\Shipping\Services\LabelStore;
use Anchor\Shipping\Support\Settings;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** wp_ajax wrappers do nonce + capability; handle_* do the work and are unit-tested directly. */
final class Ajax {

	public const NONCE = 'anchor_shipping';

	public function __construct(
		private LabelService $labels,
		private ShipmentRepository $shipments,
		private LabelStore $store,
		private Packer $packer,
		private CarrierRegistry $carriers
	) {
		\add_action( 'wp_ajax_anchor_shipping_create_label', [ $this, 'ajax_create' ] );
		\add_action( 'wp_ajax_anchor_shipping_void_label', [ $this, 'ajax_void' ] );
		\add_action( 'wp_ajax_anchor_shipping_label', [ $this, 'ajax_download' ] );
	}

	public static function download_url( int $row_id ): string {
		return \add_query_arg( [ 'action' => 'anchor_shipping_label', 'id' => $row_id, '_wpnonce' => \wp_create_nonce( self::NONCE ) ], \admin_url( 'admin-ajax.php' ) );
	}

	public function authorized(): bool {
		return \current_user_can( 'edit_shop_orders' );
	}

	public function ajax_create(): void {
		$this->json( fn() => $this->handle_create( \wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification -- checked in json()
	}

	public function ajax_void(): void {
		$this->json( fn() => $this->handle_void( \wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification -- checked in json()
	}

	public function ajax_download(): void {
		if ( ! \check_ajax_referer( self::NONCE, '_wpnonce', false ) || ! $this->authorized() ) {
			\wp_die( \esc_html__( 'You are not allowed to download this label.', 'anchor-schema' ), 403 );
		}
		try {
			$dl = $this->handle_download( (int) ( $_GET['id'] ?? 0 ) );
		} catch ( \RuntimeException $e ) {
			\wp_die( \esc_html( $e->getMessage() ), 404 );
		}
		\nocache_headers();
		header( 'Content-Type: ' . $dl['type'] );
		header( 'Content-Disposition: inline; filename="' . $dl['filename'] . '"' );
		readfile( $dl['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	public function handle_create( array $post ): array {
		$order = \wc_get_order( (int) ( $post['order_id'] ?? 0 ) );
		if ( ! $order ) {
			throw new \RuntimeException( \__( 'Order not found.', 'anchor-schema' ) );
		}
		$weight = (float) ( $post['weight'] ?? 0 );
		if ( $weight <= 0 ) {
			throw new \RuntimeException( \__( 'Enter the package weight.', 'anchor-schema' ) );
		}
		$box_id = (string) ( $post['box'] ?? 'custom' );
		$boxes  = Settings::boxes();
		if ( 'custom' !== $box_id && isset( $boxes[ $box_id ] ) ) {
			$b      = $boxes[ $box_id ];
			$parcel = Parcel::from_store_units( $weight, (float) $b['length'], (float) $b['width'], (float) $b['height'], $box_id );
		} else {
			$dims = array_map( static fn( $k ) => (float) ( $post[ $k ] ?? 0 ), [ 'length', 'width', 'height' ] );
			if ( min( $dims ) <= 0 ) {
				throw new \RuntimeException( \__( 'Enter length, width and height for a custom box.', 'anchor-schema' ) );
			}
			$parcel = Parcel::from_store_units( $weight, $dims[0], $dims[1], $dims[2] );
		}

		$subtotal = $this->packer->shippable_subtotal( $order );
		try {
			$this->labels->create_for_order(
				$order,
				[ $parcel ],
				'manual',
				[
					'carrier'        => \sanitize_key( (string) ( $post['carrier'] ?? '' ) ) ?: null,
					'service'        => \sanitize_text_field( (string) ( $post['service'] ?? '' ) ) ?: null,
					'additional'     => ! empty( $post['additional'] ),
					'declared_value' => ! empty( $post['insure'] ) ? $subtotal : 0.0,
					'signature'      => ! empty( $post['signature'] ),
				]
			);
		} catch ( CarrierError | \LogicException $e ) {
			throw new \RuntimeException( $e->getMessage() );
		}
		return [ 'html' => $this->panel( $order ) ];
	}

	public function handle_void( array $post ): array {
		$row = $this->shipments->find( (int) ( $post['id'] ?? 0 ) );
		if ( ! $row ) {
			throw new \RuntimeException( \__( 'Label not found.', 'anchor-schema' ) );
		}
		try {
			$this->labels->void_shipment( (int) $row['id'] );
		} catch ( CarrierError $e ) {
			throw new \RuntimeException( $e->getMessage() );
		}
		return [ 'html' => $this->panel( \wc_get_order( (int) $row['order_id'] ) ) ];
	}

	/** @return array{path:string, type:string, filename:string} */
	public function handle_download( int $row_id ): array {
		$row  = $this->shipments->find( $row_id );
		$path = $row ? $this->store->absolute( $row['label_path'] ) : null;
		if ( ! $row || ! $path ) {
			throw new \RuntimeException( \__( 'Label file not found.', 'anchor-schema' ) );
		}
		return [
			'path'     => $path,
			'type'     => $this->store->content_type( $row['label_format'] ),
			'filename' => 'label-' . preg_replace( '/[^A-Za-z0-9]/', '', $row['tracking_number'] ) . ( 'ZPL' === $row['label_format'] ? '.zpl' : '.pdf' ),
		];
	}

	private function panel( \WC_Order $order ): string {
		return ( new OrderPanel( $this->shipments, $this->packer, $this->carriers ) )->render_panel( $order );
	}

	private function json( callable $fn ): void {
		if ( ! \check_ajax_referer( self::NONCE, 'nonce', false ) || ! $this->authorized() ) {
			\wp_send_json_error( [ 'message' => \__( 'Your session expired or you lack permission. Reload the page.', 'anchor-schema' ) ], 403 );
		}
		try {
			\wp_send_json_success( $fn() );
		} catch ( \RuntimeException $e ) {
			\wp_send_json_error( [ 'message' => $e->getMessage() ], 400 );
		}
	}
}
```

Note: constructing `OrderPanel` inside `panel()` re-registers its hooks; that is harmless on an AJAX request (no `add_meta_boxes` fires), but keep `OrderPanel`'s constructor side-effect-free beyond `add_action`.

- [ ] **Step 5: Assets**

`anchor-shipping/assets/admin.js`:

```js
(function ($) {
	'use strict';
	var cfg = window.anchorShipping || {};

	function panelData($panel) {
		var data = { nonce: cfg.nonce, order_id: $panel.data('order') };
		$panel.find('.anchor-shipping-create').find('input, select').each(function () {
			if (this.type === 'checkbox') { if (this.checked) { data[this.name] = 1; } return; }
			data[this.name] = $(this).val();
		});
		return data;
	}

	function send($panel, action, data) {
		$panel.addClass('is-busy').find('.anchor-shipping-error').text('');
		$.post(cfg.ajax, $.extend({ action: action }, data))
			.done(function (res) {
				if (res && res.success) { $panel.replaceWith(res.data.html); toggleCustom(); return; }
				$panel.find('.anchor-shipping-error').text((res && res.data && res.data.message) || 'Something went wrong.');
			})
			.fail(function (xhr) {
				var msg = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message;
				$panel.find('.anchor-shipping-error').text(msg || 'Request failed.');
			})
			.always(function () { $panel.removeClass('is-busy'); });
	}

	function toggleCustom() {
		$('.anchor-shipping-panel').each(function () {
			var custom = $(this).find('select[name="box"]').val() === 'custom';
			$(this).find('.anchor-shipping-custom').toggle(custom);
		});
	}

	$(document).on('click', '.anchor-shipping-submit', function () {
		var $panel = $(this).closest('.anchor-shipping-panel');
		if ($panel.hasClass('is-busy')) { return; }
		send($panel, 'anchor_shipping_create_label', panelData($panel));
	});

	$(document).on('click', '.anchor-shipping-void', function () {
		var $panel = $(this).closest('.anchor-shipping-panel');
		if ($panel.hasClass('is-busy') || !window.confirm(cfg.confirmVoid)) { return; }
		send($panel, 'anchor_shipping_void_label', { nonce: cfg.nonce, id: $(this).data('id') });
	});

	$(document).on('change', '.anchor-shipping-panel select[name="box"]', toggleCustom);
	$(toggleCustom);
})(jQuery);
```

(`window.confirm` runs only in a staff member's admin browser on click; it is the standard WP-admin confirm pattern.)

`anchor-shipping/assets/admin.css`:

```css
.anchor-shipping-panel.is-busy { opacity: .5; pointer-events: none; }
.anchor-shipping-row { display: flex; flex-wrap: wrap; gap: 4px; align-items: center; padding: 6px 0; border-bottom: 1px solid #f0f0f1; }
.anchor-shipping-row strong { flex: 1 1 100%; font-family: monospace; }
.anchor-shipping-row.is-voided { opacity: .55; text-decoration: line-through; }
.anchor-shipping-meta { flex: 1 1 100%; color: #646970; font-size: 12px; }
.anchor-shipping-note { background: #fcf9e8; border-left: 3px solid #dba617; padding: 6px 8px; }
.anchor-shipping-custom input { width: 4em; }
.anchor-shipping-error { color: #b32d2e; }
```

- [ ] **Step 6: Wire it**

In `Module`'s `is_admin()` block:

```php
			new Admin\OrderPanel( $this->shipments, $this->packer, $this->carriers );
			new Admin\Ajax( $this->labels, $this->shipments, $this->store, $this->packer, $this->carriers );
```

`is_admin()` is true for `admin-ajax.php`, so the AJAX hooks register.

- [ ] **Step 7: Run tests**

Run: `vendor/bin/phpunit tests/test-shipping-admin-ajax.php`
Expected: PASS (5 tests).

- [ ] **Step 8: Commit**

```bash
composer dump-autoload --no-dev
git add anchor-shipping/ tests/test-shipping-admin-ajax.php
git commit -m "feat(shipping): order Shipping-label panel with create/print/track/void

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 11: Shipping-inbox emails (label + ready to ship)

**Files:**
- Create: `anchor-shipping/src/Emails/LabelEmail.php`, `anchor-shipping/src/Emails/ReadyToShipEmail.php`, `anchor-shipping/src/Emails/Registry.php`
- Create: `anchor-shipping/templates/emails/label.php`, `label-plain.php`, `ready-to-ship.php`, `ready-to-ship-plain.php`
- Modify: `anchor-shipping/anchor-shipping.php`
- Test: `tests/test-shipping-emails.php`

**Interfaces:**
- Consumes: actions `anchor_shipping_label_created (int $order_id, int[] $ids)` and `anchor_shipping_needs_attention (int $order_id, string $reason)` (Task 9); `ShipmentRepository`, `LabelStore`, `Packer`, `Settings::inbox()`.
- Produces: `Registry::register(array $emails): array` (filter `woocommerce_email_classes`) and `Registry::actions(array $actions): array` (filter `woocommerce_email_actions`, adds both action names so Woo loads the mailer and calls `{action}_notification`).
- Produces: email ids `anchor_shipping_label` (PDF/ZPL attached) and `anchor_shipping_ready_to_ship`; both go to `Settings::inbox()`, never the customer.

- [ ] **Step 1: Write the failing tests**

`tests/test-shipping-emails.php`:

```php
<?php
use Anchor\Shipping\Domain\Parcel;
use Anchor\Shipping\Module;

class Test_Shipping_Emails extends Anchor_Shipping_TestCase {

	public function set_up() {
		parent::set_up();
		reset_phpmailer_instance();
	}

	private function sent(): array {
		return tests_retrieve_phpmailer_instance()->mock_sent;
	}

	public function test_label_email_goes_to_the_inbox_with_the_label_attached_and_nothing_to_the_customer() {
		$this->configure_ups();
		$order = $this->make_order( [], 'pending' );
		reset_phpmailer_instance();
		$this->queue_token();
		$this->queue_response( 200, $this->fixture( 'ups-ship-single' ) );
		Module::instance()->labels->create_for_order( $order, [ new Parcel( 1, 10, 10, 10 ) ], 'manual' );

		$sent = $this->sent();
		$this->assertCount( 1, $sent );
		$this->assertSame( 'shipping@example.com', $sent[0]['to'][0][0] );
		$this->assertStringContainsString( (string) $order->get_order_number(), $sent[0]['subject'] );
		$this->assertStringContainsString( '1ZK877V90300000001', $sent[0]['body'] );
		$attachments = tests_retrieve_phpmailer_instance()->getAttachments();
		$this->assertCount( 1, $attachments );
		$this->assertStringEndsWith( '.pdf', $attachments[0][0] );
	}

	public function test_ready_to_ship_email_carries_reason_and_create_link() {
		$this->configure_ups();
		$order = $this->make_order( [], 'pending' );
		reset_phpmailer_instance();
		Module::instance()->labels->mark_needs_attention( $order, 'Banner Stand has no weight.' );

		$sent = $this->sent();
		$this->assertCount( 1, $sent );
		$this->assertSame( 'shipping@example.com', $sent[0]['to'][0][0] );
		$this->assertStringContainsString( 'Banner Stand has no weight.', $sent[0]['body'] );
		$this->assertStringContainsString( '#anchor-shipping', $sent[0]['body'] );
		$this->assertStringContainsString( 'Cupertino', $sent[0]['body'] );
	}

	public function test_disabled_email_sends_nothing() {
		$this->configure_ups();
		update_option( 'woocommerce_anchor_shipping_ready_to_ship_settings', [ 'enabled' => 'no' ] );
		$order = $this->make_order( [], 'pending' );
		reset_phpmailer_instance();
		Module::instance()->labels->mark_needs_attention( $order, 'x' );
		$this->assertCount( 0, $this->sent() );
	}
}
```

(Orders are created as `pending` so Woo's own status emails don't land in the mock mailer.)

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit tests/test-shipping-emails.php`
Expected: FAIL — 0 emails sent.

- [ ] **Step 3: Implement the emails**

`anchor-shipping/src/Emails/Registry.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Shipping\Emails;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class Registry {

	public const ACTIONS = [ 'anchor_shipping_label_created', 'anchor_shipping_needs_attention' ];

	public static function register( array $emails ): array {
		$emails['Anchor_Shipping_Label_Email']         = new LabelEmail();
		$emails['Anchor_Shipping_Ready_To_Ship_Email'] = new ReadyToShipEmail();
		return $emails;
	}

	public static function actions( array $actions ): array {
		return array_values( array_unique( array_merge( $actions, self::ACTIONS ) ) );
	}
}
```

`anchor-shipping/src/Emails/LabelEmail.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Shipping\Emails;

use Anchor\Shipping\Module;
use Anchor\Shipping\Support\Settings;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** To the shipping inbox: "here is the label for order #N", label attached. */
final class LabelEmail extends \WC_Email {

	/** @var array<int, array> shipment rows */
	public array $rows = [];

	public function __construct() {
		$this->id             = 'anchor_shipping_label';
		$this->title          = \__( 'Shipping label created', 'anchor-schema' );
		$this->description    = \__( 'Sent to the Anchor Shipping inbox with the label attached whenever a label is created.', 'anchor-schema' );
		$this->template_base  = Module::dir() . '/templates/';
		$this->template_html  = 'emails/label.php';
		$this->template_plain = 'emails/label-plain.php';
		$this->placeholders   = [ '{order_number}' => '' ];
		\add_action( 'anchor_shipping_label_created_notification', [ $this, 'trigger' ], 10, 2 );
		parent::__construct();
	}

	public function get_default_subject(): string {
		return \__( '[{site_title}] Label ready — order #{order_number}', 'anchor-schema' );
	}

	public function get_default_heading(): string {
		return \__( 'Label ready for order #{order_number}', 'anchor-schema' );
	}

	public function trigger( int $order_id, array $ids ): void {
		$this->setup_locale();
		$this->object    = \wc_get_order( $order_id );
		$this->recipient = Settings::inbox();
		$this->rows      = array_values( array_filter( array_map( [ Module::instance()->shipments, 'find' ], array_map( 'intval', $ids ) ) ) );
		if ( $this->object && $this->rows && $this->is_enabled() && $this->get_recipient() ) {
			$this->placeholders['{order_number}'] = $this->object->get_order_number();
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}
		$this->restore_locale();
	}

	public function get_attachments() {
		$paths = [];
		foreach ( $this->rows as $r ) {
			$path = Module::instance()->store->absolute( (string) $r['label_path'] );
			if ( $path ) {
				$paths[] = $path;
			}
		}
		return \apply_filters( 'woocommerce_email_attachments', $paths, $this->id, $this->object, $this );
	}

	public function get_content_html() {
		return \wc_get_template_html( $this->template_html, [ 'order' => $this->object, 'rows' => $this->rows, 'email_heading' => $this->get_heading(), 'email' => $this ], '', $this->template_base );
	}

	public function get_content_plain() {
		return \wc_get_template_html( $this->template_plain, [ 'order' => $this->object, 'rows' => $this->rows, 'email_heading' => $this->get_heading(), 'email' => $this ], '', $this->template_base );
	}
}
```

`anchor-shipping/src/Emails/ReadyToShipEmail.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Shipping\Emails;

use Anchor\Shipping\Module;
use Anchor\Shipping\Support\Settings;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** To the shipping inbox: "order #N needs a label", with a Create label button. */
final class ReadyToShipEmail extends \WC_Email {

	public string $reason = '';

	public function __construct() {
		$this->id             = 'anchor_shipping_ready_to_ship';
		$this->title          = \__( 'Ready to ship', 'anchor-schema' );
		$this->description    = \__( 'Sent to the Anchor Shipping inbox when a paid order needs someone to create its label.', 'anchor-schema' );
		$this->template_base  = Module::dir() . '/templates/';
		$this->template_html  = 'emails/ready-to-ship.php';
		$this->template_plain = 'emails/ready-to-ship-plain.php';
		$this->placeholders   = [ '{order_number}' => '' ];
		\add_action( 'anchor_shipping_needs_attention_notification', [ $this, 'trigger' ], 10, 2 );
		parent::__construct();
	}

	public function get_default_subject(): string {
		return \__( '[{site_title}] Ready to ship — order #{order_number}', 'anchor-schema' );
	}

	public function get_default_heading(): string {
		return \__( 'Order #{order_number} is ready to ship', 'anchor-schema' );
	}

	public function trigger( int $order_id, string $reason ): void {
		$this->setup_locale();
		$this->object    = \wc_get_order( $order_id );
		$this->recipient = Settings::inbox();
		$this->reason    = $reason;
		if ( $this->object && $this->is_enabled() && $this->get_recipient() ) {
			$this->placeholders['{order_number}'] = $this->object->get_order_number();
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}
		$this->restore_locale();
	}

	public function create_url(): string {
		return $this->object ? $this->object->get_edit_order_url() . '#anchor-shipping' : '';
	}

	public function get_content_html() {
		return \wc_get_template_html( $this->template_html, [ 'order' => $this->object, 'reason' => $this->reason, 'create_url' => $this->create_url(), 'email_heading' => $this->get_heading(), 'email' => $this ], '', $this->template_base );
	}

	public function get_content_plain() {
		return \wc_get_template_html( $this->template_plain, [ 'order' => $this->object, 'reason' => $this->reason, 'create_url' => $this->create_url(), 'email_heading' => $this->get_heading(), 'email' => $this ], '', $this->template_base );
	}
}
```

- [ ] **Step 4: Templates**

`anchor-shipping/templates/emails/label.php`:

```php
<?php
/** @var WC_Order $order @var array $rows @var string $email_heading @var WC_Email $email */
defined( 'ABSPATH' ) || exit;
do_action( 'woocommerce_email_header', $email_heading, $email );
?>
<p><?php echo esc_html( sprintf( /* translators: %s: order number */ __( 'The shipping label for order #%s is attached. Print it, pack the box and hand it to the carrier.', 'anchor-schema' ), $order->get_order_number() ) ); ?></p>
<ul>
	<?php foreach ( $rows as $r ) : ?>
		<li><strong><?php echo esc_html( $r['tracking_number'] ); ?></strong><?php echo (float) $r['declared_value'] > 0 ? esc_html__( ' — insured', 'anchor-schema' ) : ''; ?><?php echo '1' === (string) $r['signature'] ? esc_html__( ' — signature required', 'anchor-schema' ) : ''; ?></li>
	<?php endforeach; ?>
</ul>
<p><strong><?php esc_html_e( 'Ship to', 'anchor-schema' ); ?></strong><br><?php echo wp_kses_post( $order->get_formatted_shipping_address() ?: $order->get_formatted_billing_address() ); ?></p>
<?php do_action( 'woocommerce_email_order_details', $order, true, false, $email ); ?>
<p><a href="<?php echo esc_url( $order->get_edit_order_url() . '#anchor-shipping' ); ?>"><?php esc_html_e( 'Open the order to reprint or void the label', 'anchor-schema' ); ?></a></p>
<?php
do_action( 'woocommerce_email_footer', $email );
```

`anchor-shipping/templates/emails/label-plain.php`:

```php
<?php
/** @var WC_Order $order @var array $rows @var string $email_heading */
defined( 'ABSPATH' ) || exit;
echo esc_html( wp_strip_all_tags( $email_heading ) ) . "\n\n";
echo esc_html( sprintf( /* translators: %s: order number */ __( 'The shipping label for order #%s is attached.', 'anchor-schema' ), $order->get_order_number() ) ) . "\n\n";
foreach ( $rows as $r ) {
	echo esc_html( $r['tracking_number'] ) . "\n";
}
echo "\n" . esc_html( wp_strip_all_tags( str_replace( '<br/>', "\n", (string) ( $order->get_formatted_shipping_address() ?: $order->get_formatted_billing_address() ) ) ) ) . "\n\n";
echo esc_url_raw( $order->get_edit_order_url() . '#anchor-shipping' ) . "\n";
```

`anchor-shipping/templates/emails/ready-to-ship.php`:

```php
<?php
/** @var WC_Order $order @var string $reason @var string $create_url @var string $email_heading @var WC_Email $email */
defined( 'ABSPATH' ) || exit;
do_action( 'woocommerce_email_header', $email_heading, $email );
?>
<p><?php esc_html_e( 'This order is paid and needs a shipping label.', 'anchor-schema' ); ?></p>
<?php if ( '' !== $reason ) : ?>
	<p style="background:#fcf9e8;border-left:3px solid #dba617;padding:8px 10px;"><?php echo esc_html( $reason ); ?></p>
<?php endif; ?>
<p><strong><?php esc_html_e( 'Ship to', 'anchor-schema' ); ?></strong><br><?php echo wp_kses_post( $order->get_formatted_shipping_address() ?: $order->get_formatted_billing_address() ); ?></p>
<?php do_action( 'woocommerce_email_order_details', $order, true, false, $email ); ?>
<p style="margin:24px 0;"><a href="<?php echo esc_url( $create_url ); ?>" style="background:#040541;color:#ffffff;padding:12px 20px;text-decoration:none;border-radius:4px;display:inline-block;"><?php esc_html_e( 'Create label', 'anchor-schema' ); ?></a></p>
<p style="font-size:12px;color:#646970;"><?php esc_html_e( 'You will be asked to log in if you are not already.', 'anchor-schema' ); ?></p>
<?php
do_action( 'woocommerce_email_footer', $email );
```

(The button colour is a plain inline style so it renders in mail clients; Woo's email styling owns everything else.)

`anchor-shipping/templates/emails/ready-to-ship-plain.php`:

```php
<?php
/** @var WC_Order $order @var string $reason @var string $create_url @var string $email_heading */
defined( 'ABSPATH' ) || exit;
echo esc_html( wp_strip_all_tags( $email_heading ) ) . "\n\n";
esc_html_e( 'This order is paid and needs a shipping label.', 'anchor-schema' );
echo "\n\n" . ( '' !== $reason ? esc_html( $reason ) . "\n\n" : '' );
echo esc_html( wp_strip_all_tags( str_replace( '<br/>', "\n", (string) ( $order->get_formatted_shipping_address() ?: $order->get_formatted_billing_address() ) ) ) ) . "\n\n";
echo esc_html__( 'Create label:', 'anchor-schema' ) . ' ' . esc_url_raw( $create_url ) . "\n";
```

- [ ] **Step 5: Wire it**

In `Module` (outside `is_admin()`, after the services):

```php
		\add_filter( 'woocommerce_email_classes', [ Emails\Registry::class, 'register' ] );
		\add_filter( 'woocommerce_email_actions', [ Emails\Registry::class, 'actions' ] );
```

If Woo has already built its mailer before the module boots (it hasn't at `plugins_loaded` 25, but tests reuse one WC instance), `WC()->mailer()` would miss the classes. In the test `set_up`, if `test_label_email…` fails with 0 emails, add `WC()->mailer()->init();` after `reset_phpmailer_instance()` in `Test_Shipping_Emails::set_up()` — and only there.

- [ ] **Step 6: Run tests**

Run: `vendor/bin/phpunit tests/test-shipping-emails.php`
Expected: PASS (3 tests).

- [ ] **Step 7: Commit**

```bash
composer dump-autoload --no-dev
git add anchor-shipping/ tests/test-shipping-emails.php
git commit -m "feat(shipping): label and ready-to-ship emails to the shipping inbox

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 12: Paid-order handling (the auto-label checkbox)

**Files:**
- Create: `anchor-shipping/src/Services/PaidOrderHandler.php`
- Modify: `anchor-shipping/anchor-shipping.php`
- Test: `tests/test-shipping-paid-orders.php`

**Interfaces:**
- Consumes: `LabelService`, `Packer`, `Settings::auto_label()`, `CarrierError`.
- Produces: `PaidOrderHandler::ACTION = 'anchor_shipping_process_paid_order'`, `GROUP = 'anchor-shipping'`, `MAX_ATTEMPTS = 3`; `queue(int $order_id): void` (on `woocommerce_order_status_processing`); `run(int $order_id, int $attempt = 1): void` (the Action Scheduler callback); `eligible(\WC_Order): bool`.

- [ ] **Step 1: Write the failing tests**

`tests/test-shipping-paid-orders.php`:

```php
<?php
use Anchor\Shipping\Module;
use Anchor\Shipping\Services\LabelService;
use Anchor\Shipping\Services\PaidOrderHandler;

class Test_Shipping_Paid_Orders extends Anchor_Shipping_TestCase {

	private function handler(): PaidOrderHandler {
		return Module::instance()->paid_orders;
	}

	private function state( int $order_id ): string {
		return (string) wc_get_order( $order_id )->get_meta( LabelService::STATE_META );
	}

	public function test_paying_queues_one_async_job_and_skips_orders_without_shipping() {
		$this->configure_ups();
		$order = $this->make_order( [], 'pending' );
		$order->update_status( 'processing' );
		$this->assertTrue( (bool) as_has_scheduled_action( PaidOrderHandler::ACTION, [ $order->get_id(), 1 ], PaidOrderHandler::GROUP ) );

		$ticket_only = $this->make_order( [ $this->make_product( [ 'virtual' => true ] ) ], 'pending', false );
		$ticket_only->update_status( 'processing' );
		$this->assertFalse( (bool) as_has_scheduled_action( PaidOrderHandler::ACTION, [ $ticket_only->get_id(), 1 ], PaidOrderHandler::GROUP ) );
	}

	public function test_checkbox_off_sends_ready_to_ship_even_when_sizeable() {
		$this->configure_ups( [ 'auto_label' => false ] );
		$order = $this->make_order( [], 'processing' );
		$this->handler()->run( $order->get_id() );
		$this->assertSame( 'needs_attention', $this->state( $order->get_id() ) );
		$this->assertCount( 0, $this->requests, 'no carrier call' );
	}

	public function test_checkbox_on_and_sizeable_creates_the_label() {
		$this->configure_ups( [ 'auto_label' => true ] );
		$order = $this->make_order( [], 'processing' );
		$this->queue_token();
		$this->queue_response( 200, $this->fixture( 'ups-ship-single' ) );
		$this->handler()->run( $order->get_id() );
		$this->assertSame( 'labelled', $this->state( $order->get_id() ) );
		$this->assertSame( 'auto', Module::instance()->shipments->for_order( $order->get_id() )[0]['source'] );
	}

	public function test_checkbox_on_but_unsizeable_falls_back_to_ready_to_ship() {
		$this->configure_ups( [ 'auto_label' => true ] );
		$order = $this->make_order( [ $this->make_product( [ 'name' => 'Banner Stand' ] ) ], 'processing' );
		$this->handler()->run( $order->get_id() );
		$this->assertSame( 'needs_attention', $this->state( $order->get_id() ) );
		$this->assertCount( 0, $this->requests );
	}

	public function test_running_the_job_twice_creates_one_label() {
		$this->configure_ups( [ 'auto_label' => true ] );
		$order = $this->make_order( [], 'processing' );
		$this->queue_token();
		$this->queue_response( 200, $this->fixture( 'ups-ship-single' ) );
		$this->handler()->run( $order->get_id() );
		$this->handler()->run( $order->get_id() );
		$this->assertCount( 1, Module::instance()->shipments->for_order( $order->get_id() ) );
		$this->assertCount( 2, $this->requests );
	}

	public function test_retryable_error_reschedules_then_gives_up_to_a_person() {
		$this->configure_ups( [ 'auto_label' => true ] );
		$order = $this->make_order( [], 'processing' );
		$this->queue_token();
		$this->queue_response( 503, 'down' );
		$this->handler()->run( $order->get_id(), 1 );
		$this->assertTrue( (bool) as_has_scheduled_action( PaidOrderHandler::ACTION, [ $order->get_id(), 2 ], PaidOrderHandler::GROUP ) );
		$this->assertNotSame( 'needs_attention', $this->state( $order->get_id() ) );

		$this->queue_response( 503, 'down' ); // token cached
		$this->handler()->run( $order->get_id(), PaidOrderHandler::MAX_ATTEMPTS );
		$this->assertSame( 'needs_attention', $this->state( $order->get_id() ) );
	}

	public function test_non_retryable_error_goes_straight_to_a_person_with_the_carrier_message() {
		$this->configure_ups( [ 'auto_label' => true ] );
		$order = $this->make_order( [], 'processing' );
		$this->queue_token();
		$this->queue_response( 400, [ 'response' => [ 'errors' => [ [ 'code' => '120802', 'message' => 'Address validation failed' ] ] ] ] );
		$this->handler()->run( $order->get_id() );
		$this->assertSame( 'needs_attention', $this->state( $order->get_id() ) );
		$notes = implode( ' ', wp_list_pluck( wc_get_order_notes( [ 'order_id' => $order->get_id() ] ), 'content' ) );
		$this->assertStringContainsString( 'Address validation failed', $notes );
	}
}
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit tests/test-shipping-paid-orders.php`
Expected: FAIL — `Undefined property: Anchor\Shipping\Module::$paid_orders`.

- [ ] **Step 3: Implement**

`anchor-shipping/src/Services/PaidOrderHandler.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Shipping\Services;

use Anchor\Shipping\Domain\CarrierError;
use Anchor\Shipping\Packing\Packer;
use Anchor\Shipping\Support\Settings;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Paid order → background job. Checkbox off: ask a person (ready-to-ship email).
 * Checkbox on: create the label when the Packer can size it, otherwise ask a person.
 */
final class PaidOrderHandler {

	public const ACTION       = 'anchor_shipping_process_paid_order';
	public const GROUP        = 'anchor-shipping';
	public const MAX_ATTEMPTS = 3;

	public function __construct( private LabelService $labels, private Packer $packer ) {
		\add_action( 'woocommerce_order_status_processing', [ $this, 'queue' ], 20, 1 );
		\add_action( self::ACTION, [ $this, 'run' ], 10, 2 );
	}

	public function eligible( \WC_Order $order ): bool {
		return $order->needs_shipping_address()
			&& [] !== $order->get_shipping_methods()
			&& ! $this->labels->is_labelled( $order )
			&& 'needs_attention' !== $order->get_meta( LabelService::STATE_META );
	}

	public function queue( int $order_id ): void {
		$order = \wc_get_order( $order_id );
		if ( ! $order || ! $this->eligible( $order ) ) {
			return;
		}
		if ( \as_has_scheduled_action( self::ACTION, [ $order_id, 1 ], self::GROUP ) ) {
			return;
		}
		\as_enqueue_async_action( self::ACTION, [ $order_id, 1 ], self::GROUP );
	}

	public function run( int $order_id, int $attempt = 1 ): void {
		$order = \wc_get_order( $order_id );
		if ( ! $order || ! $this->eligible( $order ) ) {
			return; // already labelled or already waiting on a person
		}
		if ( ! Settings::auto_label() ) {
			$this->labels->mark_needs_attention( $order, '' );
			return;
		}
		$pack = $this->packer->pack_order( $order );
		if ( ! $pack->ok() ) {
			$this->labels->mark_needs_attention( $order, $pack->reason );
			return;
		}
		try {
			$this->labels->create_for_order( $order, $pack->parcels, 'auto' );
		} catch ( CarrierError $e ) {
			if ( $e->retryable && $attempt < self::MAX_ATTEMPTS ) {
				\as_schedule_single_action( time() + 300 * $attempt, self::ACTION, [ $order_id, $attempt + 1 ], self::GROUP );
				return;
			}
			/* translators: %s: carrier error message */
			$this->labels->mark_needs_attention( $order, sprintf( \__( 'The carrier refused the label: %s', 'anchor-schema' ), $e->getMessage() ) );
		} catch ( \LogicException $e ) {
			return; // labelled concurrently
		}
	}
}
```

Note: `needs_shipping_address()` is false for local-pickup orders and orders with only virtual items — exactly the "don't label" cases.

- [ ] **Step 4: Wire it**

In `Module` add `public Services\PaidOrderHandler $paid_orders;` and after the labels service: `$this->paid_orders = new Services\PaidOrderHandler( $this->labels, $this->packer );`

- [ ] **Step 5: Run tests**

Run: `vendor/bin/phpunit tests/test-shipping-paid-orders.php`
Expected: PASS (7 tests).

- [ ] **Step 6: Commit**

```bash
composer dump-autoload --no-dev
git add anchor-shipping/ tests/test-shipping-paid-orders.php
git commit -m "feat(shipping): paid-order job — ready-to-ship email, or auto label behind the checkbox

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 13: Void on cancel / full refund

**Files:**
- Create: `anchor-shipping/src/Services/VoidOnCancel.php`
- Modify: `anchor-shipping/anchor-shipping.php`
- Test: `tests/test-shipping-void-on-cancel.php`

**Interfaces:**
- Consumes: `LabelService::void_shipment()`, `LabelService::mark_needs_attention()`, `ShipmentRepository::active_for_order()`.
- Produces: `VoidOnCancel::handle(int $order_id): void` on `woocommerce_order_status_cancelled` and `woocommerce_order_status_refunded` (full refund).

- [ ] **Step 1: Write the failing tests**

`tests/test-shipping-void-on-cancel.php`:

```php
<?php
use Anchor\Shipping\Domain\Parcel;
use Anchor\Shipping\Module;

class Test_Shipping_Void_On_Cancel extends Anchor_Shipping_TestCase {

	private function labelled_order(): WC_Order {
		$this->configure_ups();
		$order = $this->make_order( [], 'processing' );
		$this->queue_token();
		$this->queue_response( 200, $this->fixture( 'ups-ship-single' ) );
		Module::instance()->labels->create_for_order( $order, [ new Parcel( 1, 10, 10, 10 ) ], 'manual' );
		return wc_get_order( $order->get_id() );
	}

	public function test_cancelling_voids_active_labels() {
		$order = $this->labelled_order();
		$this->queue_response( 200, $this->fixture( 'ups-void-ok' ) );
		$order->update_status( 'cancelled' );
		$this->assertSame( [], Module::instance()->shipments->active_for_order( $order->get_id() ) );
	}

	public function test_refusal_to_void_asks_a_person() {
		$order = $this->labelled_order();
		$this->queue_response( 400, [ 'response' => [ 'errors' => [ [ 'code' => '190102', 'message' => 'No shipment found within the allowed void period' ] ] ] ] );
		$order->update_status( 'refunded' );
		$this->assertCount( 1, Module::instance()->shipments->active_for_order( $order->get_id() ) );
		$this->assertSame( 'needs_attention', wc_get_order( $order->get_id() )->get_meta( '_anchor_shipping_state' ) );
		$notes = implode( ' ', wp_list_pluck( wc_get_order_notes( [ 'order_id' => $order->get_id() ] ), 'content' ) );
		$this->assertStringContainsString( 'allowed void period', $notes );
	}

	public function test_unlabelled_orders_make_no_carrier_calls() {
		$this->configure_ups();
		$order = $this->make_order( [], 'processing' );
		$order->update_status( 'cancelled' );
		$this->assertCount( 0, $this->requests );
	}
}
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit tests/test-shipping-void-on-cancel.php`
Expected: FAIL — active shipment still present after cancel.

- [ ] **Step 3: Implement**

`anchor-shipping/src/Services/VoidOnCancel.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Shipping\Services;

use Anchor\Shipping\Database\ShipmentRepository;
use Anchor\Shipping\Domain\CarrierError;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** Cancelled or fully refunded → void its labels; if the carrier refuses, tell a person. */
final class VoidOnCancel {

	public function __construct( private LabelService $labels, private ShipmentRepository $shipments ) {
		\add_action( 'woocommerce_order_status_cancelled', [ $this, 'handle' ], 20, 1 );
		\add_action( 'woocommerce_order_status_refunded', [ $this, 'handle' ], 20, 1 );
	}

	public function handle( int $order_id ): void {
		$done = [];
		foreach ( $this->shipments->active_for_order( $order_id ) as $row ) {
			$key = $row['carrier'] . '|' . $row['shipment_id'];
			if ( isset( $done[ $key ] ) ) {
				continue; // one void covers every package of a shipment
			}
			$done[ $key ] = true;
			try {
				$this->labels->void_shipment( (int) $row['id'] );
			} catch ( CarrierError $e ) {
				$order = \wc_get_order( $order_id );
				if ( $order ) {
					/* translators: 1: tracking number, 2: carrier message */
					$this->labels->mark_needs_attention( $order, sprintf( \__( 'Order was cancelled/refunded but label %1$s could not be voided: %2$s', 'anchor-schema' ), $row['tracking_number'], $e->getMessage() ) );
				}
			}
		}
	}
}
```

- [ ] **Step 4: Wire it**

In `Module` after the paid-order handler: `new Services\VoidOnCancel( $this->labels, $this->shipments );`

- [ ] **Step 5: Run tests**

Run: `vendor/bin/phpunit tests/test-shipping-void-on-cancel.php`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
composer dump-autoload --no-dev
git add anchor-shipping/ tests/test-shipping-void-on-cancel.php
git commit -m "feat(shipping): void labels on cancel or full refund, escalate refusals

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 14: Checkout protection checkboxes (customer mode)

**Files:**
- Create: `anchor-shipping/src/Checkout/ProtectionFields.php`
- Modify: `anchor-shipping/anchor-shipping.php`
- Test: `tests/test-shipping-checkout-protection.php`

**Interfaces:**
- Consumes: `Protection::offered()`, `insurance_fee()`, `signature_fee()`, `META_*` (Task 9); `Packer` not needed (cart subtotal is computed from cart lines that need shipping).
- Produces: fields `anchor_shipping_insure`, `anchor_shipping_signature` rendered on `woocommerce_review_order_before_payment`; session keys `anchor_shipping_insure` / `anchor_shipping_signature` (`'yes'|''`) set from `woocommerce_checkout_update_order_review`; fees added in `woocommerce_cart_calculate_fees` (non-taxable) named "Shipping insurance" / "Signature on delivery"; order meta written in `woocommerce_checkout_create_order`. Public pure helpers: `cart_shippable_subtotal(\WC_Cart): float`, `apply_fees(\WC_Cart): void`, `chosen(string $option): bool`.

- [ ] **Step 1: Write the failing tests**

`tests/test-shipping-checkout-protection.php`:

```php
<?php
use Anchor\Shipping\Checkout\ProtectionFields;
use Anchor\Shipping\Services\Protection;

class Test_Shipping_Checkout_Protection extends Anchor_Shipping_TestCase {

	public function set_up() {
		parent::set_up();
		WC()->frontend_includes();
		WC()->session = new WC_Session_Handler();
		WC()->session->init();
		WC()->cart = new WC_Cart();
		WC()->customer = new WC_Customer( 0, true );
	}

	public function tear_down() {
		WC()->cart->empty_cart();
		parent::tear_down();
	}

	private function fees(): array {
		WC()->cart->calculate_totals();
		return array_map( static fn( $f ) => [ $f->name, (float) $f->amount ], array_values( WC()->cart->get_fees() ) );
	}

	public function test_chosen_options_add_fees_from_the_shippable_subtotal() {
		$this->configure_ups( [ 'insurance' => [ 'mode' => 'customer', 'fee_per_100' => '1.25' ], 'signature' => [ 'mode' => 'customer', 'fee' => '7.70' ] ] );
		WC()->cart->add_to_cart( $this->make_product( [ 'price' => 450, 'weight' => 0.5 ] ) );
		WC()->cart->add_to_cart( $this->make_product( [ 'price' => 2500, 'virtual' => true ] ) );
		WC()->session->set( 'anchor_shipping_insure', 'yes' );
		WC()->session->set( 'anchor_shipping_signature', 'yes' );

		$this->assertSame( 450.0, ( new ProtectionFields() )->cart_shippable_subtotal( WC()->cart ) );
		$this->assertSame( [ [ 'Shipping insurance', 5.0 ], [ 'Signature on delivery', 7.7 ] ], $this->fees() );
	}

	public function test_no_fee_when_not_offered_or_not_chosen() {
		$this->configure_ups( [ 'insurance' => [ 'mode' => 'auto', 'fee_per_100' => '1.25' ] ] );
		WC()->cart->add_to_cart( $this->make_product( [ 'price' => 450, 'weight' => 0.5 ] ) );
		WC()->session->set( 'anchor_shipping_insure', 'yes' );
		$this->assertSame( [], $this->fees(), 'auto mode never charges the customer' );

		$this->configure_ups( [ 'insurance' => [ 'mode' => 'customer', 'fee_per_100' => '1.25' ] ] );
		WC()->session->set( 'anchor_shipping_insure', '' );
		$this->assertSame( [], $this->fees() );
	}

	public function test_render_hides_unoffered_options() {
		$this->configure_ups( [ 'signature' => [ 'mode' => 'customer', 'fee' => '7.70' ] ] );
		WC()->cart->add_to_cart( $this->make_product( [ 'price' => 450, 'weight' => 0.5 ] ) );
		ob_start();
		( new ProtectionFields() )->render();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'name="anchor_shipping_signature"', $html );
		$this->assertStringNotContainsString( 'name="anchor_shipping_insure"', $html );
	}

	public function test_order_records_the_choice() {
		$this->configure_ups( [ 'insurance' => [ 'mode' => 'customer', 'fee_per_100' => '1.25' ] ] );
		WC()->session->set( 'anchor_shipping_insure', 'yes' );
		$order = wc_create_order();
		( new ProtectionFields() )->save_to_order( $order );
		$this->assertSame( 'yes', $order->get_meta( Protection::META_INSURE ) );
		$this->assertSame( '', $order->get_meta( Protection::META_SIGNATURE ) );
	}
}
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit tests/test-shipping-checkout-protection.php`
Expected: FAIL — `Class "Anchor\Shipping\Checkout\ProtectionFields" not found`.

- [ ] **Step 3: Implement**

`anchor-shipping/src/Checkout/ProtectionFields.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Shipping\Checkout;

use Anchor\Shipping\Services\Protection;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Classic-checkout checkboxes for insurance / signature when the store offers them.
 * The choice lives in the Woo session while the customer is on checkout (so the
 * fee shows live in the order table) and is copied to the order on placement.
 */
final class ProtectionFields {

	private const OPTIONS = [ 'insurance' => 'anchor_shipping_insure', 'signature' => 'anchor_shipping_signature' ];

	public function __construct() {
		\add_action( 'woocommerce_review_order_before_payment', [ $this, 'render' ] );
		\add_action( 'woocommerce_checkout_update_order_review', [ $this, 'capture' ] );
		\add_action( 'woocommerce_cart_calculate_fees', [ $this, 'apply_fees' ] );
		\add_action( 'woocommerce_checkout_create_order', [ $this, 'save_to_order' ] );
	}

	public function chosen( string $option ): bool {
		return Protection::offered( $option ) && \WC()->session && 'yes' === \WC()->session->get( self::OPTIONS[ $option ] );
	}

	public function cart_shippable_subtotal( \WC_Cart $cart ): float {
		$total = 0.0;
		foreach ( $cart->get_cart() as $line ) {
			$product = $line['data'] ?? null;
			if ( $product instanceof \WC_Product && $product->needs_shipping() ) {
				$total += (float) $line['line_subtotal'];
			}
		}
		return round( $total, 2 );
	}

	public function render(): void {
		if ( ! \WC()->cart || ! \WC()->cart->needs_shipping() ) {
			return;
		}
		$subtotal = $this->cart_shippable_subtotal( \WC()->cart );
		$rows     = [];
		if ( Protection::offered( 'insurance' ) && $subtotal > 100 ) {
			/* translators: %s: fee */
			$rows['insurance'] = sprintf( \__( 'Insure my shipment for its full value (+%s)', 'anchor-schema' ), \wc_price( Protection::insurance_fee( $subtotal ) ) );
		}
		if ( Protection::offered( 'signature' ) ) {
			/* translators: %s: fee */
			$rows['signature'] = sprintf( \__( 'Require a signature on delivery (+%s)', 'anchor-schema' ), \wc_price( Protection::signature_fee() ) );
		}
		if ( ! $rows ) {
			return;
		}
		echo '<div class="anchor-shipping-protection">';
		foreach ( $rows as $option => $label ) {
			printf(
				'<p class="form-row"><label class="checkbox"><input type="checkbox" class="input-checkbox" name="%1$s" value="yes" %2$s> %3$s</label></p>',
				\esc_attr( self::OPTIONS[ $option ] ),
				\checked( $this->chosen( $option ), true, false ),
				\wp_kses_post( $label )
			);
		}
		echo '</div>';
		// Re-total when ticked: Woo only refreshes on its own address fields.
		echo "<script>jQuery(function($){ $(document.body).on('change', '.anchor-shipping-protection input', function(){ $(document.body).trigger('update_checkout'); }); });</script>";
	}

	/** @param string $post_data serialized checkout form from update_order_review */
	public function capture( $post_data ): void {
		parse_str( (string) $post_data, $fields );
		foreach ( self::OPTIONS as $key ) {
			\WC()->session->set( $key, isset( $fields[ $key ] ) && 'yes' === $fields[ $key ] ? 'yes' : '' );
		}
	}

	public function apply_fees( \WC_Cart $cart ): void {
		if ( ! $cart->needs_shipping() ) {
			return;
		}
		if ( $this->chosen( 'insurance' ) ) {
			$fee = Protection::insurance_fee( $this->cart_shippable_subtotal( $cart ) );
			if ( $fee > 0 ) {
				$cart->add_fee( \__( 'Shipping insurance', 'anchor-schema' ), $fee, false );
			}
		}
		if ( $this->chosen( 'signature' ) && Protection::signature_fee() > 0 ) {
			$cart->add_fee( \__( 'Signature on delivery', 'anchor-schema' ), Protection::signature_fee(), false );
		}
	}

	public function save_to_order( \WC_Order $order ): void {
		$order->update_meta_data( Protection::META_INSURE, $this->chosen( 'insurance' ) ? 'yes' : '' );
		$order->update_meta_data( Protection::META_SIGNATURE, $this->chosen( 'signature' ) ? 'yes' : '' );
	}
}
```

On the final "Place order" POST, Woo does not call `update_order_review`; the session already holds the latest choice from the last refresh, and `render()`'s change handler forces a refresh on every tick, so the session is current when the order is created.

- [ ] **Step 4: Wire it**

In `Module` (outside `is_admin()`): `new Checkout\ProtectionFields();`

- [ ] **Step 5: Run tests**

Run: `vendor/bin/phpunit tests/test-shipping-checkout-protection.php`
Expected: PASS (4 tests).

- [ ] **Step 6: Run the whole shipping suite and the full suite**

Run: `vendor/bin/phpunit --filter Shipping` → all PASS.
Run: `vendor/bin/phpunit` → no new failures versus `main` (record the pre-existing failure list from a run on `origin/main` first if the suite is not green there; do not inherit "pre-existing" without that comparison).

- [ ] **Step 7: Commit**

```bash
composer dump-autoload --no-dev
git add anchor-shipping/ tests/test-shipping-checkout-protection.php
git commit -m "feat(shipping): checkout insurance/signature checkboxes with fees (customer mode)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 15: Staging deploy and end-to-end check against UPS CIE

No production changes in this task. Staging is `stg-dekalasers-stage.kinsta.cloud` (skill `deka-stage`, SSH port **35740** — 29603 is live; check the port before every command). Staging is a copy of production: it has real customer orders and **sends real email**, so every test order uses your own address and the shipping inbox is set to `jmartin@anchorcorps.com`.

**Files:** none in the repo (unless a defect is found — fix it in a new commit with a test first).

- [ ] **Step 1: Pre-flight on staging**

Using the `deka-stage` skill's SSH command:

```bash
wp eval 'var_dump( function_exists( "imagecreatefromstring" ), function_exists( "imagerotate" ) );' --path=<staging web root>
wp plugin list --name=Anchor-Tools --fields=name,version,status --path=<staging web root>
```

Expected: `bool(true) bool(true)`; note the installed Anchor-Tools version (the rollback target).

- [ ] **Step 2: Back up and deploy the branch build**

```bash
# on staging
cd <staging web root>/wp-content/plugins && tar czf ~/Anchor-Tools.bak-$(date +%Y%m%d-%H%M)-pre-shipping.tgz Anchor-Tools
# locally
rm -rf /tmp/at-ship && git -C ~/Developer/anchor-os/Anchor-Tools-shipping archive feat/anchor-shipping | (mkdir -p /tmp/at-ship && tar -x -C /tmp/at-ship)
cd /tmp/at-ship && composer install --no-dev --quiet && npm install --no-save csso terser && node bin/build-assets.mjs   # same steps as the release workflow
rsync -az --delete --exclude .env --exclude tests --exclude e2e --exclude node_modules --exclude docs -e "ssh -p 35740" /tmp/at-ship/ <staging user>@<staging host>:<staging web root>/wp-content/plugins/Anchor-Tools/
```

`--exclude .env` keeps the server's `.env` (the updater token) — `--delete` would otherwise remove it. Do not click "update" on Anchor-Tools in staging while this branch is deployed; the updater would replace it with the last release.

Then enable the module and configure sandbox credentials (secrets via `wp eval` reading from stdin — never on the command line or in a file in the repo):

```bash
wp eval '$s = get_option( "anchor_schema_settings", [] ); $s["modules"]["shipping"] = true; update_option( "anchor_schema_settings", $s );' --path=<root>
```

Then in staging wp-admin → WooCommerce → Anchor Shipping: environment **Sandbox**, Client ID / Secret / account from `~/Desktop/creds.txt` (DEKA UPS block), ship-from phone `8133208285`, inbox `jmartin@anchorcorps.com`, one box "Small" 25.4×20.3×10.2 cm, empty 0.2 kg, max 10 kg. Leave auto-label **off**.

- [ ] **Step 3: Exercise every flow (record evidence for each)**

1. Place a test order on staging for "Threaded Straight Tip (10 Ct)" with your own name/email and a real US address; pay with the staging test gateway (or set the order to Processing in admin). Expect: a "Ready to ship" email in jmartin's inbox with a working Create label button; order note "Shipping label needed".
2. From the button → order screen → Shipping label box → Small box, 0.5 kg → Create label. Expect: a sample label PDF prints at 4×6 portrait (open it and look); a "Label ready" email with the PDF attached; order note with tracking number and cost.
3. Void it from the panel. Expect: row struck through, "label voided" note.
4. Set the tip product's weight to 0.1 and default box Small; tick auto-label; place another order. Expect: label created with no clicks and emailed.
5. Cancel that order. Expect: label voided automatically.
6. Set insurance to Customer with fee 1.25 and signature to Customer with fee 7.70; at checkout for a $450 cart, tick both. Expect: fees appear in the order table live; the created label's request carries DeclaredValue 450.00 and DCISType 2 (check the order panel shows "insured · signature").
7. Screenshot the order panel and the settings page with the Playwright MCP (`mcp__MCP_DOCKER__browser_take_screenshot`, files under `/tmp/playwright-output/`) and check computed styles for the panel (no zero-size text).
8. WooCommerce → Status → Logs → `anchor-shipping`: confirm no credentials or label bodies appear.

- [ ] **Step 4: Reset staging**

Untick auto-label, set protection modes back to Off, and leave the module enabled in sandbox mode for the client demo — or restore the backup tarball if anything misbehaved. Production is not touched by this plan; going live is a separate, explicitly approved step (merge → release tag → update on live → set wp-config constants → switch environment to Production).

- [ ] **Step 5: Report**

Post the evidence (emails received, PDF screenshot, panel screenshot, log excerpt) in the session. Then run the whole-branch review (Opus) before any PR, and count files (`git diff --name-only origin/main...HEAD | wc -l`) before opening one — it must stay well under 150 (expect ~45 including `vendor/setasign`).
