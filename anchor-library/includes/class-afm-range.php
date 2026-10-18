<?php
if (!defined('ABSPATH') && !defined('AFM_TEST')) {
    if (php_sapi_name() !== 'cli') { exit; }
}

/**
 * Parses a single HTTP Range header. Deliberately conservative: anything it
 * does not fully understand returns null, which tells the caller to serve the
 * whole file with a 200 rather than risk sending a wrong byte window.
 */
class Anchor_FM_Range {

    /**
     * @param string $header   raw Range header value, e.g. "bytes=500-999"
     * @param int    $filesize total size of the file on disk
     * @return array|null  null => serve full body (200)
     *                     ['satisfiable'=>false] => 416
     *                     ['satisfiable'=>true,'start'=>int,'end'=>int] => 206
     */
    public static function parse($header, $filesize) {
        $filesize = (int) $filesize;
        if ($filesize <= 0) return null;

        $header = trim((string) $header);
        if ($header === '') return null;
        if (stripos($header, 'bytes=') !== 0) return null;

        $spec = trim(substr($header, 6));
        if ($spec === '') return null;

        // Multi-range ("bytes=0-99,200-299") requires a multipart/byteranges
        // body. No video element needs it, so fall back to the full body.
        if (strpos($spec, ',') !== false) return null;

        $dash = strpos($spec, '-');
        if ($dash === false) return null;

        $from = trim(substr($spec, 0, $dash));
        $to   = trim(substr($spec, $dash + 1));

        // Suffix form: "-500" means the LAST 500 bytes, not "from 0 to 500".
        if ($from === '') {
            if ($to === '' || !ctype_digit($to)) return null;
            $n = (int) $to;
            if ($n <= 0) return ['satisfiable' => false];
            if ($n >= $filesize) {
                return ['satisfiable' => true, 'start' => 0, 'end' => $filesize - 1];
            }
            return ['satisfiable' => true, 'start' => $filesize - $n, 'end' => $filesize - 1];
        }

        if (!ctype_digit($from)) return null;
        $start = (int) $from;
        if ($start >= $filesize) return ['satisfiable' => false];

        if ($to === '') {
            return ['satisfiable' => true, 'start' => $start, 'end' => $filesize - 1];
        }
        if (!ctype_digit($to)) return null;

        $end = (int) $to;
        if ($end < $start) return null;
        if ($end > $filesize - 1) $end = $filesize - 1;

        return ['satisfiable' => true, 'start' => $start, 'end' => $end];
    }
}
