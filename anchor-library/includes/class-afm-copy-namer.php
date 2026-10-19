<?php
/**
 * Pure (WordPress-free) name-collision helpers for copy/paste/duplicate.
 * No WP functions here so tests/run.php can exercise it.
 */
class Anchor_FM_Copy_Namer {

    const MAX_SUFFIX = 1000;

    /** Split into [base, ext]; ext includes the leading dot, or '' if none. */
    public static function split_extension($name) {
        $name = (string) $name;
        if (preg_match('/^(.+)(\.[A-Za-z0-9]{1,10})$/', $name, $m)) {
            return [$m[1], $m[2]];
        }
        return [$name, ''];
    }

    /** Append or bump a "(copy)" suffix on a base (no extension handling). */
    public static function add_copy_suffix($base) {
        $base = (string) $base;
        if (preg_match('/^(.*) \(copy(?: (\d+))?\)$/', $base, $m)) {
            $stem = $m[1];
            $n = (isset($m[2]) && $m[2] !== '') ? (int) $m[2] : 1;
            return $stem . ' (copy ' . ($n + 1) . ')';
        }
        return $base . ' (copy)';
    }

    /** Apply add_copy_suffix, preserving a file extension when $is_file. */
    public static function next_copy_name($name, $is_file) {
        if ($is_file) {
            list($base, $ext) = self::split_extension($name);
            return self::add_copy_suffix($base) . $ext;
        }
        return self::add_copy_suffix((string) $name);
    }

    /**
     * Resolve a unique name against existing sibling names (case-insensitive).
     * If $force_copy, always apply at least one "(copy)"; then bump until free.
     */
    public static function resolve_unique($desired, array $existing, $is_file, $force_copy) {
        $taken = [];
        foreach ($existing as $e) {
            $taken[strtolower((string) $e)] = true;
        }
        $candidate = (string) $desired;
        if ($force_copy) {
            $candidate = self::next_copy_name($candidate, $is_file);
        }
        $i = 0;
        while (isset($taken[strtolower($candidate)]) && $i < self::MAX_SUFFIX) {
            $candidate = self::next_copy_name($candidate, $is_file);
            $i++;
        }
        return $candidate;
    }
}
