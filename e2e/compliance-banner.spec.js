// @ts-check
const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');

/**
 * Anchor Compliance — consent banner / preference center / script gating.
 *
 * FIXTURE: bin/e2e-seed.sh enables the `compliance` module and publishes one
 * page ("CMP E2E Consent") whose content carries:
 *   - a YouTube <iframe> (gated as `marketing` by the built-in registry, so
 *     the output-buffer blocker neutralizes it server-side),
 *   - the [anchor_consent_link] shortcode,
 *   - the [anchor_do_not_sell] shortcode,
 *   - the [anchor_privacy_request] DSAR form.
 * It writes compliance_page_url into e2e/.seed.json. Run `npm run env:seed`
 * before this spec.
 *
 * POSTURE DETERMINISM: wp-env sends no geo header, so the server resolves the
 * country as unknown and falls back to the default `strict` posture. The
 * client-side relax tier (tier 3) may only relax strict -> optout based on the
 * browser's IANA timezone — so every front-end test pins the browser timezone
 * to Europe/Berlin, which the relax tier treats as possibly-strict. Net
 * effect: deterministic STRICT posture end to end.
 *
 * RESILIENCE: sibling agents are actively editing frontend.js / frontend.css /
 * the payload. Assertions target the stable contract: #anchor-cmp,
 * #anchor-cmp-banner, #anchor-cmp-prefs, [data-anchor-action],
 * [data-anchor-category], [data-anchor-consent]/[data-anchor-src],
 * .anchor-cmp-placeholder, the `anchor_consent` cookie shape
 * {id, ts, v, cats}, and the POST to anchor-compliance/v1/consent.
 * Reject assertions check necessary-in / analytics+marketing-out rather than
 * an exact grant list, so a widened necessary-only set cannot break them.
 *
 * The admin repeater test (last) logs into wp-admin with the wp-env default
 * credentials and drives the Custom Rules add/remove repeater (admin.js).
 */

const SEED_PATH = path.join(__dirname, '.seed.json');
const COOKIE_NAME = 'anchor_consent';
const CONSENT_ENDPOINT = /anchor-compliance\/v1\/consent/;

/** @type {{ compliance_page_url: string }} */
let seed;

test.beforeAll(() => {
  if (!fs.existsSync(SEED_PATH)) {
    throw new Error('Missing e2e/.seed.json — run `npm run env:seed` first.');
  }
  seed = JSON.parse(fs.readFileSync(SEED_PATH, 'utf8'));
  if (!seed.compliance_page_url) {
    throw new Error('Seed has no compliance_page_url — re-run `npm run env:seed`.');
  }
});

/** Decode the base64url JSON consent cookie value. */
function decodeConsentValue(value) {
  const b64 = value.replace(/-/g, '+').replace(/_/g, '/');
  return JSON.parse(Buffer.from(b64, 'base64').toString('utf8'));
}

/** @returns the decoded consent cookie payload for this context, or null. */
async function readConsentCookie(context) {
  const cookies = await context.cookies();
  const hit = cookies.find((c) => c.name === COOKIE_NAME);
  return hit ? decodeConsentValue(hit.value) : null;
}

test.describe('consent banner (strict posture)', () => {
  // Keep the client relax tier from relaxing strict -> optout (see header).
  test.use({ timezoneId: 'Europe/Berlin' });

  test('first visit shows the banner and sets no consent cookie', async ({ page, context }) => {
    await page.goto(seed.compliance_page_url);

    await expect(page.locator('#anchor-cmp')).toBeVisible();
    await expect(page.locator('#anchor-cmp-banner')).toBeVisible();
    await expect(page.locator('#anchor-cmp-banner [data-anchor-action="accept-all"]')).toBeVisible();
    await expect(page.locator('#anchor-cmp-banner [data-anchor-action="reject-all"]')).toBeVisible();
    await expect(page.locator('#anchor-cmp-banner [data-anchor-action="customize"]')).toBeVisible();

    // The server payload agrees this is a strict-posture, undecided visit.
    const payload = await page.evaluate(() => ({
      posture: window.AnchorComplianceData.posture,
      hasConsent: window.AnchorComplianceData.hasConsent,
      cookieName: window.AnchorComplianceData.cookieName,
    }));
    expect(payload.posture).toBe('strict');
    expect(payload.hasConsent).toBe(false);
    expect(payload.cookieName).toBe(COOKIE_NAME);

    expect(await readConsentCookie(context)).toBeNull();
  });

  test('Accept All writes a full-grant cookie, records via REST, and persists across reload', async ({ page, context }) => {
    await page.goto(seed.compliance_page_url);
    await expect(page.locator('#anchor-cmp-banner')).toBeVisible();

    const [request] = await Promise.all([
      page.waitForRequest((r) => CONSENT_ENDPOINT.test(r.url()) && r.method() === 'POST'),
      page.click('#anchor-cmp-banner [data-anchor-action="accept-all"]'),
    ]);

    // REST audit record fires with the documented body shape.
    const body = request.postDataJSON();
    expect(body.method).toBe('banner');
    expect(Array.isArray(body.categories)).toBe(true);
    expect(body.categories).toContain('marketing');
    expect(body.consent_id).toMatch(/^[0-9a-f-]{36}$/i);

    // Cookie shape: {id, ts, v, cats} with every category granted.
    const cookie = await readConsentCookie(context);
    expect(cookie).not.toBeNull();
    expect(cookie.cats).toContain('necessary');
    expect(cookie.cats).toContain('analytics');
    expect(cookie.cats).toContain('marketing');
    expect(typeof cookie.ts).toBe('number');
    expect(cookie.v).toBeGreaterThanOrEqual(1);
    expect(cookie.id).toMatch(/^[0-9a-f-]{36}$/i);

    await expect(page.locator('#anchor-cmp-banner')).toBeHidden();

    // Persists: a reload never re-prompts.
    await page.goto(seed.compliance_page_url);
    await expect(page.locator('#anchor-cmp-banner')).toBeHidden();
    expect(await page.evaluate(() => window.AnchorComplianceData.hasConsent)).toBe(true);
  });

  test('Reject writes a necessary-only cookie and persists', async ({ page, context }) => {
    await page.goto(seed.compliance_page_url);
    await expect(page.locator('#anchor-cmp-banner')).toBeVisible();

    await Promise.all([
      page.waitForRequest((r) => CONSENT_ENDPOINT.test(r.url()) && r.method() === 'POST'),
      page.click('#anchor-cmp-banner [data-anchor-action="reject-all"]'),
    ]);

    const cookie = await readConsentCookie(context);
    expect(cookie).not.toBeNull();
    expect(cookie.cats).toContain('necessary');
    expect(cookie.cats).not.toContain('analytics');
    expect(cookie.cats).not.toContain('marketing');

    await page.goto(seed.compliance_page_url);
    await expect(page.locator('#anchor-cmp-banner')).toBeHidden();
    // The runtime honors the visitor's own cookie over anything cache-baked.
    const granted = await page.evaluate(() => window.AnchorConsent.get());
    expect(granted.necessary).toBe(true);
    expect(granted.analytics).toBe(false);
    expect(granted.marketing).toBe(false);
  });

  test('preference center opens, a category toggle round-trips, and the pill reopens it', async ({ page, context }) => {
    await page.goto(seed.compliance_page_url);
    await expect(page.locator('#anchor-cmp-banner')).toBeVisible();

    // Customize opens the preference panel (banner hides beneath it).
    await page.click('#anchor-cmp-banner [data-anchor-action="customize"]');
    const prefs = page.locator('#anchor-cmp-prefs');
    await expect(prefs).toBeVisible();

    // Necessary is locked on; grant analytics only, leave marketing off.
    await expect(prefs.locator('[data-anchor-category="necessary"]')).toBeChecked();
    await expect(prefs.locator('[data-anchor-category="necessary"]')).toBeDisabled();
    await prefs.locator('[data-anchor-category="analytics"]').check();
    await prefs.locator('[data-anchor-category="marketing"]').uncheck();

    await Promise.all([
      page.waitForRequest((r) => CONSENT_ENDPOINT.test(r.url()) && r.method() === 'POST'),
      prefs.locator('[data-anchor-action="save-preferences"]').click(),
    ]);

    const cookie = await readConsentCookie(context);
    expect(cookie.cats).toContain('analytics');
    expect(cookie.cats).not.toContain('marketing');

    // Round trip: fresh load, reopen via the floating pill.
    await page.goto(seed.compliance_page_url);
    await expect(page.locator('#anchor-cmp-banner')).toBeHidden();
    const pill = page.locator('.anchor-cmp-pill[data-anchor-action="open-preferences"]');
    await expect(pill).toBeVisible();
    await pill.click();
    await expect(prefs).toBeVisible();
    await expect(prefs.locator('[data-anchor-category="analytics"]')).toBeChecked();
    await expect(prefs.locator('[data-anchor-category="marketing"]')).not.toBeChecked();
  });

  test('closing the preference center without saving returns the undecided visitor to the banner, not the pill', async ({ page, context }) => {
    // The user-reported path (staging, strict, first visit): Customize →
    // toggle categories → close WITHOUT saving. No decision exists, so the
    // banner must come back, the strict gate must stay up, the pill must NOT
    // appear, and no consent cookie may be written.
    await page.goto(seed.compliance_page_url);
    await expect(page.locator('#anchor-cmp-banner')).toBeVisible();

    await page.click('#anchor-cmp-banner [data-anchor-action="customize"]');
    const prefs = page.locator('#anchor-cmp-prefs');
    await expect(prefs).toBeVisible();

    // Mess with the toggles like the reporter did — still not a decision.
    await prefs.locator('[data-anchor-category="analytics"]').check();
    await prefs.locator('[data-anchor-category="marketing"]').uncheck();

    // The close affordance must be reachable in strict posture.
    const closeBtn = prefs.locator('[data-anchor-action="close"]');
    await expect(closeBtn).toBeVisible();
    await closeBtn.click();

    // Cancel, never a decision: back to the banner as an open question.
    await expect(page.locator('#anchor-cmp-banner')).toBeVisible();
    await expect(prefs).toBeHidden();

    // The pill is a re-entry point for a DECIDED visitor; it must not exist
    // as a state for someone who has chosen nothing.
    await expect(page.locator('.anchor-cmp-pill[data-anchor-action="open-preferences"]')).toBeHidden();

    // The strict modal gate (scrim + scroll lock, F008) survives the cancel.
    await expect(page.locator('#anchor-cmp')).toHaveClass(/anchor-cmp--gate/);
    await expect(page.locator('html')).toHaveClass(/anchor-cmp-scroll-lock/);

    // Nothing was recorded.
    expect(await readConsentCookie(context)).toBeNull();

    // ESC from the reopened prefs panel is a cancel back to the banner too
    // (never a grant): same invariants must hold.
    await page.click('#anchor-cmp-banner [data-anchor-action="customize"]');
    await expect(prefs).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.locator('#anchor-cmp-banner')).toBeVisible();
    await expect(page.locator('.anchor-cmp-pill[data-anchor-action="open-preferences"]')).toBeHidden();
    await expect(page.locator('#anchor-cmp')).toHaveClass(/anchor-cmp--gate/);
    expect(await readConsentCookie(context)).toBeNull();
  });

  test('blocked YouTube iframe is neutralized pre-consent and restored after accepting its category', async ({ page, context }) => {
    await page.goto(seed.compliance_page_url);
    await expect(page.locator('#anchor-cmp-banner')).toBeVisible();

    // Server-side blocker output: src removed, gate + original URL preserved
    // in data attributes, actionable placeholder rendered beside it.
    const frame = page.locator('iframe[data-anchor-consent]');
    await expect(frame).toHaveAttribute('data-anchor-src', /youtube\.com\/embed/);
    expect(await frame.getAttribute('src')).toBeNull();

    const placeholder = page.locator('.anchor-cmp-placeholder[data-anchor-consent]');
    await expect(placeholder).toBeVisible();
    const acceptBtn = placeholder.locator('[data-anchor-accept]');
    await expect(acceptBtn).toBeVisible();
    const gatingCategory = await acceptBtn.getAttribute('data-anchor-accept');

    // Strict posture is fully modal (scrim gates page content for every
    // input modality — F008), so decide at the banner first. Rejecting
    // leaves the embed blocked, which is the real journey to a
    // placeholder accept.
    await page.locator('#anchor-cmp-banner [data-anchor-action="reject-all"]').click();
    await expect(page.locator('#anchor-cmp-banner')).toBeHidden();
    await expect(placeholder).toBeVisible();

    // Accept via the placeholder's own button: grants exactly the gating
    // category and unblocks the embed in place.
    await acceptBtn.click();

    await expect(page.locator('iframe.cmp-e2e-youtube')).toHaveAttribute('src', /youtube\.com\/embed/);
    await expect(page.locator('.anchor-cmp-placeholder')).toHaveCount(0);

    // The grant reached the cookie.
    const cookie = await readConsentCookie(context);
    expect(cookie).not.toBeNull();
    expect(cookie.cats).toContain(gatingCategory);
  });

  test('[anchor_consent_link] shortcode opens the preference center', async ({ page }) => {
    await page.goto(seed.compliance_page_url);
    // Strict posture gates page content until a decision is made (F008),
    // so dismiss the banner first — the link's job is re-entry for a
    // visitor who already decided.
    await page.locator('#anchor-cmp-banner [data-anchor-action="reject-all"]').click();
    await expect(page.locator('#anchor-cmp-banner')).toBeHidden();
    const link = page.locator('.anchor-cmp-link[data-anchor-action="open-preferences"]');
    await expect(link).toBeVisible();
    await link.click();
    await expect(page.locator('#anchor-cmp-prefs')).toBeVisible();
  });

  test('[anchor_privacy_request] form renders on the fixture page', async ({ page }) => {
    await page.goto(seed.compliance_page_url);
    // Cheap smoke only — the DSAR flow itself is covered by PHPUnit
    // (test-compliance-dsar.php). Target the form's stable submit wiring.
    const dsarForm = page.locator('form:has(input[name="action"][value="anchor_compliance_dsar"])');
    await expect(dsarForm).toBeVisible();
    await expect(dsarForm.locator('input[type="email"]')).toBeVisible();
  });
});

test.describe('consent banner (client-relaxed opt-out posture)', () => {
  // The staging bug report ran THIS path, not the strict one: the server saw
  // no geo header (posture 'strict', unknown_fallback), allow_client_relax is
  // on by default, and the visitor's US timezone made the tier-3 relax flip
  // the client posture to 'optout'. A US browser timezone reproduces it.
  test.use({ timezoneId: 'America/Chicago' });

  test('closing the preference center without saving returns the undecided visitor to the notice, not the pill', async ({ page, context }) => {
    await page.goto(seed.compliance_page_url);

    // Server rendered strict, client relaxed to the opt-out notice.
    expect(await page.evaluate(() => window.AnchorComplianceData.posture)).toBe('strict');
    await expect(page.locator('#anchor-cmp')).toHaveClass(/anchor-cmp--notice/);
    const banner = page.locator('#anchor-cmp-banner');
    await expect(banner).toBeVisible();

    // Customize → mess with toggles → close WITHOUT saving (the reported path).
    await page.click('#anchor-cmp-banner [data-anchor-action="customize"]');
    const prefs = page.locator('#anchor-cmp-prefs');
    await expect(prefs).toBeVisible();
    await prefs.locator('[data-anchor-category="marketing"]').uncheck();
    await prefs.locator('[data-anchor-action="close"]').click();

    // A cancel, never a decision: the notice banner returns. The visitor must
    // NOT be dismissed to the pill — no decision exists for the pill to stand
    // in for, and no cookie may be written.
    await expect(banner).toBeVisible();
    await expect(prefs).toBeHidden();
    await expect(page.locator('.anchor-cmp-pill[data-anchor-action="open-preferences"]')).toBeHidden();
    expect(await readConsentCookie(context)).toBeNull();

    // ESC inside the reopened prefs panel is the same cancel-to-banner.
    await page.click('#anchor-cmp-banner [data-anchor-action="customize"]');
    await expect(prefs).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(banner).toBeVisible();
    await expect(page.locator('.anchor-cmp-pill[data-anchor-action="open-preferences"]')).toBeHidden();
    expect(await readConsentCookie(context)).toBeNull();

    // The NOTICE itself stays dismissible without a choice — via Escape with
    // no dialog open (the deliberate ESC-never-grants design). That dismissal
    // shows the re-entry pill for this pageview and still writes no cookie.
    await page.keyboard.press('Escape');
    await expect(banner).toBeHidden();
    await expect(page.locator('.anchor-cmp-pill[data-anchor-action="open-preferences"]')).toBeVisible();
    expect(await readConsentCookie(context)).toBeNull();
  });

  test('[anchor_do_not_sell] shows a VISIBLE confirmation toast and records the opt-out', async ({ page, context }) => {
    // Owner-reported gap: the do-not-sell click used to write only to the
    // visually-hidden ARIA live region and close the panel — to a sighted
    // user it "didn't seem to do anything". The confirmation must now also
    // appear as an aria-hidden toast (#anchor-cmp-toast) so screen-reader
    // users are not told twice.
    await page.goto(seed.compliance_page_url);
    await expect(page.locator('#anchor-cmp')).toHaveClass(/anchor-cmp--notice/);

    // Dismiss the notice first (Escape, the no-choice dismissal) so the
    // fixed bottom bar cannot cover the in-content shortcode link.
    await page.keyboard.press('Escape');
    await expect(page.locator('#anchor-cmp-banner')).toBeHidden();

    const dns = page.locator('.anchor-cmp-link[data-anchor-action="do-not-sell"]');
    await expect(dns).toBeVisible();
    await dns.click();

    const toast = page.locator('#anchor-cmp-toast');
    await expect(toast).toBeVisible();
    await expect(toast).toHaveText(/opted out of the sale or sharing/i);
    await expect(toast).toHaveAttribute('aria-hidden', 'true');

    // The opt-out really happened: analytics + marketing are revoked.
    const cookie = await readConsentCookie(context);
    expect(cookie).not.toBeNull();
    expect(cookie.cats).not.toContain('analytics');
    expect(cookie.cats).not.toContain('marketing');
    expect(cookie.cats).toContain('necessary');
  });
});

test.describe('admin: custom-rules repeater (admin.js)', () => {
  /** Log into wp-admin using the wp-env default credentials (admin / password). */
  async function wpAdminLogin(page) {
    await page.goto('/wp-login.php');
    if (!(await page.locator('#user_login').isVisible().catch(() => false))) {
      return;
    }
    await page.fill('#user_login', 'admin');
    await page.fill('#user_pass', 'password');
    await Promise.all([page.waitForURL(/wp-admin/), page.click('#wp-submit')]);
  }

  test('Add rule appends a row with a fresh index and Remove deletes it', async ({ page }) => {
    await wpAdminLogin(page);
    await page.goto('/wp-admin/options-general.php?page=anchor-schema&tab=compliance');

    const container = page.locator('#anchor-cmp-custom-rules');
    await expect(container).toBeAttached();
    const rows = container.locator('.anchor-cmp-rule-row');
    const before = await rows.count();

    await page.click('#anchor-cmp-rule-add');
    await expect(rows).toHaveCount(before + 1);

    // The clone template's __INDEX__ literal must have been replaced with a
    // real index, or the row would never round-trip through sanitize().
    const newRow = rows.nth(before);
    const nameAttr = await newRow.locator('input').first().getAttribute('name');
    expect(nameAttr).toContain('[custom_rules][');
    expect(nameAttr).not.toContain('__INDEX__');

    await page.click('#anchor-cmp-rule-add');
    await expect(rows).toHaveCount(before + 2);
    // Two freshly added rows must not collide on the same index.
    const secondName = await rows.nth(before + 1).locator('input').first().getAttribute('name');
    expect(secondName).not.toBe(nameAttr);

    await newRow.locator('.anchor-cmp-rule-remove').click();
    await rows.nth(before).locator('.anchor-cmp-rule-remove').click();
    await expect(rows).toHaveCount(before);
  });
});
