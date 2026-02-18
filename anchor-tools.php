<?php
/**
 * Plugin Name: Anchor Tools
 * Description: A set of tools provided by Anchor Corps. Lightweight Mega Menu, Popups, and bulk content editing using AI
 * Version: 3.4.69
 * Author: Anchor Corps
 * Text Domain: anchor-tools
 */

use Dotenv\Dotenv;
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'ANCHOR_TOOLS_PLUGIN_FILE', __FILE__ );
define( 'ANCHOR_TOOLS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ANCHOR_TOOLS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );


if ( ! defined( 'ANCHOR_SCHEMA_VERSION' ) ) {
    define( 'ANCHOR_SCHEMA_VERSION', '1.0.3' );
}
if ( ! defined( 'ANCHOR_SCHEMA_DIR' ) ) {
    define( 'ANCHOR_SCHEMA_DIR', ANCHOR_TOOLS_PLUGIN_DIR );
}
if ( ! defined( 'ANCHOR_SCHEMA_URL' ) ) {
    define( 'ANCHOR_SCHEMA_URL', ANCHOR_TOOLS_PLUGIN_URL );
}

$acg_autoload = ANCHOR_TOOLS_PLUGIN_DIR . 'vendor/autoload.php';
if ( file_exists( $acg_autoload ) ) {
    require_once $acg_autoload;
}

if ( class_exists( Dotenv::class ) && file_exists( ANCHOR_TOOLS_PLUGIN_DIR . '.env' ) ) {
    $dotenv = Dotenv::createImmutable( ANCHOR_TOOLS_PLUGIN_DIR );
    $dotenv->safeLoad();
    
}

if ( ! class_exists( 'Anchor_Schema_Logger' ) ) {
    require_once ANCHOR_TOOLS_PLUGIN_DIR . 'includes/class-anchor-schema-logger.php';
}
if ( ! class_exists( 'Anchor_Schema_Helper' ) ) {
    require_once ANCHOR_TOOLS_PLUGIN_DIR . 'includes/class-anchor-schema-helper.php';
}
if ( ! class_exists( 'Anchor_Schema_Admin' ) ) {
    require_once ANCHOR_TOOLS_PLUGIN_DIR . 'includes/class-anchor-schema-admin.php';
}
if ( ! class_exists( 'Anchor_Schema_Render' ) ) {
    require_once ANCHOR_TOOLS_PLUGIN_DIR . 'includes/class-anchor-schema-render.php';
}
if ( ! class_exists( 'Anchor_Reviews_Google_Provider' ) ) {
    require_once ANCHOR_TOOLS_PLUGIN_DIR . 'includes/class-anchor-reviews-provider-google.php';
}
if ( ! class_exists( 'Anchor_Reviews_Manager' ) ) {
    require_once ANCHOR_TOOLS_PLUGIN_DIR . 'includes/class-anchor-reviews.php';
}

if ( class_exists( PucFactory::class ) ) {
    $anchor_tools_update = PucFactory::buildUpdateChecker(
        'https://github.com/joelhmartin/Anchor-Tools/',
        __FILE__,
        'anchor-tools'
    );
    $anchor_tools_update->setBranch( 'main' );

    $anchor_tools_token = $_ENV['GITHUB_ACCESS_TOKEN']
        ?? getenv( 'GITHUB_ACCESS_TOKEN' )
        ?: ( defined( 'GITHUB_ACCESS_TOKEN' ) ? GITHUB_ACCESS_TOKEN : null );

    if ( $anchor_tools_token ) {
        $anchor_tools_update->setAuthentication( $anchor_tools_token );
    }

    $anchor_tools_vcs = method_exists( $anchor_tools_update, 'getVcsApi' ) ? $anchor_tools_update->getVcsApi() : null;
    if ( $anchor_tools_vcs && method_exists( $anchor_tools_vcs, 'enableReleaseAssets' ) ) {
        $anchor_tools_vcs->enableReleaseAssets();
    }

    add_filter(
        'upgrader_pre_download',
        function( $reply, $package ) {
            error_log( '[Anchor Tools] pre_download package=' . $package );
            return $reply;
        },
        10,
        2
    );
    add_filter(
        'upgrader_source_selection',
        function( $source ) {
            error_log( '[Anchor Tools] source_selection source=' . $source );
            return $source;
        },
        10,
        1
    );
}

add_action(
    'init',
    function() {
        load_plugin_textdomain( 'anchor-schema', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    }
);

add_action(
    'plugins_loaded',
    function() {
        if ( is_admin() && class_exists( 'Anchor_Schema_Admin' ) ) {
            new Anchor_Schema_Admin();
        }
        if ( class_exists( 'Anchor_Schema_Render' ) ) {
            new Anchor_Schema_Render();
        }
        if ( class_exists( 'Anchor_Reviews_Manager' ) ) {
            new Anchor_Reviews_Manager();
        }
    }
);

class AI_ACF_Bulk_Rewriter_Wizard
{
    private $option_key = "ai_rewriter_api_key";
    private $legacy_option_key = "divi_ai_api_key";
    private $nonce_action = "ai_bulk_rewriter_nonce_action";
    private $nonce_name = "ai_bulk_rewriter_nonce";
    private $transient_ttl_min = 45; // preview cache TTL

    public function __construct()
    {
        add_action("admin_menu", [$this, "register_menus"]);
        add_action("admin_enqueue_scripts", [$this, "enqueue_admin"]);

        // AJAX
        add_action("wp_ajax_ai_br_bulk_preview", [$this, "ajax_bulk_preview"]);
        add_action("wp_ajax_ai_br_get_staged_for_post", [
            $this,
            "ajax_get_staged_for_post",
        ]);
        add_action("wp_ajax_ai_br_bulk_apply_approved", [
            $this,
            "ajax_bulk_apply_approved",
        ]);
        add_action("wp_ajax_ai_br_bulk_apply", [$this, "ajax_bulk_apply"]);
        add_action("wp_ajax_ai_br_bulk_queue", [$this, "ajax_bulk_queue"]);
        add_action("wp_ajax_ai_br_queue_status", [$this, "ajax_bulk_queue_status"]);
        add_action("anchor_tools_bulk_rewrite_process", [$this, "process_bulk_queue"], 10, 1);
    }

    /* ---------------- Menus & Settings ---------------- */

    public function register_menus()
    {
        add_submenu_page(
            "tools.php",
            "AI Bulk Rewriter",
            "AI Bulk Rewriter",
            "manage_options",
            "ai-bulk-rewriter",
            [$this, "bulk_page"]
        );
    }

    /* ---------------- Assets ---------------- */

    public function enqueue_admin($hook)
    {
        if (!isset($_GET["page"]) || $_GET["page"] !== "ai-bulk-rewriter") {
            return;
        }

        wp_enqueue_script("jquery");

        if (!wp_style_is("ai-br-admin-css", "registered")) {
            wp_register_style("ai-br-admin-css", "", [], "1.0");
        }
        wp_enqueue_style("ai-br-admin-css");

        $css = '
            .ai-br-card{background:#fff;border:1px solid #e5e7eb;border-radius:6px;padding:12px;margin:12px 0;}
            .ai-br-flex{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
            .ai-br-table th,.ai-br-table td{padding:8px;border-bottom:1px solid #eee;vertical-align:top;}
            .ai-br-progress{height:8px;background:#eee;border-radius:4px;overflow:hidden;margin-top:8px}
            .ai-br-bar{height:100%;width:0;background:#2271b1;transition:width .2s;}
            .ai-br-mono{font-family:monospace;font-size:12px;}
            .ai-br-small{font-size:12px;color:#555;}
            .ai-br-textarea{width:100%;min-height:180px;font-family:monospace;}
            .ai-br-edit{width:100%;min-height:120px;font-family:monospace;}
            .ai-jsonld-editor{width:100%;min-height:220px;font-family:monospace;background:#0f172a;color:#e2e8f0;border:1px solid #1e293b;border-radius:6px;padding:10px;}
            .ai-jsonld-warning{color:#b45309;font-size:12px;margin:6px 0;}
            .ai-jsonld-badge{display:inline-flex;align-items:center;background:#7c3aed;color:#fff;padding:2px 8px;border-radius:999px;font-size:11px;margin-left:8px;text-transform:uppercase;letter-spacing:0.05em;}
            /* Modal Wizard */
            .ai-br-overlay{position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,.45);display:none;align-items:center;justify-content:center}
            .ai-br-wizard{width:min(1200px,95vw);max-height:90vh;overflow:auto;background:#fff;border-radius:10px;box-shadow:0 10px 40px rgba(0,0,0,.3);padding:16px;}
            .ai-br-w-hd{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px}
            .ai-br-w-tt{font-size:18px;font-weight:600}
            .ai-br-w-body{display:grid;grid-template-columns:1fr 1fr;gap:12px}
            .ai-br-panel{border:1px solid #e5e7eb;border-radius:8px;padding:10px;overflow:auto;max-height:60vh}
            .ai-br-row{margin-bottom:12px;border-bottom:1px dashed #eee;padding-bottom:10px}
            .ai-br-row label{font-weight:600;display:block;margin-bottom:6px}
            .ai-br-actions{display:flex;gap:8px;align-items:center;justify-content:space-between;margin-top:12px}
            .button-danger{background:#b91c1c;color:#fff;border-color:#7f1d1d}
        ';
        wp_add_inline_style("ai-br-admin-css", $css);
    }

    /* ---------------- Bulk UI ---------------- */

    private function default_prompt_template()
    {
        return trim(
            <<<'EOT'
System:
You are a professional copywriter. Rewrite the provided CONTENT to be unique, natural, and accurate. Shortcodes and URLs must remain unchanged. Keep length roughly similar (+/- 20%). Never add content the user would not expect in context.

Output Mode: {{OUTPUT_MODE}}
- If TEXT_ONLY: return plain text only (no HTML, no Markdown, no code fences). Do NOT add headings or paragraphs.
- If HTML_FRAGMENT: preserve the HTML tags and structure types (e.g., <h2> stays <h2>); return raw HTML only (no Markdown, no code fences).

Context (for personalization):
- Post Title: {{POST_TITLE}}
- Field: {{FIELD_LABEL}} (type: {{FIELD_TYPE}})

SEO:
- Focus keywords (use naturally if relevant): {{KEYWORDS}}
- If Optimize for SEO is ON, include one focus keyword naturally early in the content when appropriate. Avoid keyword stuffing.

Target length hint: about {{TARGET_CHARS}} characters (±20%).

User:
Original CONTENT:
{{ORIGINAL_HTML}}

Guidelines:
- Leave tokens like %%SHORTCODE_X%% unchanged.
- Shortcodes present (do not change): {{SHORTCODES_LIST}}
- Maintain meaning and accuracy.
- Do not wrap output in triple backticks or Markdown fences.
- Never use em dashes; use commas and periods instead.
- Ignore quotations — keep quoted text and its outbound links exactly as provided.
EOT
        );
    }

    private function default_seo_prompt_template()
    {
        return trim(
            <<<'EOT'
System:
You are an SEO copywriter. Rewrite the provided SEO fields clearly, naturally, and concisely. Do not add placeholders or site-wide variables. Keep Yoast variables (%%...%%) unchanged.

Output:
- Return plain text only (no HTML, no Markdown).
- Respect the intent of the field (Title or Meta Description) and keep length reasonable for SERP display.
- Do NOT include labels or prefixes like "Title:" or "Meta Description:" in the output.

User:
Original FIELD ({{FIELD_LABEL}}):
{{ORIGINAL_HTML}}
EOT
        );
    }

    public function bulk_page()
    {
        if (!current_user_can("manage_options")) {
            return;
        }

        $nonce = wp_create_nonce($this->nonce_action);
        $schema_types = array_slice(Anchor_Schema_Helper::get_schema_types(), 0, 40);

        // Filters
        $default_types = array_keys(get_post_types(["public" => true]));
        $sel_types = isset($_GET["types"])
            ? (array) $_GET["types"]
            : $default_types;
        $search = isset($_GET["s"]) ? sanitize_text_field($_GET["s"]) : "";
        $cat = isset($_GET["cat"]) ? intval($_GET["cat"]) : 0;
        $per_page = isset($_GET["per_page"])
            ? max(5, intval($_GET["per_page"]))
            : 20;
        $paged = isset($_GET["paged"]) ? max(1, intval($_GET["paged"])) : 1;

        $args = [
            "post_type" => $sel_types,
            "posts_per_page" => $per_page,
            "paged" => $paged,
            "s" => $search,
            "post_status" => ["publish", "draft", "pending", "future"],
        ];
        if ($cat && taxonomy_exists("category")) {
            $args["cat"] = $cat;
        }

        $q = new WP_Query($args);
        $types = get_post_types(["public" => true], "objects");
        ?>

        <div class="wrap">
            <h1>AI Bulk Rewriter</h1>

            <div class="ai-br-card ai-br-small">
                Flow: <strong>Preview Selected</strong> → <strong>Review & Approve (Wizard)</strong> → approve items → <strong>Apply Approved</strong>.  
                Or use <strong>Apply Selected (Skip Preview)</strong> for immediate save. Shortcodes are preserved.
            </div>

            <div class="ai-br-card" id="ai_prompt_card">
                <div class="ai-br-flex">
                    <label>
                        <strong>Workflow mode</strong>
                        <select id="ai_bulk_mode">
                            <option value="content" selected>ACF Fields</option>
                            <option value="post_content">Blog Content</option>
                            <option value="seo_meta">Bulk SEO + Meta</option>
                            <option value="jsonld">JSON-LD Schema</option>
                        </select>
                    </label>
                    <label class="ai-jsonld-only" style="display:none;">
                        <strong>Schema type</strong>
                        <select id="ai_jsonld_type">
                            <?php foreach ( $schema_types as $schema_type ) : ?>
                                <option value="<?php echo esc_attr( $schema_type ); ?>" <?php selected( $schema_type, 'Article' ); ?>>
                                    <?php echo esc_html( $schema_type ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="ai-jsonld-only" style="display:none;">
                        <strong>Custom @type override</strong>
                        <input type="text" id="ai_jsonld_custom" placeholder="e.g., Dentist" />
                    </label>
                </div>
                <p class="ai-br-small ai-jsonld-only" style="display:none;">JSON-LD mode pulls ACF content for context, then lets you edit the generated JSON before saving.</p>
            </div>

            <div class="ai-br-card">
                <form method="get" class="ai-br-flex">
                    <input type="hidden" name="page" value="ai-bulk-rewriter">
                    <label><strong>Post types:</strong></label>
                    <?php foreach ($types as $slug => $obj): ?>
                        <label><input type="checkbox" name="types[]" value="<?php echo esc_attr(
                            $slug
                        ); ?>" <?php checked(
    in_array($slug, $sel_types)
); ?>> <?php echo esc_html($obj->labels->name); ?></label>
                    <?php endforeach; ?>

                    <label><strong>Search:</strong>
                        <input type="text" name="s" value="<?php echo esc_attr(
                            $search
                        ); ?>" placeholder="Title contains…">
                    </label>

                    <?php if (taxonomy_exists("category")): ?>
                        <label><strong>Category:</strong>
                            <?php wp_dropdown_categories([
                                "show_option_all" => "— Any —",
                                "name" => "cat",
                                "selected" => $cat,
                                "hide_empty" => false,
                            ]); ?>
                        </label>
                    <?php endif; ?>

                    <label><strong>Per page:</strong>
                        <select name="per_page">
                            <?php foreach ([10, 20, 50, 100, 200] as $n): ?>
                                <option value="<?php echo $n; ?>" <?php selected(
    $per_page,
    $n
); ?>><?php echo $n; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <button class="button button-primary">Apply Filters</button>
                </form>
            </div>

            <div class="ai-br-card" id="ai_prompt_inputs_card">
                <div class="ai-br-flex">
                    <label style="flex:1;">
                        <strong>Focus keywords</strong> (comma-separated)
                        <input type="text" id="ai_bulk_keywords" style="width:100%;" placeholder="e.g., TMJ treatment, dental sleep apnea" />
                    </label>
                    <label><input type="checkbox" id="ai_bulk_optimize" checked /> Optimize for SEO</label>
                </div>
                <div style="margin-top:10px;">
                    <label><strong>Prompt template (editable per run):</strong></label>
                    <textarea id="ai_bulk_prompt" class="ai-br-textarea"><?php echo esc_textarea(
                        $this->default_prompt_template()
                    ); ?></textarea>
                    <p class="ai-br-small">Placeholders: <code>{{ORIGINAL_HTML}}</code>, <code>{{KEYWORDS}}</code>, <code>{{POST_TITLE}}</code>, <code>{{OUTPUT_MODE}}</code>, <code>{{TARGET_CHARS}}</code>, <code>{{FIELD_LABEL}}</code>, <code>{{FIELD_TYPE}}</code>, <code>{{SHORTCODES_LIST}}</code>.</p>
                </div>
            </div>

            <div class="ai-br-card">
                <div class="ai-br-flex">
                    <label><strong>Batch size</strong>
                        <select id="ai_bulk_batch">
                            <option>1</option><option>2</option><option>3</option><option selected>5</option><option>10</option><option>20</option><option>50</option><option>200</option>
                        </select>
                        <span class="ai-br-small">Processes all selected posts in chunks; capped at 10 per request.</span>
                    </label>
                    <label><strong>Min text length to rewrite</strong>
                        <input type="number" id="ai_bulk_minlen" value="40" min="0" step="5" style="width:90px;">
                    </label>
                    <label class="ai-mode-acf-only"><input type="checkbox" id="ai_bulk_include_acf" checked> Include ACF fields</label>
                    <label class="ai-mode-acf-only"><strong>ACF field slugs (comma-separated)</strong>
                        <input type="text" id="ai_bulk_acf_filter" placeholder="e.g., hero_title, main_paragraph">
                    </label>
                </div>
            </div>

            <div class="ai-br-card">
                <table class="widefat fixed ai-br-table">
                    <thead><tr>
                        <th style="width:24px;"><input type="checkbox" id="ai_bulk_select_all"></th>
                        <th>Title</th><th>Type</th><th>Status</th><th>Date</th><th>URL</th>
                    </tr></thead>
                    <tbody>
                    <?php
                    if ($q->have_posts()):
                        while ($q->have_posts()):
                            $q->the_post(); ?>
                        <tr>
                            <td><input type="checkbox" class="ai_bulk_row" value="<?php the_ID(); ?>"></td>
                            <td><a href="<?php echo esc_url(
                                get_edit_post_link()
                            ); ?>"><?php echo esc_html(
    get_the_title()
); ?></a></td>
                            <td><?php echo esc_html(get_post_type()); ?></td>
                            <td><?php echo esc_html(get_post_status()); ?></td>
                            <td><?php echo esc_html(get_the_date()); ?></td>
                            <td class="ai-br-mono"><?php echo esc_html(
                                get_permalink()
                            ); ?></td>
                        </tr>
                    <?php
                        endwhile;
                    else:
                         ?>
                        <tr><td colspan="6">No posts found for these filters.</td></tr>
                    <?php
                    endif;
                    wp_reset_postdata();
                    ?>
                    </tbody>
                </table>

                <div style="margin-top:12px;" class="ai-br-flex">
                    <button class="button" id="ai_bulk_preview">Preview Selected</button>
                    <button class="button button-secondary" id="ai_bulk_review" disabled>Review & Approve (Wizard)</button>
                    <button class="button button-primary" id="ai_bulk_apply">Apply Selected (Skip Preview)</button>
                    <button class="button" id="ai_bulk_queue">Queue Selected (Background)</button>
                    <span id="ai_jsonld_direct_notice" class="ai-br-small" style="display:none;">JSON-LD mode requires Preview → Review → Apply.</span>
                    <div style="flex:1;"></div>
                    <div style="min-width:320px;">
                        <div class="ai-br-progress"><div class="ai-br-bar" id="ai_bulk_bar"></div></div>
                        <div id="ai_bulk_status" class="ai-br-small" style="margin-top:4px;"></div>
                    </div>
                </div>

                <div id="ai_bulk_log" class="ai-br-card" style="display:none;">
                    <strong>Run log</strong>
                    <pre class="ai-br-mono" id="ai_bulk_log_pre"></pre>
                </div>
            </div>
        </div>

        <div class="ai-br-overlay" id="ai_wizard_overlay">
            <div class="ai-br-wizard">
                <div class="ai-br-w-hd">
                    <div class="ai-br-w-tt" id="ai_wizard_title">Review</div>
                    <div>
                        <button class="button" id="ai_wizard_cancel">Cancel</button>
                        <button class="button" id="ai_wizard_approve_all">Approve All on This Step</button>
                        <button class="button" id="ai_wizard_reject_all">Reject All on This Step</button>
                    </div>
                </div>
                <div class="ai-br-w-body">
                    <div class="ai-br-panel">
                        <h3>Original</h3>
                        <div id="ai_wizard_original"></div>
                    </div>
                    <div class="ai-br-panel">
                        <h3>New</h3>
                        <div id="ai_wizard_new"></div>
                    </div>
                </div>
                <div class="ai-br-actions">
                    <div>
                        <label class="ai-wizard-acf-only"><input type="checkbox" id="ai_wizard_toggle_acf" checked> Show ACF fields</label>
                        <label style="margin-left:10px;" class="ai-wizard-content-only"><input type="checkbox" id="ai_wizard_toggle_content" checked> Show Post Content</label>
                        <label style="margin-left:10px;" class="ai-wizard-seo-only"><input type="checkbox" id="ai_wizard_toggle_seo" checked> Show SEO fields</label>
                    </div>
                    <div>
                        <button class="button button-secondary" id="ai_wizard_prev">Previous</button>
                        <button class="button button-primary" id="ai_wizard_next">Next</button>
                        <button class="button button-danger" id="ai_wizard_apply_all" style="display:none;">Apply Approved</button>
                    </div>
                </div>
            </div>
        </div>

        <script>
        jQuery(function($){
            const ajaxurlWP = ajaxurl;
            const nonce = '<?php echo esc_js($nonce); ?>';
            let aiBulkMode = $('#ai_bulk_mode').val() || 'content';
            let previewMode = aiBulkMode;

            let aiPreviewRunId = null;
            let selectedIds = [];
            let reviewIndex = 0;
            const approvals = {}; // { post_id: Set(entry_id) }
            const overrides = {}; // { post_id: { itemId: value } }
            let queueRunId = null;
            let queueTimer = null;
            const hasScheduler = <?php echo function_exists('as_enqueue_async_action') ? 'true' : 'false'; ?>;

            $('#ai_bulk_select_all').on('change', function(){ $('.ai_bulk_row').prop('checked', $(this).is(':checked')); });

            function gatherSelectedIds(){
                const ids = [];
                $('.ai_bulk_row:checked').each(function(){ ids.push($(this).val()); });
                return ids;
            }
            function updateBar(done,total){
                const pct = total ? Math.round((done/total)*100) : 0;
                $('#ai_bulk_bar').css('width', pct+'%');
                $('#ai_bulk_status').text(done+' / '+total+' processed');
            }
            function logLine(line){
                $('#ai_bulk_log').show();
                const pre = $('#ai_bulk_log_pre'); pre.text(pre.text() + line + "\n");
            }
            function approvalsClear(){ for (const k in approvals) delete approvals[k]; }
            function overridesClear(){ for (const k in overrides) delete overrides[k]; }

            function getOverride(postId, itemId){
                if (!overrides[postId]) { return null; }
                return overrides[postId][itemId] !== undefined ? overrides[postId][itemId] : null;
            }

            function updateModeUI(){
                aiBulkMode = $('#ai_bulk_mode').val() || 'content';
                const isJson = aiBulkMode === 'jsonld';
                const isSeo  = aiBulkMode === 'seo_meta';
                const isContent = aiBulkMode === 'content';
                const isPost = aiBulkMode === 'post_content';
                $('.ai-jsonld-only').toggle(isJson);
                $('#ai_prompt_inputs_card').toggle(!isJson);
                $('#ai_bulk_apply').prop('disabled', isJson);
                $('#ai_bulk_queue').prop('disabled', isJson || !hasScheduler);
                $('#ai_jsonld_direct_notice').toggle(isJson);
                if (isJson){
                    $('#ai_bulk_optimize').prop('checked', false);
                }
                if (!aiPreviewRunId){
                    previewMode = aiBulkMode;
                }
                $('.ai-mode-acf-only').toggle(isContent);
                $('.ai-wizard-content-only').toggle(isPost);
                $('.ai-wizard-acf-only').toggle(isContent);
                $('.ai-wizard-seo-only').toggle(isSeo);

                // Swap prompt to SEO default when switching into SEO mode (if using the base default)
                const basePrompt = <?php echo json_encode( $this->default_prompt_template() ); ?>;
                const seoPrompt  = <?php echo json_encode( $this->default_seo_prompt_template() ); ?>;
                const current = $('#ai_bulk_prompt').val() || '';
                if (isSeo && (current.trim() === '' || current.trim() === basePrompt.trim())) {
                    $('#ai_bulk_prompt').val(seoPrompt);
                }
                if (!isSeo && current.trim() === '' ) {
                    $('#ai_bulk_prompt').val(basePrompt);
                }
            }

            $('#ai_bulk_mode').on('change', updateModeUI);
            updateModeUI();

            function runPreviewBatches(ids){
                const payloadBase = {
                    keywords: $('#ai_bulk_keywords').val() || '',
                    optimize: $('#ai_bulk_optimize').is(':checked') ? 1 : 0,
                    prompt:   $('#ai_bulk_prompt').val() || '',
                    minlen:   parseInt($('#ai_bulk_minlen').val(), 10) || 0,
                    include_acf:  $('#ai_bulk_include_acf').is(':checked') ? 1 : 0,
                    acf_fields: $('#ai_bulk_acf_filter').val() || '',
                    mode: aiBulkMode,
                    schema_type: $('#ai_jsonld_type').val() || 'Article',
                    schema_custom: $('#ai_jsonld_custom').val() || ''
                };
                previewMode = aiBulkMode;

                const batchSel = parseInt($('#ai_bulk_batch').val(), 10) || 5;
                const batch = Math.max(1, Math.min(batchSel, 10)); // cap to 10 per request to avoid timeouts
                const chunks = [];
                for(let i=0;i<ids.length;i+=batch) chunks.push(ids.slice(i,i+batch));

                let done = 0;
                updateBar(0, ids.length);
                aiPreviewRunId = null;
                $('#ai_bulk_review').prop('disabled', true);

                function next(){
                    if (!chunks.length){
                        updateBar(ids.length, ids.length);
                        logLine('Preview finished.');
                        if (aiPreviewRunId) $('#ai_bulk_review').prop('disabled', false);
                        return;
                    }
                    const batchIds = chunks.shift();
                    const payload = { action: 'ai_br_bulk_preview', ids: batchIds, <?php echo esc_js(
                        $this->nonce_name
                    ); ?>: nonce, ...payloadBase };
                    if (aiPreviewRunId) payload.run_id = aiPreviewRunId;

                    $.post(ajaxurlWP, payload, function(resp){
                        done += batchIds.length;
                        updateBar(done, ids.length);

                        let data = resp;
                        if (typeof resp === 'string') { try { data = JSON.parse(resp); } catch(e) { data = { log: resp }; } }
                        if (data && data.log) logLine(data.log);
                        if (data && data.run_id) { aiPreviewRunId = data.run_id; $('#ai_bulk_review').prop('disabled', false); }

                        setTimeout(next, 100);
                    }).fail(function(){
                        done += batchIds.length; updateBar(done, ids.length);
                        logLine('Batch failed for IDs: '+batchIds.join(', '));
                        setTimeout(next, 100);
                    });
                }
                next();
            }

            $('#ai_bulk_preview').on('click', function(){
                selectedIds = gatherSelectedIds();
                if (!selectedIds.length) { alert('Select at least one post.'); return; }
                approvalsClear();
                overridesClear();
                $('#ai_bulk_log_pre').text('');
                runPreviewBatches(selectedIds);
            });

            if (!hasScheduler) {
                $('#ai_bulk_queue').attr('title', 'Action Scheduler not available.');
            }

            // Direct apply (skip preview/wizard)
            $('#ai_bulk_apply').on('click', function(){
                if (aiBulkMode === 'jsonld') {
                    alert('JSON-LD mode requires Preview → Review → Apply Approved.');
                    return;
                }
                const ids = gatherSelectedIds();
                if (!ids.length) { alert('Select at least one post.'); return; }
                if (!confirm('Apply rewrites immediately to selected posts? This will SAVE changes.')) return;

                const payloadBase = {
                    keywords: $('#ai_bulk_keywords').val() || '',
                    optimize: $('#ai_bulk_optimize').is(':checked') ? 1 : 0,
                    prompt:   $('#ai_bulk_prompt').val() || '',
                    minlen:   parseInt($('#ai_bulk_minlen').val(), 10) || 0,
                    include_acf:  $('#ai_bulk_include_acf').is(':checked') ? 1 : 0,
                    acf_fields: $('#ai_bulk_acf_filter').val() || '',
                    mode: aiBulkMode,
                };
                const batchSel = parseInt($('#ai_bulk_batch').val(), 10) || 5;
                const batch = Math.max(1, Math.min(batchSel, 10)); // cap to 10 per request to avoid timeouts
                const chunks = [];
                for(let i=0;i<ids.length;i+=batch) chunks.push(ids.slice(i,i+batch));
                let done = 0;
                updateBar(0, ids.length);
                $('#ai_bulk_log_pre').text('');

                function next(){
                    if (!chunks.length){ updateBar(ids.length, ids.length); logLine('Finished.'); return; }
                    const batchIds = chunks.shift();
                    $.post(ajaxurlWP, { action:'ai_br_bulk_apply', ids:batchIds, <?php echo esc_js(
                        $this->nonce_name
                    ); ?>: nonce, ...payloadBase }, function(resp){
                        done += batchIds.length; updateBar(done, ids.length);
                        logLine(resp || '[empty response]');
                        setTimeout(next, 100);
                    }).fail(function(){
                        done += batchIds.length; updateBar(done, ids.length);
                        logLine('Batch failed for IDs: '+batchIds.join(', '));
                        setTimeout(next, 100);
                    });
                }
                next();
            });

            function startQueuePolling(runId){
                queueRunId = runId;
                if (queueTimer) { clearInterval(queueTimer); }
                queueTimer = setInterval(function(){
                    $.post(ajaxurlWP, { action:'ai_br_queue_status', run_id: queueRunId, <?php echo esc_js(
                        $this->nonce_name
                    ); ?>: nonce }, function(resp){
                        let data = resp;
                        if (typeof resp === 'string') { try { data = JSON.parse(resp); } catch(e) { data = null; } }
                        if (!data) { return; }
                        updateBar(data.processed || 0, data.total || 0);
                        $('#ai_bulk_status').text((data.status || 'running') + ' — ' + (data.processed || 0) + ' / ' + (data.total || 0) + ' processed');
                        if (data.status === 'completed') {
                            clearInterval(queueTimer);
                            queueTimer = null;
                            logLine('Background run completed. Errors: ' + (data.errors || 0));
                        }
                    });
                }, 3000);
            }

            // Queue apply (background)
            $('#ai_bulk_queue').on('click', function(){
                if (aiBulkMode === 'jsonld') {
                    alert('JSON-LD mode requires Preview → Review → Apply Approved.');
                    return;
                }
                const ids = gatherSelectedIds();
                if (!ids.length) { alert('Select at least one post.'); return; }
                if (!confirm('Queue rewrites in the background for selected posts?')) return;

                const payloadBase = {
                    keywords: $('#ai_bulk_keywords').val() || '',
                    optimize: $('#ai_bulk_optimize').is(':checked') ? 1 : 0,
                    prompt:   $('#ai_bulk_prompt').val() || '',
                    minlen:   parseInt($('#ai_bulk_minlen').val(), 10) || 0,
                    include_acf:  $('#ai_bulk_include_acf').is(':checked') ? 1 : 0,
                    acf_fields: $('#ai_bulk_acf_filter').val() || '',
                    mode: aiBulkMode,
                    batch_size: parseInt($('#ai_bulk_batch').val(), 10) || 5,
                };
                $('#ai_bulk_log_pre').text('');
                updateBar(0, ids.length);
                $('#ai_bulk_status').text('Queueing background run...');

                $.post(ajaxurlWP, { action:'ai_br_bulk_queue', ids: ids, <?php echo esc_js(
                    $this->nonce_name
                ); ?>: nonce, ...payloadBase }, function(resp){
                    let data = resp;
                    if (typeof resp === 'string') { try { data = JSON.parse(resp); } catch(e) { data = null; } }
                    if (!data || !data.run_id) {
                        logLine(resp || 'Queue failed.');
                        return;
                    }
                    logLine('Background run queued. Run ID: ' + data.run_id + '.');
                    startQueuePolling(data.run_id);
                }).fail(function(xhr){
                    logLine('Queue failed: ' + (xhr && xhr.responseText ? xhr.responseText : 'unknown error'));
                });
            });

            /* ---------- Review Wizard ---------- */

            function openWizard(){
                if (!aiPreviewRunId) { alert('Run Preview first.'); return; }
                if (!selectedIds.length) { alert('No selected posts to review.'); return; }
                reviewIndex = 0;
                $('#ai_wizard_apply_all').hide();
                $('#ai_wizard_overlay').css('display','flex');
                loadStep();
            }
            function closeWizard(){ $('#ai_wizard_overlay').hide(); }

            function loadStep(){
                const postId = selectedIds[reviewIndex];
                $('#ai_wizard_title').text('Review '+(reviewIndex+1)+' / '+selectedIds.length+' — Post ID '+postId);
                $('#ai_wizard_original').html('<div class="ai-br-small">Loading…</div>');
                $('#ai_wizard_new').html('<div class="ai-br-small">Loading…</div>');
                $('#ai_wizard_prev').prop('disabled', reviewIndex===0);
                $('#ai_wizard_next').text(reviewIndex === selectedIds.length-1 ? 'Finish' : 'Next');

                $.post(ajaxurlWP, { action:'ai_br_get_staged_for_post', run_id:aiPreviewRunId, post_id:postId, <?php echo esc_js(
                    $this->nonce_name
                ); ?>: nonce }, function(resp){
                    let data = resp;
                    if (typeof resp === 'string') { try { data = JSON.parse(resp); } catch(e) { data = null; } }
                    if (!data) {
                        $('#ai_wizard_original').html('<div class="ai-br-small">Failed to load.</div>');
                        $('#ai_wizard_new').html('<div class="ai-br-small">Failed to load.</div>');
                        return;
                    }
                    renderStep(postId, data);
                    if (reviewIndex === selectedIds.length-1) $('#ai_wizard_apply_all').show(); else $('#ai_wizard_apply_all').hide();
                }).fail(function(){
                    $('#ai_wizard_original').html('<div class="ai-br-small">Request failed.</div>');
                    $('#ai_wizard_new').html('<div class="ai-br-small">Request failed.</div>');
                });
            }

            function renderStep(postId, data){
                if (previewMode === 'jsonld'){
                    renderJsonldStep(postId, data);
                    return;
                }
                const showACF  = (previewMode === 'content') && $('#ai_wizard_toggle_acf').is(':checked');
                const showContent = (previewMode === 'post_content') && $('#ai_wizard_toggle_content').is(':checked');
                const showSEO  = (previewMode === 'seo_meta') && $('#ai_wizard_toggle_seo').is(':checked');

                const origWrap = [];
                const newWrap  = [];

                function addRow(item, kind){
                    const itemId = item.id;
                    if (!approvals[postId]) approvals[postId] = new Set();
                    if (previewMode === 'seo_meta' && kind === 'seo' && approvals[postId].size === 0) {
                        approvals[postId].add(itemId);
                    }
                    const isChecked = approvals[postId].has(itemId) ? 'checked' : '';
                    const overrideVal = getOverride(postId, itemId);
                    const newVal = overrideVal !== null ? overrideVal : item.new_html;

                    // LEFT: Original — label + content (NO checkbox)
                    origWrap.push(
                        '<div class="ai-br-row" data-kind="'+kind+'" data-id="'+itemId+'">'+
                            '<label>'+escapeHtml(item.label)+'</label>'+
                            '<div class="ai-br-panel-sm">'+item.orig_html+'</div>'+
                        '</div>'
                    );

                    // RIGHT: New — single authoritative checkbox
                    newWrap.push(
                        '<div class="ai-br-row" data-kind="'+kind+'" data-id="'+itemId+'">'+
                            '<label><input type="checkbox" class="ai-approve-item" data-post="'+postId+'" data-id="'+itemId+'" '+isChecked+'> '+escapeHtml(item.label)+'</label>'+
                            '<textarea class="ai-br-edit ai-edit-field" data-post="'+postId+'" data-id="'+itemId+'">'+escapeHtml(newVal)+'</textarea>'+
                        '</div>'
                    );
                }

                if (showACF  && data.acf)  data.acf.forEach(a => addRow(a,'acf'));
                if (showContent && data.content) data.content.forEach(c => addRow(c,'content'));
                if (showSEO  && data.seo)  data.seo.forEach(s => addRow(s,'seo'));

                $('#ai_wizard_original').html(origWrap.join('') || '<div class="ai-br-small">No items for this filter.</div>');
                $('#ai_wizard_new').html(newWrap.join('') || '<div class="ai-br-small">No items for this filter.</div>');

                // --- Sync scroll (proportional) ---
                (function syncScroll(){
                    const left  = document.querySelector('#ai_wizard_original');
                    const right = document.querySelector('#ai_wizard_new');
                    if (!left || !right) return;
                    let lock = false;
                    function ratio(el){ const max = el.scrollHeight - el.clientHeight; return max>0 ? (el.scrollTop/max) : 0; }
                    function setByRatio(el, r){ const max = el.scrollHeight - el.clientHeight; el.scrollTop = r * (max>0 ? max : 0); }
                    left.onscroll = function(){ if(lock) return; lock=true; setByRatio(right, ratio(left)); lock=false; };
                    right.onscroll= function(){ if(lock) return; lock=true; setByRatio(left,  ratio(right)); lock=false; };
                })();
            }

            function renderJsonldStep(postId, data){
                if (!approvals[postId]) approvals[postId] = new Set();
                const origWrap = [];
                const newWrap  = [];
                const items = data.jsonld || [];

                items.forEach(function(item){
                    const itemId = item.id || (item.label || 'jsonld');
                    const checked = approvals[postId].has(itemId) ? 'checked' : '';
                    const badge = item.type ? '<span class="ai-jsonld-badge">'+escapeHtml(item.type)+'</span>' : '';
                    const warnings = (item.warnings && item.warnings.length)
                        ? '<div class="ai-jsonld-warning">Warnings: '+item.warnings.map(escapeHtml).join('; ')+'</div>'
                        : '';
                    const overrideVal = getOverride(postId, itemId) ?? item.json;
                    origWrap.push(
                        '<div class="ai-br-row" data-kind="jsonld" data-id="'+itemId+'">'+
                            '<label>'+escapeHtml(item.label || 'JSON-LD Preview')+badge+'</label>'+
                            '<div class="ai-br-panel-sm"><pre style="white-space:pre-wrap">'+escapeHtml(item.raw || '')+'</pre></div>'+
                        '</div>'
                    );
                    newWrap.push(
                        '<div class="ai-br-row" data-kind="jsonld" data-id="'+itemId+'">'+
                            '<label><input type="checkbox" class="ai-approve-item" data-post="'+postId+'" data-id="'+itemId+'" '+checked+'> '+escapeHtml(item.label || 'JSON-LD Preview')+'</label>'+
                            warnings+
                            '<textarea class="ai-jsonld-editor" data-post="'+postId+'" data-id="'+itemId+'">'+escapeHtml(overrideVal || '')+'</textarea>'+
                        '</div>'
                    );
                });

                $('#ai_wizard_original').html(origWrap.join('') || '<div class="ai-br-small">No JSON-LD preview generated.</div>');
                $('#ai_wizard_new').html(newWrap.join('') || '<div class="ai-br-small">No JSON-LD preview generated.</div>');
            }

            function escapeHtml(s){ return (s||"").toString().replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]||c)); }

            $('#ai_wizard_toggle_acf, #ai_wizard_toggle_seo, #ai_wizard_toggle_content').on('change', function(){ loadStep(); });

            $('#ai_wizard_approve_all').on('click', function(){
                const postId = selectedIds[reviewIndex];
                if (!approvals[postId]) approvals[postId] = new Set();
                $('#ai_wizard_new .ai-approve-item').each(function(){
                    const id = $(this).data('id'); approvals[postId].add(id); $(this).prop('checked', true);
                });
            });

            $('#ai_wizard_reject_all').on('click', function(){
                const postId = selectedIds[reviewIndex];
                approvals[postId] = new Set();
                $('#ai_wizard_new .ai-approve-item').prop('checked', false);
            });

            $(document).on('change', '.ai-approve-item', function(){
                const postId = $(this).data('post');
                const id     = $(this).data('id');
                if (!approvals[postId]) approvals[postId] = new Set();
                if ($(this).is(':checked')) approvals[postId].add(id); else approvals[postId].delete(id);
            });

            $(document).on('input', '.ai-jsonld-editor', function(){
                const postId = $(this).data('post');
                const id = $(this).data('id');
                if (!overrides[postId]) overrides[postId] = {};
                overrides[postId][id] = $(this).val();
            });

            $(document).on('input', '.ai-edit-field', function(){
                const postId = $(this).data('post');
                const id = $(this).data('id');
                if (!overrides[postId]) overrides[postId] = {};
                overrides[postId][id] = $(this).val();
            });

            $('#ai_bulk_review').on('click', openWizard);
            $('#ai_wizard_cancel').on('click', function(){ if (confirm('Cancel review? Your staged preview remains cached for a while.')) closeWizard(); });
            $('#ai_wizard_prev').on('click', function(){ if (reviewIndex>0){ reviewIndex--; loadStep(); } });
            $('#ai_wizard_next').on('click', function(){
                if (reviewIndex < selectedIds.length-1){ reviewIndex++; loadStep(); }
                else { alert('Review complete. Click "Apply Approved" to commit approved changes.'); }
            });

            $('#ai_wizard_apply_all').on('click', function(){
                if (!aiPreviewRunId) { alert('Preview expired. Run again.'); return; }
                if (!confirm('Apply only the approved changes across all reviewed posts?')) return;

                const approvedMap = {};
                for (const pid in approvals) approvedMap[pid] = Array.from(approvals[pid]);
                const overrideMap = {};
                for (const pid in overrides){
                    if (overrides[pid] && Object.keys(overrides[pid]).length){
                        overrideMap[pid] = overrides[pid];
                    }
                }

                $.post(ajaxurlWP, {
                    action: 'ai_br_bulk_apply_approved',
                    run_id: aiPreviewRunId,
                    approved: JSON.stringify(approvedMap),
                    overrides: JSON.stringify(overrideMap),
                    <?php echo esc_js($this->nonce_name); ?>: nonce
                }, function(resp){
                    alert('Apply result:\n\n' + (resp || 'Done.'));
                    closeWizard();
                    aiPreviewRunId = null;
                    $('#ai_bulk_review').prop('disabled', true);
                    overridesClear();
                }).fail(function(){
                    alert('Apply failed.');
                });
            });

        });
        </script>
        <?php
    }

    /* ---------------- Helpers ---------------- */

    // Strip markdown code fences like ``` or ```html
    private function strip_code_fences($html)
    {
        $html = trim((string) $html);
        $html = preg_replace("/^```[a-zA-Z0-9]*\s*/", "", $html);
        $html = preg_replace('/\s*```$/', "", $html);
        return trim($html);
    }

    // Deep ACF traversal: collect text/textarea/wysiwyg (keys+names) inside group/repeater/flex
    private function get_acf_fields($post_id)
    {
        $out = [];
        $seen = [];

        $collect_simple = function (
            array $pathNames,
            array $pathKeys,
            string $labelPath,
            array $field,
            $value
        ) use (&$out, &$seen) {
            $type = $field["type"] ?? "";
            if (!in_array($type, ["text", "textarea", "wysiwyg"], true)) {
                return;
            }
            $plain = is_string($value) ? wp_strip_all_tags($value) : "";
            $key = ($field["key"] ?? "") . "|" . implode("/", $pathNames);
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $out[] = [
                "name" => $field["name"] ?? "",
                "key" => $field["key"] ?? "", // for top-level update_field
                "path_names" => $pathNames, // names path for nested
                "path_keys" => $pathKeys, // keys path for nested (preferred)
                "label" => $labelPath, // breadcrumb for UI
                "value" => (string) $value,
                "len" => strlen(trim($plain)),
                "type" => $type,
            ];
        };

        $walk = function (
            $field,
            $value,
            array $pathNames = [],
            array $pathKeys = [],
            string $labelPath = ""
        ) use (&$walk, $collect_simple, $post_id) {
            if (!is_array($field)) {
                return;
            }
            $type = $field["type"] ?? "";
            $label = $field["label"] ?? ($field["name"] ?? "");
            $name = $field["name"] ?? "";
            $fkey = $field["key"] ?? "";
            $labelPath = $labelPath ? $labelPath . " → " . $label : $label;

            if (in_array($type, ["text", "textarea", "wysiwyg"], true)) {
                $collect_simple(
                    $pathNames,
                    $pathKeys,
                    $labelPath,
                    $field,
                    $value
                );
                return;
            }

            if ($type === "group" && !empty($field["sub_fields"])) {
                $groupVal = is_array($value) ? $value : [];
                foreach ($field["sub_fields"] as $sf) {
                    $sfName = $sf["name"] ?? "";
                    $sfKey = $sf["key"] ?? "";
                    $walk(
                        $sf,
                        $groupVal[$sfName] ?? "",
                        array_merge($pathNames, [$name, $sfName]),
                        array_merge($pathKeys, [$fkey, $sfKey]),
                        $labelPath
                    );
                }
                return;
            }

            if ($type === "repeater" && !empty($field["sub_fields"])) {
                $rows = is_array($value) ? $value : [];
                foreach ($rows as $i => $row) {
                    $rowIndex = $i + 1; // ACF is 1-based for update_sub_field
                    foreach ($field["sub_fields"] as $sf) {
                        $sfName = $sf["name"] ?? "";
                        $sfKey = $sf["key"] ?? "";
                        $walk(
                            $sf,
                            $row[$sfName] ?? "",
                            array_merge($pathNames, [
                                $name,
                                $rowIndex,
                                $sfName,
                            ]),
                            array_merge($pathKeys, [$fkey, $rowIndex, $sfKey]),
                            $labelPath . " #" . $rowIndex
                        );
                    }
                }
                return;
            }

            if ($type === "flexible_content" && !empty($field["layouts"])) {
                $rows = is_array($value) ? $value : [];
                $layoutsByName = [];
                foreach ($field["layouts"] as $ld) {
                    $layoutsByName[$ld["name"]] = $ld;
                }
                foreach ($rows as $i => $row) {
                    $rowIndex = $i + 1;
                    $layout = $row["acf_fc_layout"] ?? "";
                    $layoutDef = $layoutsByName[$layout] ?? null;
                    if (!$layoutDef || empty($layoutDef["sub_fields"])) {
                        continue;
                    }
                    foreach ($layoutDef["sub_fields"] as $sf) {
                        $sfName = $sf["name"] ?? "";
                        $sfKey = $sf["key"] ?? "";
                        $walk(
                            $sf,
                            $row[$sfName] ?? "",
                            array_merge($pathNames, [
                                $name,
                                $rowIndex,
                                $sfName,
                            ]),
                            array_merge($pathKeys, [$fkey, $rowIndex, $sfKey]),
                            $labelPath . " [" . $layout . " #" . $rowIndex . "]"
                        );
                    }
                }
                return;
            }

            if ($type === "clone") {
                $display = $field["display"] ?? "group";
                $clone_fields = [];
                if (!empty($field["sub_fields"])) {
                    $clone_fields = $field["sub_fields"];
                } elseif (!empty($field["clone"]) && function_exists("acf_get_field")) {
                    foreach ((array) $field["clone"] as $ckey) {
                        $cf = acf_get_field($ckey);
                        if ($cf) {
                            $clone_fields[] = $cf;
                        }
                    }
                }
                if (!empty($clone_fields)) {
                    $cloneVal = is_array($value) ? $value : [];
                    foreach ($clone_fields as $sf) {
                        $sfName = $sf["name"] ?? "";
                        $sfKey = $sf["key"] ?? "";
                        $usePrefix = ($display !== "seamless");
                        $nextPathNames = $usePrefix ? array_merge($pathNames, [$name, $sfName]) : array_merge($pathNames, [$sfName]);
                        $nextPathKeys = $usePrefix ? array_merge($pathKeys, [$fkey, $sfKey]) : array_merge($pathKeys, [$sfKey]);
                        $subVal = $cloneVal[$sfName] ?? (function_exists("get_field") ? get_field($sfName, $post_id, false) : "");
                        $walk(
                            $sf,
                            $subVal,
                            $nextPathNames,
                            $nextPathKeys,
                            $labelPath
                        );
                    }
                }
                return;
            }
        };

        if (function_exists("acf_get_field_groups") && function_exists("acf_get_fields")) {
            $groups = acf_get_field_groups(["post_id" => $post_id]);
            if (!empty($groups)) {
                foreach ($groups as $group) {
                    $fields = acf_get_fields($group);
                    if (empty($fields)) {
                        continue;
                    }
                    foreach ($fields as $field) {
                        $name = $field["name"] ?? "";
                        $key = $field["key"] ?? "";
                        $value = "";
                        if (function_exists("get_field")) {
                            if ($name) {
                                $value = get_field($name, $post_id, false);
                            } elseif ($key) {
                                $value = get_field($key, $post_id, false);
                            }
                        }
                        $walk($field, $value, [], [], "");
                    }
                }
                return $out;
            }
        }

        if (!function_exists("get_field_objects")) {
            return [];
        }
        $objs = get_field_objects($post_id);
        if (!$objs) {
            return [];
        }

        foreach ($objs as $fo) {
            $walk($fo, $fo["value"] ?? "", [], [], "");
        }

        return $out;
    }

    private function collect_jsonld_context($post, $include_acf, $params)
    {
        $pieces = [];
        $pieces[] = "Title: " . get_the_title($post->ID);
        if (!empty($post->post_excerpt)) {
            $pieces[] = "Excerpt: " . wp_strip_all_tags($post->post_excerpt);
        }
        if (!empty($post->post_content)) {
            $pieces[] = "Body: " . wp_strip_all_tags($post->post_content);
        }
        if ($include_acf && function_exists("get_field_object")) {
            $acf_items = $this->get_acf_fields($post->ID);
            foreach ($acf_items as $f) {
                if (strlen(trim($f["value"])) < 5) {
                    continue;
                }
                $pieces[] = $f["label"] . ": " . wp_strip_all_tags($f["value"]);
            }
        }
        $raw = implode("\n\n", array_filter(array_map("trim", $pieces)));
        if (function_exists("mb_substr")) {
            $raw = mb_substr($raw, 0, 8000);
        } else {
            $raw = substr($raw, 0, 8000);
        }
        return [
            "raw" => $raw,
        ];
    }

    private function protect_yoast_vars($text)
    {
        $map = [];
        $clean = (string) $text;
        if (preg_match_all('/%%[^%]+%%/', $clean, $matches)) {
            foreach ($matches[0] as $i => $tag) {
                $token = "%%YOASTVAR_" . $i . "%%";
                $map[$token] = $tag;
                $clean = str_replace($tag, $token, $clean);
            }
        }
        return [$clean, $map];
    }

    private function restore_yoast_vars($text, $map)
    {
        foreach ($map as $token => $tag) {
            $text = str_replace($token, $tag, $text);
        }
        return $text;
    }

    private function strip_wrapping_quotes($text)
    {
        $text = trim((string) $text);
        if ($text === "") {
            return $text;
        }
        $first = substr($text, 0, 1);
        $last = substr($text, -1);
        if (($first === '"' || $first === "'") && $last === $first) {
            $text = substr($text, 1, -1);
        }
        return trim($text);
    }

    private function extract_seo_labeled_value($text, array $labels, array $stop_labels)
    {
        if (empty($labels)) {
            return "";
        }
        $label_pattern = implode("|", array_map("preg_quote", $labels));
        $stop = array_filter(array_merge($labels, $stop_labels));
        $stop_pattern = $stop ? implode("|", array_map("preg_quote", $stop)) : "";

        $lookahead = $stop_pattern
            ? "(?=\\s*\\b(?:" . $stop_pattern . ")\\b\\s*[:\\-]|$)"
            : "(?=$)";
        $regex = "/\\b(?:" . $label_pattern . ")\\b\\s*[:\\-]\\s*(.+?)" . $lookahead . "/is";
        if (preg_match($regex, $text, $m)) {
            return trim((string) $m[1]);
        }
        return "";
    }

    private function normalize_seo_output($text, $field_type)
    {
        $text = trim((string) $text);
        if ($text === "") {
            return $text;
        }

        $title_labels = ["title", "seo title"];
        $meta_labels = ["meta description", "meta desc", "meta-description", "description"];

        $type = strtolower((string) $field_type);
        if ($type === "yoast_title") {
            $picked = $this->extract_seo_labeled_value($text, $title_labels, $meta_labels);
            if ($picked !== "") {
                $text = $picked;
            }
        } elseif ($type === "yoast_metadesc") {
            $picked = $this->extract_seo_labeled_value($text, $meta_labels, $title_labels);
            if ($picked !== "") {
                $text = $picked;
            }
        }

        $strip_labels = array_merge($title_labels, $meta_labels);
        $strip_pattern = implode("|", array_map("preg_quote", $strip_labels));
        $text = preg_replace("/^\\s*(?:" . $strip_pattern . ")\\s*[:\\-]\\s*/i", "", $text);

        $text = $this->strip_wrapping_quotes($text);
        $text = preg_replace("/\\s+/", " ", $text);
        return trim($text);
    }

    private function get_yoast_fields($post_id)
    {
        $fields = [];
        $post = get_post($post_id);
        $fallback_title = get_the_title($post_id);
        $fallback_desc = $post ? (string) $post->post_excerpt : '';

        $title = get_post_meta($post_id, "_yoast_wpseo_title", true);
        if ($title === '') {
            $title = $fallback_title;
        }
        $desc = get_post_meta($post_id, "_yoast_wpseo_metadesc", true);
        if ($desc === '') {
            $desc = $fallback_desc;
        }

        $fields[] = [
            "id" => "yoast_title",
            "label" => "SEO Title",
            "meta_key" => "_yoast_wpseo_title",
            "value" => (string) $title,
            "len" => strlen(trim((string) $title)),
            "type" => "yoast_title",
        ];
        $fields[] = [
            "id" => "yoast_desc",
            "label" => "Meta Description",
            "meta_key" => "_yoast_wpseo_metadesc",
            "value" => (string) $desc,
            "len" => strlen(trim((string) $desc)),
            "type" => "yoast_metadesc",
        ];

        return $fields;
    }

    private function pretty_print_json($json)
    {
        $decoded = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $json;
        }
        return wp_json_encode(
            $decoded,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    private function protect_shortcodes($html)
    {
        $map = [];
        $clean = $html;
        if (preg_match_all("/\[[^\]]+\]/", $html, $sc_matches)) {
            foreach ($sc_matches[0] as $i => $sc) {
                $token = "%%SHORTCODE_" . $i . "%%";
                $map[$token] = $sc;
                $clean = str_replace($sc, $token, $clean);
            }
        }
        return [$clean, $map];
    }

    private function restore_shortcodes($html, $map)
    {
        foreach ($map as $token => $sc) {
            $html = str_replace($token, $sc, $html);
        }
        return $html;
    }

    private function build_shortcodes_list($map)
    {
        if (empty($map)) {
            return "None";
        }
        $shortcodes = array_values($map);
        $shortcodes = array_unique($shortcodes);
        return implode("\n- ", $shortcodes);
    }

    private function parse_acf_filter_list($filter_csv)
    {
        $raw = array_filter(array_map('trim', explode(',', (string) $filter_csv)));
        $out = [];
        foreach ($raw as $val) {
            if ($val !== '') {
                $out[] = $val;
            }
        }
        return array_values(array_unique($out));
    }

    private function acf_item_matches_filter($item, $filters)
    {
        if (empty($filters)) {
            return true;
        }
        $name = $item['name'] ?? '';
        $key = $item['key'] ?? '';
        $path_names = $item['path_names'] ?? [];
        $path_dot = $path_names ? implode('.', $path_names) : '';
        $path_underscore = $path_names ? implode('_', $path_names) : '';
        $last = $path_names ? end($path_names) : '';

        foreach ($filters as $filter) {
            if ($filter === '') {
                continue;
            }
            if ($filter === $name || $filter === $key) {
                return true;
            }
            if ($path_dot && $filter === $path_dot) {
                return true;
            }
            if ($path_underscore && $filter === $path_underscore) {
                return true;
            }
            if ($last && $filter === $last) {
                return true;
            }
        }
        return false;
    }

    private function filter_acf_items($items, $filter_csv)
    {
        $filters = $this->parse_acf_filter_list($filter_csv);
        if (empty($filters)) {
            return $items;
        }
        $out = [];
        foreach ($items as $item) {
            if ($this->acf_item_matches_filter($item, $filters)) {
                $out[] = $item;
            }
        }
        return $out;
    }

    private function has_structured_layout_markup($text)
    {
        if (!is_string($text) || $text === "") {
            return false;
        }
        if (preg_match('/\\[et_pb_[^\\]]+\\]/i', $text)) {
            return true;
        }
        if (preg_match('/<!--\\s*wp:[^\\s>]+/i', $text)) {
            return true;
        }
        return false;
    }

    private function get_dom_inner_html($node)
    {
        $html = '';
        if (!$node || !$node->hasChildNodes()) {
            return $html;
        }
        foreach ($node->childNodes as $child) {
            $html .= $node->ownerDocument->saveHTML($child);
        }
        return $html;
    }

    private function rewrite_html_text_nodes($html, $api_key, $params, $node_limit = 20)
    {
        if (!is_string($html) || trim($html) === '') {
            return $html;
        }

        $original = $html;
        if (!class_exists('DOMDocument')) {
            return new WP_Error('anchor_html_dom', 'DOMDocument is not available.');
        }
        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $wrapped = '<div id="anchor-rewrite-root">' . $html . '</div>';
        $encoded = function_exists('mb_convert_encoding')
            ? mb_convert_encoding($wrapped, 'HTML-ENTITIES', 'UTF-8')
            : $wrapped;
        $loaded = $doc->loadHTML($encoded, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        if (!$loaded) {
            libxml_clear_errors();
            return new WP_Error('anchor_html_parse', 'Failed to parse HTML content.');
        }

        $xpath = new DOMXPath($doc);
        $nodes = $xpath->query('//div[@id="anchor-rewrite-root"]//text()[normalize-space(.) != ""]');
        if (!$nodes || $nodes->length === 0) {
            libxml_clear_errors();
            return $original;
        }
        if ($nodes->length > $node_limit) {
            libxml_clear_errors();
            return new WP_Error('anchor_html_nodes', 'Too many text nodes to safely rewrite.');
        }

        foreach ($nodes as $node) {
            $parent = $node->parentNode ? strtolower($node->parentNode->nodeName) : '';
            if (in_array($parent, ['script', 'style', 'pre', 'code', 'textarea'], true)) {
                continue;
            }
            $text = trim($node->nodeValue);
            if ($text === '') {
                continue;
            }
            if (strpos($text, '%%SHORTCODE_') !== false) {
                continue;
            }

            $rw = $this->call_openai($api_key, $text, array_merge($params, [
                'output_mode' => 'TEXT_ONLY',
                'field_label' => $params['field_label'] ?? '',
                'field_type' => $params['field_type'] ?? '',
            ]));
            if (is_wp_error($rw)) {
                libxml_clear_errors();
                return $rw;
            }
            $node->nodeValue = $rw;
        }

        $root = $doc->getElementById('anchor-rewrite-root');
        $result = $root ? $this->get_dom_inner_html($root) : $original;
        libxml_clear_errors();
        return $result;
    }

    private function str_replace_once($search, $replace, $subject)
    {
        $pos = strpos($subject, $search);
        if ($pos === false) {
            return $subject;
        }
        return substr_replace($subject, $replace, $pos, strlen($search));
    }

    /**
     * Write ACF updates back to their original fields (including nested repeater/flex paths).
     *
     * @param int   $post_id
     * @param array $items_to_commit Flat list of ACF items with keys/paths + new_html
     * @return array [int $total_applied, int $total_errors]
     */
    private function commit_acf_updates($post_id, $items_to_commit)
    {
        if (empty($items_to_commit) || !function_exists("update_field")) {
            return [0, count($items_to_commit)];
        }

        $applied = 0;
        $errors = 0;
        $this->store_rewrite_snapshot($post_id, $items_to_commit, 'acf');

        foreach ($items_to_commit as $item) {
            $ok = false;
            $new_val = $item["new_html"] ?? "";
            $path_names = $item["path_names"] ?? [];
            $is_nested = !empty($path_names);

            try {
                if ($is_nested) {
                    $name_selector = $this->build_acf_name_selector($path_names);

                    if ($name_selector) {
                        $ok = update_field($name_selector, $new_val, $post_id);
                    }

                    // Fallback: mutate top-level array and save
                    if (!$ok) {
                        $top_selector = $path_names[0] ?? ($item["key"] ?? "");
                        if ($top_selector) {
                            $current = get_field($top_selector, $post_id);
                            $mut_path = $path_names;
                            array_shift($mut_path);
                            $this->deep_set_by_path($current, $mut_path, $new_val);
                            $ok = update_field($top_selector, $current, $post_id);
                        }
                    }
                } else {
                    // Top-level field
                    $selector = $item["key"] ?? ($path_names[0] ?? null);
                    if ($selector) {
                        $ok = update_field($selector, $new_val, $post_id);
                    }
                }
            } catch (Exception $e) {
                error_log("AI Bulk Rewriter (Commit Error): Exception during update_field - " . $e->getMessage());
                $ok = false;
            }

            if ($ok) {
                $applied++;
            } else {
                $errors++;
            }
        }

        if ($applied > 0 && function_exists("acf_flush_cache")) {
            acf_flush_cache($post_id);
        }

        return [$applied, $errors];
    }


    private function commit_seo_updates($post_id, $items_to_commit)
    {
        $applied = 0;
        $errors = 0;
        $this->store_rewrite_snapshot($post_id, $items_to_commit, 'seo');
        foreach ($items_to_commit as $item) {
            $key = $item["meta_key"] ?? "";
            if (!$key) {
                $errors++;
                continue;
            }
            $new_val = (string) ($item["new_html"] ?? "");
            $ok = update_post_meta($post_id, $key, $new_val);
            if ($ok !== false) {
                $applied++;
            } else {
                $current = (string) get_post_meta($post_id, $key, true);
                if ($current === $new_val) {
                    $applied++;
                } else {
                    $errors++;
                }
            }
        }
        return [$applied, $errors];
    }

    private function commit_post_content_updates($post_id, $items_to_commit)
    {
        $applied = 0;
        $errors = 0;
        $this->store_rewrite_snapshot($post_id, $items_to_commit, 'content');
        $update = [ 'ID' => $post_id ];
        $count = 0;
        foreach ($items_to_commit as $item) {
            $type = $item['type'] ?? 'post_content';
            $new_val = (string) ($item["new_html"] ?? "");
            $new_val = wp_kses_post($new_val);
            if ($type === 'post_excerpt') {
                $update['post_excerpt'] = $new_val;
                $count++;
            } else {
                $update['post_content'] = $new_val;
                $count++;
            }
        }
        if ($count === 0) {
            return [0, count($items_to_commit)];
        }
        $result = wp_update_post($update, true);
        if (is_wp_error($result)) {
            $errors += $count;
        } else {
            $applied += $count;
        }
        return [$applied, $errors];
    }

    private function store_rewrite_snapshot($post_id, $items, $type)
    {
        if (empty($items)) {
            return;
        }

        $snapshots = get_post_meta($post_id, '_anchor_rewrite_snapshots', true);
        if (!is_array($snapshots)) {
            $snapshots = [];
        }

        $entry = [
            'timestamp' => current_time('mysql'),
            'type' => $type,
            'items' => [],
        ];

        foreach ($items as $item) {
            $old_val = '';
            if (isset($item['orig_html'])) {
                $old_val = (string) $item['orig_html'];
            } elseif (isset($item['value'])) {
                $old_val = (string) $item['value'];
            }

            if ($type === 'seo') {
                $entry['items'][] = [
                    'meta_key' => $item['meta_key'] ?? '',
                    'label' => $item['label'] ?? '',
                    'old_value' => $old_val,
                ];
            } elseif ($type === 'content') {
                $entry['items'][] = [
                    'label' => $item['label'] ?? 'Post Content',
                    'field_type' => $item['type'] ?? 'post_content',
                    'old_value' => $old_val,
                ];
            } else {
                $entry['items'][] = [
                    'field_key' => $item['key'] ?? '',
                    'path_names' => $item['path_names'] ?? [],
                    'label' => $item['label'] ?? '',
                    'old_value' => $old_val,
                ];
            }
        }

        $snapshots[] = $entry;
        if (count($snapshots) > 10) {
            $snapshots = array_slice($snapshots, -10);
        }
        update_post_meta($post_id, '_anchor_rewrite_snapshots', $snapshots);
    }


    /**
     * Mutate $root array in-place, following a path like:
     * ['repeater_name', 2, 'child_name', 'grandchild']  (the first segment *should* be stripped off already)
     * - Integer segments are 1-based row indices (ACF convention) — we convert to 0-based array index.
     * - String segments are array keys (ACF uses field NAMES for keys inside value arrays).
     */
    private function deep_set_by_path(&$root, array $path, $value)
    {
        if (empty($path)) {
            return;
        }

        $ref = &$root;

        $is_index = function ($seg) {
            // treat integers and numeric strings as repeater/flex indices
            return is_int($seg) || (is_string($seg) && ctype_digit($seg));
        };

        // Walk all but the last segment
        for ($i = 0; $i < count($path) - 1; $i++) {
            $seg = $path[$i];

            if ($is_index($seg)) {
                $idx = max(0, (int) $seg - 1); // ACF 1-based -> 0-based
                if (!is_array($ref)) {
                    $ref = [];
                }
                if (!isset($ref[$idx]) || !is_array($ref[$idx])) {
                    $ref[$idx] = [];
                }
                $ref = &$ref[$idx];
            } else {
                if (!isset($ref[$seg]) || !is_array($ref[$seg])) {
                    $ref[$seg] = [];
                }
                $ref = &$ref[$seg];
            }
        }

        // Final segment — assign the value
        $last = end($path);
        if ($is_index($last)) {
            $idx = max(0, (int) $last - 1);
            if (!is_array($ref)) {
                $ref = [];
            }
            $ref[$idx] = $value;
        } else {
            if (!is_array($ref)) {
                $ref = [];
            }
            $ref[$last] = $value;
        }
    }

    /**
     * Build an ACF name-based selector (e.g., repeater_0_subfield_1_child).
     *
     * @param array $path_names Array of field names and 1-based indices.
     * @return string
     */
    private function build_acf_name_selector(array $path_names)
    {
        if (empty($path_names)) {
            return "";
        }
        $parts = [];
        foreach ($path_names as $seg) {
            if (is_int($seg) || (is_string($seg) && ctype_digit($seg))) {
                $parts[] = (string) max(0, ((int) $seg) - 1); // ACF expects 0-based in names
            } else {
                $parts[] = (string) $seg;
            }
        }
        return implode("_", $parts);
    }

    /* ---------------- OpenAI ---------------- */

    private function build_prompt_from_template(
        $template,
        $original,
        $keywords_csv,
        $optimize,
        $output_mode,
        $target_chars,
        $post_title,
        $field_label,
        $field_type,
        $shortcodes_list
    ) {
        $seo_keywords = trim((string) $keywords_csv);
        $filled = strtr((string) $template, [
            "{{ORIGINAL_HTML}}" => $original,
            "{{KEYWORDS}}" => $seo_keywords,
            "{{POST_TITLE}}" => $post_title ?: "",
            "{{DOCTOR}}" => "",
            "{{BUSINESS}}" => "",
            "{{LOCATION}}" => "",
            "{{OUTPUT_MODE}}" => $output_mode,
            "{{TARGET_CHARS}}" => (string) max(10, (int) $target_chars),
            "{{FIELD_LABEL}}" => $field_label ?: "",
            "{{FIELD_TYPE}}" => $field_type ?: "",
            "{{SHORTCODES_LIST}}" => $shortcodes_list ?: "None",
        ]);

        if (
            $optimize &&
            strpos($filled, "Optimize for SEO") === false &&
            strpos($filled, "{{KEYWORDS}}") !== false
        ) {
            $filled .=
                "\n\nNote: When natural, include one focus keyword early. Avoid keyword stuffing.";
        }
        return $filled;
    }

    private function sanitize_model_output($text, $mode)
    {
        $text = $this->strip_code_fences($text);
        if ($mode === "TEXT_ONLY") {
            // return strictly plain text
            $plain = wp_strip_all_tags($text);
            // collapse whitespace
            $plain = preg_replace("/\s+/", " ", $plain);
            return trim($plain);
        }
        // HTML fragment (safe)
        return wp_kses_post($text);
    }

    private function call_openai($api_key, $original_html, $params)
    {
        $template = $params["prompt"] ?: $this->default_prompt_template();

        $output_mode = $params["output_mode"] ?? "HTML_FRAGMENT";
        $field_label = $params["field_label"] ?? "";
        $field_type = $params["field_type"] ?? "";
        $post_title = $params["post_title"] ?? "";
        $len_hint = max(10, strlen(trim(wp_strip_all_tags($original_html))));
        // Bound the hint to avoid extremes
        $len_hint = min(2000, max(20, $len_hint));

        $user_content = $this->build_prompt_from_template(
            $template,
            $original_html,
            $params["keywords"] ?? "",
            !empty($params["optimize"]),
            $output_mode,
            $len_hint,
            $post_title,
            $field_label,
            $field_type,
            $params["shortcodes_list"] ?? ""
        );

        $body = [
            "model" => "gpt-4o-mini",
            "messages" => [["role" => "user", "content" => $user_content]],
        ];

        $response = wp_remote_post(
            "https://api.openai.com/v1/chat/completions",
            [
                "headers" => [
                    "Content-Type" => "application/json",
                    "Authorization" => "Bearer " . $api_key,
                ],
                "body" => wp_json_encode($body),
                "timeout" => 60,
            ]
        );
        if (is_wp_error($response)) {
            return new WP_Error(
                "openai_err",
                "OpenAI request failed: " . $response->get_error_message()
            );
        }
        $status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if ($status < 200 || $status >= 300) {
            $msg = $data["error"]["message"] ?? ("HTTP " . $status);
            return new WP_Error("openai_http", "OpenAI request failed: " . $msg);
        }
        if (isset($data["error"])) {
            $msg = $data["error"]["message"] ?? "Unknown API error.";
            return new WP_Error("openai_api", "OpenAI error: " . $msg);
        }
        $text = $data["choices"][0]["message"]["content"] ?? "";
        if (!$text) {
            $snippet = $body ? substr($body, 0, 500) : "empty body";
            return new WP_Error(
                "openai_empty",
                "OpenAI returned empty content. Response: " . $snippet
            );
        }
        return $this->sanitize_model_output($text, $output_mode);
    }

    private function get_openai_key()
    {
        $key = '';

        // Primary: Anchor Tools settings (anchor_schema_settings)
        if (class_exists('Anchor_Schema_Admin')) {
            $global = get_option(Anchor_Schema_Admin::OPTION_KEY, []);
            $key = $global['api_key'] ?? '';
            if (!$key && isset($global['openai_api_key'])) {
                $key = $global['openai_api_key'];
            }
        }

        // Env/constant fallbacks
        if (!$key) {
            $key = getenv('OPENAI_API_KEY') ?: (defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '');
        }

        // Legacy options
        if (!$key) {
            $key = get_option($this->option_key) ?: get_option($this->legacy_option_key);
        }

        return trim($key);
    }

    private function get_bulk_queue_state()
    {
        $state = get_option('_anchor_bulk_queue_state', []);
        return is_array($state) ? $state : [];
    }

    private function set_bulk_queue_state($state)
    {
        update_option('_anchor_bulk_queue_state', $state, false);
    }

    private function init_bulk_queue($run_id, $total, $mode)
    {
        $state = $this->get_bulk_queue_state();
        $state[$run_id] = [
            'total' => (int) $total,
            'processed' => 0,
            'errors' => 0,
            'status' => 'queued',
            'mode' => $mode,
            'started' => current_time('mysql'),
            'updated' => current_time('mysql'),
        ];
        $this->set_bulk_queue_state($state);
    }

    private function bump_bulk_queue($run_id, $processed_inc, $errors_inc)
    {
        $state = $this->get_bulk_queue_state();
        if (!isset($state[$run_id])) {
            return;
        }
        $state[$run_id]['processed'] += (int) $processed_inc;
        $state[$run_id]['errors'] += (int) $errors_inc;
        $state[$run_id]['updated'] = current_time('mysql');
        if ($state[$run_id]['processed'] >= $state[$run_id]['total']) {
            $state[$run_id]['status'] = 'completed';
        } else {
            $state[$run_id]['status'] = 'running';
        }
        $this->set_bulk_queue_state($state);
    }

    /* ---------------- AJAX: PREVIEW (stage) ---------------- */

    public function ajax_bulk_preview()
    {
        if (!current_user_can("manage_options")) {
            wp_die("Unauthorized");
        }
        check_ajax_referer($this->nonce_action, $this->nonce_name);

        $api_key = $this->get_openai_key();
        if (!$api_key) {
            wp_die("OpenAI key not set.");
        }

        $ids = isset($_POST["ids"])
            ? array_map("intval", (array) $_POST["ids"])
            : [];
        $paramsBase = [
            "keywords" => sanitize_text_field($_POST["keywords"] ?? ""),
            "optimize" => intval($_POST["optimize"] ?? 0),
            "prompt" => wp_kses_post(stripslashes($_POST["prompt"] ?? "")),
        ];
        $acf_filter = sanitize_text_field($_POST["acf_fields"] ?? "");
        $minlen = max(0, intval($_POST["minlen"] ?? 0));
        $incACF = intval($_POST["include_acf"] ?? 1);
        $mode = sanitize_text_field($_POST["mode"] ?? "content");
        $allowed_modes = ["content", "post_content", "seo_meta", "jsonld"];
        if (!in_array($mode, $allowed_modes, true)) {
            $mode = "content";
        }
        if ($mode === "seo_meta") {
            $prompt = trim((string) ($paramsBase["prompt"] ?? ""));
            if ($prompt === "") {
                $paramsBase["prompt"] = $this->default_seo_prompt_template();
            }
        }
        $schema_type = sanitize_text_field($_POST["schema_type"] ?? "Article");
        $schema_custom = sanitize_text_field($_POST["schema_custom"] ?? "");
        $schema_target = $schema_custom ?: $schema_type;

        $run_id = sanitize_text_field($_POST["run_id"] ?? "");
        if ($run_id) {
            $stash = get_transient($run_id);
            if (!is_array($stash)) {
                $stash = [];
            }
        } else {
            $run_id = "ai_br_" . wp_generate_password(12, false);
            $stash = [];
        }

        $log = [];

        foreach ($ids as $post_id) {
            $post = get_post($post_id);
            if (!$post) {
                $log_message = "[$post_id] Invalid post.";
                $log[] = $log_message;
                error_log("AI Bulk Rewriter (Preview Error): " . $log_message); // Kinsta Log
                continue;
            }

            $entry = [
                "title" => get_the_title($post_id),
                "mode" => $mode,
                "acf" => [],
                "content" => [],
                "seo" => [],
                "jsonld" => [],
            ];

            if ($mode === "jsonld") {
                $context = $this->collect_jsonld_context($post, $incACF, $paramsBase);
                $raw = $context["raw"];
                if (empty($raw)) {
                    $log_message = "[$post_id] “" . $entry["title"] . "” — No content available for JSON-LD.";
                    $log[] = $log_message;
                    continue;
                }
                $prompt = Anchor_Schema_Helper::build_prompt($schema_target ?: "Thing", wp_strip_all_tags($raw));
                $response = Anchor_Schema_Helper::call_openai($api_key, $model, $prompt);
                if (is_wp_error($response)) {
                    $log[] = "[$post_id] JSON-LD generation failed: " . $response->get_error_message();
                    continue;
                }
                $json = Anchor_Schema_Helper::extract_json($response);
                if (empty($json)) {
                    $log[] = "[$post_id] JSON-LD generation returned no JSON.";
                    continue;
                }
                $validation = Anchor_Schema_Helper::validate_schema_json($json);
                if (!empty($validation["errors"])) {
                    $log[] = "[$post_id] JSON-LD validation failed: " . implode("; ", $validation["errors"]);
                    continue;
                }
                $normalized = $validation["normalized_json"] ?: $json;
                $pretty = $this->pretty_print_json($normalized);
                $entry["jsonld"][] = [
                    "id" => uniqid("schema_", true),
                    "label" => ($schema_target ?: "Thing") . " schema",
                    "type" => $validation["primary_type"] ?: $schema_target,
                    "json" => $pretty,
                    "raw" => $raw,
                    "warnings" => $validation["warnings"],
                ];
            } elseif ($mode === "seo_meta") {
                $yoast_items = $this->get_yoast_fields($post_id);
                foreach ($yoast_items as $f) {
                    [$clean, $map] = $this->protect_yoast_vars($f["value"]);
                    $rw = $this->call_openai(
                        $api_key,
                        $clean,
                        array_merge($paramsBase, [
                            "output_mode" => "TEXT_ONLY",
                            "field_label" => $f["label"],
                            "field_type" => $f["type"],
                            "post_title" => $entry["title"],
                        ])
                    );
                    if (is_wp_error($rw)) {
                        continue;
                    }
                    $rw = $this->restore_yoast_vars($rw, $map);
                    $rw = $this->normalize_seo_output($rw, $f["type"]);
                    if (trim($rw) === '') {
                        $rw = $f["value"];
                    }
                    $entry["seo"][] = [
                        "id" => $f["id"],
                        "label" => $f["label"],
                        "meta_key" => $f["meta_key"],
                        "orig_html" => $f["value"],
                        "new_html" => $rw,
                        "type" => $f["type"],
                    ];
                }
            } elseif ($mode === "post_content") {
                $raw = (string) $post->post_content;
                $plain = trim(wp_strip_all_tags($raw));
                if (strlen($plain) < $minlen) {
                    $log_message = "[$post_id] “" . $entry["title"] . "” — Post content below min length.";
                    $log[] = $log_message;
                } elseif ($this->has_structured_layout_markup($raw)) {
                    $log_message = "[$post_id] “" . $entry["title"] . "” — Skipped post content due to layout markup.";
                    $log[] = $log_message;
                } else {
                    [$clean, $map] = $this->protect_shortcodes($raw);
                    $shortcodes_list = $this->build_shortcodes_list($map);
                    $rewrite_params = array_merge($paramsBase, [
                        "output_mode" => "HTML_FRAGMENT",
                        "field_label" => "Post Content",
                        "field_type" => "post_content",
                        "shortcodes_list" => $shortcodes_list,
                        "post_title" => $entry["title"],
                    ]);
                    $rw = $this->rewrite_html_text_nodes($clean, $api_key, $rewrite_params, 40);
                    if (is_wp_error($rw)) {
                        $log_message = "[$post_id] “" . $entry["title"] . "” — Skipped post content: " . $rw->get_error_message();
                        $log[] = $log_message;
                    } else {
                        $rw = $this->restore_shortcodes($rw, $map);
                        $entry["content"][] = [
                            "id" => "post_content",
                            "label" => "Post Content",
                            "orig_html" => $raw,
                            "new_html" => $rw,
                            "type" => "post_content",
                        ];
                    }
                }

                $excerpt = (string) $post->post_excerpt;
                $excerpt_plain = trim(wp_strip_all_tags($excerpt));
                if ($excerpt_plain !== '' && strlen($excerpt_plain) >= $minlen) {
                    [$clean_ex, $map_ex] = $this->protect_shortcodes($excerpt);
                    $shortcodes_list_ex = $this->build_shortcodes_list($map_ex);
                    $rewrite_params_ex = array_merge($paramsBase, [
                        "output_mode" => "TEXT_ONLY",
                        "field_label" => "Post Excerpt",
                        "field_type" => "post_excerpt",
                        "shortcodes_list" => $shortcodes_list_ex,
                        "post_title" => $entry["title"],
                    ]);
                    $rw_ex = $this->call_openai($api_key, $clean_ex, $rewrite_params_ex);
                    if (is_wp_error($rw_ex)) {
                        $log_message = "[$post_id] “" . $entry["title"] . "” — Skipped post excerpt: " . $rw_ex->get_error_message();
                        $log[] = $log_message;
                    } else {
                        $rw_ex = $this->restore_shortcodes($rw_ex, $map_ex);
                        $entry["content"][] = [
                            "id" => "post_excerpt",
                            "label" => "Post Excerpt",
                            "orig_html" => $excerpt,
                            "new_html" => $rw_ex,
                            "type" => "post_excerpt",
                        ];
                    }
                } elseif ($excerpt_plain !== '') {
                    $log_message = "[$post_id] “" . $entry["title"] . "” — Post excerpt below min length.";
                    $log[] = $log_message;
                }
            } else {
                // ACF
                if ($incACF && function_exists("get_field_object")) {
                    $acf_items = $this->get_acf_fields($post_id);
                    $acf_items = $this->filter_acf_items($acf_items, $acf_filter);
                    if (empty($acf_items) && $acf_filter !== "") {
                        $log_message = "[$post_id] “" . $entry["title"] . "” — No ACF fields matched filter.";
                        $log[] = $log_message;
                    }
                    foreach ($acf_items as $f) {
                        if ($f["len"] < $minlen) {
                            continue;
                        }

                        // Decide output mode by ACF type
                        $field_mode =
                            $f["type"] === "wysiwyg"
                                ? "HTML_FRAGMENT"
                                : "TEXT_ONLY";

                        if ($this->has_structured_layout_markup($f["value"])) {
                            $log_message = "[$post_id] “" . $entry["title"] . "” — Skipped ACF field (“" . $f["label"] . "”) due to layout markup.";
                            $log[] = $log_message;
                            continue;
                        }

                        [$clean, $map] = $this->protect_shortcodes($f["value"]);
                        $shortcodes_list = $this->build_shortcodes_list($map);

                        $rewrite_params = array_merge($paramsBase, [
                            "output_mode" => $field_mode,
                            "field_label" => $f["label"],
                            "field_type" => "acf_" . $f["type"],
                            "shortcodes_list" => $shortcodes_list,
                            "post_title" => $entry["title"],
                        ]);

                        if ($f["type"] === "wysiwyg") {
                            $rw = $this->rewrite_html_text_nodes($clean, $api_key, $rewrite_params);
                        } else {
                            $rw = $this->call_openai($api_key, $clean, $rewrite_params);
                        }
                        if (is_wp_error($rw)) {
                            $log_message = "[$post_id] “" . $entry["title"] . "” — Skipped ACF field (“" . $f["label"] . "”): " . $rw->get_error_message();
                            $log[] = $log_message;
                            continue;
                        }
                        $rw = $this->restore_shortcodes($rw, $map);

                        $entry["acf"][] = [
                            "id" => uniqid("a_", true),
                            "label" => $f["label"],
                            "type" => $f["type"],
                            "key" => $f["key"], // keep both key & paths
                            "path_names" => $f["path_names"],
                            "path_keys" => $f["path_keys"],
                            "orig_html" => $f["value"],
                            "new_html" => $rw,
                        ];
                    }
                }
            }

            $stash[$post_id] = $entry;
            if ($mode === "jsonld") {
                $log_message = "[$post_id] “" . $entry["title"] . "” — JSON-LD preview staged.";
            } else {
                $log_message = "[$post_id] “" . $entry["title"] . "” — Preview staged.";
            }
            $log[] = $log_message;
            error_log("AI Bulk Rewriter (Preview): " . $log_message); // Kinsta Log
        }

        set_transient(
            $run_id,
            $stash,
            $this->transient_ttl_min * MINUTE_IN_SECONDS
        );
        wp_send_json(["run_id" => $run_id, "log" => implode("\n", $log)]);
    }

    /* ---------------- AJAX: Wizard Step Data ---------------- */

    public function ajax_get_staged_for_post()
    {
        if (!current_user_can("manage_options")) {
            wp_die("Unauthorized");
        }
        check_ajax_referer($this->nonce_action, $this->nonce_name);

        $run_id = sanitize_text_field($_POST["run_id"] ?? "");
        $post_id = intval($_POST["post_id"] ?? 0);
        if (!$run_id || !$post_id) {
            wp_die("Missing data.");
        }

        $stash = get_transient($run_id);
        if (!$stash || !isset($stash[$post_id])) {
            wp_die("Preview expired or not found.");
        }

        $entry = $stash[$post_id];
            $payload = [
                "title" => $entry["title"] ?? "",
                "acf" => array_map(
                    fn($a) => [
                        "id" => $a["id"],
                    "label" => $a["label"],
                    "orig_html" => $a["orig_html"],
                    "new_html" => $a["new_html"],
                    ],
                    $entry["acf"] ?? []
                ),
                "content" => array_map(
                    fn($c) => [
                        "id" => $c["id"],
                        "label" => $c["label"],
                        "orig_html" => $c["orig_html"],
                        "new_html" => $c["new_html"],
                    ],
                    $entry["content"] ?? []
                ),
                "seo" => array_map(
                    fn($s) => [
                        "id" => $s["id"],
                        "label" => $s["label"],
                        "orig_html" => $s["orig_html"],
                        "new_html" => $s["new_html"],
                        "meta_key" => $s["meta_key"] ?? "",
                    ],
                    $entry["seo"] ?? []
                ),
                "jsonld" => array_map(
                    fn($schema) => [
                        "id" => $schema["id"],
                        "label" => $schema["label"],
                        "type" => $schema["type"],
                    "json" => $schema["json"],
                    "raw" => $schema["raw"] ?? "",
                    "warnings" => $schema["warnings"] ?? [],
                ],
                $entry["jsonld"] ?? []
            ),
        ];

        wp_send_json($payload);
    }

    /* ---------------- AJAX: Apply Approved ---------------- */

    public function ajax_bulk_apply_approved()
    {
        if (!current_user_can("manage_options")) {
            wp_die("Unauthorized");
        }
        check_ajax_referer($this->nonce_action, $this->nonce_name);

        $run_id = sanitize_text_field($_POST["run_id"] ?? "");
        $approved = json_decode(stripslashes($_POST["approved"] ?? "{}"), true);
        $overrides = json_decode(stripslashes($_POST["overrides"] ?? "{}"), true);
        if (!$run_id) {
            wp_die("Missing run_id.");
        }
        if (!is_array($approved)) {
            wp_die("Invalid approvals.");
        }
        if (!is_array($overrides)) {
            $overrides = [];
        }

        $stash = get_transient($run_id);
        if (!$stash || !is_array($stash)) {
            wp_die("Preview expired or not found.");
        }

        $log = [];

        foreach ($approved as $post_id => $ids) {
            $post_id = intval($post_id);
            $entry = $stash[$post_id] ?? null;
            if (!$entry) {
                $log_message = "[$post_id] No staged data.";
                $log[] = $log_message;
                error_log("AI Bulk Rewriter (Apply Error): " . $log_message); // Kinsta Log
                continue;
            }

            $post = get_post($post_id);
            if (!$post) {
                $log_message = "[$post_id] Invalid post.";
                $log[] = $log_message;
                error_log("AI Bulk Rewriter (Apply Error): " . $log_message); // Kinsta Log
                continue;
            }

            $idset = array_flip((array) $ids);
            $acfApplied = 0;
            $acfErrors = 0;
            $contentApplied = 0;
            $contentErrors = 0;
            $seoApplied = 0;
            $seoErrors = 0;
            $schemaApplied = 0;
            $entry_mode = $entry["mode"] ?? "content";

            // --- Build list of approved ACF items ---
            $items_to_commit = [];
            if ($entry_mode === "content" && !empty($entry["acf"])) {
                foreach ($entry["acf"] as $a) {
                    if (isset($idset[$a["id"]])) {
                        if (isset($overrides[$post_id][$a["id"]])) {
                            $raw_override = (string) $overrides[$post_id][$a["id"]];
                            $a["new_html"] = ($a["type"] === "wysiwyg") ? wp_kses_post($raw_override) : sanitize_text_field($raw_override);
                        }
                        $items_to_commit[] = $a; // Add the full item object
                    }
                }
            }

            // --- Commit them all at once ---
            if (!empty($items_to_commit)) {
                list($acfApplied, $acfErrors) = $this->commit_acf_updates($post_id, $items_to_commit);
            }

            // Post content
            $content_items_to_commit = [];
            if (!empty($entry["content"])) {
                foreach ($entry["content"] as $c) {
                    if (isset($idset[$c["id"]])) {
                        if (isset($overrides[$post_id][$c["id"]])) {
                            $c["new_html"] = wp_kses_post((string) $overrides[$post_id][$c["id"]]);
                        }
                        $content_items_to_commit[] = $c;
                    }
                }
            }
            if ($entry_mode === "post_content" && !empty($content_items_to_commit)) {
                list($contentApplied, $contentErrors) = $this->commit_post_content_updates($post_id, $content_items_to_commit);
            }

            // SEO (Yoast)
            $seo_items_to_commit = [];
            if (!empty($entry["seo"])) {
                foreach ($entry["seo"] as $s) {
                    if (isset($idset[$s["id"]])) {
                        if (isset($overrides[$post_id][$s["id"]])) {
                            $s["new_html"] = sanitize_text_field((string) $overrides[$post_id][$s["id"]]);
                        }
                        $field_type = $s["type"] ?? ($s["id"] === "yoast_title" ? "yoast_title" : "yoast_metadesc");
                        $s["new_html"] = $this->normalize_seo_output($s["new_html"], $field_type);
                        $seo_items_to_commit[] = $s;
                    }
                }
            }
            if ($entry_mode === "seo_meta" && !empty($seo_items_to_commit)) {
                list($seoApplied, $seoErrors) = $this->commit_seo_updates($post_id, $seo_items_to_commit);
            }

            // JSON-LD schemas
            if ($entry_mode === "jsonld" && !empty($entry["jsonld"])) {
                $items = get_post_meta($post_id, Anchor_Schema_Admin::META_KEY, true);
                if (!is_array($items)) {
                    $items = [];
                }
                $schema_overrides = isset($overrides[$post_id]) && is_array($overrides[$post_id]) ? $overrides[$post_id] : [];
                foreach ($entry["jsonld"] as $schema_row) {
                    if (!isset($idset[$schema_row["id"]])) {
                        continue;
                    }
                    $raw_json = isset($schema_overrides[$schema_row["id"]])
                        ? (string) $schema_overrides[$schema_row["id"]]
                        : (string) $schema_row["json"];
                    $validation = Anchor_Schema_Helper::validate_schema_json($raw_json);
                    if (!empty($validation["errors"])) {
                        $log[] = "[$post_id] JSON-LD not saved (“" . ($schema_row["label"] ?? "Schema") . "”): " . implode("; ", $validation["errors"]);
                        continue;
                    }
                    $normalized = $validation["normalized_json"] ?: $raw_json;
                    $min = Anchor_Schema_Helper::minify_json($normalized) ?: $normalized;
                    $items[] = [
                        "id" => wp_generate_uuid4(),
                        "type" => $validation["primary_type"] ?: ($schema_row["type"] ?? "Thing"),
                        "raw_text" => $schema_row["raw"] ?? "",
                        "json" => $normalized,
                        "min_json" => $min,
                        "updated" => current_time("mysql"),
                        "enabled" => true,
                        "label" => $schema_row["label"] ?? (($schema_row["type"] ?? "Thing") . " schema"),
                    ];
                    $schemaApplied++;
                }
                if ($schemaApplied > 0) {
                    update_post_meta($post_id, Anchor_Schema_Admin::META_KEY, $items);
                }
            }

            $log_message =
                "[$post_id] “" .
                ($entry["title"] ?? get_the_title($post_id)) .
                "” — Applied ACF: $acfApplied (Errors: $acfErrors), Content: $contentApplied (Errors: $contentErrors), SEO: $seoApplied (Errors: $seoErrors), Schema: $schemaApplied";
                
            $log[] = $log_message;
            error_log("AI Bulk Rewriter (Apply Approved): " . $log_message); // Kinsta Log
        }

        delete_transient($run_id);
        wp_die(implode("\n", $log));
    }

    private function run_bulk_apply_for_ids($ids, $api_key, $paramsBase, $minlen, $incACF, $mode)
    {
        $log = [];
        $stats = [
            'processed' => 0,
            'errors' => 0,
        ];
        $acf_filter = (string) ($paramsBase["acf_fields"] ?? "");

        foreach ($ids as $post_id) {
            $post = get_post($post_id);
            if (!$post) {
                $log_message = "[$post_id] Invalid post.";
                $log[] = $log_message;
                error_log("AI Bulk Rewriter (Direct Apply Error): " . $log_message);
                $stats['processed']++;
                continue;
            }

            $acfUpdated = $acfErr = 0;

            if ($mode === "seo_meta") {
                $items_to_commit = [];
                $yoast_items = $this->get_yoast_fields($post_id);
                foreach ($yoast_items as $f) {
                    [$clean, $map] = $this->protect_yoast_vars($f["value"]);
                    $rw = $this->call_openai($api_key, $clean, array_merge($paramsBase, [
                        "output_mode" => "TEXT_ONLY",
                        "field_label" => $f["label"],
                        "field_type" => $f["type"],
                        "post_title" => get_the_title($post_id),
                    ]));
                    if (is_wp_error($rw)) { $acfErr++; continue; }
                    $rw = $this->restore_yoast_vars($rw, $map);
                    $rw = $this->normalize_seo_output($rw, $f["type"]);
                    if (trim($rw) === '') {
                        $rw = $f["value"];
                    }
                    $items_to_commit[] = array_merge($f, ["new_html" => $rw]);
                }
                if (!empty($items_to_commit)) {
                    list($seoApplied, $seoErrors) = $this->commit_seo_updates($post_id, $items_to_commit);
                    $acfUpdated += $seoApplied;
                    $acfErr += $seoErrors;
                }
            } elseif ($mode === "post_content") {
                $items_to_commit = [];
                $raw = (string) $post->post_content;
                $plain = trim(wp_strip_all_tags($raw));
                if (strlen($plain) < $minlen) {
                    $log_message = "[$post_id] “" . get_the_title($post_id) . "” — Post content below min length.";
                    $log[] = $log_message;
                } elseif ($this->has_structured_layout_markup($raw)) {
                    $log_message = "[$post_id] “" . get_the_title($post_id) . "” — Skipped post content due to layout markup.";
                    $log[] = $log_message;
                } else {
                    [$clean, $map] = $this->protect_shortcodes($raw);
                    $shortcodes_list = $this->build_shortcodes_list($map);
                    $rewrite_params = array_merge($paramsBase, [
                        "output_mode" => "HTML_FRAGMENT",
                        "field_label" => "Post Content",
                        "field_type" => "post_content",
                        "shortcodes_list" => $shortcodes_list,
                        "post_title" => get_the_title($post_id),
                    ]);

                    $rw = $this->rewrite_html_text_nodes($clean, $api_key, $rewrite_params, 40);
                    if (is_wp_error($rw)) {
                        $acfErr++;
                        $log_message = "[$post_id] “" . get_the_title($post_id) . "” — Skipped post content: " . $rw->get_error_message();
                        $log[] = $log_message;
                    } else {
                        $rw = $this->restore_shortcodes($rw, $map);
                        $items_to_commit[] = [
                            "id" => "post_content",
                            "label" => "Post Content",
                            "orig_html" => $raw,
                            "new_html" => $rw,
                            "type" => "post_content",
                        ];
                    }
                }

                $excerpt = (string) $post->post_excerpt;
                $excerpt_plain = trim(wp_strip_all_tags($excerpt));
                if ($excerpt_plain !== '' && strlen($excerpt_plain) >= $minlen) {
                    [$clean_ex, $map_ex] = $this->protect_shortcodes($excerpt);
                    $shortcodes_list_ex = $this->build_shortcodes_list($map_ex);
                    $rewrite_params_ex = array_merge($paramsBase, [
                        "output_mode" => "TEXT_ONLY",
                        "field_label" => "Post Excerpt",
                        "field_type" => "post_excerpt",
                        "shortcodes_list" => $shortcodes_list_ex,
                        "post_title" => get_the_title($post_id),
                    ]);

                    $rw_ex = $this->call_openai($api_key, $clean_ex, $rewrite_params_ex);
                    if (is_wp_error($rw_ex)) {
                        $acfErr++;
                        $log_message = "[$post_id] “" . get_the_title($post_id) . "” — Skipped post excerpt: " . $rw_ex->get_error_message();
                        $log[] = $log_message;
                    } else {
                        $rw_ex = $this->restore_shortcodes($rw_ex, $map_ex);
                        $items_to_commit[] = [
                            "id" => "post_excerpt",
                            "label" => "Post Excerpt",
                            "orig_html" => $excerpt,
                            "new_html" => $rw_ex,
                            "type" => "post_excerpt",
                        ];
                    }
                } elseif ($excerpt_plain !== '') {
                    $log_message = "[$post_id] “" . get_the_title($post_id) . "” — Post excerpt below min length.";
                    $log[] = $log_message;
                }

                if (!empty($items_to_commit)) {
                    list($contentApplied, $contentErrors) = $this->commit_post_content_updates($post_id, $items_to_commit);
                    $acfUpdated += $contentApplied;
                    $acfErr += $contentErrors;
                }
            } else {
                // --- ACF LOGIC (Direct Apply) ---
                if ($incACF && function_exists("get_field_object")) {
                    $items_to_commit = [];
                    $acf_items = $this->get_acf_fields($post_id);
                    $acf_items = $this->filter_acf_items($acf_items, $acf_filter);
                    if (empty($acf_items) && $acf_filter !== "") {
                        $log_message = "[$post_id] “" . get_the_title($post_id) . "” — No ACF fields matched filter.";
                        $log[] = $log_message;
                    }

                    foreach ($acf_items as $f) {
                        if ($f["len"] < $minlen) { continue; }

                        $out_mode = ($f["type"] === "wysiwyg") ? "HTML_FRAGMENT" : "TEXT_ONLY";
                        if ($this->has_structured_layout_markup($f["value"])) {
                            $log_message = "[$post_id] “" . get_the_title($post_id) . "” — Skipped ACF field (“" . $f["label"] . "”) due to layout markup.";
                            $log[] = $log_message;
                            continue;
                        }
                        [$clean, $map] = $this->protect_shortcodes($f["value"]);
                        $shortcodes_list = $this->build_shortcodes_list($map);

                        $rewrite_params = array_merge($paramsBase, [
                            "output_mode" => $out_mode,
                            "field_label" => $f["label"],
                            "field_type" => "acf_" . $f["type"],
                            "shortcodes_list" => $shortcodes_list,
                            "post_title" => get_the_title($post_id),
                        ]);

                        if ($f["type"] === "wysiwyg") {
                            $rw = $this->rewrite_html_text_nodes($clean, $api_key, $rewrite_params);
                        } else {
                            $rw = $this->call_openai($api_key, $clean, $rewrite_params);
                        }

                        if (is_wp_error($rw)) {
                            $acfErr++;
                            $log_message = "[$post_id] “" . get_the_title($post_id) . "” — Skipped ACF field (“" . $f["label"] . "”): " . $rw->get_error_message();
                            $log[] = $log_message;
                            continue;
                        }

                        $rw = $this->restore_shortcodes($rw, $map);

                        $f['new_html'] = $rw;
                        $items_to_commit[] = $f;
                    }

                    if (!empty($items_to_commit)) {
                        list($acfUpdated, $saveErrors) = $this->commit_acf_updates($post_id, $items_to_commit);
                        $acfErr += $saveErrors;
                    }
                }
            }

            $log_message =
                "[$post_id] “" .
                get_the_title($post_id) .
                "” — Updated: $acfUpdated (errors $acfErr)";
            $log[] = $log_message;
            error_log("AI Bulk Rewriter (Direct Apply): " . $log_message);
            $stats['processed']++;
            $stats['errors'] += $acfErr;
        }

        return [$log, $stats];
    }

    public function process_bulk_queue($payload)
    {
        $run_id = isset($payload['run_id']) ? sanitize_text_field($payload['run_id']) : '';
        $ids = isset($payload['ids']) ? array_map('intval', (array) $payload['ids']) : [];
        if (!$run_id || empty($ids)) {
            return;
        }

        $api_key = $this->get_openai_key();
        if (!$api_key) {
            $this->bump_bulk_queue($run_id, count($ids), count($ids));
            return;
        }

        $paramsBase = isset($payload['params']) && is_array($payload['params']) ? $payload['params'] : [];
        $minlen = isset($payload['minlen']) ? (int) $payload['minlen'] : 0;
        $incACF = isset($payload['incACF']) ? (int) $payload['incACF'] : 1;
        $mode = isset($payload['mode']) ? sanitize_text_field($payload['mode']) : 'content';

        list($log, $stats) = $this->run_bulk_apply_for_ids($ids, $api_key, $paramsBase, $minlen, $incACF, $mode);
        $this->bump_bulk_queue($run_id, $stats['processed'], $stats['errors']);
    }

    public function ajax_bulk_queue()
    {
        if (!current_user_can("manage_options")) {
            wp_die("Unauthorized");
        }
        check_ajax_referer($this->nonce_action, $this->nonce_name);

        if (!function_exists('as_enqueue_async_action')) {
            wp_die("Action Scheduler not available.");
        }

        $api_key = $this->get_openai_key();
        if (!$api_key) {
            wp_die("OpenAI key not set.");
        }

        $ids = isset($_POST["ids"])
            ? array_map("intval", (array) $_POST["ids"])
            : [];
        if (empty($ids)) {
            wp_die("No posts selected.");
        }

        $paramsBase = [
            "keywords" => sanitize_text_field($_POST["keywords"] ?? ""),
            "optimize" => intval($_POST["optimize"] ?? 0),
            "prompt" => wp_kses_post(stripslashes($_POST["prompt"] ?? "")),
            "acf_fields" => sanitize_text_field($_POST["acf_fields"] ?? ""),
        ];
        $minlen = max(0, intval($_POST["minlen"] ?? 0));
        $incACF = intval($_POST["include_acf"] ?? 1);
        $mode = sanitize_text_field($_POST["mode"] ?? "content");
        $allowed_modes = ["content", "post_content", "seo_meta", "jsonld"];
        if (!in_array($mode, $allowed_modes, true)) {
            $mode = "content";
        }
        if ($mode === "seo_meta") {
            $prompt = trim((string) ($paramsBase["prompt"] ?? ""));
            if ($prompt === "") {
                $paramsBase["prompt"] = $this->default_seo_prompt_template();
            }
        }
        if ($mode === "jsonld") {
            wp_die("JSON-LD mode requires Preview → Review → Apply Approved.");
        }

        $batch_size = intval($_POST["batch_size"] ?? 5);
        $batch_size = max(1, min(10, $batch_size));

        $run_id = "ai_br_queue_" . wp_generate_password(12, false);
        $this->init_bulk_queue($run_id, count($ids), $mode);

        foreach (array_chunk($ids, $batch_size) as $chunk) {
            as_enqueue_async_action(
                "anchor_tools_bulk_rewrite_process",
                [
                    "run_id" => $run_id,
                    "ids" => $chunk,
                    "params" => $paramsBase,
                    "minlen" => $minlen,
                    "incACF" => $incACF,
                    "mode" => $mode,
                ],
                "anchor-tools"
            );
        }

        wp_send_json([
            "run_id" => $run_id,
            "total" => count($ids),
            "batch_size" => $batch_size,
        ]);
    }

    public function ajax_bulk_queue_status()
    {
        if (!current_user_can("manage_options")) {
            wp_die("Unauthorized");
        }
        check_ajax_referer($this->nonce_action, $this->nonce_name);

        $run_id = sanitize_text_field($_POST["run_id"] ?? "");
        if (!$run_id) {
            wp_die("Missing run_id.");
        }

        $state = $this->get_bulk_queue_state();
        if (!isset($state[$run_id])) {
            wp_die("Queue not found.");
        }
        wp_send_json($state[$run_id]);
    }

    /* ---------------- AJAX: Direct Apply (no wizard) ---------------- */

    public function ajax_bulk_apply()
    {
        if (!current_user_can("manage_options")) {
            wp_die("Unauthorized");
        }
        check_ajax_referer($this->nonce_action, $this->nonce_name);

        $api_key = $this->get_openai_key();
        if (!$api_key) {
            wp_die("OpenAI key not set.");
        }

        $ids = isset($_POST["ids"])
            ? array_map("intval", (array) $_POST["ids"])
            : [];
        $paramsBase = [
            "keywords" => sanitize_text_field($_POST["keywords"] ?? ""),
            "optimize" => intval($_POST["optimize"] ?? 0),
            "prompt" => wp_kses_post(stripslashes($_POST["prompt"] ?? "")),
            "acf_fields" => sanitize_text_field($_POST["acf_fields"] ?? ""),
        ];
        $minlen = max(0, intval($_POST["minlen"] ?? 0));
        $incACF = intval($_POST["include_acf"] ?? 1);
        $mode = sanitize_text_field($_POST["mode"] ?? "content");
        $allowed_modes = ["content", "post_content", "seo_meta", "jsonld"];
        if (!in_array($mode, $allowed_modes, true)) {
            $mode = "content";
        }
        if ($mode === "seo_meta") {
            $prompt = trim((string) ($paramsBase["prompt"] ?? ""));
            if ($prompt === "") {
                $paramsBase["prompt"] = $this->default_seo_prompt_template();
            }
        }
        if ($mode === "jsonld") {
            wp_die("JSON-LD mode requires Preview → Review → Apply Approved.");
        }

        list($log, $stats) = $this->run_bulk_apply_for_ids($ids, $api_key, $paramsBase, $minlen, $incACF, $mode);
        wp_die(implode("\n", $log));
    }
}

new AI_ACF_Bulk_Rewriter_Wizard();

if ( ! function_exists( 'anchor_tools_get_available_modules' ) ) {
    /**
     * Return bundled Anchor Tools submodules.
     *
     * @return array
     */
    function anchor_tools_get_available_modules() {
        return [
            'social_feed' => [
                'label'       => __( 'Anchor Social Feed', 'anchor-schema' ),
                'description' => __( 'Display curated social feeds via shortcode.', 'anchor-schema' ),
                'path'        => ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-social-feed/anchor-social-feed.php',
                'class'       => 'Anchor_Social_Feed_Module',
                'setup'       => function () {
                    add_filter( 'anchor_social_feed_parent_menu_slug', function () {
                        return 'options-general.php';
                    } );
                },
            ],
            'mega_menu' => [
                'label'       => __( 'Anchor Mega Menu', 'anchor-schema' ),
                'description' => __( 'Create reusable mega menu snippets.', 'anchor-schema' ),
                'path'        => ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-mega-menu/anchor-mega-menu.php',
                'class'       => 'Anchor_Mega_Menu_Module',
            ],
            'events_manager' => [
                'label'       => __( 'Anchor Events Manager', 'anchor-schema' ),
                'description' => __( 'Manage events, calendars, and registrations.', 'anchor-schema' ),
                'path'        => ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-events-manager/anchor-events-manager.php',
                'class'       => '\\Anchor\\Events\\Module',
            ],
            'store_locator' => [
                'label'       => __( 'Anchor Store Locator', 'anchor-schema' ),
                'description' => __( 'Add a map-based store locator with search and proximity filtering.', 'anchor-schema' ),
                'path'        => ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-store-locator/anchor-store-locator.php',
                'class'       => '\\Anchor\\StoreLocator\\Module',
            ],
            'webinars' => [
                'label'       => __( 'Anchor Webinars', 'anchor-schema' ),
                'description' => __( 'Publish gated webinars with Vimeo watch tracking.', 'anchor-schema' ),
                'path'        => ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-webinars/anchor-webinars.php',
                'class'       => '\\Anchor\\Webinars\\Module',
            ],
            'universal_popups' => [
                'label'       => __( 'Anchor Universal Popups', 'anchor-schema' ),
                'description' => __( 'Build reusable HTML/video popups with triggers.', 'anchor-schema' ),
                'path'        => ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-universal-popups/anchor-universal-popups.php',
                'class'       => 'Anchor_Universal_Popups_Module',
            ],
            'shortcodes' => [
                'label'       => __( 'Anchor Shortcodes', 'anchor-schema' ),
                'description' => __( 'Manage general business info + custom shortcodes.', 'anchor-schema' ),
                'path'        => ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-shortcodes/anchor-shortcodes.php',
                'class'       => 'Anchor_Shortcodes_Module',
            ],
            'video_slider' => [
                'label'       => __( 'Anchor Video Gallery', 'anchor-schema' ),
                'description' => __( 'Create video galleries with slider, grid, carousel, and masonry layouts.', 'anchor-schema' ),
                'path'        => ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-video-slider/anchor-video-slider.php',
                'class'       => 'Anchor_Video_Slider_Module',
            ],
            'quick_edit' => [
                'label'       => __( 'Anchor Quick Edit', 'anchor-schema' ),
                'description' => __( 'Quick Edit fields for Yoast SEO and featured image editing.', 'anchor-schema' ),
                'path'        => ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-quick-edit/anchor-quick-edit.php',
                'class'       => 'Anchor_Quick_Edit_Module',
            ],
            'ctm_forms' => [
                'label'       => __( 'Anchor CTM Forms', 'anchor-schema' ),
                'description' => __( 'Create custom forms that submit to CallTrackingMetrics FormReactors.', 'anchor-schema' ),
                'path'        => ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-ctm-forms/anchor-ctm-forms.php',
                'class'       => 'Anchor_CTM_Forms_Module',
            ],
            'code_snippets' => [
                'label'       => __( 'Anchor Code Snippets', 'anchor-schema' ),
                'description' => __( 'Insert code snippets into header, body, or footer globally or per page.', 'anchor-schema' ),
                'path'        => ANCHOR_TOOLS_PLUGIN_DIR . 'anchor-code-snippets/anchor-code-snippets.php',
                'class'       => 'Anchor_Code_Snippets_Module',
            ],
        ];
    }
}

if ( ! function_exists( 'anchor_tools_is_module_enabled' ) ) {
    /**
     * Determine if a module is enabled via settings.
     *
     * @param string $module_key
     * @return bool
     */
    function anchor_tools_is_module_enabled( $module_key ) {
        $settings = get_option( Anchor_Schema_Admin::OPTION_KEY, [] );
        if ( empty( $settings['modules'] ) || ! is_array( $settings['modules'] ) ) {
            return true;
        }
        if ( ! array_key_exists( $module_key, $settings['modules'] ) ) {
            return true;
        }
        return (bool) $settings['modules'][ $module_key ];
    }
}

if ( ! function_exists( 'anchor_tools_bootstrap_modules' ) ) {
    /**
     * Load enabled modules and instantiate their classes.
     *
     * @return void
     */
    function anchor_tools_bootstrap_modules() {
        $modules = anchor_tools_get_available_modules();
        foreach ( $modules as $key => $module ) {
            if ( ! anchor_tools_is_module_enabled( $key ) ) {
                continue;
            }

            if ( isset( $module['setup'] ) && is_callable( $module['setup'] ) ) {
                call_user_func( $module['setup'] );
            }

            if ( isset( $module['path'] ) && file_exists( $module['path'] ) ) {
                require_once $module['path'];
            }

            if ( isset( $module['class'] ) && class_exists( $module['class'] ) ) {
                new $module['class']();
            }

            if ( isset( $module['loader'] ) && is_callable( $module['loader'] ) ) {
                call_user_func( $module['loader'] );
            }
        }
    }

    add_action( 'plugins_loaded', 'anchor_tools_bootstrap_modules', 25 );
}
