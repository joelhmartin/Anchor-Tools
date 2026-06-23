Both high-severity findings are confirmed in the merged code: `render_single_content()` emits the virtual join link with zero gating (line 2220-2222), and `reconcile_order()` logs an "Unmappable line" entry for every unmapped line and calls `$order->save()` unconditionally with no early event-line guard (lines 1228, 1256). Writing the report.

# WooCommerce-Events Integration — Post-Merge Review

## 1. Executive Verdict

**Not production-ready as-is.** The seat/capacity core is sound — the prior review rounds' fixes (refund negative-qty classification, Store-API fail-closed, count-based surplus cancellation, gap-fill revival, in-place variation handling, trash/delete release, NULL-guest undercount, PII redaction, save() try/catch, `current_time()` window) are all genuinely present in the merged code and the MVP feature set is fully built. The blockers are at the **edges**, not the core:

**Biggest risks (in order):**
1. **Paywall bypass (security, HIGH).** The virtual-event join URL is printed to every anonymous visitor on the public event page, with no seat/purchase check. For a paid virtual event this hands the deliverable away for free. Confirmed at `anchor-events-manager.php:2220-2222`.
2. **Store-wide order pollution + write amplification (perf, HIGH).** `reconcile_order()` runs on global WC hooks for *every* order, writes a "Unmappable line" sync-log entry onto non-event orders, and calls `$order->save()` unconditionally on every status transition. Confirmed at `class-woocommerce.php:1228,1256` — there is no early `order_has_event_lines()` guard.
3. **Two privilege/authorization gaps (security, MEDIUM):** front-end event editor lacks a post-type check (type confusion + publish-cap bypass), and the roster/PII CSV is gated by `edit_others_posts` (Editors can pull all WooCommerce customer data).
4. **Paid orders that silently end up with zero seats** (Pay-for-order / admin-created order flows never capture attendees).
5. **Notification/audit-layer races and flag-clobbering** that aren't protected by the seat lock.

Seat capacity itself is safe under normal operation; the failure modes are oversell-under-lock-degradation (documented, by design) and the edge cases below. Recommend addressing the HIGH items and the two MEDIUM security items before release.

---

## 2. Findings by Severity (deduped)

### HIGH

**H1 — Virtual join URL exposed to everyone (paywall bypass)** · security
`anchor-events-manager.php:2210-2222` (rendered by `templates/single-event.php:28`)
- **Trigger:** Any visitor opens the publicly-queryable single-event page; if `virtual` + `virtual_url` are set, a clickable "Join here" link is emitted with no registration/purchase/login check. Defeats paid-registration entirely and leaks links for free-registration events to non-registrants. The buy-button swap in `filter_registration_form()` does not protect this — the link is printed by a separate render method above the form.
- **Fix:** Gate the `virtual_url` block. Only emit to viewers holding a confirmed/active seat (resolve current user email/customer_id against `Registrations`) or roster-capability users; for linked paid events suppress on the public page entirely and surface only in the buyer confirmation email / logged-in attendee area.

**H2 — `reconcile_order()` pollutes every non-event order + redundant save on every status change** · perf / wc-hpos
`class-woocommerce.php:1224-1230` (unmappable-line log), `1256` (unconditional `$order->save()`); reached from `on_status_changed` (global hook, line 122) and `on_payment_complete`
- **Trigger:** These hooks fire for *every* order store-wide. With no early guard, every non-event line logs `Unmappable line left untouched.` into `_anchor_event_sync_log`, and `$order->save()` runs unconditionally — even when nothing changed. Net: sync-log meta written onto all ordinary orders (accumulating to the 50-entry cap), plus a doubled order write on every transition, nested inside `woocommerce_order_status_changed` (fires extra `woocommerce_update_order`/`after_order_object_save` other integrations react to). *(This subsumes the separately-listed "unconditionally saves every order" finding — same root cause/fix.)*
- **Fix:** Early-return `if ( ! $this->order_has_event_lines( $order ) ) return;` (helper exists at line 1628). Only log "Unmappable line" when the line has event evidence (snapshot meta or existing seats). Make the end-of-pass save conditional on a dirty flag (non-empty `$log_entries`/changed `$review_flags`/changed emails-sent gate).

### MEDIUM

**M1 — Front-end event editor edit-path missing post-type check (type confusion + publish-cap bypass)** · security
`anchor-events-manager.php:1938-1973` (contrast `handle_event_manager_delete()` which checks `get_post_type` at 2037)
- **Trigger:** Edit branch authorizes only `current_user_can('edit_post', $event_id)`, never `get_post_type($event_id) === self::CPT`. Handler is on `admin_post_anchor_event_manager_save` (any logged-in user can POST). An Author/Contributor submits `event_id` = their own draft, and `wp_update_post()` forces `post_type=CPT` and an attacker-supplied `post_status`. Since `wp_update_post()` does not enforce `publish_posts` for an explicit `post_status='publish'`, a Contributor publishes — and rewrites an arbitrary post as an event.
- **Fix:** Require `get_post_type($event_id) === self::CPT` before updating (mirror the delete handler), and validate requested `post_status` against the user's real publish capability (downgrade to `pending` unless `current_user_can('publish_posts')`).

**M2 — Roster view + customer-PII CSV gated only by `edit_others_posts`** · security
`class-roster.php:25` (`CAP`), enforced at 86/397/364
- **Trigger:** `edit_others_posts` is held by the Editor role, not just admins/shop managers. Editors — normally with no order/customer access in WooCommerce — can browse every roster and export a CSV of attendee names/emails/phones, buyer billing email, customer IDs, and order numbers.
- **Fix:** When WC is active, gate the roster/export behind `manage_woocommerce`/`edit_shop_orders` or a dedicated `anchor_manage_event_rosters` cap mapped to admins/shop managers; keep `edit_others_posts` only for free/internal installs.

**M3 — Pay-for-order & admin-created orders capture no attendees → paid order with zero seats** · wc-hpos
`class-woocommerce.php:107,109` (capture hooks), `1308-1315` (`can_create` gate)
- **Trigger:** Attendee fields render only on classic shortcode checkout (`woocommerce_checkout_after_customer_details`) and persist only via `woocommerce_checkout_create_order_line_item`. The `/checkout/order-pay/` page and admin-created orders fire neither. On reaching processing/completed, `reconcile_line` sees `expected>0`, `has_attendees=false` → sets `attendees_missing`, creates **no seats**. Buyer holds a paid ticket with no seat until staff notice. Degrades gracefully (flagged + manual roster add).
- **Fix:** Render/capture attendee fields on the order-pay flow (`woocommerce_pay_order_before_submit` + an order-pay save hook), or explicitly require classic-checkout purchase and make the needs-review notice/email loud about the missing-attendee case.

**M4 — `'mixed'` refund review flag clobbered by reconcile's stale-instance save** · wc-hpos
`class-woocommerce.php:2411-2434` interacting with `apply_review_flags()` (1598-1622) + save (1256)
- **Trigger:** `on_order_refunded()` loads order instance A, then for a mixed refund calls `Events_Log::flag_review()` which loads a *separate* instance B, adds `amount_only_refund`, saves B. It then calls `reconcile_order($order=A,...)`. A was loaded before B's write, so A's meta lacks the new flag; `apply_review_flags()` writes A's stale `ORDER_REVIEW_META` and saves, dropping the flag B persisted. The unexplained refunded money is never surfaced for review.
- **Fix:** Move the mixed `flag_review()` call to *after* `reconcile_order()` returns (fresh instance appends), or thread the extra-amount flag into `reconcile_order()`'s `$review_flags` so the single batched save persists it.

**M5 — Order-meta audit/notification layer not serialized cross-process → duplicate emails / lost log+flag entries** · data-edge
`class-woocommerce.php:1176-1259`, `dispatch_emails()` 1819-1882, `apply_order_log()` 1576-1591, `apply_review_flags()` 1598-1622
- **Trigger:** The per-event GET_LOCK protects seats, but the `EMAILS_SENT_META` gate, sync-log ring buffer, and review flags are load→mutate→`$order->save()` with no cross-process lock (`$in_flight` is per-process). Two processes reconciling the same order (gateway IPN + admin "Resync", or two near-simultaneous transitions on different workers) both see `empty($sent['customer'])` and both send buyer+organizer emails; last-write-wins also drops log entries and can drop a needs-review flag. Seats are safe; audit/notification layer is not.
- **Fix:** Wrap the email-gate + final meta write in a per-order named lock (analogous to `with_event_lock`), or re-load the order immediately before the end-of-pass save and merge `emails_sent`/flags/log. At minimum re-read `EMAILS_SENT_META` right before sending.

**M6 — Custom/unknown WooCommerce order statuses sweep all event seats to cancelled** · correctness
`class-woocommerce.php:1145-1148` (`map_order_status_to_seat()` default → null), surplus path 1287-1288/1374-1395
- **Trigger:** `map_order_status_to_seat()` returns null for any status outside `{processing,completed,on-hold,failed,cancelled,refunded}`. `active_target===null` forces `expected=0`, so the surplus branch cancels **all** active seats. `on_status_changed` fires for every transition, so any custom status (deposit/partial-payment `wc-partial-payment`, subscriptions, fulfillment `awaiting-shipment`) releases confirmed seats and frees capacity — with waitlist on, a third party can grab it; on return to processing the seat revives past capacity (overfill).
- **Fix:** In `map_order_status_to_seat()`, distinguish "no seats yet" (pending) from "unknown status." For unrecognized statuses, return a sentinel that makes `reconcile_line` **leave seats untouched** and log "unknown order status, seats left as-is." Only explicit pending/cancelled/refunded/failed should drive `expected=0`.

### LOW

**L1 — Variation-change-in-place loses per-attendee data on the moved seat** · correctness
`class-woocommerce.php:1345-1367, 1442-1465` — replacement seat created at `$index = ++$max_index`, but `_anchor_attendees` is keyed `1..qty`; after the cancelled old seat inflates `max_index`, `$attendees[$index]` misses and falls back to billing name/email, silently overwriting captured attendee details. **Fix:** map attendee data to newly-created seats by a per-line creation counter over present attendee entries, not the absolute `++$max_index`.

**L2 — Free/manual (`claim_seats`) path silently oversells on lock degradation; `lock_unavailable` never surfaced** · data-edge
`anchor-events-manager.php:2384-2404`, `class-roster.php:287-303` — both discard `$result['lock_unavailable']`. The paid path raises `capacity_lock_unavailable` (reconcile_line 1517-1519) but the free/roster paths have no admin-visible flag, inconsistent contract. **Fix:** record a per-event review marker/notice when seats were created under a missing lock, mirroring the paid path.

**L3 — `resolve_event_for_item()` snapshot fallback doesn't exclude trashed events → seat creation against trashed event** · data-edge
`class-woocommerce.php:1665-1682` — checks `get_post_type === CPT` but not status. `validate_event_id()` rejects trashed; the fallback bypasses it, so seats can attach to a trashed event. **Fix:** require `get_post_status($snapshot) !== 'trash'` in the fallback, or gate the create branch.

**L4 — `bust_cache()→clear_caches()` per-seat: O(n) option writes + non-atomic registry reset loses cache keys** · data-edge
`class-registrations.php:1033-1038`, `anchor-events-manager.php:2788-2808` — every seat mutation does a full `get_option`+`update_option(CACHE_OPTION,[])` registry wipe; a concurrent `store_cache_key()` can be lost → stale public list/calendar for up to an hour. **Fix:** in `bust_cache()` delete only the per-event caps transient; collapse the list-cache clear to once per request; clear list caches by deterministic prefix or a cache-version counter rather than read-modify-write.

**L5 — Surplus removal and pending→confirmed flip are mutually exclusive in one pass** · correctness (speculative)
`class-woocommerce.php:1376-1412` — the flip runs only in the `diff<=0` else-branch; a converged single pass with both surplus and surviving pending seats leaves survivors `pending` on a completed order (confirmed email skipped). Usually self-heals via payment_complete double-fire. **Fix:** run the flip for surviving active seats unconditionally, independent of the diff branch.

**L6 — Once-per-order buyer confirmation suppresses email for event lines added after first confirmation** · correctness
`class-woocommerce.php:1843-1852` — `sent['customer']` gate blocks confirmation for a second event line added via later manual order edit. **Fix:** gate per-event (`sent['customer:'.$event_id]`) or send a supplementary confirmation when a pass produces seats for a not-previously-covered event.

**L7 — Organizer "seats released" notice counts cancelled waitlist seats as freed capacity** · correctness
`class-woocommerce.php:1536-1547` — `released_new = count(result['removed'])` includes waitlist seats (non-terminal/active) which never consumed capacity, overstating freed seats. Email accuracy only. **Fix:** count only removed seats whose pre-removal status was confirmed/pending.

**L8 — Batched `$order->save()` in reconcile not exception-guarded** · perf
`class-woocommerce.php:1256` (inside try/finally, finally only clears in-flight). Runs on payment_complete/status-change. If save() throws (DB error or another plugin throwing on update-order hooks), the exception bubbles into the gateway callback. The module elsewhere uses `Events_Log::safe_save()` precisely to prevent this. **Fix:** wrap in `try/catch(\Throwable)` logging via `Events_Log::error`, or route through the shared safe-save helper.

**L9 — Daily status-sweep cron never unscheduled when module disabled via toggle** · perf
`anchor-events-manager.php:123-127,130-136` — `on_deactivate` is registered inside the constructor, which never runs for a disabled module, leaving an orphaned recurring `anchor_events_status_sweep` with no callback. **Fix:** clear the hook (`wp_clear_scheduled_hook`) when `events_manager` transitions enabled→disabled, or via an always-loaded cleanup.

**L10 — `render_needs_review_notice()` runs unbounded `wc_get_orders` EXISTS scan on every relevant admin screen incl. all Anchor Tools tabs** · perf
`class-woocommerce.php:2129-2155` — `page=anchor-schema` matches every settings tab, not just Events; uncapped meta-EXISTS scan per load. **Fix:** scope to the Events tab; cache the count in a short transient invalidated on flag/clear, or query with a small limit just to detect presence.

**L11 — WC Subscriptions renewal orders flag `attendees_missing` every cycle, create no seats** · wc-hpos (speculative)
`class-woocommerce.php:1303-1315, 1665-1682` — renewals don't fire the line-item capture hook; the live resolver still resolves the event, so reconcile flags and creates nothing each cycle. **Fix:** skip renewals (`wcs_order_contains_renewal`) or copy attendee meta from the parent order — or document linked products must not be subscriptions.

**L12 — `claim_woo_seats()` is dead code (reimplemented inline in `reconcile_line()`)** · correctness/maintenance
`class-registrations.php:514-584` — zero callers (grep-verified); paid allocation/idempotency lives inline in `reconcile_line` (class-woocommerce.php 1325-1496), which relies on `max_index+1` within a per-item snapshot and never calls `find_seat_by_item()` before create. Two implementations of the capacity invariant will drift. *(This consolidates the four duplicate "dead code" findings reported across the correctness/data-edge/completeness dimensions — same method, same fix.)* **Fix:** delete `claim_woo_seats()` and its doc refs, or have `reconcile_line` delegate to it so there's a single audited paid-allocation routine; if kept, add a `find_seat_by_item()` guard in reconcile_line for parity.

**L13 — Nonces passed to `wp_verify_nonce()` without `wp_unslash`/sanitize** · security (consistency only)
`anchor-events-manager.php:869, 2332, 1932-1933, 1554-1555` — not exploitable (nonces are alphanumeric) but inconsistent with hardened handlers like `save_product_link()` (class-woocommerce.php:431). **Fix:** wrap each read in `sanitize_text_field( wp_unslash( ... ) )`.

**L14 — No WP personal-data exporter/eraser hooks; attendee PII invisible to GDPR tools** · completeness/compliance
`class-registrations.php:107-125` — seat post_meta stores attendee name/email/phone; no `wp_privacy_personal_data_exporters`/`_erasers` registered, so WP Tools > Export/Erase won't surface or remove it. Real compliance gap now that paid PII is stored. **Fix:** register exporter/eraser callbacks querying seats by `_anchor_event_email`/`_anchor_event_customer_id`.

**L15 — Spec-reserved seat flag `_anchor_event_attendee_notified` not registered** · completeness (minor)
`anchor-events-manager.php:357-401` — `notify_attendee` setting reserved but the seat meta key the spec asked to reserve is absent. Harmless; doesn't preclude later addition. **Fix (optional):** register it in `register_meta()` to honor the reservation.

### INFO / by-design (no action required, recorded for honesty)

- **GET_LOCK degraded mode runs the mutation unlocked under contention** (`class-registrations.php:373-388`). On lock timeout (got===0) or NULL the closure still executes, so the one scenario the lock exists for can fall through to unlocked execution → oversell. This is the documented graceful-degradation design (surfaced via `capacity_lock_unavailable` on the paid path; see L2 for the free-path gap). Acceptable as documented; if stricter safety is wanted, fail closed for paid creation on `got===0` or raise the GET_LOCK timeout for the reservation critical section.
- **Customer-facing cancellation/refund email is organizer-only** — consistent with MVP spec; see inventory below.

---

## 3. Feature Inventory (BUILT / PARTIAL / NOT-BUILT)

**BUILT (verified against spec §2 MVP):**
- Product/variation→event linking + read-only event mirror
- Per-seat attendee capture at classic checkout (name/email/phone) with guest support
- Order-status→seat sync via single idempotent `reconcile_order` (on-hold/pending/processing/completed/failed/cancelled/refunded, payment_complete insurance, manual order edits, line delete, order trash/delete release, manual Resync button)
- Capacity hard-limit + **waitlist (status only)** under GET_LOCK
- Roster `WP_List_Table` (status/source/search filters, manual add/edit/cancel)
- CSV export (active-only/all) with `csv_safe` formula-injection guard + dynamic custom-field union
- Organizer notice + one-per-order **customer confirmation** email (both default-on), with wp_mail-failure logging
- Per-order sync log + needs-review notices (HPOS-safe `meta_query EXISTS`) + site-wide error log with clear action
- HPOS declaration at `anchor-tools.php` file scope

**PARTIAL:**
- **Custom questions (dietary/license/CE):** free `[event_registration]` path supports them *only* via the `anchor_events_registration_fields` developer filter (no admin UI; default `[]`). The **paid checkout collects only name/email/phone** — no custom-question support. Data model (`_anchor_event_reg_fields`, `create_seat` `reg_fields`) is not precluded.

**NOT-BUILT (intentionally deferred per spec; data model does not preclude any):**
- **Email reminders before the event** — no event-date cron (sweep only recomputes status).
- **Customer-facing cancellation/refund email** — only the organizer gets a "seats released" notice; buyer gets nothing on cancel/refund. (Confirmation is the only buyer email.)
- **"Send roster" / final-roster digest to organizer** — organizer gets only per-order confirmed/released notices.
- **Waitlist auto-promotion** — `reconcile_line` explicitly skips waitlist seats; the `waitlist→confirmed` transition exists but nothing fires it.
- **Check-in / QR / mark attended/no-show UI** — `STATUS_ATTENDED`/`STATUS_NO_SHOW` and transitions reserved; only the roster action is missing.
- **Certificates / CE-course fields** — not built; meta extensible.
- **Add-to-calendar / ICS** — not built.
- **Transfer attendee between events** — not built (variation-change is treated as cancel-and-recreate; a true transfer needs a new method, but seat `_anchor_event_id` is mutable).
- **Privacy / personal-data exporter+eraser hooks & anonymization** — not built (see L14 — flagged as a real compliance gap, not just deferral).

---

## 4. Fix-Before-Release Punch List (prioritized)

1. **H1 — Gate the virtual join URL** behind a confirmed-seat / roster-cap check; suppress entirely on the public page for linked paid events. *(Security blocker — the paid model is bypassable today.)*
2. **H2 — Early-return `reconcile_order()` for orders with no event lines** (helper exists) and make `$order->save()` dirty-flag conditional. *(Stops store-wide meta pollution + doubled writes on every order.)*
3. **M1 — Add post-type check on the front-end editor edit path** and validate `post_status` against publish capability. *(Security: type confusion + publish bypass.)*
4. **M2 — Re-gate roster/CSV behind a WooCommerce-aware capability** when WC is active. *(Security: customer-PII exposure to Editors.)*
5. **M6 — Treat unknown WC statuses as "leave seats as-is,"** not "cancel everything." *(Prevents seat loss/overfill with deposit/subscription/fulfillment plugins.)*
6. **M3 — Handle order-pay / admin-created orders** (capture attendees there, or make the missing-attendee needs-review path loud). *(Paid order with zero seats.)*
7. **M4 — Fix the mixed-refund flag clobber** (flag after reconcile or thread into `$review_flags`). *(Lost needs-review on unexplained refunded money.)*
8. **M5 — Serialize the order audit/notification write** (per-order lock or re-read-before-save) to stop duplicate emails / lost flags under concurrent reconciles.
9. **L8 — Wrap the reconcile `$order->save()` in `try/catch(\Throwable)`** so a persistence error can't abort checkout/payment. *(Cheap, high-value resilience.)*
10. **L1 — Fix variation-change attendee mapping** (creation-counter, not absolute index) to stop overwriting captured attendee data with billing data.
11. **L12 — Resolve the `claim_woo_seats()` dead-code fork** (delete or delegate) before the two paid-allocation implementations drift.

**Recommended fast-follow (not release blockers):** L2 (free-path oversell signal), L14 (GDPR exporter/eraser — paid PII is now live), L4/L9/L10 (perf cleanups), L13 (nonce unslash consistency).

Key files: `/Volumes/G-DRIVE SSD/DEVELOPER/Anchor-Tools/anchor-events-manager/anchor-events-manager.php`, `/Volumes/G-DRIVE SSD/DEVELOPER/Anchor-Tools/anchor-events-manager/class-woocommerce.php`, `/Volumes/G-DRIVE SSD/DEVELOPER/Anchor-Tools/anchor-events-manager/class-registrations.php`, `/Volumes/G-DRIVE SSD/DEVELOPER/Anchor-Tools/anchor-events-manager/class-roster.php`, `/Volumes/G-DRIVE SSD/DEVELOPER/Anchor-Tools/anchor-events-manager/class-events-log.php`.