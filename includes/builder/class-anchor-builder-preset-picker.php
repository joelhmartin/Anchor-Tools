<?php
/**
 * Renders a visual preset picker — cards grouped by category.
 *
 * Caller supplies preset definitions; the picker writes selected preset
 * settings to a hidden input. JS then applies the overrides to the form.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Anchor_Builder_Preset_Picker {

    /**
     * @param array  $presets   ['key' => ['label', 'category', 'description', 'thumb', 'overrides' => [...]]]
     * @param string $hidden_name Name of the hidden input that receives the selected preset key.
     * @param string $current   Currently selected preset key (optional).
     */
    public static function render( $presets, $hidden_name = 'anchor_preset', $current = '' ) {
        if ( empty( $presets ) ) {
            echo '<p class="anchor-builder__empty">No presets available yet.</p>';
            return;
        }

        $categories = [];
        foreach ( $presets as $key => $preset ) {
            $cat = $preset['category'] ?? 'Other';
            $categories[ $cat ][ $key ] = $preset;
        }

        ?>
        <div class="anchor-builder__preset-picker">
            <input type="hidden" name="<?php echo esc_attr( $hidden_name ); ?>" value="<?php echo esc_attr( $current ); ?>" class="anchor-builder__preset-selected" />
            <?php foreach ( $categories as $cat => $items ) : ?>
                <h3 class="anchor-builder__preset-category"><?php echo esc_html( $cat ); ?></h3>
                <div class="anchor-builder__preset-grid">
                    <?php foreach ( $items as $key => $preset ) :
                        $is_active = $current === $key;
                        $overrides = wp_json_encode( $preset['overrides'] ?? [] );
                        ?>
                        <button type="button"
                                class="anchor-builder__preset<?php echo $is_active ? ' is-active' : ''; ?>"
                                data-preset="<?php echo esc_attr( $key ); ?>"
                                data-overrides="<?php echo esc_attr( $overrides ); ?>">
                            <?php if ( ! empty( $preset['thumb'] ) ) : ?>
                                <span class="anchor-builder__preset-thumb"><?php echo $preset['thumb']; // already-escaped SVG ?></span>
                            <?php else : ?>
                                <span class="anchor-builder__preset-thumb anchor-builder__preset-thumb--placeholder"></span>
                            <?php endif; ?>
                            <span class="anchor-builder__preset-label"><?php echo esc_html( $preset['label'] ); ?></span>
                            <?php if ( ! empty( $preset['description'] ) ) : ?>
                                <span class="anchor-builder__preset-desc"><?php echo esc_html( $preset['description'] ); ?></span>
                            <?php endif; ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }
}
