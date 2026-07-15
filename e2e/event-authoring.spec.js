// @ts-check
const { test, expect } = require('@playwright/test');

/**
 * Event authoring UI (Task 1.3+1.4): the event-type / registration-mode
 * choosers, their conditional field groups, the session repeater, and the
 * metabox save round-trip.
 *
 * Requires wp-env running (npm run wp-env start) — no seed fixture needed,
 * this spec creates its own event draft via the block editor's "Add New
 * Event" screen.
 *
 * Two block-editor quirks this spec works around (verified by hand against
 * this repo's wp-env, WP 6.9-era Gutenberg):
 *   1. The post title lives inside the `editor-canvas` iframe, so it's
 *      addressed via frameLocator(). A plain `.click()` on it intercepts on
 *      the top toolbar's document-bar pill that visually sits on top of the
 *      title's clickable area — `.fill()` (focus + type, no click-through)
 *      sidesteps that, so title entry uses fill() only, never click().
 *   2. Classic metaboxes (our "Anchor Event" box) render inside a
 *      collapsible "Meta Boxes" panel that starts collapsed on a fresh
 *      editor session and remembers its open/closed state per-user across
 *      sessions. `expandMetaBoxes()` opens it via a direct DOM click
 *      (bypassing Playwright's actionability/interception checks, which
 *      false-positive here on the panel's resize-handle overlay) and is a
 *      no-op if it's already open.
 */

/** Log into wp-admin using the wp-env default credentials (admin / password). */
async function wpAdminLogin(page) {
  await page.goto('/wp-login.php');
  // Already logged in? wp-login redirects to the dashboard (no login form).
  if (!(await page.locator('#user_login').isVisible().catch(() => false))) {
    return;
  }
  await page.fill('#user_login', 'admin');
  await page.fill('#user_pass', 'password');
  await Promise.all([
    page.waitForURL(/wp-admin/),
    page.click('#wp-submit'),
  ]);
}

/** Dismiss the block editor's first-run "Welcome to the editor" tour, if shown. */
async function dismissWelcomeGuide(page) {
  const closeBtn = page.locator('button[aria-label="Close"]').first();
  if (await closeBtn.isVisible().catch(() => false)) {
    await closeBtn.click();
  }
}

/** Expand the classic-metaboxes "Meta Boxes" panel (see file header). No-op if already open. */
async function expandMetaBoxes(page) {
  await page.evaluate(() => {
    const btn = Array.from(document.querySelectorAll('button')).find(
      (b) => b.textContent.trim() === 'Meta Boxes'
    );
    if (btn && btn.getAttribute('aria-expanded') !== 'true') {
      btn.click();
    }
  });
  await page.waitForTimeout(300);
}

/** Open a fresh "Add New Event" draft, past the welcome tour, with the metabox panel open. */
async function newEventDraft(page) {
  await page.goto('/wp-admin/post-new.php?post_type=event');
  await page.waitForTimeout(1500);
  await dismissWelcomeGuide(page);
  await expandMetaBoxes(page);
}

test.beforeEach(async ({ page }) => {
  await wpAdminLogin(page);
});

test('type/registration-mode choosers toggle the conditional sections', async ({ page }) => {
  await newEventDraft(page);

  const typeSelect = page.locator('#anchor_event_type');
  const modeSelect = page.locator('#anchor_event_registration_mode');
  await expect(typeSelect).toBeVisible();
  await expect(modeSelect).toBeVisible();

  const sessionsSection = page.locator('.anchor-event-sessions-table').locator('..');
  const externalSection = page.locator('[data-when-mode="external"]');
  const ticketsBox = page.locator('#anchor-event-tickets');

  // Default type=single, mode derives to 'free' for a brand-new event — the
  // sessions repeater and external-registration fields both start hidden,
  // and the WooCommerce ticket-tiers box starts visible (defaults to 'wc'
  // markup being shown until a mode is explicitly chosen is NOT the case —
  // 'free' hides it too, since it only shows for data-when-mode="wc").
  await expect(sessionsSection).toBeHidden();
  await expect(externalSection).toBeHidden();

  // Selecting Multi-session reveals the sessions repeater.
  await typeSelect.selectOption('multisession');
  await expect(sessionsSection).toBeVisible();

  // Selecting External reveals the external fields and hides the
  // WooCommerce ticket-tiers metabox (data-when-mode="wc").
  await modeSelect.selectOption('external');
  await expect(externalSection).toBeVisible();
  await expect(ticketsBox).toBeHidden();

  // Switching to WooCommerce mode brings the ticket-tiers box back and
  // hides the external fields again.
  await modeSelect.selectOption('wc');
  await expect(ticketsBox).toBeVisible();
  await expect(externalSection).toBeHidden();
});

test('session repeater add/remove keeps field names contiguous, and values persist on save', async ({ page }) => {
  await newEventDraft(page);

  const titleField = page.frameLocator('iframe[name="editor-canvas"]').getByLabel('Add title');
  await titleField.fill('E2E Authoring Multisession Event');

  await page.locator('#anchor_event_start_date').fill('2026-09-01');
  await page.locator('#anchor_event_type').selectOption('multisession');
  await page.locator('#anchor_event_registration_mode').selectOption('external');

  // A brand-new event has no sessions yet, so the repeater starts EMPTY
  // (no template-seeded row) — each row is added explicitly.
  const rows = page.locator('.anchor-event-sessions-rows .anchor-event-session-row');
  await expect(rows).toHaveCount(0);

  await page.locator('.anchor-event-session-add').click();
  await expect(rows).toHaveCount(1);
  await rows.nth(0).locator('.anchor-session-date').fill('2026-09-01');
  await rows.nth(0).locator('.anchor-session-start-time').fill('09:00');
  await rows.nth(0).locator('.anchor-session-end-time').fill('10:00');
  await rows.nth(0).locator('.anchor-session-label').fill('Day 1');

  // Add a second row and confirm re-indexing kept the name contiguous
  // (anchor_event_sessions[1][date], not [__INDEX__] or a gap).
  await page.locator('.anchor-event-session-add').click();
  await expect(rows).toHaveCount(2);
  await expect(rows.nth(1).locator('.anchor-session-date')).toHaveAttribute(
    'name',
    'anchor_event_sessions[1][date]'
  );
  await rows.nth(1).locator('.anchor-session-date').fill('2026-09-02');
  await rows.nth(1).locator('.anchor-session-label').fill('Day 2');

  // Remove-and-reindex: add a third row (Day 3), then remove the MIDDLE row
  // (index 1, Day 2 — new rows always append, so index 1 is Day 2 here) and
  // confirm the remaining rows re-indexed to [0] and [1] with no gap.
  await page.locator('.anchor-event-session-add').click();
  await expect(rows).toHaveCount(3);
  await rows.nth(2).locator('.anchor-session-date').fill('2026-09-03');
  await rows.nth(2).locator('.anchor-session-label').fill('Day 3');

  await rows.nth(1).locator('.anchor-event-session-remove').click();
  await expect(rows).toHaveCount(2);
  await expect(rows.nth(0).locator('.anchor-session-date')).toHaveAttribute(
    'name',
    'anchor_event_sessions[0][date]'
  );
  await expect(rows.nth(1).locator('.anchor-session-date')).toHaveAttribute(
    'name',
    'anchor_event_sessions[1][date]'
  );
  // The remaining two rows should be Day 1 (2026-09-01) and Day 3
  // (2026-09-03) — Day 2 (the middle row) is what got removed.
  await expect(rows.nth(0).locator('.anchor-session-date')).toHaveValue('2026-09-01');
  await expect(rows.nth(1).locator('.anchor-session-date')).toHaveValue('2026-09-03');

  // External registration fields, including an embed with a script payload
  // that must be stripped by save_meta()'s sanitizer (Task 1.3+1.4).
  await page.fill('#anchor_event_external_url', 'https://example.test/register-e2e');
  await page.fill('#anchor_event_external_display_price', '$495');
  await page.fill(
    '#anchor_event_external_embed',
    '<iframe src="https://ok.example"></iframe><script>alert(1)</script>'
  );

  const publishBtn = page.getByRole('button', { name: 'Publish', exact: true });
  await publishBtn.click();
  await page.waitForTimeout(800);
  // Some Gutenberg configurations open a pre-publish review panel with a
  // second confirm button; click it if present, otherwise Publish already
  // completed the save directly.
  const confirmBtn = page.locator(
    '.editor-post-publish-panel__header-publish-button button, .editor-post-publish-button'
  );
  if (await confirmBtn.first().isVisible().catch(() => false)) {
    await confirmBtn.first().click();
  }
  await page.waitForURL(/post\.php\?post=\d+&action=edit/, { timeout: 30000 });

  // The block editor's own REST-based Publish save and the classic
  // metabox's hidden-iframe form POST (which is what actually runs
  // save_meta()) are two separate requests — the REST save's redirect can
  // land before the classic-metabox iframe POST finishes. Poll with a
  // reload rather than a single fixed sleep, so the assertion isn't racing
  // that hidden POST.
  await page.waitForLoadState('networkidle').catch(() => {});
  await expect(async () => {
    await page.reload();
    await page.waitForTimeout(600);
    await expandMetaBoxes(page);
    await expect(page.locator('#anchor_event_type')).toHaveValue('multisession');
  }).toPass({ timeout: 20000, intervals: [1000, 1500, 2000, 3000] });

  // Reload the editor and assert everything persisted, including the
  // sanitized embed (script stripped, iframe kept).
  await expect(page.locator('#anchor_event_type')).toHaveValue('multisession');
  await expect(page.locator('#anchor_event_registration_mode')).toHaveValue('external');
  await expect(page.locator('#anchor_event_external_url')).toHaveValue('https://example.test/register-e2e');
  await expect(page.locator('#anchor_event_external_display_price')).toHaveValue('$495');

  const persistedRows = page.locator('.anchor-event-sessions-rows .anchor-event-session-row');
  await expect(persistedRows).toHaveCount(2);
  await expect(persistedRows.nth(0).locator('.anchor-session-date')).toHaveValue('2026-09-01');
  await expect(persistedRows.nth(1).locator('.anchor-session-date')).toHaveValue('2026-09-03');

  const embedValue = await page.locator('#anchor_event_external_embed').inputValue();
  expect(embedValue).toContain('<iframe');
  expect(embedValue).not.toContain('<script');
});
