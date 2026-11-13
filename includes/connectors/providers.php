<?php
/**
 * OAuth provider definitions. Endpoints are hard-coded on purpose: they are never taken from user input.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'anchor_oauth_providers' ) ) {
    function anchor_oauth_providers() {
        return array(
            'google'    => array(
                'label'          => 'Google',
                'auth_url'       => 'https://accounts.google.com/o/oauth2/v2/auth',
                'token_url'      => 'https://oauth2.googleapis.com/token',
                'scopes'         => 'openid email https://www.googleapis.com/auth/spreadsheets https://www.googleapis.com/auth/drive',
                'auth_params'    => array( 'access_type' => 'offline', 'prompt' => 'consent' ),
                'token_scope'    => false, // Google token requests omit scope.
                'account_url'    => 'https://openidconnect.googleapis.com/v1/userinfo',
                'account_fields' => array( 'email' ),
            ),
            'microsoft' => array(
                'label'          => 'Microsoft',
                'auth_url'       => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
                'token_url'      => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
                'scopes'         => 'offline_access openid email User.Read Files.ReadWrite.All',
                'auth_params'    => array(),
                'token_scope'    => true, // Microsoft token requests include scope.
                'account_url'    => 'https://graph.microsoft.com/v1.0/me',
                'account_fields' => array( 'mail', 'userPrincipalName' ),
            ),
        );
    }
}
