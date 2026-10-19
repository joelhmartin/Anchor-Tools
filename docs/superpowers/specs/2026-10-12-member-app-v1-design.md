# Member App v1 — Design

**Date:** 2026-10-12
**Status:** Approved in chat (sections 1–3).
**First client:** TMJ & Sleep Therapy Centre International. DEKA (or another Anchor site) is next.
**Where the code lives:** a new repo, `anchor-member-app`. This spec lives in Anchor Tools until that repo exists, then moves there.
**Depends on:** [Events Feed API](2026-10-12-events-feed-api-design.md), project 1 of the roadmap below.

---

## Why

Each client wants a branded phone app for their members (on TMJ, the Centres). The app shows the events and courses they can attend, and later their courses, videos and library.

The rule is **one place to upload everything**: the client's WordPress site. The app holds no content of its own. It mirrors what the site exposes through Anchor Tools' REST APIs and sends people back to the site for anything transactional (registration, checkout, agreements).

## Roadmap (five projects, each with its own spec → plan → build)

| # | Project | Lives in |
|---|---|---|
| 1 | **Events feed API**: `anchor-events/v1` events, categories, me | Anchor Tools (events module) |
| 2 | **App v1**: Events + Account tabs (this spec) | `anchor-member-app` |
| 3 | **Courses in the app**: uses the existing `anchor-courses/v1` API | `anchor-member-app` (+ small API gaps in Anchor Tools) |
| 4 | **Library module**: Private File Manager moved into Anchor Tools, with a REST API, role-based folders and server-side Vimeo links | Anchor Tools |
| 5 | **Offline video downloads** in the app | `anchor-member-app` (+ download-link endpoint from 4) |

### Common module API pattern

Every module the app reads follows the same shape, so the app has one API client and one auth path:

- Namespace `anchor-<module>/v1`, read-only `GET` routes for listing content.
- Auth is a WordPress Application Password sent as HTTP Basic. Signed out means public content only.
- Who sees what is decided by the module's existing permission logic (Prerequisites, course access, folder policies). The API never re-implements it.
- Responses are plain JSON with plain-text fields, a weak `ETag` and `Cache-Control: private, max-age=300`.
- Errors are `WP_Error` with `anchor_<module>_<reason>` codes. A missing route (404) means "this site needs an Anchor Tools update".
- No personal data beyond the caller's own name.
- Secrets (the Vimeo token, for example) never leave the site. The site hands the app short-lived links after checking access.

## Decisions taken in chat

| Question | Decision |
|---|---|
| One app or one per client | **One app per client** (white-label), built from **one codebase** with per-client config folders. |
| Store accounts | **iOS:** each client gets its own Apple Developer org account (client's legal entity and D-U-N-S; login is an Anchor alias such as `jmartin+tmjint@anchorcorps.com`). This avoids Apple's 4.2.6 template-app and 4.3 spam rejections. **Android:** all apps under Anchor's Google Play account. |
| Branding | Set **manually** in each client's config folder (name, icon, splash, colours, logo, site URL). Not pulled from the site. |
| Framework | **Expo / React Native + TypeScript**, built with EAS. |
| v1 features | Events (month grid + agenda), event detail with **Register** and **Add to calendar**, Centre sign-in. |
| Registration | **Link out**: an in-app browser opens the event page on the site. |
| Which categories show | The site's **App categories** setting (Events settings). The site admin chooses in one place; the app does not have its own category settings. |
| Video hosting (project 4–5) | Videos stay on **Vimeo** (Bunny Stream if cost matters), never WordPress uploads. The Vimeo key stays on the site. |

---

## Structure

```
anchor-member-app/
  app/                  Expo Router screens
  src/api/              one API client (auth header, ETag cache, error mapping)
  src/features/events/  feed hooks, date grouping, calendar export
  src/features/account/ sign-in, /me
  clients/
    tmj/
      client.json       name, slug, bundle ids, site URL, URL scheme, colours
      assets/           icon, adaptive icon, splash, logo
      store/            listing text, screenshots, privacy answers
  app.config.ts         reads clients/$CLIENT/client.json
  eas.json              one build profile per client
```

`CLIENT=tmj` selects the client at build time. Nothing client-specific lives outside `clients/<slug>/`.

## Screens

**Bottom tabs.** v1 has **Events** and **Account**. The tab bar is built from a list so Courses, Library and Downloads slot in later without restructuring.

**Events tab**
- A month grid with dots on days that have events, and an agenda list below for the selected month. Multi-day events show on every day they cover.
- Rows show the title, date and time in the event's own timezone, location, a price or "Free", and a "Centres" badge for `audience: restricted`.
- Pull to refresh.

**Event detail**
- Image, title, dates, location, summary and registration status.
- **Register** opens `register_url` in an in-app browser (SFSafariViewController / Custom Tabs), so the site's login cookie, checkout and Agreements work unchanged. It is hidden when the status is `closed`; when it is `sold_out` it reads "Sold out" (or "Join waitlist" for `waitlist`).
- **Add to calendar** writes the event to the phone's calendar (expo-calendar), with the event's URL in the notes.

**Account tab**
- Signed out: "Sign in with your <site> account".
- Signed in: "Signed in as <name>" and Sign out.

## Sign-in and data flow

1. **Sign-in:** the app opens the site's `wp-admin/authorize-application.php` in an auth session with `app_name` set to "<App name> (<device>)" and `success_url` set to the app scheme (for example `tmjcentres://auth`).
2. The person logs in on the site and approves. The site redirects to the scheme with `user_login` and `password`.
3. The app stores the pair in **Keychain / Keystore** (expo-secure-store). It is never written to logs or plain storage.
4. **On launch:** the app calls `GET /me`. A 200 shows "Signed in as …". A 401 clears the key quietly and the app runs in public mode.
5. **Feed:** `GET /events` with no `category` param, so the site's App categories setting applies. Requests send `If-None-Match`; a 304 keeps the cached copy.
6. **Refresh:** on pull, on launch, and when the app returns after more than 5 minutes in the background.
7. **Cache:** the last good feed response is stored on the device. Offline, the app shows it with "Last updated <time>".
8. **Sign-out:** the app deletes the stored key. The person can also revoke it on the site (Profile → Application Passwords), and the next request then returns 401.

## Errors and empty states

| Case | What the person sees |
|---|---|
| Site unreachable, cached feed exists | The cached calendar with a "Couldn't refresh" banner and Retry. Never a blank screen. |
| Site unreachable, no cache (first launch offline) | A friendly empty state with Retry. |
| 401 on any request | Quiet sign-out, public events shown. |
| 404 on the feed (old plugin) | "This site needs an update." Not an empty calendar. |
| Other 4xx/5xx | "Something went wrong" with Retry; the cached copy stays visible. |
| No events in range | "No upcoming events." |
| Sign-in cancelled or rejected | Back to the Account tab, no error. |

## Testing

- **Unit (Jest):** the API client (auth header, ETag/304, error mapping), feed parsing, timezone display, month-grid placement of multi-day events, and the offline cache.
- **Screen tests (React Native Testing Library):** Events, Event detail and Account, against fixtures shaped exactly like the feed spec's rows.
- **End-to-end on simulators**, first against the local TMJ copy (DevKinsta), then staging: sign in as a test Centre, see a Centre-only event, open Register, add an event to the calendar, go offline and see the cached copy.
- **Real-phone pass** before store submission, on an iPhone and an Android phone: sign-in, offline mode and Add to calendar.

## Build and release

- **Builds:** EAS, one profile per client (`eas build --profile tmj`).
- **Testing tracks:** TestFlight (on the client's Apple account) and Google Play internal testing (on Anchor's account).
- **Store listings:** name, description, screenshots and privacy answers live in `clients/<slug>/store/`. Privacy: the app collects no data beyond the person's site login, which stays on the device.
- **Updates:** native changes go through a store build. Small JS fixes can ship with EAS Update per client channel.

## Before v1 can ship

- **Site:** the events feed (project 1) released and live on the client's site; Application Passwords confirmed enabled; at least one event category chosen in App categories.
- **Client:** their Apple Developer org account enrolled, which needs their legal info and a one-time verification on their side.

## Out of this spec

| Item | Where it goes |
|---|---|
| Courses, Library and offline video | Roadmap projects 3–5 |
| In-app RSVP for free events | A later spec (needs `POST` registration on the site) |
| Paid registration in-app (cart handoff) | A later spec |
| Push notifications | A later spec |
| One app serving several clients | Not planned. One app per client. |
