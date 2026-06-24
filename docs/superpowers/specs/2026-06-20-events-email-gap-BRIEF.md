# Brief: Events Email Gap (v1.1) — for the spec-writing agent

**Your task:** write the full design spec (then, separately, the phased implementation plan) for the email features that were deferred from the WooCommerce events MVP. This brief gives you the context, the existing system to build on, the features, the decisions you must resolve, and the acceptance bar. Follow the brainstorming → spec → plan workflow. Write the spec to `docs/superpowers/specs/2026-06-20-events-email-gap-design.md` and commit it. Do **not** start implementation — stop at the plan.

---

## 1. Context

The module `anchor-events-manager` (namespace `Anchor\Events`) is a WordPress plugin module that does free internal event registration + event display, and (newly merged) full WooCommerce-integrated paid registration. One seat = one `anchor_event_reg` post. The MVP shipped a **transactional** email system but deliberately deferred the **lifecycle/notification** emails. This work fills that gap.

Read these before writing the spec:
- `docs/superpowers/specs/2026-06-19-woocommerce-events-design.md` (esp. §4 data model, §11 emails & logging)
- `docs/superpowers/specs/2026-06-19-woocommerce-events-implementation-plan.md`
- `docs/superpowers/specs/2026-06-20-wc-events-review-findings.md` (review of the merged code)
- The code: `anchor-events-manager/anchor-events-manager.php`, `class-woocommerce.php`, `class-registrations.php`, `class-events-log.php`.

## 2. Features in scope (the gap)

1. **Reminder email(s) before the event** — e.g. "your event is in N days." Sent to confirmed attendees (free + paid). Must support at least one configurable lead time; consider multiple (e.g. 7 days + 1 day).
2. **Customer-facing cancellation / refund email** — when a seat is cancelled or refunded, email the *attendee* (today only the organizer gets a "seats released" notice; the buyer gets nothing).
3. **Final roster email to organizer + manual "Send roster" button** — a digest of the event's confirmed attendees, sendable on demand from the roster/admin and/or automatically at a configurable time before/after the event.
4. **Round out the template/placeholder system** — today there's a partial token-expansion helper (`expand_email_tokens`) + editable subject/intro for confirmations. Extend to a consistent, documented placeholder set across all event emails (e.g. `{event_title}`, `{event_date}`, `{venue}`, `{attendee_name}`, `{join_link}`, `{order_number}`, `{remaining}`, etc.).

Out of scope (stay deferred): waitlist auto-promotion, check-in/QR, certificates, ICS/add-to-calendar, attendee transfer. (If reminders naturally want an ICS attachment, you may note it as an option but don't require it.)

## 3. Existing system to build on (do NOT reinvent)

- **Send + failure logging:** `Module::send_html_email($to,$subject,$html,$headers=[]):bool` already wraps `wp_mail` and logs failures; `wp_mail_failed` is captured into `Events_Log::error()` (which redacts PII). Reuse these.
- **Email builder:** `Module::build_registration_email_html($ctx_or_legacy_args)` builds the HTML shell from a `$ctx` array (`event_id,name,status,intro_message,guests,detail_rows[],seat_list[],cta_label,cta_url`) and now also renders a virtual "Join the event" button for confirmed virtual events. Filter `anchor_events_registration_email_html`. Reuse/extend this builder rather than making new HTML shells.
- **Token expansion:** `Module::expand_email_tokens($template,$tokens)`.
- **Transactional emails today (paid path):** `class-woocommerce.php` `dispatch_emails()` sends one customer confirmation per order (gated per-event via order meta `_anchor_event_emails_sent` key `customer:{event_id}`) + organizer notice (`organizer:{event_id}`), and a "seats released" organizer notice on cancel/refund. Triggered from the confirmed `reconcile_order()` pass, inside a per-order lock, riding a single batched `$order->save()`.
- **Transactional email today (free path):** `Module::send_registration_emails()`.
- **Settings:** `get_settings()` defaults + `register_settings()` + `sanitize_settings()` already hold `wc_notify_customer/organizer`, `wc_customer_subject`, `wc_customer_intro`, `wc_organizer_subject`, global `organizer_email`, and a **reserved `notify_attendee`** flag. Event-level recipient is event meta `_anchor_event_organizer_email`. The WC email subsection renders only when `class_exists('WooCommerce')`.
- **Reserved seat flag:** `_anchor_event_reg_status` history + a reserved seat meta `_anchor_event_attendee_notified` already registered — use it (or per-type variants) to make reminders idempotent.
- **Scheduling precedent:** a daily cron `anchor_events_status_sweep` is already scheduled defensively on `init` via `wp_next_scheduled()` guard (survives plugin upgrades) and self-unschedules when the CPT is absent. **Model the reminder scheduler on this exact pattern** (do not rely on `register_activation_hook`, which doesn't fire on plugin updates here).
- **Data the seats already carry:** event_id, attendee name/email/phone, status, source (internal|woocommerce|manual|imported), order/customer ids, history. Events carry `start_ts`/`end_ts` (cached unix timestamps), timezone, virtual + virtual_url, venue/address.

## 4. Design decisions the spec MUST resolve (call these out explicitly)

1. **Reminder scheduling mechanism.** Recommended: a single recurring cron (hourly or daily) that queries events whose `start_ts` falls in the upcoming lead-time window and sends to confirmed seats not yet reminded for that offset — NOT a per-seat `wp_schedule_single_event` (which is fragile across edits/reschedules). Spec the query, the per-seat idempotency marker (e.g. `_anchor_event_attendee_notified` storing which offsets were sent, or a per-offset meta), timezone handling (use the event timezone / site tz consistently with `calculate_timestamps`), and what happens if the event date moves.
2. **How many reminders + lead times,** and whether they're global settings vs per-event overrides. Define defaults.
3. **Idempotency for every new email** (reminders, cancellation, roster) so re-runs / re-syncs / cron overlaps never double-send. Reuse the `emails_sent` gate pattern (order meta for paid; a seat meta marker for free/reminders).
4. **Recipient resolution + opt-out.** Confirm the recipient for each email; respect existing enable/disable settings; add new toggles (e.g. `wc_notify_customer_cancellation`, `reminder_enabled`, `reminder_offsets`, `organizer_roster_email`). Consider an unsubscribe/opt-out stance (at minimum honor the existing toggles; transactional emails generally don't need unsubscribe, but reminders are borderline — make a recommendation).
5. **Free vs paid parity.** Reminders and cancellation emails must work for BOTH free seats and paid seats (the seat data layer is the common source; don't tie reminders to orders).
6. **Cancellation/refund email triggering.** Today cancel/refund flows through `reconcile_order()` / `update_status()`. Decide where the customer cancellation email fires (likely a hook/callback on seat status → cancelled/refunded, in the data layer or the reconcile pass) so it covers paid (order-driven), manual roster cancel, and order trash/delete. Avoid emailing on every reconcile pass — only on the actual transition into cancelled/refunded.
7. **"Send roster" — manual + scheduled?** Define the manual button (where it lives: roster screen + order? cap `Roster::current_user_can_manage()`; nonce), the digest contents/format (confirmed attendees, counts, capacity), and whether to also auto-send at a configurable time (e.g. day before). Recommend.
8. **Template/placeholder system scope.** Decide whether to add per-email-type subject + body templates in settings (with the documented placeholder set) or keep intro-only + code-built bodies. Recommend something maintainable; don't over-build a full template editor unless justified.

## 5. Cross-cutting constraints (non-negotiable)

- **WooCommerce-optional:** reminders, cancellation emails, and roster emails must work with WC absent (they key off seats/events, not orders). Anything order-specific stays guarded by `class_exists('WooCommerce')`/`function_exists('wc_*')`.
- **HPOS-safe:** any order access via WC CRUD only (no order postmeta).
- **No PII in logs** (Events_Log already redacts; keep it that way).
- **i18n:** text domain `anchor-schema`; all strings translatable with proper placeholders.
- **Idempotent + cron-upgrade-safe** (see §3 scheduling precedent).
- **Escape all output;** caps + nonces on any new admin actions.
- **Reuse `send_html_email` + `build_registration_email_html`** so failure logging and the HTML shell stay consistent.
- Keep the free + paid transactional emails already shipped working unchanged.

## 6. Acceptance criteria the spec should define (test matrix)

- Reminder fires once per attendee per configured offset; never double-sends across cron overlaps, re-syncs, or event-detail edits; respects the enable toggle; works for a free seat and a paid seat; uses the correct event timezone; does not fire for cancelled/refunded/waitlist seats; handles an event whose date moved (re-evaluates window).
- Cancellation email fires exactly once when a seat actually transitions to cancelled/refunded — via order cancel, partial refund, manual roster cancel, and order trash/delete — and not on no-op reconcile passes; not sent to waitlist-only seats unless intended.
- Manual "Send roster" produces an accurate confirmed-attendee digest, cap+nonce protected; optional scheduled roster send (if specced) is idempotent.
- All emails: WC-absent site still sends free-path versions; failures land in the error log; placeholders expand correctly; no PII leaks.

## 7. Deliverables (for the spec agent)

1. The design spec at `docs/superpowers/specs/2026-06-20-events-email-gap-design.md` (sections: overview, scope, decisions-resolved with rationale, data model additions/markers, scheduling design, per-email design (reminder/cancellation/roster/templates), settings additions, free-vs-paid handling, idempotency, constraints, acceptance/test matrix). Commit it.
2. Then a phased implementation plan (independently shippable phases, manual verification steps since there's no automated suite, acceptance criteria per phase). Stop at the plan for human review — do not implement.
