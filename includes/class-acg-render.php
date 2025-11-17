<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class ACG_Render {
    public function __construct(){
        add_action('wp_head', [ $this, 'output_active_schemas' ], 99);
    }

    public function output_active_schemas(){
        if ( is_admin() ) { return; }
        if ( ! is_singular() ) { return; }
        global $post;
        if ( ! $post ) { return; }
        $items = get_post_meta($post->ID, ACG_Admin::META_KEY, true);
        if ( ! is_array($items) || empty($items) ) { return; }
        $printed = 0;
        foreach($items as $it){
            if ( empty($it['enabled']) ) { continue; }
            $schema = isset($it['min_json']) ? $it['min_json'] : '';
            if ( ! $schema ) { $schema = $it['json'] ?? ''; }
            if ( ! $schema ) { continue; }
            echo "\n<script type=\"application/ld+json\">" . $schema . "</script>\n";
            $printed++;
        }
        if ( class_exists('ACG_Logger') ) {
            ACG_Logger::log('render:printed', [ 'post_id' => $post->ID, 'count' => $printed ]);
        }
    }
}
