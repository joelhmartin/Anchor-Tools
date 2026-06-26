// @ts-check
const { defineConfig, devices } = require('@playwright/test');

/**
 * Playwright config for the Anchor Tools WooCommerce purchase E2E suite.
 *
 * The WordPress + WooCommerce environment is stood up separately via
 * `@wordpress/env` (Docker) by CI — we deliberately do NOT use a Playwright
 * `webServer`. WooCommerce pages are slow, so timeouts are generous.
 */
module.exports = defineConfig({
  testDir: './e2e',
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  workers: 1,
  timeout: 120000,
  expect: {
    timeout: 30000,
  },
  reporter: [
    ['list'],
    ['html', { open: 'never' }],
  ],
  use: {
    baseURL: process.env.WP_BASE_URL || 'http://localhost:8888',
    headless: true,
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    actionTimeout: 30000,
    navigationTimeout: 60000,
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
});
