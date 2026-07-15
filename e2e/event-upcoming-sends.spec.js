// @ts-check
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');
const { test, expect } = require('@playwright/test');

/**
 * Task 3.3 — read-only "upcoming sends" schedule panel (side metabox
 * `anchor_event_upcoming_sends`, rendered by
 * Module::render_upcoming_sends_metabox() over Module::compute_email_schedule()).
 *
 * Light smoke test only: the exhaustive logic (offsets, sent/partial/past
 * states, roster digest, disabled/no-start notices, grouped-parent vs.
 * -child) is covered by PHPUnit (tests/test-event-upcoming-sends.php)
 * against compute_email_schedule() directly. This spec just proves the
 * metabox actually renders those computed rows in a real wp-admin page.
 *
 * FIXTURE: reuses the existing seeded `event` fixture (slug
 * `e2e-test-event`, id read from e2e/.seed.json as `event_id`) — no seed
 * script changes. The seed event's start_ts is far enough in the future
 * (see bin/e2e-seed.sh) that a reminder offset of a few weeks and a roster
 * offset of ~a week both land in the future, so both rows come back
 * "Scheduled" without needing to touch the event's own meta.
 *
 * Settings: this spec flips the SITE-WIDE `anchor_events_settings` option
 * (reminder_enabled / organizer_roster_email) via `wp option update` run
 * inside the wp-env `cli` container — no admin-init sanitize callback runs
 * under WP-CLI, so the JSON payload lands verbatim. The prior option value
 * is captured in beforeAll and restored in afterAll so this spec doesn't
 * leak global state into any other spec file sharing the same wp-env DB.
 *
 * Requires wp-env running (npm run wp-env start) and the seed fixture
 * (npm run env:seed).
 */

const SEED_PATH = path.join(__dirname, '.seed.json');
const OPTION_NAME = 'anchor_events_settings';

/** @type {{ event_id: number }} */
let seed;
/** @type {string|null} JSON of the option's value before this spec ran, or null if unset. */
let previousOptionJson = null;

function wpEnvCli(cmd) {
  return execSync(`npx wp-env run cli ${cmd}`, { encoding: 'utf8' });
}

test.beforeAll(() => {
  if (!fs.existsSync(SEED_PATH)) {
    throw new Error(`Missing ${SEED_PATH}. Run the seed first: npm run env:seed`);
  }
  seed = JSON.parse(fs.readFileSync(SEED_PATH, 'utf8'));
  expect(seed.event_id, 'seed event_id').toBeTruthy();

  try {
    previousOptionJson = wpEnvCli(`wp option get ${OPTION_NAME} --format=json`).trim();
  } catch {
    previousOptionJson = null; // option didn't exist yet — fine, restore by deleting.
  }

  const fixture = JSON.stringify({
    reminder_enabled: true,
    reminder_offsets: '20',
    organizer_roster_email: true,
    roster_auto_offset: 10,
    organizer_email: 'organizer@example.test',
  });
  wpEnvCli(`wp option update ${OPTION_NAME} '${fixture}' --format=json`);
});

test.afterAll(() => {
  if (previousOptionJson) {
    wpEnvCli(`wp option update ${OPTION_NAME} '${previousOptionJson}' --format=json`);
  } else {
    try {
      wpEnvCli(`wp option delete ${OPTION_NAME}`);
    } catch {
      // Nothing to clean up.
    }
  }
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

test.beforeEach(async ({ page }) => {
  await wpAdminLogin(page);
});

test('Upcoming Sends metabox lists the scheduled reminder + roster rows for a normal event', async ({ page }) => {
  await page.goto(`/wp-admin/post.php?post=${seed.event_id}&action=edit`);
  await page.waitForTimeout(1200);
  await expandMetaBoxes(page);

  const panel = page.locator('.anchor-upcoming-sends');
  await expect(panel).toBeVisible({ timeout: 15000 });

  // No "reminders are off" style notice — settings are on and the event has
  // a future start_ts, so it must render actual rows, not the explanatory text.
  await expect(panel.locator('p.description')).toHaveCount(0);

  const rows = panel.locator('.anchor-upcoming-send');
  await expect(rows).toHaveCount(2);

  const reminderRow = panel.locator('.anchor-upcoming-send', { hasText: 'Reminder' });
  await expect(reminderRow).toContainText('Scheduled');
  await expect(reminderRow).toContainText('confirmed attendee');

  const rosterRow = panel.locator('.anchor-upcoming-send', { hasText: 'Roster digest' });
  await expect(rosterRow).toContainText('Scheduled');
  await expect(rosterRow).toContainText('organizer@example.test');

  // Read-only: no send/reschedule controls anywhere in the panel.
  await expect(panel.locator('button, input[type="submit"]')).toHaveCount(0);
});
