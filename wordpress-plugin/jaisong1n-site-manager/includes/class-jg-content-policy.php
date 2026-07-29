<?php

if (!defined('ABSPATH')) {
	exit;
}

final class JG_Content_Policy {
	public const MAX_SNAPSHOT_BYTES = 2097152;
	public const MAX_RICH_TEXT_BYTES = 204800;
	public const MAX_SHORT_TEXT_BYTES = 2000;
	public const MAX_ITEMS_PER_TYPE = 500;
	public const MAX_PAGES = 100;
	public const MAX_ANNOUNCEMENTS = 20;
	public const MAX_NAVIGATION_ITEMS = 100;
	public const MAX_MEDIA_ITEMS = 1000;
	public const MAX_MEDIA_BYTES = 15728640;
	public const ALLOWED_IMAGE_MIME_TYPES = array(
		'image/jpeg',
		'image/png',
		'image/webp',
		'image/gif',
		'image/avif',
	);

	public const RESERVED_SLUGS = array(
		'404',
		'about',
		'ai-tools',
		'albums',
		'anime',
		'api',
		'archive',
		'atom',
		'devices',
		'diary',
		'friends',
		'og',
		'posts',
		'projects',
		'robots.txt',
		'rss',
		'sitemap-index',
		'skills',
		'timeline',
	);

	public static function public_post_types(): array {
		return array_merge(array('page'), array_keys(JG_Content_Types::definitions()));
	}

	public static function is_reserved_slug(string $slug): bool {
		return in_array(sanitize_title($slug), self::RESERVED_SLUGS, true);
	}

	public static function is_allowed_image_mime(string $mime_type): bool {
		return in_array(strtolower($mime_type), self::ALLOWED_IMAGE_MIME_TYPES, true);
	}

	public static function sanitize_host_list($value): array {
		$values = is_array($value) ? $value : preg_split('/[\r\n,]+/', (string) $value);
		$hosts = array();
		foreach ($values as $host) {
			$host = strtolower(trim((string) $host));
			$host = preg_replace('/^https?:\/\//i', '', $host);
			$host = trim((string) $host, " \t\n\r\0\x0B/.");
			if (
				$host === ''
				|| $host === 'localhost'
				|| str_ends_with($host, '.localhost')
				|| str_ends_with($host, '.local')
				|| !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $host)
			) {
				continue;
			}
			if (
				filter_var($host, FILTER_VALIDATE_IP)
				&& !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
			) {
				continue;
			}
			$hosts[$host] = true;
		}
		return array_keys($hosts);
	}

	public static function sanitize_public_html(string $html, array $embed_hosts = array()): string {
		$html = preg_replace('#<(script|style)\b[^>]*>[\s\S]*?</\1\s*>#i', '', $html);
		$html = preg_replace('/\s(?:on[a-z]+|style)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', (string) $html);
		$html = self::filter_embeds((string) $html, $embed_hosts);

		$allowed = wp_kses_allowed_html('post');
		$media_attributes = array(
			'src' => true,
			'controls' => true,
			'autoplay' => true,
			'loop' => true,
			'muted' => true,
			'poster' => true,
			'preload' => true,
			'width' => true,
			'height' => true,
			'allow' => true,
			'allowfullscreen' => true,
			'loading' => true,
			'title' => true,
		);
		$allowed['iframe'] = $media_attributes;
		$allowed['audio'] = $media_attributes;
		$allowed['video'] = $media_attributes;
		$allowed['source'] = array('src' => true, 'type' => true);

		return wp_kses($html, $allowed, array('http', 'https', 'mailto'));
	}

	private static function filter_embeds(string $html, array $embed_hosts): string {
		$site_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
		$allowed_hosts = array_fill_keys(self::sanitize_host_list($embed_hosts), true);
		if ($site_host !== '') {
			$allowed_hosts[$site_host] = true;
		}

		return (string) preg_replace_callback(
			'#<(iframe|audio|video)\b[^>]*?(?:>.*?</\1\s*>|/?>)#is',
			static function (array $matches) use ($allowed_hosts): string {
				if (!preg_match('/\ssrc\s*=\s*["\']([^"\']+)["\']/i', $matches[0], $src_match)) {
					error_log('[JG Site Manager] Removed embed without a valid src attribute.');
					return '';
				}
				$url = esc_url_raw($src_match[1], array('http', 'https'));
				$host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
				if ($url === '' || $host === '' || !isset($allowed_hosts[$host])) {
					error_log('[JG Site Manager] Removed embed from a disallowed host: ' . sanitize_text_field($host));
					return '';
				}
				return $matches[0];
			},
			$html
		);
	}

	public static function normalize($value) {
		if (!is_array($value)) {
			return $value;
		}
		$is_list = array_keys($value) === range(0, count($value) - 1);
		if (!$is_list) {
			ksort($value, SORT_STRING);
		}
		foreach ($value as $key => $item) {
			$value[$key] = self::normalize($item);
		}
		return $value;
	}

	public static function canonical_json(array $snapshot): string {
		unset($snapshot['generatedAt'], $snapshot['revision']);
		return json_encode(
			self::normalize($snapshot),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
		);
	}

	public static function revision(array $snapshot): string {
		return hash('sha256', self::canonical_json($snapshot));
	}

	public static function etag_matches(?string $header, string $etag): bool {
		if ($header === null || trim($header) === '') {
			return false;
		}
		foreach (explode(',', $header) as $candidate) {
			$candidate = trim($candidate);
			if (str_starts_with($candidate, 'W/')) {
				$candidate = substr($candidate, 2);
			}
			if ($candidate === '*' || hash_equals($etag, $candidate)) {
				return true;
			}
		}
		return false;
	}
}
