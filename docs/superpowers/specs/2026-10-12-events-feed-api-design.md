# Events Feed API — Design

**Date:** 2026-10-12
**Status:** Approved in chat (sections 1–3). Site side only; the phone app has its own spec.
**First site:** TMJ & Sleep Therapy Centre International. The feed ships in the events module, so every Anchor site that runs it gets the same API.
**Roadmap:** project 1 of 5 in the member-app roadmap ([Member App v1](2026-10-12-member-app-v1-design.md)). It is the first module API built to the common pattern described there, so later module APIs (courses, library) copy its shape.

---

## Why

Centres want a phone app with a calendar of upcoming courses and events, and a way to register.

The rule is **set it in one place**. Staff manage events, categories, prices, capacity and who may register on the WordPress site. The app only mirrors that data and talks to the site's backend. It holds no event data of its own, and registration stays on the site.

The site already exposes events at `/wp-json/wp/v2/<events CPT>`, with about a dozen meta fields. That endpoint returns raw posts, though: no date-range filtering, recurring and pick-one dates are not expanded, the hidden group parents are included, and there is no registration state or audience. An app built on it would have to re-implement event logic the plugin already owns, and the two would drift apart. This spec adds a purpose-built, read-only feed built on that existing logic.

## Decisions taken in chat

| Question | Decision |
|---|---|
| Who the app is for | Member **Centres**. It shows publicly registrable courses plus RSVP/free events meant only for Centres. |
| How "Centre" is decided | The site's existing **`centre` role** (81 accounts on TMJ). Centres sign in with their own WordPress login. |
| How an event becomes Centre-only | Reuse the event's existing **Prerequisites** (`required_roles`). An event that requires the `centre` role is Centre-only. One setting decides both who may register and who sees the event in the app. |
| How the app signs in | **WordPress Application Passwords**, through core's "authorize application" flow. The app opens the site's own login, the Centre approves, and the app receives a revocable per-device password. No custom auth code. |
| Which events the app shows | A site setting, **App categories** (Events settings), picks the event categories the app shows. It is the feed's default filter, so the admin decides in one place and the app has no category settings of its own. An explicit `category` param still overrides it. |
| Registration (this build) | **Link out**: "Register" opens the event page on the site, so the real checkout, Agreements and emails run unchanged. |
| Later stages (separate specs) | One-tap in-app RSVP for free events; paid events through a cart handoff to the site's own checkout. |
| Row shape | **One row per actual date.** Recurring and pick-one events expand into their dates, and group parents never appear. |

---

## Endpoints

All of these live in the module's existing REST namespace `anchor-events/v1` (`Module::REST_NS`). All are read-only (`GET`).

### `GET /anchor-events/v1/events`

**Query parameters**

| Param | Type | Default | Notes |
|---|---|---|---|
| `from` | `YYYY-MM-DD` | today, in the site's zone | Inclusive. |
| `to` | `YYYY-MM-DD` | `from` + 12 months | Inclusive. The range may not exceed 24 months; a longer one returns 400. |
| `category` | comma-separated slugs | the **App categories** setting; all categories if that is empty | `event_category` slugs. An unknown slug returns 400. |
| `page` | int ≥ 1 | 1 | |
| `per_page` | int 1–100 | 50 | |

**Selection**

- Rows are published events whose time overlaps `[from, to]`. Overlap means `start_ts < end of to` and `end_ts >= start of from`, so a multi-day course that started yesterday still appears.
- Group parents and every other listing-excluded post are removed with the same hide rule the website's listings use.
- Rows are ordered by `start_ts` ascending, with id as the tiebreak.
- The visibility rules below are applied on top.

**Response**: `200` with `{ "events": [ …rows ], "page": n, "per_page": n, "total": n, "total_pages": n }`, plus the `X-WP-Total` and `X-WP-TotalPages` headers.

**Row**

```json
{
  "id": 1120711,
  "parent_id": 1119885,
  "title": "Mini Residency – Toronto",
  "summary": "Plain-text excerpt, ≤ 300 chars",
  "url": "https://site/events/…/",
  "image": "https://site/…/hero.jpg",
  "categories": [ { "slug": "courses", "name": "Courses" } ],
  "start": "2026-11-13T08:00:00-05:00",
  "end": "2026-11-15T17:00:00-05:00",
  "all_day": false,
  "timezone": "America/Toronto",
  "location": { "venue": "…", "city": "Toronto", "state": "ON", "country": "CA", "online": false },
  "registration": {
    "mode": "paid | free | rsvp | external",
    "status": "open | sold_out | waitlist | closed | not_open_yet",
    "price": "950.00",
    "currency": "USD",
    "register_url": "https://site/events/…/"
  },
  "audience": "public | restricted"
}
```

- **Text fields:** `title` and `summary` are plain text with entities decoded. No HTML is sent.
- **Times:** `start` and `end` are ISO 8601 strings in the event's own timezone.
- **Mapping:** the field mapping is pinned in the implementation plan. Values come from the sources listed in "Reuse", never from parallel calculations.

### `GET /anchor-events/v1/categories`

Returns `{ "categories": [ { "slug", "name", "count" } ] }`. These are the `event_category` terms that have at least one event the caller could see, so a public caller never learns that Centre-only categories exist. It is limited to the App categories setting when that is set. The app can use it for a filter chip row; v1 does not need it to work.

### `GET /anchor-events/v1/me`

- **Signed in:** returns `{ "name", "sees_restricted": bool }`. `sees_restricted` is true when the caller meets the Prerequisites of at least one published, upcoming restricted event (on TMJ, a `centre` account). No role names are hard-coded.
- **Not signed in:** returns 401. The app uses this to show "Signed in as …" and to confirm its key works.

---

## Visibility and sign-in

- **Signed-out caller:** sees only events with **no Prerequisites**. Those rows carry `audience: public`.
- **Signed-in caller:** an Application Password sent as HTTP Basic over HTTPS. Core resolves the user before the route runs. The caller also sees events whose Prerequisites they meet, according to `Entitlements::meets_prerequisites( $event_id, $user_id )`. Those rows carry `audience: restricted`.
- **One rule for site and app:** the app can never show an event the site would not let that user register for, because both ask the same function.
- **Bad or revoked key:** core rejects it with `401` before the route runs. The app then drops to the public view.
- **No personal data, ever:** no rosters, registrant or seat counts, attendee data, email templates or private meta. Rows hold only what the event's public page already shows, plus the restricted events for callers who qualify.
- **Pre-flight check:** confirm that Application Passwords are available on the target site (`wp_is_application_passwords_available()`). Some security plugins disable them. If they are off, that is a site setting to flip, not a design change.

## App categories setting

- A multi-select of `event_category` terms on the Events settings tab, labelled **App categories**, with help text "Categories the member app shows. Leave empty to show all."
- Stored as an array of term IDs (not slugs, so renaming a category doesn't break it) in its own option with `autoload=false`. Deleted terms are ignored.
- Saving it bumps the cache generation, so app ETags change.

## Reuse (no second implementation)

| Need | Existing owner |
|---|---|
| "Events in a time range" query | The meta query inside `render_calendar_month()` (`start_ts` range + `build_hide_clause()` + `build_visibility_clause()`, through `get_cached_ids()`). **Extract it into one shared range-query method.** The month calendar and the feed both call it, and the calendar's output must not change. The feed uses the overlap form of the range; the calendar keeps its current form. |
| Row data (title, dates, place, offers, image, url) | `Event_Schema::for_event()`, the schema.org node the site already prints for Google. Rows are mapped from it, so the app, the website and Google agree. |
| Registration mode | `Module::registration_mode()` and `Module::uses_external_signup()` |
| Registration status | `Module::bookability()` and `Module::is_bookable()` |
| Who may see restricted events | `Entitlements::meets_prerequisites()` |
| Caching | The module's cache generation (`CACHE_VERSION_OPTION`, `clear_caches()`) |

## Caching

- **ETag:** each response carries a weak `ETag`, built from the cache generation, the normalised query and the caller's visibility tier (public, or the user's role set). A matching `If-None-Match` returns `304` with no body.
- **Cache-Control:** responses send `Cache-Control: private, max-age=300`. They must never be cached publicly, because restricted rows depend on who is asking. Signed-out responses could be cached publicly; that is left out for now.

## Errors

| Case | Response |
|---|---|
| Bad `from`/`to`, range over 24 months, `to` before `from` | `400 anchor_events_bad_range` |
| Unknown category slug | `400 anchor_events_bad_category` |
| Bad or revoked credentials | `401`, from core |
| `/me` without credentials | `401 anchor_events_not_signed_in` |
| An event with incomplete data (no start, no schema node) | Skipped silently. One bad post must never break the feed. |

## Testing (PHPUnit, existing events test base)

- **Expansion:** recurring and pick-one events expand to one row per live child, the parent never appears, and drafts and private events are excluded.
- **Range overlap:** a course that started before `from` but ends inside the range is included; events outside the range are not.
- **Category filter:** filters correctly, and an unknown slug returns 400.
- **App categories:** with the setting saved and no `category` param, only those categories return; an explicit `category` overrides it; an empty setting returns all.
- **Visibility:**
  - Signed out: Prerequisite events are absent.
  - Signed in as a `centre` user: they appear with `audience: restricted`.
  - A user without the role: they stay absent.
- **Categories endpoint:** never lists a category whose only events are hidden from the caller.
- **`/me`:** returns 401 when signed out; `sees_restricted` is true for a Centre when a Centre-only event exists, and false for an ordinary customer.
- **Mapping:** row fields match `Event_Schema::for_event()` for the same event, and `registration.status` matches `bookability()`.
- **ETag:** 304 on a repeat request, and a changed ETag after `clear_caches()`.
- **Calendar regression:** the month calendar's output is unchanged after the query extraction.
- **Docs:** `EVENTS.md` gains a "REST feed" section (endpoints, params, row, auth, App categories) for the app's developers.

## Out of this spec

| Item | Where it goes |
|---|---|
| The phone app (screens, calendar UI, offline cache, store publishing) | [Member App v1](2026-10-12-member-app-v1-design.md) |
| In-app RSVP for free events (`POST /events/{id}/register`, attendee-question schema) | A later spec |
| Paid registration (cart handoff to the site's checkout) | A later spec |
| Multi-site aggregation | Not needed. The app is configured per site. |
