<?php
/**
 * Anchor Compliance — Code Snippets bridge.
 *
 * Adds a "Consent Category" control to each Anchor Code Snippet and holds
 * non-necessary snippets until the visitor consents. This is the surgical
 * blocking layer: the Script Blocker (class-script-blocker.php) pattern-matches
 * a services registry against arbitrary theme/page markup, but this is where
 * the agency's clients actually paste GTM, Meta Pixel, and CallTrackingMetrics
 * code — and because the category here is DECLARED by whoever pasted the
 * snippet rather than inferred, there is zero false-positive risk.
 *
 * mu-plugin limitation: a snippet whose acs_location is 'mu_plugin' is written
 * by Anchor Code Snippets to a real file in wp-content/mu-plugins/, which
 * WordPress loads long before plugins_loaded (and therefore long before this
 * module boots). Nothing running inside this module can gate that output —
 * see is_gateable(). The metabox disables the control and explains why, and
 * admin_notices() surfaces any snippet where a non-necessary category is
 * stored anyway (e.g. left over from before the snippet was moved to
 * mu-plugin), so a mis-set snippet is visible rather than silently ungated.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Anchor_Compliance_Snippets_Bridge {

	const META_KEY = 'anchor_consent_category';
	const NONCE    = 'anchor_cmp_snippet_category_nonce';
	const ACTION   = 'anchor_cmp_snippet_category';

	/** @var Anchor_Compliance_Consent_State */
	private $state;

	/** @var Anchor_Compliance_Geo */
	private $geo;

	public function __construct( $state, $geo ) {
		$this->state = $state;
		$this->geo   = $geo;
	}

	/**
	 * The Anchor Code Snippets CPT slug. Read from the module's own constant
	 * when that module is loaded (the normal case), with a literal fallback so
	 * this class never fatals if it is ever loaded without it.
	 */
	private function cpt() {
		return class_exists( 'Anchor_Code_Snippets_Module' ) ? Anchor_Code_Snippets_Module::CPT : 'anchor_snippet';
	}

	/* ─── Metabox ──────────────────────────────────────────── */

	public function add_metabox() {
		add_meta_box(
			'anchor_cmp_consent_category',
			__( 'Consent Category', 'anchor-schema' ),
			[ $this, 'render_metabox' ],
			$this->cpt(),
			'side'
		);
	}

	public function render_metabox( $post ) {
		wp_nonce_field( self::ACTION, self::NONCE );

		$stored   = get_post_meta( $post->ID, self::META_KEY, true );
		$category = $stored ? Anchor_Compliance_Settings::sanitize_category( $stored ) : 'necessary';
		$gateable = $this->is_gateable( $post->ID );

		$labels = [
			'necessary'  => __( 'Necessary', 'anchor-schema' ),
			'functional' => __( 'Functional', 'anchor-schema' ),
			'analytics'  => __( 'Analytics', 'anchor-schema' ),
			'marketing'  => __( 'Marketing', 'anchor-schema' ),
		];
		?>
		<div class="anchor-cmp-field">
			<select name="<?php echo esc_attr( self::META_KEY ); ?>" id="<?php echo esc_attr( self::META_KEY ); ?>" <?php disabled( $gateable, false ); ?>>
				<?php foreach ( $labels as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $category, $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php if ( ! $gateable ) : ?>
				<p class="notice notice-warning" style="margin:8px 0 0;padding:8px;">
					<?php esc_html_e( 'This snippet runs from an mu-plugin file, which loads before the consent module. It cannot be consent-gated. Move it to Header, Body, or Footer if it needs gating.', 'anchor-schema' ); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	public function save( $post_id ) {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( wp_unslash( $_POST[ self::NONCE ] ), self::ACTION ) ) {
			return;
		}
		if ( $this->is_autosave() ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST[ self::META_KEY ] ) ) {
			return;
		}

		$category = Anchor_Compliance_Settings::sanitize_category( wp_unslash( $_POST[ self::META_KEY ] ) );
		update_post_meta( $post_id, self::META_KEY, $category );
	}

	/**
	 * Isolated as its own (protected, overridable) method rather than an
	 * inline `defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE` check: DOING_AUTOSAVE
	 * is a raw PHP constant with no WP-core filter wrapper (unlike
	 * wp_doing_ajax()/wp_doing_cron()), and once define()'d it can never be
	 * unset for the rest of the PHPUnit process — permanently flipping this
	 * branch for every other save_post-hooked test that runs afterward in the
	 * same run, not just this class's own tests. A test subclass overrides
	 * this method instead, which pins the bail behavior with zero process-wide
	 * side effects.
	 */
	protected function is_autosave() {
		return defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE;
	}

	/** Max offenders queried/listed per render — this is a small admin-notice affordance, not a report; a big number is never useful here and defeats the point of bounding the query. */
	const ADMIN_NOTICE_LIMIT = 5;

	/**
	 * True only on the screens where this notice is actionable: the snippet
	 * list table and the snippet editor. Every other wp-admin screen skips the
	 * query entirely — this used to run unconditionally on every admin page
	 * load for every user who can edit_posts, which is the exact class of
	 * defect this project has been bitten by before (an unbounded/unscoped
	 * admin-wide query).
	 */
	private function on_relevant_screen() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}
		$screen = get_current_screen();
		if ( ! $screen ) {
			return false;
		}
		$cpt = $this->cpt();
		return in_array( $screen->id, [ $cpt, 'edit-' . $cpt ], true );
	}

	/**
	 * Lists any mu-plugin snippet that nonetheless has a non-necessary
	 * category stored (e.g. set before the snippet was moved to mu-plugin, or
	 * written directly to post meta), so a mis-set snippet is visible rather
	 * than silently ungated — the mu-plugin file will never honor it.
	 *
	 * Bounded to ADMIN_NOTICE_LIMIT rows (with a "+N more" affordance rather
	 * than posts_per_page => -1) and gated to on_relevant_screen(), so this
	 * never runs an unbounded query on every admin page load.
	 */
	public function admin_notices() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		if ( ! $this->on_relevant_screen() ) {
			return;
		}

		// Fetch one extra row so we can tell whether there are more offenders
		// than ADMIN_NOTICE_LIMIT without a second, COUNT-style query.
		$ids = get_posts( [
			'post_type'              => $this->cpt(),
			'post_status'            => 'any',
			'posts_per_page'         => self::ADMIN_NOTICE_LIMIT + 1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'meta_query'             => [
				[
					'key'   => 'acs_location',
					'value' => 'mu_plugin',
				],
			],
		] );

		if ( ! $ids ) {
			return;
		}

		$offenders = [];
		foreach ( $ids as $id ) {
			$stored = get_post_meta( $id, self::META_KEY, true );
			if ( '' === $stored ) {
				continue;
			}
			if ( 'necessary' !== Anchor_Compliance_Settings::sanitize_category( $stored ) ) {
				$offenders[] = $id;
			}
		}

		if ( ! $offenders ) {
			return;
		}

		$more      = count( $offenders ) > self::ADMIN_NOTICE_LIMIT;
		$offenders = array_slice( $offenders, 0, self::ADMIN_NOTICE_LIMIT );

		echo '<div class="notice notice-warning"><p>' . esc_html__( 'Anchor Compliance: the following code snippets have a non-necessary consent category set but run from an mu-plugin file, which loads before the consent module and cannot be gated. Move them to Header, Body, or Footer, or set their category to Necessary.', 'anchor-schema' ) . '</p><ul style="list-style:disc;margin-left:1.5em;">';
		foreach ( $offenders as $id ) {
			printf(
				'<li><a href="%1$s">%2$s</a></li>',
				esc_url( (string) get_edit_post_link( $id, 'raw' ) ),
				esc_html( get_the_title( $id ) )
			);
		}
		if ( $more ) {
			echo '<li>' . esc_html__( '&hellip;and more.', 'anchor-schema' ) . '</li>';
		}
		echo '</ul></div>';
	}

	/* ─── Gating ───────────────────────────────────────────── */

	/**
	 * False for a snippet written to a real mu-plugin file: that file loads on
	 * the `muplugins_loaded` hook, long before this module's constructor runs
	 * on `plugins_loaded`, so nothing here ever sees — let alone rewrites —
	 * its output.
	 */
	public function is_gateable( $post_id ) {
		return 'mu_plugin' !== get_post_meta( $post_id, 'acs_location', true );
	}

	/**
	 * Hooked to `anchor_code_snippet_output`. Returns $html completely
	 * unchanged — never half-processed — when the module is disabled, the
	 * snippet is not gateable, its category is necessary, or that category is
	 * already granted for this visitor.
	 */
	public function filter_snippet_output( $html, $post_id ) {
		$opts = Anchor_Compliance_Settings::get();
		if ( empty( $opts['general']['enabled'] ) ) {
			return $html;
		}
		if ( ! $this->is_gateable( $post_id ) ) {
			return $html;
		}

		$stored   = get_post_meta( $post_id, self::META_KEY, true );
		$category = $stored ? Anchor_Compliance_Settings::sanitize_category( $stored ) : 'necessary';

		if ( 'necessary' === $category ) {
			return $html;
		}
		if ( $this->state->allows( $category, ! $this->geo->is_strict() ) ) {
			return $html;
		}

		return $this->neutralize( $html, $category );
	}

	/**
	 * Rewrite every <script src="..."> and inline <script> in $html to the
	 * SAME neutralized shape Anchor_Compliance_Script_Blocker produces
	 * (rewrite_src_tags() / rewrite_inline_scripts() in class-script-blocker.php),
	 * so the shared front-end runtime can activate these tags identically to
	 * any other blocked embed: src moves to data-anchor-src, any existing type
	 * is replaced with type="text/plain", and data-anchor-consent carries the
	 * category. Non-script markup in the snippet (HTML, a <style> block, plain
	 * text) is left untouched — neither pattern matches anything but a
	 * <script> tag.
	 *
	 * The actual attribute rewriting reuses Anchor_Compliance_Script_Blocker's
	 * own public-static helpers (type_value(), is_executable_type(),
	 * strip_type_attribute(), strip_self_closing_slash()) rather than keeping
	 * a second copy of that logic here — a prior version of this method DID
	 * duplicate it, and the duplicate silently lacked the is_executable_type()
	 * guard, so a gated snippet's <script type="application/ld+json"> got its
	 * type rewritten to text/plain even though the blocker itself would never
	 * touch it. Calling the shared statics means the two classes cannot drift
	 * apart on this again.
	 */
	private function neutralize( $html, $category ) {
		$html = $this->neutralize_src_tags( $html, $category );
		$html = $this->neutralize_inline_scripts( $html, $category );
		return $html;
	}

	/** Mirrors Anchor_Compliance_Script_Blocker::rewrite_src_tags() for the 'script' tag only — see that method for the full pattern walkthrough. */
	private function neutralize_src_tags( $html, $category ) {
		$pattern = '#<script\b([^>]*?)(?<![\w:.-])src\s*=\s*(?:(["\'])((?:(?!\2)[^>])*)\2|([^\s>]+))([^>]*)>#is';

		return preg_replace_callback(
			$pattern,
			function ( $m ) use ( $category ) {
				$before = $m[1];
				$url    = ( '' !== $m[2] ) ? $m[3] : $m[4];
				$after  = Anchor_Compliance_Script_Blocker::strip_self_closing_slash( $m[5] );

				$attrs = Anchor_Compliance_Script_Blocker::strip_type_attribute( $before . $after );

				// Decode once, encode once — see the identical comment in
				// Script_Blocker::rewrite_src_tags() for why (avoids
				// double-escaping an already-entity-encoded "&amp;" in a
				// query string).
				$clean_url = html_entity_decode( $url, ENT_QUOTES, 'UTF-8' );

				return sprintf(
					'<script%s type="text/plain" data-anchor-consent="%s" data-anchor-src="%s">',
					$attrs,
					esc_attr( $category ),
					esc_attr( $clean_url )
				);
			},
			$html
		);
	}

	/**
	 * Mirrors Anchor_Compliance_Script_Blocker::rewrite_inline_scripts() —
	 * see that method for the full pattern walkthrough. Crucially includes
	 * the SAME is_executable_type( type_value( $attrs ) ) guard: a <script
	 * type="application/ld+json"> or type="text/template"> is inert
	 * data/markup, never code, and gating it would destroy structured data
	 * (this plugin's flagship feature, emitted by five other modules) or
	 * template markup for zero compliance benefit.
	 */
	private function neutralize_inline_scripts( $html, $category ) {
		return preg_replace_callback(
			'#<script\b(?![^>]*(?<![\w:.-])src\s*=)([^>]*)>(.*?)</script>#is',
			function ( $m ) use ( $category ) {
				$attrs = $m[1];
				$body  = $m[2];

				if ( ! Anchor_Compliance_Script_Blocker::is_executable_type( Anchor_Compliance_Script_Blocker::type_value( $attrs ) ) ) {
					return $m[0]; // JSON-LD, a client-side template, etc. — not code, never gated.
				}

				if ( false !== stripos( $attrs, 'data-anchor-consent' ) ) {
					return $m[0]; // already handled — keeps this idempotent on its own prior output.
				}

				return sprintf(
					'<script%s type="text/plain" data-anchor-consent="%s">%s</script>',
					Anchor_Compliance_Script_Blocker::strip_type_attribute( $attrs ),
					esc_attr( $category ),
					$body
				);
			},
			$html
		);
	}
}
