<?php
/**
 * Pure (WordPress-free) helpers for bulk user import: CSV parsing
 * (username, first, last, email, optional password), username
 * derivation, and row validation. No WP functions here so the
 * plain-PHP test runner (tests/run.php) can exercise it.
 */
class Anchor_FM_User_Import {

    const MAX_ROWS = 1000;

    /** Lowercase and keep only characters allowed in a username. */
    public static function sanitize_username($raw) {
        $raw = strtolower(trim((string) $raw));
        return preg_replace('/[^a-z0-9._\-]/', '', $raw);
    }

    /** Derive a username base from a first + last name: first initial + '.' + last. */
    public static function derive_username($first, $last) {
        $first = trim((string) $first);
        $last  = trim((string) $last);
        $initial = $first === '' ? '' : substr($first, 0, 1);
        return self::sanitize_username($initial . '.' . $last);
    }

    /**
     * Return a username not rejected by $exists($name) (true = taken).
     * Appends 2,3,... to $base until a free name is found.
     */
    public static function make_unique($base, callable $exists) {
        $base = $base === '' ? 'user' : $base;
        if (!$exists($base)) {
            return $base;
        }
        $i = 2;
        while ($exists($base . $i)) {
            $i++;
            if ($i > self::MAX_ROWS + 2) {
                break; // safety valve; never expected to hit
            }
        }
        return $base . $i;
    }

    /** Canonical column key for a header cell, or '' if unrecognized. */
    private static function header_key($cell) {
        $c = strtolower(trim((string) $cell));
        $c = str_replace(['_', '-'], ' ', $c);
        $c = preg_replace('/\s+/', ' ', $c);
        switch ($c) {
            case 'username':
            case 'user name':
            case 'login':
                return 'username';
            case 'first name':
            case 'first':
            case 'firstname':
            case 'given name':
                return 'first_name';
            case 'last name':
            case 'last':
            case 'lastname':
            case 'surname':
            case 'family name':
                return 'last_name';
            case 'email':
            case 'email address':
            case 'e mail':
                return 'email';
            case 'password':
            case 'pass':
            case 'pwd':
                return 'password';
            default:
                return '';
        }
    }

    /** True if the row looks like a header (2 or more distinct recognized header keys). */
    public static function is_header_row($cells) {
        $distinct = [];
        foreach ((array) $cells as $cell) {
            $key = self::header_key($cell);
            if ($key !== '') {
                $distinct[$key] = true;
            }
        }
        return count($distinct) >= 2;
    }

    /**
     * Parse raw CSV text into canonical rows.
     * @return array ['header_detected'=>bool, 'rows'=>array]
     */
    public static function parse($raw) {
        $lines = preg_split('/\r\n|\r|\n/', (string) $raw);
        $default_cols = ['username', 'first_name', 'last_name', 'email', 'password'];

        // Find the first non-blank line to test for a header.
        $header_detected = false;
        $col_map = $default_cols; // index => canonical key
        $first_idx = null;
        foreach ($lines as $i => $line) {
            if (trim($line) !== '') { $first_idx = $i; break; }
        }
        if ($first_idx !== null) {
            $cells = str_getcsv($lines[$first_idx], ',', '"', '');
            if (self::is_header_row($cells)) {
                $header_detected = true;
                $col_map = [];
                foreach ($cells as $idx => $cell) {
                    $col_map[$idx] = self::header_key($cell); // '' for unknown
                }
            }
        }

        $rows = [];
        foreach ($lines as $i => $line) {
            if (trim($line) === '') { continue; }
            if ($header_detected && $i === $first_idx) { continue; } // skip header line
            $cells = str_getcsv($line, ',', '"', '');
            $row = ['line' => $i + 1, 'username' => '', 'first_name' => '', 'last_name' => '', 'email' => '', 'password' => ''];
            foreach ($cells as $idx => $val) {
                $key = isset($col_map[$idx]) ? $col_map[$idx] : '';
                if ($key !== '' && isset($row[$key])) {
                    $row[$key] = trim((string) $val);
                }
            }
            $rows[] = $row;
        }

        return ['header_detected' => $header_detected, 'rows' => $rows];
    }

    /** Lowercase + trim an email. */
    public static function normalize_email($raw) {
        return strtolower(trim((string) $raw));
    }

    /**
     * Validate a canonical row. Returns ['ok'=>bool, 'error'=>string].
     * Checks email, then first name, then last name.
     */
    public static function validate($row) {
        $email = isset($row['email']) ? trim((string) $row['email']) : '';
        $first = isset($row['first_name']) ? trim((string) $row['first_name']) : '';
        $last  = isset($row['last_name']) ? trim((string) $row['last_name']) : '';

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Invalid or missing email'];
        }
        if ($first === '') {
            return ['ok' => false, 'error' => 'Missing first name'];
        }
        if ($last === '') {
            return ['ok' => false, 'error' => 'Missing last name'];
        }
        return ['ok' => true, 'error' => ''];
    }
}
