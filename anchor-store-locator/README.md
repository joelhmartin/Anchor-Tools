# Anchor Store Locator

Google-Maps-backed store/location finder. CPT `anchor_store` (Store Locations), class `\Anchor\StoreLocator\Module`.

## Shortcode: `[anchor_store_locator]`

Renders the search box, radius selector, map and a results list of published `anchor_store` posts. Requires a Google Maps API key (**Anchor Tools > Settings**); shows a "missing key" notice in its place otherwise.

| Attribute | Values | Default | Notes |
|---|---|---|---|
| `card` | `full`, `compact` | `full` | `full` is today's card (photo, distance, address, phone/email/website, excerpt, "View location"). `compact` is a small blurb card: title, owner ("Dr. ..."), and "View location" only. Existing sites that don't pass `card` are unaffected. |

The chosen mode is exposed on the root element as `data-anchor-store-card="full\|compact"` and read by `assets/frontend.js`; both cards are built by the same `createCard()` function with a mode switch, not separate builders. Card click/keyboard activation and map-marker sync (pan, zoom, info window, `.is-active` highlight) behave identically in both modes.

Compact-card look is themable via CSS custom properties on `.anchor-store-card--compact` (see `assets/frontend.css`): `--asl-compact-padding`, `--asl-compact-gap`, `--asl-compact-radius`, `--asl-compact-bg`, `--asl-compact-border`, `--asl-compact-title-size`, `--asl-compact-title-color`, `--asl-compact-owner-size`, `--asl-compact-owner-color`, `--asl-compact-link-size`, `--asl-compact-link-color`. All have neutral fallbacks; no brand colors are hardcoded.

## Shortcode: `[anchor_store_field]`

Prints a single meta value for the current (or `id="..."`) store: `field="owner|address|lat|lng|website|email|phone|maps_url|place_id"`, with optional `format="tel|email|link|map"`.

## Meta (prefix `_anchor_store_`)

`address`, `lat`, `lng`, `website`, `email`, `phone`, `maps_url`, `place_id`, `owner` (e.g. "Dr. Erica Sok", shown in the compact card and by `[anchor_store_field field="owner"]`).
