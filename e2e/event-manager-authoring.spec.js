// @ts-check
const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');

/**
 * Front-end "event manager" authoring form (Task 1.5): the event-type /
 * registration-mode choosers, their conditional field groups, the session
 * repeater, and the front-end save round-trip — mirroring the admin metabox
 * coverage in e2e/event-authoring.spec.js, but driven through the public
 * [event_manager] shortcode form instead of the block editor.
 *
 * FIXTURE: bin/e2e-seed.sh creates (or reuses) a published page at slug
 * `event-manager` whose content is exactly the `[event_manager]` shortcode,
 * and writes its permalink into e2e/.seed.json as `manager_page_url` — same
 * pattern as the paid-event fixture e2e/purchase.spec.js consumes. Run the
 * seed before this spec: `npm run env:seed`.
 *
 * Requires wp-env running (npm run wp-env start) and a logged-in user with
 * edit_others_posts (the wp-env default admin qualifies).
 */

const SEED_PATH = path.join(__dirname, '.seed.json');

/** @type {{ manager_page_id: number, manager_page_url: string }} */
let seed;
let MANAGER_PAGE_PATH;

test.beforeAll(() => {
  if (!fs.existsSync(SEED_PATH)) {
    throw new Error(
      `Missing ${SEED_PATH}. Run the seed first: npm run env:seed`
    );
  }
  seed = JSON.parse(fs.readFileSync(SEED_PATH, 'utf8'));
  expect(seed.manager_page_url, 'seed manager_page_url').toBeTruthy();
  MANAGER_PAGE_PATH = new URL(seed.manager_page_url).pathname;
});

/** Log into wp-admin using the wp-env default credentials (admin / password). */
async function wpAdminLogin(page) {
  await page.goto('/wp-login.php');
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

test.beforeEach(async ({ page }) => {
  await wpAdminLogin(page);
  const resp = await page.goto(MANAGER_PAGE_PATH);
  expect(
    resp && resp.status() !== 404,
    `Expected the seeded manager-form page at ${MANAGER_PAGE_PATH} to exist ` +
      '(bin/e2e-seed.sh should have created it — re-run `npm run env:seed`).'
  ).toBeTruthy();
});

test('front-end manager form: type/registration-mode choosers toggle the conditional sections', async ({ page }) => {
  await page.goto(`${MANAGER_PAGE_PATH}?event_action=new`);

  const typeSelect = page.locator('#anchor_event_type');
  const modeSelect = page.locator('#anchor_event_registration_mode');
  await expect(typeSelect).toBeVisible();
  await expect(modeSelect).toBeVisible();

  const sessionsSection = page.locator('.anchor-event-sessions-table').locator('..');
  const externalSection = page.locator('[data-when-mode="external"]');

  // Default type=single, mode=free for a brand-new event — both conditional
  // sections start hidden.
  await expect(sessionsSection).toBeHidden();
  await expect(externalSection).toBeHidden();

  await typeSelect.selectOption('multisession');
  await expect(sessionsSection).toBeVisible();

  await modeSelect.selectOption('external');
  await expect(externalSection).toBeVisible();
});

test('front-end manager form: create with multisession + external round-trips through save', async ({ page }) => {
  await page.goto(`${MANAGER_PAGE_PATH}?event_action=new`);

  const uniqueTitle = `E2E Manager Multisession Event ${Date.now()}`;
  await page.fill('#anchor_event_title', uniqueTitle);
  await page.fill('#anchor_event_start_date', '2026-09-01');
  await page.locator('#anchor_event_type').selectOption('multisession');
  await page.locator('#anchor_event_registration_mode').selectOption('external');

  await page.locator('.anchor-event-session-add').click();
  const rows = page.locator('.anchor-event-sessions-rows .anchor-event-session-row');
  await expect(rows).toHaveCount(1);
  await rows.nth(0).locator('.anchor-session-date').fill('2026-09-01');
  await rows.nth(0).locator('.anchor-session-start-time').fill('09:00');
  await rows.nth(0).locator('.anchor-session-end-time').fill('10:00');
  await rows.nth(0).locator('.anchor-session-label').fill('Day 1');

  await page.fill('#anchor_event_external_url', 'https://example.test/register-e2e-manager');
  await page.fill('#anchor_event_external_display_price', '$495');
  await page.fill(
    '#anchor_event_external_embed',
    '<iframe src="https://ok.example"></iframe><script>alert(1)</script>'
  );

  await page.getByRole('button', { name: 'Create event' }).click();
  await page.waitForURL(/event_manager_notice=created/, { timeout: 20000 });

  // Back on the list view — find the new event's row and open its edit form
  // to assert everything persisted (this exercises handle_event_manager_save()
  // end to end, including the shared sanitizer via save_event_manager_fields()).
  const item = page.locator('.anchor-event-admin-item', { hasText: uniqueTitle });
  await expect(item).toBeVisible({ timeout: 15000 });
  await item.locator('summary').click();
  await item.getByRole('link', { name: 'Edit', exact: true }).click();
  await page.waitForURL(/event_action=edit/, { timeout: 15000 });

  await expect(page.locator('#anchor_event_type')).toHaveValue('multisession');
  await expect(page.locator('#anchor_event_registration_mode')).toHaveValue('external');
  await expect(page.locator('#anchor_event_external_url')).toHaveValue('https://example.test/register-e2e-manager');
  await expect(page.locator('#anchor_event_external_display_price')).toHaveValue('$495');

  const persistedRows = page.locator('.anchor-event-sessions-rows .anchor-event-session-row');
  await expect(persistedRows).toHaveCount(1);
  await expect(persistedRows.nth(0).locator('.anchor-session-date')).toHaveValue('2026-09-01');
  await expect(persistedRows.nth(0).locator('.anchor-session-label')).toHaveValue('Day 1');

  // Sanitized embed: iframe kept, script stripped — proves the front-end
  // save path used the SAME sanitize_event_type_input()/sanitize_external_embed()
  // helper as the admin metabox save, not a raw store.
  const embedValue = await page.locator('#anchor_event_external_embed').inputValue();
  expect(embedValue).toContain('<iframe');
  expect(embedValue).not.toContain('<script');
});
