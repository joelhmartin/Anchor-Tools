# Anchor Agreements Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A new Anchor Tools module that puts a required "I have read and signed …" checkbox above Place Order. The checkbox opens a pop-up where the buyer draws or generates a signature, and the module logs each signature against the order with a versioned copy of the document.

**Architecture:** A PSR-4 module at `anchor-agreements/` (namespace `Anchor\Agreements`) that follows the `anchor-courses` layout: `Module` bootstrap, `Database\Migrations` + repositories, `Services`, `Frontend`, `Admin`. Documents are a CPT, snapshotted lazily into an immutable versions table at signing time. Signatures (PNG image in a MEDIUMBLOB) live in their own table and are tracked in the WooCommerce session until checkout attaches them to the order. The front end is dependency-free vanilla JS (a canvas pad plus four bundled OFL script fonts).

**Tech Stack:** PHP 8.1+, WordPress, WooCommerce (classic + FunnelKit checkout, HPOS), PHPUnit 9 (`WP_UnitTestCase` integration suite + WordPress-free unit suite), vanilla JS, Playwright (existing `e2e/`).

**Spec:** `~/Desktop/anchor-agreements-design.md` (copy it into `docs/superpowers/specs/2026-10-01-anchor-agreements-design.md` in Task 1).

**Repo:** `~/Developer/anchor-os/Anchor-Tools`. Branch from **`origin/main`** (the local checkout may be on an old feature branch): `git fetch origin && git switch -c feat/anchor-agreements origin/main`.

## Global Constraints

- PHP floor **8.1** (CI matrix `8.1`, `8.2`). `declare(strict_types=1);` in every `src/` file, **not** in the bootstrap `anchor-agreements.php` (WordPress calls its hooks with loose values; see the anchor-courses bootstrap comment).
- Text domain **`anchor-schema`** (every module in the registry uses it).
- Every CSS class is prefixed **`aagr-`**, so one WP Rocket RUCSS safelist regex (`/aagr-/`) covers the module.
- **No third-party JS or PHP libraries.** The signature pad and font rendering are hand-written.
- Signature images are stored **only in the DB**. Never write them to `uploads/`; Nginx ignores `.htaccess`, so uploads are public.
- Admin capability: **`manage_woocommerce`**.
- Must work with **HPOS on and off**. Use `wc_get_order()` / order meta APIs, never `get_post_meta` on orders.
- Option keys: `anchor_agreements_db_version`, `anchor_agreements_settings`, `anchor_agreements_rewrite_version`.
- Product meta keys: `_anchor_agreement_required` (`yes`|absent), `_anchor_agreement_id` (int, `0` = site default).
- Order meta keys: `_anchor_agreement_signature_ids` (int[]), `_anchor_agreements_notified` (`yes`).
- WC session key: `anchor_agreements` → `array<int agreement_id, int signature_id>`.
- **Every business-facing value is a setting** in `Support\Settings` (Agreements → Settings): default agreement, staff notification on/off, recipients, subject template, customer email links on/off, checkbox label, consent sentence, reuse window (default **24 h**), abandoned purge (default **30 days**). No other class hard-codes these. Security limits stay constants: rate limit **10 signatures / session / hour**. Image cap: **200 KB decoded, ≤ 2000×1000 px, PNG only**.
- Release: **bump `Version:` in `anchor-tools.php`**. Merging without a bump does not deploy to sites. Keep the PR under 150 files (CodeRabbit skips larger PRs). Push with `git push origin feat/anchor-agreements`, never a bare `git push`.

## Review Focus

1. **Checkout fragment refresh.** WooCommerce/FunnelKit re-render the payment section (which contains `woocommerce_review_order_before_submit`) on every `update_checkout`. The checkbox must come back **checked** after signing, which is why state is rendered server-side from the session. Pinned in Task 7 (`test_render_shows_checked_after_signing`).
2. **Failed payment, then retry on the same order.** WooCommerce reuses `order_awaiting_payment` (order 1119696 did exactly this). The existing signature must still satisfy validation. Pinned in Task 6 (`test_signature_attached_to_awaiting_order_still_counts`).
3. **Product marked "required" but no usable document** (no default set, or the document trashed). It must not silently sell unsigned: the product save shows an error, and the product list flags it. Pinned in Task 5 (`test_save_with_required_but_no_document_adds_error`).
4. **Hostile image payloads** (SVG, JPEG, 5 MB PNG, truncated base64, `data:text/html`) must be rejected. Pinned in Task 2.
5. **Cart changed after signing.** A signature for an agreement no longer in the cart must not be attached to the order, and a newly added product with a different agreement must require a fresh signature. Pinned in Task 7 (`test_only_required_signatures_are_attached`).

---

## File Structure

```
anchor-agreements/
  anchor-agreements.php              Module bootstrap (class Anchor\Agreements\Module)
  src/Database/Migrations.php        dbDelta schema, versioned
  src/Database/VersionRepository.php Immutable document snapshots
  src/Database/SignatureRepository.php  CRUD on signatures
  src/Content/AgreementPostType.php  CPT anchor_agreement
  src/Support/Settings.php           Option accessors
  src/Support/SignatureImage.php     PNG data-URL validator (WordPress-free)
  src/Services/Requirements.php      Product/cart → required agreement ids
  src/Services/SignatureCheck.php    Which required agreements are still unsigned
  src/Frontend/SigningEndpoint.php   wc-ajax anchor_agreement_sign
  src/Frontend/Checkout.php          Checkbox render, validation, attach, Store API guard
  src/Frontend/SignedCopyPage.php    /signed-agreement/{token}/
  src/Frontend/CustomerViews.php     Email block, account endpoint, shortcode
  src/Admin/ProductFields.php        Product General-tab fields
  src/Admin/OrderMetabox.php         Order screen metabox
  src/Admin/SignaturesPage.php       List table + CSV export
  src/Admin/SettingsPage.php         Settings screen
  src/Services/Notifier.php          "Signed:" staff email
  src/Services/Cleanup.php           Daily purge cron
  assets/checkout.js  assets/checkout.css  assets/signed-copy.css
  assets/fonts/{dancing-script,great-vibes,allura,caveat}.woff2  assets/fonts/OFL.txt
  templates/signed-copy.php
  README.md
tests/test-agreements-*.php          Integration tests
tests/unit/test-agreements-signature-image.php
```

Modified: `anchor-tools.php` (registry entry + version), `composer.json` (PSR-4), `tests/bootstrap.php` (enable module).

---

### Task 1: Module scaffold, registry, schema

**Files:**
- Create: `anchor-agreements/anchor-agreements.php`, `anchor-agreements/src/Database/Migrations.php`, `docs/superpowers/specs/2026-10-01-anchor-agreements-design.md` (copy of the Desktop spec)
- Modify: `anchor-tools.php` (inside `anchor_tools_get_available_modules()`, after the `'courses'` entry), `composer.json` (`autoload.psr-4`), `tests/bootstrap.php` (the `modules` array)
- Test: `tests/test-agreements-migrations.php`

**Interfaces:**
- Produces: `Anchor\Agreements\Database\Migrations::table(string $name): string` (`'versions'|'signatures'` → `{prefix}anchor_agreement_{name}`), `Migrations::maybe_migrate(): void`, `Migrations::DB_VERSION = '1.0.0'`. `Anchor\Agreements\Module::instance(): ?Module`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/test-agreements-migrations.php
use Anchor\Agreements\Database\Migrations;

class Test_Agreements_Migrations extends WP_UnitTestCase {
	public function test_tables_exist_after_module_boot() {
		global $wpdb;
		foreach ( [ 'versions', 'signatures' ] as $t ) {
			$name = Migrations::table( $t );
			$this->assertSame( $name, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ) );
		}
		$this->assertSame( Migrations::DB_VERSION, get_option( Migrations::OPTION ) );
	}

	public function test_signature_table_has_blob_and_unique_token() {
		global $wpdb;
		$cols = $wpdb->get_results( 'SHOW COLUMNS FROM ' . Migrations::table( 'signatures' ), OBJECT_K );
		$this->assertStringContainsStringIgnoringCase( 'mediumblob', $cols['image']->Type );
		$idx = $wpdb->get_results( 'SHOW INDEX FROM ' . Migrations::table( 'signatures' ) . " WHERE Key_name = 'token'" );
		$this->assertSame( '0', (string) $idx[0]->Non_unique );
	}
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter Test_Agreements_Migrations`
Expected: FAIL with `Class "Anchor\Agreements\Database\Migrations" not found`.

- [ ] **Step 3: Register the module and autoload**

`composer.json`, `autoload.psr-4`:
```json
"Anchor\\Agreements\\": "anchor-agreements/src/"
```
Then run `composer dump-autoload`.

`anchor-tools.php`, after the `'courses'` entry:
```php
            'agreements' => [
                'label'       => __( 'Anchor Agreements', 'anchor-schema' ),
                'description' => __( 'Require a signed agreement (drawn or generated signature) at WooCommerce checkout.', 'anchor-schema' ),
                'path'        => ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-agreements/anchor-agreements.php',
                'class'       => '\\Anchor\\Agreements\\Module',
            ],
```

`tests/bootstrap.php`: add `'agreements' => true` to the `modules` array.

- [ ] **Step 4: Write `Migrations`**

```php
<?php
declare(strict_types=1);

namespace Anchor\Agreements\Database;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Versioned schema. Anchor Tools has no per-module activation hook, so this runs
 * from Module::__construct() and again on admin_init (same as anchor-courses).
 * dbDelta is whitespace-sensitive: two spaces after PRIMARY KEY, one key per line.
 */
final class Migrations {

	public const DB_VERSION = '1.0.0';
	public const OPTION     = 'anchor_agreements_db_version';

	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'anchor_agreement_' . $name;
	}

	public static function maybe_migrate(): void {
		if ( (string) \get_option( self::OPTION, '' ) === self::DB_VERSION ) {
			return;
		}
		self::run();
	}

	public static function run(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$collate    = $wpdb->get_charset_collate();
		$versions   = self::table( 'versions' );
		$signatures = self::table( 'signatures' );

		\dbDelta( "CREATE TABLE {$versions} (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  agreement_id BIGINT(20) UNSIGNED NOT NULL,
  content_hash CHAR(64) NOT NULL,
  title TEXT NOT NULL,
  content LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY agreement_hash (agreement_id,content_hash)
) {$collate};" );

		\dbDelta( "CREATE TABLE {$signatures} (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  token CHAR(36) NOT NULL,
  version_id BIGINT(20) UNSIGNED NOT NULL,
  agreement_id BIGINT(20) UNSIGNED NOT NULL,
  order_id BIGINT(20) UNSIGNED DEFAULT NULL,
  product_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
  user_id BIGINT(20) UNSIGNED DEFAULT NULL,
  signer_name VARCHAR(120) NOT NULL,
  signer_email VARCHAR(190) NOT NULL DEFAULT '',
  method VARCHAR(10) NOT NULL,
  font VARCHAR(40) DEFAULT NULL,
  image MEDIUMBLOB NOT NULL,
  ip VARCHAR(45) NOT NULL DEFAULT '',
  user_agent VARCHAR(255) NOT NULL DEFAULT '',
  session_key VARCHAR(64) NOT NULL DEFAULT '',
  signed_at DATETIME NOT NULL,
  attached_at DATETIME DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY token (token),
  KEY order_id (order_id),
  KEY agreement_id (agreement_id),
  KEY user_id (user_id),
  KEY session_key (session_key),
  KEY signed_at (signed_at)
) {$collate};" );

		\update_option( self::OPTION, self::DB_VERSION, false );
	}
}
```

- [ ] **Step 5: Write the bootstrap**

```php
<?php
/**
 * Anchor Tools module: Anchor Agreements.
 * Bootstrap only; everything else is PSR-4 under src/. No strict_types here on purpose.
 */

namespace Anchor\Agreements;

use Anchor\Agreements\Database\Migrations;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

class Module {

	const VERSION = '1.0.0';

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
		// Later tasks register their services here (see each task's "wire it" step).
	}
}
```

- [ ] **Step 6: Run the tests and verify they pass**

Run: `vendor/bin/phpunit --filter Test_Agreements_Migrations`
Expected: PASS (2 tests).

- [ ] **Step 7: Commit**

```bash
git add anchor-agreements composer.json anchor-tools.php tests/bootstrap.php tests/test-agreements-migrations.php docs/superpowers/specs/2026-10-01-anchor-agreements-design.md
git commit -m "feat(agreements): scaffold module, registry entry and schema"
```

---

### Task 2: Signature image validator (WordPress-free)

**Files:**
- Create: `anchor-agreements/src/Support/SignatureImage.php`
- Test: `tests/unit/test-agreements-signature-image.php`

**Interfaces:**
- Produces: `Anchor\Agreements\Support\SignatureImage::decode(string $data_url): ?string`. Returns raw PNG bytes, or `null` if the input is invalid. Constants `MAX_BYTES = 204800`, `MAX_W = 2000`, `MAX_H = 1000`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/unit/test-agreements-signature-image.php
use Anchor\Agreements\Support\SignatureImage;
use PHPUnit\Framework\TestCase;

class Test_Agreements_Signature_Image extends TestCase {
	// Valid 1x1 PNG.
	const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

	public function test_valid_png_decodes() {
		$bytes = SignatureImage::decode( 'data:image/png;base64,' . self::PNG );
		$this->assertSame( "\x89PNG\r\n\x1a\n", substr( (string) $bytes, 0, 8 ) );
	}

	/** @dataProvider bad_inputs */
	public function test_rejects( string $input ) {
		$this->assertNull( SignatureImage::decode( $input ) );
	}

	public function bad_inputs(): array {
		$svg  = base64_encode( '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>' );
		$jpeg = base64_encode( "\xFF\xD8\xFF\xE0" . str_repeat( 'x', 50 ) );
		$big  = base64_encode( "\x89PNG\r\n\x1a\n" . str_repeat( 'A', 300000 ) );
		return [
			'empty'          => [ '' ],
			'svg mime'       => [ 'data:image/svg+xml;base64,' . $svg ],
			'html mime'      => [ 'data:text/html;base64,' . base64_encode( '<b>x</b>' ) ],
			'jpeg bytes'     => [ 'data:image/png;base64,' . $jpeg ],
			'png magic only' => [ 'data:image/png;base64,' . base64_encode( "\x89PNG\r\n\x1a\nnot-a-real-png" ) ],
			'too big'        => [ 'data:image/png;base64,' . $big ],
			'bad base64'     => [ 'data:image/png;base64,@@@!!!' ],
			'truncated'      => [ 'data:image/png;base64,' . substr( self::PNG, 0, 20 ) ],
		];
	}

	public function test_rejects_oversized_dimensions() {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			$this->markTestSkipped( 'GD not available' );
		}
		$im = imagecreatetruecolor( 2500, 10 );
		ob_start();
		imagepng( $im );
		$png = ob_get_clean();
		$this->assertNull( SignatureImage::decode( 'data:image/png;base64,' . base64_encode( $png ) ) );
	}
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit -c phpunit-unit.xml.dist --filter Test_Agreements_Signature_Image`
Expected: FAIL with `Class "Anchor\Agreements\Support\SignatureImage" not found`. If instead it errors because composer's autoloader isn't loaded, add `require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';` to `tests/unit/bootstrap.php`.

- [ ] **Step 3: Implement**

```php
<?php
declare(strict_types=1);

namespace Anchor\Agreements\Support;

/**
 * The one gate between a browser-supplied data URL and the DB. WordPress-free on purpose
 * (unit-tested). The ABSPATH guard is deliberately omitted so the unit suite can load it.
 */
final class SignatureImage {

	public const MAX_BYTES = 204800;
	public const MAX_W     = 2000;
	public const MAX_H     = 1000;
	private const PREFIX   = 'data:image/png;base64,';

	public static function decode( string $data_url ): ?string {
		if ( 0 !== strpos( $data_url, self::PREFIX ) ) {
			return null;
		}
		$b64 = substr( $data_url, strlen( self::PREFIX ) );
		if ( strlen( $b64 ) > (int) ceil( self::MAX_BYTES * 4 / 3 ) + 4 ) {
			return null;
		}
		$bytes = base64_decode( $b64, true );
		if ( false === $bytes || '' === $bytes || strlen( $bytes ) > self::MAX_BYTES ) {
			return null;
		}
		if ( 0 !== strncmp( $bytes, "\x89PNG\r\n\x1a\n", 8 ) ) {
			return null;
		}
		$info = @getimagesizefromstring( $bytes );
		if ( ! is_array( $info ) || IMAGETYPE_PNG !== ( $info[2] ?? null ) ) {
			return null;
		}
		if ( $info[0] < 1 || $info[1] < 1 || $info[0] > self::MAX_W || $info[1] > self::MAX_H ) {
			return null;
		}
		return $bytes;
	}
}
```

- [ ] **Step 4: Run the tests and verify they pass**

Run: `vendor/bin/phpunit -c phpunit-unit.xml.dist --filter Test_Agreements_Signature_Image`
Expected: PASS (10 tests).

- [ ] **Step 5: Commit**

```bash
git add anchor-agreements/src/Support/SignatureImage.php tests/unit/test-agreements-signature-image.php tests/unit/bootstrap.php
git commit -m "feat(agreements): strict PNG data-URL validator for signatures"
```

---

### Task 3: Agreement CPT, settings, and immutable versions

**Files:**
- Create: `anchor-agreements/src/Content/AgreementPostType.php`, `anchor-agreements/src/Support/Settings.php`, `anchor-agreements/src/Database/VersionRepository.php`
- Modify: `anchor-agreements/anchor-agreements.php` (wire CPT)
- Test: `tests/test-agreements-versions.php`

**Interfaces:**
- Produces:
  - `AgreementPostType::CPT = 'anchor_agreement'`, `AgreementPostType::register(): void`, `AgreementPostType::is_usable(int $id): bool` (exists, correct type, `publish`).
  - `Settings::OPTION = 'anchor_agreements_settings'`, `Settings::DEFAULTS` (array below), `Settings::get(): array` (stored values merged over DEFAULTS), `Settings::sanitize(array $in): array` (used by the settings screen), and typed accessors: `default_agreement_id(): int`, `notify_enabled(): bool`, `notify_recipients(): string[]`, `notify_subject(string $documents, string $name, int $order_id): string`, `customer_email_links(): bool`, `label_for(string $title): string`, `consent_text(): string`, `reuse_seconds(): int`, `purge_days(): int`.
  - Every business-facing value lives here and is edited on **Agreements → Settings** — no other class hard-codes a recipient, subject, label, consent wording or time window.
  - `VersionRepository::current_for(int $agreement_id): ?array` returns `{id:int, agreement_id:int, title:string, content:string, created_at:string}` (creates the snapshot if this exact title+content hasn't been stored), and `VersionRepository::get(int $version_id): ?array`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/test-agreements-versions.php
use Anchor\Agreements\Content\AgreementPostType;
use Anchor\Agreements\Database\VersionRepository;
use Anchor\Agreements\Support\Settings;

class Test_Agreements_Versions extends WP_UnitTestCase {
	private function agreement( string $content = '<p>No refunds within 30 days.</p>' ): int {
		return self::factory()->post->create( [ 'post_type' => AgreementPostType::CPT, 'post_title' => 'Cancellation Policy', 'post_content' => $content, 'post_status' => 'publish' ] );
	}

	public function test_same_content_reuses_version() {
		$repo = new VersionRepository();
		$id   = $this->agreement();
		$a    = $repo->current_for( $id );
		$b    = $repo->current_for( $id );
		$this->assertSame( $a['id'], $b['id'] );
		$this->assertSame( 'Cancellation Policy', $a['title'] );
	}

	public function test_edit_creates_new_version_and_keeps_old() {
		$repo = new VersionRepository();
		$id   = $this->agreement();
		$v1   = $repo->current_for( $id );
		wp_update_post( [ 'ID' => $id, 'post_content' => '<p>Changed.</p>' ] );
		$v2 = $repo->current_for( $id );
		$this->assertNotSame( $v1['id'], $v2['id'] );
		$this->assertStringContainsString( 'No refunds', $repo->get( $v1['id'] )['content'] );
	}

	public function test_unusable_agreement_returns_null() {
		$repo  = new VersionRepository();
		$draft = self::factory()->post->create( [ 'post_type' => AgreementPostType::CPT, 'post_status' => 'draft' ] );
		$this->assertNull( $repo->current_for( $draft ) );
		$this->assertNull( $repo->current_for( 999999 ) );
	}

	public function test_settings_defaults() {
		delete_option( Settings::OPTION );
		$this->assertSame( 0, Settings::default_agreement_id() );
		$this->assertSame( [ get_option( 'admin_email' ) ], Settings::notify_recipients() );
		$this->assertSame( 'I have read and signed the Cancellation Policy', Settings::label_for( 'Cancellation Policy' ) );
		$this->assertTrue( Settings::notify_enabled() );
		$this->assertTrue( Settings::customer_email_links() );
		$this->assertSame( DAY_IN_SECONDS, Settings::reuse_seconds() );
		$this->assertSame( 30, Settings::purge_days() );
		$this->assertSame( 'Signed: Cancellation Policy – Pari Example, order #12', Settings::notify_subject( 'Cancellation Policy', 'Pari Example', 12 ) );
	}

	public function test_settings_sanitize_clamps_and_cleans() {
		$out = Settings::sanitize( [ 'notify' => 'a@x.com, not-an-email ,b@y.com', 'reuse_hours' => '9999', 'purge_days' => '0', 'notify_enabled' => '', 'label' => '<b>Sign</b> {title}' ] );
		$this->assertSame( 'a@x.com, b@y.com', $out['notify'] );
		$this->assertSame( 168, $out['reuse_hours'] );
		$this->assertSame( 1, $out['purge_days'] );
		$this->assertFalse( $out['notify_enabled'] );
		$this->assertSame( 'Sign {title}', $out['label'] );
		update_option( Settings::OPTION, [ 'notify' => 'a@x.com, b@y.com' ] );
		$this->assertSame( [ 'a@x.com', 'b@y.com' ], Settings::notify_recipients() );
	}
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter Test_Agreements_Versions`
Expected: FAIL with `Class "Anchor\Agreements\Content\AgreementPostType" not found`.

- [ ] **Step 3: Implement the CPT**

```php
<?php
declare(strict_types=1);

namespace Anchor\Agreements\Content;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class AgreementPostType {

	public const CPT = 'anchor_agreement';

	public static function register(): void {
		\register_post_type( self::CPT, [
			'labels'          => [
				'name'          => \__( 'Agreements', 'anchor-schema' ),
				'singular_name' => \__( 'Agreement', 'anchor-schema' ),
				'add_new_item'  => \__( 'Add New Agreement', 'anchor-schema' ),
				'edit_item'     => \__( 'Edit Agreement', 'anchor-schema' ),
			],
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => true,
			'menu_icon'       => 'dashicons-edit-page',
			'supports'        => [ 'title', 'editor', 'revisions' ],
			'capability_type' => 'post',
			'capabilities'    => [
				'edit_post' => 'manage_woocommerce', 'read_post' => 'manage_woocommerce', 'delete_post' => 'manage_woocommerce',
				'edit_posts' => 'manage_woocommerce', 'edit_others_posts' => 'manage_woocommerce', 'publish_posts' => 'manage_woocommerce',
				'read_private_posts' => 'manage_woocommerce', 'delete_posts' => 'manage_woocommerce', 'create_posts' => 'manage_woocommerce',
			],
			'map_meta_cap'    => false,
			'show_in_rest'    => false,
		] );
	}

	public static function is_usable( int $id ): bool {
		$post = $id ? \get_post( $id ) : null;
		return $post && self::CPT === $post->post_type && 'publish' === $post->post_status;
	}
}
```

- [ ] **Step 4: Implement `Settings`**

```php
<?php
declare(strict_types=1);

namespace Anchor\Agreements\Support;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class Settings {

	public const OPTION = 'anchor_agreements_settings';

	/** Every value an admin can change. Edited on Agreements → Settings (Admin\SettingsPage). */
	public const DEFAULTS = [
		'default_agreement_id' => 0,
		'notify_enabled'       => true,
		'notify'               => '',   // comma-separated; empty = site admin email
		'notify_subject'       => 'Signed: {documents} – {name}, order #{order}',
		'customer_email_links' => true, // "Your signed agreements" block in customer order emails
		'label'                => '',   // empty = translated "I have read and signed the {title}"
		'consent_text'         => '',   // empty = translated default consent sentence
		'reuse_hours'          => 24,   // a signature still counts for a retried payment within this window
		'purge_days'           => 30,   // unattached (abandoned-cart) signatures are deleted after this
	];

	public static function get(): array {
		$raw = \get_option( self::OPTION, [] );
		return array_merge( self::DEFAULTS, \is_array( $raw ) ? $raw : [] );
	}

	public static function sanitize( $in ): array {
		$in = \is_array( $in ) ? $in : [];
		return [
			'default_agreement_id' => \absint( $in['default_agreement_id'] ?? 0 ),
			'notify_enabled'       => ! empty( $in['notify_enabled'] ),
			'notify'               => implode( ', ', array_values( array_filter( array_map( static fn( $e ) => \is_email( trim( $e ) ) ? trim( $e ) : '', explode( ',', (string) ( $in['notify'] ?? '' ) ) ) ) ) ),
			'notify_subject'       => \sanitize_text_field( (string) ( $in['notify_subject'] ?? '' ) ) ?: self::DEFAULTS['notify_subject'],
			'customer_email_links' => ! empty( $in['customer_email_links'] ),
			'label'                => \sanitize_text_field( (string) ( $in['label'] ?? '' ) ),
			'consent_text'         => \sanitize_text_field( (string) ( $in['consent_text'] ?? '' ) ),
			'reuse_hours'          => max( 1, min( 168, (int) ( $in['reuse_hours'] ?? 24 ) ) ),
			'purge_days'           => max( 1, min( 365, (int) ( $in['purge_days'] ?? 30 ) ) ),
		];
	}

	public static function default_agreement_id(): int {
		return (int) self::get()['default_agreement_id'];
	}

	public static function notify_enabled(): bool {
		return (bool) self::get()['notify_enabled'];
	}

	/** @return string[] */
	public static function notify_recipients(): array {
		$list = array_values( array_filter( array_map( 'trim', explode( ',', (string) self::get()['notify'] ) ), 'is_email' ) );
		return $list ?: [ (string) \get_option( 'admin_email' ) ];
	}

	public static function notify_subject( string $documents, string $name, int $order_id ): string {
		return strtr( (string) self::get()['notify_subject'], [ '{documents}' => $documents, '{name}' => $name, '{order}' => (string) $order_id ] );
	}

	public static function customer_email_links(): bool {
		return (bool) self::get()['customer_email_links'];
	}

	public static function label_for( string $title ): string {
		$tpl = (string) self::get()['label'];
		$tpl = '' !== $tpl ? $tpl : \__( 'I have read and signed the {title}', 'anchor-schema' );
		return str_replace( '{title}', $title, $tpl );
	}

	public static function consent_text(): string {
		$t = (string) self::get()['consent_text'];
		return '' !== $t ? $t : \__( 'I agree that this electronic signature is the legal equivalent of my handwritten signature.', 'anchor-schema' );
	}

	public static function reuse_seconds(): int {
		return (int) self::get()['reuse_hours'] * HOUR_IN_SECONDS;
	}

	public static function purge_days(): int {
		return (int) self::get()['purge_days'];
	}
}
``````

- [ ] **Step 5: Implement `VersionRepository`**

```php
<?php
declare(strict_types=1);

namespace Anchor\Agreements\Database;

use Anchor\Agreements\Content\AgreementPostType;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Lazy, immutable snapshots: a version is created the first time a given
 * title+content is signed. Editing a document never rewrites what anyone signed.
 */
final class VersionRepository {

	public function current_for( int $agreement_id ): ?array {
		global $wpdb;
		if ( ! AgreementPostType::is_usable( $agreement_id ) ) {
			return null;
		}
		$post    = \get_post( $agreement_id );
		$title   = (string) $post->post_title;
		$content = (string) $post->post_content;
		$hash    = hash( 'sha256', $title . "\n" . $content );
		$table   = Migrations::table( 'versions' );

		$wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$table} (agreement_id, content_hash, title, content, created_at) VALUES (%d, %s, %s, %s, %s)",
			$agreement_id, $hash, $title, $content, \current_time( 'mysql', true )
		) );
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE agreement_id = %d AND content_hash = %s", $agreement_id, $hash
		), ARRAY_A );
		return $row ? self::shape( $row ) : null;
	}

	public function get( int $version_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Migrations::table( 'versions' ) . ' WHERE id = %d', $version_id ), ARRAY_A );
		return $row ? self::shape( $row ) : null;
	}

	private static function shape( array $row ): array {
		return [
			'id'           => (int) $row['id'],
			'agreement_id' => (int) $row['agreement_id'],
			'title'        => (string) $row['title'],
			'content'      => (string) $row['content'],
			'created_at'   => (string) $row['created_at'],
		];
	}
}
```

- [ ] **Step 6: Wire it.** In `Module::__construct()` add:

```php
		\add_action( 'init', [ Content\AgreementPostType::class, 'register' ] );
```

The tests create posts after `init`, but `register_post_type` must have run. If the test fails with "invalid post type", call `AgreementPostType::register()` in a `set_up_before_class()`.

- [ ] **Step 7: Run the tests and verify they pass**

Run: `vendor/bin/phpunit --filter Test_Agreements_Versions`
Expected: PASS (4 tests).

- [ ] **Step 8: Commit**

```bash
git add anchor-agreements tests/test-agreements-versions.php
git commit -m "feat(agreements): agreement CPT, settings and immutable versions"
```

---

### Task 4: Signature repository

**Files:**
- Create: `anchor-agreements/src/Database/SignatureRepository.php`
- Test: `tests/test-agreements-signatures.php`

**Interfaces:**
- Consumes: `Migrations::table()`.
- Produces (`Anchor\Agreements\Database\SignatureRepository`):
  - `insert(array $data): int`. `$data` keys: `version_id, agreement_id, product_id, user_id|null, signer_name, signer_email, method ('draw'|'generate'), font|null, image (raw PNG bytes), ip, user_agent, session_key`. Generates `token` (`wp_generate_uuid4()`) and `signed_at` (UTC now). Returns the id.
  - `get(int $id): ?array` and `find_by_token(string $token): ?array`. Both return the row array with ints cast and `image` as raw bytes.
  - `attach(int $id, int $order_id): bool`. Sets `order_id` and `attached_at`, and only touches rows where `order_id IS NULL OR order_id = $order_id`.
  - `for_order(int $order_id): array[]`, `for_signer(int $user_id, string $email): array[]`.
  - `count_recent_for_session(string $session_key, int $seconds): int`.
  - `purge_unattached(int $older_than_seconds): int`.
  - `search(array $args): array{rows: array[], total: int}`. `$args`: `s`, `agreement_id`, `paged`, `per_page`. Rows exclude `image`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/test-agreements-signatures.php
use Anchor\Agreements\Database\SignatureRepository;
use Anchor\Agreements\Database\Migrations;

class Test_Agreements_Signatures extends WP_UnitTestCase {
	const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

	private function data( array $over = [] ): array {
		return array_merge( [
			'version_id' => 1, 'agreement_id' => 10, 'product_id' => 5, 'user_id' => null,
			'signer_name' => 'Pari Example', 'signer_email' => 'p@example.com', 'method' => 'draw',
			'font' => null, 'image' => base64_decode( self::PNG ), 'ip' => '1.2.3.4',
			'user_agent' => 'phpunit', 'session_key' => 'sess1',
		], $over );
	}

	public function test_insert_and_find_by_token_roundtrips_image() {
		$repo = new SignatureRepository();
		$id   = $repo->insert( $this->data() );
		$row  = $repo->get( $id );
		$this->assertMatchesRegularExpression( '/^[0-9a-f-]{36}$/', $row['token'] );
		$this->assertSame( base64_decode( self::PNG ), $repo->find_by_token( $row['token'] )['image'] );
		$this->assertNull( $row['order_id'] );
	}

	public function test_attach_is_idempotent_and_refuses_other_order() {
		$repo = new SignatureRepository();
		$id   = $repo->insert( $this->data() );
		$this->assertTrue( $repo->attach( $id, 100 ) );
		$this->assertTrue( $repo->attach( $id, 100 ) );
		$this->assertFalse( $repo->attach( $id, 200 ) );
		$this->assertSame( 100, $repo->get( $id )['order_id'] );
		$this->assertCount( 1, $repo->for_order( 100 ) );
	}

	public function test_purge_only_removes_old_unattached() {
		global $wpdb;
		$repo = new SignatureRepository();
		$old  = $repo->insert( $this->data() );
		$kept = $repo->insert( $this->data() );
		$att  = $repo->insert( $this->data() );
		$repo->attach( $att, 7 );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Migrations::table( 'signatures' ) . ' SET signed_at = %s WHERE id IN (%d,%d)', gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ), $old, $att ) );
		$this->assertSame( 1, $repo->purge_unattached( 30 * DAY_IN_SECONDS ) );
		$this->assertNull( $repo->get( $old ) );
		$this->assertNotNull( $repo->get( $kept ) );
		$this->assertNotNull( $repo->get( $att ) );
	}

	public function test_count_recent_for_session() {
		$repo = new SignatureRepository();
		$repo->insert( $this->data() );
		$repo->insert( $this->data( [ 'session_key' => 'other' ] ) );
		$this->assertSame( 1, $repo->count_recent_for_session( 'sess1', HOUR_IN_SECONDS ) );
	}

	public function test_search_by_name_excludes_image() {
		$repo = new SignatureRepository();
		$repo->insert( $this->data() );
		$res = $repo->search( [ 's' => 'Pari' ] );
		$this->assertSame( 1, $res['total'] );
		$this->assertArrayNotHasKey( 'image', $res['rows'][0] );
	}
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter Test_Agreements_Signatures`
Expected: FAIL with `Class "Anchor\Agreements\Database\SignatureRepository" not found`.

- [ ] **Step 3: Implement**

```php
<?php
declare(strict_types=1);

namespace Anchor\Agreements\Database;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class SignatureRepository {

	private const LIST_COLS = 'id, token, version_id, agreement_id, order_id, product_id, user_id, signer_name, signer_email, method, font, ip, user_agent, signed_at, attached_at';

	private function t(): string {
		return Migrations::table( 'signatures' );
	}

	public function insert( array $d ): int {
		global $wpdb;
		$wpdb->insert( $this->t(), [
			'token'        => \wp_generate_uuid4(),
			'version_id'   => (int) $d['version_id'],
			'agreement_id' => (int) $d['agreement_id'],
			'product_id'   => (int) ( $d['product_id'] ?? 0 ),
			'user_id'      => $d['user_id'] ? (int) $d['user_id'] : null,
			'signer_name'  => (string) $d['signer_name'],
			'signer_email' => (string) ( $d['signer_email'] ?? '' ),
			'method'       => (string) $d['method'],
			'font'         => $d['font'] ?? null,
			'image'        => (string) $d['image'],
			'ip'           => (string) ( $d['ip'] ?? '' ),
			'user_agent'   => substr( (string) ( $d['user_agent'] ?? '' ), 0, 255 ),
			'session_key'  => (string) ( $d['session_key'] ?? '' ),
			'signed_at'    => \current_time( 'mysql', true ),
		] );
		return (int) $wpdb->insert_id;
	}

	public function get( int $id ): ?array {
		global $wpdb;
		return self::shape( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->t()} WHERE id = %d", $id ), ARRAY_A ) );
	}

	public function find_by_token( string $token ): ?array {
		global $wpdb;
		if ( ! preg_match( '/^[0-9a-f-]{36}$/', $token ) ) {
			return null;
		}
		return self::shape( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->t()} WHERE token = %s", $token ), ARRAY_A ) );
	}

	public function attach( int $id, int $order_id ): bool {
		global $wpdb;
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$this->t()} SET order_id = %d, attached_at = COALESCE(attached_at, %s) WHERE id = %d AND (order_id IS NULL OR order_id = %d)",
			$order_id, \current_time( 'mysql', true ), $id, $order_id
		) );
		$row = $this->get( $id );
		return $row && $row['order_id'] === $order_id;
	}

	public function for_order( int $order_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->t()} WHERE order_id = %d ORDER BY id", $order_id ), ARRAY_A );
		return array_map( [ self::class, 'shape' ], $rows ?: [] );
	}

	public function for_signer( int $user_id, string $email ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT ' . self::LIST_COLS . " FROM {$this->t()} WHERE order_id IS NOT NULL AND (user_id = %d OR (signer_email <> '' AND signer_email = %s)) ORDER BY signed_at DESC",
			$user_id, $email
		), ARRAY_A );
		return array_map( [ self::class, 'shape' ], $rows ?: [] );
	}

	public function count_recent_for_session( string $session_key, int $seconds ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$this->t()} WHERE session_key = %s AND signed_at >= %s",
			$session_key, gmdate( 'Y-m-d H:i:s', time() - $seconds )
		) );
	}

	public function purge_unattached( int $older_than_seconds ): int {
		global $wpdb;
		return (int) $wpdb->query( $wpdb->prepare(
			"DELETE FROM {$this->t()} WHERE order_id IS NULL AND signed_at < %s",
			gmdate( 'Y-m-d H:i:s', time() - $older_than_seconds )
		) );
	}

	public function search( array $args ): array {
		global $wpdb;
		$per   = max( 1, (int) ( $args['per_page'] ?? 20 ) );
		$page  = max( 1, (int) ( $args['paged'] ?? 1 ) );
		$where = [ '1=1' ];
		$vals  = [];
		if ( ! empty( $args['s'] ) ) {
			$like    = '%' . $wpdb->esc_like( (string) $args['s'] ) . '%';
			$where[] = '(signer_name LIKE %s OR signer_email LIKE %s OR order_id = %d)';
			array_push( $vals, $like, $like, (int) $args['s'] );
		}
		if ( ! empty( $args['agreement_id'] ) ) {
			$where[] = 'agreement_id = %d';
			$vals[]  = (int) $args['agreement_id'];
		}
		$w     = implode( ' AND ', $where );
		$total = (int) $wpdb->get_var( $vals ? $wpdb->prepare( "SELECT COUNT(*) FROM {$this->t()} WHERE {$w}", $vals ) : "SELECT COUNT(*) FROM {$this->t()} WHERE {$w}" );
		$sql   = 'SELECT ' . self::LIST_COLS . " FROM {$this->t()} WHERE {$w} ORDER BY id DESC LIMIT %d OFFSET %d";
		$rows  = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $vals, [ $per, ( $page - 1 ) * $per ] ) ), ARRAY_A );
		return [ 'rows' => array_map( [ self::class, 'shape' ], $rows ?: [] ), 'total' => $total ];
	}

	private static function shape( ?array $row ): ?array {
		if ( ! $row ) {
			return null;
		}
		foreach ( [ 'id', 'version_id', 'agreement_id', 'product_id' ] as $k ) {
			if ( isset( $row[ $k ] ) ) {
				$row[ $k ] = (int) $row[ $k ];
			}
		}
		foreach ( [ 'order_id', 'user_id' ] as $k ) {
			if ( array_key_exists( $k, $row ) ) {
				$row[ $k ] = null === $row[ $k ] ? null : (int) $row[ $k ];
			}
		}
		return $row;
	}
}
```

- [ ] **Step 4: Run the tests and verify they pass**

Run: `vendor/bin/phpunit --filter Test_Agreements_Signatures`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add anchor-agreements/src/Database/SignatureRepository.php tests/test-agreements-signatures.php
git commit -m "feat(agreements): signature repository with attach, purge and search"
```

---

### Task 5: Requirements resolver and product fields

**Files:**
- Create: `anchor-agreements/src/Services/Requirements.php`, `anchor-agreements/src/Admin/ProductFields.php`
- Modify: `anchor-agreements/anchor-agreements.php`
- Test: `tests/test-agreements-requirements.php`

**Interfaces:**
- Consumes: `AgreementPostType::is_usable()`, `Settings::default_agreement_id()`.
- Produces:
  - `Requirements::for_product(\WC_Product $product): int`. Returns the agreement id, or `0` if none is required or none is usable. Variations resolve through their parent.
  - `Requirements::is_misconfigured(\WC_Product $product): bool`. True when "required" is on but nothing usable resolves.
  - `Requirements::for_cart(?\WC_Cart $cart = null): array<int,int>`, mapping agreement_id → first product_id that needs it.
  - `Requirements::for_order(\WC_Order $order): array<int,int>`, the same shape built from the order's line items.
  - `ProductFields::save(\WC_Product $product): void` (hooked to `woocommerce_admin_process_product_object`).

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/test-agreements-requirements.php
use Anchor\Agreements\Admin\ProductFields;
use Anchor\Agreements\Content\AgreementPostType;
use Anchor\Agreements\Services\Requirements;
use Anchor\Agreements\Support\Settings;

class Test_Agreements_Requirements extends WP_UnitTestCase {
	private function agreement(): int {
		return self::factory()->post->create( [ 'post_type' => AgreementPostType::CPT, 'post_status' => 'publish', 'post_title' => 'Policy' ] );
	}

	private function product( string $required = 'yes', int $agreement = 0 ): WC_Product_Simple {
		$p = new WC_Product_Simple();
		$p->set_name( 'Course' );
		$p->set_regular_price( '10' );
		if ( $required ) {
			$p->update_meta_data( '_anchor_agreement_required', $required );
		}
		$p->update_meta_data( '_anchor_agreement_id', $agreement );
		$p->save();
		return $p;
	}

	public function test_not_required_returns_zero() {
		$this->assertSame( 0, Requirements::for_product( $this->product( '' ) ) );
	}

	public function test_explicit_agreement_wins_over_default() {
		$a = $this->agreement();
		$b = $this->agreement();
		update_option( Settings::OPTION, [ 'default_agreement_id' => $b ] );
		$this->assertSame( $a, Requirements::for_product( $this->product( 'yes', $a ) ) );
	}

	public function test_falls_back_to_default() {
		$b = $this->agreement();
		update_option( Settings::OPTION, [ 'default_agreement_id' => $b ] );
		$this->assertSame( $b, Requirements::for_product( $this->product( 'yes', 0 ) ) );
	}

	public function test_variation_uses_parent() {
		$a      = $this->agreement();
		$parent = new WC_Product_Variable();
		$parent->set_name( 'Var' );
		$parent->update_meta_data( '_anchor_agreement_required', 'yes' );
		$parent->update_meta_data( '_anchor_agreement_id', $a );
		$parent->save();
		$v = new WC_Product_Variation();
		$v->set_parent_id( $parent->get_id() );
		$v->set_regular_price( '5' );
		$v->save();
		$this->assertSame( $a, Requirements::for_product( wc_get_product( $v->get_id() ) ) );
	}

	public function test_save_with_required_but_no_document_adds_error() {
		delete_option( Settings::OPTION );
		$p             = $this->product( '' );
		$_POST         = [ '_anchor_agreement_required' => 'yes', '_anchor_agreement_id' => '0' ];
		WC_Admin_Meta_Boxes::$meta_box_errors = [];
		( new ProductFields() )->save( $p );
		$this->assertTrue( Requirements::is_misconfigured( $p ) );
		$this->assertNotEmpty( WC_Admin_Meta_Boxes::$meta_box_errors );
		$_POST = [];
	}
}
```

If `WC_Admin_Meta_Boxes` isn't loaded in the test bootstrap, add `require_once WC_ABSPATH . 'includes/admin/class-wc-admin-meta-boxes.php';` at the top of that test.

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter Test_Agreements_Requirements`
Expected: FAIL with `Class "Anchor\Agreements\Services\Requirements" not found`.

- [ ] **Step 3: Implement `Requirements`**

```php
<?php
declare(strict_types=1);

namespace Anchor\Agreements\Services;

use Anchor\Agreements\Content\AgreementPostType;
use Anchor\Agreements\Support\Settings;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class Requirements {

	private static function owner( \WC_Product $product ): ?\WC_Product {
		if ( $product->is_type( 'variation' ) ) {
			$parent = \wc_get_product( $product->get_parent_id() );
			return $parent ?: null;
		}
		return $product;
	}

	private static function is_required( \WC_Product $product ): bool {
		$owner = self::owner( $product );
		return $owner && 'yes' === $owner->get_meta( '_anchor_agreement_required' );
	}

	public static function for_product( \WC_Product $product ): int {
		if ( ! self::is_required( $product ) ) {
			return 0;
		}
		$id = (int) self::owner( $product )->get_meta( '_anchor_agreement_id' );
		$id = $id ?: Settings::default_agreement_id();
		return AgreementPostType::is_usable( $id ) ? $id : 0;
	}

	public static function is_misconfigured( \WC_Product $product ): bool {
		return self::is_required( $product ) && 0 === self::for_product( $product );
	}

	public static function for_cart( ?\WC_Cart $cart = null ): array {
		$cart = $cart ?: ( \function_exists( 'WC' ) ? \WC()->cart : null );
		$out  = [];
		if ( ! $cart ) {
			return $out;
		}
		foreach ( $cart->get_cart() as $item ) {
			if ( empty( $item['data'] ) || ! $item['data'] instanceof \WC_Product ) {
				continue;
			}
			$aid = self::for_product( $item['data'] );
			if ( $aid && ! isset( $out[ $aid ] ) ) {
				$out[ $aid ] = (int) $item['data']->get_id();
			}
		}
		return $out;
	}

	public static function for_order( \WC_Order $order ): array {
		$out = [];
		foreach ( $order->get_items() as $item ) {
			$product = $item instanceof \WC_Order_Item_Product ? $item->get_product() : null;
			$aid     = $product ? self::for_product( $product ) : 0;
			if ( $aid && ! isset( $out[ $aid ] ) ) {
				$out[ $aid ] = (int) $product->get_id();
			}
		}
		return $out;
	}
}
```

- [ ] **Step 4: Implement `ProductFields`**

```php
<?php
declare(strict_types=1);

namespace Anchor\Agreements\Admin;

use Anchor\Agreements\Content\AgreementPostType;
use Anchor\Agreements\Services\Requirements;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class ProductFields {

	public function __construct() {
		\add_action( 'woocommerce_product_options_general_product_data', [ $this, 'render' ] );
		\add_action( 'woocommerce_admin_process_product_object', [ $this, 'save' ] );
	}

	public function render(): void {
		global $product_object;
		$options = [ '0' => \__( '— Site default —', 'anchor-schema' ) ];
		foreach ( \get_posts( [ 'post_type' => AgreementPostType::CPT, 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ] ) as $p ) {
			$options[ (string) $p->ID ] = $p->post_title;
		}
		echo '<div class="options_group">';
		\woocommerce_wp_checkbox( [
			'id'          => '_anchor_agreement_required',
			'label'       => \__( 'Require signed agreement', 'anchor-schema' ),
			'description' => \__( 'Buyers must sign before they can place the order.', 'anchor-schema' ),
			'value'       => $product_object ? $product_object->get_meta( '_anchor_agreement_required' ) : '',
		] );
		\woocommerce_wp_select( [
			'id'      => '_anchor_agreement_id',
			'label'   => \__( 'Agreement', 'anchor-schema' ),
			'options' => $options,
			'value'   => $product_object ? (string) (int) $product_object->get_meta( '_anchor_agreement_id' ) : '0',
		] );
		echo '</div>';
	}

	public function save( \WC_Product $product ): void {
		// Nonce is verified by WooCommerce before woocommerce_admin_process_product_object fires.
		$required = isset( $_POST['_anchor_agreement_required'] ) ? 'yes' : '';
		$id       = isset( $_POST['_anchor_agreement_id'] ) ? \absint( \wp_unslash( $_POST['_anchor_agreement_id'] ) ) : 0;
		if ( 'yes' === $required ) {
			$product->update_meta_data( '_anchor_agreement_required', 'yes' );
		} else {
			$product->delete_meta_data( '_anchor_agreement_required' );
		}
		$product->update_meta_data( '_anchor_agreement_id', $id );

		if ( Requirements::is_misconfigured( $product ) && class_exists( '\WC_Admin_Meta_Boxes' ) ) {
			\WC_Admin_Meta_Boxes::add_error( \__( 'This product requires a signed agreement, but no published agreement is selected and no site default is set. Buyers will NOT be asked to sign until this is fixed (Agreements → Settings).', 'anchor-schema' ) );
		}
	}
}
```

- [ ] **Step 5: Wire it.** In `Module::__construct()`:

```php
		if ( \is_admin() ) {
			new Admin\ProductFields();
		}
```

- [ ] **Step 6: Run the tests and verify they pass**

Run: `vendor/bin/phpunit --filter Test_Agreements_Requirements`
Expected: PASS (5 tests).

- [ ] **Step 7: Commit**

```bash
git add anchor-agreements tests/test-agreements-requirements.php
git commit -m "feat(agreements): per-product requirement with site default and misconfig warning"
```

---

### Task 6: Signature check and signing endpoint

**Files:**
- Create: `anchor-agreements/src/Services/SignatureCheck.php`, `anchor-agreements/src/Frontend/SigningEndpoint.php`
- Modify: `anchor-agreements/anchor-agreements.php`
- Test: `tests/test-agreements-signing.php`

**Interfaces:**
- Consumes: `SignatureRepository`, `VersionRepository::current_for()`, `Requirements::for_cart()`, `SignatureImage::decode()`.
- Produces:
  - `SignatureCheck::SESSION = 'anchor_agreements'`. The reuse window comes from `Settings::reuse_seconds()`.
  - `SignatureCheck::session_key(): string` (the WC session customer id, or `''`).
  - `SignatureCheck::session_map(): array<int,int>` and `SignatureCheck::remember(int $agreement_id, int $signature_id): void`.
  - `SignatureCheck::unsigned(array $required, int $awaiting_order_id = 0): int[]`. Takes the `for_cart()`/`for_order()` shape and returns the agreement ids still unsigned.
  - `SignatureCheck::valid_signature_id(int $agreement_id, int $awaiting_order_id = 0): int`.
  - `SigningEndpoint::ACTION = 'anchor_agreement_sign'`. `SigningEndpoint::handle(array $input): array` returns `['ok'=>bool, 'error'?:string, 'signature_id'?:int, 'remaining'?:int]` and is unit-testable. `SigningEndpoint::ajax(): void` is the `wc_ajax_*` wrapper that checks the nonce `anchor_agreements_sign`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/test-agreements-signing.php
use Anchor\Agreements\Content\AgreementPostType;
use Anchor\Agreements\Database\SignatureRepository;
use Anchor\Agreements\Frontend\SigningEndpoint;
use Anchor\Agreements\Services\SignatureCheck;

class Test_Agreements_Signing extends WP_UnitTestCase {
	const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';
	private int $agreement;
	private WC_Product_Simple $product;

	public function set_up(): void {
		parent::set_up();
		WC()->session = new WC_Session_Handler();
		WC()->session->init();
		WC()->cart = new WC_Cart();
		$this->agreement = self::factory()->post->create( [ 'post_type' => AgreementPostType::CPT, 'post_status' => 'publish', 'post_title' => 'Cancellation Policy', 'post_content' => 'Terms' ] );
		$this->product   = new WC_Product_Simple();
		$this->product->set_regular_price( '10' );
		$this->product->update_meta_data( '_anchor_agreement_required', 'yes' );
		$this->product->update_meta_data( '_anchor_agreement_id', $this->agreement );
		$this->product->save();
		WC()->cart->add_to_cart( $this->product->get_id() );
	}

	private function input( array $over = [] ): array {
		return array_merge( [ 'agreement_id' => $this->agreement, 'name' => 'Pari Example', 'method' => 'draw', 'font' => '', 'image' => self::PNG, 'consent' => '1' ], $over );
	}

	public function test_valid_signature_is_stored_and_remembered() {
		$res = ( new SigningEndpoint() )->handle( $this->input() );
		$this->assertTrue( $res['ok'] );
		$this->assertSame( 0, $res['remaining'] );
		$this->assertSame( [ $this->agreement => $res['signature_id'] ], SignatureCheck::session_map() );
		$this->assertSame( [], ( new SignatureCheck() )->unsigned( [ $this->agreement => $this->product->get_id() ] ) );
	}

	/** @dataProvider rejects */
	public function test_rejects_bad_input( array $over, string $error ) {
		$res = ( new SigningEndpoint() )->handle( $this->input( $over ) );
		$this->assertFalse( $res['ok'] );
		$this->assertSame( $error, $res['error'] );
	}

	public function rejects(): array {
		return [
			'no consent'       => [ [ 'consent' => '' ], 'consent' ],
			'empty name'       => [ [ 'name' => '  ' ], 'name' ],
			'bad method'       => [ [ 'method' => 'stamp' ], 'method' ],
			'bad font'         => [ [ 'method' => 'generate', 'font' => 'comic-sans' ], 'font' ],
			'bad image'        => [ [ 'image' => 'data:image/svg+xml;base64,PHN2Zz4=' ], 'image' ],
			'not in cart'      => [ [ 'agreement_id' => 999999 ], 'agreement' ],
		];
	}

	public function test_rate_limit() {
		$ep = new SigningEndpoint();
		for ( $i = 0; $i < 10; $i++ ) {
			$this->assertTrue( $ep->handle( $this->input() )['ok'] );
		}
		$this->assertSame( 'rate_limited', $ep->handle( $this->input() )['error'] );
	}

	public function test_signature_older_than_window_is_unsigned() {
		global $wpdb;
		$res = ( new SigningEndpoint() )->handle( $this->input() );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . \Anchor\Agreements\Database\Migrations::table( 'signatures' ) . ' SET signed_at = %s WHERE id = %d', gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ), $res['signature_id'] ) );
		$this->assertSame( [ $this->agreement ], ( new SignatureCheck() )->unsigned( [ $this->agreement => 1 ] ) );
	}

	public function test_signature_attached_to_awaiting_order_still_counts() {
		$res = ( new SigningEndpoint() )->handle( $this->input() );
		( new SignatureRepository() )->attach( $res['signature_id'], 555 );
		$check = new SignatureCheck();
		$this->assertSame( [], $check->unsigned( [ $this->agreement => 1 ], 555 ) );
		$this->assertSame( [ $this->agreement ], $check->unsigned( [ $this->agreement => 1 ], 0 ) );
	}
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter Test_Agreements_Signing`
Expected: FAIL with `Class "Anchor\Agreements\Frontend\SigningEndpoint" not found`.

- [ ] **Step 3: Implement `SignatureCheck`**

```php
<?php
declare(strict_types=1);

namespace Anchor\Agreements\Services;

use Anchor\Agreements\Database\SignatureRepository;
use Anchor\Agreements\Support\Settings;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * The single answer to "is agreement X signed for this checkout?". Checkout
 * validation, the checkbox render and the endpoint's "remaining" count all ask
 * here, so they can never disagree.
 */
final class SignatureCheck {

	public const SESSION = 'anchor_agreements';

	public function __construct( private ?SignatureRepository $repo = null ) {
		$this->repo = $repo ?: new SignatureRepository();
	}

	public static function session_key(): string {
		if ( ! \function_exists( 'WC' ) || ! \WC()->session ) {
			return '';
		}
		return (string) \WC()->session->get_customer_id();
	}

	public static function session_map(): array {
		$map = \function_exists( 'WC' ) && \WC()->session ? \WC()->session->get( self::SESSION, [] ) : [];
		return \is_array( $map ) ? array_map( 'intval', $map ) : [];
	}

	public static function remember( int $agreement_id, int $signature_id ): void {
		if ( ! \function_exists( 'WC' ) || ! \WC()->session ) {
			return;
		}
		$map                  = self::session_map();
		$map[ $agreement_id ] = $signature_id;
		\WC()->session->set( self::SESSION, $map );
	}

	public static function forget(): void {
		if ( \function_exists( 'WC' ) && \WC()->session ) {
			\WC()->session->set( self::SESSION, [] );
		}
	}

	public function valid_signature_id( int $agreement_id, int $awaiting_order_id = 0 ): int {
		$sig_id = self::session_map()[ $agreement_id ] ?? 0;
		$row    = $sig_id ? $this->repo->get( $sig_id ) : null;
		if ( ! $row || $row['agreement_id'] !== $agreement_id || $row['session_key'] !== self::session_key() ) {
			return 0;
		}
		if ( strtotime( $row['signed_at'] . ' UTC' ) < time() - Settings::reuse_seconds() ) {
			return 0;
		}
		if ( null !== $row['order_id'] && $row['order_id'] !== $awaiting_order_id ) {
			return 0;
		}
		return $sig_id;
	}

	/** @param array<int,int> $required agreement_id => product_id. @return int[] */
	public function unsigned( array $required, int $awaiting_order_id = 0 ): array {
		$out = [];
		foreach ( array_keys( $required ) as $aid ) {
			if ( ! $this->valid_signature_id( (int) $aid, $awaiting_order_id ) ) {
				$out[] = (int) $aid;
			}
		}
		return $out;
	}

	public static function awaiting_order_id(): int {
		return \function_exists( 'WC' ) && \WC()->session ? (int) \WC()->session->get( 'order_awaiting_payment', 0 ) : 0;
	}
}
```

- [ ] **Step 4: Implement `SigningEndpoint`**

```php
<?php
declare(strict_types=1);

namespace Anchor\Agreements\Frontend;

use Anchor\Agreements\Database\SignatureRepository;
use Anchor\Agreements\Database\VersionRepository;
use Anchor\Agreements\Services\Requirements;
use Anchor\Agreements\Services\SignatureCheck;
use Anchor\Agreements\Support\SignatureImage;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class SigningEndpoint {

	public const ACTION = 'anchor_agreement_sign';
	public const NONCE  = 'anchor_agreements_sign';
	public const FONTS  = [ 'dancing-script', 'great-vibes', 'allura', 'caveat' ];
	private const LIMIT = 10;

	public function __construct() {
		\add_action( 'wc_ajax_' . self::ACTION, [ $this, 'ajax' ] );
	}

	public function ajax(): void {
		if ( ! \check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			\wp_send_json( [ 'ok' => false, 'error' => 'nonce' ], 403 );
		}
		$in  = \wp_unslash( $_POST );
		$res = $this->handle( [
			'agreement_id' => $in['agreement_id'] ?? 0,
			'name'         => $in['name'] ?? '',
			'method'       => $in['method'] ?? '',
			'font'         => $in['font'] ?? '',
			'image'        => $in['image'] ?? '',
			'consent'      => $in['consent'] ?? '',
		] );
		\wp_send_json( $res, $res['ok'] ? 200 : 400 );
	}

	public function handle( array $in ): array {
		$aid      = \absint( $in['agreement_id'] ?? 0 );
		$required = Requirements::for_cart();
		if ( ! isset( $required[ $aid ] ) ) {
			return [ 'ok' => false, 'error' => 'agreement' ];
		}
		if ( '1' !== (string) ( $in['consent'] ?? '' ) ) {
			return [ 'ok' => false, 'error' => 'consent' ];
		}
		$name = trim( \sanitize_text_field( (string) ( $in['name'] ?? '' ) ) );
		if ( '' === $name || mb_strlen( $name ) > 120 ) {
			return [ 'ok' => false, 'error' => 'name' ];
		}
		$method = (string) ( $in['method'] ?? '' );
		if ( ! \in_array( $method, [ 'draw', 'generate' ], true ) ) {
			return [ 'ok' => false, 'error' => 'method' ];
		}
		$font = null;
		if ( 'generate' === $method ) {
			$font = (string) ( $in['font'] ?? '' );
			if ( ! \in_array( $font, self::FONTS, true ) ) {
				return [ 'ok' => false, 'error' => 'font' ];
			}
		}
		$image = SignatureImage::decode( (string) ( $in['image'] ?? '' ) );
		if ( null === $image ) {
			return [ 'ok' => false, 'error' => 'image' ];
		}
		$repo    = new SignatureRepository();
		$session = SignatureCheck::session_key();
		if ( $repo->count_recent_for_session( $session, HOUR_IN_SECONDS ) >= self::LIMIT ) {
			return [ 'ok' => false, 'error' => 'rate_limited' ];
		}
		$version = ( new VersionRepository() )->current_for( $aid );
		if ( ! $version ) {
			return [ 'ok' => false, 'error' => 'agreement' ];
		}
		$user = \wp_get_current_user();
		$id   = $repo->insert( [
			'version_id'   => $version['id'],
			'agreement_id' => $aid,
			'product_id'   => $required[ $aid ],
			'user_id'      => $user->ID ?: null,
			'signer_name'  => $name,
			'signer_email' => $user->ID ? (string) $user->user_email : (string) ( \WC()->customer ? \WC()->customer->get_billing_email() : '' ),
			'method'       => $method,
			'font'         => $font,
			'image'        => $image,
			'ip'           => \WC_Geolocation::get_ip_address(),
			'user_agent'   => (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ),
			'session_key'  => $session,
		] );
		SignatureCheck::remember( $aid, $id );

		$remaining = \count( ( new SignatureCheck( $repo ) )->unsigned( $required, SignatureCheck::awaiting_order_id() ) );
		return [ 'ok' => true, 'signature_id' => $id, 'remaining' => $remaining ];
	}
}
```

- [ ] **Step 5: Wire it.** In `Module::__construct()`: `new Frontend\SigningEndpoint();`

- [ ] **Step 6: Run the tests and verify they pass**

Run: `vendor/bin/phpunit --filter Test_Agreements_Signing`
Expected: PASS (10 tests). If `get_customer_id()` returns a different value per call in tests, call `WC()->session->set_customer_session_cookie( true )` in `set_up()`.

- [ ] **Step 7: Commit**

```bash
git add anchor-agreements tests/test-agreements-signing.php
git commit -m "feat(agreements): signing endpoint and single signature-validity check"
```

---

### Task 7: Checkout integration (checkbox, validation, attach, Store API guard)

**Files:**
- Create: `anchor-agreements/src/Frontend/Checkout.php`
- Modify: `anchor-agreements/anchor-agreements.php`
- Test: `tests/test-agreements-checkout.php`

**Interfaces:**
- Consumes: `Requirements::for_cart()`, `Requirements::for_order()`, `SignatureCheck` (all methods), `SignatureRepository::attach()`, `VersionRepository::current_for()`, `Settings::label_for()`, `SigningEndpoint::ACTION|NONCE|FONTS`.
- Produces:
  - `Checkout::render(): void` (on `woocommerce_review_order_before_submit`). Emits `.aagr-checkout` with `data-complete="0|1"`, a disabled checkbox `#aagr-confirm`, a `.aagr-open` button, and a `<template class="aagr-doc" data-agreement-id data-title data-version-date data-signed="0|1">` per required document holding the sanitised HTML.
  - `Checkout::validate(array $data, \WP_Error $errors): void` (on `woocommerce_after_checkout_validation`). Error code `anchor_agreements_unsigned`.
  - `Checkout::attach(\WC_Order $order): void` (on `woocommerce_checkout_order_created`). Writes order meta `_anchor_agreement_signature_ids` and fires `do_action( 'anchor_agreements_attached', int $order_id, int[] $signature_ids )`.
  - `Checkout::forget_on_thankyou(): void` (on `woocommerce_thankyou`).
  - `Checkout::guard_store_api(\WC_Order $order): void` (on `woocommerce_store_api_checkout_update_order_from_request`).

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/test-agreements-checkout.php
use Anchor\Agreements\Content\AgreementPostType;
use Anchor\Agreements\Database\SignatureRepository;
use Anchor\Agreements\Frontend\Checkout;
use Anchor\Agreements\Frontend\SigningEndpoint;

class Test_Agreements_Checkout extends WP_UnitTestCase {
	const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

	public function set_up(): void {
		parent::set_up();
		WC()->session = new WC_Session_Handler();
		WC()->session->init();
		WC()->session->set_customer_session_cookie( true );
		WC()->cart = new WC_Cart();
	}

	private function product_with_agreement( string $title ): array {
		$aid = self::factory()->post->create( [ 'post_type' => AgreementPostType::CPT, 'post_status' => 'publish', 'post_title' => $title, 'post_content' => '<p>Body of ' . $title . '</p><script>x</script>' ] );
		$p   = new WC_Product_Simple();
		$p->set_regular_price( '10' );
		$p->update_meta_data( '_anchor_agreement_required', 'yes' );
		$p->update_meta_data( '_anchor_agreement_id', $aid );
		$p->save();
		return [ $aid, $p ];
	}

	private function sign( int $aid ): int {
		return ( new SigningEndpoint() )->handle( [ 'agreement_id' => $aid, 'name' => 'A B', 'method' => 'draw', 'font' => '', 'image' => self::PNG, 'consent' => '1' ] )['signature_id'];
	}

	private function render(): string {
		ob_start();
		( new Checkout() )->render();
		return (string) ob_get_clean();
	}

	public function test_render_nothing_when_no_requirement() {
		$p = new WC_Product_Simple();
		$p->set_regular_price( '1' );
		$p->save();
		WC()->cart->add_to_cart( $p->get_id() );
		$this->assertSame( '', $this->render() );
	}

	public function test_render_unchecked_then_checked_after_signing() {
		[ $aid, $p ] = $this->product_with_agreement( 'Cancellation Policy' );
		WC()->cart->add_to_cart( $p->get_id() );
		$html = $this->render();
		$this->assertStringContainsString( 'data-complete="0"', $html );
		$this->assertStringContainsString( 'I have read and signed the Cancellation Policy', $html );
		$this->assertStringNotContainsString( '<script>x</script>', $html );
		$this->sign( $aid );
		$this->assertStringContainsString( 'data-complete="1"', $this->render() );
	}

	public function test_render_shows_checked_after_signing() {
		// Fragment refresh = render() called again on a fresh request; state must come from the session.
		[ $aid, $p ] = $this->product_with_agreement( 'Cancellation Policy' );
		WC()->cart->add_to_cart( $p->get_id() );
		$this->sign( $aid );
		$this->render();
		$this->assertStringContainsString( 'checked', $this->render() );
	}

	public function test_two_documents_use_count_label() {
		[ , $p1 ] = $this->product_with_agreement( 'Policy A' );
		[ , $p2 ] = $this->product_with_agreement( 'Policy B' );
		WC()->cart->add_to_cart( $p1->get_id() );
		WC()->cart->add_to_cart( $p2->get_id() );
		$this->assertStringContainsString( 'I have read and signed 2 required agreements', $this->render() );
	}

	public function test_validate_blocks_unsigned_and_passes_signed() {
		[ $aid, $p ] = $this->product_with_agreement( 'Cancellation Policy' );
		WC()->cart->add_to_cart( $p->get_id() );
		$errors = new WP_Error();
		( new Checkout() )->validate( [], $errors );
		$this->assertContains( 'anchor_agreements_unsigned', $errors->get_error_codes() );
		$this->sign( $aid );
		$errors = new WP_Error();
		( new Checkout() )->validate( [], $errors );
		$this->assertSame( [], $errors->get_error_codes() );
	}

	public function test_only_required_signatures_are_attached() {
		[ $a1, $p1 ] = $this->product_with_agreement( 'Policy A' );
		[ $a2, $p2 ] = $this->product_with_agreement( 'Policy B' );
		WC()->cart->add_to_cart( $p1->get_id() );
		WC()->cart->add_to_cart( $p2->get_id() );
		$s1 = $this->sign( $a1 );
		$s2 = $this->sign( $a2 );
		// Buyer removes product 2 before ordering.
		$order = wc_create_order();
		$order->add_product( $p1, 1 );
		$order->save();
		( new Checkout() )->attach( $order );
		$repo = new SignatureRepository();
		$this->assertSame( $order->get_id(), $repo->get( $s1 )['order_id'] );
		$this->assertNull( $repo->get( $s2 )['order_id'] );
		$this->assertSame( [ $s1 ], wc_get_order( $order->get_id() )->get_meta( '_anchor_agreement_signature_ids' ) );
	}

	public function test_store_api_guard_throws_when_required() {
		[ , $p ] = $this->product_with_agreement( 'Cancellation Policy' );
		$order   = wc_create_order();
		$order->add_product( $p, 1 );
		$this->expectException( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException::class );
		( new Checkout() )->guard_store_api( $order );
	}
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter Test_Agreements_Checkout`
Expected: FAIL with `Class "Anchor\Agreements\Frontend\Checkout" not found`.

- [ ] **Step 3: Implement**

```php
<?php
declare(strict_types=1);

namespace Anchor\Agreements\Frontend;

use Anchor\Agreements\Database\SignatureRepository;
use Anchor\Agreements\Database\VersionRepository;
use Anchor\Agreements\Module;
use Anchor\Agreements\Services\Requirements;
use Anchor\Agreements\Services\SignatureCheck;
use Anchor\Agreements\Support\Settings;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Classic + FunnelKit checkout. Both fire woocommerce_review_order_before_submit
 * inside the payment fragment that update_order_review REPLACES on every refresh,
 * so the checked state is always rendered from the session, never kept in JS.
 */
final class Checkout {

	public function __construct() {
		\add_action( 'woocommerce_review_order_before_submit', [ $this, 'render' ] );
		\add_action( 'woocommerce_after_checkout_validation', [ $this, 'validate' ], 10, 2 );
		\add_action( 'woocommerce_checkout_order_created', [ $this, 'attach' ] );
		\add_action( 'woocommerce_thankyou', [ $this, 'forget_on_thankyou' ] );
		\add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ $this, 'guard_store_api' ] );
		\add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	public function enqueue(): void {
		if ( ! \is_checkout() || \is_order_received_page() || ! Requirements::for_cart() ) {
			return;
		}
		$dir = Module::dir() . '/assets/';
		\wp_enqueue_style( 'aagr-checkout', Module::url( 'assets/checkout.css' ), [], (string) @filemtime( $dir . 'checkout.css' ) );
		\wp_enqueue_script( 'aagr-checkout', Module::url( 'assets/checkout.js' ), [ 'jquery' ], (string) @filemtime( $dir . 'checkout.js' ), true );
		\wp_localize_script( 'aagr-checkout', 'aagrCheckout', [
			'endpoint' => \WC_AJAX::get_endpoint( SigningEndpoint::ACTION ),
			'nonce'    => \wp_create_nonce( SigningEndpoint::NONCE ),
			'fonts'    => [
				[ 'key' => 'dancing-script', 'family' => 'Aagr Dancing Script' ],
				[ 'key' => 'great-vibes', 'family' => 'Aagr Great Vibes' ],
				[ 'key' => 'allura', 'family' => 'Aagr Allura' ],
				[ 'key' => 'caveat', 'family' => 'Aagr Caveat' ],
			],
			'i18n'     => [
				'draw' => \__( 'Draw', 'anchor-schema' ), 'generate' => \__( 'Generate', 'anchor-schema' ),
				'clear' => \__( 'Clear', 'anchor-schema' ), 'name' => \__( 'Full legal name', 'anchor-schema' ),
				'consent' => Settings::consent_text(),
				'sign' => \__( 'Sign & continue', 'anchor-schema' ), 'step' => \__( '%1$d of %2$d', 'anchor-schema' ),
				'close' => \__( 'Close', 'anchor-schema' ), 'error' => \__( 'We could not save your signature. Please try again.', 'anchor-schema' ),
				'version' => \__( 'Version of %s', 'anchor-schema' ),
			],
		] );
	}

	public function render(): void {
		$required = Requirements::for_cart();
		if ( ! $required ) {
			return;
		}
		$check    = new SignatureCheck();
		$unsigned = $check->unsigned( $required, SignatureCheck::awaiting_order_id() );
		$complete = ! $unsigned;
		$versions = new VersionRepository();
		$docs     = [];
		foreach ( array_keys( $required ) as $aid ) {
			$v = $versions->current_for( (int) $aid );
			if ( $v ) {
				$docs[] = $v;
			}
		}
		if ( ! $docs ) {
			return;
		}
		$label = 1 === \count( $docs )
			? Settings::label_for( $docs[0]['title'] )
			/* translators: %d: number of agreements */
			: sprintf( \__( 'I have read and signed %d required agreements', 'anchor-schema' ), \count( $docs ) );

		echo '<div class="aagr-checkout" data-complete="' . ( $complete ? '1' : '0' ) . '">';
		echo '<label class="aagr-checkout__label"><input type="checkbox" id="aagr-confirm" class="aagr-checkout__box" ' . \checked( $complete, true, false ) . ' tabindex="-1" aria-readonly="true" /> ';
		echo '<span>' . \esc_html( $label ) . '</span></label> ';
		echo '<button type="button" class="aagr-open">' . \esc_html( $complete ? \__( 'View', 'anchor-schema' ) : \__( 'Read & sign', 'anchor-schema' ) ) . '</button>';
		foreach ( $docs as $d ) {
			$signed = ! \in_array( $d['agreement_id'], $unsigned, true );
			printf(
				'<template class="aagr-doc" data-agreement-id="%d" data-title="%s" data-version-date="%s" data-signed="%d">%s</template>',
				$d['agreement_id'],
				\esc_attr( $d['title'] ),
				\esc_attr( \wp_date( \get_option( 'date_format' ), strtotime( $d['created_at'] . ' UTC' ) ) ),
				$signed ? 1 : 0,
				\wp_kses_post( \wpautop( $d['content'] ) )
			);
		}
		echo '</div>';
	}

	public function validate( array $data, \WP_Error $errors ): void {
		$required = Requirements::for_cart();
		if ( ! $required ) {
			return;
		}
		if ( ( new SignatureCheck() )->unsigned( $required, SignatureCheck::awaiting_order_id() ) ) {
			$errors->add( 'anchor_agreements_unsigned', \__( 'Please read and sign the required agreement before placing your order.', 'anchor-schema' ) );
		}
	}

	public function attach( \WC_Order $order ): void {
		$required = Requirements::for_order( $order );
		if ( ! $required ) {
			return;
		}
		$check = new SignatureCheck();
		$repo  = new SignatureRepository();
		$ids   = [];
		foreach ( array_keys( $required ) as $aid ) {
			$sig = $check->valid_signature_id( (int) $aid, $order->get_id() );
			if ( $sig && $repo->attach( $sig, $order->get_id() ) ) {
				$ids[] = $sig;
			}
		}
		if ( $ids ) {
			$order->update_meta_data( '_anchor_agreement_signature_ids', $ids );
			$order->save_meta_data();
			\do_action( 'anchor_agreements_attached', $order->get_id(), $ids );
		}
	}

	public function forget_on_thankyou(): void {
		SignatureCheck::forget();
	}

	public function guard_store_api( \WC_Order $order ): void {
		if ( Requirements::for_order( $order ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
				'anchor_agreements_block_checkout',
				\__( 'This order requires a signed agreement, which the block checkout does not support. Please contact us to complete your purchase.', 'anchor-schema' ),
				400
			);
		}
	}
}
```

Note: `valid_signature_id( $aid, $order->get_id() )` accepts both unattached rows and rows already on *this* order. That makes `attach()` idempotent across the failed-payment retry.

- [ ] **Step 4: Wire it.** In `Module::__construct()`: `new Frontend\Checkout();`

- [ ] **Step 5: Run the tests and verify they pass**

Run: `vendor/bin/phpunit --filter Test_Agreements_Checkout`
Expected: PASS (7 tests).

- [ ] **Step 6: Commit**

```bash
git add anchor-agreements tests/test-agreements-checkout.php
git commit -m "feat(agreements): checkout checkbox, validation, order attach and Store API guard"
```

---

### Task 8: Signing modal: pad, generated signatures, mobile

**Files:**
- Create: `anchor-agreements/assets/checkout.js`, `anchor-agreements/assets/checkout.css`, `anchor-agreements/assets/fonts/` (four `.woff2` + `OFL.txt`)
- Test: `e2e/agreements-checkout.spec.js` (Playwright, existing `playwright.config.js`)

**Interfaces:**
- Consumes: the markup from Task 7 (`.aagr-checkout`, `.aagr-open`, `#aagr-confirm`, `template.aagr-doc[data-agreement-id|data-title|data-version-date|data-signed]`) and `window.aagrCheckout` (`endpoint`, `nonce`, `fonts[{key,family}]`, `i18n`).
- Produces: a POST to `aagrCheckout.endpoint` with `nonce, agreement_id, name, method, font, image, consent`. After the last document it calls `jQuery(document.body).trigger('update_checkout')` so the server re-renders the checkbox as checked.

- [ ] **Step 1: Add the fonts.** Download the Latin-subset `.woff2` files for **Dancing Script, Great Vibes, Allura, Caveat** from Google Fonts (all SIL OFL 1.1) into `assets/fonts/` as `dancing-script.woff2`, `great-vibes.woff2`, `allura.woff2`, `caveat.woff2`, and add `OFL.txt` (the licence text). Self-hosted on purpose: no Google Fonts request at checkout.

- [ ] **Step 2: Write the failing e2e test**

```js
// e2e/agreements-checkout.spec.js
const { test, expect, devices } = require( '@playwright/test' );

// bin/e2e-seed.sh must create: agreement "Cancellation Policy", product slug "agreement-test" with
// _anchor_agreement_required=yes pointing at it, and set it as default (see Step 5).
for ( const device of [ 'Desktop Chrome', 'iPhone 13' ] ) {
	test.describe( device, () => {
		test.use( { ...devices[ device ] } );

		test( 'cannot order unsigned; draw signature unlocks; generate works', async ( { page } ) => {
			await page.goto( '/?add-to-cart=' + process.env.AAGR_PRODUCT_ID );
			await page.goto( '/checkout/' );
			const box = page.locator( '#aagr-confirm' );
			await expect( box ).not.toBeChecked();

			await page.locator( '.aagr-open' ).click();
			const modal = page.locator( '.aagr-modal' );
			await expect( modal ).toBeVisible();
			await expect( modal.locator( '.aagr-modal__doc' ) ).toContainText( 'Cancellation Policy' );

			const sign = modal.locator( '.aagr-modal__sign' );
			await expect( sign ).toBeDisabled();
			await modal.locator( 'input[name="aagr-name"]' ).fill( 'Test Buyer' );

			const pad = modal.locator( 'canvas.aagr-pad' );
			const b   = await pad.boundingBox();
			await page.mouse.move( b.x + 20, b.y + 40 );
			await page.mouse.down();
			await page.mouse.move( b.x + 120, b.y + 60, { steps: 8 } );
			await page.mouse.up();
			await modal.locator( 'input[name="aagr-consent"]' ).check();
			await expect( sign ).toBeEnabled();
			await sign.click();

			await expect( modal ).toBeHidden();
			await expect( page.locator( '.aagr-checkout[data-complete="1"] #aagr-confirm' ) ).toBeChecked();
		} );

		test( 'generate tab renders four font choices', async ( { page } ) => {
			await page.goto( '/?add-to-cart=' + process.env.AAGR_PRODUCT_ID );
			await page.goto( '/checkout/' );
			await page.locator( '.aagr-open' ).click();
			await page.locator( '.aagr-tab[data-tab="generate"]' ).click();
			await page.locator( 'input[name="aagr-name"]' ).fill( 'Test Buyer' );
			await expect( page.locator( '.aagr-font-choice' ) ).toHaveCount( 4 );
		} );
	} );
}
```

- [ ] **Step 3: Run it to verify it fails**

Run: `npm run wp-env start && npm run env:seed && AAGR_PRODUCT_ID=<id printed by seed> npx playwright test e2e/agreements-checkout.spec.js`
Expected: FAIL, because `.aagr-modal` is never visible.

- [ ] **Step 4: Write `checkout.css`**

```css
@font-face { font-family: "Aagr Dancing Script"; src: url("fonts/dancing-script.woff2") format("woff2"); font-display: swap; }
@font-face { font-family: "Aagr Great Vibes"; src: url("fonts/great-vibes.woff2") format("woff2"); font-display: swap; }
@font-face { font-family: "Aagr Allura"; src: url("fonts/allura.woff2") format("woff2"); font-display: swap; }
@font-face { font-family: "Aagr Caveat"; src: url("fonts/caveat.woff2") format("woff2"); font-display: swap; }

.aagr-checkout { margin: 0 0 1em; padding: 12px 14px; border: 1px solid #d0d5dd; border-radius: 8px; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.aagr-checkout__label { display: flex; gap: 8px; align-items: center; margin: 0; cursor: pointer; flex: 1 1 240px; }
.aagr-checkout__box { pointer-events: none; }
.aagr-open { background: none; border: 0; padding: 0; text-decoration: underline; cursor: pointer; font: inherit; color: inherit; }
.aagr-checkout[data-complete="1"] { border-color: #12b76a; }

.aagr-modal { position: fixed; inset: 0; z-index: 100000; display: flex; align-items: center; justify-content: center; background: rgba(16, 24, 40, .6); }
.aagr-modal[hidden] { display: none; }
.aagr-modal__dialog { background: #fff; color: #101828; width: min(720px, 100%); max-height: 92vh; display: flex; flex-direction: column; border-radius: 12px; overflow: hidden; }
.aagr-modal__head { display: flex; justify-content: space-between; align-items: center; padding: 14px 18px; border-bottom: 1px solid #eaecf0; }
.aagr-modal__head h2 { margin: 0; font-size: 1.15em; }
.aagr-modal__step, .aagr-modal__version { font-size: .85em; color: #667085; }
.aagr-modal__close { background: none; border: 0; font-size: 1.6em; line-height: 1; cursor: pointer; }
.aagr-modal__body { overflow-y: auto; padding: 16px 18px; -webkit-overflow-scrolling: touch; }
.aagr-modal__doc { max-height: 38vh; overflow-y: auto; padding: 12px; background: #f9fafb; border: 1px solid #eaecf0; border-radius: 8px; margin-bottom: 14px; }
.aagr-modal__field { display: block; margin-bottom: 12px; }
.aagr-modal__field input[type="text"] { width: 100%; font-size: 16px; padding: 10px; } /* 16px stops iOS zoom-on-focus */
.aagr-tabs { display: flex; gap: 4px; margin-bottom: 8px; }
.aagr-tab { flex: 1; padding: 10px; border: 1px solid #d0d5dd; background: #fff; cursor: pointer; border-radius: 6px; font: inherit; }
.aagr-tab[aria-selected="true"] { background: #101828; color: #fff; border-color: #101828; }
.aagr-pad-wrap { position: relative; border: 1px dashed #98a2b3; border-radius: 8px; background: #fff; }
.aagr-pad { display: block; width: 100%; height: 180px; touch-action: none; cursor: crosshair; }
.aagr-pad-clear { position: absolute; top: 6px; right: 6px; font-size: .85em; background: #fff; border: 1px solid #d0d5dd; border-radius: 4px; padding: 2px 8px; cursor: pointer; }
.aagr-pad-line { position: absolute; left: 16px; right: 16px; bottom: 36px; border-bottom: 1px solid #d0d5dd; pointer-events: none; }
.aagr-fonts { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.aagr-font-choice { height: 80px; border: 1px solid #d0d5dd; border-radius: 8px; background: #fff; cursor: pointer; font-size: 30px; overflow: hidden; white-space: nowrap; padding: 0 10px; }
.aagr-font-choice[aria-pressed="true"] { border: 2px solid #101828; }
.aagr-modal__foot { padding: 12px 18px; border-top: 1px solid #eaecf0; display: flex; flex-direction: column; gap: 10px; background: #fff; }
.aagr-modal__consent { display: flex; gap: 8px; align-items: flex-start; font-size: .9em; }
.aagr-modal__sign { padding: 14px; font-size: 1em; font-weight: 600; border: 0; border-radius: 8px; background: #101828; color: #fff; cursor: pointer; }
.aagr-modal__sign:disabled { opacity: .45; cursor: not-allowed; }
.aagr-modal__error { color: #b42318; font-size: .9em; }
html.aagr-lock, html.aagr-lock body { overflow: hidden; }

@media (max-width: 640px) {
	.aagr-modal { align-items: stretch; }
	.aagr-modal__dialog { width: 100%; max-height: none; height: 100%; border-radius: 0; }
	.aagr-modal__body { flex: 1; }
	.aagr-modal__doc { max-height: 30vh; }
	.aagr-fonts { grid-template-columns: 1fr; }
	.aagr-modal__foot { position: sticky; bottom: 0; padding-bottom: calc(12px + env(safe-area-inset-bottom)); }
}
```

- [ ] **Step 5: Write `checkout.js`**

```js
/* Anchor Agreements: checkout signing modal. No dependencies beyond jQuery (for WooCommerce's events). */
( function ( $ ) {
	'use strict';
	const cfg = window.aagrCheckout;
	if ( ! cfg ) {
		return;
	}
	const t = cfg.i18n;
	let modal = null, queue = [], index = 0, pad = null, state = { method: 'draw', font: '' };

	function el( tag, attrs, html ) {
		const n = document.createElement( tag );
		Object.entries( attrs || {} ).forEach( ( [ k, v ] ) => n.setAttribute( k, v ) );
		if ( html !== undefined ) n.innerHTML = html;
		return n;
	}

	/* ---- Signature pad: pointer events, DPR-aware, keeps strokes on resize. ---- */
	function Pad( canvas, onChange ) {
		const ctx = canvas.getContext( '2d' );
		let strokes = [], current = null;
		function size() {
			const r = canvas.getBoundingClientRect(), dpr = window.devicePixelRatio || 1;
			canvas.width = Math.round( r.width * dpr );
			canvas.height = Math.round( r.height * dpr );
			ctx.setTransform( dpr, 0, 0, dpr, 0, 0 );
			redraw();
		}
		function point( e ) {
			const r = canvas.getBoundingClientRect();
			return { x: e.clientX - r.left, y: e.clientY - r.top };
		}
		function redraw() {
			ctx.clearRect( 0, 0, canvas.width, canvas.height );
			ctx.lineWidth = 2.4; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#101828';
			strokes.forEach( ( s ) => {
				ctx.beginPath();
				s.forEach( ( p, i ) => ( i ? ctx.lineTo( p.x, p.y ) : ctx.moveTo( p.x, p.y ) ) );
				if ( s.length === 1 ) ctx.lineTo( s[ 0 ].x + 0.1, s[ 0 ].y + 0.1 );
				ctx.stroke();
			} );
		}
		canvas.addEventListener( 'pointerdown', ( e ) => {
			e.preventDefault();
			canvas.setPointerCapture( e.pointerId );
			current = [ point( e ) ];
			strokes.push( current );
			redraw();
		} );
		canvas.addEventListener( 'pointermove', ( e ) => {
			if ( ! current ) return;
			e.preventDefault();
			current.push( point( e ) );
			redraw();
		} );
		const end = () => { if ( current ) { current = null; onChange(); } };
		canvas.addEventListener( 'pointerup', end );
		canvas.addEventListener( 'pointercancel', end );
		window.addEventListener( 'resize', size );
		this.size = size;
		this.clear = () => { strokes = []; redraw(); onChange(); };
		this.isEmpty = () => strokes.length === 0;
		this.toDataURL = () => canvas.toDataURL( 'image/png' );
		this.destroy = () => window.removeEventListener( 'resize', size );
	}

	/* ---- Generated signature: render the name in the chosen font onto a canvas. ---- */
	function renderGenerated( name, family ) {
		const c = document.createElement( 'canvas' ), w = 900, h = 220;
		c.width = w; c.height = h;
		const ctx = c.getContext( '2d' );
		let size = 110;
		ctx.fillStyle = '#101828'; ctx.textBaseline = 'middle';
		do { ctx.font = size + 'px "' + family + '"'; size -= 4; } while ( ctx.measureText( name ).width > w - 40 && size > 24 );
		ctx.fillText( name, 20, h / 2 );
		return c.toDataURL( 'image/png' );
	}

	function build() {
		modal = el( 'div', { class: 'aagr-modal', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'aagr-title', hidden: '' } );
		modal.innerHTML =
			'<div class="aagr-modal__dialog">' +
			'<div class="aagr-modal__head"><div><h2 id="aagr-title"></h2><span class="aagr-modal__step"></span> <span class="aagr-modal__version"></span></div>' +
			'<button type="button" class="aagr-modal__close" aria-label="' + t.close + '">&times;</button></div>' +
			'<div class="aagr-modal__body"><div class="aagr-modal__doc" tabindex="0"></div>' +
			'<label class="aagr-modal__field">' + t.name + '<input type="text" name="aagr-name" autocomplete="name" maxlength="120" /></label>' +
			'<div class="aagr-tabs" role="tablist"><button type="button" class="aagr-tab" data-tab="draw" role="tab" aria-selected="true">' + t.draw + '</button>' +
			'<button type="button" class="aagr-tab" data-tab="generate" role="tab" aria-selected="false">' + t.generate + '</button></div>' +
			'<div class="aagr-panel" data-panel="draw"><div class="aagr-pad-wrap"><canvas class="aagr-pad"></canvas><span class="aagr-pad-line"></span>' +
			'<button type="button" class="aagr-pad-clear">' + t.clear + '</button></div></div>' +
			'<div class="aagr-panel" data-panel="generate" hidden><div class="aagr-fonts"></div></div></div>' +
			'<div class="aagr-modal__foot"><label class="aagr-modal__consent"><input type="checkbox" name="aagr-consent" /> <span>' + t.consent + '</span></label>' +
			'<div class="aagr-modal__error" role="alert" hidden></div>' +
			'<button type="button" class="aagr-modal__sign" disabled>' + t.sign + '</button></div></div>';
		document.body.appendChild( modal );

		const fontsBox = modal.querySelector( '.aagr-fonts' );
		cfg.fonts.forEach( ( f ) => {
			const b = el( 'button', { type: 'button', class: 'aagr-font-choice', 'data-font': f.key, 'aria-pressed': 'false', style: 'font-family:"' + f.family + '"' } );
			fontsBox.appendChild( b );
		} );

		modal.addEventListener( 'click', ( e ) => {
			const tab = e.target.closest( '.aagr-tab' );
			if ( tab ) return selectTab( tab.dataset.tab );
			const font = e.target.closest( '.aagr-font-choice' );
			if ( font ) {
				state.font = font.dataset.font;
				modal.querySelectorAll( '.aagr-font-choice' ).forEach( ( b ) => b.setAttribute( 'aria-pressed', String( b === font ) ) );
				return validate();
			}
			if ( e.target.closest( '.aagr-pad-clear' ) ) return pad.clear();
			if ( e.target.closest( '.aagr-modal__close' ) || e.target === modal ) return close();
			if ( e.target.closest( '.aagr-modal__sign' ) ) return submit();
		} );
		modal.addEventListener( 'input', ( e ) => {
			if ( e.target.name === 'aagr-name' ) {
				modal.querySelectorAll( '.aagr-font-choice' ).forEach( ( b ) => { b.textContent = e.target.value; } );
			}
			validate();
		} );
		modal.addEventListener( 'change', validate );
		document.addEventListener( 'keydown', ( e ) => { if ( e.key === 'Escape' && ! modal.hidden ) close(); } );
		pad = new Pad( modal.querySelector( '.aagr-pad' ), validate );
	}

	function selectTab( name ) {
		state.method = name;
		modal.querySelectorAll( '.aagr-tab' ).forEach( ( b ) => b.setAttribute( 'aria-selected', String( b.dataset.tab === name ) ) );
		modal.querySelectorAll( '.aagr-panel' ).forEach( ( p ) => { p.hidden = p.dataset.panel !== name; } );
		if ( name === 'draw' ) pad.size();
		validate();
	}

	function nameValue() {
		return modal.querySelector( 'input[name="aagr-name"]' ).value.trim();
	}

	function validate() {
		const hasSig = state.method === 'draw' ? ! pad.isEmpty() : !! state.font;
		const ok = nameValue() !== '' && hasSig && modal.querySelector( 'input[name="aagr-consent"]' ).checked;
		modal.querySelector( '.aagr-modal__sign' ).disabled = ! ok;
	}

	function show() {
		const doc = queue[ index ];
		modal.querySelector( '#aagr-title' ).textContent = doc.dataset.title;
		modal.querySelector( '.aagr-modal__step' ).textContent = queue.length > 1 ? t.step.replace( '%1$d', index + 1 ).replace( '%2$d', queue.length ) : '';
		modal.querySelector( '.aagr-modal__version' ).textContent = t.version.replace( '%s', doc.dataset.versionDate );
		modal.querySelector( '.aagr-modal__doc' ).innerHTML = doc.innerHTML; // server-side wp_kses_post'd
		modal.querySelector( '.aagr-modal__doc' ).scrollTop = 0;
		modal.querySelector( 'input[name="aagr-consent"]' ).checked = false;
		modal.querySelector( '.aagr-modal__error' ).hidden = true;
		state.font = '';
		modal.querySelectorAll( '.aagr-font-choice' ).forEach( ( b ) => b.setAttribute( 'aria-pressed', 'false' ) );
		pad.clear();
		selectTab( state.method );
	}

	function open() {
		const docs = Array.from( document.querySelectorAll( '.aagr-checkout template.aagr-doc' ) );
		queue = docs.filter( ( d ) => d.dataset.signed !== '1' );
		if ( ! queue.length ) queue = docs; // all signed: open read-only review of the first
		if ( ! queue.length ) return;
		if ( ! modal ) build();
		const nameInput = modal.querySelector( 'input[name="aagr-name"]' );
		if ( ! nameInput.value ) {
			const f = ( $( '#billing_first_name' ).val() || '' ).trim(), l = ( $( '#billing_last_name' ).val() || '' ).trim();
			nameInput.value = ( f + ' ' + l ).trim();
		}
		modal.querySelectorAll( '.aagr-font-choice' ).forEach( ( b ) => { b.textContent = nameInput.value; } );
		index = 0;
		modal.hidden = false;
		document.documentElement.classList.add( 'aagr-lock' );
		show();
		modal.querySelector( '.aagr-modal__close' ).focus();
	}

	function close() {
		modal.hidden = true;
		document.documentElement.classList.remove( 'aagr-lock' );
	}

	async function submit() {
		const btn = modal.querySelector( '.aagr-modal__sign' ), err = modal.querySelector( '.aagr-modal__error' );
		const doc = queue[ index ], family = ( cfg.fonts.find( ( f ) => f.key === state.font ) || {} ).family;
		btn.disabled = true;
		if ( state.method === 'generate' ) await document.fonts.load( '60px "' + family + '"' );
		const body = new FormData();
		body.append( 'nonce', cfg.nonce );
		body.append( 'agreement_id', doc.dataset.agreementId );
		body.append( 'name', nameValue() );
		body.append( 'method', state.method );
		body.append( 'font', state.method === 'generate' ? state.font : '' );
		body.append( 'image', state.method === 'draw' ? pad.toDataURL() : renderGenerated( nameValue(), family ) );
		body.append( 'consent', '1' );
		try {
			const res = await fetch( cfg.endpoint, { method: 'POST', body, credentials: 'same-origin' } ).then( ( r ) => r.json() );
			if ( ! res.ok ) throw new Error( res.error || 'error' );
			doc.dataset.signed = '1';
			if ( index < queue.length - 1 ) {
				index++;
				show();
			} else {
				close();
				$( document.body ).trigger( 'update_checkout' );
			}
		} catch ( e ) {
			err.textContent = t.error;
			err.hidden = false;
			btn.disabled = false;
		}
	}

	$( document ).on( 'click', '.aagr-open, .aagr-checkout__label', ( e ) => { e.preventDefault(); open(); } );
	$( document.body ).on( 'checkout_error', () => {
		if ( document.querySelector( '.aagr-checkout[data-complete="0"]' ) ) open();
	} );
} )( jQuery );
```

- [ ] **Step 6: Seed for e2e.** Append to `bin/e2e-seed.sh`:

```bash
AAGR_DOC=$(wp post create --post_type=anchor_agreement --post_status=publish --post_title="Cancellation Policy" --post_content="<p>Cancellations within 30 days forfeit the deposit.</p>" --porcelain)
wp option update anchor_agreements_settings "{\"default_agreement_id\":${AAGR_DOC}}" --format=json
AAGR_PRODUCT=$(wp wc product create --name="Agreement Test" --regular_price=10 --virtual=true --user=1 --porcelain)
wp post meta update "$AAGR_PRODUCT" _anchor_agreement_required yes
echo "AAGR_PRODUCT_ID=$AAGR_PRODUCT"
```

Also make sure the seed enables the module: `'agreements' => true` in `anchor_schema_settings` `modules`, and a payment method for checkout (e.g. `wp option update woocommerce_cod_settings '{"enabled":"yes"}' --format=json`).

- [ ] **Step 7: Run e2e and verify it passes**

Run: `AAGR_PRODUCT_ID=<id> npx playwright test e2e/agreements-checkout.spec.js`
Expected: PASS (4 tests: 2 per device).

- [ ] **Step 8: Manual mobile check (real phone, not just emulation).** Open the checkout on an iPhone and an Android phone and confirm:
  - drawing doesn't scroll the page;
  - the line is sharp, not blurry;
  - the Sign button stays visible above the keyboard while typing the name;
  - the Generate fonts load;
  - rotating the phone keeps the drawn strokes.

- [ ] **Step 9: Commit**

```bash
git add anchor-agreements/assets e2e/agreements-checkout.spec.js bin/e2e-seed.sh
git commit -m "feat(agreements): mobile-first signing modal with draw and generated signatures"
```

---

### Task 9: Signed copy page, email block, account list

**Files:**
- Create: `anchor-agreements/src/Frontend/SignedCopyPage.php`, `anchor-agreements/src/Frontend/CustomerViews.php`, `anchor-agreements/templates/signed-copy.php`, `anchor-agreements/assets/signed-copy.css`
- Modify: `anchor-agreements/anchor-agreements.php`
- Test: `tests/test-agreements-customer-views.php`

**Interfaces:**
- Consumes: `SignatureRepository::find_by_token()|for_order()|for_signer()`, `VersionRepository::get()`.
- Produces:
  - `SignedCopyPage::QUERY_VAR = 'anchor_agreement_token'`, `SignedCopyPage::url(string $token): string` (`home_url('/signed-agreement/{token}/')`), `SignedCopyPage::headers(): array<string,string>`, `SignedCopyPage::render_html(?array $signature): string`.
  - `CustomerViews::list_html(array $rows): string`, `CustomerViews::email_block(\WC_Order $order, bool $sent_to_admin, bool $plain_text): void`, shortcode `[anchor_signed_agreements]`, account endpoint `signed-documents`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/test-agreements-customer-views.php
use Anchor\Agreements\Content\AgreementPostType;
use Anchor\Agreements\Database\SignatureRepository;
use Anchor\Agreements\Database\VersionRepository;
use Anchor\Agreements\Frontend\CustomerViews;
use Anchor\Agreements\Frontend\SignedCopyPage;

class Test_Agreements_Customer_Views extends WP_UnitTestCase {
	const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

	private function signed( int $order_id, string $name = 'Pari <b>Example</b>' ): array {
		$aid  = self::factory()->post->create( [ 'post_type' => AgreementPostType::CPT, 'post_status' => 'publish', 'post_title' => 'Cancellation Policy', 'post_content' => '<p>Original terms.</p>' ] );
		$v    = ( new VersionRepository() )->current_for( $aid );
		$repo = new SignatureRepository();
		$id   = $repo->insert( [ 'version_id' => $v['id'], 'agreement_id' => $aid, 'product_id' => 1, 'user_id' => 1, 'signer_name' => $name, 'signer_email' => 'p@example.com', 'method' => 'draw', 'font' => null, 'image' => base64_decode( self::PNG ), 'ip' => '1.1.1.1', 'user_agent' => 'x', 'session_key' => 's' ] );
		$repo->attach( $id, $order_id );
		wp_update_post( [ 'ID' => $aid, 'post_content' => '<p>EDITED LATER.</p>' ] );
		return $repo->get( $id );
	}

	public function test_signed_copy_shows_version_as_signed_not_current() {
		$sig  = $this->signed( 42 );
		$html = SignedCopyPage::render_html( $sig );
		$this->assertStringContainsString( 'Original terms.', $html );
		$this->assertStringNotContainsString( 'EDITED LATER', $html );
		$this->assertStringContainsString( 'data:image/png;base64,', $html );
		$this->assertStringContainsString( '#42', $html );
		$this->assertStringNotContainsString( '<b>Example</b>', $html );
	}

	public function test_unknown_token_renders_not_found_and_headers_are_identical() {
		$this->assertStringContainsString( 'aagr-copy--missing', SignedCopyPage::render_html( null ) );
		$h = SignedCopyPage::headers();
		$this->assertSame( 'noindex, nofollow', $h['X-Robots-Tag'] );
		$this->assertStringContainsString( 'no-store', $h['Cache-Control'] );
	}

	public function test_url_shape() {
		$this->assertStringEndsWith( '/signed-agreement/abc/', SignedCopyPage::url( 'abc' ) );
	}

	public function test_email_block_only_for_customer_emails() {
		$order = wc_create_order();
		$sig   = $this->signed( $order->get_id() );
		ob_start();
		CustomerViews::email_block( $order, true, false );
		$this->assertSame( '', ob_get_clean() );
		ob_start();
		CustomerViews::email_block( $order, false, false );
		$this->assertStringContainsString( SignedCopyPage::url( $sig['token'] ), ob_get_clean() );
		update_option( \Anchor\Agreements\Support\Settings::OPTION, [ 'customer_email_links' => false ] );
		ob_start();
		CustomerViews::email_block( $order, false, false );
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_shortcode_lists_for_logged_in_user_only() {
		$this->signed( 7 );
		wp_set_current_user( 0 );
		$this->assertStringNotContainsString( 'Cancellation Policy', do_shortcode( '[anchor_signed_agreements]' ) );
		wp_set_current_user( 1 );
		$this->assertStringContainsString( 'Cancellation Policy', do_shortcode( '[anchor_signed_agreements]' ) );
	}
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter Test_Agreements_Customer_Views`
Expected: FAIL with `Class "Anchor\Agreements\Frontend\SignedCopyPage" not found`.

- [ ] **Step 3: Implement `SignedCopyPage`**

```php
<?php
declare(strict_types=1);

namespace Anchor\Agreements\Frontend;

use Anchor\Agreements\Database\SignatureRepository;
use Anchor\Agreements\Database\VersionRepository;
use Anchor\Agreements\Module;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * /signed-agreement/{token}/ - reachable by unguessable UUID only (same model as
 * anchor-courses CertificatePage). Found and not-found responses carry identical headers.
 */
final class SignedCopyPage {

	public const QUERY_VAR       = 'anchor_agreement_token';
	public const REWRITE_OPTION  = 'anchor_agreements_rewrite_version';
	public const REWRITE_VERSION = '1';

	public function __construct() {
		\add_action( 'init', [ $this, 'add_rewrite' ] );
		\add_filter( 'query_vars', fn( array $v ) => array_merge( $v, [ self::QUERY_VAR ] ) );
		\add_action( 'init', [ $this, 'flush_if_needed' ], 99 );
		\add_action( 'template_redirect', [ $this, 'maybe_render' ], 1 );
	}

	public function add_rewrite(): void {
		\add_rewrite_rule( '^signed-agreement/([0-9a-f-]{36})/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
		\add_rewrite_endpoint( 'signed-documents', EP_ROOT | EP_PAGES );
	}

	public function flush_if_needed(): void {
		if ( \get_option( self::REWRITE_OPTION ) !== self::REWRITE_VERSION ) {
			\flush_rewrite_rules( false );
			\update_option( self::REWRITE_OPTION, self::REWRITE_VERSION, false );
		}
	}

	public static function url( string $token ): string {
		return \home_url( '/signed-agreement/' . rawurlencode( $token ) . '/' );
	}

	public static function headers(): array {
		return [ 'X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'no-store, private, max-age=0', 'Referrer-Policy' => 'no-referrer' ];
	}

	public function maybe_render(): void {
		$token = (string) \get_query_var( self::QUERY_VAR );
		if ( '' === $token ) {
			return;
		}
		\nocache_headers();
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // WP Rocket.
		}
		foreach ( self::headers() as $k => $v ) {
			header( $k . ': ' . $v );
		}
		$sig = ( new SignatureRepository() )->find_by_token( $token );
		if ( ! $sig || null === $sig['order_id'] ) {
			$sig = null;
			\status_header( 404 );
		}
		echo self::render_html( $sig ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in template.
		exit;
	}

	public static function render_html( ?array $sig ): string {
		$version = $sig ? ( new VersionRepository() )->get( (int) $sig['version_id'] ) : null;
		$css     = Module::url( 'assets/signed-copy.css' );
		ob_start();
		include Module::dir() . '/templates/signed-copy.php';
		return (string) ob_get_clean();
	}
}
```

- [ ] **Step 4: Write `templates/signed-copy.php`**

```php
<?php
/** @var array|null $sig  @var array|null $version  @var string $css */
if ( ! defined( 'ABSPATH' ) ) { exit; }
$tz = wp_timezone();
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="robots" content="noindex, nofollow" />
<title><?php echo esc_html( $version ? $version['title'] : __( 'Signed agreement', 'anchor-schema' ) ); ?> — <?php bloginfo( 'name' ); ?></title>
<link rel="stylesheet" href="<?php echo esc_url( $css ); ?>" />
</head>
<body>
<?php if ( ! $sig || ! $version ) : ?>
	<main class="aagr-copy aagr-copy--missing"><h1><?php esc_html_e( 'Signed agreement not found', 'anchor-schema' ); ?></h1></main>
<?php else : ?>
	<main class="aagr-copy">
		<p class="aagr-copy__site"><?php bloginfo( 'name' ); ?></p>
		<h1><?php echo esc_html( $version['title'] ); ?></h1>
		<p class="aagr-copy__meta">
			<?php
			/* translators: 1: version date */
			printf( esc_html__( 'Version of %s', 'anchor-schema' ), esc_html( wp_date( get_option( 'date_format' ), strtotime( $version['created_at'] . ' UTC' ), $tz ) ) );
			?>
		</p>
		<div class="aagr-copy__doc"><?php echo wp_kses_post( wpautop( $version['content'] ) ); ?></div>
		<section class="aagr-copy__sig">
			<img src="data:image/png;base64,<?php echo esc_attr( base64_encode( $sig['image'] ) ); ?>" alt="<?php esc_attr_e( 'Signature', 'anchor-schema' ); ?>" />
			<dl>
				<dt><?php esc_html_e( 'Signed by', 'anchor-schema' ); ?></dt><dd><?php echo esc_html( $sig['signer_name'] ); ?></dd>
				<dt><?php esc_html_e( 'Date', 'anchor-schema' ); ?></dt><dd><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) . ' T', strtotime( $sig['signed_at'] . ' UTC' ), $tz ) ); ?></dd>
				<dt><?php esc_html_e( 'Order', 'anchor-schema' ); ?></dt><dd>#<?php echo esc_html( (string) $sig['order_id'] ); ?></dd>
				<dt><?php esc_html_e( 'Signature ID', 'anchor-schema' ); ?></dt><dd><?php echo esc_html( strtoupper( substr( $sig['token'], 0, 8 ) ) ); ?></dd>
			</dl>
		</section>
		<button type="button" class="aagr-copy__print" onclick="window.print()"><?php esc_html_e( 'Print / Save as PDF', 'anchor-schema' ); ?></button>
	</main>
<?php endif; ?>
</body>
</html>
```

`assets/signed-copy.css`:
```css
body { margin: 0; background: #f2f4f7; font: 16px/1.6 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; color: #101828; }
.aagr-copy { max-width: 760px; margin: 24px auto; background: #fff; padding: 32px; border-radius: 10px; }
.aagr-copy__site { margin: 0; color: #667085; font-size: .9em; }
.aagr-copy h1 { margin: .2em 0; }
.aagr-copy__meta { color: #667085; font-size: .9em; }
.aagr-copy__doc { border-top: 1px solid #eaecf0; border-bottom: 1px solid #eaecf0; padding: 12px 0; margin: 16px 0; }
.aagr-copy__sig img { max-width: 320px; width: 100%; height: auto; border-bottom: 1px solid #101828; }
.aagr-copy__sig dl { display: grid; grid-template-columns: max-content 1fr; gap: 4px 16px; }
.aagr-copy__sig dt { color: #667085; }
.aagr-copy__sig dd { margin: 0; }
.aagr-copy__print { margin-top: 20px; padding: 12px 18px; border: 0; border-radius: 8px; background: #101828; color: #fff; font-size: 1em; cursor: pointer; }
@media (max-width: 640px) { .aagr-copy { margin: 0; border-radius: 0; padding: 20px 16px; } }
@media print { body { background: #fff; } .aagr-copy { margin: 0; padding: 0; } .aagr-copy__print { display: none; } }
```

- [ ] **Step 5: Implement `CustomerViews`**

```php
<?php
declare(strict_types=1);

namespace Anchor\Agreements\Frontend;

use Anchor\Agreements\Database\SignatureRepository;
use Anchor\Agreements\Database\VersionRepository;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class CustomerViews {

	public function __construct() {
		\add_action( 'woocommerce_email_after_order_table', [ self::class, 'email_block' ], 20, 3 );
		\add_shortcode( 'anchor_signed_agreements', [ self::class, 'shortcode' ] );
		\add_filter( 'woocommerce_account_menu_items', [ self::class, 'menu_item' ] );
		\add_action( 'woocommerce_account_signed-documents_endpoint', [ self::class, 'endpoint' ] );
	}

	private static function title_for( array $row ): string {
		$v = ( new VersionRepository() )->get( (int) $row['version_id'] );
		return $v ? $v['title'] : \__( 'Agreement', 'anchor-schema' );
	}

	public static function email_block( $order, $sent_to_admin, $plain_text ): void {
		if ( $sent_to_admin || ! $order instanceof \WC_Order || ! \Anchor\Agreements\Support\Settings::customer_email_links() ) {
			return;
		}
		$rows = ( new SignatureRepository() )->for_order( $order->get_id() );
		if ( ! $rows ) {
			return;
		}
		if ( $plain_text ) {
			echo "\n" . \esc_html__( 'Your signed agreements:', 'anchor-schema' ) . "\n";
			foreach ( $rows as $r ) {
				echo \esc_html( self::title_for( $r ) ) . ': ' . \esc_url_raw( SignedCopyPage::url( $r['token'] ) ) . "\n";
			}
			return;
		}
		echo '<h2>' . \esc_html__( 'Your signed agreements', 'anchor-schema' ) . '</h2><ul>';
		foreach ( $rows as $r ) {
			printf( '<li>%s (%s) &rarr; <a href="%s">%s</a></li>',
				\esc_html( self::title_for( $r ) ),
				/* translators: %s: date */
				\esc_html( sprintf( \__( 'signed %s', 'anchor-schema' ), \wp_date( \get_option( 'date_format' ), strtotime( $r['signed_at'] . ' UTC' ) ) ) ),
				\esc_url( SignedCopyPage::url( $r['token'] ) ),
				\esc_html__( 'View', 'anchor-schema' )
			);
		}
		echo '</ul>';
	}

	public static function list_html( array $rows ): string {
		if ( ! $rows ) {
			return '<p class="aagr-list aagr-list--empty">' . \esc_html__( 'You have no signed documents yet.', 'anchor-schema' ) . '</p>';
		}
		$out = '<table class="aagr-list shop_table"><thead><tr><th>' . \esc_html__( 'Document', 'anchor-schema' ) . '</th><th>' . \esc_html__( 'Signed', 'anchor-schema' ) . '</th><th>' . \esc_html__( 'Order', 'anchor-schema' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$out .= sprintf( '<tr><td>%s</td><td>%s</td><td>#%d</td><td><a href="%s">%s</a></td></tr>',
				\esc_html( self::title_for( $r ) ),
				\esc_html( \wp_date( \get_option( 'date_format' ), strtotime( $r['signed_at'] . ' UTC' ) ) ),
				(int) $r['order_id'],
				\esc_url( SignedCopyPage::url( $r['token'] ) ),
				\esc_html__( 'View', 'anchor-schema' )
			);
		}
		return $out . '</tbody></table>';
	}

	private static function current_rows(): array {
		$user = \wp_get_current_user();
		return $user->ID ? ( new SignatureRepository() )->for_signer( $user->ID, (string) $user->user_email ) : [];
	}

	public static function shortcode(): string {
		return \is_user_logged_in() ? self::list_html( self::current_rows() ) : '';
	}

	public static function menu_item( array $items ): array {
		$logout = $items['customer-logout'] ?? null;
		unset( $items['customer-logout'] );
		$items['signed-documents'] = \__( 'Signed documents', 'anchor-schema' );
		if ( $logout ) {
			$items['customer-logout'] = $logout;
		}
		return $items;
	}

	public static function endpoint(): void {
		echo self::list_html( self::current_rows() ); // phpcs:ignore -- escaped in list_html.
	}
}
```

- [ ] **Step 6: Wire it.** In `Module::__construct()`: `new Frontend\SignedCopyPage(); new Frontend\CustomerViews();`

- [ ] **Step 7: Run the tests and verify they pass**

Run: `vendor/bin/phpunit --filter Test_Agreements_Customer_Views`
Expected: PASS (5 tests).

- [ ] **Step 8: Commit**

```bash
git add anchor-agreements tests/test-agreements-customer-views.php
git commit -m "feat(agreements): signed copy page, email links and account list"
```

---

### Task 10: Admin: settings, order metabox, signatures list, CSV, staff email, cleanup

**Files:**
- Create: `anchor-agreements/src/Admin/SettingsPage.php`, `anchor-agreements/src/Admin/OrderMetabox.php`, `anchor-agreements/src/Admin/SignaturesPage.php`, `anchor-agreements/src/Services/Notifier.php`, `anchor-agreements/src/Services/Cleanup.php`
- Modify: `anchor-agreements/anchor-agreements.php`
- Test: `tests/test-agreements-admin.php`

**Interfaces:**
- Consumes: `SignatureRepository::for_order()|search()|purge_unattached()`, `VersionRepository::get()`, `Settings::notify_enabled()|notify_recipients()|notify_subject()|purge_days()|sanitize()|DEFAULTS`, `SignedCopyPage::url()`, the `anchor_agreements_attached` action from Task 7.
- Produces:
  - `Notifier::maybe_send(int $order_id): bool` (hooked to `woocommerce_order_status_processing|completed|on-hold`; sends once and sets order meta `_anchor_agreements_notified=yes`), `Notifier::subject(\WC_Order $order, array $rows): string`.
  - `SignaturesPage::csv_rows(array $args): array<int,string[]>` (header row first).
  - `Cleanup::HOOK = 'anchor_agreements_cleanup'`, `Cleanup::run(): int`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/test-agreements-admin.php
use Anchor\Agreements\Admin\SignaturesPage;
use Anchor\Agreements\Content\AgreementPostType;
use Anchor\Agreements\Database\SignatureRepository;
use Anchor\Agreements\Database\VersionRepository;
use Anchor\Agreements\Services\Cleanup;
use Anchor\Agreements\Services\Notifier;

class Test_Agreements_Admin extends WP_UnitTestCase {
	const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

	private function signed_order(): WC_Order {
		$order = wc_create_order();
		$order->set_billing_first_name( 'Pari' );
		$order->set_billing_last_name( 'Example' );
		$order->save();
		$aid  = self::factory()->post->create( [ 'post_type' => AgreementPostType::CPT, 'post_status' => 'publish', 'post_title' => 'Cancellation Policy', 'post_content' => 'x' ] );
		$v    = ( new VersionRepository() )->current_for( $aid );
		$repo = new SignatureRepository();
		$id   = $repo->insert( [ 'version_id' => $v['id'], 'agreement_id' => $aid, 'product_id' => 1, 'user_id' => null, 'signer_name' => 'Pari Example', 'signer_email' => 'p@example.com', 'method' => 'generate', 'font' => 'allura', 'image' => base64_decode( self::PNG ), 'ip' => '1.1.1.1', 'user_agent' => 'x', 'session_key' => 's' ] );
		$repo->attach( $id, $order->get_id() );
		return $order;
	}

	public function test_notifier_sends_once_with_expected_subject() {
		reset_phpmailer_instance();
		$order = $this->signed_order();
		$this->assertTrue( Notifier::maybe_send( $order->get_id() ) );
		$this->assertFalse( Notifier::maybe_send( $order->get_id() ) );
		$mail = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertSame( 'Signed: Cancellation Policy – Pari Example, order #' . $order->get_id(), $mail->subject );
	}

	public function test_notifier_respects_settings() {
		$order = $this->signed_order();
		update_option( \Anchor\Agreements\Support\Settings::OPTION, [ 'notify_enabled' => false ] );
		$this->assertFalse( Notifier::maybe_send( $order->get_id() ) );
		update_option( \Anchor\Agreements\Support\Settings::OPTION, [ 'notify' => 'staff@example.com', 'notify_subject' => 'Agreement signed by {name}' ] );
		reset_phpmailer_instance();
		$this->assertTrue( Notifier::maybe_send( $order->get_id() ) );
		$mail = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertSame( 'Agreement signed by Pari Example', $mail->subject );
		$this->assertSame( 'staff@example.com', $mail->to[0][0] );
	}

	public function test_notifier_skips_orders_without_signatures() {
		$order = wc_create_order();
		$this->assertFalse( Notifier::maybe_send( $order->get_id() ) );
	}

	public function test_csv_has_header_and_no_image() {
		$this->signed_order();
		$rows = SignaturesPage::csv_rows( [] );
		$this->assertSame( [ 'Signature ID', 'Document', 'Version date', 'Signer', 'Email', 'Method', 'Font', 'Order', 'Signed (UTC)', 'IP', 'User agent' ], $rows[0] );
		$this->assertSame( 'Pari Example', $rows[1][3] );
		$this->assertCount( 11, $rows[1] );
	}

	public function test_csv_neutralises_formula_injection() {
		$order = wc_create_order();
		$aid   = self::factory()->post->create( [ 'post_type' => AgreementPostType::CPT, 'post_status' => 'publish', 'post_title' => 'P', 'post_content' => 'x' ] );
		$v     = ( new VersionRepository() )->current_for( $aid );
		$repo  = new SignatureRepository();
		$id    = $repo->insert( [ 'version_id' => $v['id'], 'agreement_id' => $aid, 'product_id' => 1, 'user_id' => null, 'signer_name' => '=HYPERLINK("x")', 'signer_email' => '', 'method' => 'draw', 'font' => null, 'image' => base64_decode( self::PNG ), 'ip' => '', 'user_agent' => '', 'session_key' => 's' ] );
		$repo->attach( $id, $order->get_id() );
		$this->assertSame( "'=HYPERLINK(\"x\")", SignaturesPage::csv_rows( [] )[1][3] );
	}

	public function test_cleanup_is_scheduled() {
		$this->assertNotFalse( wp_next_scheduled( Cleanup::HOOK ) );
	}
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter Test_Agreements_Admin`
Expected: FAIL with `Class "Anchor\Agreements\Services\Notifier" not found`.

- [ ] **Step 3: Implement `Notifier`**

```php
<?php
declare(strict_types=1);

namespace Anchor\Agreements\Services;

use Anchor\Agreements\Database\SignatureRepository;
use Anchor\Agreements\Database\VersionRepository;
use Anchor\Agreements\Frontend\SignedCopyPage;
use Anchor\Agreements\Support\Settings;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** One staff email per order, sent only once the order is paid/held (never for a failed attempt). */
final class Notifier {

	public function __construct() {
		foreach ( [ 'processing', 'completed', 'on-hold' ] as $status ) {
			\add_action( 'woocommerce_order_status_' . $status, [ self::class, 'maybe_send' ] );
		}
	}

	public static function subject( \WC_Order $order, array $rows ): string {
		$versions = new VersionRepository();
		$titles   = array_unique( array_map( fn( $r ) => ( $versions->get( (int) $r['version_id'] )['title'] ?? '' ), $rows ) );
		return Settings::notify_subject( implode( ', ', $titles ), (string) $rows[0]['signer_name'], $order->get_id() );
	}

	public static function maybe_send( $order_id ): bool {
		$order = \wc_get_order( (int) $order_id );
		if ( ! Settings::notify_enabled() || ! $order || 'yes' === $order->get_meta( '_anchor_agreements_notified' ) ) {
			return false;
		}
		$rows = ( new SignatureRepository() )->for_order( $order->get_id() );
		if ( ! $rows ) {
			return false;
		}
		$body = '<p>' . \esc_html( sprintf( 'Order #%d — %s', $order->get_id(), $order->get_formatted_billing_full_name() ) ) . '</p><ul>';
		foreach ( $rows as $r ) {
			$body .= sprintf( '<li>%s — %s (%s) · <a href="%s">View signed copy</a></li>',
				\esc_html( ( new VersionRepository() )->get( (int) $r['version_id'] )['title'] ?? '' ),
				\esc_html( $r['signer_name'] ),
				\esc_html( $r['method'] ),
				\esc_url( SignedCopyPage::url( $r['token'] ) )
			);
		}
		$body .= '</ul><p><a href="' . \esc_url( $order->get_edit_order_url() ) . '">Open order</a></p>';
		$subject = self::subject( $order, $rows );
		$html    = class_exists( '\Anchor_Email_Shell' ) ? \Anchor_Email_Shell::render( [ 'title' => $subject, 'preheader' => $subject, 'body' => $body ] ) : $body;
		$sent    = \wp_mail( Settings::notify_recipients(), $subject, $html, [ 'Content-Type: text/html; charset=UTF-8' ] );
		$order->update_meta_data( '_anchor_agreements_notified', 'yes' );
		$order->save_meta_data();
		return (bool) $sent;
	}
}
```

- [ ] **Step 4: Implement `Cleanup`**

```php
<?php
declare(strict_types=1);

namespace Anchor\Agreements\Services;

use Anchor\Agreements\Database\SignatureRepository;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class Cleanup {

	public const HOOK = 'anchor_agreements_cleanup';

	public function __construct() {
		\add_action( self::HOOK, [ self::class, 'run' ] );
		if ( ! \wp_next_scheduled( self::HOOK ) ) {
			\wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	public static function run(): int {
		return ( new SignatureRepository() )->purge_unattached( \Anchor\Agreements\Support\Settings::purge_days() * DAY_IN_SECONDS );
	}
}
```

Add to `anchor-tools.php`, beside the existing announcements module-off housekeeping: when `modules.agreements` flips from on to off, call `wp_clear_scheduled_hook( 'anchor_agreements_cleanup' )`. Use a literal hook name so no module code is needed while the module is off.

- [ ] **Step 5: Implement `SignaturesPage`** (list + CSV)

```php
<?php
declare(strict_types=1);

namespace Anchor\Agreements\Admin;

use Anchor\Agreements\Content\AgreementPostType;
use Anchor\Agreements\Database\SignatureRepository;
use Anchor\Agreements\Database\VersionRepository;
use Anchor\Agreements\Frontend\SignedCopyPage;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class SignaturesPage {

	public function __construct() {
		\add_action( 'admin_menu', [ $this, 'menu' ] );
		\add_action( 'admin_post_anchor_agreements_export', [ $this, 'export' ] );
	}

	public function menu(): void {
		\add_submenu_page( 'edit.php?post_type=' . AgreementPostType::CPT, \__( 'Signatures', 'anchor-schema' ), \__( 'Signatures', 'anchor-schema' ), 'manage_woocommerce', 'anchor-agreements-signatures', [ $this, 'render' ] );
	}

	private static function cell( string $v ): string {
		return preg_match( '/^[=+\-@\t\r]/', $v ) ? "'" . $v : $v;
	}

	public static function csv_rows( array $args ): array {
		$args     = array_merge( [ 'per_page' => 100000, 'paged' => 1 ], $args );
		$versions = new VersionRepository();
		$out      = [ [ 'Signature ID', 'Document', 'Version date', 'Signer', 'Email', 'Method', 'Font', 'Order', 'Signed (UTC)', 'IP', 'User agent' ] ];
		foreach ( ( new SignatureRepository() )->search( $args )['rows'] as $r ) {
			$v     = $versions->get( (int) $r['version_id'] );
			$out[] = array_map( [ self::class, 'cell' ], [
				strtoupper( substr( $r['token'], 0, 8 ) ), $v['title'] ?? '', $v['created_at'] ?? '', $r['signer_name'], $r['signer_email'],
				$r['method'], (string) $r['font'], (string) $r['order_id'], $r['signed_at'], $r['ip'], $r['user_agent'],
			] );
		}
		return $out;
	}

	public function export(): void {
		if ( ! \current_user_can( 'manage_woocommerce' ) || ! \check_admin_referer( 'anchor_agreements_export' ) ) {
			\wp_die( 'Forbidden', 403 );
		}
		\nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=signatures-' . gmdate( 'Y-m-d' ) . '.csv' );
		$fh = fopen( 'php://output', 'w' );
		foreach ( self::csv_rows( [ 's' => \sanitize_text_field( \wp_unslash( $_GET['s'] ?? '' ) ), 'agreement_id' => \absint( $_GET['agreement_id'] ?? 0 ) ] ) as $row ) {
			fputcsv( $fh, $row );
		}
		fclose( $fh );
		exit;
	}

	public function render(): void {
		$s      = \sanitize_text_field( \wp_unslash( $_GET['s'] ?? '' ) );
		$aid    = \absint( $_GET['agreement_id'] ?? 0 );
		$paged  = max( 1, \absint( $_GET['paged'] ?? 1 ) );
		$result = ( new SignatureRepository() )->search( [ 's' => $s, 'agreement_id' => $aid, 'paged' => $paged, 'per_page' => 50 ] );
		$export = \wp_nonce_url( \admin_url( 'admin-post.php?action=anchor_agreements_export&s=' . rawurlencode( $s ) . '&agreement_id=' . $aid ), 'anchor_agreements_export' );
		$versions = new VersionRepository();
		echo '<div class="wrap"><h1 class="wp-heading-inline">' . \esc_html__( 'Signatures', 'anchor-schema' ) . '</h1> <a class="page-title-action" href="' . \esc_url( $export ) . '">' . \esc_html__( 'Export CSV', 'anchor-schema' ) . '</a>';
		echo '<form method="get"><input type="hidden" name="post_type" value="' . \esc_attr( AgreementPostType::CPT ) . '" /><input type="hidden" name="page" value="anchor-agreements-signatures" />';
		echo '<p class="search-box"><input type="search" name="s" value="' . \esc_attr( $s ) . '" placeholder="' . \esc_attr__( 'Name, email or order #', 'anchor-schema' ) . '" /> ';
		echo '<select name="agreement_id"><option value="0">' . \esc_html__( 'All documents', 'anchor-schema' ) . '</option>';
		foreach ( \get_posts( [ 'post_type' => AgreementPostType::CPT, 'post_status' => 'any', 'numberposts' => -1 ] ) as $p ) {
			echo '<option value="' . (int) $p->ID . '"' . \selected( $aid, $p->ID, false ) . '>' . \esc_html( $p->post_title ) . '</option>';
		}
		echo '</select> <button class="button">' . \esc_html__( 'Filter', 'anchor-schema' ) . '</button></p></form>';
		echo '<table class="widefat striped"><thead><tr><th>' . \esc_html__( 'Signer', 'anchor-schema' ) . '</th><th>' . \esc_html__( 'Document', 'anchor-schema' ) . '</th><th>' . \esc_html__( 'Order', 'anchor-schema' ) . '</th><th>' . \esc_html__( 'Method', 'anchor-schema' ) . '</th><th>' . \esc_html__( 'Signed', 'anchor-schema' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $result['rows'] as $r ) {
			$order_link = $r['order_id'] ? '<a href="' . \esc_url( \wc_get_order( $r['order_id'] ) ? \wc_get_order( $r['order_id'] )->get_edit_order_url() : '#' ) . '">#' . (int) $r['order_id'] . '</a>' : '<em>' . \esc_html__( 'not ordered', 'anchor-schema' ) . '</em>';
			printf( '<tr><td>%s<br><small>%s</small></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				\esc_html( $r['signer_name'] ), \esc_html( $r['signer_email'] ),
				\esc_html( $versions->get( (int) $r['version_id'] )['title'] ?? '' ),
				$order_link, \esc_html( $r['method'] ),
				\esc_html( \wp_date( 'Y-m-d H:i', strtotime( $r['signed_at'] . ' UTC' ) ) ),
				$r['order_id'] ? '<a href="' . \esc_url( SignedCopyPage::url( $r['token'] ) ) . '" target="_blank">' . \esc_html__( 'View', 'anchor-schema' ) . '</a>' : ''
			);
		}
		echo '</tbody></table>';
		echo \paginate_links( [ 'base' => \add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $paged, 'total' => (int) ceil( $result['total'] / 50 ) ] ) ?: '';
		echo '</div>';
	}
}
```

- [ ] **Step 6: Implement `OrderMetabox` and `SettingsPage`**

```php
<?php
declare(strict_types=1);

namespace Anchor\Agreements\Admin;

use Anchor\Agreements\Database\SignatureRepository;
use Anchor\Agreements\Database\VersionRepository;
use Anchor\Agreements\Frontend\SignedCopyPage;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class OrderMetabox {

	public function __construct() {
		\add_action( 'add_meta_boxes', [ $this, 'register' ], 30 );
	}

	public function register(): void {
		$screen = \function_exists( 'wc_get_page_screen_id' ) ? \wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		\add_meta_box( 'anchor-agreements', \__( 'Signed agreements', 'anchor-schema' ), [ $this, 'render' ], $screen, 'side', 'default' );
	}

	public function render( $post_or_order ): void {
		$order = $post_or_order instanceof \WC_Order ? $post_or_order : \wc_get_order( $post_or_order->ID );
		$rows  = $order ? ( new SignatureRepository() )->for_order( $order->get_id() ) : [];
		if ( ! $rows ) {
			echo '<p>' . \esc_html__( 'No signed agreements on this order.', 'anchor-schema' ) . '</p>';
			return;
		}
		$versions = new VersionRepository();
		foreach ( $rows as $r ) {
			$v = $versions->get( (int) $r['version_id'] );
			printf( '<p><strong>%s</strong><br>%s · %s<br>%s · IP %s<br><a href="%s" target="_blank">%s</a></p>',
				\esc_html( $v['title'] ?? '' ),
				\esc_html( $r['signer_name'] ), \esc_html( $r['method'] ),
				\esc_html( \wp_date( 'Y-m-d H:i', strtotime( $r['signed_at'] . ' UTC' ) ) ), \esc_html( $r['ip'] ),
				\esc_url( SignedCopyPage::url( $r['token'] ) ), \esc_html__( 'View signed copy', 'anchor-schema' )
			);
		}
	}
}
```

```php
<?php
declare(strict_types=1);

namespace Anchor\Agreements\Admin;

use Anchor\Agreements\Content\AgreementPostType;
use Anchor\Agreements\Support\Settings;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class SettingsPage {

	public function __construct() {
		\add_action( 'admin_menu', [ $this, 'menu' ] );
		\add_action( 'admin_init', [ $this, 'register' ] );
	}

	public function menu(): void {
		\add_submenu_page( 'edit.php?post_type=' . AgreementPostType::CPT, \__( 'Agreement settings', 'anchor-schema' ), \__( 'Settings', 'anchor-schema' ), 'manage_woocommerce', 'anchor-agreements-settings', [ $this, 'render' ] );
	}

	public function register(): void {
		\register_setting( 'anchor_agreements', Settings::OPTION, [
			'type'              => 'array',
			'sanitize_callback' => [ Settings::class, 'sanitize' ],
		] );
	}

	private function row( string $label, string $field, string $help = '' ): void {
		echo '<tr><th scope="row">' . \esc_html( $label ) . '</th><td>' . $field; // $field is built escaped below.
		if ( '' !== $help ) {
			echo '<p class="description">' . \esc_html( $help ) . '</p>';
		}
		echo '</td></tr>';
	}

	private function text( string $key, string $value, string $placeholder = '', string $class = 'regular-text' ): string {
		return sprintf( '<input type="text" class="%s" name="%s[%s]" value="%s" placeholder="%s" />', \esc_attr( $class ), \esc_attr( Settings::OPTION ), \esc_attr( $key ), \esc_attr( $value ), \esc_attr( $placeholder ) );
	}

	private function number( string $key, int $value, int $min, int $max ): string {
		return sprintf( '<input type="number" class="small-text" min="%d" max="%d" name="%s[%s]" value="%d" />', $min, $max, \esc_attr( Settings::OPTION ), \esc_attr( $key ), $value );
	}

	private function toggle( string $key, bool $on, string $label ): string {
		return sprintf( '<label><input type="checkbox" name="%s[%s]" value="1" %s /> %s</label>', \esc_attr( Settings::OPTION ), \esc_attr( $key ), \checked( $on, true, false ), \esc_html( $label ) );
	}

	public function render(): void {
		$s   = Settings::get();
		$opt = '<option value="0">' . \esc_html__( '— None —', 'anchor-schema' ) . '</option>';
		foreach ( \get_posts( [ 'post_type' => AgreementPostType::CPT, 'post_status' => 'publish', 'numberposts' => -1 ] ) as $p ) {
			$opt .= '<option value="' . (int) $p->ID . '"' . \selected( (int) $s['default_agreement_id'], $p->ID, false ) . '>' . \esc_html( $p->post_title ) . '</option>';
		}
		echo '<div class="wrap"><h1>' . \esc_html__( 'Agreement settings', 'anchor-schema' ) . '</h1><form method="post" action="options.php">';
		\settings_fields( 'anchor_agreements' );

		echo '<h2>' . \esc_html__( 'Documents', 'anchor-schema' ) . '</h2><table class="form-table">';
		$this->row( \__( 'Default agreement', 'anchor-schema' ), '<select name="' . \esc_attr( Settings::OPTION ) . '[default_agreement_id]">' . $opt . '</select>', \__( 'Used by any product that requires a signature but does not pick its own document.', 'anchor-schema' ) );
		echo '</table>';

		echo '<h2>' . \esc_html__( 'Staff notifications', 'anchor-schema' ) . '</h2><table class="form-table">';
		$this->row( \__( 'Send "Signed" emails', 'anchor-schema' ), $this->toggle( 'notify_enabled', (bool) $s['notify_enabled'], \__( 'Email staff when a paid order has signed agreements', 'anchor-schema' ) ) );
		$this->row( \__( 'Recipients', 'anchor-schema' ), $this->text( 'notify', (string) $s['notify'], (string) \get_option( 'admin_email' ), 'large-text' ), \__( 'Comma-separated email addresses. Leave empty to use the site admin email.', 'anchor-schema' ) );
		$this->row( \__( 'Subject', 'anchor-schema' ), $this->text( 'notify_subject', (string) $s['notify_subject'], Settings::DEFAULTS['notify_subject'], 'large-text' ), \__( 'Placeholders: {documents}, {name}, {order}', 'anchor-schema' ) );
		echo '</table>';

		echo '<h2>' . \esc_html__( 'Customer', 'anchor-schema' ) . '</h2><table class="form-table">';
		$this->row( \__( 'Links in order emails', 'anchor-schema' ), $this->toggle( 'customer_email_links', (bool) $s['customer_email_links'], \__( 'Add "Your signed agreements" links to customer order emails', 'anchor-schema' ) ) );
		$this->row( \__( 'Checkout checkbox label', 'anchor-schema' ), $this->text( 'label', (string) $s['label'], 'I have read and signed the {title}', 'large-text' ), \__( 'Placeholder: {title}. Used when one agreement is required.', 'anchor-schema' ) );
		$this->row( \__( 'Consent sentence', 'anchor-schema' ), $this->text( 'consent_text', (string) $s['consent_text'], Settings::consent_text(), 'large-text' ), \__( 'Shown next to the box the signer must tick. Have your attorney approve any change.', 'anchor-schema' ) );
		echo '</table>';

		echo '<h2>' . \esc_html__( 'Timing', 'anchor-schema' ) . '</h2><table class="form-table">';
		$this->row( \__( 'Reuse a signature for (hours)', 'anchor-schema' ), $this->number( 'reuse_hours', (int) $s['reuse_hours'], 1, 168 ), \__( 'If a payment fails, the buyer does not have to sign again within this window.', 'anchor-schema' ) );
		$this->row( \__( 'Delete abandoned signatures after (days)', 'anchor-schema' ), $this->number( 'purge_days', (int) $s['purge_days'], 1, 365 ), \__( 'Signatures never attached to an order. Signatures on orders are kept forever.', 'anchor-schema' ) );
		echo '</table>';
		\submit_button();
		echo '</form></div>';
	}
}
```

- [ ] **Step 7: Wire it.** In `Module::__construct()`:

```php
		new Services\Notifier();
		new Services\Cleanup();
		if ( \is_admin() ) {
			new Admin\OrderMetabox();
			new Admin\SignaturesPage();
			new Admin\SettingsPage();
		}
```
(Merge with the `is_admin()` block from Task 5.)

- [ ] **Step 8: Run the whole suite**

Run: `vendor/bin/phpunit --filter Agreements && vendor/bin/phpunit -c phpunit-unit.xml.dist --filter Agreements`
Expected: PASS, all agreements tests.

- [ ] **Step 9: Commit**

```bash
git add anchor-agreements anchor-tools.php tests/test-agreements-admin.php
git commit -m "feat(agreements): admin settings, order metabox, signatures list/CSV, staff email, cleanup cron"
```

---

### Task 11: Docs, version bump, PR, TMJ rollout

**Files:**
- Create: `anchor-agreements/README.md`
- Modify: `anchor-tools.php` (`Version:` header + any version constant), `readme.txt` (changelog)

- [ ] **Step 1: Write `anchor-agreements/README.md`.** One page covering:
  - what the module does;
  - how to create an agreement and set the default;
  - the product fields;
  - the `[anchor_signed_agreements]` shortcode, with the note **"use this on sites whose My Account page has no `[woocommerce_my_account]` shortcode (e.g. TMJ)"**;
  - the RUCSS safelist `/aagr-/`;
  - that block checkout is refused;
  - the hooks: `anchor_agreements_attached`.

- [ ] **Step 2: Bump the plugin version** (minor bump, e.g. `3.34.1` → `3.35.0`) and add a `readme.txt` changelog line.

- [ ] **Step 3: Full test run.** `composer test` (or `vendor/bin/phpunit && vendor/bin/phpunit -c phpunit-unit.xml.dist`) and the e2e spec. Everything must be green.

- [ ] **Step 4: PR.** Count files first: `git diff --name-only origin/main...HEAD | wc -l` (must be well under 150). Check CodeRabbit capacity (no "Review paused" notice in the last hour), then:

```bash
git push origin feat/anchor-agreements
gh pr create --base main --title "feat: Anchor Agreements — signed agreements at checkout" --body "Implements docs/superpowers/specs/2026-10-01-anchor-agreements-design.md"
```

Confirm CodeRabbit posted real inline findings, not only the walkthrough. Get explicit human sign-off before merging.

- [ ] **Step 5: TMJ rollout** (after the release reaches the site; run over SSH on the Live environment):
  1. Enable the module (Anchor Tools settings → Agreements).
  2. Create the agreement from WP E-Signature doc 1:
     `wp db query "SELECT document_content FROM wp_esign_documents WHERE document_id=1"`. The content may be encrypted or encoded by WP E-Signature; if it is unreadable, copy the text from the WP E-Signature editor in wp-admin instead. Publish it as **Cancellation Policy** and set it as the default in Agreements → Settings.
  3. In **Agreements → Settings**, fill in the staff notification recipients (ask the client who should get "Signed:" emails) and review the other defaults.
  4. On products **1118014** and **1119079**: tick **Require signed agreement** and clear WP E-Signature's `_esig_woo_meta_product_agreement`, so buyers aren't asked to sign twice.
  5. Add `[anchor_signed_agreements]` to the logged-in account surface. `/my-account/` (page 8003) renders no WooCommerce endpoints; see the site's `references/theme-and-header.md`.
  6. Add `/aagr-/` to WP Rocket `remove_unused_css_safelist`. Checkout is `x-kinsta-cache: BYPASS`, but the signed-copy page and any account page aren't.
  7. Verify on the server with a real cart: cookie jar → `/?add-to-cart=<product>` → `/checkout/`, then grep for `aagr-checkout`. An empty-cart checkout redirects to `/cart/` and gives a false negative.
  8. Place one real order end to end on a low-price test product, then refund it. Confirm the signed copy link in the email, the order metabox, the "Signed:" email and the Signatures list.
  9. Leave WP E-Signature installed until its 83 records are exported. That's a separate decision.

- [ ] **Step 6: Commit docs + version**

```bash
git add anchor-agreements/README.md anchor-tools.php readme.txt
git commit -m "docs(agreements): module README; release 3.35.0"
```
