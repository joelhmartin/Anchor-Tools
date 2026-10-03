# Events: "Use external signup form" — design

**Date:** 2026-10-01 · **Module:** anchor-events-manager · **First client:** DEKA Dental Lasers

## Problem

Many client events take sign-ups on a third-party form (JotForm, Google Forms, a partner's
site). The plugin still treats them as native "Free registration" events, so it builds an
empty roster and sends the organizer a roster digest for every one of them. On DEKA
all 22 free-mode Academy courses embed a JotForm through a *theme* metabox
(`_deka_event_reg_embed`), invisible to the plugin. The plugin's own `external` registration
mode exists but takes only a link, sits as a third option in a dropdown, and decommissions
almost nothing (it only suppresses the livestream room).

## Decisions (user, 2026-10-01)

1. **One explicit signal per event: a checkbox, "Use external signup form".** It is the
   plugin's existing `registration_mode = external`, surfaced as a checkbox instead of a
   third dropdown option. Checked → mode `external`. Unchecked → the existing mode select
   (WooCommerce ticketed / Free registration) is shown again and governs. No new mode value;
   no back-compat break for events already saved as `external`.
2. **The form field appears only after the box is checked.** The external section takes
   either a signup URL (existing `external_url`) or a pasted embed code (new
   `external_embed` meta: iframe-only allowlist, multiple iframes allowed, one per session).
   Front end: embed wins; otherwise a "Register" button to the URL (today's behaviour).
3. **Checking it decommissions every setting that only applies to native registration**,
   in the UI (hidden, both wp-admin metabox and front-end creator wizard) and at runtime:
   - no roster: no roster screen/console panel/list entry, no roster digest (scheduled or
     manual), no roster row in Upcoming Sends (`roster_capable()` — already on the branch)
   - no confirmation / reminder / cancellation emails, no organizer "new registration"
     notice; their per-event email-builder sections are hidden
   - no capacity, waitlist, ticket tiers, attendee questions/registration fields, access-role
     grant, livestream room; the native register form / storefront never renders and the
     registration endpoints refuse (existing external-mode behaviour where it exists)
   - settings already stored stay in the DB untouched (unchecking restores them)
4. **The theme-only JotForm field is retired into the plugin field.** DEKA migration: every
   event with `_deka_event_reg_embed` gets mode `external` + `external_embed` copied; the
   theme renders the plugin's field (falls back to its own meta only until migrated) and
   its JotForm metabox is removed. `_deka_event_reg_form_id` (used for JotForm signup
   lookups) is derived from the plugin field.
5. `anchor_events_roster_capable` filter from the WIP commit is dropped (YAGNI) — the
   checkbox is the signal.

## Out of scope

Pulling JotForm submissions into a roster; per-session external forms beyond "multiple
iframes in one field"; changing WooCommerce-mode events.
