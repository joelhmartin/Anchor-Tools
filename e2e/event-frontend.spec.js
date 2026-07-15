// @ts-check
const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');

/**
 * Front-end single-event render (Task 1.6): the multi-session "Sessions"
 * list, the external-registration link/embed block, and the display-only
 * price label — public, unauthenticated pages, no login required.
 *
 * FIXTURES: bin/e2e-seed.sh creates (or reuses) three published events and
 * writes their ids/urls into e2e/.seed.json:
 *   - multisession_event_url  — type=multisession, 3 sessions.
 *   - external_event_url      — registration_mode=external, link variant
 *                                (external_url + external_display_price, no embed).
 *   - external_embed_event_url — registration_mode=external, embed variant
 *                                 (external_embed iframe + external_display_price).
 * Run the seed before this spec: `npm run env:seed`.
 */

const SEED_PATH = path.join(__dirname, '.seed.json');

/** @type {{
 *   multisession_event_url: string,
 *   external_event_url: string,
 *   external_embed_event_url: string,
 * }} */
let seed;

test.beforeAll(() => {
  if (!fs.existsSync(SEED_PATH)) {
    throw new Error(
      `Missing ${SEED_PATH}. Run the seed first: npm run env:seed`
    );
  }
  seed = JSON.parse(fs.readFileSync(SEED_PATH, 'utf8'));
  expect(seed.multisession_event_url, 'seed multisession_event_url').toBeTruthy();
  expect(seed.external_event_url, 'seed external_event_url').toBeTruthy();
  expect(seed.external_embed_event_url, 'seed external_embed_event_url').toBeTruthy();
});

test('multisession event: the Sessions block lists each session date', async ({ page }) => {
  const resp = await page.goto(new URL(seed.multisession_event_url).pathname);
  expect(resp && resp.status() !== 404, 'Expected the seeded multisession event to exist.').toBeTruthy();

  const sessions = page.locator('.anchor-event-sessions');
  await expect(sessions).toBeVisible();
  await expect(sessions.locator('.anchor-event-sessions-title')).toHaveText('Sessions');

  const rows = sessions.locator('.anchor-event-sessions-list tbody tr');
  await expect(rows).toHaveCount(3);
  await expect(sessions).toContainText('Day 1: Orientation');
  await expect(sessions).toContainText('Day 2: Workshop');
  await expect(sessions).toContainText('Day 3: Wrap-up');
});

test('external event (link variant): shows the register link + display price, no cart/ticket UI', async ({ page }) => {
  const resp = await page.goto(new URL(seed.external_event_url).pathname);
  expect(resp && resp.status() !== 404, 'Expected the seeded external (link) event to exist.').toBeTruthy();

  const block = page.locator('.anchor-event-registration-external');
  await expect(block).toBeVisible();

  const link = block.locator('a.anchor-event-register');
  await expect(link).toBeVisible();
  await expect(link).toHaveAttribute('href', 'https://example.test/e2e-external-register');

  await expect(block.locator('.anchor-event-external-price')).toHaveText('$495');

  // No cart/ticket UI: the free inline registration form and any
  // add-to-cart/checkout controls must not appear.
  await expect(page.locator('form.anchor-event-registration')).toHaveCount(0);
  await expect(page.locator('button[name="add-to-cart"]')).toHaveCount(0);
  await expect(page.locator('.anchor-event-ticket-type')).toHaveCount(0);
});

test('external event (embed variant): renders the iframe + display price, no cart/ticket UI', async ({ page }) => {
  const resp = await page.goto(new URL(seed.external_embed_event_url).pathname);
  expect(resp && resp.status() !== 404, 'Expected the seeded external (embed) event to exist.').toBeTruthy();

  const block = page.locator('.anchor-event-registration-external');
  await expect(block).toBeVisible();

  // The embed must render as a real, visible <iframe> element (proving it
  // was echoed as HTML, not escaped to literal `&lt;iframe&gt;` text).
  const iframe = block.locator('.anchor-event-external-embed iframe');
  await expect(iframe).toHaveAttribute('src', 'https://example.test/e2e-embed');

  await expect(block.locator('.anchor-event-external-price')).toHaveText('$150');

  // The page's raw HTML must never contain a literal escaped iframe tag.
  const html = await page.content();
  expect(html).not.toContain('&lt;iframe');

  await expect(page.locator('form.anchor-event-registration')).toHaveCount(0);
  await expect(page.locator('button[name="add-to-cart"]')).toHaveCount(0);
});
