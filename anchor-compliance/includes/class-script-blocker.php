<?php
/**
 * Anchor Compliance — output-buffer script blocker.
 *
 * Regex, not DOMDocument: DOMDocument reformats and repairs partial or
 * non-conforming HTML, which corrupts real-world theme output. We only need to
 * rewrite two attributes on two tag types, which regex does safely.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Anchor_Compliance_Script_Blocker {

	private $registry;
	private $state;
	private $geo;
	private $blocked = 0;

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
		if ( empty( $opts['general']['enabled'] ) || empty( $opts['advanced']['buffer_enabled'] ) ) {
			return false;
		}
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return false;
		}
		if ( is_feed() || is_embed() || is_preview() || is_customize_preview() ) {
			return false;
		}
		if ( ! empty( $_GET['sitemap'] ) || ! empty( $_GET['wp-sitemap'] ) ) {
			return false;
		}
		return true;
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
	 * @param string $html
	 * @return string
	 */
	public function rewrite( $html ) {
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

		$this->blocked = 0;

		$html = $this->rewrite_src_tags( $html, 'script', $rules, $allowed );
		$html = $this->rewrite_inline_scripts( $html, $rules, $allowed );
		$html = $this->rewrite_src_tags( $html, 'iframe', $rules, $allowed );

		if ( ! empty( $opts['advanced']['debug'] ) && $this->blocked > 0 && class_exists( 'Anchor_Schema_Logger' ) ) {
			Anchor_Schema_Logger::log( sprintf( '[compliance] blocked %d tag(s) on %s', $this->blocked, esc_url_raw( home_url( add_query_arg( [] ) ) ) ) );
		}

		return $html;
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
	 * Rewrite <script src="..."> / <iframe src="..."> whose URL matches a
	 * denied rule.
	 *
	 * Pattern walkthrough:
	 *   <TAG\b            — the opening tag name, e.g. "<script" or "<iframe".
	 *   ([^>]*?)          — (1) any attributes before src, lazy so it stops at
	 *                       the first real match instead of swallowing the tag.
	 *   (?<![\w-])src     — the literal attribute name "src", guarded by a
	 *                       negative lookbehind so it does NOT match inside
	 *                       "data-src" or "data-anchor-src" (a plain \b would:
	 *                       "-" is a non-word character, so \b sits right
	 *                       between the "-" and the "s" of "...-src"). Without
	 *                       this guard, a lazy-loaded iframe written as
	 *                       <iframe data-src="https://youtube..." src="about:blank">
	 *                       would have its harmless placeholder src="about:blank"
	 *                       left live while "data-src" got mistaken for the
	 *                       real attribute, and would leave a dangling,
	 *                       malformed "data-" fragment in the rebuilt tag.
	 *   \s*=\s*(["\'])    — (2) the opening quote, single or double.
	 *   (.*?)\2           — (3) the URL, lazy up to the matching quote.
	 *   ([^>]*)           — (4) any attributes after src (id, width, etc.),
	 *                       preserved verbatim so they survive on the page.
	 *   >                 — end of the opening tag.
	 * Flags: i = case-insensitive tag/attr names, s = "." also matches
	 * newlines (attributes can legally wrap lines in minified/prettified
	 * markup).
	 *
	 * Deliberately does NOT match: the tag's closing "</script>" (irrelevant
	 * here — only the opening tag is rewritten), or any tag whose src-like
	 * attribute is a "*-src" variant rather than a bare "src".
	 */
	private function rewrite_src_tags( $html, $tag, array $rules, array $allowed ) {
		$pattern = '#<' . $tag . '\b([^>]*?)(?<![\w-])src\s*=\s*(["\'])(.*?)\2([^>]*)>#is';

		return preg_replace_callback(
			$pattern,
			function ( $m ) use ( $rules, $allowed, $tag ) {
				$before = $m[1];
				$url    = $m[3];
				$after  = $m[4];

				$category = $this->category_for( $url, $rules );
				if ( null === $category || ! empty( $allowed[ $category ] ) ) {
					return $m[0];
				}

				$this->blocked++;

				$attrs = $this->strip_type_attribute( $before . $after );

				if ( 'script' === $tag ) {
					return sprintf(
						'<script%s type="text/plain" data-anchor-consent="%s" data-anchor-src="%s">',
						$attrs,
						esc_attr( $category ),
						esc_attr( $url )
					);
				}

				// An iframe leaves a visible, actionable placeholder rather than a hole.
				return sprintf(
					'<div class="anchor-cmp-placeholder" data-anchor-consent="%1$s"><p class="anchor-cmp-placeholder__text">%2$s</p>'
					. '<button type="button" class="anchor-cmp-placeholder__btn" data-anchor-accept="%1$s">%3$s</button></div>'
					. '<iframe%4$s data-anchor-consent="%1$s" data-anchor-src="%5$s" style="display:none">',
					esc_attr( $category ),
					esc_html__( 'This content is blocked until you accept the related cookies.', 'anchor-schema' ),
					esc_html__( 'Accept & Load', 'anchor-schema' ),
					$attrs,
					esc_attr( $url )
				);
			},
			$html
		);
	}

	/**
	 * Inline scripts have no src, so match their body against the same
	 * patterns — this is how an inline fbq() or hj() bootstrap gets caught.
	 *
	 * Pattern walkthrough:
	 *   <script\b                    — opening tag.
	 *   (?![^>]*(?<![\w-])src\s*=)   — negative lookahead: bail out of this
	 *                                  branch entirely when the tag has a real
	 *                                  "src" attribute — that case is already
	 *                                  handled by rewrite_src_tags(), which
	 *                                  runs first. The same (?<![\w-]) guard as
	 *                                  above keeps a "data-src"/"data-anchor-
	 *                                  src" attribute from tripping this check
	 *                                  and wrongly skipping a genuinely inline
	 *                                  (src-less) script.
	 *   ([^>]*)                      — (1) the tag's attributes, kept verbatim.
	 *   >                            — end of the opening tag.
	 *   (.*?)                        — (2) the script body, lazy.
	 *   </script>                    — the closing tag.
	 * Flags: i, s (see rewrite_src_tags for why).
	 *
	 * Deliberately does NOT match: any <script> that already carries
	 * data-anchor-consent (checked separately below) — Task 12's Code
	 * Snippets bridge will have already handled those explicitly, and
	 * re-processing them here would be redundant at best.
	 */
	private function rewrite_inline_scripts( $html, array $rules, array $allowed ) {
		return preg_replace_callback(
			'#<script\b(?![^>]*(?<![\w-])src\s*=)([^>]*)>(.*?)</script>#is',
			function ( $m ) use ( $rules, $allowed ) {
				$attrs = $m[1];
				$body  = $m[2];

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

	/** Remove any existing type="" so ours is unambiguous. */
	private function strip_type_attribute( $attrs ) {
		return preg_replace( '#\stype\s*=\s*(["\']).*?\1#i', '', $attrs );
	}
}
