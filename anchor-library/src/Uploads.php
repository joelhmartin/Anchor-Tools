<?php
/**
 * Library upload policy: which file types the private store accepts, validation by content, and the upload email.
 *
 * Ported from Anchor Private File Manager 2.17.0 with behaviour unchanged. Shared by the Files uploader and the
 * Product Docs uploader so both enforce one policy.
 *
 * @package Anchor\Library
 */

namespace Anchor\Library;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

class Uploads {

	/**
	 * Extensions accepted by the uploaders. Anything outside this list is
	 * rejected before the file is written, preventing executable payloads
	 * (e.g. .php, .phtml) from landing in the web-served uploads directory.
	 * Filterable so a site can extend it without touching the plugin.
	 */
	private static function allowed_upload_extensions() {
		$exts = [
			// documents
			'pdf','doc','docx','xls','xlsx','ppt','pptx','txt','csv','rtf',
			'odt','ods','odp',
			// images
			'jpg','jpeg','png','gif','webp','bmp','tif','tiff','heic',
			// audio / video
			'mp4','mov','m4v','webm','mp3','wav','m4a',
			// archives
			'zip',
		];
		$exts = \apply_filters('anchor_fm_allowed_upload_extensions', $exts);
		return \array_map('strtolower', (array) $exts);
	}

	/**
	 * extension => MIME for every allowed extension, from WordPress's full
	 * type list. This is the plugin's upload policy for its private store.
	 */
	private static function allowed_upload_mimes() {
		$allowed = self::allowed_upload_extensions();
		$map = [];
		foreach (\wp_get_mime_types() as $exts => $mime) {
			foreach (\explode('|', $exts) as $ext) {
				if (\in_array($ext, $allowed, true)) $map[$ext] = $mime;
			}
		}
		// Formats older WordPress versions don't list.
		if (\in_array('heic', $allowed, true) && !isset($map['heic'])) $map['heic'] = 'image/heic';
		return (array) \apply_filters('anchor_fm_upload_mimes', $map);
	}

	/**
	 * Validate an uploaded file by content and extension. Returns
	 * ['ext', 'type', 'filename'] on success — filename is WordPress's
	 * corrected name when the extension didn't match the content — or false.
	 *
	 * A WordPress rejection is final (AFM-06), with one narrow exception:
	 * content types libmagic is known to misreport for a genuine file of that
	 * extension (\Anchor_FM_Upload_Types). There is no fallback to trusting
	 * the filename's extension.
	 */
	public static function validate_type($tmp, $filename) {
		$mimes = self::allowed_upload_mimes();

		// \wp_check_filetype_and_ext() finally requires the type to be in the
		// site-wide \get_allowed_mime_types(), which other plugins filter. The
		// private store's policy is this plugin's allow-list, so apply it for
		// this one check only.
		$allow = function ($site_mimes) use ($mimes) { return \array_merge((array) $site_mimes, $mimes); };
		\add_filter('upload_mimes', $allow, PHP_INT_MAX);
		try {
			$ft = \wp_check_filetype_and_ext($tmp, $filename, $mimes);
		} finally {
			\remove_filter('upload_mimes', $allow, PHP_INT_MAX);
		}

		$ext  = !empty($ft['ext'])  ? \strtolower((string) $ft['ext']) : '';
		$type = !empty($ft['type']) ? (string) $ft['type'] : '';
		if ($ext !== '' && $type !== '' && isset($mimes[$ext])) {
			$name = !empty($ft['proper_filename']) ? (string) $ft['proper_filename'] : (string) $filename;
			return ['ext' => $ext, 'type' => $type, 'filename' => $name];
		}

		$claimed = \strtolower((string) \pathinfo((string) $filename, PATHINFO_EXTENSION));
		if (isset($mimes[$claimed]) && \Anchor_FM_Upload_Types::is_known_misdetection($claimed, self::sniff_mime($tmp))) {
			return ['ext' => $claimed, 'type' => $mimes[$claimed], 'filename' => (string) $filename];
		}
		return false;
	}

	private static function sniff_mime($path) {
		if (!\function_exists('finfo_open')) return '';
		$finfo = @\finfo_open(FILEINFO_MIME_TYPE);
		if (!$finfo) return '';
		$mime = @\finfo_file($finfo, $path);
		\finfo_close($finfo);
		return \is_string($mime) ? $mime : '';
	}

	/** Email the administrators about an upload. Off unless both the anchor_fm_enable_upload_email filter and the setting say so. */
	public static function notify($file_row, $actor_user_id) {
		// Hard-disabled by default. Toggle via the anchor_fm_enable_upload_email filter if ever needed.
		if (!\apply_filters('anchor_fm_enable_upload_email', false)) return;
		if (!\get_option(Schema::OPT_EMAIL_ON_UPLOAD, 0)) return;

		$subject = \sprintf('[%s] New file uploaded: %s', \wp_specialchars_decode(\get_bloginfo('name'), ENT_QUOTES), $file_row->original_name);
		$message = "A new file was uploaded.\n\n";
		$message .= "File: {$file_row->original_name}\n";
		$message .= "MIME: {$file_row->mime_type}\n";
		$message .= "Size: " . \size_format((int) $file_row->size) . "\n";
		$message .= "Uploaded by user ID: " . (int) $actor_user_id . "\n";

		$recipients = [];
		foreach (\get_users(['role' => 'administrator', 'fields' => ['user_email']]) as $u) {
			if (!empty($u->user_email)) $recipients[] = $u->user_email;
		}

		$recipients = \array_values(\array_unique(\array_filter($recipients)));
		if (!$recipients) return;

		\wp_mail($recipients, $subject, $message);
	}
}
