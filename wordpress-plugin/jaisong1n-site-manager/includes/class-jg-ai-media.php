<?php

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Controlled AI-owned media upload and read endpoints.
 *
 * Uploads are restricted to PNG/JPEG/WebP, validated by WordPress file-type
 * detection, the real MIME (finfo), image dimensions, and an executable
 * suffix block. SHA-256 plus the AI owner and idempotency key are used for
 * safe reuse and conflict detection. Ordinary user media is never claimed.
 */
final class JG_AI_Media {
	private const CAPABILITY = 'jg_ai_upload_media';
	private const MAX_BYTES_DEFAULT = 10 * MB_IN_BYTES;
	private const MAX_IDEMPOTENCY_KEY = 200;
	private const MAX_TITLE = 200;
	private const MAX_ALT_TEXT = 200;
	private const MAX_CAPTION = 2000;
	private const MAX_DESCRIPTION = 2000;
	private const MAX_ATTRIBUTION = 500;
	private const MAX_SOURCE_URL = 1000;
	private const MAX_LICENSE = 200;
	private const MAX_LICENSE_URL = 1000;
	private const ALLOWED_MIMES = array(
		'image/png' => 'png',
		'image/jpeg' => 'jpg',
		'image/webp' => 'webp',
	);
	private const WP_MIMES = array(
		'png' => 'image/png',
		'jpg|jpeg' => 'image/jpeg',
		'webp' => 'image/webp',
	);
	private const FORBIDDEN_EXTENSIONS = array(
		'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phar', 'pht',
		'html', 'htm', 'svg', 'js', 'exe', 'sh', 'py', 'pl', 'cgi',
	);
	private const META_CREATED = '_jg_ai_media_created';
	private const META_OWNER = '_jg_ai_media_owner_user_id';
	private const META_SHA256 = '_jg_ai_media_sha256';
	private const META_IDEMPOTENCY_KEY = '_jg_ai_media_idempotency_key';
	private const META_ATTRIBUTION = '_jg_ai_media_attribution';
	private const META_SOURCE_URL = '_jg_ai_media_source_url';
	private const META_LICENSE = '_jg_ai_media_license';
	private const META_LICENSE_URL = '_jg_ai_media_license_url';
	private const META_ORIGINAL_FILENAME = '_jg_ai_media_original_filename';
	private const META_UPLOADED_AT = '_jg_ai_media_uploaded_at';

	public static function init(): void {
		add_action('rest_api_init', array(__CLASS__, 'register_routes'));
		add_action('admin_init', array(__CLASS__, 'install'));
	}

	public static function install(): void {
		$role = get_role(JG_AI_Content::ROLE);
		if (!$role) return;
		// Intended 0.12.0 expansion, scoped to the AI role only: the upload
		// capability gates the endpoints, upload_files lets WordPress native
		// media functions work for this role. Never grants admin capabilities.
		$role->add_cap(self::CAPABILITY);
		$role->add_cap('upload_files');
	}

	public static function register_routes(): void {
		register_rest_route('jaisong1n/v1/ai', '/media', array(
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => array(__CLASS__, 'upload'),
			'permission_callback' => static fn(WP_REST_Request $request) => self::permission($request),
		));
		register_rest_route('jaisong1n/v1/ai', '/media/(?P<id>\d+)', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array(__CLASS__, 'read'),
			'permission_callback' => static fn(WP_REST_Request $request) => self::permission($request),
			'args' => array('id' => array('sanitize_callback' => 'absint')),
		));
	}

	public static function permission(WP_REST_Request $request) {
		$base = JG_AI_Content::permission($request, 'media');
		if ($base !== true) return $base;
		if (!current_user_can(self::CAPABILITY)) {
			return self::error('jg_ai_media_forbidden', 'You do not have permission for media operations.', 403);
		}
		return true;
	}

	public static function upload(WP_REST_Request $request) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		$user_id = get_current_user_id();
		$files = $request->get_file_params();
		$file = isset($files['file']) && is_array($files['file']) ? $files['file'] : array();
		$tmp = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';
		if ($tmp === '' || !is_file($tmp)) {
			return self::error('jg_ai_media_missing_file', 'A file upload is required.', 400);
		}
		if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
			return self::error('jg_ai_media_upload_error', 'The file upload failed.', 400);
		}
		$key = $request->get_param('idempotencyKey');
		if (!is_string($key) || trim($key) === '') {
			return self::error('jg_ai_media_idempotency_required', 'idempotencyKey is required.', 400);
		}
		$key = mb_substr(sanitize_text_field($key), 0, self::MAX_IDEMPOTENCY_KEY);
		if ($key === '') {
			return self::error('jg_ai_media_invalid_idempotency_key', 'idempotencyKey is invalid.', 400);
		}
		$size = (int) ($file['size'] ?? 0);
		if ($size <= 0) {
			return self::error('jg_ai_media_empty_file', 'The uploaded file is empty.', 400);
		}
		$max = self::max_bytes();
		if ($size > $max) {
			return self::error('jg_ai_media_file_too_large', 'The uploaded file exceeds the size limit.', 413, array('maxBytes' => $max));
		}

		$sha256 = hash_file('sha256', $tmp);
		if ($sha256 === false) {
			return self::error('jg_ai_media_read_failed', 'The uploaded file could not be read.', 422);
		}

		// Case 3: the same idempotency key with different content is a conflict.
		$by_key = self::find_ai_media(self::META_IDEMPOTENCY_KEY, $key, $user_id);
		if ($by_key !== null) {
			$existing_sha = (string) get_post_meta($by_key, self::META_SHA256, true);
			if (hash_equals($existing_sha, $sha256)) {
				return self::media_response($by_key, true);
			}
			return self::error('jg_ai_media_idempotency_conflict', 'This idempotency key was already used with different file content.', 409);
		}

		// Case 2: the same physical image already exists for this AI owner.
		$by_sha = self::find_ai_media(self::META_SHA256, $sha256, $user_id);
		if ($by_sha !== null) {
			return self::media_response($by_sha, true);
		}

		$validated = self::validate_file($file);
		if (is_wp_error($validated)) {
			return $validated;
		}

		$fields = self::clean_metadata_fields($request);
		if (is_wp_error($fields)) {
			return $fields;
		}
		$title = $fields['title'] !== '' ? $fields['title'] : self::default_title($file['name'] ?? '');
		// WordPress native upload handling. The custom action keeps the
		// readable-file check (strict content validation already happened in
		// validate_file()) while still flowing through wp_handle_upload's
		// MIME, size, and destination-name handling.
		$upload = wp_handle_upload($file, array(
			'test_form' => false,
			'action' => 'jg_ai_media_upload',
			'mimes' => self::WP_MIMES,
		));
		if (empty($upload['file']) || !empty($upload['error'])) {
			return self::error('jg_ai_media_upload_failed', 'The media item could not be stored.', 500);
		}
		$attachment_id = wp_insert_attachment(array(
			'post_mime_type' => $upload['type'],
			'post_title' => $title,
			'post_excerpt' => $fields['caption'],
			'post_content' => $fields['description'],
			'post_status' => 'inherit',
		), $upload['file'], 0);
		if (!$attachment_id || is_wp_error($attachment_id)) {
			return self::error('jg_ai_media_upload_failed', 'The media item could not be stored.', 500);
		}
		$attachment_id = (int) $attachment_id;
		wp_generate_attachment_metadata($attachment_id, $upload['file']);
		update_post_meta($attachment_id, '_wp_attachment_image_alt', $fields['altText']);
		update_post_meta($attachment_id, self::META_CREATED, 1);
		update_post_meta($attachment_id, self::META_OWNER, $user_id);
		update_post_meta($attachment_id, self::META_SHA256, $sha256);
		update_post_meta($attachment_id, self::META_IDEMPOTENCY_KEY, $key);
		update_post_meta($attachment_id, self::META_ATTRIBUTION, $fields['attribution']);
		update_post_meta($attachment_id, self::META_SOURCE_URL, $fields['sourceUrl']);
		update_post_meta($attachment_id, self::META_LICENSE, $fields['license']);
		update_post_meta($attachment_id, self::META_LICENSE_URL, $fields['licenseUrl']);
		update_post_meta($attachment_id, self::META_ORIGINAL_FILENAME, sanitize_file_name($file['name'] ?? ''));
		update_post_meta($attachment_id, self::META_UPLOADED_AT, gmdate('Y-m-d H:i:s'));
		return self::media_response($attachment_id, false);
	}

	public static function read(WP_REST_Request $request) {
		$id = (int) $request['id'];
		$post = get_post($id);
		if (!$post || $post->post_type !== 'attachment') {
			return self::error('jg_ai_media_not_found', 'Media was not found.', 404);
		}
		if (!(bool) get_post_meta($id, self::META_CREATED, true)) {
			return self::error('jg_ai_media_forbidden', 'This media item is not AI-owned.', 403);
		}
		$owner = (int) get_post_meta($id, self::META_OWNER, true);
		if ($owner !== get_current_user_id() && !current_user_can('manage_options')) {
			return self::error('jg_ai_media_forbidden', 'This media item is not owned by the current caller.', 403);
		}
		return new WP_REST_Response(self::media_detail($id), 200);
	}

	private static function validate_file(array $file) {
		$tmp = (string) $file['tmp_name'];
		$name = (string) ($file['name'] ?? '');
		$extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
		if ($extension !== '' && in_array($extension, self::FORBIDDEN_EXTENSIONS, true)) {
			return self::error('jg_ai_media_unsupported_type', 'Only PNG, JPEG and WebP images are allowed.', 415);
		}
		$wp_filetype = wp_check_filetype_and_ext($tmp, $name, self::WP_MIMES);
		if (empty($wp_filetype['type']) || !isset(self::ALLOWED_MIMES[$wp_filetype['type']])) {
			return self::error('jg_ai_media_unsupported_type', 'Only PNG, JPEG and WebP images are allowed.', 415);
		}
		$finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
		$real_mime = $finfo ? (string) finfo_file($finfo, $tmp) : '';
		if ($finfo) finfo_close($finfo);
		if ($real_mime === '' || !isset(self::ALLOWED_MIMES[$real_mime])) {
			return self::error('jg_ai_media_unsupported_type', 'The file content does not match a supported image type.', 415);
		}
		if ($real_mime !== $wp_filetype['type']) {
			return self::error('jg_ai_media_invalid_content', 'The file extension and content do not match.', 415);
		}
		$dimensions = @getimagesize($tmp);
		if ($dimensions === false || (int) $dimensions[0] <= 0 || (int) $dimensions[1] <= 0) {
			return self::error('jg_ai_media_invalid_image', 'The file is not a readable image with valid dimensions.', 422);
		}
		return array(
			'mimeType' => $wp_filetype['type'],
			'width' => (int) $dimensions[0],
			'height' => (int) $dimensions[1],
		);
	}

	private static function clean_metadata_fields(WP_REST_Request $request) {
		$clean = array();
		foreach (array('title', 'altText') as $key) {
			$value = $request->get_param($key);
			$clean[$key === 'altText' ? 'altText' : 'title'] = is_string($value) ? mb_substr(sanitize_text_field($value), 0, $key === 'altText' ? self::MAX_ALT_TEXT : self::MAX_TITLE) : '';
		}
		foreach (array('caption', 'description') as $key) {
			$value = $request->get_param($key);
			$clean[$key] = is_string($value) ? mb_substr(wp_strip_all_tags($value), 0, $key === 'caption' ? self::MAX_CAPTION : self::MAX_DESCRIPTION) : '';
		}
		$clean['attribution'] = self::clean_text($request->get_param('attribution'), self::MAX_ATTRIBUTION);
		$clean['license'] = self::clean_text($request->get_param('license'), self::MAX_LICENSE);
		foreach (array('sourceUrl', 'licenseUrl') as $key) {
			$value = $request->get_param($key);
			if ($value === null || $value === '') {
				$clean[$key] = '';
				continue;
			}
			if (!is_string($value)) {
				return self::error('jg_ai_media_invalid_url', $key . ' must be a string.', 400);
			}
			$url = esc_url_raw(mb_substr(trim($value), 0, $key === 'sourceUrl' ? self::MAX_SOURCE_URL : self::MAX_LICENSE_URL));
			if ($url !== '' && !preg_match('#^https?://#i', $url)) {
				return self::error('jg_ai_media_invalid_url', $key . ' must be an http(s) URL.', 400);
			}
			$clean[$key] = $url;
		}
		return $clean;
	}

	private static function clean_text($value, int $max): string {
		return is_string($value) ? mb_substr(sanitize_text_field($value), 0, $max) : '';
	}

	private static function default_title(string $filename): string {
		$base = sanitize_file_name($filename);
		$base = pathinfo($base, PATHINFO_FILENAME);
		return $base === '' ? 'AI media' : mb_substr($base, 0, self::MAX_TITLE);
	}

	private static function max_bytes(): int {
		$default = (int) apply_filters('jg_ai_media_max_bytes', self::MAX_BYTES_DEFAULT);
		$wp_max = (int) wp_max_upload_size();
		return $default > 0 ? min($default, $wp_max > 0 ? $wp_max : $default) : ($wp_max > 0 ? $wp_max : self::MAX_BYTES_DEFAULT);
	}

	private static function find_ai_media(string $meta_key, string $meta_value, int $owner_id): ?int {
		$query = new WP_Query(array(
			'post_type' => 'attachment',
			'post_status' => 'inherit',
			'posts_per_page' => 1,
			'fields' => 'ids',
			'no_found_rows' => true,
			'meta_query' => array(
				array('key' => self::META_CREATED, 'compare' => 'EXISTS'),
				array('key' => self::META_OWNER, 'value' => $owner_id, 'type' => 'NUMERIC'),
				array('key' => $meta_key, 'value' => $meta_value),
			),
		));
		$ids = $query->posts;
		return isset($ids[0]) ? (int) $ids[0] : null;
	}

	private static function media_response(int $attachment_id, bool $reused): WP_REST_Response {
		$detail = self::media_detail($attachment_id);
		return new WP_REST_Response(array(
			'success' => true,
			'reused' => $reused,
			'mediaId' => $detail['mediaId'],
			'url' => $detail['url'],
			'mimeType' => $detail['mimeType'],
			'sha256' => $detail['sha256'],
			'altText' => $detail['altText'],
			'caption' => $detail['caption'],
			'width' => $detail['width'],
			'height' => $detail['height'],
		), 200);
	}

	private static function media_detail(int $attachment_id): array {
		$post = get_post($attachment_id);
		$metadata = wp_get_attachment_metadata($attachment_id);
		$is_ai = (bool) get_post_meta($attachment_id, self::META_CREATED, true);
		return array(
			'success' => true,
			'mediaId' => $attachment_id,
			'url' => (string) wp_get_attachment_url($attachment_id),
			'title' => $post ? get_the_title($post) : '',
			'altText' => (string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true),
			'caption' => (string) wp_get_attachment_caption($attachment_id),
			'description' => $post ? (string) $post->post_content : '',
			'mimeType' => $post ? (string) $post->post_mime_type : '',
			'width' => (int) ($metadata['width'] ?? 0),
			'height' => (int) ($metadata['height'] ?? 0),
			'sha256' => (string) get_post_meta($attachment_id, self::META_SHA256, true),
			'attribution' => (string) get_post_meta($attachment_id, self::META_ATTRIBUTION, true),
			'sourceUrl' => (string) get_post_meta($attachment_id, self::META_SOURCE_URL, true),
			'license' => (string) get_post_meta($attachment_id, self::META_LICENSE, true),
			'licenseUrl' => (string) get_post_meta($attachment_id, self::META_LICENSE_URL, true),
			'aiOwned' => $is_ai,
			'createdAt' => self::iso_date($post ? $post->post_date_gmt : ''),
			'modifiedAt' => self::iso_date($post ? $post->post_modified_gmt : ''),
		);
	}

	private static function iso_date(string $gmt_value): ?string {
		$value = trim($gmt_value);
		if ($value === '' || $value === '0000-00-00 00:00:00') return null;
		$timestamp = strtotime($value . ' GMT');
		return $timestamp === false ? null : gmdate('Y-m-d\\TH:i:s\\Z', $timestamp);
	}

	private static function error(string $code, string $message, int $status, array $extra = array()): WP_Error {
		return new WP_Error($code, $message, array_merge(array('status' => $status, 'correlationId' => wp_generate_uuid4()), $extra));
	}
}
