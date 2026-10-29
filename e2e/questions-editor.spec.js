// @ts-check
const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
const { acceptConsentBanner, STRICT_POSTURE_TIMEZONE } = require('./helpers/consent');

/**
 * The shared question-row editor (includes/questions/assets/questions-editor.js),
 * driven through the one surface it renders on today: the front-end
 * [event_manager] form's "Attendee questions" step. Covers add, per-type
 * Choices toggle, remove-with-renumber, and the save round-trip.
 *
 * Needs the seed (`npm run env:seed`) for manager_page_url in e2e/.seed.json.
 */

const SEED_PATH = path.join(__dirname, '.seed.json');
let MANAGER_PAGE_PATH;

test.beforeAll(() => {
  if (!fs.existsSync(SEED_PATH)) {
    throw new Error(`Missing ${SEED_PATH}. Run the seed first: npm run env:seed`);
  }
  const seed = JSON.parse(fs.readFileSync(SEED_PATH, 'utf8'));
  expect(seed.manager_page_url, 'seed manager_page_url').toBeTruthy();
  MANAGER_PAGE_PATH = new URL(seed.manager_page_url).pathname;
});

async function wpAdminLogin(page) {
  await page.goto('/wp-login.php');
  if (!(await page.locator('#user_login').isVisible().catch(() => false))) {
    return;
  }
  await page.fill('#user_login', 'admin');
  await page.fill('#user_pass', 'password');
  await Promise.all([page.waitForURL(/wp-admin/), page.click('#wp-submit')]);
}

test.use({ timezoneId: STRICT_POSTURE_TIMEZONE });

test.beforeEach(async ({ page }) => {
  await wpAdminLogin(page);
  await page.goto(MANAGER_PAGE_PATH);
  await acceptConsentBanner(page);
});

test('question editor: add, type toggle, remove renumbers, and save persists in order', async ({ page }) => {
  await page.goto(`${MANAGER_PAGE_PATH}?event_action=new`);
  await acceptConsentBanner(page);

  const uniqueTitle = `E2E Questions Editor ${Date.now()}`;
  await page.fill('#anchor_event_title', uniqueTitle);
  await page.fill('#anchor_event_start_date', '2026-09-01');

  // Step 4 holds the repeater when the form is a wizard; otherwise it is on the page already.
  const stepNav = page.locator('[data-step-nav="4"]');
  if (await stepNav.count()) {
    await stepNav.first().click();
  }

  const editor = page.locator('.anchor-questions-editor');
  await expect(editor).toBeVisible();
  const rows = editor.locator('.anchor-question-row');
  const add = editor.locator('.anchor-question-add');

  await add.click();
  await add.click();
  await add.click();
  await expect(rows).toHaveCount(3);

  await rows.nth(0).locator('input[name$="[label]"]').fill('First question');
  await rows.nth(1).locator('input[name$="[label]"]').fill('Middle question');
  await rows.nth(2).locator('input[name$="[label]"]').fill('Third question');

  // Choices only shows for a choice type.
  await rows.nth(1).locator('.anchor-question-type').selectOption('select');
  await expect(rows.nth(1).locator('textarea[name$="[options]"]')).toBeVisible();
  await expect(rows.nth(0).locator('textarea[name$="[options]"]')).toBeHidden();
  await rows.nth(1).locator('textarea[name$="[options]"]').fill('Red\nBlue');

  // Remove the middle one; the survivors renumber with labels intact.
  await rows.nth(1).locator('.anchor-question-remove').click();
  await expect(rows).toHaveCount(2);
  await expect(rows.nth(0).locator('input[name$="[label]"]')).toHaveAttribute('name', 'anchor_event_questions[0][label]');
  await expect(rows.nth(0).locator('input[name$="[label]"]')).toHaveValue('First question');
  await expect(rows.nth(1).locator('input[name$="[label]"]')).toHaveAttribute('name', 'anchor_event_questions[1][label]');
  await expect(rows.nth(1).locator('input[name$="[label]"]')).toHaveValue('Third question');

  // Walk to the last step if this is a wizard, then save.
  const create = page.getByRole('button', { name: 'Create event' });
  for (let i = 0; i < 6 && !(await create.isVisible().catch(() => false)); i++) {
    const next = page.locator('[data-step-next], .anchor-event-wizard-next').first();
    if (!(await next.count())) break;
    await next.click();
  }
  await create.click();
  await page.waitForURL(/event_manager_notice=created/, { timeout: 20000 });

  const item = page.locator('.anchor-event-admin-item', { hasText: uniqueTitle });
  await expect(item).toBeVisible({ timeout: 15000 });
  await item.locator('summary').click();
  await item.getByRole('link', { name: 'Edit', exact: true }).click();
  await page.waitForURL(/event_action=edit/, { timeout: 15000 });

  const edit = page.locator('[data-step-nav="4"]');
  if (await edit.count()) {
    await edit.first().click();
  }
  const saved = page.locator('.anchor-questions-editor .anchor-question-row');
  await expect(saved).toHaveCount(2);
  await expect(saved.nth(0).locator('input[name$="[label]"]')).toHaveValue('First question');
  await expect(saved.nth(1).locator('input[name$="[label]"]')).toHaveValue('Third question');
});
