<?php
/**
 * Anchor Tools module: Anchor Mega Menu.
 */

if (!defined('ABSPATH')) { exit; }

class Anchor_Mega_Menu_Module {
    const CPT = 'anchor_mega_snippet';
    const LEGACY_CPT = 'mm_snippet';
    const MIGRATION_FLAG = 'anchor_mega_menu_migrated';
    const NONCE = 'anchor_mega_snippet_nonce';
    const GLOBAL_CSS_OPTION = 'anchor_mega_menu_global_css';

    public function __construct(){
        add_action('init', [$this, 'register_cpt']);
        add_action('add_meta_boxes', [$this, 'add_metaboxes']);
        add_action('save_post', [$this, 'save_meta']);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        add_action('wp_enqueue_scripts', [$this, 'frontend_assets']);
        add_shortcode('mm_snippet', [$this, 'shortcode_render']);
        add_shortcode('anchor_mega_snippet', [$this, 'shortcode_render']);

        add_filter('manage_' . self::CPT . '_posts_columns', [$this, 'add_admin_columns']);
        add_action('manage_' . self::CPT . '_posts_custom_column', [$this, 'render_admin_column'], 10, 2);
    }

    public function register_cpt(){
        $this->maybe_migrate_legacy_posts();

        $labels = [
            'name' => 'Anchor Mega Snippets',
            'singular_name' => 'Anchor Mega Snippet',
            'add_new_item' => 'Add New Anchor Mega Snippet',
            'edit_item' => 'Edit Anchor Mega Snippet',
            'menu_name' => 'Anchor Mega Snippets',
        ];
        register_post_type(self::CPT, [
            'labels' => $labels,
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => apply_filters('anchor_mega_menu_parent_menu', true),
            'menu_icon' => 'dashicons-editor-code',
            'supports' => ['title'],
        ]);
    }

    private function maybe_migrate_legacy_posts(){
        if ( get_option(self::MIGRATION_FLAG) ) {
            return;
        }
        global $wpdb;
        $updated = $wpdb->update(
            $wpdb->posts,
            ['post_type' => self::CPT],
            ['post_type' => self::LEGACY_CPT]
        );
        if ( false !== $updated ) {
            update_option(self::MIGRATION_FLAG, 1);
        }
    }

    public function add_metaboxes(){
        add_meta_box('mm_snippet_code', 'Snippet Code (HTML / CSS / JS)', [$this, 'render_box_code'], self::CPT, 'normal', 'high');
        add_meta_box('mm_snippet_settings', 'Behavior & Appearance', [$this, 'render_box_settings'], self::CPT, 'side');
        add_meta_box('mm_snippet_preview', 'Live Preview', [$this, 'render_box_preview'], self::CPT, 'normal', 'default');
    }

    private function get_meta($post_id){
        $defaults = [
            'html' => '',
            'css'  => '',
            'js'   => '',
            'trigger_class' => '',
            'position' => 'below',      // below | above | left | right
            'absolute' => '1',
            'hover_delay' => 200,
            'max_height' => 400,
            'animation' => 'fade',      // fade | slide | scale | flip
            'z_index' => 9999,
            'offset_x' => 0,
            'offset_y' => 8,
            // Arrow options
            'arrow' => '0',             // 1 = show arrow
            'arrow_color' => '#ffffff',
            'arrow_size' => 10,
            'arrow_align' => 'auto',    // auto | center | start | end
            'arrow_offset' => 0,
        ];
        $meta = [];
        foreach ($defaults as $k => $v){
            $meta[$k] = get_post_meta($post_id, "mm_$k", true);
            if ($meta[$k] === '') { $meta[$k] = $v; }
        }
        return $meta;
    }

    public function render_box_code($post){
        wp_nonce_field(self::NONCE, self::NONCE);
        $m = $this->get_meta($post->ID);
        $global_css = get_option(self::GLOBAL_CSS_OPTION, '');
        wp_enqueue_code_editor(array('type' => 'text/html'));
        wp_enqueue_code_editor(array('type' => 'text/css'));
        wp_enqueue_code_editor(array('type' => 'application/javascript'));
        wp_enqueue_script('code-editor');
        wp_enqueue_style('code-editor');
        ?>
        <div class="mm-fields">
            <div class="mm-field">
                <label for="mm_html"><strong>HTML</strong></label>
                <textarea id="mm_html" name="mm_html" rows="10" class="widefat code"><?php echo esc_textarea($m['html']); ?></textarea>
            </div>
            <div class="mm-field">
                <label for="mm_global_css"><strong>Global CSS (applies to all snippets)</strong></label>
                <textarea id="mm_global_css" name="mm_global_css" rows="6" class="widefat code"><?php echo esc_textarea($global_css); ?></textarea>
            </div>
            <div class="mm-field">
                <label for="mm_css"><strong>CSS</strong></label>
                <textarea id="mm_css" name="mm_css" rows="8" class="widefat code"><?php echo esc_textarea($m['css']); ?></textarea>
            </div>
            <div class="mm-field">
                <label for="mm_js"><strong>JavaScript</strong></label>
                <textarea id="mm_js" name="mm_js" rows="8" class="widefat code"><?php echo esc_textarea($m['js']); ?></textarea>
            </div>
        </div>
        <?php
    }

    public function render_box_settings($post){
        $m = $this->get_meta($post->ID);
        ?>
        <style>
            .mm-side label{ display:block; margin-top:8px; font-weight:600; }
            .mm-side input[type="number"], .mm-side input[type="text"], .mm-side select, .mm-side input[type="color"]{ width:100%; }
            .description{ color:#666; font-size:12px; }
            .mm-grid{ display:grid; grid-template-columns: 1fr 1fr; gap:8px; }
        </style>
        <div class="mm-side">
            <label>Trigger class (without dot)</label>
            <input type="text" name="mm_trigger_class" value="<?php echo esc_attr($m['trigger_class']); ?>" placeholder="e.g. my-mega-trigger" />
            <p class="description">Add this class to any element on your site to attach this panel.</p>

            <label>Position</label>
            <select name="mm_position">
                <?php foreach (['below'=>'Below','above'=>'Above','left'=>'Left','right'=>'Right'] as $k=>$label): ?>
                    <option value="<?php echo esc_attr($k); ?>" <?php selected($m['position'], $k); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>

            <label>Absolute positioning?</label>
            <select name="mm_absolute">
                <option value="1" <?php selected($m['absolute'],'1'); ?>>Yes (append to body, absolute)</option>
                <option value="0" <?php selected($m['absolute'],'0'); ?>>No (position fixed to viewport)</option>
            </select>

            <label>Hover close delay (ms)</label>
            <input type="number" min="0" step="50" name="mm_hover_delay" value="<?php echo esc_attr($m['hover_delay']); ?>" />

            <label>Viewport max height (px)</label>
            <input type="number" min="100" step="10" name="mm_max_height" value="<?php echo esc_attr($m['max_height']); ?>" />

            <label>Z-index</label>
            <input type="number" name="mm_z_index" value="<?php echo esc_attr($m['z_index']); ?>" />

            <div class="mm-grid">
              <div>
                <label>Offset X (px)</label>
                <input type="number" name="mm_offset_x" value="<?php echo esc_attr($m['offset_x']); ?>" />
              </div>
              <div>
                <label>Offset Y (px)</label>
                <input type="number" name="mm_offset_y" value="<?php echo esc_attr($m['offset_y']); ?>" />
              </div>
            </div>

            <label>Animation</label>
            <select name="mm_animation">
                <?php foreach (['fade'=>'Fade','slide'=>'Slide','scale'=>'Scale','flip'=>'Flip'] as $k=>$label): ?>
                    <option value="<?php echo esc_attr($k); ?>" <?php selected($m['animation'], $k); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>

            <hr/>
            <h4 style="margin:8px 0 0;">Arrow</h4>

            <label>Show arrow?</label>
            <select name="mm_arrow">
                <option value="0" <?php selected($m['arrow'],'0'); ?>>No</option>
                <option value="1" <?php selected($m['arrow'],'1'); ?>>Yes</option>
            </select>

            <div class="mm-grid">
              <div>
                <label>Arrow color</label>
                <input type="color" name="mm_arrow_color" value="<?php echo esc_attr($m['arrow_color']); ?>" />
              </div>
              <div>
                <label>Arrow size (px)</label>
                <input type="number" min="4" step="1" name="mm_arrow_size" value="<?php echo esc_attr($m['arrow_size']); ?>" />
              </div>
            </div>

            <label>Arrow position along edge</label>
            <select name="mm_arrow_align">
                <?php foreach (['auto'=>'Auto (aim at trigger)','center'=>'Center','start'=>'Start','end'=>'End'] as $k=>$label): ?>
                    <option value="<?php echo esc_attr($k); ?>" <?php selected($m['arrow_align'], $k); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>

            <label>Arrow offset (px)</label>
            <input type="number" step="1" name="mm_arrow_offset" value="<?php echo esc_attr($m['arrow_offset']); ?>" />
            <p class="description">Positive = right (top/bottom edges) or down (left/right edges).</p>
        </div>
        <?php
    }

    public function render_box_preview($post){
        ?>
        <p class="description">Live preview renders your HTML/CSS/JS below inside a contained viewport so you can test scrolling and interactions.</p>
        <div id="mm-preview-wrap">
            <div id="mm-preview-viewport" style="border:1px solid #ccd0d4; border-radius:8px; overflow:auto; max-height:400px; background:#fff;">
                <div id="mm-preview-content" style="padding:16px;"></div>
            </div>
        </div>
        <?php
    }

    public function save_meta($post_id){
        if (!isset($_POST[self::NONCE]) || !wp_verify_nonce($_POST[self::NONCE], self::NONCE)) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        if (isset($_POST['mm_global_css'])) {
            update_option(self::GLOBAL_CSS_OPTION, $_POST['mm_global_css']);
        }

        $fields = ['html','css','js','trigger_class','position','absolute','hover_delay','max_height','animation','z_index','offset_x','offset_y',
                   'arrow','arrow_color','arrow_size','arrow_align','arrow_offset'];
        foreach ($fields as $f){
            $key = "mm_$f";
            $val = isset($_POST[$key]) ? $_POST[$key] : '';
            update_post_meta($post_id, $key, $val);
        }
    }

    private function get_published_snippets(){
        $q = new WP_Query([
            'post_type' => self::CPT,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'no_found_rows' => true,
        ]);
        $items = [];
        foreach ($q->posts as $p){
            $meta = $this->get_meta($p->ID);
            $items[] = [
                'id' => (int)$p->ID,
                'title' => $p->post_title,
                'trigger_class' => $meta['trigger_class'],
                'settings' => [
                    'position' => $meta['position'],
                    'absolute' => $meta['absolute'] === '1',
                    'hoverDelay' => (int)$meta['hover_delay'],
                    'maxHeight' => (int)$meta['max_height'],
                    'animation' => $meta['animation'],
                    'zIndex' => (int)$meta['z_index'],
                    'offsetX' => (int)$meta['offset_x'],
                    'offsetY' => (int)$meta['offset_y'],
                    'arrow' => ($meta['arrow'] === '1'),
                    'arrowColor' => $meta['arrow_color'],
                    'arrowSize' => (int)$meta['arrow_size'],
                    'arrowAlign' => $meta['arrow_align'],
                    'arrowOffset' => (int)$meta['arrow_offset'],
                ],
                'html' => $meta['html'],
                'css' => $meta['css'],
                'js' => $meta['js'],
            ];
        }
        return $items;
    }

    public function admin_assets($hook){
        global $post;
        if (($hook === 'post-new.php' || $hook === 'post.php') && isset($post) && $post->post_type === self::CPT){
            wp_enqueue_style('mm-admin', plugins_url('admin.css', __FILE__), [], '1.1.4');
            wp_enqueue_script('mm-admin', plugins_url('admin.js', __FILE__), ['jquery', 'code-editor'], '1.1.4', true);
        }
    }

    public function frontend_assets(){
        $snippets = $this->get_published_snippets();
        if (empty($snippets)) return;
        wp_enqueue_style('mm-frontend', plugins_url('frontend.css', __FILE__), [], '1.1.4');
        wp_enqueue_script('mm-frontend', plugins_url('frontend.js', __FILE__), [], '1.1.4', true);
        $global_css = get_option(self::GLOBAL_CSS_OPTION, '');
        if ($global_css !== '') {
            wp_add_inline_style('mm-frontend', $global_css);
        }
        wp_localize_script('mm-frontend', 'MM_SNIPPETS', $snippets);
    }

    public function shortcode_render($atts){
        $atts = shortcode_atts(['id' => 0], $atts);
        $post_id = (int)$atts['id'];
        if (!$post_id) return '';
        $this->ensure_embed_assets();
        $meta = $this->get_meta($post_id);
        $max_h = (int)$meta['max_height'];
        $html  = $meta['html'];
        $css   = trim($meta['css']);
        $js    = trim($meta['js']);

        if ($css !== '') {
            wp_add_inline_style('mm-frontend', "\n/* Mega Menu snippet {$post_id} */\n{$css}");
        }
        if ($js !== '') {
            wp_add_inline_script('mm-frontend', "(function(){try{ {$js} }catch(e){console.error(e);}})();");
        }

        ob_start(); ?>
        <div class="mm-snippet-inline" data-mm-id="<?php echo (int)$post_id; ?>" style="--mm-max-h: <?php echo esc_attr($max_h); ?>px;">
            <?php echo $html; ?>
        </div>
        <?php return ob_get_clean();
    }

    private function ensure_embed_assets(){
        if (!wp_style_is('mm-frontend', 'enqueued')) {
            wp_enqueue_style('mm-frontend', plugins_url('frontend.css', __FILE__), [], '1.1.4');
            $global_css = get_option(self::GLOBAL_CSS_OPTION, '');
            if ($global_css !== '') {
                wp_add_inline_style('mm-frontend', $global_css);
            }
        }
    }

    public function add_admin_columns($columns){
        $new = [];
        foreach ($columns as $key => $label){
            $new[$key] = $label;
            if ($key === 'title'){
                $new['mm_trigger'] = __('Trigger', 'anchor-schema');
                $new['mm_shortcode'] = __('Shortcode', 'anchor-schema');
            }
        }
        return $new;
    }

    public function render_admin_column($column, $post_id){
        if ($column === 'mm_trigger'){
            $meta = $this->get_meta($post_id);
            $trigger = trim($meta['trigger_class']);
            if ($trigger){
                $classes = preg_split('/\s+/', $trigger);
                $formatted = array_map(function($c){
                    return '<code>.' . esc_html($c) . '</code>';
                }, array_filter($classes));
                echo implode('<br/>', $formatted);
            } else {
                echo '<span class="dashicons dashicons-clock"></span> ' . esc_html__('Page load / manual trigger', 'anchor-schema');
            }
            return;
        }

        if ($column === 'mm_shortcode'){
            $shortcode = '[anchor_mega_snippet id="' . (int)$post_id . '"]';
            echo '<code>' . esc_html($shortcode) . '</code>';
            return;
        }
    }
}
