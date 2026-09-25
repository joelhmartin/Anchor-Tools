// @ts-check
const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');

/**
 * The hosted stream room (virtual-events spec §8).
 *
 * FIXTURES (bin/e2e-seed.sh -> e2e/.seed.json):
 *   stream_event_token_url        — room URL carrying a valid ?aek= token.
 *   stream_event_room_url         — the same room, clean.
 *   in_person_toggle_off_room_url — an in-person seat on an event whose
 *                                   "in-person also get the stream" is OFF.
 *   in_person_toggle_on_room_url  — the same, with the toggle ON.
 * Run the seed first: `npm run env:seed`.
 */
const SEED_PATH = path.join(__dirname, '.seed.json');
let seed;

test.beforeAll(() => {
  if (!fs.existsSync(SEED_PATH)) {
    throw new Error(`Missing ${SEED_PATH}. Run the seed first: npm run env:seed`);
  }
  seed = JSON.parse(fs.readFileSync(SEED_PATH, 'utf8'));
  expect(seed.stream_event_token_url, 'seed stream_event_token_url').toBeTruthy();
});

test('tokenised link signs a logged-out registrant in and lands them in the room', async ({ page }) => {
  await page.goto(new URL(seed.stream_event_token_url).pathname + new URL(seed.stream_event_token_url).search);

  // The token is consumed and stripped.
  await expect(page).toHaveURL(new RegExp('/live/$'));
  const state = page.locator('.anchor-room-state');
  await expect(state).toHaveAttribute('data-state', 'countdown');
  await expect(page.locator('.anchor-room-countdown')).toBeVisible();
  await expect(page.locator('iframe[src*="player.vimeo.com"]')).toHaveCount(0);
});

test('the room goes live, then ends, as the clock advances', async ({ page }) => {
  await page.goto(new URL(seed.stream_event_token_url).pathname + new URL(seed.stream_event_token_url).search);

  await page.goto(new URL(seed.stream_event_room_url).pathname + '?anchor_stream_now=start');
  await expect(page.locator('.anchor-room-state')).toHaveAttribute('data-state', 'live');
  await expect(page.locator('iframe[src*="player.vimeo.com"]')).toBeVisible();

  await page.goto(new URL(seed.stream_event_room_url).pathname + '?anchor_stream_now=after');
  await expect(page.locator('.anchor-room-state')).toHaveAttribute('data-state', 'ended');
  await expect(page.locator('.anchor-room-status')).toContainText('has ended');
});

test('an in-person seat is denied when the toggle is off and allowed when it is on', async ({ page }) => {
  await page.goto(new URL(seed.in_person_toggle_off_room_url).pathname + new URL(seed.in_person_toggle_off_room_url).search);
  await expect(page.locator('.anchor-room--denied')).toBeVisible();

  // The two fixtures are two DIFFERENT seeded accounts. room_headers()'s
  // one-click sign-in deliberately only fires for a logged-OUT visitor (a
  // token must never silently switch an already-signed-in account), so the
  // cookie the line above just set has to be cleared first — otherwise this
  // second visit stays signed in as the toggle-off account, which has no
  // seat on the toggle-on event either, and the assertion below would be
  // testing that account's (correct) denial rather than the toggle.
  await page.context().clearCookies();

  await page.goto(new URL(seed.in_person_toggle_on_room_url).pathname + new URL(seed.in_person_toggle_on_room_url).search);
  await expect(page.locator('.anchor-room-state')).toBeVisible();
});
