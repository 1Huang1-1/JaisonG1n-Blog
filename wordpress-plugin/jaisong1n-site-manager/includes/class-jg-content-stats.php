<?php

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Public, privacy-safe content view counting for published articles and
 * diaries. Writes only to dedicated statistics tables and transients; it never
 * touches wp_posts, never calls wp_update_post, and never enters the content
 * change/dispatch pipeline.
 */
final class JG_Content_Stats {
	private const VERSION_OPTION = 'jg_content_stats_version';
	private const VERSION = '1';
	private const EVENT_TTL = 30 * DAY_IN_SECONDS;
	private const MAX_BODY_BYTES = 1024;
	private const RATE_LIMIT = 60;
	private const RATE_WINDOW = MINUTE_IN_SECONDS;
	private const CLEANUP_PROBABILITY = 1;
	private const ALLOWED_ORIGINS = array(
		'https://jaisong1n.com',
		'https://www.jaisong1n.com',
		'http://localhost:4321',
		'http://localhost:3000',
	);
	private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
	private const BOT_UA_PATTERN = '/(bot|crawler|spider|slurp|scrapy|preview|facebookexternalhit|twitterbot|telegrambot|discordbot|whatsapp|linkedinbot|pinterest|bingbot|googlebot|google-inspectiontool|baiduspider|yandex|semrush|ahrefs|mj12bot|dotbot|curl|wget|python-requests|python-urllib|go-http-client|headless|phantomjs|lighthouse)/i';

	public static function init(): void {
		self::install();
		add_action('rest_api_init', array(__CLASS__, 'register_routes'));
		add_filter('rest_pre_dispatch', array(__CLASS__, 'pre_dispatch'), 10, 3);
	}

	public static function pre_dispatch($response, $server, WP_REST_Request $request) {
		if (str_starts_with((string) $request->get_route(), '/jg-public/v1/') && $request->get_method() === 'OPTIONS') {
			$origin = self::request_origin();
			if ($origin !== '' && in_array($origin, self::ALLOWED_ORIGINS, true)) {
				return new WP_REST_Response(null, 204, self::cors_headers($origin));
			}
		}
		return $response;
	}

	public static function install(): void {
		if (get_option(self::VERSION_OPTION, '') === self::VERSION) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta("CREATE TABLE {$wpdb->prefix}jg_content_stats (
			content_type varchar(16) NOT NULL,
			content_id bigint(20) unsigned NOT NULL,
			view_count bigint(20) unsigned NOT NULL DEFAULT 0,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (content_type, content_id)
		) {$charset};");
		dbDelta("CREATE TABLE {$wpdb->prefix}jg_view_events (
			event_hash char(64) NOT NULL,
			content_type varchar(16) NOT NULL,
			content_id bigint(20) unsigned NOT NULL,
			expires_at datetime NOT NULL,
			PRIMARY KEY  (event_hash),
			KEY event_lookup (content_type, content_id, expires_at)
		) {$charset};");
		update_option(self::VERSION_OPTION, self::VERSION, false);
	}

	public static function register_routes(): void {
		register_rest_route('jg-public/v1', '/content/(?P<contentType>[a-z]+)/(?P<id>[^/]+)/view', array(
			'methods' => array('POST', 'OPTIONS'),
			'callback' => array(__CLASS__, 'handle_view'),
			'permission_callback' => '__return_true',
		));
	}

	public static function handle_view(WP_REST_Request $request) {
		$origin = self::request_origin();
		$cors = $origin !== '' ? self::cors_headers($origin) : array();

		if ($request->get_method() === 'OPTIONS') {
			if ($origin !== '' && !in_array($origin, self::ALLOWED_ORIGINS, true)) {
				return new WP_REST_Response(array('code' => 'jg_view_origin_forbidden', 'message' => 'Origin is not allowed.'), 403);
			}
			return new WP_REST_Response(null, 204, $cors);
		}
		if ($origin !== '' && !in_array($origin, self::ALLOWED_ORIGINS, true)) {
			return self::json(array('code' => 'jg_view_origin_forbidden', 'message' => 'Origin is not allowed.'), 403, $cors);
		}

		$content_type = sanitize_key((string) $request['contentType']);
		$post_type = self::post_type_for($content_type);
		if ($post_type === null) {
			return self::json(array('code' => 'jg_view_invalid_content_type', 'message' => 'Content type must be article or diary.'), 400, $cors);
		}
		if (strlen((string) $request->get_body()) > self::MAX_BODY_BYTES) {
			return self::json(array('code' => 'jg_view_body_too_large', 'message' => 'Request body is too large.'), 413, $cors);
		}
		$body = json_decode((string) $request->get_body(), true);
		if (!is_array($body)) {
			return self::json(array('code' => 'jg_view_invalid_body', 'message' => 'A JSON body is required.'), 400, $cors);
		}
		$event_id = isset($body['eventId']) && is_string($body['eventId']) ? trim($body['eventId']) : '';
		if (preg_match(self::UUID_PATTERN, $event_id) !== 1) {
			return self::json(array('code' => 'jg_view_invalid_event_id', 'message' => 'eventId must be a UUID.'), 400, $cors);
		}
		$post = self::resolve_post($content_type, $post_type, (string) $request['id']);
		if (is_wp_error($post)) {
			return self::json(array('code' => $post->get_error_code(), 'message' => $post->get_error_message()), (int) $post->get_error_data()['status'], $cors);
		}
		$post_id = (int) $post->ID;
		if (!$post || $post->post_type !== $post_type || $post->post_status !== 'publish') {
			return self::json(array('code' => 'jg_view_not_found', 'message' => 'Content was not found.'), 404, $cors);
		}
		if (self::is_bot_user_agent()) {
			return self::json(array('contentType' => $content_type, 'id' => $post_id, 'views' => self::current_views($content_type, $post_id), 'counted' => false), 200, $cors);
		}
		if (self::rate_limited()) {
			return self::json(array('code' => 'jg_view_rate_limited', 'message' => 'Too many view requests. Try again later.', 'retryAfter' => 60), 429, $cors);
		}

		$counted = self::record_view($content_type, $post_id, $event_id);
		return self::json(array('contentType' => $content_type, 'id' => $post_id, 'views' => self::current_views($content_type, $post_id), 'counted' => $counted), 200, $cors);
	}

	private static function resolve_post(string $content_type, string $post_type, string $identifier) {
		if ($identifier === '') {
			return new WP_Error('jg_view_invalid_id', 'A content id or slug is required.', array('status' => 400));
		}
		if (ctype_digit($identifier)) {
			$post = get_post((int) $identifier);
			if (!$post || $post->post_type !== $post_type) {
				return new WP_Error('jg_view_not_found', 'Content was not found.', array('status' => 404));
			}
			return $post;
		}
		global $wpdb;
		// WordPress stores post_name either as the plain sanitized slug (ASCII),
		// a percent-encoded CJK slug, or a decoded CJK slug depending on the
		// version and locale. Try every form so a public URL slug always maps to
		// the real post without weakening the publish/status guard below.
		$decoded = rawurldecode($identifier);
		$candidates = array();
		foreach (array($identifier, $decoded, sanitize_title($decoded)) as $candidate) {
			$candidate = (string) $candidate;
			if ($candidate !== '' && !in_array($candidate, $candidates, true)) {
				$candidates[] = $candidate;
			}
		}
		if ($candidates === array()) {
			return new WP_Error('jg_view_invalid_id', 'A content id or slug is required.', array('status' => 400));
		}
		$placeholders = implode(', ', array_fill(0, count($candidates), '%s'));
		$sql = "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' AND post_name IN ({$placeholders}) ORDER BY post_date DESC LIMIT 1";
		$post_id = (int) $wpdb->get_var($wpdb->prepare($sql, array_merge(array($post_type), $candidates)));
		$post = $post_id > 0 ? get_post($post_id) : null;
		if (!$post) {
			return new WP_Error('jg_view_not_found', 'Content was not found.', array('status' => 404));
		}
		return $post;
	}

	private static function record_view(string $content_type, int $post_id, string $event_id): bool {
		global $wpdb;
		$event_hash = hash('sha256', $content_type . ':' . $post_id . ':' . $event_id);
		$expires_at = gmdate('Y-m-d H:i:s', time() + self::EVENT_TTL);
		$inserted = $wpdb->query($wpdb->prepare(
			"INSERT IGNORE INTO {$wpdb->prefix}jg_view_events (event_hash, content_type, content_id, expires_at) VALUES (%s, %s, %d, %s)",
			$event_hash,
			$content_type,
			$post_id,
			$expires_at
		));
		if ($inserted !== 1) {
			return false;
		}
		$wpdb->query($wpdb->prepare(
			"INSERT INTO {$wpdb->prefix}jg_content_stats (content_type, content_id, view_count, updated_at) VALUES (%s, %d, 1, %s) ON DUPLICATE KEY UPDATE view_count = view_count + 1, updated_at = VALUES(updated_at)",
			$content_type,
			$post_id,
			gmdate('Y-m-d H:i:s')
		));
		if (mt_rand(1, 100) <= self::CLEANUP_PROBABILITY) {
			$wpdb->query("DELETE FROM {$wpdb->prefix}jg_view_events WHERE expires_at < UTC_TIMESTAMP()");
		}
		return true;
	}

	private static function current_views(string $content_type, int $post_id): int {
		global $wpdb;
		return (int) $wpdb->get_var($wpdb->prepare(
			"SELECT view_count FROM {$wpdb->prefix}jg_content_stats WHERE content_type = %s AND content_id = %d",
			$content_type,
			$post_id
		));
	}

	private static function post_type_for(string $content_type): ?string {
		if ($content_type === 'article') return 'post';
		if ($content_type === 'diary') return 'jg_diary';
		return null;
	}

	private static function request_origin(): string {
		return isset($_SERVER['HTTP_ORIGIN']) ? esc_url_raw(wp_unslash($_SERVER['HTTP_ORIGIN'])) : '';
	}

	private static function cors_headers(string $origin): array {
		return array(
			'Access-Control-Allow-Origin' => $origin,
			'Vary' => 'Origin',
			'Access-Control-Allow-Methods' => 'POST, OPTIONS',
			'Access-Control-Allow-Headers' => 'Content-Type',
			'Access-Control-Max-Age' => '86400',
		);
	}

	private static function is_bot_user_agent(): bool {
		$ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) wp_unslash($_SERVER['HTTP_USER_AGENT']) : '';
		return $ua === '' || preg_match(self::BOT_UA_PATTERN, $ua) === 1;
	}

	private static function rate_limited(): bool {
		$ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
		$key = 'jg_view_rate_' . substr(hash('sha256', $ip), 0, 24);
		$count = (int) get_transient($key);
		if ($count >= self::RATE_LIMIT) {
			return true;
		}
		set_transient($key, $count + 1, self::RATE_WINDOW);
		return false;
	}

	private static function json(array $data, int $status, array $headers = array()): WP_REST_Response {
		return new WP_REST_Response($data, $status, $headers);
	}
}
