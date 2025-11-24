<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class ACG_Logger {
    public static function enabled(){
        $opts = get_option( ACG_Admin::OPTION_KEY, [] );
        if ( empty($opts) ) {
            // back-compat to old option name
            $opts = get_option( 'anchor_schema_settings', [] );
        }
        return ! empty( $opts['debug'] );
    }
    public static function log( $event, $context = [] ){
        if ( ! self::enabled() ) { return; }
        $user = function_exists('get_current_user_id') ? get_current_user_id() : 0;
        $req  = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        if ( isset($context['body']) ) { $context['body'] = '[omitted]'; }
        if ( isset($context['api_key']) ) { $context['api_key'] = '[omitted]'; }
        error_log('[Anchor Tools] ' . $event . ' | user=' . $user . ' | req=' . $req . ' | ' . wp_json_encode($context));
    }
}
