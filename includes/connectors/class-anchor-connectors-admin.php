<?php
/**
 * Settings > Anchor Tools > Connectors tab: per-site OAuth app credentials, Connect / Disconnect.
 * Later features add their own settings to a provider card via the `anchor_connector_card_settings` action.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Anchor_Connectors_Admin' ) ) {
    class Anchor_Connectors_Admin {
        const TAB = 'connectors';

        public function __construct() {
            add_filter( 'anchor_settings_tabs', array( $this, 'register_tab' ), 15 );
            add_action( 'anchor_settings_enqueue_' . self::TAB, array( $this, 'enqueue' ) );
            add_action( 'admin_post_anchor_connector_save', array( $this, 'handle_save' ) );
            add_action( 'admin_post_anchor_connector_connect', array( $this, 'handle_connect' ) );
            add_action( 'admin_post_anchor_connector_disconnect', array( $this, 'handle_disconnect' ) );
            add_action( 'admin_post_anchor_connector_share_save', array( $this, 'handle_share_save' ) );
            add_action( 'anchor_connector_card_settings', array( $this, 'render_share_settings' ), 10, 2 );
        }

        public function register_tab( $tabs ) {
            $tabs[ self::TAB ] = array(
                'label'    => __( 'Connectors', 'anchor-schema' ),
                'callback' => array( $this, 'render_tab' ),
            );
            return $tabs;
        }

        public function enqueue() {
            wp_enqueue_script( 'anchor-connectors-admin', ANCHOR_TOOLS_PLUGIN_URL . 'assets/admin/connectors.js', array( 'jquery' ), '1.0.0', true );
            wp_localize_script( 'anchor-connectors-admin', 'AnchorConnectors', array(
                'copied'  => __( 'Copied', 'anchor-schema' ),
                'confirm' => __( 'Click again to disconnect', 'anchor-schema' ),
            ) );
        }

        /* ---------- handlers ---------- */

        /** Verifies capability + nonce, returns the connector for the posted provider. */
        private function authorize( $action ) {
            $provider = isset( $_REQUEST['provider'] ) ? sanitize_key( wp_unslash( $_REQUEST['provider'] ) ) : '';
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( 'You do not have permission to do that.', 'anchor-schema' ), 403 );
            }
            check_admin_referer( "anchor_connector_{$action}_{$provider}" );
            $connector = Anchor_OAuth_Connector::get( $provider );
            if ( ! $connector ) {
                wp_die( esc_html__( 'Unknown connector.', 'anchor-schema' ), 400 );
            }
            return $connector;
        }

        private function tab_url( array $args = array() ) {
            return add_query_arg( $args, admin_url( 'options-general.php?page=anchor-schema&tab=' . self::TAB ) );
        }

        public function handle_save() {
            $c = $this->authorize( 'save' );
            $c->save_app_credentials(
                isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '',
                isset( $_POST['client_secret'] ) ? trim( wp_unslash( $_POST['client_secret'] ) ) : '' // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            );
            wp_safe_redirect( $this->tab_url( array( 'anchor_saved' => sanitize_key( $_POST['provider'] ) ) ) );
            exit;
        }

        public function handle_connect() {
            $c = $this->authorize( 'connect' );
            if ( ! $c->has_app_credentials() ) {
                wp_safe_redirect( $this->tab_url( array( 'anchor_connect_error' => sanitize_key( $_REQUEST['provider'] ) ) ) );
                exit;
            }
            wp_redirect( $c->begin_authorize( get_current_user_id() ) ); // phpcs:ignore WordPress.Security.SafeRedirect -- provider endpoint is hard-coded.
            exit;
        }

        public function handle_disconnect() {
            $c = $this->authorize( 'disconnect' );
            $c->disconnect();
            wp_safe_redirect( $this->tab_url( array( 'anchor_disconnected' => sanitize_key( $_REQUEST['provider'] ) ) ) );
            exit;
        }

        /** Saves the Google auto-share settings (validated emails; invalid lines are dropped and counted). */
        public function handle_share_save() {
            $this->authorize( 'share_save' );
            if ( 'google' !== sanitize_key( $_POST['provider'] ?? '' ) ) {
                wp_die( esc_html__( 'Unknown connector.', 'anchor-schema' ), 400 );
            }
            $emails  = array();
            $dropped = 0;
            foreach ( preg_split( '/\R/', (string) wp_unslash( $_POST['share_emails'] ?? '' ) ) as $line ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
                $line = trim( $line );
                if ( '' === $line ) {
                    continue;
                }
                $e = is_email( $line ) ? sanitize_email( $line ) : '';
                if ( '' === $e ) {
                    ++$dropped;
                } else {
                    $emails[ strtolower( $e ) ] = strtolower( $e );
                }
            }
            // Written whole to its own option: never a read-modify-write of the token option.
            update_option( Anchor_Sheet_Google::SETTINGS_OPTION, array(
                'share_managers'       => ! empty( $_POST['share_managers'] ),
                'share_emails'         => array_values( $emails ),
                'share_role'           => 'reader' === ( $_POST['share_role'] ?? '' ) ? 'reader' : 'writer',
                'share_notify'         => ! empty( $_POST['share_notify'] ),
                'autocreate'           => ! empty( $_POST['autocreate'] ),
                'autocreate_folder_id' => Anchor_Sheet_Google::parse_folder_id( wp_unslash( $_POST['autocreate_folder'] ?? '' ) ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            ), false );
            wp_safe_redirect( $this->tab_url( array( 'anchor_share_saved' => 'google', 'anchor_share_dropped' => $dropped ) ) );
            exit;
        }

        /** Google card section: who spreadsheets are auto-shared with. */
        public function render_share_settings( $provider, $c ) {
            if ( 'google' !== $provider ) {
                return;
            }
            $s = Anchor_Sheet_Google::share_settings();
            echo '<h3>' . esc_html__( 'Auto-share spreadsheets with', 'anchor-schema' ) . '</h3>';
            if ( $c->is_connected() && ! $c->has_scope( Anchor_Sheet_Google::DRIVE_SCOPE ) ) {
                echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Reconnect to enable auto-share', 'anchor-schema' ) . ' &mdash; '
                    . esc_html__( 'this connection was made before Drive access was requested.', 'anchor-schema' ) . '</p></div>';
            }
            $inner  = '<table class="form-table" role="presentation"><tbody>';
            $inner .= '<tr><th>' . esc_html__( 'Share with event managers', 'anchor-schema' ) . '</th><td><label><input type="checkbox" name="share_managers" value="1"' . checked( $s['managers'], true, false ) . ' /> '
                . esc_html__( 'Everyone who can manage events, by their account email', 'anchor-schema' ) . '</label></td></tr>';
            $inner .= '<tr><th><label for="anchor-share-emails">' . esc_html__( 'Also share with', 'anchor-schema' ) . '</label></th><td><textarea id="anchor-share-emails" name="share_emails" rows="4" class="large-text code">' . esc_textarea( implode( "\n", $s['emails'] ) ) . '</textarea>'
                . '<p class="description">' . esc_html__( 'One email per line.', 'anchor-schema' ) . '</p></td></tr>';
            $inner .= '<tr><th><label for="anchor-share-role">' . esc_html__( 'Access', 'anchor-schema' ) . '</label></th><td><select id="anchor-share-role" name="share_role">'
                . '<option value="writer"' . selected( $s['role'], 'writer', false ) . '>' . esc_html__( 'Editor', 'anchor-schema' ) . '</option>'
                . '<option value="reader"' . selected( $s['role'], 'reader', false ) . '>' . esc_html__( 'Viewer', 'anchor-schema' ) . '</option></select></td></tr>';
            $inner .= '<tr><th>' . esc_html__( 'Notify people by email', 'anchor-schema' ) . '</th><td><label><input type="checkbox" name="share_notify" value="1"' . checked( $s['notify'], true, false ) . ' /> '
                . esc_html__( 'Send Google\'s own share notification', 'anchor-schema' ) . '</label></td></tr>';
            $a      = Anchor_Sheet_Google::autocreate_settings();
            $folder = '' !== $a['folder_id'] ? 'https://drive.google.com/drive/folders/' . $a['folder_id'] : '';
            $inner .= '<tr><th>' . esc_html__( 'Auto-create spreadsheets', 'anchor-schema' ) . '</th><td><label><input type="checkbox" name="autocreate" value="1"' . checked( $a['enabled'], true, false ) . ' /> '
                . esc_html__( 'Create a spreadsheet for each new event when it is first published', 'anchor-schema' ) . '</label></td></tr>';
            $inner .= '<tr><th><label for="anchor-autocreate-folder">' . esc_html__( 'Drive folder', 'anchor-schema' ) . '</label></th><td><input type="url" id="anchor-autocreate-folder" name="autocreate_folder" class="large-text" value="' . esc_attr( $folder ) . '" placeholder="https://drive.google.com/drive/folders/..." />'
                . '<p class="description">' . esc_html__( 'Optional. New spreadsheets are moved here; blank keeps them in My Drive.', 'anchor-schema' ) . '</p></td></tr>';
            $inner .= '</tbody></table><p class="description">' . esc_html__( 'Access is only ever added; remove people in Google.', 'anchor-schema' ) . '</p>'
                . '<p><button type="submit" class="button">' . esc_html__( 'Save auto-share and auto-create', 'anchor-schema' ) . '</button></p>';
            $this->post_form( 'share_save', $provider, $inner );
        }

        /* ---------- rendering ---------- */

        private function notices() {
            $map = array(
                'anchor_connected'    => array( 'success', __( '%s connected.', 'anchor-schema' ) ),
                'anchor_connect_error' => array( 'error', __( '%s could not be connected. Check the Client ID and secret, the redirect URI, and that you approved access.', 'anchor-schema' ) ),
                'anchor_saved'        => array( 'success', __( '%s credentials saved.', 'anchor-schema' ) ),
                'anchor_disconnected' => array( 'success', __( '%s disconnected.', 'anchor-schema' ) ),
            );
            if ( ! empty( $_GET['anchor_share_saved'] ) ) {
                $n = isset( $_GET['anchor_share_dropped'] ) ? absint( $_GET['anchor_share_dropped'] ) : 0;
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Auto-share settings saved.', 'anchor-schema' ) . '</p></div>';
                if ( $n > 0 ) {
                    /* translators: %d number of lines */
                    echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( sprintf( _n( '%d invalid email was dropped.', '%d invalid emails were dropped.', $n, 'anchor-schema' ), $n ) ) . '</p></div>';
                }
            }
            foreach ( $map as $arg => $def ) {
                if ( empty( $_GET[ $arg ] ) ) {
                    continue;
                }
                $c = Anchor_OAuth_Connector::get( sanitize_key( wp_unslash( $_GET[ $arg ] ) ) );
                if ( $c ) {
                    $msg  = sprintf( $def[1], $c->label() );
                    $code = 'anchor_connect_error' === $arg && isset( $_GET['anchor_connect_error_code'] ) ? sanitize_key( wp_unslash( $_GET['anchor_connect_error_code'] ) ) : '';
                    if ( '' !== $code ) {
                        /* translators: %s: OAuth error code such as invalid_client */
                        $msg .= ' ' . sprintf( __( '(Error: %s)', 'anchor-schema' ), $code );
                    }
                    printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $def[0] ), esc_html( $msg ) );
                }
            }
        }

        private function status( Anchor_OAuth_Connector $c ) {
            if ( $c->needs_reconnect() ) {
                return __( 'Needs reconnect', 'anchor-schema' );
            }
            if ( $c->is_connected() ) {
                $acct = $c->account();
                /* translators: %s: account email */
                return '' !== $acct ? sprintf( __( 'Connected as %s', 'anchor-schema' ), $acct ) : __( 'Connected', 'anchor-schema' );
            }
            return $c->has_app_credentials() ? __( 'Ready to connect', 'anchor-schema' ) : __( 'Not configured', 'anchor-schema' );
        }

        private function setup_steps( $provider ) {
            if ( 'google' === $provider ) {
                return array(
                    __( 'In Google Cloud Console, create an OAuth client of type "Web application" in a project whose consent screen allows the accounts that will connect (Internal = your Workspace accounts only, no Google review).', 'anchor-schema' ),
                    __( 'Add the callback URL above under "Authorized redirect URIs".', 'anchor-schema' ),
                    __( 'Enable the Google Sheets API and the Google Drive API on the project.', 'anchor-schema' ),
                    __( 'Paste the Client ID and Client Secret here, save, then click Connect.', 'anchor-schema' ),
                );
            }
            return array(
                __( 'In Microsoft Entra, register an app with "Accounts in any organizational directory and personal Microsoft accounts".', 'anchor-schema' ),
                __( 'Add a "Web" platform with the callback URL above as a redirect URI, and create a client secret.', 'anchor-schema' ),
                __( 'Add the delegated Microsoft Graph permissions Files.ReadWrite.All, User.Read and offline_access. If a client tenant blocks user consent, its admin must approve once.', 'anchor-schema' ),
                __( 'Paste the Application (client) ID and the secret value here, save, then click Connect.', 'anchor-schema' ),
            );
        }

        private function post_form( $action, $provider, $inner, $class = '' ) {
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="' . esc_attr( $class ) . '">';
            echo '<input type="hidden" name="action" value="anchor_connector_' . esc_attr( $action ) . '" />';
            echo '<input type="hidden" name="provider" value="' . esc_attr( $provider ) . '" />';
            wp_nonce_field( "anchor_connector_{$action}_{$provider}" );
            echo $inner; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts by callers.
            echo '</form>';
        }

        private function render_card( $provider, Anchor_OAuth_Connector $c ) {
            $o   = (array) get_option( 'anchor_connector_' . $provider, array() );
            $cid = isset( $o['client_id'] ) ? (string) $o['client_id'] : '';
            $has = $c->has_app_credentials();
            echo '<div class="card anchor-connector-card" style="max-width:720px;margin-top:16px;padding:12px 20px;">';
            echo '<h2>' . esc_html( $c->label() ) . '</h2>';
            echo '<p><strong>' . esc_html( $this->status( $c ) ) . '</strong></p>';

            $inner  = '<table class="form-table" role="presentation"><tbody>';
            $inner .= '<tr><th><label>' . esc_html__( 'Client ID', 'anchor-schema' ) . '</label></th><td><input type="text" name="client_id" class="regular-text" value="' . esc_attr( $cid ) . '" autocomplete="off" /></td></tr>';
            $inner .= '<tr><th><label>' . esc_html__( 'Client Secret', 'anchor-schema' ) . '</label></th><td><input type="password" name="client_secret" class="regular-text" value="" autocomplete="new-password" placeholder="' . ( $has ? '&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;' : '' ) . '" />'
                . ( $has ? '<p class="description">' . esc_html__( 'Leave blank to keep the saved secret.', 'anchor-schema' ) . '</p>' : '' ) . '</td></tr>';
            $inner .= '<tr><th><label>' . esc_html__( 'Callback URL', 'anchor-schema' ) . '</label></th><td><input type="text" readonly class="regular-text code anchor-connector-callback" value="' . esc_attr( $c->callback_url() ) . '" /> '
                . '<button type="button" class="button anchor-connector-copy">' . esc_html__( 'Copy', 'anchor-schema' ) . '</button></td></tr>';
            $inner .= '</tbody></table><p><button type="submit" class="button button-primary">' . esc_html__( 'Save credentials', 'anchor-schema' ) . '</button></p>';
            $this->post_form( 'save', $provider, $inner );

            echo '<p style="display:flex;gap:8px;align-items:center;">';
            if ( $has ) {
                $label = ( $c->is_connected() || $c->needs_reconnect() ) ? __( 'Reconnect', 'anchor-schema' ) : __( 'Connect', 'anchor-schema' );
                $this->post_form( 'connect', $provider, '<button type="submit" class="button button-primary">' . esc_html( $label ) . '</button>' );
            }
            if ( $c->is_connected() ) {
                $this->post_form( 'disconnect', $provider, '<button type="submit" class="button anchor-connector-disconnect">' . esc_html__( 'Disconnect', 'anchor-schema' ) . '</button>' );
            }
            echo '</p>';

            // Hook point: later features (auto-share, auto-create, ...) render their provider settings here.
            echo '<div class="anchor-connector-settings">';
            do_action( 'anchor_connector_card_settings', $provider, $c );
            echo '</div>';

            echo '<details style="margin:8px 0 12px;"><summary>' . esc_html__( 'How to set this up', 'anchor-schema' ) . '</summary><ol>';
            foreach ( $this->setup_steps( $provider ) as $step ) {
                echo '<li>' . esc_html( $step ) . '</li>';
            }
            echo '</ol></details></div>';
        }

        public function render_tab() {
            $this->notices();
            echo '<p>' . esc_html__( 'Connect this site to Google or Microsoft so Anchor Tools features can write to spreadsheets on your behalf. Each site uses its own OAuth app credentials.', 'anchor-schema' ) . '</p>';
            if ( '' === (string) get_option( 'permalink_structure' ) ) {
                echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'This site uses plain permalinks. Switch to pretty permalinks (Settings > Permalinks) so the callback URL is a clean path; providers may reject the ?rest_route= form.', 'anchor-schema' ) . '</p></div>';
            }
            foreach ( array_keys( anchor_oauth_providers() ) as $provider ) {
                $c = Anchor_OAuth_Connector::get( $provider );
                if ( $c ) {
                    $this->render_card( $provider, $c );
                }
            }
        }
    }
}
