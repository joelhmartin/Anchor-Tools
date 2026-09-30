# Anchor Announcements Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A new Anchor Tools module that composes branded emails in wp-admin with the shared email builder, targets recipients with an AND/OR rule builder, sends through `wp_mail()` in background batches, and reports opens, clicks and unsubscribes per recipient on any mail provider.

**Architecture:** A provider-neutral email kit in `includes/email/` (shell, tokens, sanitizer, builder UI) is the reusable primitive. The `announcements` module (`Anchor\Announcements\`, PSR-4 under `anchor-announcements/src/`) owns a CPT for drafts, three custom tables (sends, events, suppressions), an audience resolver made of pluggable conditions, a WP-Cron queue, and query-var tracking endpoints.

**Tech Stack:** PHP 8.1+ (WordPress plugin, no build step), jQuery + vanilla JS (source files only, never `.min.*`), WordPress TinyMCE (`wp.editor`), the shared `Anchor_Monaco`, PHPUnit 9 on the WP test library, Playwright on wp-env.

**Spec:** `docs/superpowers/specs/2026-09-30-anchor-announcements-design.md` (read it before any task).

## Global Constraints

- Work only in the worktree `~/Developer/anchor-os/anchor-tools-wt-announcements` on branch `feat/anchor-announcements`. Never commit to or push `main`; push with `git push origin feat/anchor-announcements` only.
- No em dashes (U+2014) in code, comments, docs or commit messages. Check: `grep -rnP '\x{2014}' anchor-announcements includes/email assets/email-kit tests/*announce* docs/superpowers/plans/2026-09-30-anchor-announcements.md` returns nothing.
- Never commit `*.min.css`, `*.min.js`, `*.min.js.map` (CI builds them). Enqueue the source files through `Anchor_Asset_Loader::url()`.
- Text domain `anchor-schema` (the plugin's single domain).
- Every admin screen, AJAX handler and send action checks `current_user_can( 'anchor_send_announcements' )` and a nonce.
- Sending goes through `wp_mail()` only. No provider SDKs, no provider API calls.
- Custom tables are created through `dbDelta()` in `Database\Migrations::maybe_migrate()`, keyed on option `anchor_announcements_db_version`.
- Option `anchor_announcements_settings` is saved with `autoload = false`.
- Do not edit `anchor-events-manager/` (the events migration onto the kit is a separate PR, spec section 10).
- Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. **A recipient who is both a user and a guest buyer.** The same address as a WP user and on guest orders must produce one recipient (user data wins) and one email. Test in Task 5.
2. **A link with a query string or an HTML entity in its href** (`https://x.com/?a=1&amp;b=2`). The click redirect must land on the exact original URL, and `mailto:`/`tel:`/`#` hrefs must be untouched. Test in Task 8.
3. **The queue running twice at once** (two overlapping cron spawns). No recipient may get two copies (MySQL `GET_LOCK` is re-entrant for one connection, so each row is claimed individually). Test in Task 10.
4. **A token value containing HTML or quotes** (a display name like `Bob <b>"Jr"</b>`). It must render escaped in the body, raw in the subject. Test in Task 1.
5. **An announcement whose audience resolves to zero after suppression.** Send must refuse with a message, not create an empty "sent" record. Test in Task 10.

## File Map

Create:
- `includes/email/class-anchor-email-tokens.php`: `{token}` expansion with context-aware escaping, and the token registry the builder palette reads.
- `includes/email/class-anchor-email-sanitizer.php`: the email-safe `wp_kses()` allowlist (ported from events).
- `includes/email/class-anchor-email-shell.php`: the table-based branded layout (ported from events' default shell).
- `includes/email/class-anchor-email-kit.php`: builder markup and asset enqueue.
- `assets/email-kit/builder.js`, `assets/email-kit/builder.css`: the builder UI (subject, preheader, Design/HTML tabs, token palette, live preview).
- `anchor-announcements/anchor-announcements.php`: module bootstrap (`Anchor\Announcements\Module`).
- `anchor-announcements/src/Database/Migrations.php`: tables and capability.
- `anchor-announcements/src/Support/Settings.php`: settings option and defaults.
- `anchor-announcements/src/Content/AnnouncementPostType.php`: CPT and meta keys.
- `anchor-announcements/src/Audience/RecipientSet.php`, `Condition.php`, `Registry.php`, `Universe.php`, `Resolver.php`, `WooOrders.php`.
- `anchor-announcements/src/Audience/Conditions/UserRole.php`, `UserRegistered.php`, `UserField.php`, `SpecificPeople.php`, `WcPurchased.php`, `WcOrderCount.php`, `WcTotalSpent.php`, `CourseEnrolled.php`, `CourseCompleted.php`, `EventRegistered.php`.
- `anchor-announcements/src/Suppression/Suppressions.php`.
- `anchor-announcements/src/Tracking/Urls.php`, `LinkRewriter.php`, `Endpoints.php`.
- `anchor-announcements/src/Rendering/Renderer.php`.
- `anchor-announcements/src/Sending/Queue.php`, `Mailer.php`.
- `anchor-announcements/src/Admin/Editor.php`, `Ajax.php`, `Reports.php`, `SettingsPage.php`, `SuppressionsPage.php`, `ListColumns.php`.
- `anchor-announcements/src/Privacy/Privacy.php`.
- `anchor-announcements/assets/admin.js`, `admin.css` (audience builder, actions, reports).
- `anchor-announcements/templates/unsubscribe.php`.
- `anchor-announcements/ANNOUNCEMENTS.md`: module docs.
- `tests/class-anchor-announcements-testcase.php` and `tests/test-announcements-*.php`, `tests/test-email-kit.php`.
- `e2e/announcements.spec.js`.

Modify:
- `anchor-tools.php`: require the four kit classes after `class-anchor-monaco.php`; add the `announcements` registry entry after `courses`.
- `composer.json`: PSR-4 `"Anchor\\Announcements\\": "anchor-announcements/src/"`.
- `tests/bootstrap.php`: enable `announcements` in the module list.
- `CLAUDE.md`: one row in the module table.

---

### Task 0: Local test environment

**Files:** none committed.

- [ ] **Step 1: Start a throwaway MySQL (the same image CI uses)**

```bash
docker run -d --name anchor-tools-testdb -e MYSQL_ROOT_PASSWORD=root -p 33061:3306 mysql:8.0
until docker exec anchor-tools-testdb mysqladmin ping -uroot -proot --silent; do sleep 2; done
```

- [ ] **Step 2: Install PHP deps, the WP test library and WooCommerce**

```bash
cd ~/Developer/anchor-os/anchor-tools-wt-announcements
composer install --no-interaction
export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress
bash bin/install-wp-tests.sh wordpress_test root root 127.0.0.1:33061 latest
curl -sL https://downloads.wordpress.org/plugin/woocommerce.latest-stable.zip -o /tmp/woocommerce.zip
unzip -qo /tmp/woocommerce.zip -d "$WP_CORE_DIR/wp-content/plugins"
```

- [ ] **Step 3: Baseline: the existing suite passes before any change**

Run: `WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress vendor/bin/phpunit --filter 'courses' 2>&1 | tail -5`
Expected: `OK` (or only pre-existing skips). If anything fails here, stop and report it: it is not caused by this work.

Every later "Run" line assumes `export WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress` in the shell.

---

### Task 1: Email kit: tokens, sanitizer, shell

**Files:**
- Create: `includes/email/class-anchor-email-tokens.php`, `includes/email/class-anchor-email-sanitizer.php`, `includes/email/class-anchor-email-shell.php`
- Modify: `anchor-tools.php` (after the `class-anchor-monaco.php` require, line ~62)
- Test: `tests/test-email-kit.php`

**Interfaces:**
- Produces:
  - `Anchor_Email_Tokens::expand( string $template, array $tokens, bool $html = true ): string`
  - `Anchor_Email_Tokens::register( string $key, string $label, string $group ): void`, `Anchor_Email_Tokens::registered(): array` (`[ key => [ 'label' => , 'group' => ] ]`), `Anchor_Email_Tokens::reset(): void` (tests)
  - `Anchor_Email_Sanitizer::body( string $html ): string`, `Anchor_Email_Sanitizer::allowed_html(): array`
  - `Anchor_Email_Shell::render( array $args ): string` with keys `title`, `preheader`, `body`, `footer`, `brand_color`, `logo_url`

- [ ] **Step 1: Write the failing tests**

```php
<?php
/**
 * Email kit: tokens, sanitizer, shell.
 */
class Test_Email_Kit extends WP_UnitTestCase {

	public function test_expand_escapes_values_in_html_context() {
		$out = Anchor_Email_Tokens::expand( '<p>Hi {first_name}</p>', [ 'first_name' => 'Bob <b>"Jr"</b>' ] );
		$this->assertSame( '<p>Hi Bob &lt;b&gt;&quot;Jr&quot;&lt;/b&gt;</p>', $out );
	}

	public function test_expand_leaves_values_raw_in_text_context() {
		$out = Anchor_Email_Tokens::expand( 'Hi {first_name}', [ 'first_name' => 'Bob <b>"Jr"</b>' ], false );
		$this->assertSame( 'Hi Bob <b>"Jr"</b>', $out );
	}

	public function test_url_tokens_are_url_escaped_in_html() {
		$out = Anchor_Email_Tokens::expand( '<a href="{login_url}">x</a>', [ 'login_url' => 'javascript:alert(1)' ] );
		$this->assertStringNotContainsString( 'javascript:', $out );
	}

	public function test_unknown_tokens_are_left_alone() {
		$this->assertSame( 'Hi {nope}', Anchor_Email_Tokens::expand( 'Hi {nope}', [ 'first_name' => 'A' ] ) );
	}

	public function test_registry_round_trip() {
		Anchor_Email_Tokens::reset();
		Anchor_Email_Tokens::register( 'first_name', 'First name', 'Recipient' );
		$this->assertSame( [ 'first_name' => [ 'label' => 'First name', 'group' => 'Recipient' ] ], Anchor_Email_Tokens::registered() );
	}

	public function test_sanitizer_keeps_email_markup_and_strips_script() {
		$html = '<table role="presentation" style="width:100%"><tr><td style="color:red"><a href="https://x.com" style="color:blue">x</a><img src="https://x.com/a.png" alt="a" width="10"></td></tr></table><script>alert(1)</script><form><input></form>';
		$out  = Anchor_Email_Sanitizer::body( $html );
		$this->assertStringContainsString( '<table role="presentation" style="width:100%">', $out );
		$this->assertStringContainsString( '<a href="https://x.com" style="color:blue">', $out );
		$this->assertStringContainsString( '<img src="https://x.com/a.png" alt="a" width="10"', $out );
		$this->assertStringNotContainsString( '<script', $out );
		$this->assertStringNotContainsString( '<form', $out );
		$this->assertStringNotContainsString( '<input', $out );
	}

	public function test_sanitizer_keeps_token_placeholders_in_hrefs() {
		$out = Anchor_Email_Sanitizer::body( '<a href="{login_url}">Sign in</a>' );
		$this->assertStringContainsString( 'href="{login_url}"', $out );
	}

	public function test_shell_renders_parts() {
		$html = Anchor_Email_Shell::render(
			[
				'title'       => 'Hello',
				'preheader'   => 'Quick note',
				'body'        => '<p>Body text</p>',
				'footer'      => '<p>123 Main St</p>',
				'brand_color' => '#123456',
				'logo_url'    => 'https://x.com/logo.png',
			]
		);
		$this->assertStringContainsString( '<p>Body text</p>', $html );
		$this->assertStringContainsString( '123 Main St', $html );
		$this->assertStringContainsString( 'Quick note', $html );
		$this->assertStringContainsString( '#123456', $html );
		$this->assertStringContainsString( 'https://x.com/logo.png', $html );
		$this->assertStringContainsString( '</body>', $html );
	}

	public function test_shell_rejects_a_bad_brand_color() {
		$html = Anchor_Email_Shell::render( [ 'body' => 'x', 'brand_color' => 'red;background:url(x)' ] );
		$this->assertStringNotContainsString( 'url(x)', $html );
	}
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/phpunit --filter Test_Email_Kit`
Expected: FAIL, `Class "Anchor_Email_Tokens" not found`.

- [ ] **Step 3: Implement the tokens class**

`includes/email/class-anchor-email-tokens.php`:

```php
<?php
/**
 * {token} placeholders for Anchor Tools emails: expansion with context-aware escaping,
 * and the registry the email builder's token palette reads. One implementation for
 * every module that sends email (announcements today; events moves onto it later).
 *
 * @package AnchorTools
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Anchor_Email_Tokens {

	/** @var array<string,array{label:string,group:string}> */
	private static $registry = array();

	/**
	 * Replace {key} with $tokens[key]. In HTML context values are escaped: keys ending
	 * in `_url` with esc_url(), everything else with esc_html(). In text context (a
	 * subject line) values go in raw. Unknown placeholders are left untouched.
	 *
	 * @param string $template Template text.
	 * @param array  $tokens   [ key => value ].
	 * @param bool   $html     True for an HTML body, false for plain text.
	 * @return string
	 */
	public static function expand( $template, array $tokens, $html = true ) {
		$map = array();
		foreach ( $tokens as $key => $value ) {
			$value = (string) $value;
			if ( $html ) {
				$value = '_url' === substr( (string) $key, -4 ) ? esc_url( $value ) : esc_html( $value );
			}
			$map[ '{' . $key . '}' ] = $value;
		}
		return strtr( (string) $template, $map );
	}

	/**
	 * Add a token to the builder palette.
	 *
	 * @param string $key   Token name without braces.
	 * @param string $label Human label.
	 * @param string $group Palette group heading.
	 */
	public static function register( $key, $label, $group ) {
		self::$registry[ (string) $key ] = array( 'label' => (string) $label, 'group' => (string) $group );
	}

	/** @return array<string,array{label:string,group:string}> */
	public static function registered() {
		return self::$registry;
	}

	/** Empty the registry (tests). */
	public static function reset() {
		self::$registry = array();
	}
}
```

- [ ] **Step 4: Implement the sanitizer**

`includes/email/class-anchor-email-sanitizer.php`:

```php
<?php
/**
 * The email-safe wp_kses() allowlist: tables, inline styles, images and links, no
 * script, forms or embeds. Ported from the events module's template allowlist so
 * both modules accept the same markup.
 *
 * @package AnchorTools
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Anchor_Email_Sanitizer {

	/**
	 * Sanitize an email body. `{token}` placeholders survive, including inside href
	 * and src (wp_kses would otherwise drop a scheme-less "{login_url}" value).
	 *
	 * @param string $html Raw HTML.
	 * @return string
	 */
	public static function body( $html ) {
		$protocols = array_merge( wp_allowed_protocols(), array( 'data' ) );
		$html      = preg_replace_callback(
			'/\b(href|src)=(["\'])\{([a-z0-9_]+)\}\2/i',
			static function ( $m ) {
				return $m[1] . '=' . $m[2] . 'anchor-token://' . $m[3] . $m[2];
			},
			(string) $html
		);
		$clean     = wp_kses( $html, self::allowed_html(), array_merge( $protocols, array( 'anchor-token' ) ) );
		return preg_replace( '#anchor-token://([a-z0-9_]+)#i', '{$1}', $clean );
	}

	/** @return array wp_kses() allowed_html. */
	public static function allowed_html() {
		$allowed = array(
			'html'   => array(),
			'head'   => array(),
			'meta'   => array( 'charset' => true, 'name' => true, 'content' => true ),
			'title'  => array(),
			'body'   => array( 'style' => true ),
			'table'  => array( 'role' => true, 'width' => true, 'cellpadding' => true, 'cellspacing' => true, 'style' => true, 'align' => true, 'border' => true ),
			'thead'  => array(),
			'tbody'  => array(),
			'tr'     => array( 'style' => true ),
			'td'     => array( 'style' => true, 'align' => true, 'valign' => true, 'width' => true, 'colspan' => true ),
			'th'     => array( 'style' => true, 'align' => true, 'valign' => true, 'width' => true, 'colspan' => true ),
			'div'    => array( 'style' => true, 'class' => true, 'id' => true, 'align' => true ),
			'span'   => array( 'style' => true, 'class' => true, 'id' => true ),
			'p'      => array( 'style' => true, 'class' => true, 'align' => true ),
			'br'     => array(),
			'hr'     => array( 'style' => true ),
			'h1'     => array( 'style' => true ),
			'h2'     => array( 'style' => true ),
			'h3'     => array( 'style' => true ),
			'h4'     => array( 'style' => true ),
			'a'      => array( 'href' => true, 'style' => true, 'target' => true, 'rel' => true, 'class' => true, 'id' => true ),
			'img'    => array( 'src' => true, 'alt' => true, 'width' => true, 'height' => true, 'style' => true, 'class' => true ),
			'strong' => array(),
			'em'     => array(),
			'b'      => array(),
			'i'      => array(),
			'u'      => array(),
			'ul'     => array( 'style' => true ),
			'ol'     => array( 'style' => true ),
			'li'     => array( 'style' => true ),
			'blockquote' => array( 'style' => true ),
		);

		/**
		 * Filter the email body allowlist.
		 *
		 * @param array $allowed wp_kses() allowed_html.
		 */
		return (array) apply_filters( 'anchor_email_allowed_html', $allowed );
	}
}
```

- [ ] **Step 5: Implement the shell**

`includes/email/class-anchor-email-shell.php`:

```php
<?php
/**
 * The branded, table-based email layout every Anchor Tools email can share: a 600px
 * card on a tinted background, optional logo, body, footer. Ported from the events
 * module's default shell.
 *
 * @package AnchorTools
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Anchor_Email_Shell {

	/**
	 * @param array $args {
	 *     @type string $title       Document title.
	 *     @type string $preheader   Hidden inbox preview text.
	 *     @type string $body        Body HTML (already sanitized and token-expanded).
	 *     @type string $footer      Footer HTML (address, unsubscribe).
	 *     @type string $brand_color Hex color for the header rule and links.
	 *     @type string $logo_url    Optional logo URL.
	 * }
	 * @return string Full HTML document.
	 */
	public static function render( array $args ) {
		$args  = wp_parse_args(
			$args,
			array( 'title' => '', 'preheader' => '', 'body' => '', 'footer' => '', 'brand_color' => '#1a4f48', 'logo_url' => '' )
		);
		$color = sanitize_hex_color( (string) $args['brand_color'] );
		$color = $color ? $color : '#1a4f48';

		$preheader = '' !== $args['preheader']
			? '<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;">' . esc_html( $args['preheader'] ) . '</div>'
			: '';
		$logo      = '' !== $args['logo_url']
			? '<tr><td style="padding:24px 32px 0;"><img src="' . esc_url( $args['logo_url'] ) . '" alt="' . esc_attr( get_bloginfo( 'name' ) ) . '" style="max-width:200px;height:auto;border:0;" /></td></tr>'
			: '';

		return '<!DOCTYPE html><html><head><meta charset="UTF-8" /><meta name="viewport" content="width=device-width,initial-scale=1" /><title>' . esc_html( $args['title'] ) . '</title></head>'
			. '<body style="margin:0;padding:0;background:#f4f5f5;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;">'
			. $preheader
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f5;padding:24px 12px;"><tr><td align="center">'
			. '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:8px;overflow:hidden;border-top:4px solid ' . $color . ';">'
			. $logo
			. '<tr><td style="padding:24px 32px;font-size:16px;line-height:1.6;color:#1f2a28;">' . $args['body'] . '</td></tr>'
			. '<tr><td style="padding:16px 32px 24px;border-top:1px solid #eeeeee;font-size:12px;line-height:1.5;color:#777777;">' . $args['footer'] . '</td></tr>'
			. '</table></td></tr></table></body></html>';
	}
}
```

- [ ] **Step 6: Load the kit**

In `anchor-tools.php`, directly after the `includes/class-anchor-monaco.php` require block (line ~62), add:

```php
    require_once ANCHOR_TOOLS_PLUGIN_DIR . 'includes/email/class-anchor-email-tokens.php';
    require_once ANCHOR_TOOLS_PLUGIN_DIR . 'includes/email/class-anchor-email-sanitizer.php';
    require_once ANCHOR_TOOLS_PLUGIN_DIR . 'includes/email/class-anchor-email-shell.php';
```

(Match the surrounding block's guard style exactly; read lines 55 to 70 first.)

- [ ] **Step 7: Run the tests**

Run: `vendor/bin/phpunit --filter Test_Email_Kit`
Expected: PASS (9 tests).

- [ ] **Step 8: Commit**

```bash
git add includes/email anchor-tools.php tests/test-email-kit.php
git commit -m "Email kit: shared tokens, email-safe sanitizer and branded shell

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Email kit: the builder UI

**Files:**
- Create: `includes/email/class-anchor-email-kit.php`, `assets/email-kit/builder.js`, `assets/email-kit/builder.css`
- Modify: `anchor-tools.php` (one more require after Task 1's three)
- Test: `tests/test-email-kit.php` (append)

**Interfaces:**
- Consumes: `Anchor_Email_Tokens::registered()`, `Anchor_Monaco::enqueue( $cpt )`.
- Produces:
  - `Anchor_Email_Kit::builder_markup( array $args ): string`, args `id` (DOM id prefix), `name` (form field prefix, e.g. `anchor_announcement`), `subject`, `preheader`, `body`. Fields post as `{name}[subject]`, `{name}[preheader]`, `{name}[body]`.
  - `Anchor_Email_Kit::enqueue( array $config, string $cpt ): void`, config keys `ajaxUrl`, `nonce`, `previewAction` (AJAX action that receives `subject`, `preheader`, `body`, `post_id` and returns `{ success: true, data: { html: string } }`), `tokens` (the registry), `emptyTokens` (keys that can be empty for a guest).
  - DOM contract: root `.anchor-email-builder[data-anchor-email-builder]`; the preview `<iframe class="anchor-email-builder__frame">`.

- [ ] **Step 1: Append the failing tests**

```php
	public function test_builder_markup_carries_fields_and_tokens() {
		Anchor_Email_Tokens::reset();
		Anchor_Email_Tokens::register( 'first_name', 'First name', 'Recipient' );
		$html = Anchor_Email_Kit::builder_markup(
			[ 'id' => 'aa', 'name' => 'anchor_announcement', 'subject' => 'S & T', 'preheader' => 'P', 'body' => '<p>B</p>' ]
		);
		$this->assertStringContainsString( 'data-anchor-email-builder', $html );
		$this->assertStringContainsString( 'name="anchor_announcement[subject]" value="S &amp; T"', $html );
		$this->assertStringContainsString( 'name="anchor_announcement[preheader]"', $html );
		$this->assertStringContainsString( 'name="anchor_announcement[body]"', $html );
		$this->assertStringContainsString( '&lt;p&gt;B&lt;/p&gt;', $html ); // textarea content is escaped
		$this->assertStringContainsString( 'data-token="{first_name}"', $html );
		$this->assertStringContainsString( 'anchor-email-builder__frame', $html );
	}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit --filter test_builder_markup_carries_fields_and_tokens`
Expected: FAIL, `Class "Anchor_Email_Kit" not found`.

- [ ] **Step 3: Implement the PHP side**

`includes/email/class-anchor-email-kit.php`:

```php
<?php
/**
 * The email builder UI any module can mount: subject and preheader fields, a Design
 * tab (WordPress's TinyMCE) and an HTML tab (the shared Monaco editor) over one body
 * textarea, a token palette, and a live preview the consuming module renders through
 * its own AJAX action (so the preview is always that module's real renderer).
 *
 * @package AnchorTools
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Anchor_Email_Kit {

	const VERSION = '1.0.0';

	/**
	 * @param array $args id, name, subject, preheader, body.
	 * @return string
	 */
	public static function builder_markup( array $args ) {
		$args = wp_parse_args( $args, array( 'id' => 'anchor-email', 'name' => 'anchor_email', 'subject' => '', 'preheader' => '', 'body' => '' ) );
		$id   = sanitize_html_class( $args['id'] );
		$name = preg_replace( '/[^a-z0-9_\-]/i', '', (string) $args['name'] );

		$groups = array();
		foreach ( Anchor_Email_Tokens::registered() as $key => $token ) {
			$groups[ $token['group'] ][ $key ] = $token['label'];
		}
		$palette = '';
		foreach ( $groups as $group => $tokens ) {
			$palette .= '<div class="anchor-email-builder__token-group"><span class="anchor-email-builder__token-heading">' . esc_html( $group ) . '</span>';
			foreach ( $tokens as $key => $label ) {
				$palette .= '<button type="button" class="button button-small anchor-email-builder__token" data-token="{' . esc_attr( $key ) . '}" title="{' . esc_attr( $key ) . '}">' . esc_html( $label ) . '</button>';
			}
			$palette .= '</div>';
		}

		ob_start();
		?>
		<div class="anchor-email-builder" data-anchor-email-builder id="<?php echo esc_attr( $id ); ?>">
			<div class="anchor-email-builder__edit">
				<p><label for="<?php echo esc_attr( $id ); ?>-subject"><strong><?php esc_html_e( 'Subject', 'anchor-schema' ); ?></strong></label>
				<input type="text" class="widefat anchor-email-builder__subject" id="<?php echo esc_attr( $id ); ?>-subject" name="<?php echo esc_attr( $name ); ?>[subject]" value="<?php echo esc_attr( $args['subject'] ); ?>" /></p>
				<p><label for="<?php echo esc_attr( $id ); ?>-preheader"><strong><?php esc_html_e( 'Preview text', 'anchor-schema' ); ?></strong> <span class="description"><?php esc_html_e( 'Shown after the subject in most inboxes.', 'anchor-schema' ); ?></span></label>
				<input type="text" class="widefat anchor-email-builder__preheader" id="<?php echo esc_attr( $id ); ?>-preheader" name="<?php echo esc_attr( $name ); ?>[preheader]" value="<?php echo esc_attr( $args['preheader'] ); ?>" /></p>
				<div class="anchor-email-builder__tabs" role="tablist">
					<button type="button" class="anchor-email-builder__tab is-active" data-view="design" role="tab" aria-selected="true"><?php esc_html_e( 'Design', 'anchor-schema' ); ?></button>
					<button type="button" class="anchor-email-builder__tab" data-view="html" role="tab" aria-selected="false"><?php esc_html_e( 'HTML', 'anchor-schema' ); ?></button>
				</div>
				<div class="anchor-email-builder__palette"><?php echo $palette; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?></div>
				<div class="anchor-email-builder__design">
					<textarea class="anchor-email-builder__body" id="<?php echo esc_attr( $id ); ?>-body" name="<?php echo esc_attr( $name ); ?>[body]" rows="18"><?php echo esc_textarea( $args['body'] ); ?></textarea>
				</div>
				<div class="anchor-email-builder__html" hidden>
					<div class="anchor-monaco" data-anchor-monaco='[{"id":"<?php echo esc_attr( $id ); ?>-source","label":"HTML","lang":"html"}]'>
						<textarea id="<?php echo esc_attr( $id ); ?>-source" class="anchor-email-builder__source" rows="18"></textarea>
					</div>
				</div>
			</div>
			<div class="anchor-email-builder__preview">
				<div class="anchor-email-builder__preview-head">
					<strong><?php esc_html_e( 'Preview', 'anchor-schema' ); ?></strong>
					<span class="anchor-email-builder__status" aria-live="polite"></span>
				</div>
				<iframe class="anchor-email-builder__frame" title="<?php esc_attr_e( 'Email preview', 'anchor-schema' ); ?>" sandbox=""></iframe>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Enqueue the builder on the current admin screen (caller guards the screen).
	 *
	 * @param array  $config ajaxUrl, nonce, previewAction, tokens, emptyTokens.
	 * @param string $cpt    Post type, for Anchor_Monaco.
	 */
	public static function enqueue( array $config, $cpt ) {
		Anchor_Monaco::enqueue( $cpt );
		wp_enqueue_editor();
		wp_enqueue_style( 'anchor-email-kit', Anchor_Asset_Loader::url( 'assets/email-kit/builder.css' ), array(), self::VERSION );
		wp_enqueue_script( 'anchor-email-kit', Anchor_Asset_Loader::url( 'assets/email-kit/builder.js' ), array( 'jquery', 'wp-editor' ), self::VERSION, true );
		wp_localize_script( 'anchor-email-kit', 'ANCHOR_EMAIL_KIT', $config );
	}
}
```

Add the require in `anchor-tools.php` after the Task 1 requires:

```php
    require_once ANCHOR_TOOLS_PLUGIN_DIR . 'includes/email/class-anchor-email-kit.php';
```

- [ ] **Step 4: Implement the JS**

`assets/email-kit/builder.js`:

```js
/**
 * Anchor email kit builder. One body textarea is the source of truth; the Design tab
 * edits it through WordPress's TinyMCE, the HTML tab through the shared Monaco glue
 * (anchor-monaco.js keeps its own textarea in sync and fires `input`). Switching tabs
 * copies the body across. The preview is the consuming module's own AJAX renderer,
 * so what it shows is what will be sent.
 */
(function ($) {
  'use strict';

  var cfg = window.ANCHOR_EMAIL_KIT || {};

  function init(root) {
    var body      = root.querySelector('.anchor-email-builder__body');
    var source    = root.querySelector('.anchor-email-builder__source');
    var subject   = root.querySelector('.anchor-email-builder__subject');
    var preheader = root.querySelector('.anchor-email-builder__preheader');
    var frame     = root.querySelector('.anchor-email-builder__frame');
    var status    = root.querySelector('.anchor-email-builder__status');
    var view      = 'design';
    var timer     = null;
    var request   = 0;

    wp.editor.initialize(body.id, {
      tinymce: {
        wpautop: false,
        plugins: 'lists,link,paste,textcolor,colorpicker,hr,wordpress,wplink',
        toolbar1: 'formatselect,bold,italic,underline,forecolor,bullist,numlist,alignleft,aligncenter,link,unlink,hr,undo,redo',
        setup: function (ed) { ed.on('change keyup SetContent', schedule); }
      },
      quicktags: false,
      mediaButtons: true
    });

    function editor() { return window.tinymce ? window.tinymce.get(body.id) : null; }

    function currentBody() {
      if (view === 'html') { return monacoValue(); }
      var ed = editor();
      return ed ? ed.getContent() : body.value;
    }

    function monacoValue() {
      var ed = monacoEditor();
      return ed ? ed.getValue() : source.value;
    }

    function monacoEditor() {
      if (!window.monaco || !window.monaco.editor || !window.monaco.editor.getEditors) { return null; }
      var wrap = source.closest('.anchor-monaco');
      var found = null;
      window.monaco.editor.getEditors().forEach(function (e) {
        if (wrap && wrap.contains(e.getDomNode())) { found = e; }
      });
      return found;
    }

    function setView(next) {
      if (next === view) { return; }
      var html = currentBody();
      view = next;
      root.querySelectorAll('.anchor-email-builder__tab').forEach(function (tab) {
        var on = tab.getAttribute('data-view') === view;
        tab.classList.toggle('is-active', on);
        tab.setAttribute('aria-selected', on ? 'true' : 'false');
      });
      root.querySelector('.anchor-email-builder__design').hidden = view !== 'design';
      root.querySelector('.anchor-email-builder__html').hidden = view !== 'html';
      if (view === 'html') {
        source.value = html;
        var m = monacoEditor();
        if (m) { m.setValue(html); m.layout(); }
      } else {
        var ed = editor();
        if (ed) { ed.setContent(html); } else { body.value = html; }
      }
      body.value = html;
    }

    function insertToken(token) {
      if (view === 'html') {
        var m = monacoEditor();
        if (m) {
          m.executeEdits('anchor-email-kit', [{ range: m.getSelection(), text: token, forceMoveMarkers: true }]);
          m.focus();
        } else {
          source.value += token;
        }
      } else {
        var ed = editor();
        if (ed) { ed.execCommand('mceInsertContent', false, token); } else { body.value += token; }
      }
      schedule();
    }

    function schedule() {
      clearTimeout(timer);
      timer = setTimeout(refresh, 500);
    }

    function refresh() {
      body.value = currentBody();
      var mine = ++request;
      status.textContent = '…';
      var data = new FormData();
      data.set('action', cfg.previewAction || '');
      data.set('nonce', cfg.nonce || '');
      data.set('post_id', (document.getElementById('post_ID') || {}).value || '0');
      data.set('subject', subject.value);
      data.set('preheader', preheader.value);
      data.set('body', body.value);
      fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (mine !== request) { return; }
          if (res && res.success) {
            frame.srcdoc = res.data.html;
            status.textContent = '';
          } else {
            status.textContent = (res && res.data && res.data.message) || 'Preview failed';
          }
        })
        .catch(function () { if (mine === request) { status.textContent = 'Preview failed'; } });
    }

    root.querySelectorAll('.anchor-email-builder__tab').forEach(function (tab) {
      tab.addEventListener('click', function () { setView(tab.getAttribute('data-view')); });
    });
    root.querySelectorAll('.anchor-email-builder__token').forEach(function (btn) {
      btn.addEventListener('click', function () { insertToken(btn.getAttribute('data-token')); });
    });
    [subject, preheader].forEach(function (el) { el.addEventListener('input', schedule); });
    source.addEventListener('input', schedule);

    // The form submits the body textarea: make sure it holds the active view's HTML.
    var form = root.closest('form');
    if (form) { form.addEventListener('submit', function () { body.value = currentBody(); }); }

    refresh();
  }

  $(function () {
    document.querySelectorAll('[data-anchor-email-builder]').forEach(init);
  });
})(jQuery);
```

- [ ] **Step 5: Implement the CSS**

`assets/email-kit/builder.css`:

```css
.anchor-email-builder{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:20px;align-items:start}
@media (max-width:1100px){.anchor-email-builder{grid-template-columns:minmax(0,1fr)}}
.anchor-email-builder__tabs{display:flex;gap:4px;margin:12px 0 0;border-bottom:1px solid #c3c4c7}
.anchor-email-builder__tab{background:none;border:1px solid transparent;border-bottom:0;padding:6px 14px;cursor:pointer;margin-bottom:-1px;border-radius:3px 3px 0 0}
.anchor-email-builder__tab.is-active{background:#fff;border-color:#c3c4c7}
.anchor-email-builder__palette{display:flex;flex-wrap:wrap;gap:10px 16px;padding:10px 0}
.anchor-email-builder__token-group{display:flex;flex-wrap:wrap;align-items:center;gap:4px}
.anchor-email-builder__token-heading{font-weight:600;margin-right:4px;color:#50575e}
.anchor-email-builder__html .anchor-monaco{min-height:420px}
.anchor-email-builder__preview{position:sticky;top:40px}
.anchor-email-builder__preview-head{display:flex;justify-content:space-between;margin-bottom:6px}
.anchor-email-builder__frame{width:100%;height:640px;border:1px solid #c3c4c7;background:#f4f5f5;border-radius:4px}
```

- [ ] **Step 6: Run the tests**

Run: `vendor/bin/phpunit --filter Test_Email_Kit`
Expected: PASS (10 tests). The JS is exercised by the Playwright spec in Task 15.

- [ ] **Step 7: Commit**

```bash
git add includes/email/class-anchor-email-kit.php assets/email-kit anchor-tools.php tests/test-email-kit.php
git commit -m "Email kit: the builder UI (Design and HTML tabs, token palette, live preview)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Module skeleton: registry, tables, capability, CPT, settings

**Files:**
- Create: `anchor-announcements/anchor-announcements.php`, `anchor-announcements/src/Database/Migrations.php`, `anchor-announcements/src/Support/Settings.php`, `anchor-announcements/src/Content/AnnouncementPostType.php`, `anchor-announcements/src/Admin/SettingsPage.php`, `tests/class-anchor-announcements-testcase.php`
- Modify: `anchor-tools.php` (registry, after the `courses` entry at line ~311), `composer.json` (PSR-4), `tests/bootstrap.php` (enable `announcements`)
- Test: `tests/test-announcements-module.php`

**Interfaces:**
- Produces:
  - `Anchor\Announcements\Module::instance(): ?Module`, constants `Module::VERSION = '1.0.0'`, `Module::CAP = 'anchor_send_announcements'`.
  - `Database\Migrations::maybe_migrate(): void`, `Database\Migrations::table( string $name ): string` for `sends`, `events`, `suppressions`.
  - `Support\Settings::get(): array` keys `from_name`, `from_email`, `reply_to`, `footer_address`, `brand_color`, `logo_url`, `batch_size` (int); `Support\Settings::OPTION = 'anchor_announcements_settings'`; `Support\Settings::save( array $raw ): array`.
  - `Content\AnnouncementPostType::CPT = 'anchor_announcement'` and meta key constants `META_SUBJECT = '_aa_subject'`, `META_PREHEADER = '_aa_preheader'`, `META_BODY = '_aa_body'`, `META_AUDIENCE = '_aa_audience'`, `META_STATE = '_aa_state'`, `META_SCHEDULED = '_aa_scheduled_at'`, `META_LINKS = '_aa_links'`, `META_SENT_AT = '_aa_sent_at'`; states `STATE_DRAFT = 'draft'`, `STATE_SCHEDULED = 'scheduled'`, `STATE_SENDING = 'sending'`, `STATE_PAUSED = 'paused'`, `STATE_SENT = 'sent'`, `STATE_CANCELLED = 'cancelled'`; `AnnouncementPostType::state( int $id ): string`.
  - `Anchor_Announcements_TestCase` with `module()`, `make_announcement( array $meta = [] ): int`, `make_user( string $email, array $args = [] ): int`.

- [ ] **Step 1: Wire the test bootstrap and write the failing tests**

In `tests/bootstrap.php`, add `'announcements' => true` to the `modules` array in the `plugins_loaded` priority-1 filter.

`tests/class-anchor-announcements-testcase.php`:

```php
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
```

`tests/test-announcements-module.php`:

```php
<?php
use Anchor\Announcements\Content\AnnouncementPostType as PT;
use Anchor\Announcements\Database\Migrations;
use Anchor\Announcements\Module;
use Anchor\Announcements\Support\Settings;

class Test_Announcements_Module extends Anchor_Announcements_TestCase {

	public function test_module_boots() {
		$this->assertInstanceOf( Module::class, $this->module() );
	}

	public function test_tables_exist() {
		global $wpdb;
		foreach ( [ 'sends', 'events', 'suppressions' ] as $t ) {
			$name = Migrations::table( $t );
			$this->assertSame( $name, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ) );
		}
	}

	public function test_sends_table_has_unique_announcement_email() {
		global $wpdb;
		$t = Migrations::table( 'sends' );
		$wpdb->insert( $t, [ 'announcement_id' => 1, 'email' => 'a@x.com', 'token' => str_repeat( 'a', 32 ), 'status' => 'queued', 'queued_at' => current_time( 'mysql', true ) ] );
		$wpdb->suppress_errors( true );
		$ok = $wpdb->insert( $t, [ 'announcement_id' => 1, 'email' => 'a@x.com', 'token' => str_repeat( 'b', 32 ), 'status' => 'queued', 'queued_at' => current_time( 'mysql', true ) ] );
		$wpdb->suppress_errors( false );
		$this->assertFalse( $ok );
	}

	public function test_administrator_has_the_capability_and_editor_does_not() {
		$this->assertTrue( get_role( 'administrator' )->has_cap( Module::CAP ) );
		$this->assertFalse( get_role( 'editor' )->has_cap( Module::CAP ) );
	}

	public function test_cpt_is_registered_private_with_the_capability() {
		$pt = get_post_type_object( PT::CPT );
		$this->assertNotNull( $pt );
		$this->assertFalse( $pt->public );
		$this->assertSame( Module::CAP, $pt->cap->edit_posts );
	}

	public function test_state_defaults_to_draft() {
		$id = self::factory()->post->create( [ 'post_type' => PT::CPT ] );
		$this->assertSame( PT::STATE_DRAFT, PT::state( $id ) );
	}

	public function test_settings_defaults_and_clamping() {
		delete_option( Settings::OPTION );
		$s = Settings::get();
		$this->assertSame( 50, $s['batch_size'] );
		$this->assertSame( get_bloginfo( 'name' ), $s['from_name'] );
		$saved = Settings::save( [ 'batch_size' => '99999', 'from_email' => 'not an email', 'brand_color' => 'blue', 'footer_address' => "1 Main St\nTown" ] );
		$this->assertSame( 500, $saved['batch_size'] );
		$this->assertSame( '', $saved['from_email'] );
		$this->assertSame( '#1a4f48', $saved['brand_color'] );
		$this->assertSame( "1 Main St\nTown", $saved['footer_address'] );
		global $wpdb;
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Settings::OPTION ) );
		$this->assertContains( $autoload, [ 'no', 'off' ] ); // 'off' on WP 6.6+
	}
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/phpunit --filter Test_Announcements_Module`
Expected: FAIL, class `Anchor_Announcements_TestCase` loads but `Module` is not found / module did not boot.

- [ ] **Step 3: Register the module and the autoloader**

`composer.json` `autoload.psr-4`: add `"Anchor\\Announcements\\": "anchor-announcements/src/"`, then run `composer dump-autoload`.

`anchor-tools.php`, inside `anchor_tools_get_available_modules()` after the `courses` entry:

```php
            'announcements' => [
                'label'       => __( 'Anchor Announcements', 'anchor-schema' ),
                'description' => __( 'Compose and send tracked email announcements to filtered audiences.', 'anchor-schema' ),
                'path'        => ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-announcements/anchor-announcements.php',
                'class'       => '\\Anchor\\Announcements\\Module',
            ],
```

- [ ] **Step 4: Implement Migrations**

`anchor-announcements/src/Database/Migrations.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Announcements\Database;

use Anchor\Announcements\Module;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** Tables and the send capability, created or upgraded when the stored version is behind. */
final class Migrations {

	public const VERSION = '1';
	public const OPTION  = 'anchor_announcements_db_version';

	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'anchor_announce_' . $name;
	}

	public static function maybe_migrate(): void {
		if ( self::VERSION === (string) \get_option( self::OPTION ) ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		\dbDelta(
			'CREATE TABLE ' . self::table( 'sends' ) . " (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				announcement_id BIGINT UNSIGNED NOT NULL,
				email VARCHAR(191) NOT NULL,
				user_id BIGINT UNSIGNED NULL,
				name VARCHAR(191) NOT NULL DEFAULT '',
				token CHAR(32) NOT NULL,
				status VARCHAR(20) NOT NULL,
				skip_reason VARCHAR(40) NOT NULL DEFAULT '',
				error TEXT NULL,
				attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
				queued_at DATETIME NOT NULL,
				claimed_at DATETIME NULL,
				sent_at DATETIME NULL,
				first_opened_at DATETIME NULL,
				open_count INT UNSIGNED NOT NULL DEFAULT 0,
				first_clicked_at DATETIME NULL,
				click_count INT UNSIGNED NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY announcement_email (announcement_id,email),
				UNIQUE KEY token (token),
				KEY status_queued (status,queued_at)
			) $charset;"
		);
		\dbDelta(
			'CREATE TABLE ' . self::table( 'events' ) . " (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				send_id BIGINT UNSIGNED NOT NULL,
				type VARCHAR(20) NOT NULL,
				link_index SMALLINT UNSIGNED NULL,
				scanner TINYINT(1) NOT NULL DEFAULT 0,
				user_agent VARCHAR(255) NOT NULL DEFAULT '',
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY send_type (send_id,type)
			) $charset;"
		);
		\dbDelta(
			'CREATE TABLE ' . self::table( 'suppressions' ) . " (
				email VARCHAR(191) NOT NULL,
				reason VARCHAR(20) NOT NULL,
				source_announcement_id BIGINT UNSIGNED NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (email)
			) $charset;"
		);

		$admin = \get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( Module::CAP );
		}
		\update_option( self::OPTION, self::VERSION, false );
	}
}
```

- [ ] **Step 5: Implement Settings**

`anchor-announcements/src/Support/Settings.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Announcements\Support;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** The module's one option: sender identity, required footer address, brand, batch size. */
final class Settings {

	public const OPTION = 'anchor_announcements_settings';

	public static function defaults(): array {
		return [
			'from_name'      => (string) \get_bloginfo( 'name' ),
			'from_email'     => '',
			'reply_to'       => '',
			'footer_address' => '',
			'brand_color'    => '#1a4f48',
			'logo_url'       => '',
			'batch_size'     => 50,
		];
	}

	public static function get(): array {
		$stored = \get_option( self::OPTION, [] );
		return \array_merge( self::defaults(), \is_array( $stored ) ? $stored : [] );
	}

	public static function save( array $raw ): array {
		$color = \sanitize_hex_color( (string) ( $raw['brand_color'] ?? '' ) );
		$clean = [
			'from_name'      => \sanitize_text_field( (string) ( $raw['from_name'] ?? '' ) ),
			'from_email'     => (string) \sanitize_email( (string) ( $raw['from_email'] ?? '' ) ),
			'reply_to'       => (string) \sanitize_email( (string) ( $raw['reply_to'] ?? '' ) ),
			'footer_address' => \sanitize_textarea_field( (string) ( $raw['footer_address'] ?? '' ) ),
			'brand_color'    => $color ? $color : '#1a4f48',
			'logo_url'       => \esc_url_raw( (string) ( $raw['logo_url'] ?? '' ) ),
			'batch_size'     => \max( 1, \min( 500, (int) ( $raw['batch_size'] ?? 50 ) ) ),
		];
		if ( '' === $clean['from_name'] ) {
			$clean['from_name'] = (string) \get_bloginfo( 'name' );
		}
		\update_option( self::OPTION, $clean, false );
		return $clean;
	}
}
```

- [ ] **Step 6: Implement the CPT**

`anchor-announcements/src/Content/AnnouncementPostType.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Announcements\Content;

use Anchor\Announcements\Module;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** The announcement post: the draft while composing, the record once sent. */
final class AnnouncementPostType {

	public const CPT = 'anchor_announcement';

	public const META_SUBJECT   = '_aa_subject';
	public const META_PREHEADER = '_aa_preheader';
	public const META_BODY      = '_aa_body';
	public const META_AUDIENCE  = '_aa_audience';
	public const META_STATE     = '_aa_state';
	public const META_SCHEDULED = '_aa_scheduled_at';
	public const META_LINKS     = '_aa_links';
	public const META_SENT_AT   = '_aa_sent_at';

	public const STATE_DRAFT     = 'draft';
	public const STATE_SCHEDULED = 'scheduled';
	public const STATE_SENDING   = 'sending';
	public const STATE_PAUSED    = 'paused';
	public const STATE_SENT      = 'sent';
	public const STATE_CANCELLED = 'cancelled';

	public static function register(): void {
		$cap = Module::CAP;
		\register_post_type(
			self::CPT,
			[
				'labels'          => [
					'name'          => \__( 'Announcements', 'anchor-schema' ),
					'singular_name' => \__( 'Announcement', 'anchor-schema' ),
					'add_new_item'  => \__( 'New announcement', 'anchor-schema' ),
					'edit_item'     => \__( 'Edit announcement', 'anchor-schema' ),
					'menu_name'     => \__( 'Announcements', 'anchor-schema' ),
				],
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => true,
				'menu_icon'       => 'dashicons-megaphone',
				'menu_position'   => 26,
				'supports'        => [ 'title' ],
				'map_meta_cap'    => false,
				'capabilities'    => [
					'edit_post'          => $cap,
					'read_post'          => $cap,
					'delete_post'        => $cap,
					'edit_posts'         => $cap,
					'edit_others_posts'  => $cap,
					'publish_posts'      => $cap,
					'read_private_posts' => $cap,
					'delete_posts'       => $cap,
					'create_posts'       => $cap,
				],
			]
		);
	}

	public static function state( int $id ): string {
		$state = (string) \get_post_meta( $id, self::META_STATE, true );
		return '' !== $state ? $state : self::STATE_DRAFT;
	}
}
```

- [ ] **Step 7: Implement the settings page**

`anchor-announcements/src/Admin/SettingsPage.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Announcements\Admin;

use Anchor\Announcements\Content\AnnouncementPostType as PT;
use Anchor\Announcements\Module;
use Anchor\Announcements\Support\Settings;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** Announcements > Settings. */
final class SettingsPage {

	public const SLUG = 'anchor-announcements-settings';

	public function __construct() {
		\add_action( 'admin_menu', [ $this, 'menu' ] );
		\add_action( 'admin_post_anchor_announcements_settings', [ $this, 'save' ] );
	}

	public function menu(): void {
		\add_submenu_page( 'edit.php?post_type=' . PT::CPT, \__( 'Announcement settings', 'anchor-schema' ), \__( 'Settings', 'anchor-schema' ), Module::CAP, self::SLUG, [ $this, 'render' ] );
	}

	public function save(): void {
		if ( ! \current_user_can( Module::CAP ) ) {
			\wp_die( \esc_html__( 'You are not allowed to do that.', 'anchor-schema' ), 403 );
		}
		\check_admin_referer( 'anchor_announcements_settings' );
		Settings::save( (array) \wp_unslash( $_POST['settings'] ?? [] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Settings::save sanitizes each key.
		\wp_safe_redirect( \add_query_arg( [ 'post_type' => PT::CPT, 'page' => self::SLUG, 'updated' => 1 ], \admin_url( 'edit.php' ) ) );
		exit;
	}

	public function render(): void {
		$s = Settings::get();
		$fields = [
			'from_name'   => [ \__( 'From name', 'anchor-schema' ), 'text', '' ],
			'from_email'  => [ \__( 'From email', 'anchor-schema' ), 'email', \__( 'Leave empty to use the site default. Must be an address your mail provider can send as.', 'anchor-schema' ) ],
			'reply_to'    => [ \__( 'Reply-to', 'anchor-schema' ), 'email', '' ],
			'brand_color' => [ \__( 'Brand color', 'anchor-schema' ), 'text', \__( 'Hex, e.g. #1a4f48.', 'anchor-schema' ) ],
			'logo_url'    => [ \__( 'Logo URL', 'anchor-schema' ), 'url', '' ],
			'batch_size'  => [ \__( 'Emails per minute', 'anchor-schema' ), 'number', \__( '1 to 500. Keep within your mail provider\'s rate limit.', 'anchor-schema' ) ],
		];
		?>
		<div class="wrap">
			<h1><?php \esc_html_e( 'Announcement settings', 'anchor-schema' ); ?></h1>
			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success"><p><?php \esc_html_e( 'Settings saved.', 'anchor-schema' ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo \esc_url( \admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="anchor_announcements_settings" />
				<?php \wp_nonce_field( 'anchor_announcements_settings' ); ?>
				<table class="form-table" role="presentation">
					<?php foreach ( $fields as $key => $f ) : ?>
						<tr>
							<th scope="row"><label for="aa-<?php echo \esc_attr( $key ); ?>"><?php echo \esc_html( $f[0] ); ?></label></th>
							<td>
								<input class="regular-text" type="<?php echo \esc_attr( $f[1] ); ?>" id="aa-<?php echo \esc_attr( $key ); ?>" name="settings[<?php echo \esc_attr( $key ); ?>]" value="<?php echo \esc_attr( (string) $s[ $key ] ); ?>" />
								<?php if ( '' !== $f[2] ) : ?><p class="description"><?php echo \esc_html( $f[2] ); ?></p><?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					<tr>
						<th scope="row"><label for="aa-footer_address"><?php \esc_html_e( 'Mailing address (required)', 'anchor-schema' ); ?></label></th>
						<td>
							<textarea class="large-text" rows="3" id="aa-footer_address" name="settings[footer_address]"><?php echo \esc_textarea( (string) $s['footer_address'] ); ?></textarea>
							<p class="description"><?php \esc_html_e( 'Printed in every announcement\'s footer. Anti-spam law (CAN-SPAM, CASL) requires a physical postal address; nothing sends until this is filled in.', 'anchor-schema' ); ?></p>
						</td>
					</tr>
				</table>
				<?php \submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
```

- [ ] **Step 8: Implement the Module bootstrap**

`anchor-announcements/anchor-announcements.php`:

```php
<?php
/**
 * Anchor Tools module: Anchor Announcements.
 *
 * Compose a branded email with the shared email kit, pick recipients with AND/OR
 * rules, send through wp_mail() in background batches, and see opens, clicks and
 * unsubscribes per recipient on any mail provider. See ANNOUNCEMENTS.md.
 *
 * @package Anchor\Announcements
 */

namespace Anchor\Announcements;

use Anchor\Announcements\Database\Migrations;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

class Module {

	public const VERSION = '1.0.0';
	public const CAP     = 'anchor_send_announcements';

	private static ?Module $instance = null;

	public static function instance(): ?Module {
		return self::$instance;
	}

	public function __construct() {
		self::$instance = $this;
		// No per-module activation hook: converge on load and again on admin_init.
		Migrations::maybe_migrate();
		\add_action( 'admin_init', [ Migrations::class, 'maybe_migrate' ] );
		\add_action( 'init', [ Content\AnnouncementPostType::class, 'register' ] );
		if ( \is_admin() ) {
			new Admin\SettingsPage();
		}
	}
}
```

Later tasks add their wiring to this constructor; each says exactly which lines.

- [ ] **Step 9: Run the tests**

Run: `vendor/bin/phpunit --filter Test_Announcements_Module`
Expected: PASS (7 tests).

- [ ] **Step 10: Commit**

```bash
git add anchor-announcements anchor-tools.php composer.json tests/bootstrap.php tests/class-anchor-announcements-testcase.php tests/test-announcements-module.php
git commit -m "Announcements: module skeleton, tables, capability, CPT and settings

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Audience engine: recipient sets, conditions, registry, resolver

**Files:**
- Create: `anchor-announcements/src/Audience/RecipientSet.php`, `Condition.php`, `Registry.php`, `Resolver.php`, `anchor-announcements/src/Support/Dates.php`
- Modify: `anchor-announcements/anchor-announcements.php` (add `public Audience\Registry $conditions;` and construct it)
- Test: `tests/test-announcements-audience.php`

**Interfaces:**
- Produces:
  - `RecipientSet::add( string $email, int $user_id = 0, string $name = '' ): void` (lowercases and trims; ignores invalid addresses; a later add with a `user_id` overwrites a guest entry's user_id and name), `union( RecipientSet ): RecipientSet`, `intersect( RecipientSet ): RecipientSet`, `diff( RecipientSet ): RecipientSet`, `count(): int`, `has( string $email ): bool`, `get( string $email ): ?array`, `all(): array` (list of `['email','user_id','name']`, sorted by email), `static from_user( \WP_User $u ): array` (a row).
  - `interface Condition { key(): string; label(): string; group(): string; available(): bool; fields(): array; match( array $params ): RecipientSet; }`. `fields()` returns a list of `['key','type','label','options'?, 'search'?, 'default'?]` where type is one of `multiselect`, `select`, `date`, `number`, `text`, `textarea`, `search` (with `search` one of `users`, `products`, `courses`, `events`).
  - `Registry::register( Condition $c ): void`, `Registry::all(): array<string,Condition>` (available ones, after the `anchor_announcements_conditions` filter), `Registry::get( string $key ): ?Condition`.
  - `Resolver::__construct( Registry $registry )`, `Resolver::sanitize( $raw ): array` (accepts a JSON string or array, returns `['groups' => [ ['conditions' => [ ['type','negate','params'] ] ] ] ]`, dropping unknown types and empty groups), `Resolver::resolve( array $rules ): RecipientSet`, `Resolver::universe(): RecipientSet`.
  - `Support\Dates::gmt_range( string $from, string $to ): array{0:?string,1:?string}`: site-timezone `Y-m-d` days (inclusive) to GMT `Y-m-d H:i:s` bounds, `null` for an empty end.

Resolution rule (spec 6.1, 6.2): groups are OR'd. Inside a group, the positive conditions are intersected (the universe stands in when a group has none), then each negated condition's matches are subtracted. This keeps "specific people AND NOT role X" correct for pasted addresses that are not users.

- [ ] **Step 1: Write the failing tests**

```php
<?php
use Anchor\Announcements\Audience\Condition;
use Anchor\Announcements\Audience\RecipientSet;
use Anchor\Announcements\Audience\Registry;
use Anchor\Announcements\Audience\Resolver;
use Anchor\Announcements\Support\Dates;

/** A fixed-set condition for engine tests. */
class AA_Fixed_Condition implements Condition {
	public function __construct( private string $k, private array $emails ) {}
	public function key(): string { return $this->k; }
	public function label(): string { return $this->k; }
	public function group(): string { return 'Test'; }
	public function available(): bool { return true; }
	public function fields(): array { return []; }
	public function match( array $params ): RecipientSet {
		$s = new RecipientSet();
		foreach ( $this->emails as $e ) { $s->add( $e ); }
		return $s;
	}
}

class Test_Announcements_Audience extends Anchor_Announcements_TestCase {

	private function resolver( array $conditions ): Resolver {
		$r = new Registry();
		foreach ( $conditions as $c ) { $r->register( $c ); }
		return new Resolver( $r );
	}

	private function rules( array $groups ): array {
		return [ 'groups' => array_map( fn( $g ) => [ 'conditions' => $g ], $groups ) ];
	}

	public function test_set_normalises_and_dedupes() {
		$s = new RecipientSet();
		$s->add( ' A@X.com ' );
		$s->add( 'a@x.com', 0, 'Guest Name' );
		$s->add( 'not-an-email' );
		$this->assertSame( 1, $s->count() );
		$this->assertSame( 'a@x.com', $s->all()[0]['email'] );
		$this->assertSame( 'Guest Name', $s->all()[0]['name'] );
	}

	public function test_user_data_wins_over_guest_data() {
		$s = new RecipientSet();
		$s->add( 'a@x.com', 0, 'Guest Name' );
		$s->add( 'a@x.com', 42, 'Account Name' );
		$s->add( 'a@x.com', 0, 'Another Guest' );
		$this->assertSame( [ 'email' => 'a@x.com', 'user_id' => 42, 'name' => 'Account Name' ], $s->get( 'a@x.com' ) );
	}

	public function test_set_algebra() {
		$a = new RecipientSet(); $a->add( '1@x.com' ); $a->add( '2@x.com' );
		$b = new RecipientSet(); $b->add( '2@x.com' ); $b->add( '3@x.com' );
		$this->assertSame( 3, $a->union( $b )->count() );
		$this->assertTrue( $a->intersect( $b )->has( '2@x.com' ) );
		$this->assertSame( 1, $a->intersect( $b )->count() );
		$this->assertSame( [ '1@x.com' ], array_column( $a->diff( $b )->all(), 'email' ) );
	}

	public function test_and_inside_a_group_or_between_groups() {
		$r = $this->resolver( [ new AA_Fixed_Condition( 'a', [ '1@x.com', '2@x.com' ] ), new AA_Fixed_Condition( 'b', [ '2@x.com', '3@x.com' ] ), new AA_Fixed_Condition( 'c', [ '9@x.com' ] ) ] );
		$set = $r->resolve( $this->rules( [
			[ [ 'type' => 'a', 'negate' => false, 'params' => [] ], [ 'type' => 'b', 'negate' => false, 'params' => [] ] ],
			[ [ 'type' => 'c', 'negate' => false, 'params' => [] ] ],
		] ) );
		$this->assertSame( [ '2@x.com', '9@x.com' ], array_column( $set->all(), 'email' ) );
	}

	public function test_negation_subtracts_within_the_group() {
		$r = $this->resolver( [ new AA_Fixed_Condition( 'a', [ 'pasted@x.com', '2@x.com' ] ), new AA_Fixed_Condition( 'b', [ '2@x.com' ] ) ] );
		$set = $r->resolve( $this->rules( [ [ [ 'type' => 'a', 'negate' => false, 'params' => [] ], [ 'type' => 'b', 'negate' => true, 'params' => [] ] ] ] ) );
		// pasted@x.com is not a user, so it is outside the universe: it must survive anyway.
		$this->assertSame( [ 'pasted@x.com' ], array_column( $set->all(), 'email' ) );
	}

	public function test_only_negated_group_uses_the_universe() {
		$this->make_user( 'u1@x.com' );
		$this->make_user( 'u2@x.com' );
		$r   = $this->resolver( [ new AA_Fixed_Condition( 'b', [ 'u2@x.com' ] ) ] );
		$set = $r->resolve( $this->rules( [ [ [ 'type' => 'b', 'negate' => true, 'params' => [] ] ] ] ) );
		$this->assertTrue( $set->has( 'u1@x.com' ) );
		$this->assertFalse( $set->has( 'u2@x.com' ) );
	}

	public function test_sanitize_drops_unknown_types_and_empty_groups() {
		$r   = $this->resolver( [ new AA_Fixed_Condition( 'a', [] ) ] );
		$out = $r->sanitize( wp_json_encode( [ 'groups' => [ [ 'conditions' => [ [ 'type' => 'nope' ] ] ], [ 'conditions' => [ [ 'type' => 'a', 'negate' => '1', 'params' => [ 'x' => 1 ] ] ] ] ] ] ) );
		$this->assertSame( [ 'groups' => [ [ 'conditions' => [ [ 'type' => 'a', 'negate' => true, 'params' => [ 'x' => 1 ] ] ] ] ] ], $out );
		$this->assertSame( [ 'groups' => [] ], $r->sanitize( 'garbage' ) );
	}

	public function test_empty_rules_resolve_to_nobody() {
		$this->make_user( 'u1@x.com' );
		$this->assertSame( 0, $this->resolver( [] )->resolve( [ 'groups' => [] ] )->count() );
	}

	public function test_gmt_range_is_inclusive_site_days() {
		update_option( 'timezone_string', 'America/New_York' );
		[ $from, $to ] = Dates::gmt_range( '2026-03-01', '2026-03-31' );
		$this->assertSame( '2026-03-01 05:00:00', $from );
		$this->assertSame( '2026-04-01 03:59:59', $to ); // DST began 2026-03-08
		$this->assertSame( [ null, null ], Dates::gmt_range( '', 'garbage' ) );
		update_option( 'timezone_string', '' );
	}
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/phpunit --filter Test_Announcements_Audience`
Expected: FAIL, `Interface "Anchor\Announcements\Audience\Condition" not found`.

- [ ] **Step 3: Implement RecipientSet**

```php
<?php
declare(strict_types=1);

namespace Anchor\Announcements\Audience;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** A set of recipients keyed by lowercased email. A user's data beats a guest's. */
final class RecipientSet {

	/** @var array<string,array{email:string,user_id:int,name:string}> */
	private array $rows = [];

	public function add( string $email, int $user_id = 0, string $name = '' ): void {
		$email = \strtolower( \trim( $email ) );
		if ( ! \is_email( $email ) ) {
			return;
		}
		$existing = $this->rows[ $email ] ?? null;
		if ( null === $existing || ( $user_id > 0 && 0 === $existing['user_id'] ) ) {
			$this->rows[ $email ] = [ 'email' => $email, 'user_id' => \max( 0, $user_id ), 'name' => $name ];
			return;
		}
		if ( '' === $existing['name'] && '' !== $name ) {
			$this->rows[ $email ]['name'] = $name;
		}
	}

	public static function from_user( \WP_User $u ): array {
		return [ 'email' => (string) $u->user_email, 'user_id' => (int) $u->ID, 'name' => (string) $u->display_name ];
	}

	private function add_row( array $row ): void {
		$this->add( $row['email'], (int) $row['user_id'], (string) $row['name'] );
	}

	public function union( RecipientSet $other ): RecipientSet {
		$out = clone $this;
		foreach ( $other->rows as $row ) { $out->add_row( $row ); }
		return $out;
	}

	public function intersect( RecipientSet $other ): RecipientSet {
		$out = new RecipientSet();
		foreach ( $this->rows as $email => $row ) {
			if ( isset( $other->rows[ $email ] ) ) {
				$out->add_row( $row );
				$out->add_row( $other->rows[ $email ] );
			}
		}
		return $out;
	}

	public function diff( RecipientSet $other ): RecipientSet {
		$out = new RecipientSet();
		foreach ( $this->rows as $email => $row ) {
			if ( ! isset( $other->rows[ $email ] ) ) { $out->add_row( $row ); }
		}
		return $out;
	}

	public function count(): int { return \count( $this->rows ); }

	public function has( string $email ): bool { return isset( $this->rows[ \strtolower( \trim( $email ) ) ] ); }

	public function get( string $email ): ?array { return $this->rows[ \strtolower( \trim( $email ) ) ] ?? null; }

	/** @return list<array{email:string,user_id:int,name:string}> */
	public function all(): array {
		$rows = $this->rows;
		\ksort( $rows );
		return \array_values( $rows );
	}
}
```

- [ ] **Step 4: Implement Condition, Registry and Dates**

`Condition.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Announcements\Audience;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * One audience filter ("bought product X between these dates"). match() returns the
 * people it matches; the Resolver does AND, OR and NOT. Integrations add their own
 * through the `anchor_announcements_conditions` filter.
 */
interface Condition {
	public function key(): string;
	public function label(): string;
	/** Picker heading, e.g. "Site users", "WooCommerce". */
	public function group(): string;
	public function available(): bool;
	/** @return list<array{key:string,type:string,label:string,options?:array,search?:string,default?:mixed}> */
	public function fields(): array;
	public function match( array $params ): RecipientSet;
}
```

`Registry.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Announcements\Audience;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class Registry {

	/** @var array<string,Condition> */
	private array $conditions = [];

	public function register( Condition $c ): void {
		$this->conditions[ $c->key() ] = $c;
	}

	/** @return array<string,Condition> Available conditions only. */
	public function all(): array {
		/**
		 * Add or remove audience conditions.
		 *
		 * @param array<string,Condition> $conditions Keyed by Condition::key().
		 */
		$all = (array) \apply_filters( 'anchor_announcements_conditions', $this->conditions );
		return \array_filter( $all, static fn( $c ) => $c instanceof Condition && $c->available() );
	}

	public function get( string $key ): ?Condition {
		return $this->all()[ $key ] ?? null;
	}
}
```

`anchor-announcements/src/Support/Dates.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Announcements\Support;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class Dates {

	/**
	 * Site-timezone calendar days (inclusive) to GMT bounds for SQL.
	 *
	 * @return array{0:?string,1:?string}
	 */
	public static function gmt_range( string $from, string $to ): array {
		return [ self::bound( $from, '00:00:00' ), self::bound( $to, '23:59:59' ) ];
	}

	private static function bound( string $day, string $time ): ?string {
		if ( ! \preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) ) {
			return null;
		}
		$local = \date_create_immutable( $day . ' ' . $time, \wp_timezone() );
		return $local ? $local->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) : null;
	}
}
```

- [ ] **Step 5: Implement the Resolver**

```php
<?php
declare(strict_types=1);

namespace Anchor\Announcements\Audience;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** Groups OR'd, conditions in a group AND'd, negated conditions subtracted. */
final class Resolver {

	public function __construct( private Registry $registry ) {}

	/** @param mixed $raw JSON string or array. */
	public function sanitize( $raw ): array {
		if ( \is_string( $raw ) ) {
			$raw = \json_decode( $raw, true );
		}
		$out = [ 'groups' => [] ];
		foreach ( (array) ( \is_array( $raw ) ? ( $raw['groups'] ?? [] ) : [] ) as $group ) {
			$conditions = [];
			foreach ( (array) ( $group['conditions'] ?? [] ) as $c ) {
				$type = \sanitize_key( (string) ( $c['type'] ?? '' ) );
				if ( null === $this->registry->get( $type ) ) {
					continue;
				}
				$conditions[] = [
					'type'   => $type,
					'negate' => ! empty( $c['negate'] ),
					'params' => \is_array( $c['params'] ?? null ) ? $c['params'] : [],
				];
			}
			if ( $conditions ) {
				$out['groups'][] = [ 'conditions' => $conditions ];
			}
		}
		return $out;
	}

	public function resolve( array $rules ): RecipientSet {
		$result   = new RecipientSet();
		$universe = null;
		foreach ( $this->sanitize( $rules )['groups'] as $group ) {
			$set      = null;
			$negated  = [];
			foreach ( $group['conditions'] as $c ) {
				$matches = $this->registry->get( $c['type'] )->match( $c['params'] );
				if ( $c['negate'] ) {
					$negated[] = $matches;
					continue;
				}
				$set = null === $set ? $matches : $set->intersect( $matches );
			}
			if ( null === $set ) {
				$universe = $universe ?? $this->universe();
				$set      = $universe;
			}
			foreach ( $negated as $n ) {
				$set = $set->diff( $n );
			}
			$result = $result->union( $set );
		}
		return $result;
	}

	/** Everyone the site knows: users, WooCommerce buyers, event registrants. */
	public function universe(): RecipientSet {
		$set = new RecipientSet();
		foreach ( \get_users( [ 'fields' => [ 'ID', 'user_email', 'display_name' ] ] ) as $u ) {
			$set->add( (string) $u->user_email, (int) $u->ID, (string) $u->display_name );
		}
		/**
		 * Add non-user contacts to the universe (WooCommerce guests, event seats).
		 * Tasks 6 and 7 hook this.
		 *
		 * @param RecipientSet $set
		 */
		return \apply_filters( 'anchor_announcements_universe', $set );
	}
}
```

- [ ] **Step 6: Wire the registry into the module**

In `Module` add the property `public Audience\Registry $conditions;` and in the constructor, before the `init` hook line: `$this->conditions = new Audience\Registry();`. Add a method:

```php
	public function resolver(): Audience\Resolver {
		return new Audience\Resolver( $this->conditions );
	}
```

- [ ] **Step 7: Run the tests**

Run: `vendor/bin/phpunit --filter Test_Announcements_Audience`
Expected: PASS (9 tests).

- [ ] **Step 8: Commit**

```bash
git add anchor-announcements tests/test-announcements-audience.php
git commit -m "Announcements: audience engine (recipient sets, conditions, AND/OR/NOT resolver)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Site-user conditions

**Files:**
- Create: `anchor-announcements/src/Audience/Conditions/UserRole.php`, `UserRegistered.php`, `UserField.php`, `SpecificPeople.php`
- Modify: `anchor-announcements/anchor-announcements.php` (register the four)
- Test: `tests/test-announcements-conditions-users.php`

**Interfaces:**
- Consumes: `Condition`, `RecipientSet`, `Support\Dates::gmt_range()`.
- Produces: condition keys `user_role` (params `roles: string[]`), `user_registered` (`from`, `to`), `user_field` (`key`, `compare` one of `=`, `!=`, `contains`, `exists`, `not_exists`, `>`, `<`, `value`), `specific_people` (`users: int[]`, `emails: string` free text).

- [ ] **Step 1: Write the failing tests**

```php
<?php
use Anchor\Announcements\Audience\Conditions\SpecificPeople;
use Anchor\Announcements\Audience\Conditions\UserField;
use Anchor\Announcements\Audience\Conditions\UserRegistered;
use Anchor\Announcements\Audience\Conditions\UserRole;

class Test_Announcements_Conditions_Users extends Anchor_Announcements_TestCase {

	public function test_user_role() {
		$this->make_user( 'sub@x.com' );
		$this->make_user( 'ed@x.com', [ 'role' => 'editor' ] );
		$set = ( new UserRole() )->match( [ 'roles' => [ 'editor' ] ] );
		$this->assertTrue( $set->has( 'ed@x.com' ) );
		$this->assertFalse( $set->has( 'sub@x.com' ) );
		$this->assertSame( 0, ( new UserRole() )->match( [ 'roles' => [] ] )->count() );
	}

	public function test_user_registered_range() {
		$this->make_user( 'old@x.com', [ 'user_registered' => '2025-01-10 12:00:00' ] );
		$this->make_user( 'new@x.com', [ 'user_registered' => '2026-02-10 12:00:00' ] );
		$set = ( new UserRegistered() )->match( [ 'from' => '2026-01-01', 'to' => '' ] );
		$this->assertTrue( $set->has( 'new@x.com' ) );
		$this->assertFalse( $set->has( 'old@x.com' ) );
	}

	public function test_user_field_compares() {
		$a = $this->make_user( 'a@x.com' );
		$b = $this->make_user( 'b@x.com' );
		update_user_meta( $a, 'practice_state', 'Texas' );
		update_user_meta( $b, 'practice_state', 'Ohio' );
		$c = new UserField();
		$this->assertSame( [ 'a@x.com' ], array_column( $c->match( [ 'key' => 'practice_state', 'compare' => '=', 'value' => 'Texas' ] )->all(), 'email' ) );
		$this->assertTrue( $c->match( [ 'key' => 'practice_state', 'compare' => 'contains', 'value' => 'hi' ] )->has( 'b@x.com' ) );
		$this->assertSame( 2, $c->match( [ 'key' => 'practice_state', 'compare' => 'exists', 'value' => '' ] )->count() );
		$this->assertSame( 0, $c->match( [ 'key' => '', 'compare' => '=', 'value' => 'x' ] )->count() );
	}

	public function test_specific_people_users_and_pasted_addresses() {
		$u   = $this->make_user( 'user@x.com', [ 'display_name' => 'Uma User' ] );
		$set = ( new SpecificPeople() )->match( [ 'users' => [ $u ], 'emails' => "Pasted@X.com, second@x.com\nnot-an-email" ] );
		$this->assertSame( [ 'pasted@x.com', 'second@x.com', 'user@x.com' ], array_column( $set->all(), 'email' ) );
		$this->assertSame( 'Uma User', $set->get( 'user@x.com' )['name'] );
	}

	public function test_all_four_are_registered() {
		$keys = array_keys( $this->module()->conditions->all() );
		foreach ( [ 'user_role', 'user_registered', 'user_field', 'specific_people' ] as $k ) {
			$this->assertContains( $k, $keys );
		}
	}
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/phpunit --filter Test_Announcements_Conditions_Users`
Expected: FAIL, class not found.

- [ ] **Step 3: Implement the four conditions**

`UserRole.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Announcements\Audience\Conditions;

use Anchor\Announcements\Audience\Condition;
use Anchor\Announcements\Audience\RecipientSet;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class UserRole implements Condition {
	public function key(): string { return 'user_role'; }
	public function label(): string { return \__( 'User role', 'anchor-schema' ); }
	public function group(): string { return \__( 'Site users', 'anchor-schema' ); }
	public function available(): bool { return true; }
	public function fields(): array {
		$roles = [];
		foreach ( \wp_roles()->roles as $slug => $role ) {
			$roles[ $slug ] = \translate_user_role( $role['name'] );
		}
		return [ [ 'key' => 'roles', 'type' => 'multiselect', 'label' => \__( 'Has any of these roles', 'anchor-schema' ), 'options' => $roles ] ];
	}
	public function match( array $params ): RecipientSet {
		$set   = new RecipientSet();
		$roles = \array_values( \array_filter( \array_map( 'sanitize_key', (array) ( $params['roles'] ?? [] ) ) ) );
		if ( ! $roles ) {
			return $set;
		}
		foreach ( \get_users( [ 'role__in' => $roles, 'fields' => [ 'ID', 'user_email', 'display_name' ] ] ) as $u ) {
			$set->add( (string) $u->user_email, (int) $u->ID, (string) $u->display_name );
		}
		return $set;
	}
}
```

`UserRegistered.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Announcements\Audience\Conditions;

use Anchor\Announcements\Audience\Condition;
use Anchor\Announcements\Audience\RecipientSet;
use Anchor\Announcements\Support\Dates;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class UserRegistered implements Condition {
	public function key(): string { return 'user_registered'; }
	public function label(): string { return \__( 'Account created', 'anchor-schema' ); }
	public function group(): string { return \__( 'Site users', 'anchor-schema' ); }
	public function available(): bool { return true; }
	public function fields(): array {
		return [
			[ 'key' => 'from', 'type' => 'date', 'label' => \__( 'From', 'anchor-schema' ) ],
			[ 'key' => 'to', 'type' => 'date', 'label' => \__( 'To', 'anchor-schema' ) ],
		];
	}
	public function match( array $params ): RecipientSet {
		global $wpdb;
		[ $from, $to ] = Dates::gmt_range( (string) ( $params['from'] ?? '' ), (string) ( $params['to'] ?? '' ) );
		$where = [ '1=1' ];
		$args  = [];
		if ( $from ) { $where[] = 'user_registered >= %s'; $args[] = $from; }
		if ( $to ) { $where[] = 'user_registered <= %s'; $args[] = $to; }
		$sql  = "SELECT ID, user_email, display_name FROM {$wpdb->users} WHERE " . \implode( ' AND ', $where );
		$rows = $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ) ) : $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders only.
		$set  = new RecipientSet();
		foreach ( $rows as $r ) {
			$set->add( (string) $r->user_email, (int) $r->ID, (string) $r->display_name );
		}
		return $set;
	}
}
```

`UserField.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Announcements\Audience\Conditions;

use Anchor\Announcements\Audience\Condition;
use Anchor\Announcements\Audience\RecipientSet;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class UserField implements Condition {
	private const COMPARES = [ '=' => '=', '!=' => '!=', 'contains' => 'LIKE', 'exists' => 'EXISTS', 'not_exists' => 'NOT EXISTS', '>' => '>', '<' => '<' ];

	public function key(): string { return 'user_field'; }
	public function label(): string { return \__( 'User profile field', 'anchor-schema' ); }
	public function group(): string { return \__( 'Site users', 'anchor-schema' ); }
	public function available(): bool { return true; }
	public function fields(): array {
		return [
			[ 'key' => 'key', 'type' => 'text', 'label' => \__( 'Field (meta key)', 'anchor-schema' ) ],
			[ 'key' => 'compare', 'type' => 'select', 'label' => \__( 'Compare', 'anchor-schema' ), 'options' => [ '=' => 'equals', '!=' => 'does not equal', 'contains' => 'contains', 'exists' => 'is set', 'not_exists' => 'is not set', '>' => 'greater than', '<' => 'less than' ], 'default' => '=' ],
			[ 'key' => 'value', 'type' => 'text', 'label' => \__( 'Value', 'anchor-schema' ) ],
		];
	}
	public function match( array $params ): RecipientSet {
		$set = new RecipientSet();
		$key = \sanitize_key( (string) ( $params['key'] ?? '' ) );
		$cmp = self::COMPARES[ (string) ( $params['compare'] ?? '=' ) ] ?? null;
		if ( '' === $key || null === $cmp ) {
			return $set;
		}
		$clause = [ 'key' => $key, 'compare' => $cmp ];
		if ( ! \in_array( $cmp, [ 'EXISTS', 'NOT EXISTS' ], true ) ) {
			$clause['value'] = (string) ( $params['value'] ?? '' );
			if ( \in_array( $cmp, [ '>', '<' ], true ) ) {
				$clause['type'] = 'NUMERIC';
			}
		}
		foreach ( \get_users( [ 'meta_query' => [ $clause ], 'fields' => [ 'ID', 'user_email', 'display_name' ] ] ) as $u ) {
			$set->add( (string) $u->user_email, (int) $u->ID, (string) $u->display_name );
		}
		return $set;
	}
}
```

`SpecificPeople.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Announcements\Audience\Conditions;

use Anchor\Announcements\Audience\Condition;
use Anchor\Announcements\Audience\RecipientSet;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class SpecificPeople implements Condition {
	public function key(): string { return 'specific_people'; }
	public function label(): string { return \__( 'Specific people', 'anchor-schema' ); }
	public function group(): string { return \__( 'Site users', 'anchor-schema' ); }
	public function available(): bool { return true; }
	public function fields(): array {
		return [
			[ 'key' => 'users', 'type' => 'search', 'search' => 'users', 'label' => \__( 'Users', 'anchor-schema' ) ],
			[ 'key' => 'emails', 'type' => 'textarea', 'label' => \__( 'Email addresses (comma or one per line)', 'anchor-schema' ) ],
		];
	}
	public function match( array $params ): RecipientSet {
		$set = new RecipientSet();
		foreach ( \array_filter( \array_map( 'absint', (array) ( $params['users'] ?? [] ) ) ) as $id ) {
			$u = \get_userdata( $id );
			if ( $u ) {
				$set->add( (string) $u->user_email, (int) $u->ID, (string) $u->display_name );
			}
		}
		foreach ( \preg_split( '/[\s,;]+/', (string) ( $params['emails'] ?? '' ) ) as $email ) {
			$user = \get_user_by( 'email', $email );
			$user ? $set->add( (string) $user->user_email, (int) $user->ID, (string) $user->display_name ) : $set->add( (string) $email );
		}
		return $set;
	}
}
```

- [ ] **Step 4: Register them**

In `Module::__construct()`, right after `$this->conditions = new Audience\Registry();`:

```php
		foreach ( [ new Audience\Conditions\UserRole(), new Audience\Conditions\UserRegistered(), new Audience\Conditions\UserField(), new Audience\Conditions\SpecificPeople() ] as $condition ) {
			$this->conditions->register( $condition );
		}
```

- [ ] **Step 5: Run the tests**

Run: `vendor/bin/phpunit --filter 'Test_Announcements_(Conditions_Users|Audience)'`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add anchor-announcements tests/test-announcements-conditions-users.php
git commit -m "Announcements: site-user conditions (role, account created, profile field, specific people)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: WooCommerce conditions

**Files:**
- Create: `anchor-announcements/src/Audience/WooOrders.php`, `Conditions/WcPurchased.php`, `Conditions/WcOrderCount.php`, `Conditions/WcTotalSpent.php`
- Modify: `anchor-announcements/anchor-announcements.php` (register, universe hook)
- Test: `tests/test-announcements-conditions-woo.php`

**Interfaces:**
- Produces:
  - `WooOrders::available(): bool`; `WooOrders::orders( array $statuses, ?string $from_gmt, ?string $to_gmt, array $product_ids = [] ): array` returning a list of `['order_id' => int, 'email' => string, 'user_id' => int, 'name' => string, 'total' => float]` where a registered customer's row carries their account email and display name, a guest's the billing email and billing name; `WooOrders::add_to( RecipientSet $set, array $row ): void`; `WooOrders::statuses( array $raw ): array` (maps `completed` to `wc-completed`, default completed + processing).
  - Condition keys `wc_purchased` (`products: int[]` empty = any, `from`, `to`, `statuses: string[]`), `wc_order_count` (`compare` `>=`, `<=`, `=`; `number`; `from`; `to`), `wc_total_spent` (`compare`; `amount`; `from`; `to`).
  - Universe hook: every buyer from `WooOrders::orders( all statuses except failed, cancelled, trash, draft )`.

- [ ] **Step 1: Write the failing tests**

```php
<?php
use Anchor\Announcements\Audience\Conditions\WcOrderCount;
use Anchor\Announcements\Audience\Conditions\WcPurchased;
use Anchor\Announcements\Audience\Conditions\WcTotalSpent;

class Test_Announcements_Conditions_Woo extends Anchor_Announcements_TestCase {

	public function set_up() {
		parent::set_up();
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce not installed in this run.' );
		}
	}

	private function product( string $name ): int {
		$p = new WC_Product_Simple();
		$p->set_name( $name );
		$p->set_regular_price( '10' );
		return $p->save();
	}

	private function order( int $product_id, string $date, string $status = 'completed', int $customer_id = 0, string $email = 'guest@x.com', int $qty = 1 ): int {
		$o = wc_create_order( [ 'customer_id' => $customer_id ] );
		$o->add_product( wc_get_product( $product_id ), $qty );
		$o->set_billing_email( $email );
		$o->set_billing_first_name( 'Gina' );
		$o->set_billing_last_name( 'Guest' );
		$o->calculate_totals();
		$o->set_date_created( strtotime( $date . ' 12:00:00 UTC' ) );
		$o->set_status( $status );
		return $o->save();
	}

	public function test_purchased_product_in_date_range_includes_guests() {
		$a = $this->product( 'A' );
		$b = $this->product( 'B' );
		$this->order( $a, '2026-02-10', 'completed', 0, 'guest@x.com' );
		$this->order( $a, '2025-02-10', 'completed', 0, 'old@x.com' );
		$this->order( $b, '2026-02-10', 'completed', 0, 'other@x.com' );
		$set = ( new WcPurchased() )->match( [ 'products' => [ $a ], 'from' => '2026-01-01', 'to' => '2026-12-31', 'statuses' => [] ] );
		$this->assertSame( [ 'guest@x.com' ], array_column( $set->all(), 'email' ) );
		$this->assertSame( 'Gina Guest', $set->get( 'guest@x.com' )['name'] );
	}

	public function test_status_filter_excludes_refunded_by_default() {
		$a = $this->product( 'A' );
		$this->order( $a, '2026-02-10', 'refunded', 0, 'refund@x.com' );
		$this->assertSame( 0, ( new WcPurchased() )->match( [ 'products' => [ $a ] ] )->count() );
		$this->assertSame( 1, ( new WcPurchased() )->match( [ 'products' => [ $a ], 'statuses' => [ 'refunded' ] ] )->count() );
	}

	public function test_registered_customer_uses_account_email_and_merges_with_guest_orders() {
		$a    = $this->product( 'A' );
		$user = $this->make_user( 'member@x.com', [ 'display_name' => 'Mem Ber' ] );
		$this->order( $a, '2026-02-10', 'completed', $user, 'billing-alias@x.com' );
		$this->order( $a, '2026-02-11', 'completed', 0, 'member@x.com' );
		$set = ( new WcPurchased() )->match( [ 'products' => [ $a ] ] );
		$this->assertSame( [ 'member@x.com' ], array_column( $set->all(), 'email' ) );
		$this->assertSame( $user, $set->get( 'member@x.com' )['user_id'] );
	}

	public function test_any_product_when_products_empty() {
		$a = $this->product( 'A' );
		$this->order( $a, '2026-02-10' );
		$this->assertSame( 1, ( new WcPurchased() )->match( [ 'products' => [] ] )->count() );
	}

	public function test_variation_id_matches() {
		$parent = new WC_Product_Variable();
		$parent->set_name( 'V' );
		$pid = $parent->save();
		$v   = new WC_Product_Variation();
		$v->set_parent_id( $pid );
		$v->set_regular_price( '5' );
		$vid = $v->save();
		$this->order( $vid, '2026-02-10', 'completed', 0, 'var@x.com' );
		$this->assertTrue( ( new WcPurchased() )->match( [ 'products' => [ $vid ] ] )->has( 'var@x.com' ) );
		$this->assertTrue( ( new WcPurchased() )->match( [ 'products' => [ $pid ] ] )->has( 'var@x.com' ) );
	}

	public function test_order_count_and_total_spent() {
		$a = $this->product( 'A' );
		$this->order( $a, '2026-02-10', 'completed', 0, 'two@x.com' );
		$this->order( $a, '2026-03-10', 'completed', 0, 'two@x.com', 3 );
		$this->order( $a, '2026-03-10', 'completed', 0, 'one@x.com' );
		$this->assertSame( [ 'two@x.com' ], array_column( ( new WcOrderCount() )->match( [ 'compare' => '>=', 'number' => 2 ] )->all(), 'email' ) );
		$this->assertSame( [ 'two@x.com' ], array_column( ( new WcTotalSpent() )->match( [ 'compare' => '>=', 'amount' => 35 ] )->all(), 'email' ) );
		$this->assertSame( [ 'one@x.com' ], array_column( ( new WcTotalSpent() )->match( [ 'compare' => '<=', 'amount' => 10 ] )->all(), 'email' ) );
	}

	public function test_guest_buyers_join_the_universe() {
		$a = $this->product( 'A' );
		$this->order( $a, '2026-02-10', 'completed', 0, 'guest@x.com' );
		$this->assertTrue( $this->module()->resolver()->universe()->has( 'guest@x.com' ) );
	}
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/phpunit --filter Test_Announcements_Conditions_Woo`
Expected: FAIL, class not found (or all skipped if WooCommerce is missing: then fix Task 0 Step 2 before continuing).

- [ ] **Step 3: Implement WooOrders**

```php
<?php
declare(strict_types=1);

namespace Anchor\Announcements\Audience;

use Automattic\WooCommerce\Utilities\OrderUtil;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Order queries for the WooCommerce conditions, over the order tables themselves (HPOS
 * or legacy posts, whichever the store uses). The analytics lookup tables are not used:
 * they stop updating when WooCommerce Analytics is off (spec 1).
 */
final class WooOrders {

	public static function available(): bool {
		return \class_exists( 'WooCommerce' );
	}

	/** @return list<string> e.g. [ 'wc-completed', 'wc-processing' ]. */
	public static function statuses( array $raw ): array {
		$valid = \array_keys( \wc_get_order_statuses() );
		$out   = [];
		foreach ( $raw as $s ) {
			$s = 'wc-' . \preg_replace( '/^wc-/', '', \sanitize_key( (string) $s ) );
			if ( \in_array( $s, $valid, true ) ) {
				$out[] = $s;
			}
		}
		return $out ? \array_values( \array_unique( $out ) ) : [ 'wc-completed', 'wc-processing' ];
	}

	/** @return list<array{order_id:int,email:string,user_id:int,name:string,total:float}> */
	public static function orders( array $statuses, ?string $from_gmt, ?string $to_gmt, array $product_ids = [] ): array {
		global $wpdb;
		$statuses    = $statuses ? $statuses : [ 'wc-completed', 'wc-processing' ];
		$product_ids = \array_values( \array_filter( \array_map( 'absint', $product_ids ) ) );
		$args        = [];
		$in_status   = \implode( ',', \array_fill( 0, \count( $statuses ), '%s' ) );

		if ( \class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$orders = $wpdb->prefix . 'wc_orders';
			$addr   = $wpdb->prefix . 'wc_order_addresses';
			$sql    = "SELECT o.id AS order_id, o.billing_email AS email, o.customer_id AS user_id, CONCAT_WS(' ', a.first_name, a.last_name) AS name, o.total_amount AS total
				FROM {$orders} o LEFT JOIN {$addr} a ON a.order_id = o.id AND a.address_type = 'billing'
				WHERE o.type = 'shop_order' AND o.status IN ({$in_status})";
			$id_col   = 'o.id';
			$date_col = 'o.date_created_gmt';
		} else {
			$pm       = $wpdb->postmeta;
			$sql      = "SELECT p.ID AS order_id, em.meta_value AS email, CAST(cu.meta_value AS UNSIGNED) AS user_id, CONCAT_WS(' ', fn.meta_value, ln.meta_value) AS name, CAST(tot.meta_value AS DECIMAL(20,4)) AS total
				FROM {$wpdb->posts} p
				LEFT JOIN {$pm} em ON em.post_id = p.ID AND em.meta_key = '_billing_email'
				LEFT JOIN {$pm} cu ON cu.post_id = p.ID AND cu.meta_key = '_customer_user'
				LEFT JOIN {$pm} fn ON fn.post_id = p.ID AND fn.meta_key = '_billing_first_name'
				LEFT JOIN {$pm} ln ON ln.post_id = p.ID AND ln.meta_key = '_billing_last_name'
				LEFT JOIN {$pm} tot ON tot.post_id = p.ID AND tot.meta_key = '_order_total'
				WHERE p.post_type = 'shop_order' AND p.post_status IN ({$in_status})";
			$id_col   = 'p.ID';
			$date_col = 'p.post_date_gmt';
		}
		$args = $statuses;
		if ( $from_gmt ) { $sql .= " AND {$date_col} >= %s"; $args[] = $from_gmt; }
		if ( $to_gmt ) { $sql .= " AND {$date_col} <= %s"; $args[] = $to_gmt; }
		if ( $product_ids ) {
			$items = $wpdb->prefix . 'woocommerce_order_items';
			$meta  = $wpdb->prefix . 'woocommerce_order_itemmeta';
			$in_p  = \implode( ',', \array_fill( 0, \count( $product_ids ), '%d' ) );
			$sql  .= " AND {$id_col} IN (SELECT oi.order_id FROM {$items} oi JOIN {$meta} im ON im.order_item_id = oi.order_item_id
				WHERE oi.order_item_type = 'line_item' AND im.meta_key IN ('_product_id','_variation_id') AND im.meta_value IN ({$in_p}))";
			$args  = \array_merge( $args, $product_ids );
		}

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders only.
		$out  = [];
		foreach ( $rows as $r ) {
			$user_id = (int) $r->user_id;
			$user    = $user_id > 0 ? \get_userdata( $user_id ) : false;
			$out[]   = [
				'order_id' => (int) $r->order_id,
				'email'    => $user ? (string) $user->user_email : (string) $r->email,
				'user_id'  => $user ? $user_id : 0,
				'name'     => $user ? (string) $user->display_name : \trim( (string) $r->name ),
				'total'    => (float) $r->total,
			];
		}
		return $out;
	}

	public static function add_to( RecipientSet $set, array $row ): void {
		$set->add( $row['email'], $row['user_id'], $row['name'] );
	}
}
```

- [ ] **Step 4: Implement the three conditions**

`WcPurchased.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Announcements\Audience\Conditions;

use Anchor\Announcements\Audience\Condition;
use Anchor\Announcements\Audience\RecipientSet;
use Anchor\Announcements\Audience\WooOrders;
use Anchor\Announcements\Support\Dates;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class WcPurchased implements Condition {
	public function key(): string { return 'wc_purchased'; }
	public function label(): string { return \__( 'Purchased a product', 'anchor-schema' ); }
	public function group(): string { return 'WooCommerce'; }
	public function available(): bool { return WooOrders::available(); }
	public function fields(): array {
		return [
			[ 'key' => 'products', 'type' => 'search', 'search' => 'products', 'label' => \__( 'Any of these products (empty: any product)', 'anchor-schema' ) ],
			[ 'key' => 'from', 'type' => 'date', 'label' => \__( 'Ordered from', 'anchor-schema' ) ],
			[ 'key' => 'to', 'type' => 'date', 'label' => \__( 'Ordered to', 'anchor-schema' ) ],
			[ 'key' => 'statuses', 'type' => 'multiselect', 'label' => \__( 'Order status', 'anchor-schema' ), 'options' => WooOrders::available() ? \wc_get_order_statuses() : [], 'default' => [ 'wc-completed', 'wc-processing' ] ],
		];
	}
	public function match( array $params ): RecipientSet {
		[ $from, $to ] = Dates::gmt_range( (string) ( $params['from'] ?? '' ), (string) ( $params['to'] ?? '' ) );
		$set = new RecipientSet();
		foreach ( WooOrders::orders( WooOrders::statuses( (array) ( $params['statuses'] ?? [] ) ), $from, $to, (array) ( $params['products'] ?? [] ) ) as $row ) {
			WooOrders::add_to( $set, $row );
		}
		return $set;
	}
}
```

The variation-parent case in the test (`products => [ $pid ]` matching a variation line) works because WooCommerce stores the parent in `_product_id` and the variation in `_variation_id` on every variation line.

`WcOrderCount.php` and `WcTotalSpent.php` share one aggregation. Put it in `WooOrders` as well:

```php
	/**
	 * Per-person aggregate over orders in range: 'count' or 'total'. Keyed by the
	 * recipient's email (a registered customer's account email).
	 *
	 * @return array<string,array{row:array,value:float}>
	 */
	public static function aggregate( string $measure, array $statuses, ?string $from_gmt, ?string $to_gmt ): array {
		$out = [];
		foreach ( self::orders( $statuses, $from_gmt, $to_gmt ) as $row ) {
			$key = \strtolower( $row['email'] );
			if ( ! isset( $out[ $key ] ) || ( $row['user_id'] > 0 && 0 === $out[ $key ]['row']['user_id'] ) ) {
				$out[ $key ] = [ 'row' => $row, 'value' => $out[ $key ]['value'] ?? 0.0 ];
			}
			$out[ $key ]['value'] += 'count' === $measure ? 1.0 : $row['total'];
		}
		return $out;
	}

	public static function compare( float $value, string $op, float $target ): bool {
		switch ( $op ) {
			case '<=': return $value <= $target;
			case '=':  return \abs( $value - $target ) < 0.005;
			default:   return $value >= $target;
		}
	}
```

`WcOrderCount.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Announcements\Audience\Conditions;

use Anchor\Announcements\Audience\Condition;
use Anchor\Announcements\Audience\RecipientSet;
use Anchor\Announcements\Audience\WooOrders;
use Anchor\Announcements\Support\Dates;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class WcOrderCount implements Condition {
	public function key(): string { return 'wc_order_count'; }
	public function label(): string { return \__( 'Number of orders', 'anchor-schema' ); }
	public function group(): string { return 'WooCommerce'; }
	public function available(): bool { return WooOrders::available(); }
	public function fields(): array {
		return [
			[ 'key' => 'compare', 'type' => 'select', 'label' => \__( 'Compare', 'anchor-schema' ), 'options' => [ '>=' => 'at least', '<=' => 'at most', '=' => 'exactly' ], 'default' => '>=' ],
			[ 'key' => 'number', 'type' => 'number', 'label' => \__( 'Orders', 'anchor-schema' ), 'default' => 1 ],
			[ 'key' => 'from', 'type' => 'date', 'label' => \__( 'From', 'anchor-schema' ) ],
			[ 'key' => 'to', 'type' => 'date', 'label' => \__( 'To', 'anchor-schema' ) ],
		];
	}
	public function match( array $params ): RecipientSet {
		[ $from, $to ] = Dates::gmt_range( (string) ( $params['from'] ?? '' ), (string) ( $params['to'] ?? '' ) );
		$set = new RecipientSet();
		foreach ( WooOrders::aggregate( 'count', WooOrders::statuses( [] ), $from, $to ) as $agg ) {
			if ( WooOrders::compare( $agg['value'], (string) ( $params['compare'] ?? '>=' ), (float) ( $params['number'] ?? 1 ) ) ) {
				WooOrders::add_to( $set, $agg['row'] );
			}
		}
		return $set;
	}
}
```

`WcTotalSpent.php` is the same class shape with `key()` `wc_total_spent`, `label()` "Total spent", the number field keyed `amount` labelled "Amount" (default 100), and `WooOrders::aggregate( 'total', ... )` compared against `(float) ( $params['amount'] ?? 0 )`. Write it out in full; do not extend `WcOrderCount`.

- [ ] **Step 5: Register and extend the universe**

In `Module::__construct()` after the Task 5 registration loop:

```php
		foreach ( [ new Audience\Conditions\WcPurchased(), new Audience\Conditions\WcOrderCount(), new Audience\Conditions\WcTotalSpent() ] as $condition ) {
			$this->conditions->register( $condition );
		}
		\add_filter(
			'anchor_announcements_universe',
			static function ( Audience\RecipientSet $set ) {
				if ( Audience\WooOrders::available() ) {
					$all = \array_diff( \array_keys( \wc_get_order_statuses() ), [ 'wc-failed', 'wc-cancelled', 'wc-checkout-draft' ] );
					foreach ( Audience\WooOrders::orders( \array_values( $all ), null, null ) as $row ) {
						Audience\WooOrders::add_to( $set, $row );
					}
				}
				return $set;
			}
		);
```

- [ ] **Step 6: Run the tests**

Run: `vendor/bin/phpunit --filter Test_Announcements_Conditions_Woo`
Expected: PASS (7 tests). Then cover the legacy (posts) storage branch. Add to the test class's `set_up()` after the WooCommerce check:

```php
		if ( '0' === getenv( 'WC_HPOS' ) ) {
			update_option( 'woocommerce_custom_orders_table_enabled', 'no' );
		}
```

and run: `WC_HPOS=0 vendor/bin/phpunit --filter Test_Announcements_Conditions_Woo`
Expected: PASS in both runs.

- [ ] **Step 7: Commit**

```bash
git add anchor-announcements tests/test-announcements-conditions-woo.php
git commit -m "Announcements: WooCommerce conditions over the order tables (purchased, order count, total spent)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Course and event conditions

**Files:**
- Create: `anchor-announcements/src/Audience/Conditions/CourseEnrolled.php`, `CourseCompleted.php`, `EventRegistered.php`
- Modify: `anchor-announcements/anchor-announcements.php` (register, universe hook for seats)
- Test: `tests/test-announcements-conditions-lms-events.php`

**Interfaces:**
- Consumes: Anchor Courses table `\Anchor\Courses\Database\Migrations::table( 'enrollments' )` (columns `user_id`, `course_id`, `status` in `enrolled`, `in_progress`, `completed`, `expired`, `cancelled`, `enrolled_at`, `completed_at`); Anchor Events seats: post type `anchor_event_reg` (`\Anchor\Events\Module::REG_CPT`), meta `_anchor_event_id`, `_anchor_event_email`, `_anchor_event_name`, `_anchor_event_user_id`, `_anchor_event_reg_status`; the seat's `post_date_gmt` is its registration time.
- Produces: condition keys `course_enrolled` (`courses: int[]`, `status` one of `active`, `completed`, `any`, `from`, `to` on `enrolled_at`), `course_completed` (`courses: int[]`, `from`, `to` on `completed_at`), `event_registered` (`events: int[]`, `statuses: string[]` default `['confirmed']`, `from`, `to` on the seat's registration time).

- [ ] **Step 1: Write the failing tests**

```php
<?php
use Anchor\Announcements\Audience\Conditions\CourseCompleted;
use Anchor\Announcements\Audience\Conditions\CourseEnrolled;
use Anchor\Announcements\Audience\Conditions\EventRegistered;

class Test_Announcements_Conditions_Lms_Events extends Anchor_Announcements_TestCase {

	private function enrol( int $user, int $course, string $status, string $enrolled, ?string $completed = null ): void {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->insert( \Anchor\Courses\Database\Migrations::table( 'enrollments' ), [
			'user_id' => $user, 'course_id' => $course, 'status' => $status, 'enrolled_at' => $enrolled,
			'completed_at' => $completed, 'created_at' => $now, 'updated_at' => $now,
		] );
	}

	private function seat( int $event, string $email, string $status, string $date, int $user = 0 ): int {
		$id = self::factory()->post->create( [ 'post_type' => 'anchor_event_reg', 'post_status' => 'publish', 'post_date_gmt' => $date, 'post_date' => $date ] );
		update_post_meta( $id, '_anchor_event_id', $event );
		update_post_meta( $id, '_anchor_event_email', $email );
		update_post_meta( $id, '_anchor_event_name', 'Seat Person' );
		update_post_meta( $id, '_anchor_event_reg_status', $status );
		update_post_meta( $id, '_anchor_event_user_id', $user );
		return $id;
	}

	public function test_course_enrolled_status_and_range() {
		if ( ! class_exists( '\Anchor\Courses\Module' ) ) { $this->markTestSkipped( 'courses module off' ); }
		$a = $this->make_user( 'active@x.com' );
		$b = $this->make_user( 'done@x.com' );
		$c = $this->make_user( 'lapsed@x.com' );
		$this->enrol( $a, 100, 'in_progress', '2026-02-01 00:00:00' );
		$this->enrol( $b, 100, 'completed', '2026-02-01 00:00:00', '2026-02-05 00:00:00' );
		$this->enrol( $c, 100, 'expired', '2026-02-01 00:00:00' );
		$cond = new CourseEnrolled();
		$this->assertSame( [ 'active@x.com' ], array_column( $cond->match( [ 'courses' => [ 100 ], 'status' => 'active' ] )->all(), 'email' ) );
		$this->assertSame( 3, $cond->match( [ 'courses' => [ 100 ], 'status' => 'any' ] )->count() );
		$this->assertSame( 0, $cond->match( [ 'courses' => [ 100 ], 'status' => 'any', 'from' => '2026-03-01' ] )->count() );
	}

	public function test_course_completed_range() {
		if ( ! class_exists( '\Anchor\Courses\Module' ) ) { $this->markTestSkipped( 'courses module off' ); }
		$b = $this->make_user( 'done@x.com' );
		$this->enrol( $b, 100, 'completed', '2026-02-01 00:00:00', '2026-02-05 12:00:00' );
		$this->assertTrue( ( new CourseCompleted() )->match( [ 'courses' => [], 'from' => '2026-02-05', 'to' => '2026-02-05' ] )->has( 'done@x.com' ) );
		$this->assertSame( 0, ( new CourseCompleted() )->match( [ 'courses' => [ 999 ] ] )->count() );
	}

	public function test_event_registered_includes_guests_and_filters_status() {
		if ( ! class_exists( '\Anchor\Events\Module' ) ) { $this->markTestSkipped( 'events module off' ); }
		$this->seat( 7, 'Guest@X.com', 'confirmed', '2026-02-01 10:00:00' );
		$this->seat( 7, 'gone@x.com', 'cancelled', '2026-02-01 10:00:00' );
		$this->seat( 8, 'other@x.com', 'confirmed', '2026-02-01 10:00:00' );
		$set = ( new EventRegistered() )->match( [ 'events' => [ 7 ] ] );
		$this->assertSame( [ 'guest@x.com' ], array_column( $set->all(), 'email' ) );
		$this->assertSame( 'Seat Person', $set->get( 'guest@x.com' )['name'] );
		$this->assertSame( 2, ( new EventRegistered() )->match( [ 'events' => [ 7 ], 'statuses' => [ 'confirmed', 'cancelled' ] ] )->count() );
	}

	public function test_event_seat_with_a_user_uses_the_account() {
		if ( ! class_exists( '\Anchor\Events\Module' ) ) { $this->markTestSkipped( 'events module off' ); }
		$u = $this->make_user( 'member@x.com', [ 'display_name' => 'Mem Ber' ] );
		$this->seat( 7, 'typed-differently@x.com', 'confirmed', '2026-02-01 10:00:00', $u );
		$this->assertSame( $u, ( new EventRegistered() )->match( [ 'events' => [ 7 ] ] )->get( 'member@x.com' )['user_id'] );
	}
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/phpunit --filter Test_Announcements_Conditions_Lms_Events`
Expected: FAIL, class not found.

- [ ] **Step 3: Implement the course conditions**

`CourseEnrolled.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Announcements\Audience\Conditions;

use Anchor\Announcements\Audience\Condition;
use Anchor\Announcements\Audience\RecipientSet;
use Anchor\Announcements\Support\Dates;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class CourseEnrolled implements Condition {
	public function key(): string { return 'course_enrolled'; }
	public function label(): string { return \__( 'Enrolled in a course', 'anchor-schema' ); }
	public function group(): string { return \__( 'Courses', 'anchor-schema' ); }
	public function available(): bool { return \class_exists( '\Anchor\Courses\Module' ); }
	public function fields(): array {
		return [
			[ 'key' => 'courses', 'type' => 'search', 'search' => 'courses', 'label' => \__( 'Any of these courses (empty: any course)', 'anchor-schema' ) ],
			[ 'key' => 'status', 'type' => 'select', 'label' => \__( 'Enrolment', 'anchor-schema' ), 'options' => [ 'active' => 'active', 'completed' => 'completed', 'any' => 'any status' ], 'default' => 'active' ],
			[ 'key' => 'from', 'type' => 'date', 'label' => \__( 'Enrolled from', 'anchor-schema' ) ],
			[ 'key' => 'to', 'type' => 'date', 'label' => \__( 'Enrolled to', 'anchor-schema' ) ],
		];
	}
	public function match( array $params ): RecipientSet {
		$statuses = [
			'active'    => [ 'enrolled', 'in_progress' ],
			'completed' => [ 'completed' ],
			'any'       => [ 'enrolled', 'in_progress', 'completed', 'expired', 'cancelled' ],
		][ (string) ( $params['status'] ?? 'active' ) ] ?? [ 'enrolled', 'in_progress' ];
		return self::query( $statuses, 'enrolled_at', $params );
	}

	/** Shared with CourseCompleted. */
	public static function query( array $statuses, string $date_col, array $params ): RecipientSet {
		global $wpdb;
		$table         = \Anchor\Courses\Database\Migrations::table( 'enrollments' );
		[ $from, $to ] = Dates::gmt_range( (string) ( $params['from'] ?? '' ), (string) ( $params['to'] ?? '' ) );
		$courses       = \array_values( \array_filter( \array_map( 'absint', (array) ( $params['courses'] ?? [] ) ) ) );
		$date_col      = 'completed_at' === $date_col ? 'completed_at' : 'enrolled_at';

		$sql  = "SELECT DISTINCT e.user_id FROM {$table} e WHERE e.status IN (" . \implode( ',', \array_fill( 0, \count( $statuses ), '%s' ) ) . ')';
		$args = $statuses;
		if ( $courses ) { $sql .= ' AND e.course_id IN (' . \implode( ',', \array_fill( 0, \count( $courses ), '%d' ) ) . ')'; $args = \array_merge( $args, $courses ); }
		if ( $from ) { $sql .= " AND e.{$date_col} >= %s"; $args[] = $from; }
		if ( $to ) { $sql .= " AND e.{$date_col} <= %s"; $args[] = $to; }

		$set = new RecipientSet();
		foreach ( $wpdb->get_col( $wpdb->prepare( $sql, $args ) ) as $user_id ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders only.
			$u = \get_userdata( (int) $user_id );
			if ( $u ) {
				$set->add( (string) $u->user_email, (int) $u->ID, (string) $u->display_name );
			}
		}
		return $set;
	}
}
```

`CourseCompleted.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Announcements\Audience\Conditions;

use Anchor\Announcements\Audience\Condition;
use Anchor\Announcements\Audience\RecipientSet;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class CourseCompleted implements Condition {
	public function key(): string { return 'course_completed'; }
	public function label(): string { return \__( 'Completed a course', 'anchor-schema' ); }
	public function group(): string { return \__( 'Courses', 'anchor-schema' ); }
	public function available(): bool { return \class_exists( '\Anchor\Courses\Module' ); }
	public function fields(): array {
		return [
			[ 'key' => 'courses', 'type' => 'search', 'search' => 'courses', 'label' => \__( 'Any of these courses (empty: any course)', 'anchor-schema' ) ],
			[ 'key' => 'from', 'type' => 'date', 'label' => \__( 'Completed from', 'anchor-schema' ) ],
			[ 'key' => 'to', 'type' => 'date', 'label' => \__( 'Completed to', 'anchor-schema' ) ],
		];
	}
	public function match( array $params ): RecipientSet {
		return CourseEnrolled::query( [ 'completed' ], 'completed_at', $params );
	}
}
```

- [ ] **Step 4: Implement the event condition**

`EventRegistered.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Announcements\Audience\Conditions;

use Anchor\Announcements\Audience\Condition;
use Anchor\Announcements\Audience\RecipientSet;
use Anchor\Announcements\Support\Dates;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class EventRegistered implements Condition {
	public const SEAT_CPT = 'anchor_event_reg';

	public function key(): string { return 'event_registered'; }
	public function label(): string { return \__( 'Registered for an event', 'anchor-schema' ); }
	public function group(): string { return \__( 'Events', 'anchor-schema' ); }
	public function available(): bool { return \class_exists( '\Anchor\Events\Module' ); }
	public function fields(): array {
		return [
			[ 'key' => 'events', 'type' => 'search', 'search' => 'events', 'label' => \__( 'Any of these events (empty: any event)', 'anchor-schema' ) ],
			[ 'key' => 'statuses', 'type' => 'multiselect', 'label' => \__( 'Seat status', 'anchor-schema' ), 'options' => [ 'confirmed' => 'confirmed', 'pending' => 'pending', 'waitlist' => 'waitlist', 'cancelled' => 'cancelled', 'refunded' => 'refunded' ], 'default' => [ 'confirmed' ] ],
			[ 'key' => 'from', 'type' => 'date', 'label' => \__( 'Registered from', 'anchor-schema' ) ],
			[ 'key' => 'to', 'type' => 'date', 'label' => \__( 'Registered to', 'anchor-schema' ) ],
		];
	}
	public function match( array $params ): RecipientSet {
		return self::seats( $params, false );
	}

	/** Seats matching $params; with $all, every seat with an email (the universe). */
	public static function seats( array $params, bool $all ): RecipientSet {
		global $wpdb;
		$pm   = $wpdb->postmeta;
		$sql  = "SELECT p.ID, em.meta_value AS email, nm.meta_value AS name, CAST(us.meta_value AS UNSIGNED) AS user_id
			FROM {$wpdb->posts} p
			JOIN {$pm} em ON em.post_id = p.ID AND em.meta_key = '_anchor_event_email'
			LEFT JOIN {$pm} nm ON nm.post_id = p.ID AND nm.meta_key = '_anchor_event_name'
			LEFT JOIN {$pm} us ON us.post_id = p.ID AND us.meta_key = '_anchor_event_user_id'
			LEFT JOIN {$pm} st ON st.post_id = p.ID AND st.meta_key = '_anchor_event_reg_status'
			LEFT JOIN {$pm} ev ON ev.post_id = p.ID AND ev.meta_key = '_anchor_event_id'
			WHERE p.post_type = %s AND p.post_status = 'publish'";
		$args = [ self::SEAT_CPT ];
		if ( ! $all ) {
			$statuses = \array_values( \array_filter( \array_map( 'sanitize_key', (array) ( $params['statuses'] ?? [] ) ) ) );
			$statuses = $statuses ? $statuses : [ 'confirmed' ];
			$sql     .= ' AND st.meta_value IN (' . \implode( ',', \array_fill( 0, \count( $statuses ), '%s' ) ) . ')';
			$args     = \array_merge( $args, $statuses );
			$events   = \array_values( \array_filter( \array_map( 'absint', (array) ( $params['events'] ?? [] ) ) ) );
			if ( $events ) {
				$sql .= ' AND CAST(ev.meta_value AS UNSIGNED) IN (' . \implode( ',', \array_fill( 0, \count( $events ), '%d' ) ) . ')';
				$args = \array_merge( $args, $events );
			}
			[ $from, $to ] = Dates::gmt_range( (string) ( $params['from'] ?? '' ), (string) ( $params['to'] ?? '' ) );
			if ( $from ) { $sql .= ' AND p.post_date_gmt >= %s'; $args[] = $from; }
			if ( $to ) { $sql .= ' AND p.post_date_gmt <= %s'; $args[] = $to; }
		}
		$set = new RecipientSet();
		foreach ( $wpdb->get_results( $wpdb->prepare( $sql, $args ) ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders only.
			$u = (int) $r->user_id > 0 ? \get_userdata( (int) $r->user_id ) : false;
			$u ? $set->add( (string) $u->user_email, (int) $u->ID, (string) $u->display_name ) : $set->add( (string) $r->email, 0, (string) $r->name );
		}
		return $set;
	}
}
```

- [ ] **Step 5: Register and extend the universe**

In `Module::__construct()` after the Task 6 block:

```php
		foreach ( [ new Audience\Conditions\CourseEnrolled(), new Audience\Conditions\CourseCompleted(), new Audience\Conditions\EventRegistered() ] as $condition ) {
			$this->conditions->register( $condition );
		}
		\add_filter(
			'anchor_announcements_universe',
			static function ( Audience\RecipientSet $set ) {
				return \class_exists( '\Anchor\Events\Module' ) ? $set->union( Audience\Conditions\EventRegistered::seats( [], true ) ) : $set;
			}
		);
```

Courses need no universe hook: every enrolment belongs to a user, already in the universe.

- [ ] **Step 6: Run the tests**

Run: `vendor/bin/phpunit --filter 'Test_Announcements_'`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add anchor-announcements tests/test-announcements-conditions-lms-events.php
git commit -m "Announcements: course and event conditions

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Suppressions, tracking URLs, link rewriting and the renderer

**Files:**
- Create: `anchor-announcements/src/Suppression/Suppressions.php`, `anchor-announcements/src/Tracking/Urls.php`, `anchor-announcements/src/Tracking/LinkRewriter.php`, `anchor-announcements/src/Rendering/Renderer.php`
- Modify: `anchor-announcements/anchor-announcements.php` (register palette tokens, the suppress action)
- Test: `tests/test-announcements-render.php`

**Interfaces:**
- Consumes: `Anchor_Email_Tokens`, `Anchor_Email_Sanitizer`, `Anchor_Email_Shell`, `Support\Settings`, `AnnouncementPostType` meta keys.
- Produces:
  - `Suppressions::is_suppressed( string $email ): bool`, `Suppressions::add( string $email, string $reason, int $announcement_id = 0 ): void` (first reason wins), `Suppressions::remove( string $email ): void`, `Suppressions::list( string $search = '', int $page = 1, int $per_page = 50 ): array{rows:array,total:int}`. Action `anchor_announcements_suppress( $email, $reason, $announcement_id )` calls `add()`.
  - `Urls::QUERY_VAR = 'anchor_aa'`; `Urls::open( string $token ): string`, `Urls::click( string $token, int $index ): string`, `Urls::unsubscribe( string $token ): string`.
  - `LinkRewriter::links( string $body ): array` (the ordered, de-duplicated list of trackable href templates, entities decoded), `LinkRewriter::rewrite( string $html, array $links, callable $url_for ): string` (`$url_for( int $index ): string`). Not trackable: `mailto:`, `tel:`, `sms:`, `#...`, anything containing `{unsubscribe_url}`, anything that is not `http(s)://` or a `{token}` placeholder.
  - `Renderer::tokens( array $recipient, string $send_token ): array`, `Renderer::render( int $announcement_id, array $recipient, string $send_token, bool $track, ?array $override = null ): array{subject:string,html:string}` (`$override` keys `subject`, `preheader`, `body` for unsaved previews), `Renderer::sample_recipient(): array` (the current user as a recipient row).

- [ ] **Step 1: Write the failing tests**

```php
<?php
use Anchor\Announcements\Content\AnnouncementPostType as PT;
use Anchor\Announcements\Rendering\Renderer;
use Anchor\Announcements\Suppression\Suppressions;
use Anchor\Announcements\Tracking\LinkRewriter;
use Anchor\Announcements\Tracking\Urls;
use Anchor\Announcements\Support\Settings;

class Test_Announcements_Render extends Anchor_Announcements_TestCase {

	public function test_suppressions_first_reason_wins_and_remove() {
		Suppressions::add( 'A@x.com', 'unsubscribed', 5 );
		Suppressions::add( 'a@x.com', 'bounced' );
		$this->assertTrue( Suppressions::is_suppressed( 'a@X.com' ) );
		$this->assertSame( 'unsubscribed', Suppressions::list()['rows'][0]['reason'] );
		Suppressions::remove( 'a@x.com' );
		$this->assertFalse( Suppressions::is_suppressed( 'a@x.com' ) );
	}

	public function test_suppress_action() {
		do_action( 'anchor_announcements_suppress', 'b@x.com', 'complained', 0 );
		$this->assertTrue( Suppressions::is_suppressed( 'b@x.com' ) );
	}

	public function test_links_are_collected_decoded_and_deduped() {
		$body  = '<a href="https://x.com/?a=1&amp;b=2">1</a><a href="mailto:a@x.com">m</a><a href="tel:123">t</a><a href="#top">h</a>'
			. '<a href="{unsubscribe_url}">u</a><a href="https://x.com/?a=1&amp;b=2">again</a><a href="{account_url}">acct</a><a href="javascript:x">j</a>';
		$this->assertSame( [ 'https://x.com/?a=1&b=2', '{account_url}' ], LinkRewriter::links( $body ) );
	}

	public function test_rewrite_replaces_only_trackable_hrefs() {
		$body  = '<a href="https://x.com/?a=1&amp;b=2">1</a> <a href="mailto:a@x.com">m</a> <a href=\'{account_url}\'>acct</a>';
		$links = LinkRewriter::links( $body );
		$out   = LinkRewriter::rewrite( $body, $links, fn( $i ) => 'https://site.test/?anchor_aa=c&t=T&l=' . $i );
		$this->assertStringContainsString( 'href="https://site.test/?anchor_aa=c&amp;t=T&amp;l=0"', $out );
		$this->assertStringContainsString( 'href="https://site.test/?anchor_aa=c&amp;t=T&amp;l=1"', $out );
		$this->assertStringContainsString( 'href="mailto:a@x.com"', $out );
	}

	public function test_render_expands_tokens_rewrites_links_and_adds_pixel_and_footer() {
		Settings::save( [ 'footer_address' => "1 Main St\nTown", 'brand_color' => '#112233' ] );
		$uid = $this->make_user( 'rita@x.com', [ 'first_name' => 'Rita', 'last_name' => 'Ray', 'display_name' => 'Rita Ray', 'user_login' => 'rita' ] );
		$id  = $this->make_announcement( [ PT::META_BODY => '<p>Hi {first_name} ({username})</p><a href="https://example.com/page?a=1&amp;b=2">read</a>' ] );
		update_post_meta( $id, PT::META_LINKS, LinkRewriter::links( get_post_meta( $id, PT::META_BODY, true ) ) );
		$out = Renderer::render( $id, [ 'email' => 'rita@x.com', 'user_id' => $uid, 'name' => 'Rita Ray' ], str_repeat( 'a', 32 ), true );
		$this->assertSame( 'Hello Rita', $out['subject'] );
		$this->assertStringContainsString( 'Hi Rita (rita)', $out['html'] );
		$this->assertStringContainsString( esc_attr( Urls::click( str_repeat( 'a', 32 ), 0 ) ), $out['html'] );
		$this->assertStringNotContainsString( 'https://example.com/page', $out['html'] );
		$this->assertStringContainsString( 'anchor_aa=o', $out['html'] );
		$this->assertStringContainsString( '1 Main St<br />', $out['html'] );
		$this->assertStringContainsString( 'anchor_aa=u', $out['html'] );
		$this->assertStringContainsString( '#112233', $out['html'] );
	}

	public function test_untracked_render_keeps_original_links_and_has_no_pixel() {
		Settings::save( [ 'footer_address' => '1 Main St' ] );
		$id  = $this->make_announcement();
		$out = Renderer::render( $id, [ 'email' => 'g@x.com', 'user_id' => 0, 'name' => 'Gia Guest' ], '', false );
		$this->assertStringContainsString( 'https://example.com/page?a=1&amp;b=2', $out['html'] );
		$this->assertStringNotContainsString( 'anchor_aa=o', $out['html'] );
	}

	public function test_guest_tokens() {
		$t = Renderer::tokens( [ 'email' => 'g@x.com', 'user_id' => 0, 'name' => 'Gia Van Guest' ], 'tok' );
		$this->assertSame( 'Gia', $t['first_name'] );
		$this->assertSame( 'Van Guest', $t['last_name'] );
		$this->assertSame( '', $t['username'] );
		$this->assertSame( 'g@x.com', $t['email'] );
		$this->assertSame( Urls::unsubscribe( 'tok' ), $t['unsubscribe_url'] );
	}

	public function test_override_renders_unsaved_content() {
		Settings::save( [ 'footer_address' => '1 Main St' ] );
		$id  = $this->make_announcement();
		$out = Renderer::render( $id, Renderer::sample_recipient(), '', false, [ 'subject' => 'Draft {site_name}', 'preheader' => '', 'body' => '<p>Unsaved</p><script>x</script>' ] );
		$this->assertSame( 'Draft ' . get_bloginfo( 'name' ), $out['subject'] );
		$this->assertStringContainsString( '<p>Unsaved</p>', $out['html'] );
		$this->assertStringNotContainsString( '<script', $out['html'] );
	}
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/phpunit --filter Test_Announcements_Render`
Expected: FAIL, class not found.

- [ ] **Step 3: Implement Suppressions**

```php
<?php
declare(strict_types=1);

namespace Anchor\Announcements\Suppression;

use Anchor\Announcements\Database\Migrations;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** Addresses that must never be sent an announcement (unsubscribed, bounced, complained, manual). */
final class Suppressions {

	public const REASONS = [ 'unsubscribed', 'bounced', 'complained', 'manual' ];

	public static function is_suppressed( string $email ): bool {
		global $wpdb;
		$t = Migrations::table( 'suppressions' );
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$t} WHERE email = %s", \strtolower( \trim( $email ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
	}

	public static function add( string $email, string $reason, int $announcement_id = 0 ): void {
		global $wpdb;
		$email = \strtolower( \trim( $email ) );
		if ( ! \is_email( $email ) ) {
			return;
		}
		$reason = \in_array( $reason, self::REASONS, true ) ? $reason : 'manual';
		$t      = Migrations::table( 'suppressions' );
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$t} (email, reason, source_announcement_id, created_at) VALUES (%s, %s, %d, %s)", $email, $reason, $announcement_id, \current_time( 'mysql', true ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function remove( string $email ): void {
		global $wpdb;
		$wpdb->delete( Migrations::table( 'suppressions' ), [ 'email' => \strtolower( \trim( $email ) ) ] );
	}

	/** @return array{rows:list<array>,total:int} */
	public static function list( string $search = '', int $page = 1, int $per_page = 50 ): array {
		global $wpdb;
		$t     = Migrations::table( 'suppressions' );
		$where = '' !== $search ? $wpdb->prepare( 'WHERE email LIKE %s', '%' . $wpdb->esc_like( \strtolower( $search ) ) . '%' ) : '';
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d", $per_page, \max( 0, $page - 1 ) * $per_page ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return [ 'rows' => $rows, 'total' => $total ];
	}
}
```

- [ ] **Step 4: Implement Urls and LinkRewriter**

`Urls.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Announcements\Tracking;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** Query-var tracking URLs on the home page: work with REST restricted and no login. */
final class Urls {

	public const QUERY_VAR = 'anchor_aa';

	public static function open( string $token ): string {
		return \add_query_arg( [ self::QUERY_VAR => 'o', 't' => $token ], \home_url( '/' ) );
	}

	public static function click( string $token, int $index ): string {
		return \add_query_arg( [ self::QUERY_VAR => 'c', 't' => $token, 'l' => $index ], \home_url( '/' ) );
	}

	public static function unsubscribe( string $token ): string {
		return \add_query_arg( [ self::QUERY_VAR => 'u', 't' => $token ], \home_url( '/' ) );
	}
}
```

`LinkRewriter.php`:

```php
<?php
declare(strict_types=1);

namespace Anchor\Announcements\Tracking;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Finds the trackable links in a body template and swaps each for its click URL. The
 * link list is captured once per announcement (at send) and is the ONLY source a click
 * redirect reads, so the click endpoint can never be used as an open redirect.
 */
final class LinkRewriter {

	private const HREF = '/(<a\b[^>]*?\bhref\s*=\s*)(["\'])(.*?)\2/is';

	/** @return list<string> Decoded href templates, in first-seen order. */
	public static function links( string $body ): array {
		$links = [];
		\preg_match_all( self::HREF, $body, $m );
		foreach ( $m[3] as $raw ) {
			$href = \html_entity_decode( \trim( (string) $raw ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			if ( self::trackable( $href ) && ! \in_array( $href, $links, true ) ) {
				$links[] = $href;
			}
		}
		return $links;
	}

	/** @param callable(int):string $url_for */
	public static function rewrite( string $html, array $links, callable $url_for ): string {
		return (string) \preg_replace_callback(
			self::HREF,
			static function ( $m ) use ( $links, $url_for ) {
				$href  = \html_entity_decode( \trim( (string) $m[3] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				$index = \array_search( $href, $links, true );
				if ( false === $index || ! self::trackable( $href ) ) {
					return $m[0];
				}
				return $m[1] . '"' . \esc_attr( $url_for( (int) $index ) ) . '"';
			},
			$html
		);
	}

	private static function trackable( string $href ): bool {
		if ( '' === $href || \str_contains( $href, '{unsubscribe_url}' ) ) {
			return false;
		}
		return (bool) \preg_match( '#^(https?://|\{[a-z0-9_]+\})#i', $href );
	}
}
```

- [ ] **Step 5: Implement the Renderer**

```php
<?php
declare(strict_types=1);

namespace Anchor\Announcements\Rendering;

use Anchor\Announcements\Content\AnnouncementPostType as PT;
use Anchor\Announcements\Support\Settings;
use Anchor\Announcements\Tracking\LinkRewriter;
use Anchor\Announcements\Tracking\Urls;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** One announcement, one recipient: subject and full HTML, exactly as sent. */
final class Renderer {

	public static function tokens( array $recipient, string $send_token ): array {
		$user  = ! empty( $recipient['user_id'] ) ? \get_userdata( (int) $recipient['user_id'] ) : false;
		$name  = \trim( (string) ( $recipient['name'] ?? '' ) );
		$parts = '' !== $name ? \preg_split( '/\s+/', $name, 2 ) : [ '', '' ];
		$first = $user && '' !== (string) $user->first_name ? (string) $user->first_name : (string) ( $parts[0] ?? '' );
		$last  = $user && '' !== (string) $user->last_name ? (string) $user->last_name : (string) ( $parts[1] ?? '' );

		$tokens = [
			'first_name'      => $first,
			'last_name'       => $last,
			'display_name'    => $user ? (string) $user->display_name : $name,
			'username'        => $user ? (string) $user->user_login : '',
			'email'           => (string) $recipient['email'],
			'site_name'       => (string) \get_bloginfo( 'name' ),
			'site_url'        => \home_url( '/' ),
			'login_url'       => \wp_login_url(),
			'account_url'     => \function_exists( 'wc_get_page_permalink' ) ? (string) \wc_get_page_permalink( 'myaccount' ) : \admin_url( 'profile.php' ),
			'unsubscribe_url' => '' !== $send_token ? Urls::unsubscribe( $send_token ) : \home_url( '/' ),
		];

		/**
		 * Per-recipient tokens.
		 *
		 * @param array $tokens    [ key => value ].
		 * @param array $recipient email, user_id, name.
		 */
		return (array) \apply_filters( 'anchor_announcements_tokens', $tokens, $recipient );
	}

	public static function sample_recipient(): array {
		$u = \wp_get_current_user();
		return [ 'email' => (string) $u->user_email, 'user_id' => (int) $u->ID, 'name' => (string) $u->display_name ];
	}

	/**
	 * @param array|null $override subject, preheader, body (unsaved editor content).
	 * @return array{subject:string,html:string}
	 */
	public static function render( int $announcement_id, array $recipient, string $send_token, bool $track, ?array $override = null ): array {
		$subject   = null !== $override ? (string) ( $override['subject'] ?? '' ) : (string) \get_post_meta( $announcement_id, PT::META_SUBJECT, true );
		$preheader = null !== $override ? (string) ( $override['preheader'] ?? '' ) : (string) \get_post_meta( $announcement_id, PT::META_PREHEADER, true );
		$body      = \Anchor_Email_Sanitizer::body( null !== $override ? (string) ( $override['body'] ?? '' ) : (string) \get_post_meta( $announcement_id, PT::META_BODY, true ) );
		$tokens    = self::tokens( $recipient, $send_token );
		$settings  = Settings::get();

		if ( $track && '' !== $send_token ) {
			$links = (array) \get_post_meta( $announcement_id, PT::META_LINKS, true );
			$body  = LinkRewriter::rewrite( $body, $links, static fn( int $i ) => Urls::click( $send_token, $i ) );
		}

		$footer = '<p style="margin:0 0 6px;">' . \esc_html( (string) \get_bloginfo( 'name' ) ) . '<br />' . \nl2br( \esc_html( (string) $settings['footer_address'] ) ) . '</p>'
			. '<p style="margin:0;"><a href="{unsubscribe_url}" style="color:#777777;">' . \esc_html__( 'Unsubscribe', 'anchor-schema' ) . '</a></p>';

		$html = \Anchor_Email_Shell::render(
			[
				'title'       => \Anchor_Email_Tokens::expand( $subject, $tokens, true ),
				'preheader'   => \Anchor_Email_Tokens::expand( $preheader, $tokens, false ),
				'body'        => \Anchor_Email_Tokens::expand( $body, $tokens, true ),
				'footer'      => \Anchor_Email_Tokens::expand( $footer, $tokens, true ),
				'brand_color' => (string) $settings['brand_color'],
				'logo_url'    => (string) $settings['logo_url'],
			]
		);

		if ( $track && '' !== $send_token ) {
			$pixel = '<img src="' . \esc_url( Urls::open( $send_token ) ) . '" width="1" height="1" alt="" style="display:block;border:0;width:1px;height:1px;" />';
			$html  = \str_replace( '</body>', $pixel . '</body>', $html );
		}

		return [ 'subject' => \Anchor_Email_Tokens::expand( $subject, $tokens, false ), 'html' => $html ];
	}
}
```

- [ ] **Step 6: Register palette tokens and the suppress action**

In `Module::__construct()`:

```php
		\add_action( 'init', [ $this, 'register_tokens' ] );
		\add_action( 'anchor_announcements_suppress', [ Suppression\Suppressions::class, 'add' ], 10, 3 );
```

and the method:

```php
	public function register_tokens(): void {
		$recipient = \__( 'Recipient', 'anchor-schema' );
		$site      = \__( 'Site', 'anchor-schema' );
		foreach ( [
			'first_name' => [ \__( 'First name', 'anchor-schema' ), $recipient ],
			'last_name' => [ \__( 'Last name', 'anchor-schema' ), $recipient ],
			'display_name' => [ \__( 'Display name', 'anchor-schema' ), $recipient ],
			'username' => [ \__( 'Username', 'anchor-schema' ), $recipient ],
			'email' => [ \__( 'Email', 'anchor-schema' ), $recipient ],
			'site_name' => [ \__( 'Site name', 'anchor-schema' ), $site ],
			'site_url' => [ \__( 'Site link', 'anchor-schema' ), $site ],
			'login_url' => [ \__( 'Login link', 'anchor-schema' ), $site ],
			'account_url' => [ \__( 'Account link', 'anchor-schema' ), $site ],
			'unsubscribe_url' => [ \__( 'Unsubscribe link', 'anchor-schema' ), $site ],
		] as $key => $t ) {
			\Anchor_Email_Tokens::register( $key, $t[0], $t[1] );
		}
	}
```

Note: `Suppressions::add( string, string, int )` receives the action's arguments as passed; callers must pass an int announcement id.

- [ ] **Step 7: Run the tests**

Run: `vendor/bin/phpunit --filter Test_Announcements_Render`
Expected: PASS (8 tests).

- [ ] **Step 8: Commit**

```bash
git add anchor-announcements tests/test-announcements-render.php
git commit -m "Announcements: suppressions, tracking URLs, link rewriting and the per-recipient renderer

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Tracking endpoints (open, click, unsubscribe)

**Files:**
- Create: `anchor-announcements/src/Tracking/Endpoints.php`, `anchor-announcements/templates/unsubscribe.php`
- Modify: `anchor-announcements/anchor-announcements.php` (construct `Tracking\Endpoints`)
- Test: `tests/test-announcements-tracking.php`

**Interfaces:**
- Consumes: `Urls::QUERY_VAR`, `Suppressions::add()`, `Renderer::tokens()`, `PT::META_LINKS`, the `sends` and `events` tables.
- Produces:
  - `Endpoints::find_send( string $token ): ?object` (a `sends` row, `null` for an unknown or malformed token).
  - `Endpoints::record( object $send, string $type, ?int $link_index = null, bool $scanner = false ): void` (inserts an `events` row; for `open` bumps `open_count` and sets `first_opened_at` once; for a non-scanner `click` bumps `click_count` and sets `first_clicked_at` once).
  - `Endpoints::click_target( object $send, int $index ): string` (the expanded destination, `''` when the index is not in the stored list or does not expand to an http(s) URL).
  - `Endpoints::is_scanner_click( object $send ): bool` (sent under 10 seconds ago and never opened).
  - `Endpoints::handle(): void` on `template_redirect` priority 0; it answers and `exit`s only when `anchor_aa` is present.

- [ ] **Step 1: Write the failing tests**

```php
<?php
use Anchor\Announcements\Content\AnnouncementPostType as PT;
use Anchor\Announcements\Database\Migrations;
use Anchor\Announcements\Suppression\Suppressions;
use Anchor\Announcements\Tracking\Endpoints;

class Test_Announcements_Tracking extends Anchor_Announcements_TestCase {

	private function send_row( int $announcement, string $email = 'r@x.com', ?string $sent_at = '2026-01-01 00:00:00', int $user_id = 0 ): object {
		global $wpdb;
		$token = bin2hex( random_bytes( 16 ) );
		$wpdb->insert( Migrations::table( 'sends' ), [ 'announcement_id' => $announcement, 'email' => $email, 'user_id' => $user_id ?: null, 'name' => 'R', 'token' => $token, 'status' => 'sent', 'queued_at' => '2026-01-01 00:00:00', 'sent_at' => $sent_at ] );
		return Endpoints::find_send( $token );
	}

	public function test_find_send_rejects_bad_tokens() {
		$this->assertNull( Endpoints::find_send( 'nope' ) );
		$this->assertNull( Endpoints::find_send( str_repeat( 'z', 32 ) ) );
	}

	public function test_open_counts_every_hit_and_first_time_once() {
		$send = $this->send_row( $this->make_announcement() );
		Endpoints::record( $send, 'open' );
		$first = Endpoints::find_send( $send->token )->first_opened_at;
		Endpoints::record( Endpoints::find_send( $send->token ), 'open' );
		$again = Endpoints::find_send( $send->token );
		$this->assertSame( 2, (int) $again->open_count );
		$this->assertSame( $first, $again->first_opened_at );
	}

	public function test_click_target_only_from_the_stored_list_and_expands_tokens() {
		$id = $this->make_announcement();
		update_post_meta( $id, PT::META_LINKS, [ 'https://example.com/page?a=1&b=2', '{account_url}', 'javascript:alert(1)' ] );
		$send = $this->send_row( $id );
		$this->assertSame( 'https://example.com/page?a=1&b=2', Endpoints::click_target( $send, 0 ) );
		$this->assertStringStartsWith( 'http', Endpoints::click_target( $send, 1 ) );
		$this->assertSame( '', Endpoints::click_target( $send, 2 ) );
		$this->assertSame( '', Endpoints::click_target( $send, 99 ) );
	}

	public function test_scanner_click_is_kept_but_not_counted() {
		$send = $this->send_row( $this->make_announcement(), 'r@x.com', gmdate( 'Y-m-d H:i:s' ) );
		$this->assertTrue( Endpoints::is_scanner_click( $send ) );
		Endpoints::record( $send, 'click', 0, true );
		$this->assertSame( 0, (int) Endpoints::find_send( $send->token )->click_count );
		global $wpdb;
		$this->assertSame( '1', $wpdb->get_var( $wpdb->prepare( 'SELECT scanner FROM ' . Migrations::table( 'events' ) . ' WHERE send_id = %d', $send->id ) ) );
	}

	public function test_real_click_counts() {
		$send = $this->send_row( $this->make_announcement() );
		$this->assertFalse( Endpoints::is_scanner_click( $send ) );
		Endpoints::record( $send, 'click', 0 );
		$this->assertSame( 1, (int) Endpoints::find_send( $send->token )->click_count );
		$this->assertNotNull( Endpoints::find_send( $send->token )->first_clicked_at );
	}

	public function test_unsubscribe_suppresses_and_records() {
		$send = $this->send_row( $this->make_announcement(), 'leave@x.com' );
		Endpoints::unsubscribe( $send );
		$this->assertTrue( Suppressions::is_suppressed( 'leave@x.com' ) );
		global $wpdb;
		$this->assertSame( 'unsubscribe', $wpdb->get_var( $wpdb->prepare( 'SELECT type FROM ' . Migrations::table( 'events' ) . ' WHERE send_id = %d', $send->id ) ) );
	}
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/phpunit --filter Test_Announcements_Tracking`
Expected: FAIL, class not found.

- [ ] **Step 3: Implement Endpoints**

```php
<?php
declare(strict_types=1);

namespace Anchor\Announcements\Tracking;

use Anchor\Announcements\Content\AnnouncementPostType as PT;
use Anchor\Announcements\Database\Migrations;
use Anchor\Announcements\Rendering\Renderer;
use Anchor\Announcements\Suppression\Suppressions;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** ?anchor_aa=o|c|u&t=<token>[&l=<index>] on the home URL. */
final class Endpoints {

	private const GIF = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

	public function __construct() {
		\add_action( 'template_redirect', [ $this, 'handle' ], 0 );
	}

	public static function find_send( string $token ): ?object {
		if ( ! \preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
			return null;
		}
		global $wpdb;
		$t   = Migrations::table( 'sends' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE token = %s", $token ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? $row : null;
	}

	public static function record( object $send, string $type, ?int $link_index = null, bool $scanner = false ): void {
		global $wpdb;
		$now = \current_time( 'mysql', true );
		$ua  = \substr( \sanitize_text_field( \wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ), 0, 255 ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$wpdb->insert( Migrations::table( 'events' ), [ 'send_id' => (int) $send->id, 'type' => $type, 'link_index' => $link_index, 'scanner' => $scanner ? 1 : 0, 'user_agent' => $ua, 'created_at' => $now ] );
		$t = Migrations::table( 'sends' );
		if ( 'open' === $type ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET open_count = open_count + 1, first_opened_at = COALESCE(first_opened_at, %s) WHERE id = %d", $now, (int) $send->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} elseif ( 'click' === $type && ! $scanner ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET click_count = click_count + 1, first_clicked_at = COALESCE(first_clicked_at, %s) WHERE id = %d", $now, (int) $send->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	public static function click_target( object $send, int $index ): string {
		$links = (array) \get_post_meta( (int) $send->announcement_id, PT::META_LINKS, true );
		if ( ! isset( $links[ $index ] ) ) {
			return '';
		}
		$recipient = [ 'email' => (string) $send->email, 'user_id' => (int) $send->user_id, 'name' => (string) $send->name ];
		$url       = \Anchor_Email_Tokens::expand( (string) $links[ $index ], Renderer::tokens( $recipient, (string) $send->token ), false );
		return \preg_match( '#^https?://#i', $url ) ? $url : '';
	}

	public static function is_scanner_click( object $send ): bool {
		if ( empty( $send->sent_at ) || (int) $send->open_count > 0 ) {
			return false;
		}
		return ( \time() - (int) \strtotime( $send->sent_at . ' UTC' ) ) < 10;
	}

	public static function unsubscribe( object $send ): void {
		Suppressions::add( (string) $send->email, 'unsubscribed', (int) $send->announcement_id );
		self::record( $send, 'unsubscribe' );
	}

	public function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification -- token-authenticated, no session.
		$kind = isset( $_GET[ Urls::QUERY_VAR ] ) ? \sanitize_key( \wp_unslash( $_GET[ Urls::QUERY_VAR ] ) ) : '';
		if ( '' === $kind ) {
			return;
		}
		$send = self::find_send( isset( $_GET['t'] ) ? \sanitize_key( \wp_unslash( $_GET['t'] ) ) : '' );
		\nocache_headers();

		if ( 'o' === $kind ) {
			if ( $send ) {
				self::record( $send, 'open' );
			}
			\header( 'Content-Type: image/gif' );
			echo \base64_decode( self::GIF ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- a fixed 1x1 GIF.
			exit;
		}

		if ( 'c' === $kind ) {
			$index  = isset( $_GET['l'] ) ? \absint( $_GET['l'] ) : -1;
			$target = $send && $index >= 0 ? self::click_target( $send, $index ) : '';
			if ( '' === $target ) {
				\wp_safe_redirect( \home_url( '/' ) );
				exit;
			}
			self::record( $send, 'click', $index, self::is_scanner_click( $send ) );
			\wp_redirect( $target, 302 ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- target comes only from the announcement's stored link list.
			exit;
		}

		if ( 'u' === $kind ) {
			$done = false;
			if ( $send && 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				self::unsubscribe( $send );
				$done = true;
			}
			\status_header( $send ? 200 : 404 );
			$email = $send ? (string) $send->email : '';
			include \dirname( __DIR__, 2 ) . '/templates/unsubscribe.php';
			exit;
		}
		// phpcs:enable
	}
}
```

- [ ] **Step 4: The unsubscribe page**

`anchor-announcements/templates/unsubscribe.php` (a standalone page: it must work even if the theme is broken, and it is also the RFC 8058 one-click target, which POSTs to the same URL):

```php
<?php
/**
 * Unsubscribe page. Variables: $send (row|null), $done (bool), $email (string).
 *
 * @package Anchor\Announcements
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }
$aa_site = get_bloginfo( 'name' );
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width,initial-scale=1" />
	<meta name="robots" content="noindex" />
	<title><?php echo esc_html( sprintf( __( 'Unsubscribe from %s', 'anchor-schema' ), $aa_site ) ); ?></title>
	<style>body{margin:0;background:#f4f5f5;font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:#1f2a28}.card{max-width:480px;margin:10vh auto;background:#fff;border-radius:8px;padding:32px;box-shadow:0 2px 8px rgba(0,0,0,.06)}button{font:inherit;padding:10px 20px;border:0;border-radius:6px;background:#1a4f48;color:#fff;cursor:pointer}a{color:#1a4f48}</style>
</head>
<body>
	<div class="card">
		<?php if ( ! $send ) : ?>
			<h1><?php esc_html_e( 'Link not recognised', 'anchor-schema' ); ?></h1>
			<p><?php esc_html_e( 'This unsubscribe link is not valid. Please use the link in the email you received.', 'anchor-schema' ); ?></p>
		<?php elseif ( $done ) : ?>
			<h1><?php esc_html_e( 'You are unsubscribed', 'anchor-schema' ); ?></h1>
			<p><?php echo esc_html( sprintf( __( '%1$s will no longer receive announcements from %2$s.', 'anchor-schema' ), $email, $aa_site ) ); ?></p>
		<?php else : ?>
			<h1><?php esc_html_e( 'Unsubscribe?', 'anchor-schema' ); ?></h1>
			<p><?php echo esc_html( sprintf( __( 'Stop sending announcements from %2$s to %1$s?', 'anchor-schema' ), $email, $aa_site ) ); ?></p>
			<form method="post"><button type="submit"><?php esc_html_e( 'Unsubscribe', 'anchor-schema' ); ?></button></form>
		<?php endif; ?>
		<p><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo esc_html( $aa_site ); ?></a></p>
	</div>
</body>
</html>
```

- [ ] **Step 5: Wire it**

In `Module::__construct()`: `new Tracking\Endpoints();` (always, not only in admin).

- [ ] **Step 6: Run the tests**

Run: `vendor/bin/phpunit --filter Test_Announcements_Tracking`
Expected: PASS (6 tests).

- [ ] **Step 7: Commit**

```bash
git add anchor-announcements tests/test-announcements-tracking.php
git commit -m "Announcements: open pixel, click redirect and one-click unsubscribe endpoints

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 10: The send queue and mailer

**Files:**
- Create: `anchor-announcements/src/Sending/Queue.php`, `anchor-announcements/src/Sending/Mailer.php`
- Modify: `anchor-announcements/anchor-announcements.php` (`Sending\Queue::register()`)
- Test: `tests/test-announcements-queue.php`

**Interfaces:**
- Consumes: `Module::instance()->resolver()`, `Suppressions`, `Renderer::render()`, `LinkRewriter::links()`, `Settings::get()`, `PT` constants.
- Produces:
  - `Mailer::send( int $announcement_id, array $recipient, string $token, bool $track, string $subject_prefix = '' ): true|\WP_Error`, `Mailer::headers( string $unsubscribe_url ): array`.
  - `Queue::HOOK = 'anchor_announcements_tick'`, `Queue::LAST_RUN_OPTION = 'anchor_announcements_last_tick'`.
  - `Queue::register(): void` (one-minute schedule, event, action).
  - `Queue::start( int $id ): int|\WP_Error` (snapshot; returns queued count; errors `missing_address`, `wrong_state`, `empty_audience`, `empty_content`).
  - `Queue::schedule( int $id, int $timestamp ): true|\WP_Error`, `Queue::unschedule( int $id ): void`.
  - `Queue::pause( int $id ): void`, `Queue::resume( int $id ): void`, `Queue::cancel( int $id ): void`.
  - `Queue::claim( int $send_id ): bool` (queued to sending; false if already claimed).
  - `Queue::tick(): void`.
  - `Queue::test_send( int $id, string $email, ?array $override ): true|\WP_Error`.

- [ ] **Step 1: Write the failing tests**

```php
<?php
use Anchor\Announcements\Content\AnnouncementPostType as PT;
use Anchor\Announcements\Database\Migrations;
use Anchor\Announcements\Sending\Queue;
use Anchor\Announcements\Support\Settings;
use Anchor\Announcements\Suppression\Suppressions;

class Test_Announcements_Queue extends Anchor_Announcements_TestCase {

	/** @var array<int,array> captured wp_mail calls */
	private array $mails = [];
	private bool $fail = false;

	public function set_up() {
		parent::set_up();
		$this->mails = [];
		$this->fail  = false;
		Settings::save( [ 'footer_address' => '1 Main St', 'batch_size' => 2 ] );
		add_filter( 'pre_wp_mail', [ $this, 'capture' ], 10, 2 );
	}

	public function tear_down() {
		remove_filter( 'pre_wp_mail', [ $this, 'capture' ], 10 );
		parent::tear_down();
	}

	public function capture( $return, $atts ) {
		if ( $this->fail ) {
			return false;
		}
		$this->mails[] = $atts;
		return true;
	}

	private function audience( array $emails ): string {
		return wp_json_encode( [ 'groups' => [ [ 'conditions' => [ [ 'type' => 'specific_people', 'negate' => false, 'params' => [ 'emails' => implode( ',', $emails ) ] ] ] ] ] ] );
	}

	private function rows( int $id ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Migrations::table( 'sends' ) . ' WHERE announcement_id = %d ORDER BY email', $id ) );
	}

	public function test_start_snapshots_and_skips_suppressed() {
		Suppressions::add( 'b@x.com', 'unsubscribed' );
		$id = $this->make_announcement( [ PT::META_AUDIENCE => $this->audience( [ 'a@x.com', 'b@x.com', 'c@x.com' ] ) ] );
		$this->assertSame( 2, Queue::start( $id ) );
		$rows = $this->rows( $id );
		$this->assertSame( [ 'queued', 'skipped', 'queued' ], array_column( $rows, 'status' ) );
		$this->assertSame( 'suppressed', $rows[1]->skip_reason );
		$this->assertSame( PT::STATE_SENDING, PT::state( $id ) );
		$this->assertSame( [ 'https://example.com/page?a=1&b=2' ], get_post_meta( $id, PT::META_LINKS, true ) );
	}

	public function test_start_refuses_empty_audience_after_suppression() {
		Suppressions::add( 'only@x.com', 'unsubscribed' );
		$id  = $this->make_announcement( [ PT::META_AUDIENCE => $this->audience( [ 'only@x.com' ] ) ] );
		$res = Queue::start( $id );
		$this->assertWPError( $res );
		$this->assertSame( 'empty_audience', $res->get_error_code() );
		$this->assertSame( PT::STATE_DRAFT, PT::state( $id ) );
		$this->assertSame( [], $this->rows( $id ) );
	}

	public function test_start_requires_a_footer_address() {
		Settings::save( [ 'footer_address' => '' ] );
		$id = $this->make_announcement( [ PT::META_AUDIENCE => $this->audience( [ 'a@x.com' ] ) ] );
		$this->assertSame( 'missing_address', Queue::start( $id )->get_error_code() );
	}

	public function test_tick_sends_in_batches_then_marks_sent() {
		$id = $this->make_announcement( [ PT::META_AUDIENCE => $this->audience( [ 'a@x.com', 'b@x.com', 'c@x.com' ] ) ] );
		Queue::start( $id );
		Queue::tick();
		$this->assertCount( 2, $this->mails );
		Queue::tick();
		$this->assertCount( 3, $this->mails );
		$this->assertSame( [ 'sent', 'sent', 'sent' ], array_column( $this->rows( $id ), 'status' ) );
		$this->assertSame( PT::STATE_SENT, PT::state( $id ) );
		$this->assertNotEmpty( get_post_meta( $id, PT::META_SENT_AT, true ) );
		$this->assertNotEmpty( get_option( Queue::LAST_RUN_OPTION ) );
	}

	public function test_each_mail_is_personal_tracked_and_has_unsubscribe_headers() {
		$id = $this->make_announcement( [ PT::META_AUDIENCE => $this->audience( [ 'a@x.com' ] ) ] );
		Queue::start( $id );
		Queue::tick();
		$mail    = $this->mails[0];
		$headers = implode( "\n", (array) $mail['headers'] );
		$this->assertSame( 'a@x.com', $mail['to'] );
		$this->assertStringContainsString( 'anchor_aa=o', $mail['message'] );
		$this->assertStringContainsString( 'List-Unsubscribe: <', $headers );
		$this->assertStringContainsString( 'List-Unsubscribe-Post: List-Unsubscribe=One-Click', $headers );
		$this->assertStringContainsString( 'X-Mailgun-Track: no', $headers );
		$this->assertStringContainsString( 'Content-Type: text/html; charset=UTF-8', $headers );
	}

	public function test_a_claimed_row_cannot_be_claimed_again() {
		$id = $this->make_announcement( [ PT::META_AUDIENCE => $this->audience( [ 'a@x.com' ] ) ] );
		Queue::start( $id );
		$row = $this->rows( $id )[0];
		$this->assertTrue( Queue::claim( (int) $row->id ) );
		$this->assertFalse( Queue::claim( (int) $row->id ) );
	}

	public function test_failure_retries_then_fails() {
		$id = $this->make_announcement( [ PT::META_AUDIENCE => $this->audience( [ 'a@x.com' ] ) ] );
		Queue::start( $id );
		$this->fail = true;
		Queue::tick();
		$this->assertSame( 'queued', $this->rows( $id )[0]->status );
		Queue::tick();
		Queue::tick();
		$row = $this->rows( $id )[0];
		$this->assertSame( 'failed', $row->status );
		$this->assertSame( '3', (string) $row->attempts );
		$this->assertSame( PT::STATE_SENT, PT::state( $id ) );
	}

	public function test_pause_resume_cancel() {
		$id = $this->make_announcement( [ PT::META_AUDIENCE => $this->audience( [ 'a@x.com', 'b@x.com', 'c@x.com' ] ) ] );
		Queue::start( $id );
		Queue::pause( $id );
		Queue::tick();
		$this->assertCount( 0, $this->mails );
		Queue::resume( $id );
		Queue::tick();
		$this->assertCount( 2, $this->mails );
		Queue::cancel( $id );
		Queue::tick();
		$this->assertCount( 2, $this->mails );
		$this->assertSame( PT::STATE_CANCELLED, PT::state( $id ) );
		$this->assertContains( 'cancelled', array_column( $this->rows( $id ), 'skip_reason' ) );
	}

	public function test_schedule_releases_when_due() {
		$id = $this->make_announcement( [ PT::META_AUDIENCE => $this->audience( [ 'a@x.com' ] ) ] );
		$this->assertWPError( Queue::schedule( $id, time() - 60 ) );
		$this->assertTrue( Queue::schedule( $id, time() + 3600 ) );
		Queue::tick();
		$this->assertSame( PT::STATE_SCHEDULED, PT::state( $id ) );
		update_post_meta( $id, PT::META_SCHEDULED, time() - 1 );
		Queue::tick();
		$this->assertCount( 1, $this->mails );
	}

	public function test_test_send_is_untracked_and_prefixed() {
		$id = $this->make_announcement();
		$this->assertTrue( Queue::test_send( $id, 'me@x.com', null ) );
		$this->assertSame( 'me@x.com', $this->mails[0]['to'] );
		$this->assertStringStartsWith( '[Test] ', $this->mails[0]['subject'] );
		$this->assertStringNotContainsString( 'anchor_aa=o', $this->mails[0]['message'] );
		$this->assertSame( [], $this->rows( $id ) );
	}
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/phpunit --filter Test_Announcements_Queue`
Expected: FAIL, class not found.

- [ ] **Step 3: Implement the Mailer**

```php
<?php
declare(strict_types=1);

namespace Anchor\Announcements\Sending;

use Anchor\Announcements\Rendering\Renderer;
use Anchor\Announcements\Support\Settings;
use Anchor\Announcements\Tracking\Urls;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** Renders and hands one email to wp_mail(): whatever transport the site uses delivers it. */
final class Mailer {

	/** @return true|\WP_Error */
	public static function send( int $announcement_id, array $recipient, string $token, bool $track, string $subject_prefix = '' ) {
		$mail    = Renderer::render( $announcement_id, $recipient, $token, $track );
		$error   = null;
		$catch   = static function ( \WP_Error $e ) use ( &$error ) { $error = $e; };
		\add_action( 'wp_mail_failed', $catch );
		try {
			$ok = \wp_mail( (string) $recipient['email'], $subject_prefix . $mail['subject'], $mail['html'], self::headers( '' !== $token ? Urls::unsubscribe( $token ) : '' ) );
		} catch ( \Throwable $t ) {
			$ok    = false;
			$error = new \WP_Error( 'mail_exception', $t->getMessage() );
		} finally {
			\remove_action( 'wp_mail_failed', $catch );
		}
		return $ok ? true : ( $error ?? new \WP_Error( 'mail_failed', \__( 'wp_mail() returned false.', 'anchor-schema' ) ) );
	}

	public static function headers( string $unsubscribe_url ): array {
		$s       = Settings::get();
		$headers = [ 'Content-Type: text/html; charset=UTF-8' ];
		if ( '' !== $s['from_email'] ) {
			$headers[] = 'From: ' . \str_replace( [ "\r", "\n", '"' ], '', (string) $s['from_name'] ) . ' <' . $s['from_email'] . '>';
		}
		if ( '' !== $s['reply_to'] ) {
			$headers[] = 'Reply-To: ' . $s['reply_to'];
		}
		if ( '' !== $unsubscribe_url ) {
			$headers[] = 'List-Unsubscribe: <' . $unsubscribe_url . '>';
			$headers[] = 'List-Unsubscribe-Post: List-Unsubscribe=One-Click';
		}
		// Our own open/click tracking is already in the HTML; stop Mailgun rewriting it again. Other providers ignore this header.
		$headers[] = 'X-Mailgun-Track: no';

		/**
		 * Filter announcement mail headers.
		 *
		 * @param string[] $headers
		 */
		return (array) \apply_filters( 'anchor_announcements_mail_headers', $headers );
	}
}
```

- [ ] **Step 4: Implement the Queue**

```php
<?php
declare(strict_types=1);

namespace Anchor\Announcements\Sending;

use Anchor\Announcements\Content\AnnouncementPostType as PT;
use Anchor\Announcements\Database\Migrations;
use Anchor\Announcements\Module;
use Anchor\Announcements\Support\Settings;
use Anchor\Announcements\Suppression\Suppressions;
use Anchor\Announcements\Tracking\LinkRewriter;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/**
 * Snapshot the audience into send rows, then a one-minute WP-Cron tick sends a batch.
 * GET_LOCK keeps ticks from overlapping across connections; claim() keeps a row from
 * ever being sent twice even when the lock cannot (GET_LOCK is re-entrant for one
 * connection).
 */
final class Queue {

	public const HOOK            = 'anchor_announcements_tick';
	public const SCHEDULE        = 'anchor_announcements_minute';
	public const LAST_RUN_OPTION = 'anchor_announcements_last_tick';
	public const MAX_ATTEMPTS    = 3;
	private const STALE_CLAIM    = 600; // seconds before a crashed claim is retried

	public static function register(): void {
		\add_filter(
			'cron_schedules',
			static function ( $s ) {
				$s[ self::SCHEDULE ] = [ 'interval' => 60, 'display' => \__( 'Every minute (Anchor Announcements)', 'anchor-schema' ) ];
				return $s;
			}
		);
		\add_action( self::HOOK, [ self::class, 'tick' ] );
		\add_action(
			'init',
			static function () {
				if ( ! \wp_next_scheduled( self::HOOK ) ) {
					\wp_schedule_event( \time() + 60, self::SCHEDULE, self::HOOK );
				}
			}
		);
	}

	private static function table(): string {
		return Migrations::table( 'sends' );
	}

	/** @return int|\WP_Error */
	public static function start( int $id ) {
		$state = PT::state( $id );
		if ( ! \in_array( $state, [ PT::STATE_DRAFT, PT::STATE_SCHEDULED ], true ) ) {
			return new \WP_Error( 'wrong_state', \__( 'This announcement has already been sent.', 'anchor-schema' ) );
		}
		if ( '' === \trim( (string) Settings::get()['footer_address'] ) ) {
			return new \WP_Error( 'missing_address', \__( 'Add your mailing address in Announcements > Settings before sending.', 'anchor-schema' ) );
		}
		$body = (string) \get_post_meta( $id, PT::META_BODY, true );
		if ( '' === \trim( (string) \get_post_meta( $id, PT::META_SUBJECT, true ) ) || '' === \trim( \wp_strip_all_tags( $body ) ) ) {
			return new \WP_Error( 'empty_content', \__( 'Add a subject and a message before sending.', 'anchor-schema' ) );
		}

		$rules      = \json_decode( (string) \get_post_meta( $id, PT::META_AUDIENCE, true ), true );
		$recipients = Module::instance()->resolver()->resolve( \is_array( $rules ) ? $rules : [] )->all();
		$sendable   = \array_filter( $recipients, static fn( $r ) => ! Suppressions::is_suppressed( $r['email'] ) );
		if ( ! $sendable ) {
			return new \WP_Error( 'empty_audience', \__( 'Nobody matches this audience (after removing unsubscribed addresses).', 'anchor-schema' ) );
		}

		global $wpdb;
		$now    = \current_time( 'mysql', true );
		$queued = 0;
		foreach ( $recipients as $r ) {
			$suppressed = Suppressions::is_suppressed( $r['email'] );
			// user_id is a literal int or NULL: prepare() would turn a null into '' for a BIGINT column.
			$user_sql = $r['user_id'] > 0 ? (string) (int) $r['user_id'] : 'NULL';
			$wpdb->query(
				$wpdb->prepare(
					'INSERT IGNORE INTO ' . self::table() . " (announcement_id, email, user_id, name, token, status, skip_reason, queued_at) VALUES (%d, %s, {$user_sql}, %s, %s, %s, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$id,
					$r['email'],
					$r['name'],
					\bin2hex( \random_bytes( 16 ) ),
					$suppressed ? 'skipped' : 'queued',
					$suppressed ? 'suppressed' : '',
					$now
				)
			);
			$queued += $suppressed ? 0 : 1;
		}
		\update_post_meta( $id, PT::META_LINKS, LinkRewriter::links( \Anchor_Email_Sanitizer::body( $body ) ) );
		\update_post_meta( $id, PT::META_STATE, PT::STATE_SENDING );
		\delete_post_meta( $id, PT::META_SCHEDULED );
		return $queued;
	}

	/** @return true|\WP_Error */
	public static function schedule( int $id, int $timestamp ) {
		if ( $timestamp <= \time() ) {
			return new \WP_Error( 'past_time', \__( 'Pick a time in the future.', 'anchor-schema' ) );
		}
		if ( PT::STATE_DRAFT !== PT::state( $id ) && PT::STATE_SCHEDULED !== PT::state( $id ) ) {
			return new \WP_Error( 'wrong_state', \__( 'This announcement has already been sent.', 'anchor-schema' ) );
		}
		\update_post_meta( $id, PT::META_SCHEDULED, $timestamp );
		\update_post_meta( $id, PT::META_STATE, PT::STATE_SCHEDULED );
		return true;
	}

	public static function unschedule( int $id ): void {
		if ( PT::STATE_SCHEDULED === PT::state( $id ) ) {
			\delete_post_meta( $id, PT::META_SCHEDULED );
			\update_post_meta( $id, PT::META_STATE, PT::STATE_DRAFT );
		}
	}

	public static function pause( int $id ): void {
		if ( PT::STATE_SENDING === PT::state( $id ) ) {
			\update_post_meta( $id, PT::META_STATE, PT::STATE_PAUSED );
		}
	}

	public static function resume( int $id ): void {
		if ( PT::STATE_PAUSED === PT::state( $id ) ) {
			\update_post_meta( $id, PT::META_STATE, PT::STATE_SENDING );
		}
	}

	public static function cancel( int $id ): void {
		if ( ! \in_array( PT::state( $id ), [ PT::STATE_SENDING, PT::STATE_PAUSED, PT::STATE_SCHEDULED ], true ) ) {
			return;
		}
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET status = 'skipped', skip_reason = 'cancelled' WHERE announcement_id = %d AND status = 'queued'", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		\update_post_meta( $id, PT::META_STATE, PT::STATE_CANCELLED );
	}

	public static function claim( int $send_id ): bool {
		global $wpdb;
		return 1 === (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET status = 'sending', claimed_at = %s WHERE id = %d AND status = 'queued'", \current_time( 'mysql', true ), $send_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function tick(): void {
		global $wpdb;
		if ( 1 !== (int) $wpdb->get_var( "SELECT GET_LOCK('anchor_announcements_tick', 0)" ) ) {
			return;
		}
		try {
			\update_option( self::LAST_RUN_OPTION, \time(), false );
			self::release_scheduled();
			self::recover_stale_claims();
			self::send_batch();
			self::finish_done();
		} finally {
			$wpdb->query( "SELECT RELEASE_LOCK('anchor_announcements_tick')" );
		}
	}

	private static function release_scheduled(): void {
		$due = \get_posts(
			[
				'post_type'   => PT::CPT,
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
				'meta_query'  => [ // phpcs:ignore WordPress.DB.SlowDBQuery
					[ 'key' => PT::META_STATE, 'value' => PT::STATE_SCHEDULED ],
					[ 'key' => PT::META_SCHEDULED, 'value' => \time(), 'compare' => '<=', 'type' => 'NUMERIC' ],
				],
			]
		);
		foreach ( $due as $id ) {
			$res = self::start( (int) $id );
			if ( \is_wp_error( $res ) ) {
				\update_post_meta( (int) $id, PT::META_STATE, PT::STATE_DRAFT );
				\update_post_meta( (int) $id, '_aa_last_error', $res->get_error_message() );
			}
		}
	}

	private static function recover_stale_claims(): void {
		global $wpdb;
		$cutoff = \gmdate( 'Y-m-d H:i:s', \time() - self::STALE_CLAIM );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET status = 'queued' WHERE status = 'sending' AND claimed_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private static function send_batch(): void {
		global $wpdb;
		$sending = \get_posts(
			[
				'post_type'   => PT::CPT,
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
				'meta_key'    => PT::META_STATE, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'  => PT::STATE_SENDING, // phpcs:ignore WordPress.DB.SlowDBQuery
			]
		);
		if ( ! $sending ) {
			return;
		}
		$in   = \implode( ',', \array_map( 'intval', $sending ) );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE status = 'queued' AND announcement_id IN ({$in}) ORDER BY id ASC LIMIT %d", (int) Settings::get()['batch_size'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		foreach ( $rows as $row ) {
			if ( ! self::claim( (int) $row->id ) ) {
				continue;
			}
			$result = Mailer::send( (int) $row->announcement_id, [ 'email' => (string) $row->email, 'user_id' => (int) $row->user_id, 'name' => (string) $row->name ], (string) $row->token, true );
			$tries  = (int) $row->attempts + 1;
			if ( true === $result ) {
				$wpdb->update( self::table(), [ 'status' => 'sent', 'sent_at' => \current_time( 'mysql', true ), 'attempts' => $tries, 'error' => null ], [ 'id' => (int) $row->id ] );
			} else {
				$wpdb->update( self::table(), [ 'status' => $tries >= self::MAX_ATTEMPTS ? 'failed' : 'queued', 'attempts' => $tries, 'error' => $result->get_error_message() ], [ 'id' => (int) $row->id ] );
			}
		}
	}

	private static function finish_done(): void {
		global $wpdb;
		foreach ( \get_posts( [ 'post_type' => PT::CPT, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => PT::META_STATE, 'meta_value' => PT::STATE_SENDING ] ) as $id ) { // phpcs:ignore WordPress.DB.SlowDBQuery
			$left = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . " WHERE announcement_id = %d AND status IN ('queued','sending')", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( 0 === $left ) {
				\update_post_meta( (int) $id, PT::META_STATE, PT::STATE_SENT );
				\update_post_meta( (int) $id, PT::META_SENT_AT, \time() );
			}
		}
	}

	/** @return true|\WP_Error */
	public static function test_send( int $id, string $email, ?array $override ) {
		$email = \sanitize_email( $email );
		if ( ! \is_email( $email ) ) {
			return new \WP_Error( 'bad_email', \__( 'Enter a valid email address for the test.', 'anchor-schema' ) );
		}
		$user      = \get_user_by( 'email', $email );
		$recipient = $user ? [ 'email' => $email, 'user_id' => (int) $user->ID, 'name' => (string) $user->display_name ] : [ 'email' => $email, 'user_id' => 0, 'name' => '' ];
		if ( null !== $override ) {
			$mail = \Anchor\Announcements\Rendering\Renderer::render( $id, $recipient, '', false, $override );
			$ok   = \wp_mail( $email, '[Test] ' . $mail['subject'], $mail['html'], Mailer::headers( '' ) );
			return $ok ? true : new \WP_Error( 'mail_failed', \__( 'The test email could not be sent. Check the site\'s mail settings.', 'anchor-schema' ) );
		}
		return Mailer::send( $id, $recipient, '', false, '[Test] ' );
	}
}
```

- [ ] **Step 5: Wire it**

In `Module::__construct()`: `Sending\Queue::register();`

- [ ] **Step 6: Run the tests**

Run: `vendor/bin/phpunit --filter Test_Announcements_Queue`
Expected: PASS (10 tests).

- [ ] **Step 7: Commit**

```bash
git add anchor-announcements tests/test-announcements-queue.php
git commit -m "Announcements: audience snapshot, one-minute send queue with per-row claims, retries, schedule, pause and cancel

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 11: The editor screen: builder, audience builder, actions and AJAX

**Files:**
- Create: `anchor-announcements/src/Admin/Editor.php`, `anchor-announcements/src/Admin/Ajax.php`, `anchor-announcements/assets/admin.js`, `anchor-announcements/assets/admin.css`
- Modify: `anchor-announcements/anchor-announcements.php` (construct both in admin; `Ajax` also on `wp_doing_ajax()`)
- Test: `tests/test-announcements-editor.php`, `tests/test-announcements-ajax.php` (`@group ajax`)

**Interfaces:**
- Consumes: `Anchor_Email_Kit`, `Resolver`, `Registry::all()`, `Renderer`, `Queue`, `Suppressions`, `PT`.
- Produces:
  - Form field names: `anchor_announcement[subject]`, `[preheader]`, `[body]`, `[audience]` (JSON), `aa_action` (`save`, `send_now`, `schedule`, `unschedule`, `pause`, `resume`, `cancel`), `aa_schedule_at` (`Y-m-d\TH:i` in the site timezone), nonce field `aa_editor_nonce` for action `aa_editor`.
  - `Editor::save( int $post_id ): void` on `save_post_anchor_announcement`; `Editor::editable( int $id ): bool` (draft or scheduled); `Editor::notice( string $type, string $message ): void` / notices shown once via a per-user transient `aa_notice_{user_id}`; `Editor::describe( array $rules ): list<string>` (human-readable audience lines for locked announcements).
  - AJAX actions (all POST, nonce `anchor_announcements` in field `nonce`, capability `anchor_send_announcements`): `anchor_announcements_preview` (`post_id`, `subject`, `preheader`, `body` -> `{html}`), `anchor_announcements_audience` (`rules` JSON -> `{count, suppressed, sample: [{email,name}]}`), `anchor_announcements_search` (`kind` in `users|products|courses|events`, `q` -> `[{id,text}]`), `anchor_announcements_test_send` (`post_id`, `email`, `subject`, `preheader`, `body` -> `{message}`).
  - `Ajax::label( string $kind, int $id ): string` (title for a saved search chip).
  - JS global `ANCHOR_AA` = `{ ajaxUrl, nonce, editable, conditions: { key: { label, group, fields } }, labels: { kind: { id: text } }, i18n: {...} }`.

- [ ] **Step 1: Write the failing tests**

`tests/test-announcements-editor.php`:

```php
<?php
use Anchor\Announcements\Admin\Editor;
use Anchor\Announcements\Content\AnnouncementPostType as PT;
use Anchor\Announcements\Module;
use Anchor\Announcements\Support\Settings;

class Test_Announcements_Editor extends Anchor_Announcements_TestCase {

	private function post_save( int $id, array $fields, string $action = 'save', string $schedule = '' ): void {
		$_POST = [
			'aa_editor_nonce'      => wp_create_nonce( 'aa_editor' ),
			'anchor_announcement'  => $fields,
			'aa_action'            => $action,
			'aa_schedule_at'       => $schedule,
		];
		( new Editor() )->save( $id );
		$_POST = [];
	}

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		Settings::save( [ 'footer_address' => '1 Main St' ] );
		add_filter( 'pre_wp_mail', '__return_true' );
	}

	public function test_save_sanitizes_body_and_audience() {
		$id = $this->make_announcement();
		$this->post_save( $id, [
			'subject'   => 'Hi <b>{first_name}</b>',
			'preheader' => 'P',
			'body'      => '<p onclick="x()">Hi</p><script>bad()</script>',
			'audience'  => wp_json_encode( [ 'groups' => [ [ 'conditions' => [ [ 'type' => 'nope' ], [ 'type' => 'user_role', 'params' => [ 'roles' => [ 'subscriber' ] ] ] ] ] ] ] ),
		] );
		$this->assertSame( 'Hi {first_name}', get_post_meta( $id, PT::META_SUBJECT, true ) );
		$this->assertSame( '<p>Hi</p>bad()', get_post_meta( $id, PT::META_BODY, true ) );
		$saved = json_decode( get_post_meta( $id, PT::META_AUDIENCE, true ), true );
		$this->assertSame( 'user_role', $saved['groups'][0]['conditions'][0]['type'] );
		$this->assertCount( 1, $saved['groups'][0]['conditions'] );
	}

	public function test_user_without_capability_cannot_save() {
		$id = $this->make_announcement();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->post_save( $id, [ 'subject' => 'Changed', 'body' => '<p>x</p>', 'preheader' => '', 'audience' => '{}' ] );
		$this->assertSame( 'Hello {first_name}', get_post_meta( $id, PT::META_SUBJECT, true ) );
	}

	public function test_send_now_starts_the_queue_and_locks_content() {
		$this->make_user( 'r@x.com' );
		$id  = $this->make_announcement();
		$aud = wp_json_encode( [ 'groups' => [ [ 'conditions' => [ [ 'type' => 'specific_people', 'negate' => false, 'params' => [ 'emails' => 'r@x.com' ] ] ] ] ] ] );
		$this->post_save( $id, [ 'subject' => 'S', 'preheader' => '', 'body' => '<p>B</p>', 'audience' => $aud ], 'send_now' );
		$this->assertSame( PT::STATE_SENDING, PT::state( $id ) );
		$this->post_save( $id, [ 'subject' => 'Too late', 'preheader' => '', 'body' => '<p>B</p>', 'audience' => $aud ] );
		$this->assertSame( 'S', get_post_meta( $id, PT::META_SUBJECT, true ) );
		$this->assertFalse( Editor::editable( $id ) );
	}

	public function test_schedule_parses_site_time() {
		update_option( 'timezone_string', 'America/New_York' );
		$id = $this->make_announcement();
		$this->post_save( $id, [ 'subject' => 'S', 'preheader' => '', 'body' => '<p>B</p>', 'audience' => '{}' ], 'schedule', '2099-01-01T09:00' );
		$this->assertSame( PT::STATE_SCHEDULED, PT::state( $id ) );
		$this->assertSame( strtotime( '2099-01-01 14:00:00 UTC' ), (int) get_post_meta( $id, PT::META_SCHEDULED, true ) );
		update_option( 'timezone_string', '' );
	}

	public function test_errors_become_a_notice() {
		$id = $this->make_announcement();
		$this->post_save( $id, [ 'subject' => 'S', 'preheader' => '', 'body' => '<p>B</p>', 'audience' => '{}' ], 'send_now' );
		$notice = get_transient( 'aa_notice_' . get_current_user_id() );
		$this->assertSame( 'error', $notice['type'] );
		$this->assertSame( PT::STATE_DRAFT, PT::state( $id ) );
	}

	public function test_describe_reads_rules_in_plain_words() {
		$lines = Editor::describe( [ 'groups' => [
			[ 'conditions' => [ [ 'type' => 'user_role', 'negate' => false, 'params' => [ 'roles' => [ 'subscriber' ] ] ], [ 'type' => 'user_registered', 'negate' => true, 'params' => [ 'from' => '2026-01-01', 'to' => '' ] ] ] ],
			[ 'conditions' => [ [ 'type' => 'specific_people', 'negate' => false, 'params' => [ 'emails' => 'a@x.com' ] ] ] ],
		] ] );
		$this->assertSame( 'User role is subscriber AND NOT Account created from 2026-01-01', $lines[0] );
		$this->assertSame( 'OR Specific people is a@x.com', $lines[1] );
	}
}
```

`tests/test-announcements-ajax.php`:

```php
<?php
/**
 * @group ajax
 */
use Anchor\Announcements\Suppression\Suppressions;
use Anchor\Announcements\Support\Settings;

class Test_Announcements_Ajax extends WP_Ajax_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Settings::save( [ 'footer_address' => '1 Main St' ] );
		add_filter( 'pre_wp_mail', '__return_true' );
	}

	private function call( string $action, array $post ): array {
		$_POST = array_merge( [ 'nonce' => wp_create_nonce( 'anchor_announcements' ) ], $post );
		try {
			$this->_handleAjax( $action );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		} catch ( WPAjaxDieStopException $e ) {
			unset( $e );
		}
		return (array) json_decode( $this->_last_response, true );
	}

	private function as_admin(): void {
		$this->_setRole( 'administrator' );
	}

	public function test_editor_is_refused() {
		$this->_setRole( 'editor' );
		$res = $this->call( 'anchor_announcements_audience', [ 'rules' => '{}' ] );
		$this->assertFalse( $res['success'] );
	}

	public function test_audience_counts_after_suppression() {
		$this->as_admin();
		Suppressions::add( 'b@x.com', 'unsubscribed' );
		$rules = wp_json_encode( [ 'groups' => [ [ 'conditions' => [ [ 'type' => 'specific_people', 'negate' => false, 'params' => [ 'emails' => 'a@x.com,b@x.com' ] ] ] ] ] ] );
		$res   = $this->call( 'anchor_announcements_audience', [ 'rules' => $rules ] );
		$this->assertTrue( $res['success'] );
		$this->assertSame( 1, $res['data']['count'] );
		$this->assertSame( 1, $res['data']['suppressed'] );
		$this->assertSame( 'a@x.com', $res['data']['sample'][0]['email'] );
	}

	public function test_preview_renders_unsaved_content() {
		$this->as_admin();
		$id  = self::factory()->post->create( [ 'post_type' => 'anchor_announcement' ] );
		$res = $this->call( 'anchor_announcements_preview', [ 'post_id' => $id, 'subject' => 'S', 'preheader' => '', 'body' => '<p>Live {site_name}</p>' ] );
		$this->assertTrue( $res['success'] );
		$this->assertStringContainsString( 'Live ' . esc_html( get_bloginfo( 'name' ) ), $res['data']['html'] );
	}

	public function test_search_users() {
		$this->as_admin();
		self::factory()->user->create( [ 'user_email' => 'findme@x.com', 'display_name' => 'Find Me' ] );
		$res = $this->call( 'anchor_announcements_search', [ 'kind' => 'users', 'q' => 'findme' ] );
		$this->assertSame( 'Find Me (findme@x.com)', $res['data'][0]['text'] );
	}

	public function test_test_send() {
		$this->as_admin();
		$id  = self::factory()->post->create( [ 'post_type' => 'anchor_announcement' ] );
		$res = $this->call( 'anchor_announcements_test_send', [ 'post_id' => $id, 'email' => 'me@x.com', 'subject' => 'S', 'preheader' => '', 'body' => '<p>B</p>' ] );
		$this->assertTrue( $res['success'] );
	}
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/phpunit --filter Test_Announcements_Editor` and `vendor/bin/phpunit --group ajax --filter Test_Announcements_Ajax`
Expected: FAIL, class not found / actions not registered.

- [ ] **Step 3: Implement the Editor**

```php
<?php
declare(strict_types=1);

namespace Anchor\Announcements\Admin;

use Anchor\Announcements\Content\AnnouncementPostType as PT;
use Anchor\Announcements\Module;
use Anchor\Announcements\Rendering\Renderer;
use Anchor\Announcements\Sending\Queue;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** The announcement edit screen: builder, audience, actions, and the save/act handler. */
final class Editor {

	public function __construct() {
		\add_action( 'add_meta_boxes_' . PT::CPT, [ $this, 'boxes' ] );
		\add_action( 'save_post_' . PT::CPT, [ $this, 'save' ] );
		\add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
		\add_action( 'admin_notices', [ $this, 'show_notice' ] );
	}

	public static function editable( int $id ): bool {
		return \in_array( PT::state( $id ), [ PT::STATE_DRAFT, PT::STATE_SCHEDULED ], true );
	}

	public static function notice( string $type, string $message ): void {
		\set_transient( 'aa_notice_' . \get_current_user_id(), [ 'type' => $type, 'message' => $message ], 120 );
	}

	public function show_notice(): void {
		$key    = 'aa_notice_' . \get_current_user_id();
		$notice = \get_transient( $key );
		if ( \is_array( $notice ) ) {
			\delete_transient( $key );
			\printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', \esc_attr( 'error' === $notice['type'] ? 'error' : 'success' ), \esc_html( (string) $notice['message'] ) );
		}
	}

	public function boxes( \WP_Post $post ): void {
		\remove_meta_box( 'submitdiv', PT::CPT, 'side' );
		\add_meta_box( 'aa-actions', \__( 'Send', 'anchor-schema' ), [ $this, 'render_actions' ], PT::CPT, 'side', 'high' );
		\add_meta_box( 'aa-email', \__( 'Email', 'anchor-schema' ), [ $this, 'render_email' ], PT::CPT, 'normal', 'high' );
		\add_meta_box( 'aa-audience', \__( 'Audience', 'anchor-schema' ), [ $this, 'render_audience' ], PT::CPT, 'normal', 'default' );
	}

	public function assets( string $hook ): void {
		$screen = \get_current_screen();
		if ( ! $screen || PT::CPT !== $screen->post_type || ! \in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}
		$id       = (int) ( $_GET['post'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		$editable = 0 === $id || self::editable( $id );
		if ( $editable ) {
			\Anchor_Email_Kit::enqueue(
				[
					'ajaxUrl'       => \admin_url( 'admin-ajax.php' ),
					'nonce'         => \wp_create_nonce( 'anchor_announcements' ),
					'previewAction' => 'anchor_announcements_preview',
					'tokens'        => \Anchor_Email_Tokens::registered(),
					'emptyTokens'   => [ 'username', 'last_name' ],
				],
				PT::CPT
			);
		}
		$conditions = [];
		foreach ( Module::instance()->conditions->all() as $key => $c ) {
			$conditions[ $key ] = [ 'label' => $c->label(), 'group' => $c->group(), 'fields' => $c->fields() ];
		}
		$base = 'anchor-announcements/assets/';
		\wp_enqueue_style( 'anchor-announcements-admin', \Anchor_Asset_Loader::url( $base . 'admin.css' ), [], Module::VERSION );
		\wp_enqueue_script( 'anchor-announcements-admin', \Anchor_Asset_Loader::url( $base . 'admin.js' ), [ 'jquery' ], Module::VERSION, true );
		\wp_localize_script(
			'anchor-announcements-admin',
			'ANCHOR_AA',
			[
				'ajaxUrl'    => \admin_url( 'admin-ajax.php' ),
				'nonce'      => \wp_create_nonce( 'anchor_announcements' ),
				'postId'     => $id,
				'editable'   => $editable,
				'conditions' => $conditions,
				'labels'     => $id ? Ajax::labels_for( (string) \get_post_meta( $id, PT::META_AUDIENCE, true ) ) : new \stdClass(),
				'i18n'       => [
					'addCondition' => \__( '+ Add condition', 'anchor-schema' ),
					'addGroup'     => \__( '+ Add OR group', 'anchor-schema' ),
					'matchAll'     => \__( 'Match ALL of these', 'anchor-schema' ),
					'or'           => \__( 'OR', 'anchor-schema' ),
					'is'           => \__( 'is', 'anchor-schema' ),
					'isNot'        => \__( 'is not', 'anchor-schema' ),
					'remove'       => \__( 'Remove', 'anchor-schema' ),
					'onlyNegated'  => \__( 'This group only excludes people, so it starts from everyone the site knows.', 'anchor-schema' ),
					'recipients'   => \__( '%1$d recipients (%2$d unsubscribed left out)', 'anchor-schema' ),
					'confirmSend'  => \__( 'Send this announcement to %d people now?', 'anchor-schema' ),
					'nobody'       => \__( 'Nobody matches this audience yet.', 'anchor-schema' ),
					'search'       => \__( 'Search…', 'anchor-schema' ),
				],
			]
		);
	}

	public function render_email( \WP_Post $post ): void {
		\wp_nonce_field( 'aa_editor', 'aa_editor_nonce' );
		if ( self::editable( (int) $post->ID ) || 'auto-draft' === $post->post_status ) {
			echo \Anchor_Email_Kit::builder_markup( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapes its own fields.
				[
					'id'        => 'aa-email',
					'name'      => 'anchor_announcement',
					'subject'   => (string) \get_post_meta( $post->ID, PT::META_SUBJECT, true ),
					'preheader' => (string) \get_post_meta( $post->ID, PT::META_PREHEADER, true ),
					'body'      => (string) \get_post_meta( $post->ID, PT::META_BODY, true ),
				]
			);
			return;
		}
		$mail = Renderer::render( (int) $post->ID, Renderer::sample_recipient(), '', false );
		echo '<p><strong>' . \esc_html__( 'Subject:', 'anchor-schema' ) . '</strong> ' . \esc_html( $mail['subject'] ) . '</p>';
		echo '<iframe class="aa-sent-preview" sandbox="" srcdoc="' . \esc_attr( $mail['html'] ) . '"></iframe>';
	}

	public function render_audience( \WP_Post $post ): void {
		$raw = (string) \get_post_meta( $post->ID, PT::META_AUDIENCE, true );
		if ( ! self::editable( (int) $post->ID ) && 'auto-draft' !== $post->post_status ) {
			$rules = \json_decode( $raw, true );
			echo '<ul class="aa-audience-summary">';
			foreach ( self::describe( \is_array( $rules ) ? $rules : [] ) as $line ) {
				echo '<li>' . \esc_html( $line ) . '</li>';
			}
			echo '</ul>';
			return;
		}
		?>
		<input type="hidden" id="aa-audience-input" name="anchor_announcement[audience]" value="<?php echo \esc_attr( '' !== $raw ? $raw : '{"groups":[]}' ); ?>" />
		<div id="aa-audience-builder"></div>
		<p><button type="button" class="button" id="aa-audience-preview"><?php \esc_html_e( 'Preview audience', 'anchor-schema' ); ?></button> <span id="aa-audience-count" aria-live="polite"></span></p>
		<ol id="aa-audience-sample"></ol>
		<?php
	}

	public function render_actions( \WP_Post $post ): void {
		$id    = (int) $post->ID;
		$state = PT::state( $id );
		$me    = \wp_get_current_user();
		echo '<input type="hidden" name="post_status" value="publish" />';
		echo '<p class="aa-state aa-state--' . \esc_attr( $state ) . '">' . \esc_html( \ucfirst( $state ) ) . '</p>';
		$error = (string) \get_post_meta( $id, '_aa_last_error', true );
		if ( '' !== $error ) {
			echo '<p class="aa-error">' . \esc_html( $error ) . '</p>';
		}

		if ( self::editable( $id ) || 'auto-draft' === $post->post_status ) {
			?>
			<p><button type="submit" class="button button-secondary widefat" name="aa_action" value="save"><?php \esc_html_e( 'Save draft', 'anchor-schema' ); ?></button></p>
			<hr />
			<p><label for="aa-test-email"><?php \esc_html_e( 'Send a test to', 'anchor-schema' ); ?></label>
			<input type="email" class="widefat" id="aa-test-email" value="<?php echo \esc_attr( $me->user_email ); ?>" /></p>
			<p><button type="button" class="button widefat" id="aa-test-send"><?php \esc_html_e( 'Send test', 'anchor-schema' ); ?></button> <span id="aa-test-result" aria-live="polite"></span></p>
			<hr />
			<?php if ( PT::STATE_SCHEDULED === $state ) : ?>
				<p><?php echo \esc_html( \sprintf( \__( 'Scheduled for %s', 'anchor-schema' ), \wp_date( \get_option( 'date_format' ) . ' ' . \get_option( 'time_format' ), (int) \get_post_meta( $id, PT::META_SCHEDULED, true ) ) ) ); ?></p>
				<p><button type="submit" class="button widefat" name="aa_action" value="unschedule"><?php \esc_html_e( 'Unschedule', 'anchor-schema' ); ?></button></p>
			<?php else : ?>
				<p><label for="aa-schedule-at"><?php \esc_html_e( 'Send later', 'anchor-schema' ); ?></label>
				<input type="datetime-local" class="widefat" id="aa-schedule-at" name="aa_schedule_at" /></p>
				<p><button type="submit" class="button widefat" name="aa_action" value="schedule"><?php \esc_html_e( 'Schedule', 'anchor-schema' ); ?></button></p>
			<?php endif; ?>
			<p><button type="submit" class="button button-primary widefat" name="aa_action" value="send_now" id="aa-send-now"><?php \esc_html_e( 'Send now', 'anchor-schema' ); ?></button></p>
			<?php
			return;
		}
		if ( PT::STATE_SENDING === $state ) {
			echo '<p><button type="submit" class="button widefat" name="aa_action" value="pause">' . \esc_html__( 'Pause', 'anchor-schema' ) . '</button></p>';
		}
		if ( PT::STATE_PAUSED === $state ) {
			echo '<p><button type="submit" class="button button-primary widefat" name="aa_action" value="resume">' . \esc_html__( 'Resume', 'anchor-schema' ) . '</button></p>';
		}
		if ( \in_array( $state, [ PT::STATE_SENDING, PT::STATE_PAUSED ], true ) ) {
			echo '<p><button type="submit" class="button widefat" name="aa_action" value="cancel" onclick="return confirm(\'' . \esc_js( \__( 'Stop sending? People not yet emailed will not get it.', 'anchor-schema' ) ) . '\')">' . \esc_html__( 'Cancel sending', 'anchor-schema' ) . '</button></p>';
		}
		echo '<p><a href="#aa-report">' . \esc_html__( 'See the report', 'anchor-schema' ) . '</a></p>';
	}

	public function save( int $post_id ): void {
		if ( ( \defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || \wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST['aa_editor_nonce'] ) || ! \wp_verify_nonce( \sanitize_key( \wp_unslash( $_POST['aa_editor_nonce'] ) ), 'aa_editor' ) || ! \current_user_can( Module::CAP ) ) {
			return;
		}
		$fields = (array) \wp_unslash( $_POST['anchor_announcement'] ?? [] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized per key below.
		if ( self::editable( $post_id ) ) {
			\update_post_meta( $post_id, PT::META_SUBJECT, \sanitize_text_field( (string) ( $fields['subject'] ?? '' ) ) );
			\update_post_meta( $post_id, PT::META_PREHEADER, \sanitize_text_field( (string) ( $fields['preheader'] ?? '' ) ) );
			\update_post_meta( $post_id, PT::META_BODY, \Anchor_Email_Sanitizer::body( (string) ( $fields['body'] ?? '' ) ) );
			\update_post_meta( $post_id, PT::META_AUDIENCE, \wp_json_encode( Module::instance()->resolver()->sanitize( (string) ( $fields['audience'] ?? '' ) ) ) );
			\delete_post_meta( $post_id, '_aa_last_error' );
		}

		$action = \sanitize_key( \wp_unslash( $_POST['aa_action'] ?? 'save' ) );
		switch ( $action ) {
			case 'send_now':
				$res = Queue::start( $post_id );
				\is_wp_error( $res ) ? self::notice( 'error', $res->get_error_message() ) : self::notice( 'success', \sprintf( \__( 'Sending to %d people. Emails go out in batches every minute.', 'anchor-schema' ), $res ) );
				break;
			case 'schedule':
				$raw = \sanitize_text_field( \wp_unslash( $_POST['aa_schedule_at'] ?? '' ) );
				$dt  = \date_create_immutable_from_format( 'Y-m-d\TH:i', $raw, \wp_timezone() );
				$res = $dt ? Queue::schedule( $post_id, $dt->getTimestamp() ) : new \WP_Error( 'bad_time', \__( 'Pick a date and time to schedule.', 'anchor-schema' ) );
				\is_wp_error( $res ) ? self::notice( 'error', $res->get_error_message() ) : self::notice( 'success', \__( 'Scheduled.', 'anchor-schema' ) );
				break;
			case 'unschedule':
				Queue::unschedule( $post_id );
				break;
			case 'pause':
				Queue::pause( $post_id );
				break;
			case 'resume':
				Queue::resume( $post_id );
				break;
			case 'cancel':
				Queue::cancel( $post_id );
				break;
		}
	}

	/** @return list<string> */
	public static function describe( array $rules ): array {
		$all   = Module::instance()->conditions->all();
		$lines = [];
		foreach ( (array) ( $rules['groups'] ?? [] ) as $gi => $group ) {
			$parts = [];
			foreach ( (array) ( $group['conditions'] ?? [] ) as $c ) {
				$cond   = $all[ $c['type'] ?? '' ] ?? null;
				$label  = $cond ? $cond->label() : (string) ( $c['type'] ?? '' );
				$values = [];
				foreach ( (array) ( $c['params'] ?? [] ) as $k => $v ) {
					if ( '' === $v || [] === $v ) {
						continue;
					}
					$v        = \is_array( $v ) ? \implode( ', ', \array_map( 'strval', $v ) ) : (string) $v;
					$values[] = \in_array( $k, [ 'from', 'to' ], true ) ? $k . ' ' . $v : $v;
				}
				$dated   = \array_key_exists( 'from', (array) ( $c['params'] ?? [] ) ) || \array_key_exists( 'to', (array) ( $c['params'] ?? [] ) );
				$phrase  = $label . ( $dated ? ' ' : ' is ' ) . \implode( ' ', $values );
				$parts[] = ( ! empty( $c['negate'] ) ? 'NOT ' : '' ) . $phrase;
			}
			$lines[] = ( $gi > 0 ? 'OR ' : '' ) . \implode( ' AND ', $parts );
		}
		return $lines;
	}
}
```

`describe()` wording (the test pins it): a condition without `from`/`to` params reads `<label> is <values>`; a dated one reads `<label> from <date> to <date>`.

- [ ] **Step 4: Implement Ajax**

```php
<?php
declare(strict_types=1);

namespace Anchor\Announcements\Admin;

use Anchor\Announcements\Content\AnnouncementPostType as PT;
use Anchor\Announcements\Module;
use Anchor\Announcements\Rendering\Renderer;
use Anchor\Announcements\Sending\Queue;
use Anchor\Announcements\Suppression\Suppressions;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class Ajax {

	public function __construct() {
		foreach ( [ 'preview', 'audience', 'search', 'test_send' ] as $a ) {
			\add_action( 'wp_ajax_anchor_announcements_' . $a, [ $this, $a ] );
		}
	}

	private function guard(): void {
		if ( ! \check_ajax_referer( 'anchor_announcements', 'nonce', false ) || ! \current_user_can( Module::CAP ) ) {
			\wp_send_json_error( [ 'message' => \__( 'You are not allowed to do that.', 'anchor-schema' ) ], 403 );
		}
	}

	private function override(): array {
		// phpcs:disable WordPress.Security.NonceVerification -- guard() ran.
		return [
			'subject'   => \sanitize_text_field( \wp_unslash( $_POST['subject'] ?? '' ) ),
			'preheader' => \sanitize_text_field( \wp_unslash( $_POST['preheader'] ?? '' ) ),
			'body'      => (string) \wp_unslash( $_POST['body'] ?? '' ), // Renderer sanitizes.
		];
		// phpcs:enable
	}

	public function preview(): void {
		$this->guard();
		$id   = \absint( $_POST['post_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		$mail = Renderer::render( $id, Renderer::sample_recipient(), '', false, $this->override() );
		\wp_send_json_success( [ 'html' => $mail['html'], 'subject' => $mail['subject'] ] );
	}

	public function audience(): void {
		$this->guard();
		$resolver = Module::instance()->resolver();
		$rules    = $resolver->sanitize( (string) \wp_unslash( $_POST['rules'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- sanitize() validates the structure.
		$all      = $resolver->resolve( $rules )->all();
		$keep     = \array_values( \array_filter( $all, static fn( $r ) => ! Suppressions::is_suppressed( $r['email'] ) ) );
		\wp_send_json_success(
			[
				'count'      => \count( $keep ),
				'suppressed' => \count( $all ) - \count( $keep ),
				'sample'     => \array_map( static fn( $r ) => [ 'email' => $r['email'], 'name' => $r['name'] ], \array_slice( $keep, 0, 25 ) ),
			]
		);
	}

	public function search(): void {
		$this->guard();
		// phpcs:disable WordPress.Security.NonceVerification
		$kind = \sanitize_key( \wp_unslash( $_POST['kind'] ?? '' ) );
		$q    = \sanitize_text_field( \wp_unslash( $_POST['q'] ?? '' ) );
		// phpcs:enable
		$out = [];
		if ( 'users' === $kind ) {
			foreach ( \get_users( [ 'search' => '*' . $q . '*', 'search_columns' => [ 'user_login', 'user_email', 'display_name' ], 'number' => 20 ] ) as $u ) {
				$out[] = [ 'id' => (int) $u->ID, 'text' => $u->display_name . ' (' . $u->user_email . ')' ];
			}
		} else {
			$types = [ 'products' => [ 'product', 'product_variation' ], 'courses' => [ 'anchor_course' ], 'events' => [ 'event' ] ][ $kind ] ?? [];
			if ( $types ) {
				foreach ( \get_posts( [ 'post_type' => $types, 'post_status' => 'any', 's' => $q, 'numberposts' => 20 ] ) as $p ) {
					$out[] = [ 'id' => (int) $p->ID, 'text' => self::label( $kind, (int) $p->ID ) ];
				}
			}
		}
		\wp_send_json_success( $out );
	}

	public function test_send(): void {
		$this->guard();
		$id  = \absint( $_POST['post_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		$res = Queue::test_send( $id, (string) \wp_unslash( $_POST['email'] ?? '' ), $this->override() ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- test_send() validates the address.
		\is_wp_error( $res ) ? \wp_send_json_error( [ 'message' => $res->get_error_message() ] ) : \wp_send_json_success( [ 'message' => \__( 'Test sent.', 'anchor-schema' ) ] );
	}

	public static function label( string $kind, int $id ): string {
		if ( 'users' === $kind ) {
			$u = \get_userdata( $id );
			return $u ? $u->display_name . ' (' . $u->user_email . ')' : '#' . $id;
		}
		$title = \get_the_title( $id );
		return '' !== $title ? \html_entity_decode( $title, ENT_QUOTES ) . ' (#' . $id . ')' : '#' . $id;
	}

	/** Chip labels for the ids already saved in an audience. @return array<string,array<int,string>> */
	public static function labels_for( string $raw ): array {
		$rules = \json_decode( $raw, true );
		$all   = Module::instance()->conditions->all();
		$out   = [];
		foreach ( (array) ( \is_array( $rules ) ? ( $rules['groups'] ?? [] ) : [] ) as $g ) {
			foreach ( (array) ( $g['conditions'] ?? [] ) as $c ) {
				$cond = $all[ $c['type'] ?? '' ] ?? null;
				if ( ! $cond ) { continue; }
				foreach ( $cond->fields() as $f ) {
					if ( 'search' !== $f['type'] ) { continue; }
					foreach ( (array) ( $c['params'][ $f['key'] ] ?? [] ) as $id ) {
						$out[ $f['search'] ][ (int) $id ] = self::label( $f['search'], (int) $id );
					}
				}
			}
		}
		return $out;
	}
}
```

- [ ] **Step 5: Implement admin.js (audience builder and actions)**

`anchor-announcements/assets/admin.js`:

```js
/**
 * Announcements edit screen: the audience rule builder (groups OR'd, conditions AND'd,
 * "is / is not"), audience preview, test send, and the send confirmation. The rules
 * live as JSON in #aa-audience-input, which the post form saves.
 */
(function ($) {
  'use strict';

  var cfg = window.ANCHOR_AA || {};
  var t = cfg.i18n || {};
  var conditions = cfg.conditions || {};
  var labels = cfg.labels || {};
  var $input, $root, state;

  function post(action, data) {
    return $.post(cfg.ajaxUrl, $.extend({ action: 'anchor_announcements_' + action, nonce: cfg.nonce }, data));
  }

  function fmt(str) {
    var args = Array.prototype.slice.call(arguments, 1);
    return str.replace(/%(\d)\$d|%d/g, function (m, n) { return n ? args[n - 1] : args.shift(); });
  }

  function load() {
    try { state = JSON.parse($input.val() || '{}'); } catch (e) { state = {}; }
    if (!state.groups) { state.groups = []; }
  }

  function save() { $input.val(JSON.stringify(state)); }

  function defaults(type) {
    var p = {};
    ((conditions[type] || {}).fields || []).forEach(function (f) {
      if (f.default !== undefined) { p[f.key] = f.default; }
    });
    return p;
  }

  function firstType() { return Object.keys(conditions)[0]; }

  function typeSelect(current) {
    var $s = $('<select class="aa-cond-type"/>');
    var groups = {};
    Object.keys(conditions).forEach(function (k) {
      var g = conditions[k].group;
      if (!groups[g]) { groups[g] = $('<optgroup/>').attr('label', g).appendTo($s); }
      groups[g].append($('<option/>').val(k).text(conditions[k].label).prop('selected', k === current));
    });
    return $s;
  }

  function field(f, c) {
    var $wrap = $('<label class="aa-field"/>').append($('<span class="aa-field__label"/>').text(f.label));
    var val = c.params[f.key];
    var set = function (v) { c.params[f.key] = v; save(); };
    var $el;
    switch (f.type) {
      case 'multiselect':
        $el = $('<select multiple/>');
        $.each(f.options || {}, function (k, lbl) {
          $el.append($('<option/>').val(k).text(lbl).prop('selected', (val || []).indexOf(k) !== -1));
        });
        $el.on('change', function () { set($(this).val() || []); });
        break;
      case 'select':
        $el = $('<select/>');
        $.each(f.options || {}, function (k, lbl) { $el.append($('<option/>').val(k).text(lbl).prop('selected', val === k)); });
        $el.on('change', function () { set($(this).val()); });
        break;
      case 'textarea':
        $el = $('<textarea rows="3"/>').val(val || '').on('input', function () { set($(this).val()); });
        break;
      case 'search':
        return searchField(f, c, $wrap);
      default:
        $el = $('<input/>').attr('type', f.type === 'number' ? 'number' : (f.type === 'date' ? 'date' : 'text')).val(val === undefined ? '' : val)
          .on('input change', function () { set(f.type === 'number' ? Number($(this).val()) : $(this).val()); });
    }
    return $wrap.append($el);
  }

  function searchField(f, c, $wrap) {
    var kind = f.search;
    labels[kind] = labels[kind] || {};
    c.params[f.key] = (c.params[f.key] || []).map(Number);
    var $chips = $('<span class="aa-chips"/>');
    var $q = $('<input type="search" class="aa-search"/>').attr('placeholder', t.search || 'Search');
    var $results = $('<ul class="aa-results"/>');
    var timer;

    function chips() {
      $chips.empty();
      c.params[f.key].forEach(function (id) {
        $('<span class="aa-chip"/>').text(labels[kind][id] || ('#' + id))
          .append($('<button type="button" class="aa-chip__x" aria-label="Remove">&times;</button>').on('click', function () {
            c.params[f.key] = c.params[f.key].filter(function (x) { return x !== id; });
            save(); chips();
          }))
          .appendTo($chips);
      });
    }

    $q.on('input', function () {
      clearTimeout(timer);
      var q = $q.val();
      if (q.length < 2) { $results.empty(); return; }
      timer = setTimeout(function () {
        post('search', { kind: kind, q: q }).done(function (res) {
          $results.empty();
          ((res && res.data) || []).forEach(function (item) {
            $('<li/>').append($('<button type="button"/>').text(item.text).on('click', function () {
              labels[kind][item.id] = item.text;
              if (c.params[f.key].indexOf(item.id) === -1) { c.params[f.key].push(item.id); }
              save(); chips(); $results.empty(); $q.val('');
            })).appendTo($results);
          });
        });
      }, 300);
    });

    chips();
    return $wrap.append($chips, $q, $results);
  }

  function conditionRow(g, c, ci) {
    var $row = $('<div class="aa-cond"/>');
    var $type = typeSelect(c.type).on('change', function () {
      c.type = $(this).val(); c.params = defaults(c.type); render();
    });
    var $neg = $('<select class="aa-cond-neg"/>')
      .append($('<option value="0"/>').text(t.is || 'is').prop('selected', !c.negate))
      .append($('<option value="1"/>').text(t.isNot || 'is not').prop('selected', !!c.negate))
      .on('change', function () { c.negate = $(this).val() === '1'; save(); render(); });
    var $fields = $('<div class="aa-cond-fields"/>');
    ((conditions[c.type] || {}).fields || []).forEach(function (f) { $fields.append(field(f, c)); });
    var $rm = $('<button type="button" class="button-link aa-remove"/>').text(t.remove || 'Remove').on('click', function () {
      g.conditions.splice(ci, 1); render();
    });
    return $row.append($('<div class="aa-cond-head"/>').append($neg, $type, $rm), $fields);
  }

  function render() {
    $root.empty();
    state.groups = state.groups.filter(function (g) { return g.conditions.length; });
    state.groups.forEach(function (g, gi) {
      if (gi > 0) { $root.append($('<div class="aa-or"/>').text(t.or || 'OR')); }
      var $g = $('<div class="aa-group"/>').append($('<p class="aa-group__head"/>').text(t.matchAll || 'Match ALL of these'));
      g.conditions.forEach(function (c, ci) { $g.append(conditionRow(g, c, ci)); });
      if (g.conditions.every(function (c) { return c.negate; })) {
        $g.append($('<p class="description"/>').text(t.onlyNegated));
      }
      $g.append($('<button type="button" class="button"/>').text(t.addCondition).on('click', function () {
        var type = firstType();
        g.conditions.push({ type: type, negate: false, params: defaults(type) }); render();
      }));
      $root.append($g);
    });
    $root.append($('<button type="button" class="button aa-add-group"/>').text(t.addGroup).on('click', function () {
      var type = firstType();
      state.groups.push({ conditions: [{ type: type, negate: false, params: defaults(type) }] }); render();
    }));
    save();
  }

  function previewAudience() {
    return post('audience', { rules: $input.val() }).then(function (res) {
      var d = (res && res.data) || { count: 0, suppressed: 0, sample: [] };
      $('#aa-audience-count').text(d.count ? fmt(t.recipients, d.count, d.suppressed) : t.nobody);
      var $list = $('#aa-audience-sample').empty();
      d.sample.forEach(function (r) { $('<li/>').text(r.name ? r.name + ' <' + r.email + '>' : r.email).appendTo($list); });
      return d.count;
    });
  }

  function builderFields() {
    if (window.tinymce) { window.tinymce.triggerSave(); }
    return {
      post_id: cfg.postId || $('#post_ID').val(),
      subject: $('.anchor-email-builder__subject').val() || '',
      preheader: $('.anchor-email-builder__preheader').val() || '',
      body: $('.anchor-email-builder__body').val() || ''
    };
  }

  $(function () {
    if (cfg.postId === 0) { cfg.postId = Number($('#post_ID').val()) || 0; }
    $input = $('#aa-audience-input');
    $root = $('#aa-audience-builder');
    if ($input.length && cfg.editable) { load(); render(); }

    $('#aa-audience-preview').on('click', previewAudience);

    // Report search (Task 12): the report box is inside the post form, so search navigates instead of submitting.
    $('#aa-report-search-go').on('click', function () {
      var base = $(this).data('base');
      window.location = base + (base.indexOf('?') === -1 ? '?' : '&') + 'aa_s=' + encodeURIComponent($('#aa-report-search').val()) + '#aa-report';
    });
    $('#aa-report-search').on('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); $('#aa-report-search-go').trigger('click'); }
    });

    $('#aa-test-send').on('click', function () {
      var $out = $('#aa-test-result').text('…');
      post('test_send', $.extend(builderFields(), { email: $('#aa-test-email').val() })).done(function (res) {
        $out.text((res && res.data && res.data.message) || '');
      }).fail(function (xhr) {
        $out.text((xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || 'Error');
      });
    });

    $('#aa-send-now').on('click', function (e) {
      var $btn = $(this);
      if ($btn.data('confirmed')) { return; }
      e.preventDefault();
      previewAudience().then(function (count) {
        if (count && window.confirm(fmt(t.confirmSend, count))) {
          $btn.data('confirmed', true);
          $('<input type="hidden" name="aa_action" value="send_now"/>').appendTo($btn.closest('form'));
          $btn.closest('form').trigger('submit');
        }
      });
    });
  });
})(jQuery);
```

- [ ] **Step 6: admin.css**

```css
#aa-audience-builder{display:flex;flex-direction:column;gap:10px;margin-bottom:12px}
.aa-group{border:1px solid #c3c4c7;border-radius:6px;padding:12px;background:#fff}
.aa-group__head{margin:0 0 8px;font-weight:600}
.aa-or{align-self:center;font-weight:700;color:#1a4f48;letter-spacing:.08em}
.aa-cond{border-top:1px solid #f0f0f1;padding:10px 0}
.aa-cond:first-of-type{border-top:0}
.aa-cond-head{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.aa-cond-fields{display:flex;flex-wrap:wrap;gap:12px;margin-top:8px}
.aa-field{display:flex;flex-direction:column;gap:4px;min-width:180px}
.aa-field__label{font-size:12px;color:#50575e}
.aa-field select[multiple]{min-height:90px}
.aa-chips{display:flex;flex-wrap:wrap;gap:4px}
.aa-chip{background:#f0f6f5;border:1px solid #c5ddd9;border-radius:12px;padding:2px 4px 2px 10px}
.aa-chip__x{border:0;background:none;cursor:pointer}
.aa-results{margin:0;max-height:180px;overflow:auto}
.aa-results button{background:none;border:0;padding:4px;text-align:left;cursor:pointer;width:100%}
.aa-results button:hover{background:#f0f6f5}
.aa-state{font-weight:700;text-transform:uppercase;letter-spacing:.06em}
.aa-error{color:#b32d2e}
.aa-sent-preview{width:100%;height:640px;border:1px solid #c3c4c7}
#aa-audience-sample{max-height:240px;overflow:auto}
```

- [ ] **Step 7: Wire it**

In `Module::__construct()`, replace the `if ( \is_admin() )` block with:

```php
		if ( \is_admin() ) {
			new Admin\SettingsPage();
			new Admin\Editor();
			new Admin\Ajax();
		}
```

(`is_admin()` is true for admin-ajax.php requests too.)

- [ ] **Step 8: Run the tests**

Run: `vendor/bin/phpunit --filter Test_Announcements_Editor && vendor/bin/phpunit --group ajax --filter Test_Announcements_Ajax`
Expected: PASS (6 + 5 tests).

- [ ] **Step 9: Manual check in a browser**

Start wp-env (`npm install && npm run wp-env start`), enable the module (Settings > Anchor Tools), fill Announcements > Settings > Mailing address, open Announcements > Add New. Check: the builder's preview renders and updates while typing; tokens insert in both tabs; the audience builder adds conditions and OR groups, product/user search works, "is not" toggles; Preview audience shows a count; Send test reports "Test sent." Fix anything broken before committing. Screenshot the screen for the PR.

- [ ] **Step 10: Commit**

```bash
git add anchor-announcements tests/test-announcements-editor.php tests/test-announcements-ajax.php
git commit -m "Announcements: editor screen (builder, AND/OR audience builder, test send, schedule, send, pause, cancel)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 12: Reports, list columns, CSV and the suppressions screen

**Files:**
- Create: `anchor-announcements/src/Admin/Reports.php`, `anchor-announcements/src/Admin/ListColumns.php`, `anchor-announcements/src/Admin/SuppressionsPage.php`
- Modify: `anchor-announcements/anchor-announcements.php` (construct the three in admin)
- Test: `tests/test-announcements-reports.php`

**Interfaces:**
- Consumes: `sends`/`events` tables, `PT::META_LINKS`, `Suppressions`, `Queue::LAST_RUN_OPTION`.
- Produces:
  - `Reports::stats( int $id ): array` keys `total`, `queued` (queued + sending), `sent`, `failed`, `skipped`, `opened`, `clicked`, `unsubscribed`, `open_rate`, `click_rate` (floats 0..1 over `sent`).
  - `Reports::recipients( int $id, string $filter = 'all', string $search = '', int $page = 1, int $per_page = 50 ): array{rows:array,total:int}`; filters `all`, `opened`, `not_opened` (sent and never opened), `clicked`, `failed`, `skipped`, `unsubscribed`.
  - `Reports::links( int $id ): list<array{url:string,clicks:int,unique:int}>` (scanner clicks excluded).
  - `Reports::csv_rows( int $id ): list<array>` (header row first).
  - Metabox `aa-report` on non-draft announcements; `admin_post_anchor_announcements_csv`.

- [ ] **Step 1: Write the failing tests**

```php
<?php
use Anchor\Announcements\Admin\Reports;
use Anchor\Announcements\Content\AnnouncementPostType as PT;
use Anchor\Announcements\Database\Migrations;
use Anchor\Announcements\Tracking\Endpoints;

class Test_Announcements_Reports extends Anchor_Announcements_TestCase {

	private function row( int $id, string $email, string $status, int $opens = 0, int $clicks = 0 ): object {
		global $wpdb;
		$wpdb->insert( Migrations::table( 'sends' ), [ 'announcement_id' => $id, 'email' => $email, 'name' => ucfirst( strtok( $email, '@' ) ), 'token' => bin2hex( random_bytes( 16 ) ), 'status' => $status, 'queued_at' => '2026-01-01 00:00:00', 'sent_at' => 'sent' === $status ? '2026-01-01 00:00:00' : null ] );
		$send = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Migrations::table( 'sends' ) . ' WHERE id = %d', $wpdb->insert_id ) );
		for ( $i = 0; $i < $opens; $i++ ) { Endpoints::record( $send, 'open' ); }
		for ( $i = 0; $i < $clicks; $i++ ) { Endpoints::record( $send, 'click', 0 ); }
		return $send;
	}

	public function test_stats_and_rates() {
		$id = $this->make_announcement( [ PT::META_STATE => PT::STATE_SENT ] );
		$this->row( $id, 'a@x.com', 'sent', 2, 1 );
		$this->row( $id, 'b@x.com', 'sent', 1 );
		$this->row( $id, 'c@x.com', 'sent' );
		$this->row( $id, 'd@x.com', 'sent' );
		$this->row( $id, 'e@x.com', 'failed' );
		$u = $this->row( $id, 'f@x.com', 'skipped' );
		Endpoints::unsubscribe( $this->row( $id, 'g@x.com', 'sent' ) );
		$s = Reports::stats( $id );
		$this->assertSame( 7, $s['total'] );
		$this->assertSame( 5, $s['sent'] );
		$this->assertSame( 1, $s['failed'] );
		$this->assertSame( 1, $s['skipped'] );
		$this->assertSame( 2, $s['opened'] );
		$this->assertSame( 1, $s['clicked'] );
		$this->assertSame( 1, $s['unsubscribed'] );
		$this->assertEqualsWithDelta( 0.4, $s['open_rate'], 0.0001 );
		$this->assertEqualsWithDelta( 0.2, $s['click_rate'], 0.0001 );
		unset( $u );
	}

	public function test_recipient_filters_and_search() {
		$id = $this->make_announcement( [ PT::META_STATE => PT::STATE_SENT ] );
		$this->row( $id, 'opened@x.com', 'sent', 1 );
		$this->row( $id, 'quiet@x.com', 'sent' );
		$this->row( $id, 'broken@x.com', 'failed' );
		$this->assertSame( [ 'opened@x.com' ], array_column( Reports::recipients( $id, 'opened' )['rows'], 'email' ) );
		$this->assertSame( [ 'quiet@x.com' ], array_column( Reports::recipients( $id, 'not_opened' )['rows'], 'email' ) );
		$this->assertSame( [ 'broken@x.com' ], array_column( Reports::recipients( $id, 'failed' )['rows'], 'email' ) );
		$this->assertSame( 1, Reports::recipients( $id, 'all', 'quiet' )['total'] );
	}

	public function test_links_exclude_scanner_clicks() {
		$id = $this->make_announcement( [ PT::META_STATE => PT::STATE_SENT ] );
		update_post_meta( $id, PT::META_LINKS, [ 'https://one.test/', 'https://two.test/' ] );
		$a = $this->row( $id, 'a@x.com', 'sent', 0, 2 );
		$this->row( $id, 'b@x.com', 'sent', 0, 1 );
		Endpoints::record( $a, 'click', 1, true );
		$links = Reports::links( $id );
		$this->assertSame( [ 'url' => 'https://one.test/', 'clicks' => 3, 'unique' => 2 ], $links[0] );
		$this->assertSame( [ 'url' => 'https://two.test/', 'clicks' => 0, 'unique' => 0 ], $links[1] );
	}

	public function test_csv_rows() {
		$id = $this->make_announcement( [ PT::META_STATE => PT::STATE_SENT ] );
		$this->row( $id, 'a@x.com', 'sent', 1 );
		$rows = Reports::csv_rows( $id );
		$this->assertSame( [ 'name', 'email', 'status', 'skip_reason', 'error', 'sent_at', 'first_opened_at', 'open_count', 'first_clicked_at', 'click_count', 'unsubscribed' ], $rows[0] );
		$this->assertSame( 'a@x.com', $rows[1][1] );
		$this->assertSame( '1', (string) $rows[1][7] );
	}

	public function test_csv_neutralises_formula_injection() {
		$id = $this->make_announcement( [ PT::META_STATE => PT::STATE_SENT ] );
		global $wpdb;
		$wpdb->insert( Migrations::table( 'sends' ), [ 'announcement_id' => $id, 'email' => 'f@x.com', 'name' => '=HYPERLINK("x")', 'token' => bin2hex( random_bytes( 16 ) ), 'status' => 'sent', 'queued_at' => '2026-01-01 00:00:00' ] );
		$this->assertSame( "'=HYPERLINK(\"x\")", Reports::csv_rows( $id )[1][0] );
	}
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/phpunit --filter Test_Announcements_Reports`
Expected: FAIL, class not found.

- [ ] **Step 3: Implement Reports**

```php
<?php
declare(strict_types=1);

namespace Anchor\Announcements\Admin;

use Anchor\Announcements\Content\AnnouncementPostType as PT;
use Anchor\Announcements\Database\Migrations;
use Anchor\Announcements\Module;
use Anchor\Announcements\Sending\Queue;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** Totals, per-recipient and per-link reporting for one announcement. */
final class Reports {

	public function __construct() {
		\add_action( 'add_meta_boxes_' . PT::CPT, [ $this, 'box' ] );
		\add_action( 'admin_post_anchor_announcements_csv', [ $this, 'csv' ] );
	}

	private static function sends(): string { return Migrations::table( 'sends' ); }
	private static function events(): string { return Migrations::table( 'events' ); }

	public static function stats( int $id ): array {
		global $wpdb;
		$s   = self::sends();
		$e   = self::events();
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) total,
					SUM(status IN ('queued','sending')) queued,
					SUM(status = 'sent') sent,
					SUM(status = 'failed') failed,
					SUM(status = 'skipped') skipped,
					SUM(status = 'sent' AND open_count > 0) opened,
					SUM(status = 'sent' AND click_count > 0) clicked
				FROM {$s} WHERE announcement_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$id
			),
			ARRAY_A
		);
		$unsub = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT ev.send_id) FROM {$e} ev JOIN {$s} se ON se.id = ev.send_id WHERE se.announcement_id = %d AND ev.type = 'unsubscribe'", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out   = \array_map( 'intval', (array) $row );
		$out  += [ 'total' => 0, 'queued' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'opened' => 0, 'clicked' => 0 ];
		$out['unsubscribed'] = $unsub;
		$out['open_rate']    = $out['sent'] ? $out['opened'] / $out['sent'] : 0.0;
		$out['click_rate']   = $out['sent'] ? $out['clicked'] / $out['sent'] : 0.0;
		return $out;
	}

	public static function recipients( int $id, string $filter = 'all', string $search = '', int $page = 1, int $per_page = 50 ): array {
		global $wpdb;
		$s     = self::sends();
		$e     = self::events();
		$where = [ $wpdb->prepare( 'announcement_id = %d', $id ) ];
		$map   = [
			'opened'       => "status = 'sent' AND open_count > 0",
			'not_opened'   => "status = 'sent' AND open_count = 0",
			'clicked'      => 'click_count > 0',
			'failed'       => "status = 'failed'",
			'skipped'      => "status = 'skipped'",
			'unsubscribed' => "id IN (SELECT send_id FROM {$e} WHERE type = 'unsubscribe')",
		];
		if ( isset( $map[ $filter ] ) ) {
			$where[] = $map[ $filter ];
		}
		if ( '' !== $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = $wpdb->prepare( '(email LIKE %s OR name LIKE %s)', $like, $like );
		}
		$w     = \implode( ' AND ', $where );
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$s} WHERE {$w}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$s} WHERE {$w} ORDER BY email LIMIT %d OFFSET %d", $per_page, \max( 0, $page - 1 ) * $per_page ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return [ 'rows' => $rows, 'total' => $total ];
	}

	public static function links( int $id ): array {
		global $wpdb;
		$s      = self::sends();
		$e      = self::events();
		$counts = $wpdb->get_results( $wpdb->prepare( "SELECT ev.link_index, COUNT(*) clicks, COUNT(DISTINCT ev.send_id) uniq FROM {$e} ev JOIN {$s} se ON se.id = ev.send_id WHERE se.announcement_id = %d AND ev.type = 'click' AND ev.scanner = 0 GROUP BY ev.link_index", $id ), OBJECT_K ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out    = [];
		foreach ( (array) \get_post_meta( $id, PT::META_LINKS, true ) as $i => $url ) {
			$out[] = [ 'url' => (string) $url, 'clicks' => (int) ( $counts[ $i ]->clicks ?? 0 ), 'unique' => (int) ( $counts[ $i ]->uniq ?? 0 ) ];
		}
		return $out;
	}

	public static function csv_rows( int $id ): array {
		global $wpdb;
		$s     = self::sends();
		$e     = self::events();
		$rows  = [ [ 'name', 'email', 'status', 'skip_reason', 'error', 'sent_at', 'first_opened_at', 'open_count', 'first_clicked_at', 'click_count', 'unsubscribed' ] ];
		$unsub = \array_flip( \array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ev.send_id FROM {$e} ev JOIN {$s} se ON se.id = ev.send_id WHERE se.announcement_id = %d AND ev.type = 'unsubscribe'", $id ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$cell  = static fn( $v ) => \preg_match( '/^[=+\-@\t\r]/', (string) $v ) ? "'" . $v : (string) $v;
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$s} WHERE announcement_id = %d ORDER BY email", $id ) ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows[] = \array_map( $cell, [ $r->name, $r->email, $r->status, $r->skip_reason, (string) $r->error, (string) $r->sent_at, (string) $r->first_opened_at, (string) $r->open_count, (string) $r->first_clicked_at, (string) $r->click_count, isset( $unsub[ (int) $r->id ] ) ? 'yes' : '' ] );
		}
		return $rows;
	}

	public function csv(): void {
		$id = \absint( $_GET['post'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification -- checked next line.
		if ( ! \current_user_can( Module::CAP ) || ! \wp_verify_nonce( \sanitize_key( \wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'aa_csv_' . $id ) ) {
			\wp_die( \esc_html__( 'You are not allowed to do that.', 'anchor-schema' ), 403 );
		}
		\nocache_headers();
		\header( 'Content-Type: text/csv; charset=utf-8' );
		\header( 'Content-Disposition: attachment; filename="announcement-' . $id . '-recipients.csv"' );
		$out = \fopen( 'php://output', 'w' );
		foreach ( self::csv_rows( $id ) as $row ) {
			\fputcsv( $out, $row );
		}
		\fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	public function box( \WP_Post $post ): void {
		if ( \in_array( PT::state( (int) $post->ID ), [ PT::STATE_DRAFT, PT::STATE_SCHEDULED ], true ) ) {
			return;
		}
		\add_meta_box( 'aa-report', \__( 'Report', 'anchor-schema' ), [ $this, 'render' ], PT::CPT, 'normal', 'high' );
	}

	public function render( \WP_Post $post ): void {
		// phpcs:disable WordPress.Security.NonceVerification -- read-only filters.
		$id     = (int) $post->ID;
		$s      = self::stats( $id );
		$filter = \sanitize_key( \wp_unslash( $_GET['aa_filter'] ?? 'all' ) );
		$search = \sanitize_text_field( \wp_unslash( $_GET['aa_s'] ?? '' ) );
		$page   = \max( 1, \absint( $_GET['aa_page'] ?? 1 ) );
		// phpcs:enable
		$list   = self::recipients( $id, $filter, $search, $page );
		$pct    = static fn( float $r ) => \number_format_i18n( $r * 100, 1 ) . '%';
		$last   = (int) \get_option( Queue::LAST_RUN_OPTION );
		$base   = \get_edit_post_link( $id, 'raw' );
		?>
		<div class="aa-report">
			<ul class="aa-stats">
				<li><strong><?php echo \esc_html( \number_format_i18n( $s['sent'] ) ); ?></strong> <?php \esc_html_e( 'sent', 'anchor-schema' ); ?></li>
				<li><strong><?php echo \esc_html( \number_format_i18n( $s['queued'] ) ); ?></strong> <?php \esc_html_e( 'waiting', 'anchor-schema' ); ?></li>
				<li><strong><?php echo \esc_html( $pct( $s['open_rate'] ) ); ?></strong> <?php echo \esc_html( \sprintf( \__( 'opened (%s, estimated)', 'anchor-schema' ), \number_format_i18n( $s['opened'] ) ) ); ?></li>
				<li><strong><?php echo \esc_html( $pct( $s['click_rate'] ) ); ?></strong> <?php echo \esc_html( \sprintf( \__( 'clicked (%s)', 'anchor-schema' ), \number_format_i18n( $s['clicked'] ) ) ); ?></li>
				<li><strong><?php echo \esc_html( \number_format_i18n( $s['unsubscribed'] ) ); ?></strong> <?php \esc_html_e( 'unsubscribed', 'anchor-schema' ); ?></li>
				<li><strong><?php echo \esc_html( \number_format_i18n( $s['failed'] ) ); ?></strong> <?php \esc_html_e( 'failed', 'anchor-schema' ); ?></li>
				<li><strong><?php echo \esc_html( \number_format_i18n( $s['skipped'] ) ); ?></strong> <?php \esc_html_e( 'skipped', 'anchor-schema' ); ?></li>
			</ul>
			<p class="description"><?php \esc_html_e( 'Opens are estimated: Apple Mail and some company mail filters load images for the reader, so the true open rate is lower. Clicks are the reliable signal; clicks by link scanners seconds after sending are left out.', 'anchor-schema' ); ?>
				<?php echo \esc_html( $last ? \sprintf( \__( 'Queue last ran %s ago.', 'anchor-schema' ), \human_time_diff( $last ) ) : \__( 'The send queue has not run yet: check that WP-Cron runs on this site.', 'anchor-schema' ) ); ?></p>

			<h3><?php \esc_html_e( 'Recipients', 'anchor-schema' ); ?></h3>
			<p class="aa-filters">
				<?php foreach ( [ 'all' => \__( 'All', 'anchor-schema' ), 'opened' => \__( 'Opened', 'anchor-schema' ), 'not_opened' => \__( 'Not opened', 'anchor-schema' ), 'clicked' => \__( 'Clicked', 'anchor-schema' ), 'unsubscribed' => \__( 'Unsubscribed', 'anchor-schema' ), 'failed' => \__( 'Failed', 'anchor-schema' ), 'skipped' => \__( 'Skipped', 'anchor-schema' ) ] as $key => $label ) : ?>
					<a class="<?php echo $key === $filter ? 'current' : ''; ?>" href="<?php echo \esc_url( \add_query_arg( [ 'aa_filter' => $key, 'aa_page' => 1 ], $base ) . '#aa-report' ); ?>"><?php echo \esc_html( $label ); ?></a>
				<?php endforeach; ?>
				<a class="button" href="<?php echo \esc_url( \wp_nonce_url( \admin_url( 'admin-post.php?action=anchor_announcements_csv&post=' . $id ), 'aa_csv_' . $id ) ); ?>"><?php \esc_html_e( 'Export CSV', 'anchor-schema' ); ?></a>
			</p>
			<?php // The report box sits inside the post edit form: no nested <form>; admin.js navigates. ?>
			<p class="aa-report-search">
				<input type="search" id="aa-report-search" value="<?php echo \esc_attr( $search ); ?>" placeholder="<?php \esc_attr_e( 'Search name or email', 'anchor-schema' ); ?>" />
				<button type="button" class="button" id="aa-report-search-go" data-base="<?php echo \esc_url( \add_query_arg( [ 'aa_filter' => $filter, 'aa_page' => 1 ], $base ) ); ?>"><?php \esc_html_e( 'Search', 'anchor-schema' ); ?></button>
			</p>
			<table class="widefat striped">
				<thead><tr><th><?php \esc_html_e( 'Name', 'anchor-schema' ); ?></th><th><?php \esc_html_e( 'Email', 'anchor-schema' ); ?></th><th><?php \esc_html_e( 'Status', 'anchor-schema' ); ?></th><th><?php \esc_html_e( 'First opened', 'anchor-schema' ); ?></th><th><?php \esc_html_e( 'Opens', 'anchor-schema' ); ?></th><th><?php \esc_html_e( 'First clicked', 'anchor-schema' ); ?></th><th><?php \esc_html_e( 'Clicks', 'anchor-schema' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $list['rows'] as $r ) : ?>
					<tr>
						<td><?php echo \esc_html( $r['name'] ); ?></td>
						<td><?php echo \esc_html( $r['email'] ); ?></td>
						<td><?php echo \esc_html( $r['status'] . ( '' !== $r['skip_reason'] ? ' (' . $r['skip_reason'] . ')' : '' ) ); ?><?php echo '' !== (string) $r['error'] ? '<br /><small>' . \esc_html( $r['error'] ) . '</small>' : ''; ?></td>
						<td><?php echo \esc_html( $r['first_opened_at'] ? \get_date_from_gmt( $r['first_opened_at'], 'M j, g:i a' ) : '' ); ?></td>
						<td><?php echo \esc_html( (string) $r['open_count'] ); ?></td>
						<td><?php echo \esc_html( $r['first_clicked_at'] ? \get_date_from_gmt( $r['first_clicked_at'], 'M j, g:i a' ) : '' ); ?></td>
						<td><?php echo \esc_html( (string) $r['click_count'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( $list['total'] > 50 ) : ?>
				<p><?php echo \wp_kses_post( \paginate_links( [ 'base' => \add_query_arg( 'aa_page', '%#%', $base ) . '#aa-report', 'format' => '', 'current' => $page, 'total' => (int) \ceil( $list['total'] / 50 ) ] ) ); ?></p>
			<?php endif; ?>

			<h3><?php \esc_html_e( 'Links', 'anchor-schema' ); ?></h3>
			<table class="widefat striped">
				<thead><tr><th><?php \esc_html_e( 'Link', 'anchor-schema' ); ?></th><th><?php \esc_html_e( 'Clicks', 'anchor-schema' ); ?></th><th><?php \esc_html_e( 'People', 'anchor-schema' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( self::links( $id ) as $l ) : ?>
					<tr><td><?php echo \esc_html( $l['url'] ); ?></td><td><?php echo \esc_html( (string) $l['clicks'] ); ?></td><td><?php echo \esc_html( (string) $l['unique'] ); ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
```

- [ ] **Step 4: Implement ListColumns**

```php
<?php
declare(strict_types=1);

namespace Anchor\Announcements\Admin;

use Anchor\Announcements\Content\AnnouncementPostType as PT;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

final class ListColumns {

	public function __construct() {
		\add_filter( 'manage_' . PT::CPT . '_posts_columns', [ $this, 'columns' ] );
		\add_action( 'manage_' . PT::CPT . '_posts_custom_column', [ $this, 'cell' ], 10, 2 );
	}

	public function columns( array $cols ): array {
		unset( $cols['date'] );
		return $cols + [ 'aa_state' => \__( 'Status', 'anchor-schema' ), 'aa_audience' => \__( 'Audience', 'anchor-schema' ), 'aa_sent' => \__( 'Sent', 'anchor-schema' ), 'aa_open' => \__( 'Opened', 'anchor-schema' ), 'aa_click' => \__( 'Clicked', 'anchor-schema' ), 'aa_when' => \__( 'Date', 'anchor-schema' ) ];
	}

	public function cell( string $col, int $id ): void {
		$state = PT::state( $id );
		$s     = \in_array( $col, [ 'aa_audience', 'aa_sent', 'aa_open', 'aa_click' ], true ) ? Reports::stats( $id ) : [];
		switch ( $col ) {
			case 'aa_state':
				echo \esc_html( \ucfirst( $state ) );
				break;
			case 'aa_audience':
				echo \esc_html( $s['total'] ? \number_format_i18n( $s['total'] - $s['skipped'] ) : '' );
				break;
			case 'aa_sent':
				echo \esc_html( \number_format_i18n( $s['sent'] ) );
				break;
			case 'aa_open':
				echo \esc_html( $s['sent'] ? \number_format_i18n( $s['open_rate'] * 100, 1 ) . '%' : '' );
				break;
			case 'aa_click':
				echo \esc_html( $s['sent'] ? \number_format_i18n( $s['click_rate'] * 100, 1 ) . '%' : '' );
				break;
			case 'aa_when':
				$ts = PT::STATE_SCHEDULED === $state ? (int) \get_post_meta( $id, PT::META_SCHEDULED, true ) : (int) \get_post_meta( $id, PT::META_SENT_AT, true );
				echo \esc_html( $ts ? \wp_date( \get_option( 'date_format' ) . ' ' . \get_option( 'time_format' ), $ts ) : \get_the_modified_date( '', $id ) );
				break;
		}
	}
}
```

- [ ] **Step 5: Implement SuppressionsPage**

```php
<?php
declare(strict_types=1);

namespace Anchor\Announcements\Admin;

use Anchor\Announcements\Content\AnnouncementPostType as PT;
use Anchor\Announcements\Module;
use Anchor\Announcements\Suppression\Suppressions;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** Announcements > Unsubscribed: list, search, add, remove. */
final class SuppressionsPage {

	public const SLUG = 'anchor-announcements-suppressions';

	public function __construct() {
		\add_action( 'admin_menu', [ $this, 'menu' ] );
		\add_action( 'admin_post_anchor_announcements_suppression', [ $this, 'act' ] );
	}

	public function menu(): void {
		\add_submenu_page( 'edit.php?post_type=' . PT::CPT, \__( 'Unsubscribed', 'anchor-schema' ), \__( 'Unsubscribed', 'anchor-schema' ), Module::CAP, self::SLUG, [ $this, 'render' ] );
	}

	public function act(): void {
		if ( ! \current_user_can( Module::CAP ) ) {
			\wp_die( \esc_html__( 'You are not allowed to do that.', 'anchor-schema' ), 403 );
		}
		\check_admin_referer( 'aa_suppression' );
		$email = \sanitize_email( \wp_unslash( $_REQUEST['email'] ?? '' ) );
		'remove' === ( $_REQUEST['do'] ?? '' ) ? Suppressions::remove( $email ) : Suppressions::add( $email, 'manual' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		\wp_safe_redirect( \add_query_arg( [ 'post_type' => PT::CPT, 'page' => self::SLUG ], \admin_url( 'edit.php' ) ) );
		exit;
	}

	public function render(): void {
		$search = \sanitize_text_field( \wp_unslash( $_GET['s'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$page   = \max( 1, \absint( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$list   = Suppressions::list( $search, $page );
		?>
		<div class="wrap">
			<h1><?php \esc_html_e( 'Unsubscribed and blocked addresses', 'anchor-schema' ); ?></h1>
			<p><?php \esc_html_e( 'Announcements are never sent to these addresses.', 'anchor-schema' ); ?></p>
			<form method="post" action="<?php echo \esc_url( \admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="anchor_announcements_suppression" />
				<?php \wp_nonce_field( 'aa_suppression' ); ?>
				<input type="email" name="email" required placeholder="<?php \esc_attr_e( 'email@example.com', 'anchor-schema' ); ?>" />
				<?php \submit_button( \__( 'Block address', 'anchor-schema' ), 'secondary', 'submit', false ); ?>
			</form>
			<form method="get">
				<input type="hidden" name="post_type" value="<?php echo \esc_attr( PT::CPT ); ?>" />
				<input type="hidden" name="page" value="<?php echo \esc_attr( self::SLUG ); ?>" />
				<p class="search-box"><input type="search" name="s" value="<?php echo \esc_attr( $search ); ?>" /> <?php \submit_button( \__( 'Search', 'anchor-schema' ), '', '', false ); ?></p>
			</form>
			<table class="widefat striped">
				<thead><tr><th><?php \esc_html_e( 'Email', 'anchor-schema' ); ?></th><th><?php \esc_html_e( 'Reason', 'anchor-schema' ); ?></th><th><?php \esc_html_e( 'Since', 'anchor-schema' ); ?></th><th></th></tr></thead>
				<tbody>
				<?php foreach ( $list['rows'] as $r ) : ?>
					<tr>
						<td><?php echo \esc_html( $r['email'] ); ?></td>
						<td><?php echo \esc_html( $r['reason'] ); ?></td>
						<td><?php echo \esc_html( \get_date_from_gmt( $r['created_at'], (string) \get_option( 'date_format' ) ) ); ?></td>
						<td><a href="<?php echo \esc_url( \wp_nonce_url( \admin_url( 'admin-post.php?action=anchor_announcements_suppression&do=remove&email=' . \rawurlencode( $r['email'] ) ), 'aa_suppression' ) ); ?>"><?php \esc_html_e( 'Remove', 'anchor-schema' ); ?></a></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php echo \wp_kses_post( (string) \paginate_links( [ 'total' => (int) \ceil( $list['total'] / 50 ), 'current' => $page ] ) ); ?>
		</div>
		<?php
	}
}
```

- [ ] **Step 6: Wire them and style the report**

In `Module::__construct()` inside the `is_admin()` block add `new Admin\Reports(); new Admin\ListColumns(); new Admin\SuppressionsPage();`. Append to `admin.css`:

```css
.aa-stats{display:flex;flex-wrap:wrap;gap:12px;margin:0 0 8px;padding:0;list-style:none}
.aa-stats li{background:#f6f7f7;border-radius:6px;padding:10px 14px;min-width:110px}
.aa-stats strong{display:block;font-size:20px}
.aa-filters{display:flex;flex-wrap:wrap;gap:10px;align-items:center}
.aa-filters a.current{font-weight:700;text-decoration:none;color:#1d2327}
```

- [ ] **Step 7: Run the tests**

Run: `vendor/bin/phpunit --filter Test_Announcements_Reports`
Expected: PASS (5 tests).

- [ ] **Step 8: Commit**

```bash
git add anchor-announcements tests/test-announcements-reports.php
git commit -m "Announcements: report (totals, recipients, links, CSV), list columns and the unsubscribed screen

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 13: Privacy exporter and eraser

**Files:**
- Create: `anchor-announcements/src/Privacy/Privacy.php`
- Modify: `anchor-announcements/anchor-announcements.php` (`new Privacy\Privacy();`)
- Test: `tests/test-announcements-privacy.php`

**Interfaces:**
- Produces: exporter and eraser registered under the key `anchor-announcements` through `wp_privacy_personal_data_exporters` / `_erasers`; `Privacy::export( string $email, int $page = 1 ): array`, `Privacy::erase( string $email, int $page = 1 ): array` (the WordPress callback shapes).

- [ ] **Step 1: Write the failing tests**

```php
<?php
use Anchor\Announcements\Database\Migrations;
use Anchor\Announcements\Privacy\Privacy;
use Anchor\Announcements\Suppression\Suppressions;
use Anchor\Announcements\Tracking\Endpoints;

class Test_Announcements_Privacy extends Anchor_Announcements_TestCase {

	private function send( string $email ): object {
		global $wpdb;
		$wpdb->insert( Migrations::table( 'sends' ), [ 'announcement_id' => $this->make_announcement(), 'email' => $email, 'name' => 'P', 'token' => bin2hex( random_bytes( 16 ) ), 'status' => 'sent', 'queued_at' => '2026-01-01 00:00:00', 'sent_at' => '2026-01-01 00:00:00' ] );
		return $wpdb->get_row( 'SELECT * FROM ' . Migrations::table( 'sends' ) . ' WHERE id = ' . (int) $wpdb->insert_id );
	}

	public function test_registered() {
		$this->assertArrayHasKey( 'anchor-announcements', apply_filters( 'wp_privacy_personal_data_exporters', [] ) );
		$this->assertArrayHasKey( 'anchor-announcements', apply_filters( 'wp_privacy_personal_data_erasers', [] ) );
	}

	public function test_export_lists_sends_and_suppression() {
		$s = $this->send( 'p@x.com' );
		Endpoints::record( $s, 'open' );
		Suppressions::add( 'p@x.com', 'unsubscribed' );
		$out = Privacy::export( 'P@x.com' );
		$this->assertTrue( $out['done'] );
		$this->assertCount( 2, $out['data'] );
	}

	public function test_erase_removes_history_but_keeps_the_opt_out() {
		$s = $this->send( 'p@x.com' );
		Endpoints::record( $s, 'open' );
		Suppressions::add( 'p@x.com', 'unsubscribed' );
		$out = Privacy::erase( 'p@x.com' );
		$this->assertTrue( $out['items_removed'] );
		$this->assertTrue( $out['items_retained'] );
		$this->assertNotEmpty( $out['messages'] );
		global $wpdb;
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Migrations::table( 'sends' ) . ' WHERE email = %s', 'p@x.com' ) ) );
		$this->assertSame( '0', $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Migrations::table( 'events' ) ) );
		$this->assertTrue( Suppressions::is_suppressed( 'p@x.com' ) );
	}
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/phpunit --filter Test_Announcements_Privacy`
Expected: FAIL.

- [ ] **Step 3: Implement**

```php
<?php
declare(strict_types=1);

namespace Anchor\Announcements\Privacy;

use Anchor\Announcements\Database\Migrations;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

/** Tools > Export / Erase Personal Data support. An opt-out is kept on erase (so it keeps working). */
final class Privacy {

	public function __construct() {
		\add_filter( 'wp_privacy_personal_data_exporters', static function ( $e ) { $e['anchor-announcements'] = [ 'exporter_friendly_name' => \__( 'Announcements', 'anchor-schema' ), 'callback' => [ self::class, 'export' ] ]; return $e; } );
		\add_filter( 'wp_privacy_personal_data_erasers', static function ( $e ) { $e['anchor-announcements'] = [ 'eraser_friendly_name' => \__( 'Announcements', 'anchor-schema' ), 'callback' => [ self::class, 'erase' ] ]; return $e; } );
	}

	public static function export( string $email, int $page = 1 ): array {
		global $wpdb;
		$email = \strtolower( \trim( $email ) );
		$data  = [];
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Migrations::table( 'sends' ) . ' WHERE email = %s', $email ) ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$data[] = [
				'group_id'    => 'anchor-announcements',
				'group_label' => \__( 'Announcements received', 'anchor-schema' ),
				'item_id'     => 'aa-send-' . $r->id,
				'data'        => [
					[ 'name' => \__( 'Announcement', 'anchor-schema' ), 'value' => \get_the_title( (int) $r->announcement_id ) ],
					[ 'name' => \__( 'Status', 'anchor-schema' ), 'value' => $r->status ],
					[ 'name' => \__( 'Sent', 'anchor-schema' ), 'value' => (string) $r->sent_at ],
					[ 'name' => \__( 'Opens', 'anchor-schema' ), 'value' => (string) $r->open_count ],
					[ 'name' => \__( 'Clicks', 'anchor-schema' ), 'value' => (string) $r->click_count ],
				],
			];
		}
		$sup = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Migrations::table( 'suppressions' ) . ' WHERE email = %s', $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $sup ) {
			$data[] = [ 'group_id' => 'anchor-announcements-optout', 'group_label' => \__( 'Announcement opt-out', 'anchor-schema' ), 'item_id' => 'aa-optout', 'data' => [ [ 'name' => \__( 'Reason', 'anchor-schema' ), 'value' => $sup->reason ], [ 'name' => \__( 'Since', 'anchor-schema' ), 'value' => $sup->created_at ] ] ];
		}
		return [ 'data' => $data, 'done' => true ];
	}

	public static function erase( string $email, int $page = 1 ): array {
		global $wpdb;
		$email = \strtolower( \trim( $email ) );
		$sends = Migrations::table( 'sends' );
		$ids   = \array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$sends} WHERE email = %s", $email ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $ids ) {
			$wpdb->query( 'DELETE FROM ' . Migrations::table( 'events' ) . ' WHERE send_id IN (' . \implode( ',', $ids ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- ints.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$sends} WHERE email = %s", $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$kept = (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . Migrations::table( 'suppressions' ) . ' WHERE email = %s', $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return [
			'items_removed'  => (bool) $ids,
			'items_retained' => $kept,
			'messages'       => $kept ? [ \__( 'The announcement opt-out for this address was kept so it is never emailed again.', 'anchor-schema' ) ] : [],
			'done'           => true,
		];
	}
}
```

- [ ] **Step 4: Run the tests**

Run: `vendor/bin/phpunit --filter Test_Announcements_Privacy`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add anchor-announcements tests/test-announcements-privacy.php
git commit -m "Announcements: personal data exporter and eraser (opt-outs are retained)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 14: Docs, end-to-end check, full suite and the pull request

**Files:**
- Create: `anchor-announcements/ANNOUNCEMENTS.md`, `e2e/announcements.spec.js`
- Modify: `CLAUDE.md` (module table row)

- [ ] **Step 1: Module docs**

`anchor-announcements/ANNOUNCEMENTS.md`, covering in this order (short, operator-facing): what it does; setup (enable the module, fill the mailing address, point the site's SMTP plugin at Mailgun or any provider; turn off the provider's own click tracking or leave it, since `X-Mailgun-Track: no` is sent); composing and tokens (the table from spec section 5, which tokens can be empty for guests); the audience builder (groups OR'd, conditions AND'd, "is not", the condition table from spec 6.3, snapshot at send time); sending (test, schedule, send now, batches per minute, pause, cancel, WP-Cron note); tracking and its honesty caveats (spec 8); reports and CSV; unsubscribes and the Unsubscribed screen; privacy; extension points (`anchor_announcements_conditions`, `anchor_announcements_tokens`, `anchor_announcements_mail_headers`, `anchor_announcements_universe`, `anchor_email_allowed_html`, action `anchor_announcements_suppress`); the email kit and the planned events migration (spec 10).

- [ ] **Step 2: CLAUDE.md row**

Add to the module table: `| \`announcements\` | \`\Anchor\Announcements\Module\` | CPT (namespaced, PSR-4) |`, and one line under Core Classes: "`includes/email/`: the shared email kit (tokens, sanitizer, shell, builder UI); Announcements uses it, events will." (Colon, not a dash: the no-em-dash rule applies even where neighbouring lines use dashes.)

- [ ] **Step 3: The Playwright spec**

`e2e/announcements.spec.js` (follow the existing specs' login helper and `e2e/.seed.json` conventions; read one existing spec in `e2e/` first):

```js
const { test, expect } = require('@playwright/test');

test('compose, preview audience, test send, send, report', async ({ page }) => {
  await page.goto('/wp-admin/edit.php?post_type=anchor_announcement&page=anchor-announcements-settings');
  await page.fill('#aa-footer_address', '1 Test Street, Testville');
  await page.click('#submit');

  await page.goto('/wp-admin/post-new.php?post_type=anchor_announcement');
  await page.fill('#title', 'E2E announcement');
  await page.fill('.anchor-email-builder__subject', 'Hello {first_name}');
  await page.frameLocator('#aa-email-body_ifr').locator('body').fill('Hi there, read https://example.com');
  await expect(page.frameLocator('.anchor-email-builder__frame').locator('body')).toContainText('Hi there');

  await page.click('.aa-add-group');
  await page.selectOption('.aa-cond-type', 'specific_people');
  await page.fill('.aa-cond-fields textarea', 'e2e-recipient@example.com');
  await page.click('#aa-audience-preview');
  await expect(page.locator('#aa-audience-count')).toContainText('1 recipients');

  await page.click('#aa-test-send');
  await expect(page.locator('#aa-test-result')).toContainText('Test sent');

  page.on('dialog', (d) => d.accept());
  await page.click('#aa-send-now');
  await expect(page.locator('.notice-success')).toContainText('Sending to 1 people');
});
```

Run the tick between send and report with `npx wp-env run cli wp cron event run anchor_announcements_tick`, then extend the spec to reload and assert the Report box lists `e2e-recipient@example.com` as `sent` if the existing e2e setup allows shelling out from the test (check `playwright.config.js`); otherwise stop the spec at the success notice.

Run: `npm run wp-env start && npm run env:seed && npx playwright test e2e/announcements.spec.js`
Expected: PASS.

- [ ] **Step 4: Full suite, lint checks, file count**

```bash
vendor/bin/phpunit 2>&1 | tail -5
vendor/bin/phpunit --group ajax 2>&1 | tail -5
grep -rnP '\x{2014}' anchor-announcements includes/email assets/email-kit tests/*announce* tests/test-email-kit.php e2e/announcements.spec.js CLAUDE.md docs/superpowers/plans/2026-09-30-anchor-announcements.md docs/superpowers/specs/2026-09-30-anchor-announcements-design.md
git ls-files '*.min.*' | grep -E 'announcements|email-kit' || true
git diff --name-only origin/main...HEAD | wc -l
```

Expected: both PHPUnit runs `OK` with no new failures versus the Task 0 baseline; the grep prints nothing; no minified files tracked; the file count is well under 150.

- [ ] **Step 5: Commit and push the branch**

```bash
git add anchor-announcements/ANNOUNCEMENTS.md e2e/announcements.spec.js CLAUDE.md
git commit -m "Announcements: docs and end-to-end check

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
git push origin feat/anchor-announcements
```

- [ ] **Step 6: Open the pull request (ready for review, not draft)**

```bash
gh pr create --base main --head feat/anchor-announcements --title "Anchor Announcements: tracked email announcements with an AND/OR audience builder" --body "$(cat <<'BODY'
## What
A new module, **Anchor Announcements**: compose a branded email with the shared email builder, target recipients with AND/OR rules (roles, account date, profile fields, specific people, WooCommerce purchases/order count/spend with date ranges, course enrolment/completion, event registration), send through wp_mail() in background batches, and see opens, clicks and unsubscribes per recipient. Provider-neutral: tracking is self-hosted (pixel, click redirect, one-click unsubscribe with List-Unsubscribe headers).

Also adds the shared email kit in includes/email/ (tokens, sanitizer, shell, builder UI). Events does not use it yet; that migration is a follow-up PR (spec section 10).

## Docs
- Spec: docs/superpowers/specs/2026-09-30-anchor-announcements-design.md
- Plan: docs/superpowers/plans/2026-09-30-anchor-announcements.md
- Operator docs: anchor-announcements/ANNOUNCEMENTS.md

## Tests
PHPUnit: email kit, audience engine and every condition (HPOS and legacy order storage), renderer, tracking, queue (claims, retries, schedule, pause, cancel), editor, AJAX (capability), reports, privacy. Playwright: compose to send.

Not merged, no release tag: needs owner sign-off.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
BODY
)"
```

- [ ] **Step 7: Verify CodeRabbit actually reviewed**

After a few minutes: `gh pr view --comments` and `gh api repos/{owner}/{repo}/pulls/<n>/comments | jq length`. A walkthrough comment alone is not a review; look for inline comments. If only "I will review this" appears (rate limit), comment `@coderabbitai review` later and check again. Report the PR URL and the review status to the owner.
