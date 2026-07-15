// @ts-check
const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');

/**
 * Front-end Event JSON-LD emission (Phase 4, Task 4.2): a single-event view
 * carries a `<script type="application/ld+json">` Event node built from
 * Event_Schema::for_event() (Task 4.1). Public, unauthenticated pages — no
 * login required.
 *
 * FIXTURES: bin/e2e-seed.sh creates (or reuses) published events and writes
 * their ids/urls into e2e/.seed.json:
 *   - event_url              — plain single event (wc registration mode).
 *   - multisession_event_url — type=multisession, 3 sessions.
 *   - offering_event_url     — type=offering (group parent), reconciled into
 *                               >=2 live CHILD event posts (see
 *                               event-grouping-frontend.spec.js's note: this
 *                               shared fixture can grow from 2 to 3 live
 *                               dates depending on what ran earlier in a full
 *                               suite run, so this spec reads the live count
 *                               from the same page's "choose a date" picker
 *                               rather than hardcoding it).
 * Run the seed before this spec: `npm run env:seed`.
 */

const SEED_PATH = path.join(__dirname, '.seed.json');

/** @type {{ event_url: string, multisession_event_url: string, offering_event_url: string }} */
let seed;

test.beforeAll(() => {
  if (!fs.existsSync(SEED_PATH)) {
    throw new Error(`Missing ${SEED_PATH}. Run the seed first: npm run env:seed`);
  }
  seed = JSON.parse(fs.readFileSync(SEED_PATH, 'utf8'));
  expect(seed.event_url, 'seed event_url').toBeTruthy();
  expect(seed.multisession_event_url, 'seed multisession_event_url').toBeTruthy();
  expect(seed.offering_event_url, 'seed offering_event_url').toBeTruthy();
});

/**
 * Extract and JSON.parse the page's Event-typed ld+json script.
 *
 * The page can carry OTHER ld+json scripts unrelated to this task (e.g. a
 * theme-emitted BreadcrumbList) — this locates the one Event.for_event()
 * emitted by @type, rather than assuming there is only one ld+json script on
 * the page, and fails loudly if none or more than one Event script exists.
 *
 * @param {import('@playwright/test').Page} page
 */
async function getEventJsonLd(page) {
  const scripts = page.locator('script[type="application/ld+json"]');
  await expect(scripts.first()).toBeAttached();
  const count = await scripts.count();
  const parsed = [];
  for (let i = 0; i < count; i++) {
    const raw = await scripts.nth(i).textContent();
    if (!raw) continue;
    try {
      parsed.push(JSON.parse(raw));
    } catch (e) {
      // Not our concern here — skip malformed unrelated scripts.
    }
  }
  const eventNodes = parsed.filter((d) => d && d['@type'] === 'Event');
  expect(eventNodes.length, 'expected exactly one Event ld+json node on the page').toBe(1);
  return eventNodes[0];
}

test('single event page: emits one valid Event JSON-LD script with @context and startDate', async ({ page }) => {
  const resp = await page.goto(new URL(seed.event_url).pathname);
  expect(resp && resp.status() !== 404, 'Expected the seeded event to exist.').toBeTruthy();

  const data = await getEventJsonLd(page);

  expect(data['@context']).toBe('https://schema.org');
  expect(data['@type']).toBe('Event');
  expect(typeof data.startDate).toBe('string');
  expect(data.startDate.length).toBeGreaterThan(0);
  expect(data.url).toContain(new URL(seed.event_url).pathname);
});

test('multisession event page: Event JSON-LD subEvent array holds every session', async ({ page }) => {
  const resp = await page.goto(new URL(seed.multisession_event_url).pathname);
  expect(resp && resp.status() !== 404, 'Expected the seeded multisession event to exist.').toBeTruthy();

  const data = await getEventJsonLd(page);

  expect(data['@type']).toBe('Event');
  expect(Array.isArray(data.subEvent)).toBe(true);
  expect(data.subEvent.length).toBe(3);
  for (const sub of data.subEvent) {
    expect(sub['@type']).toBe('Event');
    expect(typeof sub.startDate).toBe('string');
  }
});

test('offering (group parent) page: Event JSON-LD subEvent carries every live child date — Google sees every date', async ({ page }) => {
  const resp = await page.goto(new URL(seed.offering_event_url).pathname);
  expect(resp && resp.status() !== 404, 'Expected the seeded offering event to exist.').toBeTruthy();

  // Ground truth: the same page's "choose a date" picker row count (Task
  // 2.4 front-end) is the actual number of live children right now.
  const liveChildRowCount = await page.locator('.anchor-event-choose-date-row').count();
  expect(liveChildRowCount, 'the offering parent must have at least its 2 seeded live dates').toBeGreaterThanOrEqual(2);

  const data = await getEventJsonLd(page);

  expect(data['@type']).toBe('Event');
  expect(Array.isArray(data.subEvent)).toBe(true);
  expect(data.subEvent.length).toBe(liveChildRowCount);
  for (const sub of data.subEvent) {
    expect(sub['@type']).toBe('Event');
    expect(typeof sub.startDate).toBe('string');
  }

  // Distinct dates — every child's own date is represented, not one date
  // repeated (the literal "Google only sees one date" regression this fixes).
  const uniqueStarts = new Set(data.subEvent.map((/** @type {any} */ s) => s.startDate));
  expect(uniqueStarts.size).toBe(liveChildRowCount);
});
