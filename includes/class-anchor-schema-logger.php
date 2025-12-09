<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Anchor_Schema_Logger {
    public static function enabled(){
        $opts = get_option( Anchor_Schema_Admin::OPTION_KEY, [] );
        return ! empty( $opts['debug'] );
    }
    public static function log( $event, $context = [] ){
        if ( ! self::enabled() ) { return; }
        $user = function_exists('get_current_user_id') ? get_current_user_id() : 0;
        $req  = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        if ( isset($context['body']) ) { $context['body'] = '[omitted]'; }
        if ( isset($context['api_key']) ) { $context['api_key'] = '[omitted]'; }
        error_log('[Anchor Schema] ' . $event . ' | user=' . $user . ' | req=' . $req . ' | ' . wp_json_encode($context));
    }
}
