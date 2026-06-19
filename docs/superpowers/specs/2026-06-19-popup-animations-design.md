# Anchor Universal Popups — Open/Close Animations

**Date:** 2026-06-19
**Module:** `anchor-universal-popups/`
**Status:** Approved design

## Problem

The popup module shows centered popups (`modal`, `theater`, `fullscreen`) with no
entrance animation — they appear instantly. Drawers and fly-ins animate, but with
hardcoded `@keyframes` that the user cannot change or disable. There is no
user-facing control over how a popup animates in or out.

## Goal

Give each popup a user-selectable entrance **and** exit animation via a new
dropdown in the popup editor, applied to **all** popup styles. Replace the
existing hardcoded drawer/fly-in keyframes with the same configurable system so
behavior is unified and overridable.

## Approach

Use **CSS transitions toggled by an `is-open` class** rather than `@keyframes`.
Transitions are reversible by nature: the same declaration plays forward on open
and backward on close, which is exactly what a symmetric entrance+exit needs and
avoids maintaining separate enter/exit keyframe pairs.

Rejected alternatives:
- **JS animation library** — unnecessary weight for a raw PHP/CSS/JS plugin.
- **`@keyframes` per variant** — awkward to reverse for exit; what we're replacing.

## New Settings (per popup)

Added to `defaults()`, the settings metabox, the `save_post` whitelist, and the
`$snippets` payload localized as `UP_SNIPPETS`.

| Setting | Values | Default |
|---|---|---|
| `open_animation` | `auto`, `none`, `fade`, `zoom`, `slide`, `flyin` | `auto` |
| `animation_direction` | `up`, `down`, `left`, `right` (used by `slide`/`flyin` only) | `up` |
| `animation_duration` | integer ms, clamped 100–1000 | `300` |

### `auto` semantics (backward compatibility)

`auto` derives the animation from the popup style so **existing popups behave
exactly as they do today** until someone picks a specific animation:

- centered styles (`modal`, `theater`) → `zoom` (scale + fade)
- `drawer-right` / `drawer-left` / `drawer-bottom` → `slide` from the matching edge
- `flyin-bottom` / `flyin-bottom-left` / `flyin-bottom-right` → `flyin` from the matching corner

When `open_animation` is a specific value, the user's `animation_direction`
applies (for `slide`/`flyin`). When derived from `auto`, the direction is taken
from the style's anchor edge.

## Frontend Mechanics

### Markup / data

- JS adds `up-anim-{type}` and (for slide/flyin) `up-anim-dir-{dir}` classes to
  the `.up-modal` element built in `buildModalShell()`.
- JS sets `--up-anim-duration` inline on `.up-modal` from `animation_duration`.
- `open_animation`, `animation_direction`, `animation_duration` are passed through
  the `$snippets` payload and read off `sn` in the show path.

### Open lifecycle (`revealModal`)

1. Set `modal.hidden = false`.
2. On the next animation frame, add `is-open`.
3. CSS transitions the dialog + backdrop from their closed state
   (e.g. `opacity:0; transform: translateY(20px) scale(.96)`) to the open state
   (`opacity:1; transform:none`) over `--up-anim-duration`.

### Close lifecycle (`closeModal`)

1. If already closing, no-op (guard, mirroring the existing `_closing` flag).
2. Remove `is-open`, add `is-closing` → CSS reverses the transition.
3. After `transitionend` (with a `setTimeout` fallback of `duration + buffer` in
   case `transitionend` doesn't fire), clear popup content (`[data-frame]`,
   `[data-after]`) and set `modal.hidden = true`, then remove `is-closing`.
   This generalizes the close-timer pattern already used by the fullscreen
   takeover's `collapseTakeover()`.

### Animation definitions (CSS)

Each animation is a CSS rule keyed on the `up-anim-*` classes:

- `fade` — opacity only.
- `zoom` — opacity + `scale(.96 → 1)`.
- `slide` — `translate` by `animation_direction` (e.g. `dir-up` = `translateY(20px → 0)`).
- `flyin` — same translate as slide but with an overshoot easing curve
  (`cubic-bezier(.22,.61,.36,1)`).

Easing is fixed per animation; only duration is user-configurable. Backdrop
always cross-fades regardless of the chosen dialog animation.

### `none`

Skips the transition — popup appears/disappears instantly (current `modal`
behavior). Also the effective behavior under `prefers-reduced-motion`.

## Accessibility

`@media (prefers-reduced-motion: reduce)` collapses every animation to a quick
opacity fade (or instant), so the popup still appears/closes but without motion.

## Out of Scope / Preserved

- **Fullscreen takeover** (`up-style-fullscreen`, scroll-driven FLIP expand from a
  thumbnail anchor via `expandTakeover`/`collapseTakeover`) stays as-is. It is a
  distinct self-anchoring behavior, not a generic entrance animation, and
  `closeModal` already routes it to `collapseTakeover`.

## Files Touched

- `anchor-universal-popups/anchor-universal-popups.php`
  - `defaults()` — three new keys.
  - settings metabox (near the `up_popup_style` select, ~line 639) — animation
    dropdown, direction select, duration input.
  - `save_post` whitelist (~line 785) — three new keys.
  - `$snippets` payload (~line 955) — three new values.
- `anchor-universal-popups/assets/frontend.js`
  - apply classes + duration var; `is-open`/`is-closing` lifecycle in
    `revealModal` and `closeModal`.
- `anchor-universal-popups/assets/frontend.css`
  - transition states per animation + direction; remove old drawer/flyin
    `@keyframes`; reduced-motion media query.
- Rebuild `frontend.min.css` / `frontend.min.js`; bump the `wp_enqueue_*` version
  strings.
- Bump plugin `Version:` header per release process.

## Testing (manual, no automated suite)

- Each `open_animation` value on a centered modal: open + close animate correctly.
- `auto` on a drawer and a fly-in reproduces current behavior.
- Direction field changes slide/flyin direction.
- Duration field changes timing.
- Existing popups (empty meta) animate identically to before (regression).
- Fullscreen takeover still expands/collapses from its anchor.
- `prefers-reduced-motion` collapses animations to a fade.
