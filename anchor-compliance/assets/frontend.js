/**
 * Anchor Compliance — front-end consent runtime.
 *
 * Vanilla JS. No jQuery, no ES modules, no build step. This file is enqueued
 * in the footer and runs before jQuery is registered, so nothing here may
 * assume a library, a polyfill, or module scope.
 *
 * Syntax target is deliberately conservative (ES5: `var`, `function`, no
 * arrow functions, no optional chaining, no nullish coalescing) because a
 * parse error in this file does not degrade gracefully — it would leave every
 * blocked tracker permanently blocked and the banner permanently unclickable
 * on every page of the site.
 *
 * Contracts this file implements (all verified against the task reports):
 *   - Task 9 — `window.AnchorComplianceData` payload, banner DOM ids/classes,
 *     `data-anchor-action` values, `data-anchor-category` toggles, the
 *     `#anchor-cmp-live` region, and the five `--acmp-*` custom properties.
 *   - Task 6 — neutralized-tag shape: `type="text/plain"`,
 *     `data-anchor-consent="CATEGORY"`, `data-anchor-src="URL"`, and the
 *     `<span class="anchor-cmp-placeholder">` / `[data-anchor-accept]`
 *     placeholder that precedes a blocked `<iframe>`.
 *   - Task 8 — `POST {restUrl}` with `{consent_id, categories, method}`,
 *     no nonce, fire-and-forget, any 200 is success.
 *
 * Execution order in this file matters and is documented section by section.
 */
(function () {
	'use strict';

	/* ===================================================================
	 * SECTION 0 — First-touch attribution capture.
	 *
	 * This MUST be the very first statement that runs. `document.referrer`
	 * and the landing URL are only trustworthy before any other script has
	 * had a chance to navigate, history.replaceState() the query string
	 * away, or strip UTM parameters. Several common WordPress plugins do
	 * exactly that on DOMContentLoaded.
	 *
	 * It is captured into a plain in-memory variable and NOTHING ELSE.
	 * No cookie, no localStorage, no sessionStorage — no storage at all —
	 * until a consent decision exists that covers CallTrackingMetrics'
	 * category. Reading `document.referrer` into a variable is not
	 * "storing information on the terminal equipment of a subscriber", so
	 * ePrivacy Art. 5(3) is simply not engaged by this section.
	 * =================================================================== */
	var firstTouch = {
		referrer: document.referrer || '',
		landing: location.href,
		query: location.search || ''
	};

	/**
	 * ACCEPTED LIMITATION — first-touch loss in strict regions.
	 *
	 * In a strict (consent-required) region we may not write `firstTouch`
	 * anywhere until the visitor consents. A visitor who lands on /pricing
	 * from a Google Ads click, browses to /about and /contact, and only then
	 * clicks "Accept All" will have `firstTouch` reflect /contact — the page
	 * they were on when they consented — not /pricing. The original referrer
	 * and gclid are gone, because the only lawful place to have kept them
	 * across those navigations was storage we were not allowed to use.
	 *
	 * This is deliberate and is not a bug to be "fixed" by writing the
	 * snapshot earlier. Losing attribution costs money; writing identifiers
	 * before consent costs a regulatory finding. A same-page consent (the
	 * overwhelmingly common case, since the banner is shown on the landing
	 * page) gives CTM attribution identical to an unblocked load.
	 */
	var FIRST_TOUCH_KEY = 'anchor_cmp_ft';

	/* ===================================================================
	 * SECTION 1 — Payload bootstrap.
	 * =================================================================== */

	var D = window.AnchorComplianceData;

	// Bail silently. The module can be disabled, or an aggressive HTML
	// optimizer can strip the inline payload; either way there is nothing
	// to do and throwing here would break unrelated footer scripts.
	if (!D || typeof D !== 'object') {
		return;
	}

	var ALL_CATEGORIES = ['necessary', 'functional', 'analytics', 'marketing'];
	var VALID_METHODS = ['banner', 'preference_center', 'gpc', 'api'];

	// Normalize the numeric payload fields once; they arrive as ints but a
	// hand-edited filter could hand us strings.
	var POLICY_VERSION = parseInt(D.policyVersion, 10);
	var LIFETIME_DAYS = parseInt(D.lifetimeDays, 10);
	if (isNaN(POLICY_VERSION)) { POLICY_VERSION = 0; }
	if (isNaN(LIFETIME_DAYS) || LIFETIME_DAYS <= 0) { LIFETIME_DAYS = 365; }

	var COOKIE_NAME = D.cookieName || 'anchor_consent';
	var COOKIE_PATTERNS = (D.cookiePatterns && typeof D.cookiePatterns === 'object') ? D.cookiePatterns : {};
	var SIGNAL_MAP = (D.signalMap && typeof D.signalMap === 'object') ? D.signalMap : {};
	var I18N = (D.i18n && typeof D.i18n === 'object') ? D.i18n : {};
	var CTM = (D.ctm && typeof D.ctm === 'object') ? D.ctm : { enabled: false, category: 'marketing' };

	/* ===================================================================
	 * SECTION 2 — Tiny DOM / language helpers.
	 *
	 * Deliberately hand-rolled rather than relying on NodeList.prototype
	 * .forEach, Element.prototype.closest, Array.from or ChildNode.remove,
	 * all of which are missing from Safari versions still in the wild on
	 * older iPads that clients' customers genuinely use.
	 * =================================================================== */

	function each(list, fn) {
		if (!list) { return; }
		for (var i = 0; i < list.length; i++) {
			fn(list[i], i);
		}
	}

	function qsa(selector, context) {
		try {
			return (context || document).querySelectorAll(selector);
		} catch (e) {
			return [];
		}
	}

	function qs(selector, context) {
		try {
			return (context || document).querySelector(selector);
		} catch (e) {
			return null;
		}
	}

	function inArray(needle, haystack) {
		if (!haystack) { return false; }
		for (var i = 0; i < haystack.length; i++) {
			if (haystack[i] === needle) { return true; }
		}
		return false;
	}

	function hasOwn(obj, key) {
		return Object.prototype.hasOwnProperty.call(obj, key);
	}

	function detach(node) {
		if (node && node.parentNode) {
			node.parentNode.removeChild(node);
		}
	}

	/** Element.closest() with a manual walk-up fallback. */
	function closest(node, selector) {
		var el = node;
		// A click can land on a text node (Firefox) or on an SVG icon inside
		// a button; start from the nearest element either way.
		while (el && el.nodeType !== 1) { el = el.parentNode; }
		while (el && el.nodeType === 1) {
			if (el.closest) {
				try { return el.closest(selector); } catch (e) { /* fall through */ }
			}
			var matches = el.matches || el.msMatchesSelector || el.webkitMatchesSelector;
			if (matches) {
				try {
					if (matches.call(el, selector)) { return el; }
				} catch (e) { /* ignore */ }
			}
			el = el.parentNode;
		}
		return null;
	}

	/**
	 * Decode HTML entities in a string destined for `textContent`.
	 *
	 * Every `content.*` setting is stored through `wp_kses_post()`, which
	 * entity-encodes bare specials — the `placeholder_button` default
	 * "Accept & Load" comes back as "Accept &amp; Load" the first time the
	 * settings form posts that field. Strings we inject as trusted markup
	 * (notice_body, dns_label, via innerHTML) are decoded by the HTML parser
	 * for free; strings we assign to `textContent` are NOT, so "&amp;" would
	 * render literally to the visitor.
	 *
	 * A detached <textarea> is the decoder because its content model is
	 * RCDATA: markup inside is parsed as text, so no element is ever
	 * constructed and no handler can fire. Assigning the same string to a
	 * <div>'s innerHTML would be an XSS primitive; this is not.
	 */
	var entityDecoder = null;
	function decodeEntities(str) {
		var s = String(str);
		if (s.indexOf('&') === -1) { return s; }
		try {
			if (!entityDecoder) { entityDecoder = document.createElement('textarea'); }
			entityDecoder.innerHTML = s;
			return entityDecoder.value;
		} catch (e) {
			// Explicit fallback for the five specials wp_kses_post produces.
			return s.replace(/&lt;/g, '<').replace(/&gt;/g, '>')
				.replace(/&quot;/g, '"').replace(/&#0?39;/g, "'")
				.replace(/&amp;/g, '&');
		}
	}

	/** A `content.*` string, entity-decoded, ready for textContent. */
	function i18nText(key, fallback) {
		var raw = (I18N && I18N[key]) ? String(I18N[key]) : '';
		return raw ? decodeEntities(raw) : fallback;
	}

	function setHidden(el, hidden) {
		if (!el) { return; }
		if (hidden) {
			el.setAttribute('hidden', 'hidden');
		} else {
			el.removeAttribute('hidden');
		}
	}

	/* ===================================================================
	 * SECTION 3 — Cookie read / write, and the base64url codec.
	 *
	 * The encoding mirrors Anchor_Compliance_Consent_State::encode/decode
	 * exactly: JSON, base64, `+/` swapped for `-_`, trailing `=` removed.
	 * PHP's base64_decode() in strict mode accepts unpadded input, so the
	 * padding-free form round-trips both directions.
	 * =================================================================== */

	function readRawCookie(name) {
		var target = name + '=';
		var parts = document.cookie ? document.cookie.split(';') : [];
		for (var i = 0; i < parts.length; i++) {
			var part = parts[i];
			while (part.charAt(0) === ' ') { part = part.substring(1); }
			if (part.indexOf(target) === 0) {
				return part.substring(target.length);
			}
		}
		return '';
	}

	/** UTF-8 safe: percent-encode, then map each escape to a byte. */
	function utf8ToBinary(str) {
		return encodeURIComponent(str).replace(/%([0-9A-F]{2})/gi, function (_, hex) {
			return String.fromCharCode(parseInt(hex, 16));
		});
	}

	/** Inverse of utf8ToBinary. Falls back to the raw bytes if not valid UTF-8. */
	function binaryToUtf8(bin) {
		var out = '';
		for (var i = 0; i < bin.length; i++) {
			var code = bin.charCodeAt(i);
			out += '%' + (code < 16 ? '0' : '') + code.toString(16);
		}
		try {
			return decodeURIComponent(out);
		} catch (e) {
			return bin;
		}
	}

	function base64UrlEncode(str) {
		try {
			return window.btoa(utf8ToBinary(str))
				.replace(/\+/g, '-')
				.replace(/\//g, '_')
				.replace(/=+$/, '');
		} catch (e) {
			return '';
		}
	}

	function base64UrlDecode(str) {
		var b64 = String(str).replace(/-/g, '+').replace(/_/g, '/');
		while (b64.length % 4 !== 0) { b64 += '='; }
		try {
			return binaryToUtf8(window.atob(b64));
		} catch (e) {
			return null;
		}
	}

	/**
	 * Mirror of Anchor_Compliance_Consent_State::decode() plus the version
	 * and age validation from ::stored().
	 *
	 * Reading the cookie client-side is not redundant with the server's
	 * `D.hasConsent` / `D.categories`: on a full-page-cached site the payload
	 * baked into the HTML belongs to whichever visitor warmed the cache. The
	 * cookie is the only per-visitor truth available at runtime, so it wins.
	 *
	 * @return {object|null} {id, ts, v, cats:[]} or null.
	 */
	function readStoredConsent() {
		var raw = readRawCookie(COOKIE_NAME);
		if (!raw) { return null; }

		var json = base64UrlDecode(raw);
		if (null === json) { return null; }

		var data;
		try {
			data = JSON.parse(json);
		} catch (e) {
			return null;
		}

		if (!data || typeof data !== 'object' || Object.prototype.toString.call(data.cats) !== '[object Array]') {
			return null;
		}
		if (parseInt(data.v, 10) !== POLICY_VERSION) {
			return null;
		}

		// A missing or non-numeric `ts` is INVALID, not "ageless". PHP casts it
		// with (int), so an absent timestamp becomes 0 and the record is always
		// older than the lifetime — i.e. the server rejects it. Skipping the
		// expiry check on NaN would have the client honor a cookie the server
		// throws away, which shows as a visitor whose banner never reappears
		// while every server-side decision treats them as unconsented.
		var ts = parseInt(data.ts, 10);
		if (isNaN(ts)) {
			return null;
		}

		var age = Math.floor(Date.now() / 1000) - ts;
		// Mirrors PHP: only a POSITIVE age beyond the lifetime expires the
		// record. A negative age means clock skew, not expiry — treating it
		// as expired would silently re-prompt visitors whose clock is fast.
		if (age > LIFETIME_DAYS * 86400) {
			return null;
		}

		return data;
	}

	function writeConsentCookie(id, categories) {
		var payload = {
			id: id,
			ts: Math.floor(Date.now() / 1000),
			v: POLICY_VERSION,
			cats: categories
		};
		var value = base64UrlEncode(JSON.stringify(payload));
		if (!value) { return false; }

		var parts = [
			COOKIE_NAME + '=' + value,
			'path=/',
			'max-age=' + (LIFETIME_DAYS * 86400),
			'SameSite=Lax'
		];
		if ('https:' === location.protocol) {
			parts.push('Secure');
		}
		document.cookie = parts.join('; ');
		return true;
	}

	/* ===================================================================
	 * SECTION 4 — Cookie deletion on withdrawal.
	 * =================================================================== */

	/** Patterns ending in `*` are prefix matches; everything else is exact. */
	function matchesAny(name, patterns) {
		if (!patterns) { return false; }
		for (var i = 0; i < patterns.length; i++) {
			var p = String(patterns[i]);
			if ('*' === p.charAt(p.length - 1)) {
				if (0 === name.indexOf(p.substring(0, p.length - 1))) { return true; }
			} else if (name === p) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Every domain scope a third party could have written the cookie at.
	 *
	 * A tag loaded from a third party sets its cookies on whatever domain
	 * the page is served from, but analytics libraries in particular write
	 * to the registrable parent (`.example.com`) so the value is shared with
	 * subdomains. Deleting only on `location.hostname` leaves the parent-scoped
	 * copy alive and the visitor stays tracked after withdrawing consent —
	 * which is the exact failure a regulator would look for.
	 */
	function deletionDomains() {
		var host = location.hostname;
		var parts = host.split('.');
		var domains = [host, '.' + host];
		if (parts.length > 2) {
			// e.g. www.example.com -> .example.com. For a public-suffix host
			// such as example.co.uk this produces `.co.uk`, which every
			// browser rejects outright — a harmless no-op, not a leak.
			domains.push('.' + parts.slice(-2).join('.'));
		}
		return domains;
	}

	/**
	 * Delete every cookie belonging to a currently-denied category.
	 *
	 * Only iterates the keys `D.cookiePatterns` actually contains. Task 9
	 * guarantees `necessary` is structurally absent from that object rather
	 * than present-and-empty, so iterating it can never reach a strictly
	 * necessary cookie. The module's own consent cookie is excluded by name
	 * as a second, explicit guard — withdrawing consent must not erase the
	 * record of the withdrawal.
	 */
	function sweepDeniedCookies() {
		var denied = [];
		var granted = [];
		var cat;

		for (cat in COOKIE_PATTERNS) {
			if (!hasOwn(COOKIE_PATTERNS, cat)) { continue; }
			if ('necessary' === cat) { continue; }
			var list = COOKIE_PATTERNS[cat] || [];
			if (state[cat]) {
				granted = granted.concat(list);
			} else {
				denied = denied.concat(list);
			}
		}

		if (!denied.length) { return 0; }

		var domains = deletionDomains();
		var removed = 0;
		var raw = document.cookie ? document.cookie.split(';') : [];

		for (var i = 0; i < raw.length; i++) {
			var name = raw[i].split('=')[0];
			name = name ? name.replace(/^\s+|\s+$/g, '') : '';
			if (!name) { continue; }
			if (name === COOKIE_NAME) { continue; }
			if (!matchesAny(name, denied)) { continue; }
			// A pattern shared by a still-granted category must survive.
			if (matchesAny(name, granted)) { continue; }

			for (var d = 0; d < domains.length; d++) {
				document.cookie = name + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/; domain=' + domains[d];
			}
			// Host-only cookies carry no Domain attribute at all and are only
			// matched by an expiry that likewise omits it.
			document.cookie = name + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
			removed++;
		}

		return removed;
	}

	/* ===================================================================
	 * SECTION 5 — Consent state.
	 * =================================================================== */

	/** @type {Object<string,boolean>} the live grant map. */
	var state = {};

	function blankState() {
		var map = {};
		for (var i = 0; i < ALL_CATEGORIES.length; i++) {
			map[ALL_CATEGORIES[i]] = ('necessary' === ALL_CATEGORIES[i]);
		}
		return map;
	}

	/** Mirror of Anchor_Compliance_Consent_State::categories(). */
	function mapFromList(list, defaultGrant) {
		var map = blankState();
		var i;

		if (Object.prototype.toString.call(list) === '[object Array]' && list.length) {
			for (i = 0; i < list.length; i++) {
				if (inArray(list[i], ALL_CATEGORIES)) {
					map[list[i]] = true;
				}
			}
		} else if (defaultGrant) {
			for (i = 0; i < ALL_CATEGORIES.length; i++) {
				map[ALL_CATEGORIES[i]] = true;
			}
		}

		return map;
	}

	function cloneState() {
		var out = {};
		for (var i = 0; i < ALL_CATEGORIES.length; i++) {
			out[ALL_CATEGORIES[i]] = !!state[ALL_CATEGORIES[i]];
		}
		return out;
	}

	function grantedList() {
		var out = [];
		for (var i = 0; i < ALL_CATEGORIES.length; i++) {
			if (state[ALL_CATEGORIES[i]]) { out.push(ALL_CATEGORIES[i]); }
		}
		return out;
	}

	/* ===================================================================
	 * SECTION 6 — Global Privacy Control.
	 *
	 * The server sets `D.gpc` from the `Sec-GPC` request header. The browser
	 * property is the same signal read locally, and it is the one that still
	 * works when the page came out of a full-page cache that was warmed by a
	 * request without the header.
	 *
	 * GPC is a sale/share opt-out: it revokes analytics and marketing and
	 * leaves functional alone, exactly as the PHP does.
	 * =================================================================== */

	var gpcFromBrowser = (true === navigator.globalPrivacyControl);
	var gpcActive = (true === D.gpc) || gpcFromBrowser;
	var gpcClientOnly = gpcFromBrowser && (true !== D.gpc);

	function applyGpc(map) {
		if (gpcActive) {
			map.analytics = false;
			map.marketing = false;
		}
		return map;
	}

	/* ===================================================================
	 * SECTION 7 — The relax tier (tier 3 of the geo ladder).
	 *
	 * Tier 1 is a CDN country header, tier 2 is a server-side lookup. When
	 * both are unavailable the server falls back to the STRICTEST posture,
	 * which is correct but shows a blocking consent wall to (for example)
	 * every visitor in Texas. This tier lets the browser's own IANA time
	 * zone rule that possibility out.
	 * =================================================================== */

	/**
	 * @return {boolean|null} true = might be in a strict region,
	 *                        false = conclusively outside the strict set,
	 *                        null  = unknown, make no inference.
	 */
	function clientCountryIsStrict() {
		var tz = '';
		try {
			tz = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
		} catch (e) {
			// No Intl, no resolvedOptions, or a browser that returns
			// undefined for timeZone. Unknown, never "safe to relax".
			return null;
		}
		if (!tz) { return null; }

		var region = tz.split('/')[0];

		// Only these zones can contain a strict-region visitor. Anything else
		// is conclusively outside the strict set, so we may relax.
		if (region === 'Europe' || region === 'Atlantic' || tz === 'America/Sao_Paulo' ||
			tz.indexOf('America/Argentina') === 0 || region === 'Arctic') {
			return true;
		}
		if (region === 'America' || region === 'Pacific' || region === 'Asia' ||
			region === 'Africa' || region === 'Australia' || region === 'Indian' || region === 'Antarctica') {
			return false;
		}
		return null; // unknown -> do not relax
	}

	/* ===================================================================
	 * SECTION 8 — Script and iframe activation.
	 * =================================================================== */

	/**
	 * Bring blocked tags back to life for the given categories.
	 *
	 * Scripts are REPLACED, never mutated. Rewriting `type` on a script the
	 * parser has already seen does not make the browser execute it — the
	 * "already started" flag is set on the element for its whole lifetime.
	 * Only a freshly constructed element that enters the document with an
	 * executable type will run. Mutating in place is the single most common
	 * way a consent runtime silently fails to fire any tags at all.
	 *
	 * @param {Array<string>} categories category slugs now granted.
	 */
	function activate(categories) {
		if (!categories || !categories.length) { return; }

		each(qsa('script[data-anchor-consent]'), function (el) {
			if (!inArray(el.getAttribute('data-anchor-consent'), categories)) { return; }
			if (!el.parentNode) { return; }

			var fresh = document.createElement('script');

			// Copy every attribute in source order, skipping our own markers
			// and the neutralized type. Source order is preserved because
			// el.attributes is itself in document order; some third-party
			// snippets (notably older GTM containers) read their own
			// attributes back off document.currentScript.
			for (var i = 0; i < el.attributes.length; i++) {
				var a = el.attributes[i];
				var n = a.name.toLowerCase();
				if (n === 'type' || n === 'data-anchor-consent' || n === 'data-anchor-src') { continue; }
				try {
					fresh.setAttribute(a.name, a.value);
				} catch (e) { /* an invalid attribute name must not abort the rest */ }
			}

			// A CSP nonce is hidden from getAttribute() by browsers that
			// implement nonce hiding; only the IDL attribute still carries it.
			// Without this, activation is blocked outright on CSP'd sites.
			if (el.nonce) {
				try { fresh.nonce = el.nonce; } catch (e) { /* ignore */ }
			}

			var src = el.getAttribute('data-anchor-src');
			if (src) {
				// Dynamically inserted scripts default to async, which would
				// reorder tags the page author wrote in a dependency order.
				// Only opt into async when the original actually asked for it.
				if (!el.hasAttribute('async')) {
					fresh.async = false;
				}
				fresh.src = src;
			} else {
				fresh.textContent = el.textContent;
			}

			el.parentNode.replaceChild(fresh, el);
		});

		each(qsa('iframe[data-anchor-consent]'), function (el) {
			if (!inArray(el.getAttribute('data-anchor-consent'), categories)) { return; }

			var src = el.getAttribute('data-anchor-src');
			// Guard against a second activation pass: once restored the
			// deferred attribute is gone, and assigning a null src would
			// navigate the frame to the literal string "null".
			if (!src) { return; }

			el.removeAttribute('data-anchor-src');
			el.src = src;
			el.style.display = '';
			el.removeAttribute('aria-hidden');
		});

		// Placeholders are matched by class, not tag name — Task 6 already
		// changed them from <div>/<p> to <span> once.
		each(qsa('.anchor-cmp-placeholder[data-anchor-consent]'), function (ph) {
			if (inArray(ph.getAttribute('data-anchor-consent'), categories)) {
				detach(ph);
			}
		});
	}

	/* ===================================================================
	 * SECTION 9 — Google Consent Mode v2.
	 * =================================================================== */

	function ensureGtag() {
		window.dataLayer = window.dataLayer || [];
		if (typeof window.gtag !== 'function') {
			// The server normally defines this alongside the denied defaults.
			// It can be missing when Consent Mode defaults were emitted after
			// this file (ordering), or stripped by an HTML optimizer. Pushing
			// onto dataLayer ourselves keeps the update from being lost.
			window.gtag = function () {
				window.dataLayer.push(arguments);
			};
		}
	}

	function pushConsentMode() {
		if (!D.consentMode) { return; }

		var payload = {};
		var found = false;
		for (var signal in SIGNAL_MAP) {
			if (!hasOwn(SIGNAL_MAP, signal)) { continue; }
			payload[signal] = state[SIGNAL_MAP[signal]] ? 'granted' : 'denied';
			found = true;
		}
		if (!found) { return; }

		ensureGtag();
		try {
			window.gtag('consent', 'update', payload);
		} catch (e) { /* never let a tag manager fault break the UI */ }
	}

	/* ===================================================================
	 * SECTION 10 — CallTrackingMetrics attribution replay.
	 * =================================================================== */

	function ctmGranted() {
		return !!(CTM && CTM.enabled && state[CTM.category || 'marketing']);
	}

	/**
	 * Persist the in-memory first-touch snapshot. Only ever called once
	 * consent covering CTM's category exists (or in an opt-out posture,
	 * where storage is lawful on first paint).
	 *
	 * An already-stored snapshot always wins: it was captured on an earlier
	 * page of this session and is therefore closer to the true first touch
	 * than whatever the current URL says.
	 */
	function persistFirstTouch() {
		if (!CTM || !CTM.enabled) { return null; }
		var store;
		try {
			store = window.sessionStorage;
			if (!store) { return null; }
		} catch (e) {
			// Safari private mode and cookie-blocked iframes throw on access.
			return null;
		}

		try {
			var existing = store.getItem(FIRST_TOUCH_KEY);
			if (existing) {
				return JSON.parse(existing);
			}
			store.setItem(FIRST_TOUCH_KEY, JSON.stringify(firstTouch));
		} catch (e) {
			return null;
		}
		return firstTouch;
	}

	/* ===================================================================
	 * SECTION 11 — REST audit record.
	 * =================================================================== */

	function uuidv4() {
		var c = window.crypto || window.msCrypto;

		if (c && typeof c.randomUUID === 'function') {
			try { return c.randomUUID(); } catch (e) { /* insecure context */ }
		}

		var bytes = new Array(16);
		var i;
		if (c && typeof c.getRandomValues === 'function' && window.Uint8Array) {
			var buf = new window.Uint8Array(16);
			c.getRandomValues(buf);
			for (i = 0; i < 16; i++) { bytes[i] = buf[i]; }
		} else {
			for (i = 0; i < 16; i++) { bytes[i] = Math.floor(Math.random() * 256); }
		}

		bytes[6] = (bytes[6] & 0x0f) | 0x40; // version 4
		bytes[8] = (bytes[8] & 0x3f) | 0x80; // variant 10xx

		var hex = [];
		for (i = 0; i < 16; i++) {
			hex.push((bytes[i] + 0x100).toString(16).substring(1));
		}
		return hex.slice(0, 4).join('') + '-' + hex.slice(4, 6).join('') + '-' +
			hex.slice(6, 8).join('') + '-' + hex.slice(8, 10).join('') + '-' +
			hex.slice(10, 16).join('');
	}

	/**
	 * Fire-and-forget. Task 8's endpoint is public (no nonce), returns 200
	 * for both `logged:true` and `logged:false`, and 400 for anything it
	 * rejects. A 400 is never worth retrying — the same body would fail the
	 * same validator — and a failure here must never gate the visitor's
	 * choice taking effect on the page.
	 */
	function postRecord(consentId, categories, method) {
		if (!D.restUrl) { return; }
		if (!inArray(method, VALID_METHODS)) { method = 'banner'; }

		var body;
		try {
			body = JSON.stringify({
				consent_id: consentId,
				categories: categories,
				method: method
			});
		} catch (e) {
			return;
		}

		try {
			if (window.fetch) {
				var p = window.fetch(D.restUrl, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: body,
					credentials: 'same-origin',
					keepalive: true
				});
				// Bracket access: `catch` is a reserved word as a bare
				// property name in ES3 parsers.
				if (p && p['catch']) { p['catch'](function () {}); }
			} else if (window.XMLHttpRequest) {
				var xhr = new window.XMLHttpRequest();
				xhr.open('POST', D.restUrl, true);
				xhr.setRequestHeader('Content-Type', 'application/json');
				xhr.onerror = function () {};
				xhr.send(body);
			}
		} catch (e) { /* offline, blocked by an extension, CSP connect-src */ }
	}

	/* ===================================================================
	 * SECTION 12 — Subscribers and the public change event.
	 * =================================================================== */

	var listeners = { change: [] };

	function notifyChange(consentId) {
		var subscribers = listeners.change.slice();
		for (var i = 0; i < subscribers.length; i++) {
			try {
				subscribers[i]({ categories: cloneState(), consentId: consentId });
			} catch (e) { /* one bad subscriber must not stop the others */ }
		}

		var detail = { categories: cloneState(), consentId: consentId };
		var evt;
		try {
			evt = new window.CustomEvent('anchor-consent-change', {
				bubbles: true,
				cancelable: false,
				detail: detail
			});
		} catch (e) {
			evt = document.createEvent('CustomEvent');
			evt.initCustomEvent('anchor-consent-change', true, false, detail);
		}
		document.dispatchEvent(evt);
	}

	/* ===================================================================
	 * SECTION 13 — DOM references and UI state.
	 * =================================================================== */

	var root = null;
	var banner = null;
	var prefs = null;
	var pill = null;
	var live = null;
	var pillInsideRoot = false;

	var lastFocused = null;
	var openDialog = null;   // the element currently trapping focus, if any
	var hasChoice = false;   // a consent cookie exists for this policy version
	var posture = 'strict';  // effective posture after the relax tier
	var relaxed = false;

	function cacheDom() {
		root = document.getElementById('anchor-cmp');
		banner = document.getElementById('anchor-cmp-banner');
		prefs = document.getElementById('anchor-cmp-prefs');
		live = document.getElementById('anchor-cmp-live');
		pill = qs('.anchor-cmp-pill');
		pillInsideRoot = !!(pill && root && root.contains && root.contains(pill));
	}

	function announce(message) {
		if (!live || !message) { return; }
		// Clearing first forces a re-announcement when the same string is
		// written twice (e.g. saving preferences without changing anything).
		live.textContent = '';
		window.setTimeout(function () {
			if (live) { live.textContent = message; }
		}, 50);
	}

	function showBanner() {
		if (!root) { return; }
		setHidden(root, false);
		setHidden(banner, false);
		setHidden(prefs, true);
		if (pill) { setHidden(pill, true); }
		if (root.classList) { root.classList.remove('anchor-cmp--pill-only'); }
	}

	function showPrefs() {
		if (!root) { return; }
		setHidden(root, false);
		setHidden(prefs, false);
		// The banner is hidden beneath rather than removed — Task 9 requires
		// #anchor-cmp-banner to stay in the DOM.
		setHidden(banner, true);
		if (pill) { setHidden(pill, true); }
		if (root.classList) { root.classList.remove('anchor-cmp--pill-only'); }
	}

	/**
	 * Close every panel. The root stays in the layout only when it is the
	 * pill's own parent, otherwise an empty full-viewport container could sit
	 * over the page swallowing clicks.
	 */
	function closePanels() {
		if (!root) { return; }
		setHidden(banner, true);
		setHidden(prefs, true);
		if (pill) { setHidden(pill, false); }

		var keepRoot = !!(pill && pillInsideRoot);
		setHidden(root, !keepRoot);
		if (root.classList) {
			if (keepRoot) {
				root.classList.add('anchor-cmp--pill-only');
			} else {
				root.classList.remove('anchor-cmp--pill-only');
			}
		}
	}

	/* --- Focus management ------------------------------------------- */

	var FOCUSABLE = 'a[href], area[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), ' +
		'select:not([disabled]), textarea:not([disabled]), summary, iframe, object, embed, ' +
		'[tabindex]:not([tabindex="-1"]), [contenteditable="true"]';

	function focusableWithin(container) {
		var all = qsa(FOCUSABLE, container);
		var out = [];
		each(all, function (el) {
			if (el.disabled) { return; }
			if (el.getAttribute('aria-hidden') === 'true') { return; }
			if (el.hasAttribute('hidden')) { return; }
			if (closest(el, '[hidden]')) { return; }
			// offsetParent is null for display:none subtrees (and for
			// position:fixed elements, hence the getClientRects fallback).
			if (el.offsetParent === null && (!el.getClientRects || !el.getClientRects().length)) { return; }
			out.push(el);
		});

		// If layout reports nothing visible at all — a zero-layout engine, a
		// print context, an element measured before first paint — fall back
		// to the unfiltered list. A trap that silently lets focus escape is a
		// worse failure than one that includes an off-screen control.
		if (!out.length) {
			each(all, function (el) {
				if (!el.disabled && !el.hasAttribute('hidden')) { out.push(el); }
			});
		}
		return out;
	}

	function openWithFocus(dialog, headingId) {
		if (!dialog) { return; }
		if (!openDialog) {
			// Only remember the pre-dialog focus on the FIRST open, so that
			// banner -> customize -> close returns to the page, not to a
			// button that is now hidden.
			lastFocused = document.activeElement;
		}
		openDialog = dialog;

		var heading = headingId ? document.getElementById(headingId) : null;
		var target = heading || dialog;
		try {
			if (!target.hasAttribute('tabindex')) {
				target.setAttribute('tabindex', '-1');
			}
			target.focus();
		} catch (e) { /* ignore */ }
	}

	function releaseFocus() {
		openDialog = null;
		if (lastFocused && lastFocused.focus && document.contains && document.contains(lastFocused)) {
			try { lastFocused.focus(); } catch (e) { /* ignore */ }
		}
		lastFocused = null;
	}

	function handleKeydown(e) {
		var key = e.key || '';
		var isEscape = (key === 'Escape' || key === 'Esc' || e.keyCode === 27);

		/**
		 * Opt-out posture, no focus trap active.
		 *
		 * This case has to be handled AHEAD of the `!openDialog` guard. In
		 * opt-out posture the banner is a notice, not a gate, so boot()
		 * deliberately does not call openWithFocus() — stealing the caret on
		 * every landing page would be worse for accessibility than leaving it
		 * alone. That means `openDialog` is null on a fresh load, and the
		 * trap-scoped Escape handling below is unreachable. Since the notice
		 * has no close button either, an opt-out visitor would have no way to
		 * dismiss it short of making a choice they were never required to make.
		 *
		 * Dismissing here writes NO cookie, fires NO REST call, and changes NO
		 * category — it only hides the notice for this pageview. It returns on
		 * the next load, exactly as an un-actioned notice should.
		 */
		if (isEscape && !openDialog && 'optout' === posture &&
			banner && !banner.hasAttribute('hidden')) {
			e.preventDefault();
			closePanels();
			return;
		}

		if (!openDialog) { return; }

		if (key === 'Tab' || e.keyCode === 9) {
			var items = focusableWithin(openDialog);
			if (!items.length) { return; }
			var first = items[0];
			var last = items[items.length - 1];
			var active = document.activeElement;
			var inside = !!(active && openDialog.contains && openDialog.contains(active));

			if (!inside) {
				// Focus escaped the dialog (browser chrome, an extension, a
				// programmatic focus() elsewhere). Pull it straight back.
				e.preventDefault();
				(e.shiftKey ? last : first).focus();
			} else if (e.shiftKey) {
				if (active === first || active === openDialog) {
					e.preventDefault();
					last.focus();
				}
			} else if (active === last) {
				e.preventDefault();
				first.focus();
			}
			return;
		}

		if (isEscape) {
			/**
			 * ESCAPE MUST NEVER GRANT CONSENT. This is deliberate, and it is
			 * the single easiest thing for a future maintainer to "fix" into
			 * a compliance bug, so it is spelled out:
			 *
			 *   - Strict posture, no choice yet: Escape is INERT. There is no
			 *     lawful default in a consent-required region, so dismissing
			 *     the dialog cannot stand in for either an accept or a
			 *     reject. The dialog stays; the visitor must choose.
			 *   - Opt-out posture: the banner is a notice, not a gate.
			 *     Escape dismisses it WITHOUT writing a cookie and without
			 *     changing a single category — nothing is granted, nothing is
			 *     recorded, and the notice returns on the next page load.
			 *   - A choice already exists (preference centre re-opened via
			 *     the pill): Escape is a plain cancel. It closes the panel
			 *     and leaves the stored choice exactly as it was.
			 *
			 * Under no branch does Escape call setConsent().
			 */
			if (openDialog === prefs && hasChoice) {
				e.preventDefault();
				closePanels();
				releaseFocus();
				return;
			}
			if (openDialog === prefs && !hasChoice) {
				// Cancel back to the banner, which is still an open question.
				e.preventDefault();
				showBanner();
				openWithFocus(banner, 'anchor-cmp-heading');
				return;
			}
			if ('optout' === posture) {
				e.preventDefault();
				closePanels();
				releaseFocus();
			}
			// Strict + no choice: intentionally no branch. Inert.
		}
	}

	/* ===================================================================
	 * SECTION 14 — Recording a choice.
	 * =================================================================== */

	function normalizeCategories(list) {
		var out = ['necessary'];
		if (Object.prototype.toString.call(list) === '[object Array]') {
			for (var i = 0; i < list.length; i++) {
				if (inArray(list[i], ALL_CATEGORIES) && !inArray(list[i], out)) {
					out.push(list[i]);
				}
			}
		}
		return out;
	}

	/**
	 * Apply and persist a consent decision.
	 *
	 * @param {Array<string>} categories categories the visitor granted.
	 * @param {string}        method     banner|preference_center|gpc|api.
	 * @param {object}        opts       {silent:bool, keepOpen:bool, cookie:bool}
	 */
	/**
	 * Re-entrancy guard. A double-click on Accept All used to run setConsent()
	 * twice, minting two distinct UUIDs and writing two rows to the consent
	 * log — Task 8's dedupe keys on `consent_id`, so it cannot collapse them.
	 * The window is deliberately time-based rather than a plain boolean that a
	 * later call clears: setConsent() is fully synchronous apart from the
	 * fire-and-forget POST, so a flag toggled at the end would be clear again
	 * before the second click of a double-click ever arrives.
	 *
	 * 700 ms comfortably covers a double-click (and an over-eager listener
	 * bound twice) without blocking a visitor who genuinely reopens the
	 * preference centre and saves a different choice.
	 */
	var lastConsentAt = 0;
	var lastConsentKey = '';
	var CONSENT_DEBOUNCE_MS = 700;

	function setConsent(categories, method, opts) {
		opts = opts || {};
		var chosen = normalizeCategories(categories);

		// Identical decision, same instant: collapse it to a single record.
		// A DIFFERENT decision inside the window is always honored — a visitor
		// who rejects and immediately accepts must get both applied.
		var key = method + ':' + chosen.join(',');
		var now = (new Date()).getTime();
		if (key === lastConsentKey && (now - lastConsentAt) < CONSENT_DEBOUNCE_MS) {
			// The DECISION is a duplicate; the CLICK is not. `accept-all` and
			// `reject-all` exist in BOTH the banner and the preference panel
			// under the same `method`, so the key collides across them and a
			// visitor can legitimately trigger the identical decision from a
			// second, still-open dialog.
			//
			// Suppress the RECORD, never the UI response. Returning before the
			// epilogue leaves the panel open with focus still trapped inside
			// it and nothing visibly happening — a dead click.
			if (!opts.keepOpen) {
				closePanels();
				releaseFocus();
			}
			return;
		}
		lastConsentKey = key;
		lastConsentAt = now;

		var consentId = uuidv4();

		// The cookie records what the visitor CHOSE; the live state is that
		// choice with GPC applied on top. This matches the PHP exactly:
		// Consent_State::categories() reads `cats` from the cookie and then
		// force-denies analytics/marketing when GPC is present.
		state = applyGpc(mapFromList(chosen, false));

		if (false !== opts.cookie) {
			hasChoice = writeConsentCookie(consentId, chosen) || hasChoice;
		}

		// Persist first-touch BEFORE activating, so the CTM tag finds the
		// snapshot already in sessionStorage the moment it boots.
		if (ctmGranted()) {
			persistFirstTouch();
		}

		activate(grantedList());
		sweepDeniedCookies();
		pushConsentMode();
		postRecord(consentId, chosen, method);
		notifyChange(consentId);
		refreshCheckboxes();
		syncObserver();

		if (!opts.keepOpen) {
			closePanels();
			releaseFocus();
		}
		if (!opts.silent) {
			announce(i18nText('saved_message', 'Your privacy preferences have been saved.'));
		}
	}

	function refreshCheckboxes() {
		each(qsa('[data-anchor-category]'), function (input) {
			var cat = input.getAttribute('data-anchor-category');
			if (!inArray(cat, ALL_CATEGORIES)) { return; }
			if ('necessary' === cat) { return; } // locked on, never touched
			input.checked = !!state[cat];
		});
	}

	function readCheckboxes() {
		var out = ['necessary'];
		each(qsa('#anchor-cmp-prefs [data-anchor-category]'), function (input) {
			var cat = input.getAttribute('data-anchor-category');
			if (!inArray(cat, ALL_CATEGORIES)) { return; }
			if (input.checked && !inArray(cat, out)) { out.push(cat); }
		});
		return out;
	}

	/* ===================================================================
	 * SECTION 15 — Client-side iframe guard (MutationObserver).
	 *
	 * WHY THIS EXISTS. The server-side blocker in Task 6 rewrites the HTML
	 * response, so it can only see markup that was in the response. Three
	 * sibling modules build their YouTube/Vimeo iframes in the browser
	 * instead and therefore bypass it entirely:
	 *
	 *   anchor-gallery/assets/anchor-video-slider.js:15
	 *   anchor-social-feed/assets/anchor-social-feed.js:12
	 *   anchor-universal-popups/assets/frontend.js:163
	 *
	 * Those files are NOT modified here. This observer neutralizes what they
	 * insert, using exactly the shape Task 6's PHP emits, so the same
	 * activation path and the same "Accept & Load" placeholder button work
	 * for both. The proper long-term fix is for those modules to call
	 * window.AnchorConsent.has() before building the frame and to subscribe
	 * to `anchor-consent-change`; this is the safety net until they do.
	 *
	 * KNOWN LIMITATION: MutationObserver callbacks are delivered as a
	 * microtask after the current task completes. An iframe that is created,
	 * given a src, and appended within a single tick may already have started
	 * its network request by the time we see it. Removing the src stops the
	 * frame rendering and stops all subsequent requests, but that first
	 * request can escape. Only a same-tick interception (which would mean
	 * patching createElement, i.e. exactly the fragile monkey-patching this
	 * avoids) could close it, and the sibling modules cooperating removes the
	 * need entirely.
	 *
	 * COST PROFILE: the observer is never created at all when every gated
	 * category is already granted (the common case for a consenting visitor
	 * and for every visitor in an opt-out region who has not opted out), and
	 * it disconnects the moment the last gated category is granted. While
	 * active it watches childList+subtree on <body> only — no attributes, no
	 * characterData, so text edits and class toggles produce no records. Per
	 * record it touches only `addedNodes`; per added element it does one
	 * tagName compare and, for containers, one getElementsByTagName('iframe')
	 * which is a live-collection lookup, not a selector parse. Per candidate
	 * iframe the work is at most `rules.length` (six by default) substring
	 * scans of the src.
	 * =================================================================== */

	/**
	 * FALLBACK ONLY — used when `D.iframeRules` is absent (a payload from an
	 * older build). The live list comes from the server registry; see
	 * iframeRules() below. Do not extend this array to add a service: add it
	 * to Anchor_Compliance_Service_Registry so the blocker and the observer
	 * stay in agreement.
	 */
	var DEFAULT_IFRAME_RULES = [
		{ pattern: 'youtube.com/embed', category: 'marketing' },
		{ pattern: 'youtube-nocookie.com/embed', category: 'marketing' },
		{ pattern: 'youtube.com/iframe_api', category: 'marketing' },
		{ pattern: 'youtube.com/watch', category: 'marketing' },
		{ pattern: 'youtu.be/', category: 'marketing' },
		{ pattern: 'player.vimeo.com', category: 'marketing' },
		{ pattern: 'vimeo.com/api', category: 'marketing' },
		// Trailing slash is load-bearing: bare `vimeo.com/video` also matches
		// api.vimeo.com/videoS/ and vimeo.com/videoSCHOOL, and the same
		// patterns are matched against inline SCRIPT BODIES by the server-side
		// blocker — so the bare form neutralized any inline script that merely
		// mentioned the Vimeo REST API (anchor-webinars builds exactly such a
		// URL). Mirrors Anchor_Compliance_Service_Registry; keep them in step.
		{ pattern: 'vimeo.com/video/', category: 'marketing' }
	];

	function isArray(v) {
		return Object.prototype.toString.call(v) === '[object Array]';
	}

	/**
	 * The gating rules the observer matches an iframe src against.
	 *
	 * `D.iframeRules` is authoritative: it is the server's own
	 * Anchor_Compliance_Service_Registry::active_rules(), reduced to
	 * pattern+category, so it already reflects every admin override, custom
	 * rule and re-categorisation. When it is present the built-in list is not
	 * consulted at all — a second, hardcoded copy of the same knowledge is
	 * exactly the drift this key exists to remove.
	 *
	 * DEFAULT_IFRAME_RULES survives only as a fallback for a payload built by
	 * an older Anchor_Compliance_Banner that predates the key. An EMPTY array
	 * from the server is honored as "nothing is gated", not treated as absent
	 * — that is a legitimate configuration (every service disabled, or all of
	 * them governed by Consent Mode).
	 *
	 * `window.AnchorComplianceIframeRules` is always appended on top, so a
	 * theme or sibling module can register an embed host the registry does not
	 * know about without waiting on a plugin release.
	 */
	function iframeRules() {
		var rules = [];
		var i, r;

		if (isArray(D.iframeRules)) {
			for (i = 0; i < D.iframeRules.length; i++) {
				r = D.iframeRules[i];
				if (r && r.pattern && inArray(r.category, ALL_CATEGORIES)) {
					rules.push({ pattern: String(r.pattern), category: r.category });
				}
			}
		} else {
			rules = DEFAULT_IFRAME_RULES.slice();
		}

		if (isArray(window.AnchorComplianceIframeRules)) {
			for (i = 0; i < window.AnchorComplianceIframeRules.length; i++) {
				r = window.AnchorComplianceIframeRules[i];
				if (r && r.pattern && inArray(r.category, ALL_CATEGORIES)) {
					rules.push({ pattern: String(r.pattern), category: r.category });
				}
			}
		}

		return rules;
	}

	var RULES = [];
	var observer = null;

	function gatedCategories() {
		var out = [];
		for (var i = 0; i < RULES.length; i++) {
			if (!state[RULES[i].category] && !inArray(RULES[i].category, out)) {
				out.push(RULES[i].category);
			}
		}
		return out;
	}

	function ruleForSrc(src) {
		if (!src) { return null; }
		var lower = src.toLowerCase();
		for (var i = 0; i < RULES.length; i++) {
			if (lower.indexOf(RULES[i].pattern.toLowerCase()) !== -1) { return RULES[i]; }
		}
		return null;
	}

	/** Reuse the server's own translated placeholder copy when it is on the page. */
	function placeholderCopy() {
		var text = qs('.anchor-cmp-placeholder__text');
		var btn = qs('.anchor-cmp-placeholder__btn');
		return {
			// A server-rendered placeholder already on the page wins: its text
			// went through the PHP translation layer for this exact request.
			// Its textContent is already entity-decoded by the HTML parser.
			text: (text && text.textContent) ? text.textContent : i18nText('placeholder_text', 'This content is blocked until you accept the related cookies.'),
			button: (btn && btn.textContent) ? btn.textContent : i18nText('placeholder_button', 'Accept & Load')
		};
	}

	/** Neutralize one client-built iframe into Task 6's exact blocked shape. */
	function neutralizeIframe(frame) {
		if (!frame || frame.getAttribute('data-anchor-consent')) { return; }

		var src = frame.getAttribute('src');
		if (!src || src === 'about:blank') { return; }

		var rule = ruleForSrc(src);
		if (!rule || state[rule.category]) { return; }

		frame.setAttribute('data-anchor-consent', rule.category);
		frame.setAttribute('data-anchor-src', src);
		frame.removeAttribute('src');
		frame.style.display = 'none';
		frame.setAttribute('aria-hidden', 'true');

		if (!frame.parentNode) { return; }

		var copy = placeholderCopy();
		var wrap = document.createElement('span');
		wrap.className = 'anchor-cmp-placeholder';
		wrap.style.display = 'block';
		wrap.setAttribute('data-anchor-consent', rule.category);

		var label = document.createElement('span');
		label.className = 'anchor-cmp-placeholder__text';
		label.style.display = 'block';
		label.textContent = copy.text;

		var button = document.createElement('button');
		button.type = 'button';
		button.className = 'anchor-cmp-placeholder__btn';
		button.setAttribute('data-anchor-accept', rule.category);
		button.textContent = copy.button;

		wrap.appendChild(label);
		wrap.appendChild(button);
		frame.parentNode.insertBefore(wrap, frame);
	}

	function scanForIframes(node) {
		if (!node || node.nodeType !== 1) { return; }
		if (node.tagName && 'IFRAME' === node.tagName.toUpperCase()) {
			neutralizeIframe(node);
			return;
		}
		if (!node.getElementsByTagName) { return; }
		var frames = node.getElementsByTagName('iframe');
		// Live HTMLCollection: snapshot the length, and walk backwards so
		// inserting a placeholder sibling cannot shift the cursor.
		for (var i = frames.length - 1; i >= 0; i--) {
			neutralizeIframe(frames[i]);
		}
	}

	function onMutations(records) {
		for (var i = 0; i < records.length; i++) {
			var added = records[i].addedNodes;
			if (!added || !added.length) { continue; }
			for (var j = 0; j < added.length; j++) {
				scanForIframes(added[j]);
			}
		}
	}

	/** Start when something is gated, stop as soon as nothing is. */
	function syncObserver() {
		var gated = gatedCategories().length > 0;

		if (!gated) {
			if (observer) {
				observer.disconnect();
				observer = null;
			}
			return;
		}
		if (observer || !window.MutationObserver || !document.body) { return; }

		observer = new window.MutationObserver(onMutations);
		observer.observe(document.body, { childList: true, subtree: true });

		// One-time sweep for anything a faster script already inserted.
		scanForIframes(document.body);
	}

	/* ===================================================================
	 * SECTION 16 — UI wiring.
	 * =================================================================== */

	function onClick(e) {
		if (e.defaultPrevented || (e.button && e.button !== 0)) { return; }

		var target = closest(e.target, '[data-anchor-action],[data-anchor-accept]');
		if (!target) { return; }

		var accept = target.getAttribute('data-anchor-accept');
		if (accept) {
			e.preventDefault();
			if (!inArray(accept, ALL_CATEGORIES)) { return; }
			// Grant only the category this embed needs; every other choice
			// the visitor already made is carried through unchanged.
			var next = grantedList();
			if (!inArray(accept, next)) { next.push(accept); }
			setConsent(next, 'preference_center', { silent: true });
			// Its own string, not saved_message: this action unblocks one
			// embed, it does not save a set of preferences.
			announce(i18nText('unblocked_message', 'Content unblocked.'));
			return;
		}

		var action = target.getAttribute('data-anchor-action');
		if (!action) { return; }
		e.preventDefault();

		switch (action) {
			case 'accept-all':
				setConsent(ALL_CATEGORIES.slice(), 'banner');
				break;

			case 'reject-all':
				setConsent(['necessary'], 'banner');
				break;

			case 'save-preferences':
				setConsent(readCheckboxes(), 'preference_center');
				break;

			case 'customize':
			case 'open-preferences':
				refreshCheckboxes();
				showPrefs();
				openWithFocus(prefs, 'anchor-cmp-prefs-heading');
				break;

			case 'do-not-sell':
				// A CCPA/CPRA sale-or-share opt-out: analytics and marketing
				// off, functional left alone — the same shape GPC produces.
				setConsent(['necessary', 'functional'], 'preference_center', { silent: true });
				announce(i18nText('dns_confirmation', 'You have opted out of the sale or sharing of your personal information.'));
				break;

			case 'close':
				// A cancel, never a decision. No cookie, no category change.
				if (hasChoice || 'optout' === posture) {
					closePanels();
					releaseFocus();
				} else {
					showBanner();
					openWithFocus(banner, 'anchor-cmp-heading');
				}
				break;

			default:
				break;
		}
	}

	/**
	 * Swap the banner's blocking copy for the opt-out notice copy.
	 *
	 * Used only by the relax tier. The server rendered strict copy because
	 * the server believed the visitor was in a strict region; the payload
	 * carries the opt-out strings too, so the swap needs no round trip.
	 * Every lookup is defensive — if the markup does not expose a body
	 * element we simply keep the strict copy, which reads correctly either
	 * way and is never a compliance problem.
	 */
	function applyNoticeCopy() {
		if (!banner) { return; }
		if (root && root.classList) { root.classList.add('anchor-cmp--notice'); }

		// The notice is informational, not a gate; announcing it as a modal
		// would trap screen-reader users in something they need not answer.
		banner.setAttribute('aria-modal', 'false');

		var body = qs('.anchor-cmp-body, .anchor-cmp-text, .anchor-cmp-copy', banner);
		if (body && I18N.notice_body) {
			// Payload i18n strings are wp_kses_post()-sanitized server-side
			// (Task 9) and are therefore trusted markup, not plain text.
			body.innerHTML = I18N.notice_body;
		}

		var reject = qs('[data-anchor-action="reject-all"]', banner);
		if (reject && I18N.dns_label) {
			reject.innerHTML = I18N.dns_label;
		}
	}

	/* ===================================================================
	 * SECTION 17 — Public API.
	 * =================================================================== */

	var api = {
		/** @return {Object<string,boolean>} a copy; mutating it does nothing. */
		get: function () {
			return cloneState();
		},

		/** @return {boolean} */
		has: function (category) {
			return !!state[category];
		},

		on: function (event, fn) {
			if ('change' === event && typeof fn === 'function' && !inArray(fn, listeners.change)) {
				listeners.change.push(fn);
			}
			return api;
		},

		off: function (event, fn) {
			if ('change' !== event) { return api; }
			for (var i = listeners.change.length - 1; i >= 0; i--) {
				if (listeners.change[i] === fn) { listeners.change.splice(i, 1); }
			}
			return api;
		},

		/** Omit `categories` to grant everything. */
		accept: function (categories) {
			var list = (Object.prototype.toString.call(categories) === '[object Array]')
				? categories
				: ALL_CATEGORIES.slice();
			setConsent(list, 'api');
		},

		reject: function () {
			setConsent(['necessary'], 'api');
		},

		openPreferences: function () {
			refreshCheckboxes();
			showPrefs();
			openWithFocus(prefs, 'anchor-cmp-prefs-heading');
		},

		/**
		 * The first-touch snapshot, for CallTrackingMetrics and anything else
		 * that needs the original referrer/landing URL. Returns the stored
		 * session snapshot when one exists, otherwise this page's in-memory
		 * capture. Never writes.
		 */
		firstTouch: function () {
			try {
				var stored = window.sessionStorage && window.sessionStorage.getItem(FIRST_TOUCH_KEY);
				if (stored) { return JSON.parse(stored); }
			} catch (e) { /* ignore */ }
			return {
				referrer: firstTouch.referrer,
				landing: firstTouch.landing,
				query: firstTouch.query
			};
		},

		/** Re-scan the DOM for blocked tags. Cheap, idempotent, safe to call. */
		refresh: function () {
			activate(grantedList());
			syncObserver();
		},

		version: 1
	};

	window.AnchorConsent = api;

	/* ===================================================================
	 * SECTION 18 — Boot.
	 * =================================================================== */

	function resolveInitialState() {
		var stored = readStoredConsent();
		hasChoice = (null !== stored);
		posture = ('optout' === D.posture) ? 'optout' : 'strict';

		if (hasChoice) {
			state = applyGpc(mapFromList(stored.cats, false));
			return;
		}

		if ('optout' === posture) {
			state = applyGpc(mapFromList(null, true));
			return;
		}

		// --- Strict, with no stored choice. Consider the relax tier. -----
		//
		// GUARD: clientCountryIsStrict() is consulted here and NOWHERE else.
		// It can only ever move strict -> optout. It is never reachable when
		// D.posture is already 'optout', because a false negative there would
		// TIGHTEN nothing but a false positive would be meaningless, while
		// the reverse mistake — relaxing a genuinely strict visitor — is the
		// only one that creates a compliance failure. Constraining the call
		// site to this single branch makes that direction structural rather
		// than a matter of reading the function body correctly.
		//
		// It is also skipped when GPC is present: a visitor who has asked not
		// to be tracked gets no provisional grant regardless of geography.
		if (D.allowClientRelax && !gpcActive && null === stored) {
			if (false === clientCountryIsStrict()) {
				relaxed = true;
				posture = 'optout';
				// Provisional only. NO cookie is written — the visitor has
				// made no choice, and a cookie would suppress the notice on
				// every subsequent page.
				state = applyGpc(mapFromList(null, true));
				return;
			}
		}

		state = applyGpc(blankState());
	}

	/**
	 * Everything that must not wait for DOMContentLoaded.
	 *
	 * This file is enqueued in the footer, so every blocked tag above it has
	 * already been parsed and can be activated now. Deferring activation to
	 * DOMContentLoaded would delay every analytics and marketing tag on the
	 * page by however long the remaining images and stylesheets take.
	 */
	function earlyBoot() {
		RULES = iframeRules();

		if (ctmGranted() || 'optout' === posture) {
			// Opt-out posture (including a relaxed one): storage is lawful on
			// first paint, so first-touch is captured before any navigation.
			persistFirstTouch();
		}

		// Anything already granted runs immediately. This also repairs a
		// full-page-cache mismatch, where the HTML was generated for a
		// visitor whose consent differed from this one's.
		activate(grantedList());

		if (D.consentMode && (hasChoice || relaxed || gpcClientOnly || 'optout' === posture)) {
			pushConsentMode();
		}

		syncObserver();

		// Clicks are bound to the document, not to #anchor-cmp, because the
		// [anchor_consent_link] / [anchor_do_not_sell] shortcodes and the
		// iframe placeholders all render outside the banner root.
		document.addEventListener('click', onClick, false);
		document.addEventListener('keydown', handleKeydown, false);
	}

	function boot() {
		cacheDom();

		// Re-run: markup below this script in the source order (or inserted
		// by another footer script) is only reachable once the DOM is ready.
		activate(grantedList());
		syncObserver();

		if (!root) {
			// The module can be enabled with the banner suppressed, or
			// general.enabled can be false. The API, activation and the
			// observer all still work; there is just no UI to wire.
			return;
		}

		refreshCheckboxes();

		/* --- GPC, client-side only ----------------------------------- */
		if (gpcClientOnly) {
			showGpcNotice();

			var revoking = hasChoice && storedGrantedTracking();
			if (revoking) {
				// A stored choice granted analytics or marketing and the
				// browser is now signalling GPC. That is a genuine change of
				// state, so it is written, swept and recorded.
				//
				// GPC revokes ONLY analytics and marketing. Whatever the
				// visitor decided about `functional` is carried through
				// untouched — force-granting it here would turn a withdrawal
				// signal into a grant the visitor never gave.
				var keep = ['necessary'];
				if (state.functional) { keep.push('functional'); }
				setConsent(keep, 'gpc', { silent: true });
				announce(i18nText('gpc_message', 'Your Global Privacy Control signal has been honored.'));
			} else if ('optout' === posture && !hasChoice) {
				// A definitive opt-out for this visitor. Recording it stops
				// the notice reappearing on every page.
				setConsent(['necessary', 'functional'], 'gpc', { silent: true });
				announce(i18nText('gpc_message', 'Your Global Privacy Control signal has been honored.'));
			} else {
				// Strict posture with nothing stored: analytics and marketing
				// are already denied by default, so there is nothing to
				// change and nothing worth logging once per pageview. The
				// banner still shows, because functional consent is still an
				// open question.
				announce(i18nText('gpc_message', 'Your Global Privacy Control signal has been honored.'));
			}
		}

		if (hasChoice) {
			closePanels();
			return;
		}

		if (relaxed) {
			applyNoticeCopy();
		}

		showBanner();

		if ('strict' === posture) {
			// A consent-required region: the banner is a genuine modal, so
			// focus moves into it and Tab is trapped.
			openWithFocus(banner, 'anchor-cmp-heading');
		}
		// Opt-out posture deliberately does NOT steal focus. The notice is
		// informational; hijacking the caret on every landing page would be
		// worse for accessibility than leaving it where the visitor put it.
	}

	/**
	 * Reveal — or, when the server never emitted one, BUILD — the honored-GPC
	 * notice inside the banner.
	 *
	 * The server renders `<p class="anchor-cmp-gpc-notice">` only when it saw
	 * a `Sec-GPC` header on that request. That is precisely the condition
	 * under which `gpcClientOnly` is FALSE, so on the client-only path (a
	 * cached page, or a browser signalling GPC on a request the origin never
	 * saw) the element does not exist and a lookup finds nothing. Without this
	 * the visitor gets a screen-reader announcement and no visible notice.
	 *
	 * It is built here rather than always rendered server-side on purpose:
	 * the server's markup should keep asserting only what the server actually
	 * detected. Creating it client-side keeps the two honest and independent.
	 */
	function showGpcNotice() {
		if (!banner) { return; }

		var notice = qs('.anchor-cmp-gpc-notice', root);
		if (notice) {
			setHidden(notice, false);
			return;
		}

		notice = document.createElement('p');
		notice.className = 'anchor-cmp-gpc-notice';
		// textContent, mirroring the server's esc_html() — this string is a
		// status message, never markup.
		notice.textContent = i18nText('gpc_message', 'Your Global Privacy Control signal has been honored.');

		// Same slot the server uses: after the body copy, before the actions.
		var body = qs('.anchor-cmp-body', banner);
		var actions = qs('.anchor-cmp-actions', banner);
		if (body && body.parentNode) {
			body.parentNode.insertBefore(notice, body.nextSibling);
		} else if (actions && actions.parentNode) {
			actions.parentNode.insertBefore(notice, actions);
		} else {
			banner.appendChild(notice);
		}
	}

	/** Did the stored choice grant anything GPC revokes? */
	function storedGrantedTracking() {
		var stored = readStoredConsent();
		if (!stored) { return false; }
		return inArray('analytics', stored.cats) || inArray('marketing', stored.cats);
	}

	resolveInitialState();
	earlyBoot();

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot, false);
	} else {
		boot();
	}
})();
