<?php
/**
 * Console tab nav. Vars: $tabs, $tab, $tab_url.
 *
 * @package Anchor\Shipping
 */

if ( ! \defined( 'ABSPATH' ) ) { exit; }
?>
<nav class="asm-tabs" aria-label="<?php \esc_attr_e( 'Order lists', 'anchor-schema' ); ?>">
	<ul>
		<?php foreach ( $tabs as $slug => $label ) : ?>
			<li class="asm-tab<?php echo $slug === $tab ? ' is-current' : ''; ?>"><a href="<?php echo \esc_url( $tab_url( $slug ) ); ?>"<?php echo $slug === $tab ? ' aria-current="page"' : ''; ?>><?php echo \esc_html( $label ); ?></a></li>
		<?php endforeach; ?>
	</ul>
</nav>
