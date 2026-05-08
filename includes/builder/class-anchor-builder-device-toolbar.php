<?php
/**
 * Renders the Desktop / Tablet / Mobile / Full-width toggle bar above the
 * builder preview frame. JS sets data-device on the target element.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Anchor_Builder_Device_Toolbar {

    public static function render( $target_id ) {
        $devices = [
            'desktop' => 'Desktop',
            'tablet'  => 'Tablet',
            'mobile'  => 'Mobile',
            'full'    => 'Full Width',
        ];
        ?>
        <div class="anchor-builder__device-toolbar" data-target="<?php echo esc_attr( $target_id ); ?>">
            <?php foreach ( $devices as $key => $label ) : ?>
                <button type="button" class="anchor-builder__device<?php echo $key === 'desktop' ? ' is-active' : ''; ?>" data-device="<?php echo esc_attr( $key ); ?>">
                    <?php echo esc_html( $label ); ?>
                </button>
            <?php endforeach; ?>
            <button type="button" class="anchor-builder__device anchor-builder__device--refresh" data-action="refresh" title="Refresh preview">↻</button>
        </div>
        <?php
    }
}
