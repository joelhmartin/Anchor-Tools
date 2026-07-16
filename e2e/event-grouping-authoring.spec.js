// @ts-check
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');
const { test, expect } = require('@playwright/test');

/**
 * Group authoring UI (spec Phase 2, Task 2.3): the offering-dates repeater +
 * recurrence rule builder metabox controls, and the save -> validate ->
 * Occurrences::reconcile() wiring behind them.
 *
 * FIXTURES: bin/e2e-seed.sh creates (or reuses) two published parent events
 * and writes their ids/urls into e2e/.seed.json:
 *   - `offering_event_id`/`offering_event_url` — type=offering, 2
 *     offering_dates, already reconciled into 2 CHILD event posts.
 *   - `recurring_event_id`/`recurring_event_url` — type=recurring, a
 *     COMPLETE weekly/count=3 rule, already reconciled into 3 children.
 * Run the seed before this spec: `npm run env:seed`.
 *
 * Requires wp-env running (npm run wp-env start) and the wp-env default admin
 * (admin/password).
 *
 * Test 1 drives the seeded OFFERING event through the classic metabox's
 * offering-dates repeater (admin.js's initOfferingRepeater()): edits it from
 * 2 dates to 3 and asserts the "N generated date(s) are currently live"
 * affordance render_group_authoring_sections() renders from
 * Occurrences::children() follows — this is a live, server-verified count of
 * the actual child event posts, not just a UI echo of what was typed.
 *
 * Test 2 proves the VALIDATION GUARD on a freshly created recurring event: an
 * incomplete rule (no count/until) must show persist_group_authoring()'s
 * validation message and generate ZERO children, and completing the rule
 * (setting count=3) must then reconcile it to 3 — proving the guard blocks
 * reconcile() exactly until the rule is complete, never before or after.
 * Test 2 creates its OWN event via post-new.php every run, so it never reads
 * or mutates the seeded `recurring_event_id` fixture and needs no reset.
 *
 * TEST ISOLATION: `offering_event_id` is a SHARED fixture — test 1 itself
 * mutates it in place (2 dates -> 3 dates) as part of what it's testing, so
 * a second full-suite run against the same wp-env DB (no reseed in between)
 * would otherwise find 3+ rows instead of the 2 it asserts as a starting
 * precondition. Test 1 resets the fixture back to its canonical 2-row
 * baseline via wp-cli at its own start (see resetOfferingFixture() below),
 * so its "starts at 2" precondition holds regardless of what any prior test
 * run — this spec's own or another spec's — left behind.
 *
 * The event editor is Gutenberg (block editor): it saves via REST with NO
 * page redirect, so the classic add_query_arg()/admin_notices() notice
 * (still used by the front-end classic manager form) never surfaces here.
 * The validation is instead rendered INLINE inside the metabox
 * (`.anchor-event-recurrence-error`), driven off the STORED recurrence
 * rule, which survives a block-editor save because the metabox regenerates
 * on every full page load / iframe refresh (render_group_authoring_sections()).
 */

const SEED_PATH = path.join(__dirname, '.seed.json');

/** @type {{ offering_event_id: number, offering_event_url: string, recurring_event_id: number, recurring_event_url: string }} */
let seed;

test.beforeAll(() => {
  if (!fs.existsSync(SEED_PATH)) {
    throw new Error(`Missing ${SEED_PATH}. Run the seed first: npm run env:seed`);
  }
  seed = JSON.parse(fs.readFileSync(SEED_PATH, 'utf8'));
  expect(seed.offering_event_id, 'seed offering_event_id').toBeTruthy();
  expect(seed.recurring_event_id, 'seed recurring_event_id').toBeTruthy();
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

/** Dismiss the block editor's first-run "Welcome to the editor" tour, if shown. */
async function dismissWelcomeGuide(page) {
  const closeBtn = page.locator('button[aria-label="Close"]').first();
  if (await closeBtn.isVisible().catch(() => false)) {
    await closeBtn.click();
  }
}

/** Expand the classic-metaboxes "Meta Boxes" panel (see event-authoring.spec.js for why). No-op if already open. */
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

/** yyyy-mm-dd for "today + N days", used to pick dates that can't collide with the seed's own offering rows. */
function futureDateString(daysFromNow) {
  const d = new Date();
  d.setDate(d.getDate() + daysFromNow);
  return d.toISOString().slice(0, 10);
}

/** Run a wp-cli command inside the wp-env `cli` container (same helper pattern as event-upcoming-sends.spec.js). */
function wpEnvCli(cmd) {
  return execSync(`npx wp-env run cli ${cmd}`, { encoding: 'utf8' });
}

/**
 * Reset the shared offering-event fixture's `_anchor_event_offering_dates`
 * meta back to its canonical 2-row baseline. Idempotent and robust to the
 * fixture being in ANY prior state (2 rows, 3 rows, or more) — it overwrites
 * the meta wholesale rather than diffing against whatever is currently stored.
 *
 * Only the meta is reset here (via `wp post meta update`, which needs no
 * plugin classes loaded, so it is robust regardless of wp-env's module-boot
 * state in the wp-cli context). The starting `toHaveCount(2)` assertion reads
 * this meta directly; the test's own edit→save then re-runs reconcile() through
 * the real save path, so no separate reconcile eval is needed (and calling
 * `\Anchor\Events\Module::instance()` from `wp eval` is fragile — it fatals
 * when the events module isn't bootstrapped in that CLI invocation).
 *
 * The +60/+67-day offsets don't need to match the seed script's own
 * `gmdate()` values bit-for-bit (no assertion in this spec checks the exact
 * date strings, only the row/child COUNT), so reuse the existing
 * `futureDateString()` helper.
 */
function resetOfferingFixture(offeringEventId) {
  const offeringDates = JSON.stringify([
    { date: futureDateString(60), start_time: '09:00', end_time: '11:00', label: 'Session A', capacity: 10 },
    { date: futureDateString(67), start_time: '09:00', end_time: '11:00', label: 'Session B', capacity: 10 },
  ]);
  wpEnvCli(
    `wp post meta update ${offeringEventId} _anchor_event_offering_dates '${offeringDates}' --format=json`
  );
}

test.beforeEach(async ({ page }) => {
  await wpAdminLogin(page);
});

test('offering repeater: editing the seeded 2-date event to 3 dates reconciles to 3 live children', async ({ page }) => {
  // Isolation: this test itself edits the fixture from 2 -> 3 rows, so a
  // second run in the same suite pass (no reseed in between) would
  // otherwise inherit the 3-row state this test's LAST run left behind.
  // Reset to the canonical 2-row baseline before asserting the starting
  // count below, regardless of what state the fixture is currently in.
  resetOfferingFixture(seed.offering_event_id);

  await page.goto(`/wp-admin/post.php?post=${seed.offering_event_id}&action=edit`);
  await page.waitForTimeout(1200);
  await dismissWelcomeGuide(page);
  await expandMetaBoxes(page);

  await expect(page.locator('#anchor_event_type')).toHaveValue('offering');

  const rows = page.locator('.anchor-event-offering-rows .anchor-event-offering-row');
  await expect(rows).toHaveCount(2);

  // The seed's own live-count affordance confirms 2 children exist server-side
  // (render_group_authoring_sections() reads this straight from
  // Occurrences::children(), not from what's merely typed in the form).
  await expect(page.locator('.anchor-event-offering-table').locator('..')).toContainText(
    '2 generated dates are currently live.'
  );

  // Add a third row (add/reindex via initOfferingRepeater()) and fill it with
  // a date far outside the seed's own +60/+67-day rows.
  await page.locator('.anchor-event-offering-add').click();
  await expect(rows).toHaveCount(3);
  await rows.nth(2).locator('.anchor-offering-date').fill(futureDateString(200));
  await rows.nth(2).locator('.anchor-offering-start-time').fill('09:00');
  await rows.nth(2).locator('.anchor-offering-end-time').fill('11:00');
  await rows.nth(2).locator('.anchor-offering-label').fill('Session C (E2E)');

  const updateBtn = page.getByRole('button', { name: 'Save', exact: true });
  await updateBtn.click();
  await page.waitForTimeout(800);
  await page.waitForLoadState('networkidle').catch(() => {});

  // Classic-metabox saves run via a separate hidden-iframe POST that can lag
  // the main REST save's response (same race documented in
  // event-authoring.spec.js) — poll with a reload rather than a fixed sleep.
  await expect(async () => {
    await page.reload();
    await page.waitForTimeout(600);
    await expandMetaBoxes(page);
    await expect(rows).toHaveCount(3);
    await expect(page.locator('.anchor-event-offering-table').locator('..')).toContainText(
      '3 generated dates are currently live.'
    );
  }).toPass({ timeout: 20000, intervals: [1000, 1500, 2000, 3000] });
});

test('recurrence guard: an incomplete rule reconciles zero children; completing it reconciles the full count', async ({ page }) => {
  // Two full reload+reconcile round trips (guard check, then completion) —
  // give this one more room than the file/global 120s default rather than
  // widening it for every spec.
  test.setTimeout(240000);
  await page.goto('/wp-admin/post-new.php?post_type=event');
  await page.waitForTimeout(1500);
  await dismissWelcomeGuide(page);
  await expandMetaBoxes(page);

  const titleField = page.frameLocator('iframe[name="editor-canvas"]').getByLabel('Add title');
  const uniqueTitle = 'E2E Recurrence Guard Event ' + Date.now();
  await titleField.fill(uniqueTitle);

  await page.locator('#anchor_event_start_date').fill(futureDateString(300));
  await page.locator('#anchor_event_type').selectOption('recurring');
  await page.locator('#anchor_event_recurrence_freq').selectOption('weekly');
  // Deliberately leave both "End after" and "...or end by date" EMPTY — an
  // INCOMPLETE rule. persist_group_authoring()'s guard must block reconcile().

  const publishBtn = page.getByRole('button', { name: 'Publish', exact: true });
  await publishBtn.click();
  await page.waitForTimeout(800);
  const confirmBtn = page.locator(
    '.editor-post-publish-panel__header-publish-button button, .editor-post-publish-button'
  );
  if (await confirmBtn.first().isVisible().catch(() => false)) {
    await confirmBtn.first().click();
  }
  await page.waitForURL(/post\.php\?post=\d+&action=edit/, { timeout: 30000 });
  await page.waitForLoadState('networkidle').catch(() => {});

  // The guard's validation must appear INLINE inside the metabox (the
  // Gutenberg editor has no page-redirect for the classic admin_notices()
  // query-arg notice to ride on), and the generated-count affordance must
  // NOT (it only renders when Occurrences::children() is non-empty) —
  // together these prove reconcile() never ran for the incomplete rule.
  await expect(async () => {
    await page.reload();
    await page.waitForTimeout(600);
    await expandMetaBoxes(page);
    await expect(
      page.locator('.anchor-event-recurrence-error', { hasText: 'Set an end for the recurrence' })
    ).toBeVisible();
    await expect(page.locator('.anchor-event-recurrence-weekdays').locator('../..')).not.toContainText(
      'generated occurrence'
    );
  }).toPass({ timeout: 20000, intervals: [1000, 1500, 2000, 3000] });

  // Complete the rule (count=3) and re-save.
  await page.locator('#anchor_event_recurrence_count').fill('3');
  const updateBtn = page.getByRole('button', { name: 'Save', exact: true });
  await updateBtn.click();
  await page.waitForTimeout(800);
  await page.waitForLoadState('networkidle').catch(() => {});

  await expect(async () => {
    await page.reload();
    await page.waitForTimeout(600);
    await expandMetaBoxes(page);
    await expect(page.locator('#anchor_event_recurrence_count')).toHaveValue('3');
    await expect(page.locator('.anchor-event-recurrence-weekdays').locator('../..')).toContainText(
      '3 generated occurrences are currently live.'
    );
    // The inline error must be gone now that the rule is complete and
    // reconcile() ran — the guard is validation, not a permanent banner.
    await expect(page.locator('.anchor-event-recurrence-error')).toBeHidden();
  }).toPass({ timeout: 20000, intervals: [1000, 1500, 2000, 3000] });
});
