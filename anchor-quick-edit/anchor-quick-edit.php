<?php
/**
 * Anchor Tools module: Anchor Quick Edit.
 * Quick Edit fields for Yoast SEO Title/Meta Description and a featured image editor.
 */

if (!defined('ABSPATH')) exit;

class Anchor_Quick_Edit_Module {
    const META_TITLE = '_yoast_wpseo_title';
    const META_DESC  = '_yoast_wpseo_metadesc';

    const NONCE_ACTION = 'ac_yqep_nonce_action';
    const NONCE_NAME   = 'ac_yqep_nonce';

    private $post_types = ['post', 'page'];

    public function __construct() {
        add_filter('manage_post_posts_columns', [$this, 'add_columns']);
        add_action('manage_post_posts_custom_column', [$this, 'render_columns'], 10, 2);

        add_filter('manage_page_posts_columns', [$this, 'add_columns']);
        add_action('manage_page_posts_custom_column', [$this, 'render_columns'], 10, 2);

        add_action('quick_edit_custom_box', [$this, 'render_quick_edit'], 10, 2);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('save_post', [$this, 'save_quick_edit'], 10, 2);

        add_action('wp_ajax_ac_yqep_get_featured', [$this, 'ajax_get_featured']);
        add_action('wp_ajax_ac_yqep_process_image', [$this, 'ajax_process_image']);
        add_action('wp_ajax_ac_yqep_set_featured', [$this, 'ajax_set_featured']);
        add_action('wp_ajax_ac_yqep_remove_featured', [$this, 'ajax_remove_featured']);
    }

    private function get_assets_url() {
        return ANCHOR_TOOLS_PLUGIN_URL . 'anchor-quick-edit/assets/';
    }

    public function add_columns($columns) {
        $columns['ac_yqep_yoast_title'] = 'Yoast SEO Title';
        $columns['ac_yqep_yoast_desc']  = 'Yoast Meta Description';
        $columns['ac_yqep_feat']        = 'Featured Image';
        return $columns;
    }

    public function render_columns($column, $post_id) {
        if ($column === 'ac_yqep_yoast_title') {
            $val = get_post_meta($post_id, self::META_TITLE, true);
            echo '<span class="ac-yqep-yoast-title-val">' . esc_html($val) . '</span>';
        }

        if ($column === 'ac_yqep_yoast_desc') {
            $val = get_post_meta($post_id, self::META_DESC, true);
            echo '<span class="ac-yqep-yoast-desc-val">' . esc_html($val) . '</span>';
        }

        if ($column === 'ac_yqep_feat') {
            $thumb_id = get_post_thumbnail_id($post_id);
            echo '<span class="ac-yqep-thumb-id" style="display:none;">' . esc_html((string)$thumb_id) . '</span>';
            if ($thumb_id) {
                $img = wp_get_attachment_image($thumb_id, [60, 60], true);
                echo '<span class="ac-yqep-thumb-preview">' . $img . '</span>';
            } else {
                echo '<span class="ac-yqep-thumb-preview">None</span>';
            }
        }
    }

    public function enqueue_admin_assets($hook) {
        if ($hook !== 'edit.php') return;

        $base = $this->get_assets_url();

        // WordPress media uploader
        wp_enqueue_media();

        // Cropper.js from CDN
        wp_enqueue_style('ac-yqep-cropper', 'https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.css', [], '1.6.2');
        wp_enqueue_script('ac-yqep-cropper', 'https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.js', [], '1.6.2', true);

        wp_enqueue_style('ac-yqep-admin', $base . 'admin.css', [], '1.0.1');
        wp_enqueue_script('ac-yqep-admin', $base . 'admin.js', ['jquery', 'ac-yqep-cropper'], '1.0.1', true);

        wp_localize_script('ac-yqep-admin', 'AC_YQEP', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(self::NONCE_ACTION),
            'postTypes' => $this->post_types,
            'i18n' => [
                'selectImage' => __('Select Featured Image', 'anchor-schema'),
                'useImage' => __('Set as Featured Image', 'anchor-schema'),
            ],
        ]);
    }

    public function render_quick_edit($column_name, $post_type) {
        if (!in_array($post_type, $this->post_types, true)) return;

        // Print once per row
        if ($column_name !== 'ac_yqep_yoast_title') return;

        wp_nonce_field('ac_yqep_quick_edit', 'ac_yqep_quick_edit_nonce');
        ?>
        <fieldset class="inline-edit-col-right">
            <div class="inline-edit-col">
                <h4>Yoast SEO</h4>

                <label class="inline-edit-group">
                    <span class="title">SEO Title</span>
                    <input type="text" name="<?php echo esc_attr(self::META_TITLE); ?>" value="" />
                </label>

                <label class="inline-edit-group" style="margin-top:8px;">
                    <span class="title">Meta Description</span>
                    <textarea name="<?php echo esc_attr(self::META_DESC); ?>" rows="3" style="width:100%;"></textarea>
                </label>

                <div style="margin-top:12px;">
                    <h4>Featured Image</h4>
                    <div class="ac-yqep-thumb-inline" style="margin-bottom:8px;"></div>
                    <div class="ac-yqep-feat-buttons">
                        <button type="button" class="button ac-yqep-select-featured">Select / Upload</button>
                        <button type="button" class="button ac-yqep-edit-featured">Edit Current</button>
                        <button type="button" class="button ac-yqep-remove-featured">Remove</button>
                    </div>
                    <input type="hidden" class="ac-yqep-post-id" value="" />
                </div>
            </div>
        </fieldset>

        <div class="ac-yqep-modal" aria-hidden="true">
            <div class="ac-yqep-modal__backdrop"></div>
            <div class="ac-yqep-modal__panel" role="dialog" aria-modal="true">
                <div class="ac-yqep-modal__header">
                    <strong>Featured Image Editor</strong>
                    <button type="button" class="button-link ac-yqep-close" aria-label="Close">&#10005;</button>
                </div>

                <div class="ac-yqep-modal__body">
                    <div class="ac-yqep-grid">
                        <div class="ac-yqep-left">
                            <div class="ac-yqep-image-wrap">
                                <img class="ac-yqep-image" src="" alt="" />
                            </div>
                            <div class="ac-yqep-row">
                                <button type="button" class="button ac-yqep-reset">Reset</button>
                                <button type="button" class="button ac-yqep-center">Center</button>
                            </div>
                        </div>

                        <div class="ac-yqep-right">
                            <div class="ac-yqep-field">
                                <label><strong>Output size</strong></label>
                                <div class="ac-yqep-row">
                                    <label>W <input type="number" class="ac-yqep-out-w" min="1" step="1" value="1200"></label>
                                    <label>H <input type="number" class="ac-yqep-out-h" min="1" step="1" value="1200"></label>
                                </div>
                                <label class="ac-yqep-checkbox">
                                    <input type="checkbox" class="ac-yqep-lock" checked> Lock aspect ratio to crop box
                                </label>
                            </div>

                            <div class="ac-yqep-field">
                                <label><strong>File type</strong></label>
                                <select class="ac-yqep-format">
                                    <option value="image/jpeg">JPEG</option>
                                    <option value="image/png">PNG</option>
                                    <option value="image/webp">WebP</option>
                                </select>
                                <p class="description">WebP depends on your server image editor support.</p>
                            </div>

                            <div class="ac-yqep-field">
                                <label><strong>Save mode</strong></label>
                                <label class="ac-yqep-radio">
                                    <input type="radio" name="ac_yqep_mode" value="copy" checked> Save as copy (new Media Library item)
                                </label>
                                <label class="ac-yqep-radio">
                                    <input type="radio" name="ac_yqep_mode" value="overwrite"> Overwrite existing file (regenerates sizes)
                                </label>
                                <p class="description"><strong>Overwrite</strong> is destructive. Consider backups.</p>
                            </div>

                            <div class="ac-yqep-field">
                                <button type="button" class="button button-primary ac-yqep-save">Save</button>
                                <span class="ac-yqep-status" aria-live="polite"></span>
                            </div>

                            <input type="hidden" class="ac-yqep-attachment-id" value="">
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function save_quick_edit($post_id, $post) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!isset($_POST['ac_yqep_quick_edit_nonce']) || !wp_verify_nonce($_POST['ac_yqep_quick_edit_nonce'], 'ac_yqep_quick_edit')) return;
        if (!current_user_can('edit_post', $post_id)) return;
        if (!in_array($post->post_type, $this->post_types, true)) return;

        if (isset($_POST[self::META_TITLE])) {
            update_post_meta($post_id, self::META_TITLE, sanitize_text_field(wp_unslash($_POST[self::META_TITLE])));
        }
        if (isset($_POST[self::META_DESC])) {
            update_post_meta($post_id, self::META_DESC, sanitize_textarea_field(wp_unslash($_POST[self::META_DESC])));
        }
    }

    public function ajax_get_featured() {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $post_id = isset($_POST['postId']) ? absint($_POST['postId']) : 0;
        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_send_json_error(['message' => 'Permission denied.'], 403);
        }

        $attachment_id = get_post_thumbnail_id($post_id);
        if (!$attachment_id) {
            wp_send_json_error(['message' => 'No featured image set.'], 400);
        }

        $src = wp_get_attachment_image_src($attachment_id, 'full');
        if (!$src) {
            wp_send_json_error(['message' => 'Unable to load image.'], 400);
        }

        wp_send_json_success([
            'attachmentId' => $attachment_id,
            'url' => $src[0],
            'width' => (int)$src[1],
            'height' => (int)$src[2],
            'mime' => get_post_mime_type($attachment_id),
        ]);
    }

    public function ajax_process_image() {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $post_id = isset($_POST['postId']) ? absint($_POST['postId']) : 0;
        $attachment_id = isset($_POST['attachmentId']) ? absint($_POST['attachmentId']) : 0;

        if (!$post_id || !$attachment_id) {
            wp_send_json_error(['message' => 'Missing post or attachment.'], 400);
        }
        if (!current_user_can('edit_post', $post_id) || !current_user_can('upload_files')) {
            wp_send_json_error(['message' => 'Permission denied.'], 403);
        }

        $mode   = isset($_POST['mode']) ? sanitize_key($_POST['mode']) : 'copy';
        $mime   = isset($_POST['mime']) ? sanitize_text_field($_POST['mime']) : 'image/jpeg';
        $out_w  = isset($_POST['outW']) ? max(1, absint($_POST['outW'])) : 1200;
        $out_h  = isset($_POST['outH']) ? max(1, absint($_POST['outH'])) : 1200;

        $crop = isset($_POST['crop']) ? json_decode(wp_unslash($_POST['crop']), true) : null;
        if (!is_array($crop) || !isset($crop['x'], $crop['y'], $crop['width'], $crop['height'])) {
            wp_send_json_error(['message' => 'Invalid crop data.'], 400);
        }

        $file = get_attached_file($attachment_id);
        if (!$file || !file_exists($file)) {
            wp_send_json_error(['message' => 'Original file missing.'], 400);
        }

        $editor = wp_get_image_editor($file);
        if (is_wp_error($editor)) {
            wp_send_json_error(['message' => 'Image editor unavailable: ' . $editor->get_error_message()], 500);
        }

        // Confirm mime support
        $supported = wp_get_image_editor_output_format($file, $mime);
        if (empty($supported) || empty($supported['mime-type'])) {
            wp_send_json_error(['message' => 'Requested output format not supported by your server.'], 400);
        }
        $target_mime = $supported['mime-type'];

        $src_x = (int) round($crop['x']);
        $src_y = (int) round($crop['y']);
        $src_w = (int) round($crop['width']);
        $src_h = (int) round($crop['height']);

        if ($src_w < 1 || $src_h < 1) {
            wp_send_json_error(['message' => 'Crop area too small.'], 400);
        }

        $result = $editor->crop($src_x, $src_y, $src_w, $src_h, $out_w, $out_h);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => 'Crop failed: ' . $result->get_error_message()], 500);
        }

        $uploads = wp_upload_dir();
        $subdir  = dirname(get_post_meta($attachment_id, '_wp_attached_file', true));
        if ($subdir === '.') $subdir = '';
        $base_dir = trailingslashit($uploads['basedir']) . ($subdir ? trailingslashit($subdir) : '');

        $orig_pathinfo = pathinfo($file);
        $base_name = $orig_pathinfo['filename'];
        $ext = $this->mime_to_ext($target_mime);
        if (!$ext) $ext = $orig_pathinfo['extension'];

        if ($mode === 'overwrite') {
            // Overwrite on server: we may keep same name or change extension if needed.
            $new_filename = $base_name . '.' . $ext;
            $new_path = $base_dir . $new_filename;

            $saved = $editor->save($new_path, $target_mime);
            if (is_wp_error($saved)) {
                wp_send_json_error(['message' => 'Save failed: ' . $saved->get_error_message()], 500);
            }

            // If path changed, update attached file and mime, delete old file.
            $rel = ($subdir ? trailingslashit($subdir) : '') . $new_filename;

            if ($new_path !== $file && file_exists($file)) {
                @unlink($file);
            }

            update_attached_file($attachment_id, $new_path);
            wp_update_post([
                'ID' => $attachment_id,
                'post_mime_type' => $target_mime,
            ]);

            $meta = wp_generate_attachment_metadata($attachment_id, $new_path);
            if (!is_wp_error($meta) && is_array($meta)) {
                wp_update_attachment_metadata($attachment_id, $meta);
            }

            wp_send_json_success([
                'message' => 'Overwritten successfully.',
                'attachmentId' => $attachment_id,
                'newUrl' => wp_get_attachment_url($attachment_id),
            ]);
        }

        // Save as copy (new attachment)
        $new_filename = $base_name . '-edited-' . wp_generate_password(6, false, false) . '.' . $ext;
        $new_path = $base_dir . $new_filename;

        $saved = $editor->save($new_path, $target_mime);
        if (is_wp_error($saved)) {
            wp_send_json_error(['message' => 'Save failed: ' . $saved->get_error_message()], 500);
        }

        $rel = ($subdir ? trailingslashit($subdir) : '') . $new_filename;
        $attachment = [
            'post_mime_type' => $target_mime,
            'post_title'     => $base_name . ' (edited)',
            'post_content'   => '',
            'post_status'    => 'inherit',
        ];

        $new_attachment_id = wp_insert_attachment($attachment, $new_path);
        if (is_wp_error($new_attachment_id) || !$new_attachment_id) {
            wp_send_json_error(['message' => 'Failed to create attachment record.'], 500);
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        $meta = wp_generate_attachment_metadata($new_attachment_id, $new_path);
        if (!is_wp_error($meta) && is_array($meta)) {
            wp_update_attachment_metadata($new_attachment_id, $meta);
        }

        set_post_thumbnail($post_id, $new_attachment_id);

        wp_send_json_success([
            'message' => 'Saved as copy and set as featured image.',
            'attachmentId' => $new_attachment_id,
            'newUrl' => wp_get_attachment_url($new_attachment_id),
        ]);
    }

    private function mime_to_ext($mime) {
        $map = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
        ];
        return isset($map[$mime]) ? $map[$mime] : '';
    }

    public function ajax_set_featured() {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $post_id = isset($_POST['postId']) ? absint($_POST['postId']) : 0;
        $attachment_id = isset($_POST['attachmentId']) ? absint($_POST['attachmentId']) : 0;

        if (!$post_id || !$attachment_id) {
            wp_send_json_error(['message' => 'Missing post or attachment ID.'], 400);
        }
        if (!current_user_can('edit_post', $post_id)) {
            wp_send_json_error(['message' => 'Permission denied.'], 403);
        }

        // Verify it's a valid image attachment
        if (!wp_attachment_is_image($attachment_id)) {
            wp_send_json_error(['message' => 'Selected file is not an image.'], 400);
        }

        set_post_thumbnail($post_id, $attachment_id);

        $thumb_url = get_the_post_thumbnail_url($post_id, [60, 60]);

        wp_send_json_success([
            'message' => 'Featured image set.',
            'attachmentId' => $attachment_id,
            'thumbUrl' => $thumb_url,
        ]);
    }

    public function ajax_remove_featured() {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $post_id = isset($_POST['postId']) ? absint($_POST['postId']) : 0;

        if (!$post_id) {
            wp_send_json_error(['message' => 'Missing post ID.'], 400);
        }
        if (!current_user_can('edit_post', $post_id)) {
            wp_send_json_error(['message' => 'Permission denied.'], 403);
        }

        delete_post_thumbnail($post_id);

        wp_send_json_success([
            'message' => 'Featured image removed.',
        ]);
    }
}
