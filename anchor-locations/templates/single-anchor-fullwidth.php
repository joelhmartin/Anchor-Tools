<?php
/**
 * Minimal full-width single template for Anchor Locations pages.
 *
 * Used only when the "Use plugin full-width single template" setting is on
 * (Settings > Anchor Tools > Locations) and the theme lacks a suitable
 * full-width layout. Renders the theme header + footer around the post content
 * (which runs the Phase-1 render + global wrapper via the_content) inside a
 * full-width, sidebar-less container.
 *
 * @package Anchor\Locations
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

get_header();
?>
<main id="al-fullwidth" class="al-fullwidth" style="width:100%;max-width:100%;">
	<?php
	while ( have_posts() ) {
		the_post();
		the_content();
	}
	?>
</main>
<?php
get_footer();
