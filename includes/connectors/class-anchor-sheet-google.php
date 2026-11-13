<?php
/**
 * Google Sheets v4 adapter. All writes are RAW so nothing is evaluated as a formula.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Anchor_Sheet_Google' ) ) {
    class Anchor_Sheet_Google implements Anchor_Sheet_Adapter {
        const API = 'https://sheets.googleapis.com/v4/spreadsheets/';

        const DRIVE       = 'https://www.googleapis.com/drive/v3/files/';
        const DRIVE_SCOPE = 'https://www.googleapis.com/auth/drive';

        /** Auto-share / auto-create settings. Own option: the connector option holds tokens a refresh rewrites. */
        const SETTINGS_OPTION = 'anchor_connector_google_settings';

        /** Raw settings; falls back to the keys older versions kept in the connector option until first saved. */
        public static function settings() {
            $s = get_option( self::SETTINGS_OPTION );
            if ( is_array( $s ) ) {
                return $s;
            }
            $legacy = (array) get_option( 'anchor_connector_google', array() );
            return array_intersect_key( $legacy, array_flip( array( 'share_managers', 'share_emails', 'share_role', 'share_notify', 'autocreate', 'autocreate_folder_id' ) ) );
        }

        /** Normalised auto-share settings. */
        public static function share_settings() {
            $o      = self::settings();
            $emails = array();
            foreach ( (array) ( $o['share_emails'] ?? array() ) as $e ) {
                $e = sanitize_email( (string) $e );
                if ( '' !== $e ) {
                    $emails[] = strtolower( $e );
                }
            }
            return array(
                'managers' => ! isset( $o['share_managers'] ) || (bool) $o['share_managers'],
                'emails'   => array_values( array_unique( $emails ) ),
                'role'     => 'reader' === ( $o['share_role'] ?? '' ) ? 'reader' : 'writer',
                'notify'   => ! isset( $o['share_notify'] ) || (bool) $o['share_notify'],
            );
        }

        /** Auto-create settings. */
        public static function autocreate_settings() {
            $o = self::settings();
            return array(
                'enabled'   => ! empty( $o['autocreate'] ),
                'folder_id' => preg_replace( '/[^A-Za-z0-9_-]/', '', (string) ( $o['autocreate_folder_id'] ?? '' ) ),
            );
        }

        /** Drive folder id from a drive.google.com/drive/.../folders/<id> URL ('' when not a folder link). */
        public static function parse_folder_id( $url ) {
            $parts = wp_parse_url( trim( (string) $url ) );
            if ( 'drive.google.com' === strtolower( (string) ( $parts['host'] ?? '' ) )
                && preg_match( '#/folders/([A-Za-z0-9_-]+)#', (string) ( $parts['path'] ?? '' ), $m ) ) {
                return $m[1];
            }
            return '';
        }

        /**
         * Creates a spreadsheet, optionally moving it into a Drive folder.
         * A failed move leaves the sheet in My Drive and is not an error (the sheet exists; retrying would duplicate it).
         *
         * @return array|WP_Error {url, ref}
         */
        public function create( $title, $folder_id = '' ) {
            $res = $this->call( 'POST', rtrim( self::API, '/' ), array( 'properties' => array( 'title' => (string) $title ) ) );
            if ( is_wp_error( $res ) ) {
                return $res;
            }
            $id = (string) ( $res['spreadsheetId'] ?? '' );
            if ( '' === $id ) {
                return new WP_Error( 'anchor_sheet_create_failed', __( 'Google did not return a spreadsheet.', 'anchor-schema' ) );
            }
            $warning = '';
            if ( '' !== $folder_id ) {
                $moved = $this->call( 'PATCH', self::DRIVE . rawurlencode( $id ) . '?addParents=' . rawurlencode( $folder_id ) . '&removeParents=root&supportsAllDrives=true' );
                $warning = is_wp_error( $moved ) ? $moved->get_error_code() : '';
            }
            return array(
                'url' => (string) ( $res['spreadsheetUrl'] ?? 'https://docs.google.com/spreadsheets/d/' . $id . '/edit' ),
                'ref' => array( 'provider' => 'google', 'id' => $id, 'tab' => (string) ( $res['sheets'][0]['properties']['title'] ?? 'Sheet1' ) ),
                'warning' => $warning,
            );
        }

        /**
         * Adds Drive permissions for any of $emails the file does not already have. Never removes access.
         *
         * @param string[] $emails
         * @return string[]|WP_Error Emails newly added (lowercased).
         */
        public function share( array $ref, array $emails, $role, $notify ) {
            $id   = rawurlencode( (string) ( $ref['id'] ?? '' ) );
            $role = 'reader' === $role ? 'reader' : 'writer';
            $have = array();
            $page = '';
            do {
                $url = self::DRIVE . $id . '/permissions?fields=' . rawurlencode( 'nextPageToken,permissions(emailAddress,role,type)' ) . '&supportsAllDrives=true'
                    . ( '' !== $page ? '&pageToken=' . rawurlencode( $page ) : '' );
                $res = $this->call( 'GET', $url );
                if ( is_wp_error( $res ) ) {
                    return $res;
                }
                foreach ( (array) ( $res['permissions'] ?? array() ) as $perm ) {
                    if ( ! empty( $perm['emailAddress'] ) ) {
                        $have[ strtolower( (string) $perm['emailAddress'] ) ] = true;
                    }
                }
                $page = (string) ( $res['nextPageToken'] ?? '' );
            } while ( '' !== $page );

            $added = array();
            foreach ( $emails as $email ) {
                $email = strtolower( trim( (string) $email ) );
                if ( '' === $email || isset( $have[ $email ] ) ) {
                    continue;
                }
                $url = self::DRIVE . $id . '/permissions?sendNotificationEmail=' . ( $notify ? 'true' : 'false' ) . '&supportsAllDrives=true';
                $res = $this->call( 'POST', $url, array( 'type' => 'user', 'role' => $role, 'emailAddress' => $email ) );
                if ( is_wp_error( $res ) ) {
                    return $res;
                }
                $have[ $email ] = true;
                $added[]        = $email;
            }
            return $added;
        }

        /** A1 notation tab reference: 'Bob''s List' */
        private static function q( $tab ) {
            return "'" . str_replace( "'", "''", (string) $tab ) . "'";
        }

        private function base( array $ref ) {
            return self::API . rawurlencode( (string) ( $ref['id'] ?? '' ) );
        }

        private function call( $method, $url, $body = null ) {
            return Anchor_Sheet_Adapters::call( 'google', $method, $url, $body );
        }

        /** @return array|WP_Error sheets[].properties list */
        private function tabs( $id ) {
            $res = $this->call( 'GET', self::API . rawurlencode( $id ) . '?fields=sheets.properties' );
            if ( is_wp_error( $res ) ) {
                return $res;
            }
            $tabs = array();
            foreach ( (array) ( $res['sheets'] ?? array() ) as $s ) {
                if ( ! empty( $s['properties'] ) ) {
                    $tabs[] = $s['properties'];
                }
            }
            return $tabs;
        }

        public function resolve( $url ) {
            $parts = wp_parse_url( trim( (string) $url ) );
            if ( 'docs.google.com' !== strtolower( (string) ( $parts['host'] ?? '' ) )
                || ! preg_match( '#/spreadsheets/(?:u/\d+/)?d/([A-Za-z0-9_-]+)#', (string) ( $parts['path'] ?? '' ), $m ) ) {
                return new WP_Error( 'anchor_sheet_bad_url', __( 'That is not a Google Sheets link.', 'anchor-schema' ) );
            }
            $gid = null;
            foreach ( array( $parts['fragment'] ?? '', $parts['query'] ?? '' ) as $chunk ) {
                if ( preg_match( '/(?:^|[&#])gid=(\d+)/', (string) $chunk, $g ) ) {
                    $gid = (int) $g[1];
                    break;
                }
            }
            $tabs = $this->tabs( $m[1] );
            if ( is_wp_error( $tabs ) ) {
                return $tabs;
            }
            if ( ! $tabs ) {
                return new WP_Error( 'anchor_sheet_no_tab', __( 'That spreadsheet has no tabs.', 'anchor-schema' ) );
            }
            $pick = $tabs[0];
            if ( null !== $gid ) {
                foreach ( $tabs as $t ) {
                    if ( isset( $t['sheetId'] ) && (int) $t['sheetId'] === $gid ) {
                        $pick = $t;
                        break;
                    }
                }
            }
            return array( 'provider' => 'google', 'id' => $m[1], 'tab' => (string) $pick['title'] );
        }

        public function read( array $ref ) {
            $res = $this->call( 'GET', $this->base( $ref ) . '/values/' . rawurlencode( self::q( $ref['tab'] ?? '' ) ) );
            return is_wp_error( $res ) ? $res : Anchor_Sheet_Adapters::stringify( $res['values'] ?? array() );
        }

        public function write_cells( array $ref, array $cells ) {
            if ( ! $cells ) {
                return true;
            }
            $data = array();
            foreach ( $cells as $c ) {
                $data[] = array(
                    'range'  => self::q( $ref['tab'] ?? '' ) . '!' . Anchor_Sheet_Adapters::col_letter( (int) $c[1] ) . (int) $c[0],
                    'values' => array( array( (string) $c[2] ) ),
                );
            }
            $res = $this->call( 'POST', $this->base( $ref ) . '/values:batchUpdate', array( 'valueInputOption' => 'RAW', 'data' => $data ) );
            return is_wp_error( $res ) ? $res : true;
        }

        public function append_rows( array $ref, array $rows ) {
            if ( ! $rows ) {
                return true;
            }
            $values = array_map( static function ( $r ) {
                return array_map( 'strval', array_values( $r ) );
            }, $rows );
            $url    = $this->base( $ref ) . '/values/' . rawurlencode( self::q( $ref['tab'] ?? '' ) . '!A1' ) . ':append?valueInputOption=RAW&insertDataOption=INSERT_ROWS';
            $res    = $this->call( 'POST', $url, array( 'values' => $values ) );
            return is_wp_error( $res ) ? $res : true;
        }

        public function ensure_columns( array $ref, $count ) {
            $tabs = $this->tabs( (string) ( $ref['id'] ?? '' ) );
            if ( is_wp_error( $tabs ) ) {
                return $tabs;
            }
            foreach ( $tabs as $t ) {
                if ( ( $t['title'] ?? null ) !== ( $ref['tab'] ?? null ) ) {
                    continue;
                }
                $have = (int) ( $t['gridProperties']['columnCount'] ?? 0 );
                if ( $have >= (int) $count ) {
                    return true;
                }
                $res = $this->call( 'POST', $this->base( $ref ) . ':batchUpdate', array( 'requests' => array( array(
                    'appendDimension' => array( 'sheetId' => (int) $t['sheetId'], 'dimension' => 'COLUMNS', 'length' => (int) $count - $have ),
                ) ) ) );
                return is_wp_error( $res ) ? $res : true;
            }
            return new WP_Error( 'anchor_sheet_no_tab', __( 'That tab no longer exists in the spreadsheet.', 'anchor-schema' ) );
        }
    }
}
