<?php
/**
 * Shared admin chrome for Anchor Tools builder UIs (Gallery + Slider).
 *
 * Renders the tabbed builder container — top bar, tabs, left panel, center
 * preview, right utility panel — given a config array supplied by the caller.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Anchor_Builder_Shell {

    /**
     * Render the builder shell.
     *
     * @param array $config {
     *     @type string   $id          Unique id for the builder root element.
     *     @type string   $title       Title shown in the top bar.
     *     @type string   $shortcode   Shortcode preview string ([tag id="123"]).
     *     @type string   $view_url    Frontend permalink for "View".
     *     @type array    $tabs        [ 'tab_key' => 'Tab Label', ... ]
     *     @type array    $panels      [ 'tab_key' => callable($post) ]
     *     @type callable $preview     callable($post) — renders preview node contents.
     *     @type callable $utility     callable($post) — renders right-rail utility panel.
     *     @type WP_Post  $post        Post object.
     *     @type string   $active      Default active tab key (optional).
     * }
     */
    public static function render( $config ) {
        $id        = $config['id'];
        $tabs      = $config['tabs'];
        $panels    = $config['panels'];
        $post      = $config['post'];
        $active    = $config['active'] ?? array_key_first( $tabs );
        $title     = $config['title'] ?? get_the_title( $post );
        $shortcode = $config['shortcode'] ?? '';
        $view_url  = $config['view_url'] ?? get_permalink( $post );
        ?>
        <div id="<?php echo esc_attr( $id ); ?>" class="anchor-builder" data-active-tab="<?php echo esc_attr( $active ); ?>">
            <div class="anchor-builder__topbar">
                <div class="anchor-builder__title"><?php echo esc_html( $title ); ?></div>
                <?php if ( $shortcode ) : ?>
                    <div class="anchor-builder__shortcode">
                        <code><?php echo esc_html( $shortcode ); ?></code>
                        <button type="button" class="button anchor-builder__copy" data-copy="<?php echo esc_attr( $shortcode ); ?>">Copy</button>
                    </div>
                <?php endif; ?>
                <?php if ( $view_url && get_post_status( $post ) === 'publish' ) : ?>
                    <a class="button" href="<?php echo esc_url( $view_url ); ?>" target="_blank" rel="noopener">View</a>
                <?php endif; ?>
            </div>

            <div class="anchor-builder__tabs">
                <?php foreach ( $tabs as $key => $label ) : ?>
                    <button type="button" class="anchor-builder__tab<?php echo $key === $active ? ' is-active' : ''; ?>" data-tab="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></button>
                <?php endforeach; ?>
            </div>

            <div class="anchor-builder__body">
                <div class="anchor-builder__panel anchor-builder__panel--left">
                    <?php foreach ( $tabs as $key => $label ) :
                        $hidden = $key === $active ? '' : ' hidden';
                        ?>
                        <div class="anchor-builder__pane<?php echo esc_attr( $hidden ); ?>" data-pane="<?php echo esc_attr( $key ); ?>">
                            <?php
                            if ( isset( $panels[ $key ] ) && is_callable( $panels[ $key ] ) ) {
                                call_user_func( $panels[ $key ], $post );
                            }
                            ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="anchor-builder__panel anchor-builder__panel--center">
                    <?php
                    Anchor_Builder_Device_Toolbar::render( $id . '-preview' );
                    ?>
                    <div class="anchor-builder__preview-frame" id="<?php echo esc_attr( $id ); ?>-preview" data-device="desktop">
                        <?php
                        if ( ! empty( $config['preview'] ) && is_callable( $config['preview'] ) ) {
                            call_user_func( $config['preview'], $post );
                        }
                        ?>
                    </div>
                </div>

                <div class="anchor-builder__panel anchor-builder__panel--right">
                    <?php
                    if ( ! empty( $config['utility'] ) && is_callable( $config['utility'] ) ) {
                        call_user_func( $config['utility'], $post );
                    }
                    ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render a single setting field. Used by both Gallery and Slider builders.
     *
     * @param string $key      Setting key (without prefix).
     * @param array  $def      Setting definition (type, label, options, etc.).
     * @param mixed  $value    Current value.
     * @param string $meta_key Meta key (e.g. 'avg_layout', 'as_autoplay').
     */
    public static function render_field( $key, $def, $value, $meta_key ) {
        $show_for   = $def['show_for'] ?? '';
        $wrap_class = 'anchor-builder__field';
        if ( $show_for ) {
            $wrap_class .= ' anchor-builder__field--conditional';
        }
        $type = $def['type'];
        ?>
        <p class="<?php echo esc_attr( $wrap_class ); ?>"<?php if ( $show_for ) : ?> data-show-for="<?php echo esc_attr( $show_for ); ?>"<?php endif; ?>>
            <?php if ( $type === 'select' ) : ?>
                <label for="<?php echo esc_attr( $meta_key ); ?>"><strong><?php echo esc_html( $def['label'] ); ?></strong></label>
                <select name="<?php echo esc_attr( $meta_key ); ?>" id="<?php echo esc_attr( $meta_key ); ?>" class="widefat avg-setting anchor-builder__setting">
                    <?php foreach ( $def['options'] as $opt_val => $opt_label ) : ?>
                        <option value="<?php echo esc_attr( $opt_val ); ?>" <?php selected( $value, $opt_val ); ?>><?php echo esc_html( $opt_label ); ?></option>
                    <?php endforeach; ?>
                </select>
            <?php elseif ( $type === 'number' ) : ?>
                <label for="<?php echo esc_attr( $meta_key ); ?>"><strong><?php echo esc_html( $def['label'] ); ?></strong></label>
                <input type="number" name="<?php echo esc_attr( $meta_key ); ?>" id="<?php echo esc_attr( $meta_key ); ?>" value="<?php echo esc_attr( $value ); ?>" min="<?php echo esc_attr( $def['min'] ?? 0 ); ?>" max="<?php echo esc_attr( $def['max'] ?? 999 ); ?>" step="<?php echo esc_attr( $def['step'] ?? 1 ); ?>" class="widefat avg-setting anchor-builder__setting" />
            <?php elseif ( $type === 'checkbox' ) : ?>
                <label class="anchor-builder__checkbox">
                    <input type="checkbox" name="<?php echo esc_attr( $meta_key ); ?>" value="1" <?php checked( $value ); ?> class="avg-setting anchor-builder__setting" />
                    <strong><?php echo esc_html( $def['label'] ); ?></strong>
                </label>
            <?php elseif ( $type === 'text' ) : ?>
                <label for="<?php echo esc_attr( $meta_key ); ?>"><strong><?php echo esc_html( $def['label'] ); ?></strong></label>
                <input type="text" name="<?php echo esc_attr( $meta_key ); ?>" id="<?php echo esc_attr( $meta_key ); ?>" value="<?php echo esc_attr( $value ); ?>" class="widefat anchor-builder__setting" />
            <?php elseif ( $type === 'textarea' ) : ?>
                <label for="<?php echo esc_attr( $meta_key ); ?>"><strong><?php echo esc_html( $def['label'] ); ?></strong></label>
                <textarea name="<?php echo esc_attr( $meta_key ); ?>" id="<?php echo esc_attr( $meta_key ); ?>" rows="4" class="widefat anchor-builder__setting"><?php echo esc_textarea( $value ); ?></textarea>
            <?php elseif ( $type === 'color' ) : ?>
                <label for="<?php echo esc_attr( $meta_key ); ?>"><strong><?php echo esc_html( $def['label'] ); ?></strong></label>
                <input type="text" name="<?php echo esc_attr( $meta_key ); ?>" id="<?php echo esc_attr( $meta_key ); ?>" value="<?php echo esc_attr( $value ); ?>" class="anchor-builder__color-picker anchor-builder__setting" data-default-color="<?php echo esc_attr( $def['default'] ?? '' ); ?>" />
            <?php endif; ?>
            <?php if ( ! empty( $def['help'] ) ) : ?>
                <span class="anchor-builder__help"><?php echo esc_html( $def['help'] ); ?></span>
            <?php endif; ?>
        </p>
        <?php
    }
}
