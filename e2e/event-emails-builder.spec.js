// @ts-check
const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');

/**
 * "Emails" metabox (Task 3.2): the per-event lifecycle-email builder —
 * Monaco HTML editor + token palette + live (raw) preview + "Preview with
 * real data" AJAX + persistence — layered over Task 3.1's template model.
 *
 * FIXTURE: reuses the existing plain `event` fixture bin/e2e-seed.sh already
 * creates for the purchase spec (slug `e2e-test-event`, title "E2E Test
 * Event"), read from e2e/.seed.json as `event_id` — no seed changes needed.
 * Run the seed first: `npm run env:seed`.
 *
 * Requires wp-env running (npm run wp-env start) and outbound network access
 * to cdn.jsdelivr.net (Monaco is loaded from the CDN — see includes/class-anchor-monaco.php).
 */

const SEED_PATH = path.join(__dirname, '.seed.json');

/** @type {{ event_id: number }} */
let seed;

test.beforeAll(() => {
  if (!fs.existsSync(SEED_PATH)) {
    throw new Error(`Missing ${SEED_PATH}. Run the seed first: npm run env:seed`);
  }
  seed = JSON.parse(fs.readFileSync(SEED_PATH, 'utf8'));
  expect(seed.event_id, 'seed event_id').toBeTruthy();
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

/** Expand the classic-metaboxes "Meta Boxes" panel (see event-authoring.spec.js). No-op if already open. */
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

async function openEventEditor(page, eventId) {
  await page.goto(`/wp-admin/post.php?post=${eventId}&action=edit`);
  await page.waitForTimeout(1200);
  await expandMetaBoxes(page);
}

test.beforeEach(async ({ page }) => {
  await wpAdminLogin(page);
});

test('Emails metabox: edit the Reminder template, see the live preview update, preview with real data, and persist on Publish', async ({ page }) => {
  await openEventEditor(page, seed.event_id);

  const builder = page.locator('.anchor-email-builder');
  await expect(builder).toBeVisible({ timeout: 15000 });

  // Switch to the Reminder tab.
  await page.locator('.anchor-email-tab[data-email-type="reminder"]').click();
  const reminderPanel = page.locator('.anchor-email-panel[data-email-type="reminder"]');
  await expect(reminderPanel).toBeVisible();

  const reminderTextarea = page.locator('#anchor_email_tpl_reminder');

  // Monaco loads asynchronously from the CDN (see includes/class-anchor-monaco.php).
  // Wait for the editor widget to mount inside the Reminder panel specifically —
  // all four email-type tabs render their own `.anchor-monaco` wrapper up front,
  // so this locator must stay scoped to the active panel.
  const monacoEditor = reminderPanel.locator('.monaco-editor');
  await expect(monacoEditor).toBeVisible({ timeout: 20000 });

  // Edit: click into the editor and type a unique marker. Monaco's
  // onDidChangeContent handler (assets/anchor-monaco.js) mirrors the new
  // value into the hidden #anchor_email_tpl_reminder textarea and dispatches
  // a bubbling 'input' event on it — the same signal a plain textarea edit
  // would produce, which is what email-builder.js's live-preview + save
  // paths both listen for.
  const marker = `E2E-MARKER-${Date.now()}`;
  await monacoEditor.click({ position: { x: 10, y: 10 } });
  await page.keyboard.type(marker + ' ');

  await expect(async () => {
    const value = await reminderTextarea.inputValue();
    expect(value).toContain(marker);
  }).toPass({ timeout: 10000 });

  // Live (raw, tokens literal) preview updates via the debounced srcdoc —
  // confirm the marker itself shows up in the preview iframe.
  const rawPreviewFrame = page.frameLocator('.anchor-email-preview-frame[data-email-type="reminder"]');
  await expect(rawPreviewFrame.locator('body')).toContainText(marker, { timeout: 10000 });

  // "Preview with real data": AJAX-renders the CURRENT (unsaved) editor
  // content through build_registration_email_html() with real event tokens
  // expanded — the default reminder shell already includes {event_title} in
  // its <h1>, so the seeded event's real title ("E2E Test Event") appearing
  // in the SAME iframe proves the real-data render replaced the literal
  // token, not just re-echoing the raw template again.
  await page.locator('.anchor-email-preview-real[data-email-type="reminder"]').click();
  await expect(rawPreviewFrame.locator('body')).toContainText('E2E Test Event', { timeout: 15000 });
  await expect(rawPreviewFrame.locator('body')).toContainText(marker, { timeout: 15000 });

  // Save (persists via the dedicated save_email_templates() path). The seeded
  // fixture is already published, so the primary editor button reads "Save"
  // rather than "Publish" (event-authoring.spec.js's fixtures are always
  // brand-new drafts, hence "Publish" there).
  const saveBtn = page.getByRole('button', { name: /^(Publish|Update|Save)$/, exact: true });
  await saveBtn.click();
  await page.waitForTimeout(800);
  const confirmBtn = page.locator(
    '.editor-post-publish-panel__header-publish-button button, .editor-post-publish-button'
  );
  if (await confirmBtn.first().isVisible().catch(() => false)) {
    await confirmBtn.first().click();
  }
  await page.waitForURL(/post\.php\?post=\d+&action=edit/, { timeout: 30000 });

  // The block editor's REST save and the classic-metabox hidden-iframe POST
  // (which is what actually runs save_meta()/save_email_templates()) are two
  // separate requests — poll with a reload rather than a single fixed sleep,
  // matching event-authoring.spec.js's own pattern for the same race.
  await page.waitForLoadState('networkidle').catch(() => {});
  await expect(async () => {
    await page.reload();
    await page.waitForTimeout(600);
    await expandMetaBoxes(page);
    await page.locator('.anchor-email-tab[data-email-type="reminder"]').click();
    const value = await page.locator('#anchor_email_tpl_reminder').inputValue();
    expect(value).toContain(marker);
  }).toPass({ timeout: 20000, intervals: [1000, 1500, 2000, 3000] });

  // Reopen (fresh navigation) confirms the override actually round-tripped
  // through the database, not just an in-page DOM artifact from the reload loop above.
  await openEventEditor(page, seed.event_id);
  await page.locator('.anchor-email-tab[data-email-type="reminder"]').click();
  await expect(page.locator('#anchor_email_tpl_reminder')).toHaveValue(new RegExp(marker));

  // Other email-type tabs (e.g. Confirmation) were never touched — they must
  // still resolve to the default template (no cross-type bleed).
  await page.locator('.anchor-email-tab[data-email-type="confirmation"]').click();
  const confirmationValue = await page.locator('#anchor_email_tpl_confirmation').inputValue();
  expect(confirmationValue).not.toContain(marker);
});
