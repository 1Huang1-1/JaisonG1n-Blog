<?php

if (!defined('ABSPATH')) {
	exit;
}

final class JG_Snapshot {
	private array $embed_hosts = array();
	private array $trusted_media_hosts = array();
	private array $media = array();
	private ?WP_Error $media_error = null;

	public function build() {
		$settings = JG_Settings::public_settings();
		$this->embed_hosts = $settings['security']['embedHosts'] ?? array();
		$this->trusted_media_hosts = array_fill_keys(
			JG_Content_Policy::sanitize_host_list($settings['security']['trustedMediaHosts'] ?? array()),
			true
		);

		$conflict = $this->validate_published_slugs();
		if (is_wp_error($conflict)) {
			return $conflict;
		}

		$this->register_settings_media($settings);
		$base = array(
			'schemaVersion' => 4,
			'site' => $settings['site'],
			'profile' => $settings['profile'],
			'appearance' => $settings['appearance'],
			'navigation' => $this->navigation(),
			'widgets' => $settings['widgets'],
			'pages' => $this->pages(),
			'projects' => $this->collection('jg_project'),
			'skills' => $this->collection('jg_skill'),
			'aiTools' => $this->collection('jg_ai_tool'),
			'timeline' => $this->collection('jg_timeline'),
			'friends' => $this->collection('jg_friend'),
			'techRadar' => $this->collection('jg_tech_radar'),
			'diary' => $this->collection('jg_diary'),
			'albums' => $this->collection('jg_album'),
			'learningResources' => $this->collection('jg_learning_resource'),
			'announcements' => $this->collection('jg_announcement', JG_Content_Policy::MAX_ANNOUNCEMENTS),
			'security' => $settings['security'],
		);

		foreach ($base as $value) {
			if (is_wp_error($value)) {
				return $value;
			}
		}
		if ($this->media_error) {
			return $this->media_error;
		}
		if (count($this->media) > JG_Content_Policy::MAX_MEDIA_ITEMS) {
			return new WP_Error('jg_media_manifest_limit', '媒体清单超过数量限制。', array('status' => 413));
		}
		ksort($this->media, SORT_NUMERIC);
		$base['mediaManifest'] = array_values($this->media);

		$revision = JG_Content_Policy::revision($base);
		$snapshot = array('revision' => $revision, 'generatedAt' => gmdate('c')) + $base;
		$json = wp_json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (!is_string($json) || strlen($json) > JG_Content_Policy::MAX_SNAPSHOT_BYTES) {
			return new WP_Error('jg_snapshot_too_large', '公开快照超过 2 MB 限制。', array('status' => 413));
		}
		return $snapshot;
	}

	public function revision() {
		$snapshot = $this->build();
		return is_wp_error($snapshot) ? $snapshot : $snapshot['revision'];
	}

	private function validate_published_slugs() {
		$posts = get_posts(array(
			'post_type' => JG_Content_Policy::public_post_types(),
			'post_status' => 'publish',
			'numberposts' => -1,
			'orderby' => 'ID',
			'order' => 'ASC',
		));
		$seen = array();
		foreach ($posts as $post) {
			$slug = sanitize_title($post->post_name);
			if ($slug === '') {
				return new WP_Error('jg_empty_slug', '已发布内容缺少 slug：' . $post->post_title, array('status' => 409));
			}
			if (JG_Content_Policy::is_reserved_slug($slug)) {
				return new WP_Error('jg_reserved_slug', '已发布内容使用了 Astro 保留 slug：' . $slug, array('status' => 409));
			}
			if (isset($seen[$slug])) {
				return new WP_Error('jg_duplicate_slug', '已发布内容存在重复 slug：' . $slug, array('status' => 409));
			}
			$seen[$slug] = true;
		}
		return true;
	}

	private function pages() {
		$posts = get_posts(array(
			'post_type' => 'page',
			'post_status' => 'publish',
			'numberposts' => JG_Content_Policy::MAX_PAGES + 1,
			'orderby' => array('menu_order' => 'ASC', 'date' => 'DESC', 'ID' => 'ASC'),
		));
		if (count($posts) > JG_Content_Policy::MAX_PAGES) {
			return new WP_Error('jg_pages_limit', '独立页面数量超过限制。', array('status' => 413));
		}
		$result = array();
		foreach ($posts as $post) {
			$featured = $this->featured_media($post->ID);
			$result[] = array(
				'id' => $post->ID,
				'slug' => $post->post_name,
				'title' => $this->title($post),
				'content' => $this->rich_text($post->post_content, $post->ID),
				'excerpt' => $this->plain_text($post->post_excerpt),
				'featuredImage' => $featured['url'],
				'featuredImageMedia' => $featured['media'],
				'published' => get_post_time('c', true, $post),
				'updated' => get_post_modified_time('c', true, $post),
			);
		}
		return $result;
	}

	private function collection(string $post_type, int $limit = JG_Content_Policy::MAX_ITEMS_PER_TYPE) {
		$posts = get_posts(array(
			'post_type' => $post_type,
			'post_status' => 'publish',
			'numberposts' => $limit + 1,
			'orderby' => array('menu_order' => 'ASC', 'date' => 'DESC', 'ID' => 'ASC'),
		));
		if (count($posts) > $limit) {
			return new WP_Error('jg_collection_limit', '内容类型 ' . $post_type . ' 超过数量限制。', array('status' => 413));
		}
		$result = array();
		foreach ($posts as $post) {
			$mapped = $this->map_item($post);
			if (is_wp_error($mapped)) return $mapped;
			$result[] = $mapped;
		}
		return $result;
	}

	private function map_item(WP_Post $post) {
		$title = $this->title($post);
		$content_html = $this->content_html($post->post_content, $post->ID);
		$description = $this->summary($post->post_excerpt, $content_html, $post);

		switch ($post->post_type) {
			case 'jg_project':
				$featured = $this->featured_media($post->ID);
				return array(
					'id' => $post->post_name,
					'title' => $title,
					'description' => $description,
					'contentHtml' => $content_html,
					'image' => $featured['url'],
					'imageMedia' => $featured['media'],
					'category' => $this->meta($post, 'category'),
					'techStack' => $this->csv($this->meta($post, 'tech_stack')),
					'status' => $this->meta($post, 'status'),
					'sourceCode' => $this->meta($post, 'source_code'),
					'visitUrl' => $this->meta($post, 'visit_url'),
					'featured' => $this->bool_meta($post, 'featured'),
					'showImage' => $this->bool_meta($post, 'show_image', true),
				);
			case 'jg_skill':
				return array(
					'id' => $post->post_name,
					'name' => $title,
					'description' => $description,
					'icon' => $this->meta($post, 'icon'),
					'category' => $this->meta($post, 'category'),
					'level' => $this->meta($post, 'level'),
					'experience' => array('years' => (int) $this->meta($post, 'experience_years'), 'months' => (int) $this->meta($post, 'experience_months')),
					'color' => $this->meta($post, 'color'),
				);
			case 'jg_ai_tool':
				return array(
					'id' => $post->post_name,
					'name' => $title,
					'description' => array('zh_CN' => $description),
					'icon' => $this->meta($post, 'icon'),
					'category' => $this->meta($post, 'category'),
					'frequency' => $this->meta($post, 'frequency'),
					'url' => $this->meta($post, 'url'),
					'usage' => array('zh_CN' => $this->plain_text($this->meta($post, 'usage'))),
					'tags' => $this->csv($this->meta($post, 'tags')),
					'color' => $this->meta($post, 'color'),
				);
			case 'jg_timeline':
				return array(
					'id' => $post->post_name,
					'title' => $title,
					'description' => $description,
					'contentHtml' => $content_html,
					'type' => $this->meta($post, 'type'),
					'startDate' => $this->meta($post, 'start_date'),
					'endDate' => $this->meta($post, 'end_date'),
					'location' => $this->meta($post, 'location'),
					'organization' => $this->meta($post, 'organization'),
					'position' => $this->meta($post, 'position'),
					'skills' => $this->csv($this->meta($post, 'skills')),
					'achievements' => $this->lines($this->meta($post, 'achievements')),
					'links' => $this->structured_meta($post, 'links'),
					'icon' => $this->meta($post, 'icon'),
					'color' => $this->meta($post, 'color'),
					'featured' => $this->bool_meta($post, 'featured'),
				);
			case 'jg_friend':
				$featured = $this->featured_media($post->ID);
				return array(
					'title' => $title,
					'icon' => $this->meta($post, 'icon'),
					'imgurl' => $featured['url'],
					'avatarMedia' => $featured['media'],
					'desc' => $description,
					'siteurl' => $this->meta($post, 'site_url'),
					'tags' => $this->csv($this->meta($post, 'tags')),
				);
			case 'jg_tech_radar':
				$first = $this->meta($post, 'first_observed_at');
				$last = $this->meta($post, 'last_reviewed_at');
				if ($first !== '' && $last !== '' && $last < $first) {
					return new WP_Error('jg_radar_date_order', 'Tech Radar review date cannot precede first observed date.', array('status' => 409));
				}
				$featured = $this->featured_media($post->ID);
				return array(
					'id' => $post->post_name,
					'title' => $title,
					'description' => $description,
					'contentHtml' => $content_html,
					'icon' => $this->meta($post, 'icon'),
					'image' => $featured['url'],
					'imageMedia' => $featured['media'],
					'domain' => $this->meta($post, 'domain'),
					'stage' => $this->meta($post, 'stage'),
					'trend' => $this->meta($post, 'trend'),
					'maturity' => (int) $this->meta($post, 'maturity'),
					'tags' => $this->csv($this->meta($post, 'tags')),
					'officialUrl' => $this->meta($post, 'official_url'),
					'sourceUrls' => $this->structured_meta($post, 'source_urls'),
					'firstObservedAt' => $first,
					'lastReviewedAt' => $last,
					'relatedPost' => $this->related_post($post),
					'featured' => $this->bool_meta($post, 'featured'),
				);
			case 'jg_learning_resource':
				$started = $this->meta($post, 'started_at');
				$completed = $this->meta($post, 'completed_at');
				if ($started !== '' && $completed !== '' && $completed < $started) {
					return new WP_Error('jg_learning_date_order', 'Learning completion date cannot precede start date.', array('status' => 409));
				}
				$progress = (int) $this->meta($post, 'progress');
				$total_units = (int) $this->meta($post, 'total_units');
				if ($total_units > 0 && $progress > $total_units) {
					return new WP_Error('jg_learning_progress', 'Learning progress cannot exceed total units.', array('status' => 409));
				}
				$featured = $this->featured_media($post->ID);
				return array(
					'id' => $post->post_name,
					'title' => $title,
					'description' => $description,
					'contentHtml' => $content_html,
					'icon' => $this->meta($post, 'icon'),
					'cover' => $featured['url'],
					'coverMedia' => $featured['media'],
					'type' => $this->meta($post, 'type'),
					'status' => $this->meta($post, 'status'),
					'author' => $this->meta($post, 'author'),
					'publishedYear' => $this->meta($post, 'published_year') === '' || (int) $this->meta($post, 'published_year') === 0 ? '' : (int) $this->meta($post, 'published_year'),
					'rating' => (float) $this->meta($post, 'rating'),
					'progress' => $progress,
					'totalUnits' => $total_units,
					'sourceUrl' => $this->meta($post, 'source_url'),
					'tags' => $this->csv($this->meta($post, 'tags')),
					'startedAt' => $started,
					'completedAt' => $completed,
					'relatedPost' => $this->related_post($post),
					'featured' => $this->bool_meta($post, 'featured'),
				);
			case 'jg_device':
				$featured = $this->featured_media($post->ID);
				return array(
					'name' => $title,
					'image' => $featured['url'],
					'imageMedia' => $featured['media'],
					'description' => $description,
					'category' => $this->meta($post, 'category'),
					'specs' => $this->structured_meta($post, 'specs'),
					'link' => $this->meta($post, 'link'),
				);
			case 'jg_diary':
				$images = $this->diary_media_refs($post, 'images');
				$featured = $this->featured_media($post->ID);
				$cover = $images[0] ?? $this->media_ref($featured['media']);
				$diary_date = $this->meta($post, 'diary_date');
				if ($diary_date === '') $diary_date = get_post_time('Y-m-d', true, $post);
				$mood = $this->diary_mood($post);
				return array(
					'id' => $post->post_name,
					'title' => $title,
					'description' => $description,
					'contentHtml' => $content_html,
					'date' => $diary_date,
					'publishedAt' => get_post_time('c', true, $post),
					'updatedAt' => get_post_modified_time('c', true, $post),
					'location' => $this->meta($post, 'location'),
					'mood' => $mood,
					'tags' => $this->csv($this->meta($post, 'tags')),
					'images' => $images,
					'coverImage' => $cover,
					'featured' => $this->bool_meta($post, 'featured'),
				);
			case 'jg_album':
				$featured = $this->featured_media($post->ID);
				return array(
					'id' => $post->post_name,
					'title' => $title,
					'description' => $description,
					'contentHtml' => $content_html,
					'cover' => $featured['url'],
					'coverMedia' => $featured['media'],
					'date' => $this->meta($post, 'album_date') ?: get_post_time('Y-m-d', true, $post),
					'location' => $this->meta($post, 'location'),
					'tags' => $this->csv($this->meta($post, 'tags')),
					'photos' => $this->media_refs($post, 'photos'),
				);
			case 'jg_anime':
				$featured = $this->featured_media($post->ID);
				return array(
					'title' => $title,
					'description' => $description,
					'cover' => $featured['url'],
					'coverMedia' => $featured['media'],
					'status' => $this->meta($post, 'status'),
					'rating' => (float) $this->meta($post, 'rating'),
					'year' => $this->meta($post, 'year'),
					'genre' => $this->csv($this->meta($post, 'genre')),
					'studio' => $this->meta($post, 'studio'),
					'link' => $this->meta($post, 'link'),
					'progress' => (int) $this->meta($post, 'progress'),
					'totalEpisodes' => (int) $this->meta($post, 'total_episodes'),
				);
			case 'jg_announcement':
				return array(
					'title' => $title,
					'content' => $description,
					'closable' => $this->bool_meta($post, 'closable', true),
					'link' => array(
						'enable' => $this->bool_meta($post, 'link_enable'),
						'text' => $this->meta($post, 'link_text'),
						'url' => $this->meta($post, 'link_url'),
						'external' => $this->bool_meta($post, 'link_external'),
					),
				);
		}
		return array();
	}

	private function navigation() {
		$locations = get_nav_menu_locations();
		$menu_id = absint($locations['jg_primary_navigation'] ?? 0);
		if (!$menu_id) return array();
		$items = wp_get_nav_menu_items($menu_id, array('post_status' => 'publish'));
		if (!is_array($items)) return array();
		if (count($items) > JG_Content_Policy::MAX_NAVIGATION_ITEMS) return new WP_Error('jg_navigation_limit', '导航项目超过限制。', array('status' => 413));
		$nodes = array();
		$children = array();
		foreach ($items as $item) {
			$url = esc_url_raw($item->url, array('http', 'https'));
			if ($url === '' && !str_starts_with((string) $item->url, '/')) continue;
			$nodes[$item->ID] = array('id' => $item->ID, 'name' => sanitize_text_field($item->title), 'url' => $url ?: sanitize_text_field($item->url), 'icon' => sanitize_text_field($item->description), 'external' => $url !== '' && wp_parse_url($url, PHP_URL_HOST) !== wp_parse_url(home_url('/'), PHP_URL_HOST), 'children' => array());
			$children[$item->ID] = absint($item->menu_item_parent);
		}
		$roots = array();
		foreach ($nodes as $id => $node) {
			$parent = $children[$id] ?? 0;
			if ($parent && isset($nodes[$parent])) {
				if (($children[$parent] ?? 0) !== 0) return new WP_Error('jg_navigation_depth', '导航最多允许两级。', array('status' => 409));
				$nodes[$parent]['children'][] = $node;
			} else {
				$roots[] = $node;
			}
		}
		return $roots;
	}

	private function title(WP_Post $post): string {
		return html_entity_decode(get_the_title($post), ENT_QUOTES | ENT_HTML5, 'UTF-8');
	}

	private function content_html($html, int $post_id): string {
		$html = $this->safe_string($html, $post_id, 'content');
		if (strlen($html) > JG_Content_Policy::MAX_RICH_TEXT_BYTES) {
			throw new LengthException('Content exceeds limit for post ' . $post_id);
		}
		$precleaned = JG_Content_Policy::sanitize_public_html($html, $this->embed_hosts);
		$filtered = apply_filters('the_content', $precleaned);
		if (!is_string($filtered)) {
			$this->log_mapping_warning($post_id, 'content_filter_non_string');
			$filtered = $precleaned;
		}
		$clean = wp_kses_post(JG_Content_Policy::sanitize_public_html($filtered, $this->embed_hosts));
		$this->register_inline_media($clean);
		return $clean;
	}

	private function rich_text($html, int $post_id): string {
		$html = $this->safe_string($html, $post_id, 'rich_text');
		if (strlen($html) > JG_Content_Policy::MAX_RICH_TEXT_BYTES) {
			throw new LengthException('Content exceeds limit for post ' . $post_id);
		}
		$precleaned = JG_Content_Policy::sanitize_public_html($html, $this->embed_hosts);
		$filtered = apply_filters('the_content', $precleaned);
		if (!is_string($filtered)) {
			$this->log_mapping_warning($post_id, 'rich_text_filter_non_string');
			$filtered = $precleaned;
		}
		$clean = JG_Content_Policy::sanitize_public_html($filtered, $this->embed_hosts);
		$this->register_inline_media($clean);
		return $clean;
	}

	private function summary($excerpt, string $content_html, WP_Post $post): string {
		$manual = $this->plain_text($excerpt, $post->ID, 'excerpt');
		if ($manual !== '') {
			return $manual;
		}
		return $this->truncate_summary($this->plain_text($content_html, $post->ID, 'content_summary'));
	}

	private function plain_text($value, int $post_id = 0, string $stage = 'text'): string {
		$value = $this->safe_string($value, $post_id, $stage);
		$text = html_entity_decode(wp_strip_all_tags(wp_kses_post($value)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$normalized = preg_replace('/\s+/u', ' ', $text);
		return trim(is_string($normalized) ? $normalized : $text);
	}

	private function truncate_summary(string $text): string {
		$limit = 140;
		if ($this->unicode_length($text) <= $limit) {
			return $text;
		}
		return $this->unicode_slice($text, $limit - 1) . '…';
	}

	private function unicode_length(string $value): int {
		if (function_exists('mb_strlen')) {
			return (int) mb_strlen($value, 'UTF-8');
		}
		preg_match_all('/./us', $value, $matches);
		return count($matches[0] ?? array());
	}

	private function unicode_slice(string $value, int $length): string {
		if (function_exists('mb_substr')) {
			return (string) mb_substr($value, 0, $length, 'UTF-8');
		}
		preg_match_all('/./us', $value, $matches);
		return implode('', array_slice($matches[0] ?? array(), 0, $length));
	}

	private function safe_string($value, int $post_id, string $stage): string {
		if (!is_string($value)) {
			if ($value !== null && $value !== false && $value !== array()) {
				$this->log_mapping_warning($post_id, $stage . '_non_string');
			}
			return '';
		}
		$clean = wp_check_invalid_utf8($value, true);
		if ($clean !== $value) {
			$this->log_mapping_warning($post_id, $stage . '_invalid_utf8');
		}
		return is_string($clean) ? $clean : '';
	}

	private function log_mapping_warning(int $post_id, string $stage): void {
		$post = $post_id > 0 ? get_post($post_id) : null;
		error_log(sprintf(
			'JaisonG1n Site Manager content mapping warning: type=%s id=%d slug=%s stage=%s',
			$post instanceof WP_Post ? $post->post_type : 'unknown',
			$post_id,
			$post instanceof WP_Post ? sanitize_key($post->post_name) : '',
			$stage,
		));
	}

	private function register_settings_media(array $settings): void {
		$urls = array(
			$settings['profile']['avatar'] ?? '',
			$settings['profile']['logo'] ?? '',
		);
		foreach (array('desktop', 'mobile') as $target) {
			foreach (($settings['appearance']['banner'][$target] ?? array()) as $url) {
				$urls[] = $url;
			}
		}
		foreach ($urls as $url) {
			if ((string) $url !== '') $this->media_from_url((string) $url);
		}
	}

	private function register_inline_media(string $html): void {
		if (!preg_match_all('#<img\b[^>]*\bsrc\s*=\s*["\']([^"\']+)["\']#i', $html, $matches)) {
			return;
		}
		foreach (array_unique($matches[1]) as $url) {
			$this->media_from_url((string) $url);
		}
	}

	private function featured_media(int $post_id): array {
		$attachment_id = get_post_thumbnail_id($post_id);
		if (!$attachment_id) return array('url' => '', 'media' => null);
		$media = $this->media_object((int) $attachment_id);
		if (is_wp_error($media)) {
			$this->remember_media_error($media);
			return array('url' => '', 'media' => null);
		}
		return array('url' => $media['url'], 'media' => $media);
	}

	private function media_refs(WP_Post $post, string $key): array {
		$refs = array();
		foreach ($this->structured_meta($post, $key) as $row) {
			$media = $this->media_object(absint($row['mediaId'] ?? 0));
			if (is_wp_error($media)) {
				$this->remember_media_error($media);
				continue;
			}
			$refs[] = array('mediaId' => $media['id'], 'src' => $media['url'], 'alt' => $media['alt']);
		}
		return $refs;
	}

	private function diary_media_refs(WP_Post $post, string $key): array {
		$refs = array();
		foreach ($this->structured_meta($post, $key) as $row) {
			$media = $this->media_object(absint($row['mediaId'] ?? 0));
			if (is_wp_error($media)) {
				$this->remember_media_error($media);
				continue;
			}
			$refs[] = array(
				'mediaId' => $media['id'],
				'src' => $media['url'],
				'alt' => $media['alt'],
				'width' => $media['width'],
				'height' => $media['height'],
			);
		}
		return $refs;
	}

	private function diary_mood(WP_Post $post): string {
		$value = get_post_meta($post->ID, '_jg_mood', true);
		if (!is_scalar($value)) return 'other';
		$value = sanitize_key((string) $value);
		$allowed = array('happy', 'calm', 'fulfilled', 'excited', 'thinking', 'tired', 'anxious', 'sad', 'other');
		return in_array($value, $allowed, true) ? $value : ($value === '' ? '' : 'other');
	}

	private function media_ref(?array $media): ?array {
		if (!$media) return null;
		return array(
			'mediaId' => $media['id'],
			'src' => $media['url'],
			'alt' => $media['alt'],
			'width' => $media['width'],
			'height' => $media['height'],
		);
	}

	private function media_from_url(string $url): void {
		$url = esc_url_raw($url, array('https'));
		if ($url === '') {
			$this->remember_media_error(new WP_Error('jg_media_protocol', '媒体 URL 必须使用 HTTPS。', array('status' => 409)));
			return;
		}
		$attachment_id = attachment_url_to_postid($url);
		if (!$attachment_id) {
			$this->remember_media_error(new WP_Error('jg_media_attachment_missing', '媒体 URL 不是有效的 WordPress 附件：' . $url, array('status' => 409)));
			return;
		}
		$media = $this->media_object((int) $attachment_id);
		if (is_wp_error($media)) $this->remember_media_error($media);
	}

	private function media_object(int $attachment_id) {
		if ($attachment_id <= 0) {
			return new WP_Error('jg_media_attachment_missing', '媒体附件 ID 无效。', array('status' => 409));
		}
		if (isset($this->media[$attachment_id])) {
			return $this->media[$attachment_id];
		}
		$post = get_post($attachment_id);
		if (!$post instanceof WP_Post || $post->post_type !== 'attachment') {
			return new WP_Error('jg_media_attachment_missing', '媒体附件不存在：' . $attachment_id, array('status' => 409));
		}
		$mime_type = strtolower((string) get_post_mime_type($attachment_id));
		if (!JG_Content_Policy::is_allowed_image_mime($mime_type)) {
			return new WP_Error('jg_media_mime', '媒体 MIME 不受支持：' . $mime_type, array('status' => 415));
		}
		$url = esc_url_raw((string) wp_get_attachment_url($attachment_id), array('https'));
		$host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
		if ($url === '' || $host === '' || !isset($this->trusted_media_hosts[$host])) {
			return new WP_Error('jg_media_host', '媒体不属于可信 WordPress 主机：' . $host, array('status' => 409));
		}
		$file = get_attached_file($attachment_id);
		if (!is_string($file) || !is_file($file)) {
			return new WP_Error('jg_media_file_missing', '媒体文件不存在：' . $attachment_id, array('status' => 409));
		}
		$file_size = filesize($file);
		if (!is_int($file_size) || $file_size <= 0 || $file_size > JG_Content_Policy::MAX_MEDIA_BYTES) {
			return new WP_Error('jg_media_size', '媒体文件大小无效或超过 15 MB：' . $attachment_id, array('status' => 413));
		}
		$metadata = wp_get_attachment_metadata($attachment_id);
		$width = is_array($metadata) ? absint($metadata['width'] ?? 0) : 0;
		$height = is_array($metadata) ? absint($metadata['height'] ?? 0) : 0;
		if ($width <= 0 || $height <= 0) {
			return new WP_Error('jg_media_dimensions', '媒体缺少有效尺寸：' . $attachment_id, array('status' => 409));
		}
		$alt = $this->plain_text((string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true));
		if ($alt === '') $alt = $this->plain_text(get_the_title($attachment_id));
		$media = array(
			'id' => $attachment_id,
			'url' => $url,
			'alt' => $alt,
			'mimeType' => $mime_type,
			'width' => $width,
			'height' => $height,
		);
		$this->media[$attachment_id] = $media;
		return $media;
	}

	private function remember_media_error(WP_Error $error): void {
		if (!$this->media_error) $this->media_error = $error;
	}

	private function structured_meta(WP_Post $post, string $key): array {
		$field = JG_Content_Types::field_definitions()[$post->post_type][$key] ?? null;
		if (!$field) return array();
		$value = get_post_meta($post->ID, '_jg_' . $key, true);
		$result = JG_Content_Types::sanitize_field($value, $field);
		return is_array($result) ? $result : array();
	}

	private function meta(WP_Post $post, string $key): string {
		$value = get_post_meta($post->ID, '_jg_' . $key, true);
		$field = JG_Content_Types::field_definitions()[$post->post_type][$key] ?? null;
		if ($field) {
			$value = JG_Content_Types::sanitize_field($value, $field);
		}
		return is_scalar($value) ? (string) $value : '';
	}

	private function related_post(WP_Post $post): ?array {
		$related_id = absint($this->meta($post, 'related_post_id'));
		if ($related_id <= 0) return null;
		$related = get_post($related_id);
		if (!$related instanceof WP_Post || $related->post_type !== 'post' || $related->post_status !== 'publish') {
			return null;
		}
		return array('postId' => $related_id);
	}

	private function bool_meta(WP_Post $post, string $key, bool $default = false): bool {
		if (!metadata_exists('post', $post->ID, '_jg_' . $key)) return $default;
		return (bool) get_post_meta($post->ID, '_jg_' . $key, true);
	}

	private function csv(string $value): array {
		return array_values(array_filter(array_map('trim', explode(',', $value)), static fn($item) => $item !== ''));
	}

	private function lines(string $value): array {
		return array_values(array_filter(array_map('trim', preg_split('/\R/', $value)), static fn($item) => $item !== ''));
	}
}
