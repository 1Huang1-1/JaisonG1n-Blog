<?php

if (!defined('ABSPATH')) {
	exit;
}

final class JG_Media_Index {
	private const VERSION_OPTION = 'jg_media_index_version';
	private const VERSION = '1';

	public static function init(): void {
		self::install();
		add_action('save_post', array(__CLASS__, 'index_post'), 99, 3);
		add_action('updated_option', array(__CLASS__, 'settings_changed'), 20, 3);
	}

	public static function install(): void {
		if (get_option(self::VERSION_OPTION, '') === self::VERSION) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta("CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			attachment_id bigint(20) unsigned NOT NULL,
			object_id bigint(20) unsigned NOT NULL,
			object_type varchar(32) NOT NULL,
			field_name varchar(64) NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY object_reference (attachment_id,object_id,object_type,field_name),
			KEY attachment_lookup (attachment_id),
			KEY object_lookup (object_id,object_type)
		) {$charset};");
		update_option(self::VERSION_OPTION, self::VERSION, false);
	}

	public static function uninstall(): void {
		global $wpdb;
		$wpdb->query('DROP TABLE IF EXISTS ' . self::table());
		delete_option(self::VERSION_OPTION);
	}

	public static function index_post(int $post_id, WP_Post $post, bool $update = false): void {
		if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
			return;
		}
		self::remove_object($post_id, $post->post_type);
		if ($post->post_status !== 'publish' || !self::supported_post_type($post->post_type)) {
			return;
		}
		$refs = array();
		$thumbnail = get_post_thumbnail_id($post_id);
		if ($thumbnail > 0) {
			$refs[] = array('attachment_id' => $thumbnail, 'field_name' => 'featured');
		}
		foreach (array('_jg_images' => 'images', '_jg_photos' => 'photos') as $meta_key => $field_name) {
			self::add_nested_media($refs, get_post_meta($post_id, $meta_key, true), $field_name);
		}
		self::add_body_media($refs, (string) $post->post_content);
		self::insert_refs($post_id, $post->post_type, $refs);
	}

	public static function settings_changed(string $option, $old_value, $value): void {
		if ($option !== JG_Settings::OPTION || $old_value === $value) {
			return;
		}
		self::remove_object(0, 'settings');
		$settings = is_array($value) ? $value : array();
		$ids = array();
		foreach (array('avatar_id', 'logo_id') as $key) {
			$id = absint($settings[$key] ?? 0);
			if ($id > 0) $ids[$id] = $key;
		}
		foreach (array('banner_desktop_ids', 'banner_mobile_ids') as $key) {
			foreach (explode(',', (string) ($settings[$key] ?? '')) as $raw_id) {
				$id = absint($raw_id);
				if ($id > 0) $ids[$id] = $key;
			}
		}
		$refs = array();
		foreach ($ids as $id => $field_name) {
			$refs[] = array('attachment_id' => $id, 'field_name' => $field_name);
		}
		self::insert_refs(0, 'settings', $refs);
	}

	public static function has_public_reference(int $attachment_id): bool {
		if ($attachment_id <= 0) return false;
		global $wpdb;
		return (int) $wpdb->get_var($wpdb->prepare(
			' SELECT COUNT(*) FROM ' . self::table() . ' WHERE attachment_id = %d',
			$attachment_id
		)) > 0;
	}

	public static function remove_object(int $object_id, string $object_type): void {
		global $wpdb;
		$wpdb->delete(self::table(), array('object_id' => $object_id, 'object_type' => $object_type), array('%d', '%s'));
	}

	public static function remove_attachment(int $attachment_id): void {
		global $wpdb;
		$wpdb->delete(self::table(), array('attachment_id' => $attachment_id), array('%d'));
	}

	private static function insert_refs(int $object_id, string $object_type, array $refs): void {
		global $wpdb;
		$seen = array();
		foreach ($refs as $ref) {
			$attachment_id = absint($ref['attachment_id'] ?? 0);
			$field_name = sanitize_key((string) ($ref['field_name'] ?? 'media'));
			$key = $attachment_id . ':' . $field_name;
			if ($attachment_id <= 0 || isset($seen[$key]) || !self::is_image_attachment($attachment_id)) continue;
			$seen[$key] = true;
			$wpdb->insert(self::table(), array(
				'attachment_id' => $attachment_id,
				'object_id' => $object_id,
				'object_type' => $object_type,
				'field_name' => $field_name,
			), array('%d', '%d', '%s', '%s'));
		}
	}

	private static function add_nested_media(array &$refs, $value, string $field_name): void {
		if (is_string($value)) {
			$value = array_map('absint', array_filter(explode(',', $value)));
		}
		if (!is_array($value)) return;
		foreach ($value as $row) {
			if (is_array($row)) {
				$id = absint($row['mediaId'] ?? $row['media_id'] ?? $row['id'] ?? 0);
				if ($id > 0) $refs[] = array('attachment_id' => $id, 'field_name' => $field_name);
			} else {
				$id = absint($row);
				if ($id > 0) $refs[] = array('attachment_id' => $id, 'field_name' => $field_name);
			}
		}
	}

	private static function add_body_media(array &$refs, string $content): void {
		if ($content === '' || !preg_match_all("~(?:src|data-src|srcset)\\s*=\\s*[\"']([^\"']+)[\"']~i", $content, $matches)) return;
		foreach (array_slice($matches[1], 0, 100) as $candidate) {
			foreach (preg_split('/\\s*,\\s*/', $candidate) as $item) {
				$url = trim((string) preg_split('/\\s+/', trim($item))[0]);
				if ($url === '' || !preg_match('/^https?:\\/\\//i', $url)) continue;
				$id = absint(attachment_url_to_postid($url));
				if ($id > 0) $refs[] = array('attachment_id' => $id, 'field_name' => 'content');
			}
		}
	}

	private static function is_image_attachment(int $attachment_id): bool {
		$attachment = get_post($attachment_id);
		return $attachment instanceof WP_Post && $attachment->post_type === 'attachment' && str_starts_with((string) get_post_mime_type($attachment_id), 'image/');
	}

	private static function supported_post_type(string $post_type): bool {
		return in_array($post_type, array_merge(array('post', 'page'), array_values(array_filter(array_keys(JG_Content_Types::definitions()), static fn($type) => !JG_Content_Types::is_deprecated($type)))), true);
	}

	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'jg_media_refs';
	}
}
