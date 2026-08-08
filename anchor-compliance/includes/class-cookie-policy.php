<?php
/**
 * Anchor Compliance — cookie policy shortcode.
 *
 * [anchor_cookie_policy] renders a live, categorized table of the cookies the
 * site actually sets, sourced from Anchor_Compliance_Service_Registry so a
 * client's cookie-policy page stays accurate as services are enabled,
 * disabled, or re-categorized rather than drifting from hand-written
 * boilerplate.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Anchor_Compliance_Cookie_Policy {

	/**
	 * Human labels for the four fixed categories. Wording and filter name are
	 * kept identical to class-banner.php's preference-center copy so a
	 * category reads the same wherever a visitor encounters it.
	 */
	private function category_labels() {
		return (array) apply_filters( 'anchor_compliance_category_labels', [
			'necessary'  => __( 'Necessary', 'anchor-schema' ),
			'functional' => __( 'Functional', 'anchor-schema' ),
			'analytics'  => __( 'Analytics', 'anchor-schema' ),
			'marketing'  => __( 'Marketing', 'anchor-schema' ),
		] );
	}

	/** @see category_labels() — same reasoning for reusing the banner's copy/filter. */
	private function category_descriptions() {
		return (array) apply_filters( 'anchor_compliance_category_descriptions', [
			'necessary'  => __( 'Required for the site to function and cannot be switched off.', 'anchor-schema' ),
			'functional' => __( 'Enables enhanced functionality such as live chat and embedded maps.', 'anchor-schema' ),
			'analytics'  => __( 'Helps us understand how visitors use the site so we can improve it.', 'anchor-schema' ),
			'marketing'  => __( 'Used to deliver more relevant ads and measure campaign performance.', 'anchor-schema' ),
		] );
	}

	/**
	 * [anchor_cookie_policy categories="analytics,marketing" show_empty="no"]
	 *
	 * @param array|string $atts
	 * @return string
	 */
	public function render( $atts ) {
		$all_categories = Anchor_Compliance_Consent_State::all_categories();

		$atts = shortcode_atts(
			[
				'categories' => implode( ',', $all_categories ),
				'show_empty' => 'no',
			],
			(array) $atts,
			'anchor_cookie_policy'
		);

		// Keep the caller's requested order, but only for real category slugs —
		// an unrecognised or empty value must not produce an empty policy page.
		$requested = array_filter( array_map( 'trim', explode( ',', strtolower( (string) $atts['categories'] ) ) ) );
		$requested = array_values( array_unique( array_intersect( $requested, $all_categories ) ) );
		if ( empty( $requested ) ) {
			$requested = $all_categories;
		}

		$show_empty = in_array( strtolower( (string) $atts['show_empty'] ), [ 'yes', 'true', '1' ], true );

		// A fresh registry rather than a shared/injected instance:
		// Anchor_Compliance_Service_Registry::all() memoizes its resolved
		// result for the object's own lifetime, and the module's own
		// registry instance lives for the whole request. A page can render
		// this shortcode more than once, and — as in this module's own test
		// suite, which instantiates a new registry per assertion for the
		// same reason — settings or the anchor_compliance_services filter
		// can legitimately differ between calls. Rebuilding here (cheap: an
		// array literal plus one apply_filters()) is what keeps the table
		// actually live instead of possibly serving a stale first resolve.
		$cookies = ( new Anchor_Compliance_Service_Registry() )->cookies_by_category();
		$labels  = $this->category_labels();
		$descs   = $this->category_descriptions();

		ob_start();

		echo '<div class="anchor-cmp-policy">';

		foreach ( $requested as $cat ) {
			$rows = isset( $cookies[ $cat ] ) ? $cookies[ $cat ] : [];

			if ( empty( $rows ) && ! $show_empty ) {
				continue;
			}

			printf( '<h3>%s</h3>', esc_html( isset( $labels[ $cat ] ) ? $labels[ $cat ] : ucfirst( $cat ) ) );
			printf( '<p>%s</p>', esc_html( isset( $descs[ $cat ] ) ? $descs[ $cat ] : '' ) );

			echo '<div class="anchor-cmp-policy__scroll">';
			echo '<table class="anchor-cmp-policy__table"><thead><tr>'
				. '<th>' . esc_html__( 'Name', 'anchor-schema' ) . '</th>'
				. '<th>' . esc_html__( 'Provider', 'anchor-schema' ) . '</th>'
				. '<th>' . esc_html__( 'Purpose', 'anchor-schema' ) . '</th>'
				. '<th>' . esc_html__( 'Duration', 'anchor-schema' ) . '</th>'
				. '</tr></thead><tbody>';

			foreach ( $rows as $row ) {
				echo '<tr>'
					. '<td>' . esc_html( $row['name'] ) . '</td>'
					. '<td>' . esc_html( $row['provider'] ) . '</td>'
					. '<td>' . esc_html( $row['purpose'] ) . '</td>'
					. '<td>' . esc_html( $row['duration'] ) . '</td>'
					. '</tr>';
			}

			echo '</tbody></table>';
			echo '</div>'; // .anchor-cmp-policy__scroll
		}

		echo '</div>'; // .anchor-cmp-policy

		return ob_get_clean();
	}
}
