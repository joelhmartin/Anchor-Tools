// @ts-check
const { expect } = require('@playwright/test');

/**
 * Shared consent-banner helper for every spec that CLICKS on a front-end page.
 *
 * The compliance module renders a floating bottom-left gate
 * (`#anchor-cmp.anchor-cmp--gate`) over the page until the visitor decides. It
 * covers the lower part of the viewport, so any click that lands there resolves
 * to a visible, enabled, stable element and then times out after 30s with:
 *
 *     <div id="anchor-cmp" ... class="... anchor-cmp--gate"> intercepts pointer events
 *
 * The gate landed in ce7b6f8 (2026-08-10). It went unnoticed because E2E was
 * dying earlier, at plugin activation, on a vendor autoload drift.
 *
 * We accept through the banner's OWN accept-all button rather than pre-seeding
 * a consent cookie. A hand-rolled cookie payload would drift from the plugin's
 * real cookie shape, and seeding consent globally would quietly weaken
 * e2e/compliance-banner.spec.js, which exists to exercise the undecided-visitor
 * states. That spec deliberately does NOT use this helper.
 *
 * @param {import('@playwright/test').Page} page
 */
async function acceptConsentBanner(page) {
  const acceptAll = page.locator('#anchor-cmp-banner [data-anchor-action="accept-all"]');

  // Wait for the banner rather than sampling isVisible() once: it is injected
  // by the CMP client runtime, so an instantaneous check can race it — report
  // false, skip the accept, and let the banner swallow the first real click.
  try {
    await acceptAll.waitFor({ state: 'visible', timeout: 5000 });
  } catch {
    // Never appeared: consent already settled for this context, or the
    // compliance module is inactive. Either way there is nothing to dismiss.
    return;
  }

  await acceptAll.click();
  await expect(page.locator('#anchor-cmp-banner')).toBeHidden();
}

/**
 * Timezone that pins the consent posture to STRICT.
 *
 * Posture otherwise follows the runner's timezone: a US-local run gets the
 * relaxed opt-out notice, which does NOT block clicks, while CI (UTC) gets the
 * blocking gate, which does. A suite that only ever runs locally would look
 * green and still fail in CI — which is exactly what happened here. Pin every
 * click-driven spec to strict so local runs reproduce CI.
 */
const STRICT_POSTURE_TIMEZONE = 'Europe/Berlin';

module.exports = { acceptConsentBanner, STRICT_POSTURE_TIMEZONE };
