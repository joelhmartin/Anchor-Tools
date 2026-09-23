# Virtual events: hosted stream room, per-session modality, role-based entitlement

**Module:** `anchor-events-manager` (`\Anchor\Events`)
**Date:** 2026-09-23
**Status:** approved in brainstorm (sections 1–2 reviewed with the owner; 3–7 written under the owner's "specs are not reviewed, plans are" rule)
**Companion spec:** `2026-09-23-anchor-courses-design.md` (consumes the contract in §7)

---

## 1. Goal

Let an event registered through this plugin be attended over a live stream hosted on **our own site**, with:

- a **room page** per event that shows a countdown, switches to the stream at start time, and closes after the session, without an author touching anything on the day;
- **per-session modality** on multisession events (some sessions in person, some virtual, some both) and **per-ticket modality** (an in-person seat and a livestream seat for the same occurrence, sold as two tiers of one product);
- an event-level toggle **"in-person registrants also get the stream"**, default on;
- **provider-agnostic embeds** (Vimeo today; YouTube, Zoom, anything iframe-able tomorrow);
- an **entitlement model that outlives the event**: every event mints a WordPress role, granted to confirmed registrants and revocable or grantable by hand, so the Anchor Private File Manager, the future courses module, and anything else that understands roles can gate on "attended X" with no knowledge of this plugin.

Replays are **out of scope**. The owner reuses one stream across days and hands recordings out through the file manager, gated by the event role. The room says "this session has ended" and nothing more.

## 2. Decisions taken during brainstorm

| # | Question | Decision | Why |
|---|---|---|---|
| 1 | Who may hold a stream? | Only events registered **through this plugin** (`registration_mode` internal or `wc`). External-registration events never get a room. | The owner is moving every event onto the plugin; legacy events stay legacy. We only know who someone is when we took the registration. |
| 2 | Identity | **Account required**; created at confirmation if missing; emails carry a one-click sign-in link that lands on the room. | Only model that also serves courses (progress needs identity) and watch reporting (logged-in only). |
| 3 | Where do they watch? | **A dedicated room URL**, `<event permalink>/live/`. The event page stays the public marketing page. | The event page is indexable and edge-cached; the room is private, no-cache, and changes at a specific minute for specific people. |
| 4 | After the stream? | **Room closes.** No replay. | Streams are reused across days; recordings go through the file manager by role. |
| 5 | Two products or one? | **One event, one product, tiers carry modality.** | A tier answers "what am I paying for", a child post answers "which occurrence". A livestream seat is a price, not an occurrence. (Occurrence-model memory, 2026-09-03.) |
| 6 | Entitlement | **A role per event**, `anchor_event_{id}`, granted/revoked from seat status, plus manual grant/revoke. | One door for paid, comped, imported, and late-added attendees; drops straight into the file manager's role policy and the webinars module's role gate. |
| 7 | Where does courses live? | New module in Anchor-Tools (see companion spec), talking to events **only through roles, actions, filters and a read API** defined in §7. | In-process access, one loader, one test harness; no install-order guessing. |
| 8a | Regular events | **Inert by default.** `access_role_enabled=false` unless a stream is saved or the author ticks it; then and only then do accounts, roles, the room and `{room_link}` exist for that event. | Owner's instruction 2026-09-23: none of this may apply to plain events and their attendees by accident. |
| 8 | Approach | **A: build inside the events module.** Not B (webinars-as-room: Vimeo-ID/VOD only, would need a second reconcile engine) nor C (standalone module importing half of events). | |

## 3. Data model

All keys use the existing `_anchor_event_` prefix via `Module::meta_key()`, are declared in `get_meta_defaults()`/the REST meta schema, and are sanitised in `persist_event_authoring()` next to their neighbours. Nothing here is a new storage mechanism.

### 3.1 Event-level keys

| Key | Type | Default | Notes |
|---|---|---|---|
| `stream_embed` | array `{provider, src, raw}` | `[]` | Output of `Embed::normalize()` (§5.4). Never raw HTML. Inherited to occurrence children (`INHERITED_KEYS`). |
| `stream_default_modality` | `in_person\|virtual\|hybrid` | `in_person` | Seed for new session rows and new tiers. Inherited. |
| `in_person_includes_stream` | bool | `true` | The owner's toggle. Inherited. |
| `access_role_enabled` | bool | `false` | **The master switch for everything in §4.** Off: the event mints no role, creates no accounts, has no room, and behaves exactly as before this spec. On: confirmed attendees get an account and the event role. Auto-set to `true` when a non-empty `stream_embed` is saved or any session/tier is `virtual`/`hybrid`; an author may also turn it on by hand for an in-person event whose recordings or handouts are gated by role in the file manager. Inherited. |
| `stream_open_before_minutes` | int | `15` | Room switches countdown → player this many minutes before a session's start. Inherited. |
| `stream_close_after_minutes` | int | `30` | Room keeps the player this long after a session's `end_ts`. Inherited. |
| `required_roles` | string[] role slugs | `[]` | Prerequisites (§4.6). Inherited. |
| `required_roles_mode` | `any\|all` | `any` | Inherited. |

**Legacy fields stay.** `virtual` (bool) and `virtual_url` are untouched in storage and UI. Two bridges keep one code path:

- Saving a non-empty `stream_embed` **forces `virtual=1` and `access_role_enabled=1`** so `Event_Schema::location_fields()`, the email venue line ("Online"), the archive badge and the `moved_online` status all keep working with no second branch.
- The room's stream resolver (§5.3) treats `virtual_url` as a **fallback embed input**: `Embed::normalize( $virtual_url )`. A Zoom link therefore renders as a "Join on Zoom" button inside the room, so existing virtual events get a room with no data migration.

### 3.2 Session rows (multisession)

`_anchor_event_sessions` rows gain two optional fields, sanitised in `sanitize_sessions_rows()`:

```
{ date, start_time, end_time, label, modality?, stream_embed? }
```

- `modality`: `in_person|virtual|hybrid`; empty → event's `stream_default_modality`.
- `stream_embed`: normalised embed; empty → event's `stream_embed`. This is the "same stream for both days" case: leave it empty.

`Module::get_sessions()` returns rows with both fields **resolved** (never empty), plus `start_ts`/`end_ts` per row computed with the event's timezone, so the room and the theme read one shape. A `single` event is treated everywhere as one implicit session `[0]` spanning `start_ts..end_ts`.

### 3.3 Ticket tiers

`Ticket_Types::normalize()` gains `modality`: `in_person|virtual`; empty → `in_person` (so every existing tier is an in-person tier, the previous meaning). `Product_Sync` writes it to the managed variation as `_anchor_evt_modality` so order lines and the attendee capture can say "Livestream ticket".

A tier never says `hybrid`: a hybrid **event** with one price simply has an `in_person` tier and the toggle on.

### 3.4 Offering / recurring children

Children are full events, so they inherit every §3.1 key through `Occurrences::INHERITED_KEYS`, and a child may override `stream_embed` on its own metabox exactly as a session row does. Tiers already copy per the 2026-09-03 `tier_id` rule.

### 3.5 Seats and users

A seat (`anchor_event_reg` post) gains `_anchor_event_user_id` (int, 0 = unresolved). `Entitlements::ensure_user()` (§4.3) sets it. `Registrations::user_has_active_seat()` checks `user_id` first, then falls back to email as today.

User meta `_anchor_event_grants` (array `{event_id: {source: 'seat'|'manual', at, by}}`) records **why** a user holds a role so a seat cancellation can't strip a manual grant (§4.4).

## 4. Entitlements and roles

New class `Anchor\Events\Entitlements` (`class-entitlements.php`), constructed by `Module` like `Registrations`/`Roster`, exposed as `$module->entitlements`. One responsibility: who holds access to an event and why.

**Nothing in this section runs for an event whose `access_role_enabled` is false.** `Entitlements::enabled( $event_id )` = `Module::stream_capable( $event_id ) && access_role_enabled`, and every entry point below (`grant_for_seat()`, `ensure_user()`, the seat hooks, `can_access_stream()`, `room_url()`, the `{room_link}` token, the roster Access column, the Basics role panel) checks it first and returns its pre-spec answer when it is off. This is the guarantee that a plain in-person event and its attendees are untouched: no account is created, no role is minted, no room resolves, no email gains a link. The only way an event opts in is saving a stream (auto) or ticking the switch (manual).

### 4.1 Role minting

- Slug `anchor_event_{event_id}`, display name `Event: {post_title}`, **no capabilities**. Pure membership tag.
- Minted **lazily** by `role_for( $event_id, $create = true )` the first time a grant happens. Events nobody registers for create no role.
- `save_post_event` renames the role when the title changes (`wp_roles()->roles[slug]['name']`).
- **Never deleted automatically.** Trashing or deleting the event leaves the role; the owner wants to keep granting after the fact. The console's Basics tab shows the role with member count and an explicit **Delete role** action (confirm dialog; also strips it from every holder).
- Children of an offering each mint their own role (each date is its own bookable thing). The parent mints none.

### 4.2 Grant and revoke from seats

Hooked on `anchor_events_seat_status_changed( $seat_id, $from, $to, $actor )`, the single transition point:

- `to = confirmed` → `ensure_user( $seat )` then `grant( $event_id, $user_id, 'seat' )`.
- `to ∈ {cancelled, refunded, failed, expired…}` (anything not confirmed/pending) → `maybe_revoke_seat_grant()`: revoke **only if** the user holds no other confirmed seat on the event **and** has no `manual` grant recorded.
- `pending` grants nothing (WooCommerce on-hold, waitlist). Waitlist promotion to confirmed grants normally.

Grant = `WP_User::add_role()` (additive; customer/subscriber untouched) + `_anchor_event_grants[event_id]` + `Events_Log::info('access_granted')` + `do_action('anchor_events_access_granted', $event_id, $user_id, $source)`. Revoke mirrors it with `remove_role()` and `anchor_events_access_revoked`.

### 4.3 Account creation

`ensure_user( $seat )`:

1. `_anchor_event_user_id` set and user exists → return it.
2. Order seat with `customer_id > 0` → that user.
3. `get_user_by('email', seat email)` → that user.
4. Otherwise create: `wc_create_new_customer()` when WooCommerce is active (so My Account works), else `wp_insert_user()` with the default role; `display_name` from the seat name; generated password; **no WordPress new-user email** (our confirmation carries the sign-in link, §6.2). Filter `anchor_events_create_account` (bool, default true) lets a site opt out; opting out means guests get no room access and the confirmation says so.

Called from the confirmed transition, from `Roster::handle_add()` (comped seats), and from the WooCommerce attendee capture, so every path resolves a user.

### 4.4 Manual grant and revoke

Roster gains per-row **Grant access / Revoke access** and a header **Add person by email** (name + email; creates the account if needed; grants with `source=manual`; no seat, no capacity impact, not on exports unless the "include manual access" export scope is chosen). Manual grants survive seat cancellations. Revoking a manual grant on a user who still holds a confirmed seat leaves the role (the seat still entitles them) and says so.

### 4.5 The one question

```php
public function can_access_stream( int $event_id, int $session_index = 0, int $user_id = 0 ): bool
```

Resolution order, first hit wins:

1. `Roster::current_user_can_manage()` (for the current user) → true.
2. Not logged in → false.
3. `Entitlements::enabled()` is false (external registration, group parent, or `access_role_enabled` off), the session's resolved `modality = in_person` (no stream for that session; the room shows the venue instead), or no embed resolves for that session → false.
4. `_anchor_event_grants[event_id].source = manual` → true.
5. Holds the event role **and** has a confirmed seat whose tier `modality = virtual` → true.
6. Holds the role and a confirmed seat with tier `in_person` **and** `in_person_includes_stream` → true.
7. Otherwise false.

**Informational public events keep their public link.** `can_view_virtual_link()`'s first branch (registration disabled and no product: the link is visible to everyone) stays exactly as it is today and is evaluated *before* delegation, so a public Zoom webinar's "Join here" is never hidden from anonymous visitors by this spec. Such an event has no seats, so it also never mints a role or creates an account.

Result passes through `apply_filters( 'anchor_events_can_access_stream', $allowed, $event_id, $session_index, $user_id )` so the courses module can veto later ("finish the pre-work first"). `Module::can_view_virtual_link()` is rewritten to delegate here so the event page's "Join here" and the room never disagree.

### 4.6 Prerequisites

`required_roles` is checked inside `Registrations::capacity_decision()`, the existing single authority for "may this person register", as a new refusal reason `prerequisite` with the message "This course requires {role names}". Staff bypass. Because the date picker, CTA, storefront and `WooCommerce::filter_is_purchasable()` all consult `capacity_decision()`, they refuse together. Anonymous visitors on a prerequisite-gated event see "Sign in to check eligibility" instead of the form.

The metabox offers every role from `get_editable_roles()` plus every `anchor_event_*` and `anchor_course_*` role, grouped, so a past event or a completed course is a one-click prerequisite.

## 5. The room

### 5.1 URL and routing

`add_rewrite_endpoint( 'live', EP_PERMALINK )`, honoured only when `is_singular( Module::CPT )`. `Module::room_url( $event_id )` = `trailingslashit( get_permalink() ) . 'live/'`. Rewrite flush on module version bump (existing pattern). Group parents have no room: `/live/` on a parent redirects to the parent page (the child pages each have one).

### 5.2 Headers and caching

`template_redirect` on a room request: `nocache_headers()`, `Cache-Control: private, no-store`, `X-Robots-Tag: noindex, nofollow`, robots meta, and the Kinsta/edge no-cache cookie already set by WordPress for logged-in users. The room is excluded from the sitemap via the same filter the noindexed LPs use. `Event_Schema` emits the room URL as the `VirtualLocation.url` (it is the canonical place to attend), which is fine: schema points at it, robots keep it out of results.

### 5.3 State machine

`Stream_State::for_event( $event_id, $now )` is a **pure function** of the event's resolved sessions, `stream_open_before_minutes`, `stream_close_after_minutes` and `$now`:

| State | When | Room shows |
|---|---|---|
| `unavailable` | event not stream-capable, or every session is `in_person` | "This event is in person." + venue + event link |
| `pending` | a virtual/hybrid session exists but resolves to no embed | "Stream details will appear here before the session." + schedule |
| `countdown` | now < first upcoming session's `start_ts − open_before` | countdown to that instant, schedule, "You're registered" |
| `live` | `start_ts − open_before ≤ now ≤ end_ts + close_after` for some session | the embed for that session; schedule with the live one marked |
| `between` | after one session's close and before the next's open | "Day 1 has ended. Day 2 starts in …" countdown |
| `ended` | now > last session's close | "This session has ended." + event link |

Returns `{state, session_index, target_ts, embed?}`. `embed` is populated **only** in `live`. Unit-tested as a table.

### 5.4 Embed normaliser

`Anchor\Events\Embed::normalize( string $input ): array{provider,src,raw}` accepts pasted iframe HTML (extracts `src`) or a bare URL. Host allowlist (filter `anchor_events_embed_providers`), each provider being `{hosts[], kind: iframe|link, transform}`:

| Provider | Input examples | Output |
|---|---|---|
| `vimeo` | `vimeo.com/123`, `player.vimeo.com/video/123?h=…`, `vimeo.com/event/456`, `vimeo.com/event/456/embed` | iframe `player.vimeo.com/video/123` or `player.vimeo.com/event/456/embed` (query preserved; `h` hash kept) |
| `youtube` | `youtu.be/x`, `youtube.com/watch?v=x`, `youtube.com/live/x`, `youtube.com/embed/x` | iframe `youtube-nocookie.com/embed/x` |
| `zoom` | `*.zoom.us/j/…` | **link** (Zoom forbids framing) → "Join on Zoom" button |
| `generic` | any https URL on a host an admin adds via the filter | iframe as given |

Unknown host → `WP_Error`, shown inline on save; the previous value is kept. Rendering: `<iframe src allow="autoplay; fullscreen; picture-in-picture; encrypted-media" allowfullscreen referrerpolicy="strict-origin-when-cross-origin" loading="eager" title="{event title}">` inside a 16:9 box. Vimeo domain-privacy is the site's job; the spec notes it in the admin help text.

### 5.5 Template

`templates/live-event.php`, resolved through the existing `locate_template()` so a theme may override at `events/live-event.php` (DEKA's theme will). The plugin template is theme-agnostic: `get_header()`, one `<main class="anchor-event-room">`, `get_footer()`. Body delegates to `Module::render_room( $event_id )`, which renders one of:

- **Locked, logged out:** the event title, "Sign in to join", `wp_login_form()` with `redirect` back to the room, and "Registered but no account? Use the link in your confirmation email."
- **Locked, logged in, not entitled:** "This account isn't registered for this event." + event link + "Registered under a different email? Contact us." (`anchor_events_room_denied_message` filter for the wording).
- **Entitled:** the state block from §5.3, the schedule (each session: label, date/time in the event's timezone and the viewer's, modality badge), and staff-only "Preview as attendee / Open event console" links when `current_user_can_manage()`.

### 5.6 Countdown and switch

`assets/room.js` (jQuery IIFE per repo convention): reads `data-target-ts` and `data-server-now` from the state block, corrects for client clock skew, ticks the countdown, and when the target passes calls `GET /wp-json/anchor-events/v1/events/{id}/room` (nonce, cookie auth). The endpoint runs `can_access_stream()` and `Stream_State` server-side and returns the new state block HTML. The embed **never** appears in markup before the window opens, so the stream URL can't be lifted early. While `live`, the script polls the same endpoint every 5 minutes to catch `ended`/`between`; on any error it shows "Refresh the page" rather than guessing.

## 6. Checkout, emails, and the event page

### 6.1 Checkout

Nothing structural changes in WooCommerce: tiers already map to variations. Additions: variation meta `_anchor_evt_modality`; the attendee-capture block labels each ticket "In-person" or "Livestream"; order emails' item meta shows the same. Purchasability keeps flowing through `capacity_decision()`, which now also carries the prerequisite refusal.

### 6.2 One-click sign-in link

`Entitlements::login_token( $user_id, $event_id )` mints an HMAC (`wp_hash`) over `user_id|event_id|expiry|user_pass fragment`, expiry = last session `end_ts` + 7 days, stored nowhere (stateless; changing the password invalidates it). `room_url_for( $user_id, $event_id )` = room URL + `?aek=…`. `template_redirect` on a room request with a valid `aek` for a logged-out visitor calls `wp_set_auth_cookie()` and redirects to the clean room URL; an invalid or expired token falls through to the login form with a notice. Tokens are never shown in admin or logs.

Email: a new scalar `{room_link}` (per recipient, tokenised; **resolves to `''` without touching accounts when `Entitlements::enabled()` is false**, so rendering a plain event's confirmation creates nothing) and the existing `default_email_cta()` gains a branch **above** the virtual one: stream-capable event → label "Join the livestream", URL `{room_link}`. Reminders and confirmations pick it up with no template changes. `{join_link}` keeps meaning the raw provider URL for legacy templates.

### 6.3 Event page

`can_view_virtual_link()` delegates to `can_access_stream()`; the "Join here" anchor points at the room. A stream-capable event's detail list shows "Livestream available" / "In person + livestream" from the resolved session modalities. The theme's `single-event.php` reads the same `get_sessions()` shape and may show the modality badge (theme follow-up in `deka-context`, not this spec).

## 7. Admin and console UI

- **Event Details metabox → Location section** gains a **Livestream** group: embed textarea with provider help text, default modality, the toggle, open-before / close-after minutes, and a read-only room URL with "Open room". Shown when `registration_mode ≠ external`.
- **Sessions repeater**: modality select + "Stream override" text input per row (collapsed until "Use a different stream for this session" is ticked).
- **Ticket tiers repeater**: modality select per tier.
- **Access section** (new, under Registration): the **"Give confirmed attendees an account and the event role"** switch (`access_role_enabled`, with help text naming the room and the file manager as what it unlocks; shown checked-and-locked when a stream is saved), then the required roles picker + any/all.
- **Front-end console** (manager.js / manager-wizard.js) gets the same fields through the existing `anchor_events_manager_form_fields` filter so the DEKA team's authoring path has parity.
- **Roster tab**: "Access" column (role held: yes / manual / no), Grant/Revoke row actions, "Add person by email" in the header, export scope "confirmed + manual access".
- **Basics tab**: "Event role" panel — slug, display name, holder count, Delete role.
- **Events list**: a "Live" column with room state (`countdown in 3d`, `LIVE`, `ended`) and room link.

## 8. Testing

PHPUnit (`Anchor_Events_TestCase` helpers `make_event`, `make_seat`):

- **Inertness**: a `free`/`wc` event with `access_role_enabled=false` — confirm a seat, cancel it, render its confirmation email, request `/live/`, open the roster: no user created, no role in `wp_roles()`, `room_url()` is `''`, `{room_link}` is `''`, Access column absent. Saving a `stream_embed` flips the switch; saving a `virtual` tier or session flips it; clearing them does not flip it back (an author who turned it on keeps it).
- `Entitlements`: confirm → role + grant record; cancel → revoke; cancel with a second confirmed seat → keep; cancel with manual grant → keep; manual revoke with live seat → keep and warn; role renames with title; role survives event trash; `ensure_user()` four branches; no WP new-user mail.
- `can_access_stream()` truth table across tier modality × toggle × session modality × manual × staff × logged-out.
- `Stream_State` table: every state, boundary instants, multi-day gap, single event as implicit session, timezone edge (session crossing midnight in event TZ).
- `Embed::normalize()`: every provider row, iframe-vs-URL, hash preservation, unknown host → `WP_Error`, XSS payloads in `src` rejected.
- Prerequisite in `capacity_decision()`: any/all, staff bypass, anonymous message, purchasability refuses.
- Sessions/tiers sanitisers: defaults resolve, bad modality falls back, empty override inherits.
- Inheritance: children receive §3.1 keys; child override survives reconcile.
- REST room endpoint: 401 logged out, 403 not entitled, embed absent outside window, present inside.
- Login token: valid signs in and strips query; expired/tampered/password-changed rejected.
- Email: `{room_link}` per recipient; CTA default order (stream > virtual > event page).

Playwright: register free event → follow tokenised link logged out → land in room in `countdown` → advance clock via test filter → `live` embed present → `ended`. Second scenario: in-person tier with toggle off is denied; toggle on is allowed.

## 9. Out of scope (this project)

Replays / recordings (file manager), live chat, watch-time logging for live streams (Vimeo Event embeds emit no player events; page-time lives in the file manager's tables), attendance certificates (courses module), per-session capacity for hybrid seats, calendar invite files for the room (nice later: `.ics` with the room URL), theme override for DEKA (separate task in `deka-context`).

## 10. Rollout

1. Ship as Anchor-Tools **3.31.0** from `main` per the release process. New keys default to previous behaviour, so no data migration; `virtual_url` events get a room automatically via the fallback.
2. On DEKA production: flush rewrite rules (version bump does it), spot-check one legacy virtual event's `/live/` renders a "Join on Zoom" button, then author the first hybrid event.
3. Theme follow-up in `deka-context`: `events/live-event.php` override and a modality badge on `events/single-event.php`, using `docs/DESIGN-SYSTEM.md`.
