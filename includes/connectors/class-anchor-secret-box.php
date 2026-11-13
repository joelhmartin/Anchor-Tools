<?php
/**
 * Encrypts small secrets (OAuth client secrets, refresh tokens) at rest.
 *
 * AES-256-GCM, key derived from wp_salt('auth'). Output is base64(iv|tag|ciphertext).
 * If the salts change, open() returns false and callers should ask for a reconnect.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Anchor_Secret_Box' ) ) {
    class Anchor_Secret_Box {
        const CIPHER  = 'aes-256-gcm';
        const IV_LEN  = 12;
        const TAG_LEN = 16;

        private static function key() {
            return hash( 'sha256', wp_salt( 'auth' ), true );
        }

        public static function seal( $plain ) {
            $iv  = random_bytes( self::IV_LEN );
            $tag = '';
            $ct  = openssl_encrypt( (string) $plain, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LEN );
            if ( false === $ct ) {
                return '';
            }
            return base64_encode( $iv . $tag . $ct );
        }

        /** @return string|false Plaintext, or false when malformed, tampered, or the salts changed. */
        public static function open( $sealed ) {
            if ( ! is_string( $sealed ) || '' === $sealed ) {
                return false;
            }
            $raw = base64_decode( $sealed, true );
            if ( false === $raw || strlen( $raw ) < self::IV_LEN + self::TAG_LEN ) {
                return false;
            }
            $iv  = substr( $raw, 0, self::IV_LEN );
            $tag = substr( $raw, self::IV_LEN, self::TAG_LEN );
            $ct  = substr( $raw, self::IV_LEN + self::TAG_LEN );
            return openssl_decrypt( $ct, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag );
        }
    }
}
