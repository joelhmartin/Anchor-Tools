<?php
/**
 * Anchor Compliance — output-buffer script blocker.
 *
 * Regex, not DOMDocument: DOMDocument reformats and repairs partial or
 * non-conforming HTML, which corrupts real-world theme output. We only need to
 * rewrite two attributes on two tag types, which regex does safely.
 *
 * Safety contract: every regex pass below is treated as fallible. PCRE can
 * return NULL (backtrack/recursion limit, bad UTF-8) on pathological input —
 * an unclosed <script on a large page is enough. When that happens this class
 * must fail OPEN: return the original, unmodified markup rather than an
 * empty or partially-rewritten page. An unblocked tracker is a compliance
 * problem; a blanked page is an outage, and outages are worse. See
 * pcre_ok() / fail_open().
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Anchor_Compliance_Script_Blocker {

	private $registry;
	private $state;
	private $geo;
	private $blocked = 0;

	/** MIME/type values that mean "this script executes as JavaScript." Anything else (application/ld+json, text/template, ...) is inert markup, not code, and must never be touched. */
	const EXECUTABLE_TYPES = [ 'text/javascript', 'application/javascript', 'module' ];

	public function __construct( $registry, $state, $geo ) {
		$this->registry = $registry;
		$this->state    = $state;
		$this->geo      = $geo;
	}

	public function blocked_count() {
		return $this->blocked;
	}

	/**
	 * Contexts where rewriting is wrong or pointless. Note that Anchor
	 * Translate's internal page fetch is deliberately NOT excluded: that
	 * subrequest carries no cookies, so it renders the conservative blocked
	 * variant and the front-end runtime relaxes it for the real visitor.
	 */
	public function should_run() {
		$opts = Anchor_Compliance_Settings::get();
		$run  = true;

		if ( empty( $opts['general']['enabled'] ) || empty( $opts['advanced']['buffer_enabled'] ) ) {
			$run = false;
		} elseif ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			$run = false;
		} elseif ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			$run = false;
		} elseif ( defined( 'WP_CLI' ) && WP_CLI ) {
			$run = false;
		} elseif ( is_feed() || is_embed() || is_preview() || is_customize_preview() ) {
			$run = false;
		} elseif ( '' !== (string) get_query_var( 'sitemap', '' ) ) {
			// WP core XML sitemaps register 'sitemap' as a public query var
			// (see WP_Sitemaps::register_query_vars()), so it is populated
			// from the query string regardless of permalink structure —
			// checking $_GET directly missed the pretty-permalink case
			// entirely (the value never appears in $_GET there; it only
			// exists after WP's rewrite parsing populates the query var).
			$run = false;
		}

		/**
		 * Final override for whether the buffer should start, applied to the
		 * fully-resolved decision rather than exposed as one filter per
		 * internal condition. A filter promising to answer "is this a REST
		 * request?" that actually just disables blocking would be
		 * misleading; this is the single, honestly-named place to override
		 * should_run() for any reason — including from a test (safely, with
		 * no process-global constant involved) or from a site that wants
		 * blocking suspended for some case this class doesn't otherwise
		 * know about (e.g. during a Customizer preview).
		 */
		return (bool) apply_filters( 'anchor_compliance_should_run', $run );
	}

	public function maybe_start_buffer() {
		if ( ! $this->should_run() ) {
			return;
		}
		ob_start( [ $this, 'rewrite' ] );
	}

	/** @return array<string,bool> Which categories are currently allowed. */
	private function allowed() {
		return $this->state->categories( ! $this->geo->is_strict() );
	}

	/**
	 * The output-buffer callback.
	 *
	 * Re-entrancy: ob_start() callbacks can be invoked more than once per
	 * request if something upstream (a theme, a minifier, PHP's own chunked
	 * output) flushes the buffer mid-render. This method is idempotent
	 * against its own prior output: a tag it has already neutralized never
	 * matches these patterns again (see the per-pass docblocks below for
	 * why), so re-entry can at worst miss a tag split across a flush
	 * boundary — it can never re-block or double-wrap one.
	 *
	 * @param string $html
	 * @return string
	 */
	public function rewrite( $html ) {
		$this->blocked = 0; // Reset unconditionally so blocked_count() is never stale after an early exit.

		$opts = Anchor_Compliance_Settings::get();
		if ( empty( $opts['general']['enabled'] ) || empty( $opts['advanced']['buffer_enabled'] ) ) {
			return $html;
		}
		if ( '' === trim( (string) $html ) ) {
			return $html;
		}
		// Only touch documents that actually look like HTML.
		if ( ! preg_match( '/<(?:html|head|body|script|iframe)\b/i', $html ) ) {
			return $html;
		}

		$rules = $this->registry->active_rules();
		if ( ! $rules ) {
			return $html;
		}

		$allowed = $this->allowed();
		// Nothing to do when every gated category is already granted.
		$any_denied = false;
		foreach ( $rules as $rule ) {
			if ( empty( $allowed[ $rule['category'] ] ) ) {
				$any_denied = true;
				break;
			}
		}
		if ( ! $any_denied ) {
			return $html;
		}

		$original = $html;

		// Hide the body of non-executable <script> blocks (JSON-LD, HTML
		// templates, ...) from the src/iframe passes below, so a URL that
		// merely APPEARS inside inert text/markup (a JSON string value, a
		// client-side template fragment) is never mistaken for a live tag.
		// See mask_inert_scripts() for why this exists and what it does and
		// does not touch.
		$masked = [];
		if ( false !== stripos( $original, '<script' ) && preg_match( '#<script\b[^>]*\btype\s*=#i', $original ) ) {
			$stage = $this->mask_inert_scripts( $original, $masked );
			if ( ! $this->pcre_ok( $stage ) ) {
				return $this->fail_open( $original, 'mask_inert_scripts' );
			}
			$html = $stage;
		}

		$stage = $this->rewrite_src_tags( $html, 'script', $rules, $allowed );
		if ( ! $this->pcre_ok( $stage ) ) {
			return $this->fail_open( $original, 'rewrite_src_tags(script)' );
		}
		$html = $stage;

		$stage = $this->rewrite_inline_scripts( $html, $rules, $allowed );
		if ( ! $this->pcre_ok( $stage ) ) {
			return $this->fail_open( $original, 'rewrite_inline_scripts' );
		}
		$html = $stage;

		$stage = $this->rewrite_src_tags( $html, 'iframe', $rules, $allowed );
		if ( ! $this->pcre_ok( $stage ) ) {
			return $this->fail_open( $original, 'rewrite_src_tags(iframe)' );
		}
		$html = $stage;

		if ( $masked ) {
			$html = strtr( $html, $masked );
		}

		if ( ! empty( $opts['advanced']['debug'] ) && $this->blocked > 0 && class_exists( 'Anchor_Schema_Logger' ) ) {
			Anchor_Schema_Logger::log( sprintf( '[compliance] blocked %d tag(s) on %s', $this->blocked, esc_url_raw( home_url( add_query_arg( [] ) ) ) ) );
		}

		return $html;
	}

	/**
	 * True when a preg_replace_callback() result is trustworthy. PCRE
	 * returns NULL (with preg_last_error() set) on a backtrack-limit,
	 * recursion-limit, or malformed-UTF-8 failure — silently, with no
	 * exception or warning by default. Callers MUST check this before using
	 * a stage's output; the alternative (not checking) is a NULL flowing
	 * into the next preg_* call, which PHP 8 coerces to '', so a single
	 * failed pass on a large or malformed page silently blanks the entire
	 * response.
	 */
	private function pcre_ok( $result ) {
		return null !== $result && PREG_NO_ERROR === preg_last_error();
	}

	/** Fail open: serve the pristine, pre-rewrite markup and log why. */
	private function fail_open( $original, $stage ) {
		$this->blocked = 0;
		if ( class_exists( 'Anchor_Schema_Logger' ) ) {
			Anchor_Schema_Logger::log( sprintf(
				'[compliance] PCRE failure in %s (%s) — serving original markup unmodified.',
				$stage,
				$this->pcre_error_name()
			) );
		}
		return $original;
	}

	private function pcre_error_name() {
		$names = [
			PREG_NO_ERROR              => 'PREG_NO_ERROR',
			PREG_INTERNAL_ERROR        => 'PREG_INTERNAL_ERROR',
			PREG_BACKTRACK_LIMIT_ERROR => 'PREG_BACKTRACK_LIMIT_ERROR',
			PREG_RECURSION_LIMIT_ERROR => 'PREG_RECURSION_LIMIT_ERROR',
			PREG_BAD_UTF8_ERROR        => 'PREG_BAD_UTF8_ERROR',
			PREG_BAD_UTF8_OFFSET_ERROR => 'PREG_BAD_UTF8_OFFSET_ERROR',
		];
		$code = preg_last_error();
		return $names[ $code ] ?? ( 'PCRE error code ' . $code );
	}

	/**
	 * The gating category for a URL/body, matched against an already-fetched
	 * rules list rather than calling Anchor_Compliance_Service_Registry::
	 * category_for_url(), which would re-run active_rules() (and its several
	 * Anchor_Compliance_Settings::get() calls) once per matched tag on the
	 * page. rewrite() already has the rules for this request; every tag reuses
	 * that same array. Matching logic (case-insensitive substring) mirrors
	 * category_for_url() exactly.
	 *
	 * @param string $haystack URL (src tags) or script body (inline scripts).
	 * @param array  $rules    Result of registry->active_rules(), fetched once.
	 * @return string|null
	 */
	private function category_for( $haystack, array $rules ) {
		$haystack = (string) $haystack;
		if ( '' === $haystack ) {
			return null;
		}
		foreach ( $rules as $rule ) {
			if ( '' !== $rule['pattern'] && false !== stripos( $haystack, $rule['pattern'] ) ) {
				return $rule['category'];
			}
		}
		return null;
	}

	/**
	 * Hide the body of <script> blocks that do not execute as JavaScript
	 * (application/ld+json, text/template, and similar) behind an opaque
	 * token before the src/iframe passes run, then restore them verbatim
	 * afterward (rewrite() does the restore via strtr()).
	 *
	 * Why this exists: rewrite_src_tags('iframe', ...) and
	 * rewrite_src_tags('script', ...) scan the WHOLE document as flat text —
	 * they have no notion of "this text is sitting inside another script's
	 * inert body." Anchor Tools' own flagship feature emits
	 * <script type="application/ld+json"> JSON-LD on nearly every page
	 * (class-anchor-schema-render.php, the events/locations modules, ...);
	 * a LocalBusiness record's URL fields can trivially contain a substring
	 * that matches a site's own custom_rules pattern. A client-side
	 * templating library's <script type="text/template"> can likewise
	 * contain a literal, inert "<iframe src=...>" string that was never
	 * meant to render as-is. Without this pass, either would get "blocked"
	 * — corrupting JSON-LD structured data for zero compliance benefit, or
	 * wrapping template markup in a live placeholder it was never meant to
	 * have.
	 *
	 * A <script> with a genuine src is left alone here (untouched, not
	 * masked) — the src-tag pass needs to see its opening tag intact. A
	 * <script> with no type attribute, or an executable one (see
	 * EXECUTABLE_TYPES), is also left alone — its body is real code that
	 * rewrite_inline_scripts() still needs to inspect for e.g. an inline
	 * fbq() bootstrap.
	 *
	 * Pattern: identical shape to rewrite_inline_scripts()'s own tag match
	 * (`<script\b([^>]*)>(.*?)</script>`, flags i s) — deliberately not
	 * reusing that method, because this one runs BEFORE the src/iframe
	 * passes and must mask before they ever see the document, not filter
	 * per-match the way rewrite_inline_scripts() does for itself.
	 *
	 * A note on what this does NOT protect against: the pattern above
	 * requires a literal closing "</script>". A genuinely unclosed
	 * <script type="text/template"> (malformed markup — a truncated
	 * response, a template fatal) is therefore never masked at all, and its
	 * body IS still visible, as flat text, to the src/iframe passes that
	 * follow — those passes can and will "block" a URL or an
	 * "<iframe src=...>" string that happens to sit inside it. That is a
	 * real content-corruption case for malformed input, not a harmless miss;
	 * it is not claimed to be safe anywhere in this class.
	 *
	 * The restore token embeds a fresh, unpredictable per-call nonce
	 * (`random_bytes()`), not just a fixed literal plus a restarting
	 * counter. Restoration is strtr(), which replaces EVERY occurrence of a
	 * token string in the document — including one that this method never
	 * produced. 0x01 is valid UTF-8 and passes esc_attr()/esc_html()/
	 * sanitize_text_field() untouched, so with a predictable token (e.g. a
	 * bare "ANCHOR_CMP_MASK_0" counter) an attacker who can get arbitrary
	 * text reflected onto the page (a search query, a comment, any echoed
	 * input) could plant the exact token bytes themselves. Since this plugin
	 * always emits JSON-LD, the masking path is always live, and strtr()
	 * would then splice a real, unrelated masked script into the attacker's
	 * chosen spot — including breaking out of an attribute value, e.g. a
	 * `<script type="application/ld+json">` ending up inside
	 * `value="..."`. The nonce makes the token unpredictable per request, so
	 * it cannot be pre-planted.
	 */
	private function mask_inert_scripts( $html, array &$masked ) {
		$masked = [];
		$i      = 0;

		// random_bytes() can throw (Exception on PHP 8.1, \Random\RandomException
		// on 8.2+ — both are \Throwable) if the platform's CSPRNG is
		// unavailable. This runs inside an ob_start() callback: an uncaught
		// throw here would discard the entire buffered page rather than
		// just this one pass, which is exactly the outage this class's
		// fail-open contract exists to prevent (see the class docblock).
		// wp_generate_password() is WP-native, always available, and — since
		// its only job here is to keep the token unpredictable, not to be a
		// cryptographic secret — a perfectly adequate fallback.
		try {
			$nonce = bin2hex( random_bytes( 8 ) );
		} catch ( \Throwable $e ) {
			$nonce = wp_generate_password( 16, false, false );
		}

		return preg_replace_callback(
			'#<script\b([^>]*)>(.*?)</script>#is',
			function ( $m ) use ( &$masked, &$i, $nonce ) {
				$attrs = $m[1];

				if ( preg_match( '#(?<![\w:.-])src\s*=#i', $attrs ) ) {
					return $m[0]; // has a real src — the src-tag pass needs this intact.
				}
				if ( $this->is_executable_type( $this->type_value( $attrs ) ) ) {
					return $m[0]; // real, src-less JS — rewrite_inline_scripts() still needs it.
				}

				// Trailing \x01 delimiter (not just a leading one): strtr()
				// already matches longest-key-first, so a bare "..._1" vs
				// "..._10" ambiguity does not actually arise in practice —
				// this is not a workaround for a real strtr() bug. It is
				// kept anyway so token boundaries are self-delimiting and
				// unambiguous by construction, rather than correctness
				// resting on a documented-but-unenforced PHP implementation
				// detail of one specific function.
				$token             = "\x01ANCHOR_CMP_MASK_{$nonce}_{$i}\x01";
				$masked[ $token ]  = $m[0];
				$i++;
				return $token;
			},
			$html
		);
	}

	/**
	 * Extract a <script>'s type="" value, quoted or unquoted. Returns null
	 * when there is no type attribute at all (which HTML treats identically
	 * to type="text/javascript" — absent means "this is JavaScript").
	 */
	private function type_value( $attrs ) {
		if ( preg_match( '#\btype\s*=\s*(["\'])((?:(?!\1)[^>])*)\1#is', $attrs, $m ) ) {
			return trim( $m[2] );
		}
		if ( preg_match( '#\btype\s*=\s*([^\s>]+)#i', $attrs, $m ) ) {
			return trim( $m[1] );
		}
		return null;
	}

	/**
	 * @param string|null $type As returned by type_value().
	 * @return bool True when the browser would execute this script as JS.
	 */
	private function is_executable_type( $type ) {
		if ( null === $type || '' === $type ) {
			return true; // absent/empty type attribute defaults to JavaScript.
		}
		// Strip a MIME parameter, e.g. "text/javascript;charset=utf-8".
		$type = strtolower( trim( strtok( trim( $type ), ';' ) ) );
		return in_array( $type, self::EXECUTABLE_TYPES, true );
	}

	/**
	 * Rewrite <script src="..."> / <iframe src="..."> whose URL matches a
	 * denied rule.
	 *
	 * Pattern:
	 *   #<TAG\b([^>]*?)(?<![\w:.-])src\s*=\s*(?:(["\'])((?:(?!\2)[^>])*)\2|([^\s>]+))([^>]*)>#is
	 *
	 * Walkthrough:
	 *   <TAG\b               — the opening tag name, e.g. "<script" or
	 *                          "<iframe". Flag i makes this (and everything
	 *                          below) case-insensitive, so <SCRIPT SRC=...>
	 *                          matches too.
	 *   ([^>]*?)             — (1) any attributes before src, lazy so it
	 *                          stops at the first qualifying match rather
	 *                          than swallowing the tag.
	 *   (?<![\w:.-])src      — the literal attribute name "src", guarded by
	 *                          a negative lookbehind excluding a preceding
	 *                          word character, ":", ".", or "-". Without
	 *                          this a plain \b would ALSO match "src" inside
	 *                          "data-src", "data-anchor-src", "x-bind:src",
	 *                          or ":src" (Vue/Alpine bindings), because "-"
	 *                          and ":" are non-word characters and \b sits
	 *                          right at that boundary. A lazy-loaded iframe
	 *                          written as
	 *                            <iframe data-src="https://youtube..." src="about:blank">
	 *                          would otherwise have the tracker URL pulled
	 *                          out of "data-src" while the real, harmless
	 *                          src="about:blank" was left live and
	 *                          unblocked on the page — a silent compliance
	 *                          miss, not a crash.
	 *   \s*=\s*              — "=" with optional whitespace either side
	 *                          (handles "src = "x"" as well as "src="x"").
	 *   (?:                  — one of two ways the value can be written:
	 *     (["\'])            —   (2) a quote character, single or double;
	 *     ((?:(?!\2)[^>])*)  —   (3) the URL: any character that is not ">"
	 *                            AND does not start the matching close
	 *                            quote, repeated. Bounded on BOTH sides —
	 *                            it can never cross a real ">" even if the
	 *                            quote never closes. The original brief's
	 *                            (.*?)\2 had no such bound: with the s flag,
	 *                            "." also matches newlines, so an
	 *                            unterminated quote (a truncated embed, a
	 *                            template fatal mid-<script>) let the lazy
	 *                            match run across every following tag
	 *                            looking for a closing quote that never
	 *                            comes, silently absorbing the rest of the
	 *                            page's markup into one "URL" and deleting
	 *                            it from the response.
	 *     \2                 —   the matching closing quote;
	 *   |                    — or:
	 *     ([^\s>]+)          —   (4) an UNQUOTED value: any run of
	 *                            non-whitespace, non-">" characters. HTML
	 *                            permits src=https://example.com/x.js with
	 *                            no quotes at all, and this buffer sits as
	 *                            the OUTERMOST ob_start (template_redirect
	 *                            priority 1), so it can see the OUTPUT of a
	 *                            later-attaching HTML minifier that strips
	 *                            attribute quoting. Without this branch
	 *                            such a page would go completely unblocked
	 *                            with no error or warning.
	 *   )
	 *   ([^>]*)              — (5) any attributes after src (id, width,
	 *                          etc.) plus a possible trailing self-closing
	 *                          "/", preserved and then cleaned in code (see
	 *                          strip_self_closing_slash()) so it survives
	 *                          on the page without a stray "/" landing
	 *                          mid-attribute-list.
	 *   >                    — end of the opening tag.
	 * Flags: i (case-insensitive), s ("." matches newlines — attributes can
	 * legally wrap lines in prettified/minified markup; this is now safe
	 * because of the bound above).
	 *
	 * Deliberately does NOT match: the tag's closing "</script>" (only the
	 * opening tag needs rewriting); any "src"-like attribute prefixed with a
	 * word character, "-", ":", or "." (data-src, data-anchor-src, :src,
	 * x-bind:src — never real src attributes); anything beyond the tag's own
	 * ">" no matter how the quote or value is malformed.
	 */
	private function rewrite_src_tags( $html, $tag, array $rules, array $allowed ) {
		$pattern = '#<' . $tag . '\b([^>]*?)(?<![\w:.-])src\s*=\s*(?:(["\'])((?:(?!\2)[^>])*)\2|([^\s>]+))([^>]*)>#is';

		return preg_replace_callback(
			$pattern,
			function ( $m ) use ( $rules, $allowed, $tag ) {
				$before = $m[1];
				$url    = ( '' !== $m[2] ) ? $m[3] : $m[4];
				$after  = $this->strip_self_closing_slash( $m[5] );

				$category = $this->category_for( $url, $rules );
				if ( null === $category || ! empty( $allowed[ $category ] ) ) {
					return $m[0];
				}

				$this->blocked++;

				$attrs = $this->strip_type_attribute( $before . $after );

				// The captured value came straight out of an HTML attribute,
				// so it may carry entities (a query string's "&" is almost
				// always written "&amp;"). esc_attr() re-encodes for safe
				// re-embedding, so encoding it a second time on top of the
				// original entity would double-escape: "&amp;" becomes
				// "&amp;amp;", and after "Accept & Load" the browser
				// requests a query string with a literal "amp;" glued onto
				// the next parameter name (?rel=0&amp;autoplay=1 restores
				// as ?rel=0&amp;amp;autoplay=1, i.e. a parameter named
				// "amp;autoplay"). Decoding first, then encoding once, round-trips
				// correctly.
				$clean_url = html_entity_decode( $url, ENT_QUOTES, 'UTF-8' );

				if ( 'script' === $tag ) {
					return sprintf(
						'<script%s type="text/plain" data-anchor-consent="%s" data-anchor-src="%s">',
						$attrs,
						esc_attr( $category ),
						esc_attr( $clean_url )
					);
				}

				// An iframe leaves a visible, actionable placeholder rather
				// than a hole. Built from <span> (a phrasing-content
				// element), not <div>/<p>: iframes are legal inside a <p>
				// (e.g. "<p>Watch: <iframe>...</iframe></p>"), but a <div>
				// or nested <p> is not — the HTML parser would close the
				// surrounding <p> early and orphan its closing tag. A span
				// with display:block renders identically to a div while
				// staying valid in both flow and phrasing contexts.
				return sprintf(
					'<span class="anchor-cmp-placeholder" style="display:block" data-anchor-consent="%1$s">'
					. '<span class="anchor-cmp-placeholder__text" style="display:block">%2$s</span>'
					. '<button type="button" class="anchor-cmp-placeholder__btn" data-anchor-accept="%1$s">%3$s</button></span>'
					. '<iframe%4$s data-anchor-consent="%1$s" data-anchor-src="%5$s" style="display:none">',
					esc_attr( $category ),
					esc_html__( 'This content is blocked until you accept the related cookies.', 'anchor-schema' ),
					esc_html__( 'Accept & Load', 'anchor-schema' ),
					$attrs,
					esc_attr( $clean_url )
				);
			},
			$html
		);
	}

	/**
	 * Inline scripts have no src, so match their body against the same
	 * patterns — this is how an inline fbq() or hj() bootstrap gets caught.
	 *
	 * Pattern:
	 *   #<script\b(?![^>]*(?<![\w:.-])src\s*=)([^>]*)>(.*?)</script>#is
	 *
	 * Walkthrough:
	 *   <script\b                       — opening tag.
	 *   (?![^>]*(?<![\w:.-])src\s*=)    — negative lookahead: bail out of
	 *                                     this branch entirely when the tag
	 *                                     has a real "src" attribute — that
	 *                                     case is already handled by
	 *                                     rewrite_src_tags(), which runs
	 *                                     first. Uses the same
	 *                                     (?<![\w:.-]) guard as above so a
	 *                                     "data-src"/"data-anchor-src"/":src"
	 *                                     attribute does not wrongly trip
	 *                                     this check and cause a genuinely
	 *                                     inline (src-less) script to be
	 *                                     skipped by BOTH rewriters.
	 *   ([^>]*)                         — (1) the tag's attributes, kept
	 *                                     verbatim.
	 *   >                                — end of the opening tag.
	 *   (.*?)                           — (2) the script body, lazy.
	 *   </script>                       — the closing tag (required — this
	 *                                     is the one place the closing tag
	 *                                     participates, since the whole body
	 *                                     needs replacing).
	 * Flags: i, s (see rewrite_src_tags for why).
	 *
	 * Deliberately does NOT match: any <script> whose type is not absent,
	 * empty, or one of EXECUTABLE_TYPES (checked in the callback, not the
	 * pattern — application/ld+json and text/template are inert data/markup,
	 * never code, so gating them would destroy structured data or template
	 * markup for zero compliance benefit); any <script> that already
	 * carries data-anchor-consent (Task 12's Code Snippets bridge will have
	 * already handled those explicitly — this is also what keeps a second
	 * application of this method a no-op on its own prior output).
	 */
	private function rewrite_inline_scripts( $html, array $rules, array $allowed ) {
		return preg_replace_callback(
			'#<script\b(?![^>]*(?<![\w:.-])src\s*=)([^>]*)>(.*?)</script>#is',
			function ( $m ) use ( $rules, $allowed ) {
				$attrs = $m[1];
				$body  = $m[2];

				if ( ! $this->is_executable_type( $this->type_value( $attrs ) ) ) {
					return $m[0]; // JSON-LD, a client-side template, etc. — not code, never gated.
				}

				if ( false !== stripos( $attrs, 'data-anchor-consent' ) ) {
					return $m[0]; // already handled (e.g. by the snippets bridge)
				}

				$category = $this->category_for( $body, $rules );
				if ( null === $category || ! empty( $allowed[ $category ] ) ) {
					return $m[0];
				}

				$this->blocked++;

				return sprintf(
					'<script%s type="text/plain" data-anchor-consent="%s">%s</script>',
					$this->strip_type_attribute( $attrs ),
					esc_attr( $category ),
					$body
				);
			},
			$html
		);
	}

	/**
	 * Remove any existing type= so ours is unambiguous. Without this, a tag
	 * that already carries type=text/javascript would keep it sitting in
	 * front of our freshly-appended type="text/plain" as a DUPLICATE
	 * attribute; per the HTML tokenizer the FIRST occurrence of a duplicate
	 * attribute wins, so the tag would keep executing as text/javascript —
	 * the "text/plain" marker present in the markup but never actually
	 * taking effect in the browser. All three attribute-value forms must be
	 * removed: type="x", type='x', and bare type=x (an HTML minifier that
	 * unquotes src — the case that motivated the unquoted branch in
	 * rewrite_src_tags() — unquotes type too).
	 *
	 * This is ONE quote-aware pass, not a quoted pass followed by an
	 * unquoted one. A quoted-only pattern looks safe but is not: it has no
	 * notion of quote CONTEXT, so a `type="..."` sitting inside some other
	 * attribute's value gets deleted along with the text around it.
	 * strip_type_attribute() runs on the surviving attributes of every
	 * blocked tag — including a blocked iframe's `title`, which for a
	 * YouTube embed is the video's own title: arbitrary text the site owner
	 * does not control. `title="Battery type='AA' cells"` would silently
	 * become `title="Battery cells"`, and `data-x='a type="b" c'` would
	 * become `data-x='a c'`. Quote parity survives (a matched pair is
	 * removed), so it is not structural breakage — but it is silent
	 * destruction of page content the visitor is supposed to read.
	 *
	 * The pattern walks the attribute string left to right via a single
	 * alternation. A complete quoted span (either quote style) is matched
	 * FIRST and passed through UNCHANGED as one atomic unit, so nothing
	 * inside it — including a coincidental `type=` in any of the three
	 * forms — is ever independently visible to the strip branch. Only a
	 * `type=value` that is not inside any quoted span reaches the capturing
	 * group and gets removed. The strip branch's own value alternation
	 * accepts all three forms, so one pass covers what two used to.
	 *
	 * ACCEPTED FAIL-OPEN — unbalanced quotes. When the attribute string has
	 * an odd number of quote characters (e.g. ` title="27" monitor"
	 * type=text/javascript alt="x"`), the quoted-span branches pair across
	 * the real `type=`, consuming it atomically, and it is NOT stripped —
	 * the tag keeps an executable type and the tracker can still run. This
	 * is deliberate and must not be "fixed". On malformed markup the only
	 * way to reach that `type=` is to abandon quote awareness at exactly
	 * the position where quote awareness is the thing protecting the
	 * surrounding content, which trades a missed tracker for corrupted
	 * output on every well-formed tag that merely LOOKS malformed to a
	 * dumber pattern. Missing a tracker on broken HTML is the cheaper
	 * failure; an earlier round that did strip it here still left the tag's
	 * quotes broken, so it bought nothing. Pinned by
	 * test_unbalanced_quotes_decline_to_strip_type_rather_than_corrupt().
	 */
	private function strip_type_attribute( $attrs ) {
		return preg_replace_callback(
			'#"[^"]*"|\'[^\']*\'|(\stype\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>"\']+))#i',
			static function ( $m ) {
				// Group 1 only participates on the "strip this" branch; the
				// two quoted-span alternatives leave it unset, so $m[0] (the
				// whole quoted span) is returned untouched.
				return ( '' !== ( $m[1] ?? '' ) ) ? '' : $m[0];
			},
			$attrs
		);
	}

	/**
	 * A self-closing "<script src="x" />" leaves its "/" inside the
	 * "attributes after src" capture. Left in place it lands mid-tag in the
	 * rebuilt output (e.g. "...src.js" / type="text/plain" ...">"). Strip a
	 * trailing "/" (with any surrounding whitespace) from the END of the
	 * captured tail only — this cannot touch a "/" that is part of an
	 * attribute value earlier in the string, because it is anchored to the
	 * end of this specific, already-isolated capture.
	 */
	private function strip_self_closing_slash( $after ) {
		return preg_replace( '#/\s*$#', '', $after );
	}
}
