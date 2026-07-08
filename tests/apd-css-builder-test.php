<?php
// Standalone logic test for Anchor_APD_Renderer::build_scoped_css.
// Run: php tests/apd-css-builder-test.php
// No WordPress needed — build_scoped_css uses only string ops + casts.

define( 'ABSPATH', __DIR__ );
require __DIR__ . '/../anchor-post-display/includes/class-apd-renderer.php';

$fail = 0;
function check( $cond, $msg ) {
    global $fail;
    if ( $cond ) { echo "PASS: $msg\n"; } else { echo "FAIL: $msg\n"; $fail++; }
}

$grid = Anchor_APD_Renderer::build_scoped_css( 'apd-x', [
    'layout' => 'grid', 'columns' => 4, 'columns_tablet' => 2, 'columns_mobile' => 1, 'gap' => 20,
] );
check( strpos( $grid, 'repeat(4,1fr)' ) !== false, 'grid desktop = 4 cols' );
check( strpos( $grid, '@media(max-width:1024px)' ) !== false && strpos( $grid, 'repeat(2,1fr)' ) !== false, 'grid tablet = 2 cols' );
check( strpos( $grid, '@media(max-width:767px)' ) !== false && strpos( $grid, 'repeat(1,1fr)' ) !== false, 'grid mobile = 1 col' );
check( strpos( $grid, '--apd-gap:20px' ) !== false, 'gap applied' );

$slider = Anchor_APD_Renderer::build_scoped_css( 'apd-y', [
    'layout' => 'slider', 'slider_per_view' => 3, 'slider_per_view_tablet' => 2, 'slider_per_view_mobile' => 1, 'gap' => 16,
] );
check( strpos( $slider, '--apd-per-view:3' ) !== false, 'slider desktop per-view = 3' );
check( strpos( $slider, '/ 2)' ) !== false, 'slider tablet flex-basis divides by 2' );
check( strpos( $slider, '/ 1)' ) !== false, 'slider mobile flex-basis divides by 1' );

$gm = Anchor_APD_Renderer::build_scoped_css( 'apd-z', [ 'layout' => 'grid', 'columns' => 3, 'gap' => 16, 'gap_mobile' => 8 ] );
check( strpos( $gm, '--apd-gap:8px' ) !== false, 'mobile gap override applied' );

// Structural layout must be emitted INLINE so a stale external stylesheet
// can't collapse the carousel into a vertical stack.
$carousel = Anchor_APD_Renderer::build_scoped_css( 'apd-c', [ 'layout' => 'carousel', 'slider_per_view' => 3, 'gap' => 16 ] );
check( strpos( $carousel, 'display:flex' ) !== false, 'carousel emits inline display:flex' );
check( strpos( $carousel, ':not(.anchor-post-grid-card):not(.anchor-post-grid-empty){display:contents;}' ) !== false, 'carousel dissolves injected wrappers' );

$sliderS = Anchor_APD_Renderer::build_scoped_css( 'apd-s', [ 'layout' => 'slider', 'slider_per_view' => 4, 'gap' => 16 ] );
check( strpos( $sliderS, 'display:flex' ) !== false, 'slider emits inline display:flex' );

echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
exit( $fail ? 1 : 0 );
