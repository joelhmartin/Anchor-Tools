<?php
if (!defined('ABSPATH') && !defined('AFM_TEST')) {
    // Allow standalone include in tests; block direct web access in WP.
    if (php_sapi_name() !== 'cli') { exit; }
}

class Anchor_FM_Vimeo {

    /** oEmbed endpoint. Public + unlisted (with hash); no API key required. */
    const OEMBED_ENDPOINT = 'https://vimeo.com/api/oembed.json';

    /** Seconds to wait on Vimeo before giving up and importing without metadata. */
    const HTTP_TIMEOUT = 5;

    /**
     * Ordered id patterns. Order matters: the bare `vimeo.com/<id>` form is a
     * catch-all and must be tried last, or it would claim the leading numeric
     * segment of forms like /album/<n>/video/<id>.
     */
    private static function id_patterns() {
        return [
            '~vimeo\.com/manage/videos/(\d{6,})~i',
            '~vimeo\.com/album/\d+/video/(\d{6,})~i',
            '~vimeo\.com/ondemand/[^/\s]+/(\d{6,})~i',
            '~vimeo\.com/channels/[^/\s]+/(\d{6,})~i',
            '~vimeo\.com/groups/[^/\s]+/videos/(\d{6,})~i',
            '~(?:player\.)?vimeo\.com/video/(\d{6,})~i',
            '~vimeo\.com/(\d{6,})~i',
        ];
    }

    /**
     * Resolve any Vimeo reference to its numeric id and (for unlisted videos)
     * its privacy hash. Accepts a bare id, any common URL form, or a pasted
     * <iframe> embed code. Returns ['id' => '', 'hash' => ''] when nothing can
     * be read.
     *
     * The hash matters: unlisted videos will neither embed nor resolve via
     * oEmbed without it.
     */
    public static function parse_ref($input) {
        $input = trim((string) $input);
        $empty = ['id' => '', 'hash' => ''];
        if ($input === '') return $empty;

        if (ctype_digit($input)) return ['id' => $input, 'hash' => ''];

        $id = '';
        foreach (self::id_patterns() as $re) {
            if (preg_match($re, $input, $m)) { $id = $m[1]; break; }
        }
        if ($id === '') return $empty;

        // Hash appears either as a query arg (?h=/&h=, incl. inside an iframe
        // src) or as the path segment after the id (vimeo.com/<id>/<hash>).
        $hash = '';
        if (preg_match('~[?&]h=([A-Za-z0-9]{3,32})~i', $input, $m)) {
            $hash = $m[1];
        } elseif (preg_match('~vimeo\.com/' . preg_quote($id, '~') . '/([A-Za-z0-9]{3,32})~i', $input, $m)) {
            $hash = $m[1];
        }

        return ['id' => $id, 'hash' => $hash];
    }

    /**
     * Extract just the numeric Vimeo id. Returns '' when no id can be found.
     */
    public static function parse_id($input) {
        $ref = self::parse_ref($input);
        return $ref['id'];
    }

    /**
     * Split a pasted blob into individual references. Users paste
     * comma-separated lists, one-per-line lists, or a mix of both.
     */
    public static function split_refs($input) {
        $out = [];
        foreach (preg_split('~[,\r\n]+~', (string) $input) as $part) {
            $part = trim($part);
            if ($part !== '') { $out[] = $part; }
        }
        return $out;
    }

    /**
     * Canonical vimeo.com URL for a ref — what oEmbed wants as its `url` arg.
     */
    public static function video_url($vimeo_id, $hash = '') {
        $vimeo_id = preg_replace('/\D+/', '', (string) $vimeo_id);
        if ($vimeo_id === '') return '';
        $url = 'https://vimeo.com/' . $vimeo_id;
        $hash = preg_replace('/[^A-Za-z0-9]/', '', (string) $hash);
        if ($hash !== '') { $url .= '/' . $hash; }
        return $url;
    }

    /**
     * Fetch title + thumbnail from Vimeo's oEmbed API.
     *
     * Returns ['title' => string, 'thumbnail_url' => string] or a WP_Error.
     * Callers must treat failure as non-fatal: a video that can't be described
     * is still a video worth importing.
     */
    public static function fetch_meta($vimeo_id, $hash = '') {
        $url = self::video_url($vimeo_id, $hash);
        if ($url === '') {
            return new WP_Error('afm_vimeo_bad_id', 'No Vimeo id to look up');
        }

        $endpoint = self::OEMBED_ENDPOINT . '?url=' . rawurlencode($url);
        $res = wp_remote_get($endpoint, ['timeout' => self::HTTP_TIMEOUT]);
        if (is_wp_error($res)) return $res;

        $code = (int) wp_remote_retrieve_response_code($res);
        if ($code === 403) {
            return new WP_Error('afm_vimeo_private', 'Vimeo says this video is private (an unlisted link needs its hash)');
        }
        if ($code === 404) {
            // Verified against the live API: oEmbed answers 404 both for a
            // video that doesn't exist AND for one that exists but whose owner
            // disallows embedding. Don't send anyone hunting for a deleted
            // video that is sitting right there.
            return new WP_Error('afm_vimeo_missing', 'Vimeo would not describe this video — it may not exist, or its owner blocks embedding');
        }
        if ($code !== 200) {
            return new WP_Error('afm_vimeo_http', 'Vimeo returned HTTP ' . $code);
        }

        $body = json_decode((string) wp_remote_retrieve_body($res), true);
        if (!is_array($body) || !isset($body['title'])) {
            return new WP_Error('afm_vimeo_parse', 'Could not read Vimeo\'s response');
        }

        return [
            'title'         => (string) $body['title'],
            'thumbnail_url' => isset($body['thumbnail_url']) ? (string) $body['thumbnail_url'] : '',
        ];
    }

    /**
     * Fallback title for a video whose metadata could not be fetched.
     */
    public static function fallback_title($vimeo_id) {
        return 'Vimeo video ' . preg_replace('/\D+/', '', (string) $vimeo_id);
    }
}
