// @ts-check
const { execSync } = require('child_process');
const { test, expect } = require('@playwright/test');

/**
 * Anchor Announcements: compose, preview the audience, send a test, send, and see
 * the recipient in the Report box.
 *
 * Requires wp-env running and `npm run env:seed` (which enables the `announcements`
 * module). The wp-env containers have no mail transport, so a throwaway mu-plugin
 * short-circuits wp_mail() (`pre_wp_mail`) for the run and is removed afterwards.
 * The queue is run by hand (`wp cron event run anchor_announcements_tick`) instead
 * of waiting for WP-Cron.
 */

const MU = 'wp-content/mu-plugins/aa-e2e-mail.php';
const RECIPIENT = 'e2e-recipient@example.com';
const RECIPIENT_2 = 'e2e-second@example.com';

function wpEnvCli(cmd) {
  return execSync(`npx wp-env run cli ${cmd}`, { encoding: 'utf8' });
}

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

test.beforeAll(() => {
  wpEnvCli(`bash -c "mkdir -p wp-content/mu-plugins && printf '%s' '<?php add_filter( \\"pre_wp_mail\\", \\"__return_true\\" );' > ${MU}"`);
});

test.afterAll(() => {
  try {
    wpEnvCli(`rm -f ${MU}`);
  } catch (e) {
    // best effort cleanup
  }
});

test('compose, preview audience, test send, send, report', async ({ page: settingsPage, context }) => {
  await wpAdminLogin(settingsPage);

  await settingsPage.goto('/wp-admin/edit.php?post_type=anchor_announcement&page=anchor-announcements-settings');
  await settingsPage.fill('#aa-footer_address', '1 Test Street, Testville');
  await settingsPage.click('#submit');
  await expect(settingsPage.locator('.notice-success')).toContainText('Settings saved');

  // Compose in a fresh tab: after the settings POST the original headless tab stops
  // delivering animation frames, which stalls Playwright's actionability checks.
  let page = await context.newPage();

  await page.goto('/wp-admin/post-new.php?post_type=anchor_announcement');
  await page.fill('#title', 'E2E announcement');
  await page.fill('.anchor-email-builder__subject', 'Hello {first_name}');
  await page.frameLocator('#aa-email-body_ifr').locator('body').fill('Hi there, read https://example.com');
  await expect(page.frameLocator('.anchor-email-builder__frame').locator('body')).toContainText('Hi there');

  await page.click('.aa-add-group');
  await page.selectOption('.aa-cond-type', 'specific_people');
  // Two addresses on separate lines: pins the save round trip (a double unslash used to corrupt the audience JSON).
  await page.fill('.aa-cond-fields textarea', `${RECIPIENT}\n${RECIPIENT_2}`);
  await page.click('#aa-audience-preview');
  await expect(page.locator('#aa-audience-count')).toContainText('2 recipients');

  await Promise.all([
    page.waitForURL(/post\.php\?post=\d+/),
    page.click('button[name="aa_action"][value="save"]'),
  ]);
  // Reload the saved draft in a fresh tab (same headless frame stall as after the settings POST).
  const editUrl = page.url();
  await page.close();
  page = await context.newPage();
  await page.goto(editUrl);
  await page.click('#aa-audience-preview');
  await expect(page.locator('#aa-audience-count')).toContainText('2 recipients');

  await page.click('#aa-test-send');
  await expect(page.locator('#aa-test-result')).toContainText('Test sent');

  page.on('dialog', (d) => d.accept());
  await page.click('#aa-send-now');
  await expect(page.locator('.notice-success', { hasText: 'Sending to 2 people' })).toBeVisible();

  // Run the queue now instead of waiting for WP-Cron, then check the report.
  wpEnvCli('wp cron event run anchor_announcements_tick');
  await page.reload();
  await expect(page.locator('#aa-report tr', { hasText: RECIPIENT })).toContainText(/sent/i);
  await expect(page.locator('#aa-report tr', { hasText: RECIPIENT_2 })).toContainText(/sent/i);
});
