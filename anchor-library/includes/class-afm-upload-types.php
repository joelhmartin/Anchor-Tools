<?php
if (!defined('ABSPATH') && PHP_SAPI !== 'cli') exit;

/**
 * Known libmagic misdetections for allowed upload types (WordPress-free).
 *
 * WordPress rejects a file when the content-sniffed MIME type doesn't match
 * its extension. For a few real formats the sniffer reports something else:
 * legacy Office files come back as generic OLE containers, .m4a as MP4 video,
 * plain text as source code. This table lists exactly those pairs, so a
 * rejected upload is accepted only when its detected type is one of them —
 * never on the filename's extension alone (AFM-06). application/octet-stream
 * is deliberately absent: it means "unknown", which proves nothing.
 */
class Anchor_FM_Upload_Types {

    const OLE = ['application/cdfv2', 'application/x-ole-storage', 'application/vnd.ms-office'];
    const PLAIN_TEXT = ['text/x-c', 'text/x-c++', 'text/x-asm', 'text/x-pascal', 'text/x-algol68', 'text/x-makefile', 'text/x-fortran'];

    public static function known_misdetections() {
        return [
            'doc'  => self::OLE,
            'xls'  => self::OLE,
            'ppt'  => self::OLE,
            'm4a'  => ['video/mp4', 'audio/x-m4a', 'audio/mp4'],
            'txt'  => self::PLAIN_TEXT,
            'csv'  => array_merge(self::PLAIN_TEXT, ['application/csv', 'text/x-csv', 'application/vnd.ms-excel']),
            'heic' => ['image/heif', 'image/heic', 'image/heic-sequence', 'image/heif-sequence'],
        ];
    }

    /** Whether $detected is a known misreading of a genuine .$ext file. */
    public static function is_known_misdetection($ext, $detected) {
        $ext = strtolower((string) $ext);
        $detected = strtolower(trim((string) $detected));
        if ($detected === '') return false;
        $table = self::known_misdetections();
        return isset($table[$ext]) && in_array($detected, $table[$ext], true);
    }
}
