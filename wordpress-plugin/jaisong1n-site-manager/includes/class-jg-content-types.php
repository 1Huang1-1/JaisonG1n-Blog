<?php

if (!defined('ABSPATH')) {
	exit;
}

final class JG_Content_Types {
	private const NONCE_ACTION = 'jg_save_content_fields';
	private const NONCE_NAME = 'jg_content_nonce';
	private const MAX_REPEATER_ROWS = 500;

	public static function definitions(): array {
		return array(
			'jg_project' => array('single' => '项目', 'plural' => '项目', 'icon' => 'dashicons-portfolio', 'thumbnail' => true),
			'jg_skill' => array('single' => '技能', 'plural' => '技能', 'icon' => 'dashicons-awards', 'thumbnail' => false),
			'jg_ai_tool' => array('single' => 'AI 工具', 'plural' => 'AI 工具', 'icon' => 'dashicons-superhero', 'thumbnail' => false),
			'jg_timeline' => array('single' => '时间线', 'plural' => '时间线', 'icon' => 'dashicons-backup', 'thumbnail' => false),
			'jg_friend' => array('single' => '友链', 'plural' => '友链', 'icon' => 'dashicons-admin-links', 'thumbnail' => true),
			'jg_device' => array('single' => '设备', 'plural' => '设备', 'icon' => 'dashicons-smartphone', 'thumbnail' => true, 'deprecated' => true),
			'jg_diary' => array('single' => '日记', 'plural' => '日记', 'icon' => 'dashicons-edit-page', 'thumbnail' => true),
			'jg_album' => array('single' => '相册', 'plural' => '相册', 'icon' => 'dashicons-format-gallery', 'thumbnail' => true),
			'jg_anime' => array('single' => '追番', 'plural' => '追番', 'icon' => 'dashicons-video-alt3', 'thumbnail' => true, 'deprecated' => true),
			'jg_announcement' => array('single' => '公告', 'plural' => '公告', 'icon' => 'dashicons-megaphone', 'thumbnail' => false),
			'jg_tech_radar' => array('single' => 'Tech Radar', 'plural' => 'Tech Radar', 'icon' => 'dashicons-chart-area', 'thumbnail' => true),
			'jg_learning_resource' => array('single' => 'Learning Resource', 'plural' => 'Learning Resources', 'icon' => 'dashicons-book-alt', 'thumbnail' => true),
		);
	}

	public static function field_definitions(): array {
		return array(
			'jg_project' => array(
				'category' => self::select('分类', array('web' => '网页', 'mobile' => '移动端', 'desktop' => '桌面端', 'other' => '其他')),
				'tech_stack' => self::text('技术栈（逗号分隔）'),
				'status' => self::select('状态', array('completed' => '已完成', 'in-progress' => '进行中', 'planned' => '计划中')),
				'source_code' => self::url('源代码地址'),
				'visit_url' => self::url('访问地址'),
				'featured' => self::checkbox('精选项目'),
				'show_image' => self::checkbox('显示封面', true),
			),
			'jg_skill' => array(
				'icon' => self::text('Iconify 图标名'),
				'category' => self::select('分类', array('frontend' => '前端', 'backend' => '后端', 'database' => '数据库', 'tools' => '工具', 'other' => '其他')),
				'level' => self::select('熟练度', array('beginner' => '初级', 'intermediate' => '中级', 'advanced' => '高级', 'expert' => '专家')),
				'experience_years' => self::number('经验年数', 0, 80),
				'experience_months' => self::number('额外月数', 0, 11),
				'color' => self::color('主题颜色'),
			),
			'jg_ai_tool' => array(
				'icon' => self::text('Iconify 图标名'),
				'category' => self::select('分类', array('chat' => '对话', 'coding' => '编码', 'image' => '图像', 'audio' => '音频', 'video' => '视频', 'writing' => '写作', 'search' => '搜索', 'other' => '其他')),
				'frequency' => self::select('使用频率', array('daily' => '每天', 'weekly' => '每周', 'occasional' => '偶尔', 'experimental' => '尝鲜')),
				'url' => self::url('官网'),
				'usage' => self::textarea('使用方式（中文）'),
				'tags' => self::text('标签（逗号分隔）'),
				'color' => self::color('主题颜色'),
			),
			'jg_timeline' => array(
				'type' => self::select('类型', array('education' => '教育', 'work' => '工作', 'project' => '项目', 'achievement' => '成就')),
				'start_date' => self::date('开始日期'),
				'end_date' => self::date('结束日期'),
				'location' => self::text('地点'),
				'organization' => self::text('组织'),
				'position' => self::text('职位'),
				'skills' => self::text('技能（逗号分隔）'),
				'achievements' => self::textarea('成就（每行一个）'),
				'links' => self::links_repeater('相关链接'),
				'icon' => self::text('Iconify 图标名'),
				'color' => self::color('主题颜色'),
				'featured' => self::checkbox('重点展示'),
			),
			'jg_friend' => array(
				'icon' => self::text('Iconify 图标名（例如 simple-icons:github）'),
				'site_url' => self::url('网站地址'),
				'tags' => self::text('标签（逗号分隔）'),
			),
			'jg_device' => array(
				'category' => self::text('设备分类（可自定义）'),
				'specs' => self::specs_repeater('规格参数'),
				'link' => self::url('产品链接'),
			),
			'jg_diary' => array(
				'images' => self::media_repeater('日记图片'),
				'diary_date' => self::date('日记日期'),
				'location' => self::text('地点'),
				'mood' => self::select('心情', array('' => '未指定', 'happy' => '开心', 'calm' => '平静', 'fulfilled' => '充实', 'excited' => '兴奋', 'thinking' => '思考', 'tired' => '疲惫', 'anxious' => '焦虑', 'sad' => '低落', 'other' => '其他')),
				'tags' => self::text('标签（逗号分隔）'),
				'featured' => self::checkbox('精选日记'),
			),
			'jg_album' => array(
				'photos' => self::media_repeater('相册图片'),
				'album_date' => self::date('相册日期'),
				'location' => self::text('地点'),
				'tags' => self::text('标签（逗号分隔）'),
			),
			'jg_anime' => array(
				'status' => self::select('状态', array('watching' => '在看', 'completed' => '看完', 'planned' => '计划', 'onhold' => '搁置', 'dropped' => '弃番')),
				'rating' => self::number('评分', 0, 10, '0.1'),
				'year' => self::text('年份'),
				'genre' => self::text('类型（逗号分隔）'),
				'studio' => self::text('制作公司'),
				'link' => self::url('详情链接'),
				'progress' => self::number('观看进度', 0, 100000),
				'total_episodes' => self::number('总集数', 0, 100000),
			),
			'jg_tech_radar' => array(
				'icon' => self::text('Iconify icon'),
				'domain' => self::select('Domain', array('ai' => 'AI', 'frontend' => 'Frontend', 'backend' => 'Backend', 'data' => 'Data', 'infrastructure' => 'Infrastructure', 'security' => 'Security', 'hardware' => 'Hardware', 'developer-tools' => 'Developer Tools', 'other' => 'Other')),
				'stage' => self::select('Stage', array('adopt' => 'Adopt', 'trial' => 'Trial', 'assess' => 'Assess', 'hold' => 'Hold')),
				'trend' => self::select('Trend', array('rising' => 'Rising', 'stable' => 'Stable', 'declining' => 'Declining', 'uncertain' => 'Uncertain')),
				'maturity' => self::number('Maturity', 0, 100),
				'tags' => self::text('Tags (comma separated)'),
				'official_url' => self::url('Official URL'),
				'source_urls' => self::source_urls_repeater('Source URLs'),
				'first_observed_at' => self::date('First observed'),
				'last_reviewed_at' => self::date('Last reviewed'),
				'related_post_id' => self::post_id('Related post ID'),
				'featured' => self::checkbox('Featured'),
			),
			'jg_learning_resource' => array(
				'icon' => self::text('Iconify icon'),
				'type' => self::select('Type', array('book' => 'Book', 'course' => 'Course', 'paper' => 'Paper', 'docs' => 'Docs', 'tutorial' => 'Tutorial', 'video' => 'Video', 'other' => 'Other')),
				'status' => self::select('Status', array('planned' => 'Planned', 'learning' => 'Learning', 'completed' => 'Completed', 'paused' => 'Paused')),
				'author' => self::text('Author'),
				'published_year' => self::number('Published year', 0, 3000),
				'rating' => self::number('Rating', 0, 10, '0.1'),
				'progress' => self::number('Progress', 0, 100000),
				'total_units' => self::number('Total units', 0, 100000),
				'source_url' => self::url('Source URL'),
				'tags' => self::text('Tags (comma separated)'),
				'started_at' => self::date('Started'),
				'completed_at' => self::date('Completed'),
				'related_post_id' => self::post_id('Related post ID'),
				'featured' => self::checkbox('Featured'),
			),
			'jg_announcement' => array(
				'closable' => self::checkbox('允许关闭', true),
				'link_enable' => self::checkbox('显示链接'),
				'link_text' => self::text('链接文字'),
				'link_url' => self::announcement_url('链接地址'),
				'link_external' => self::checkbox('外部链接'),
			),
		);
	}

	public static function init(): void {
		add_action('init', array(__CLASS__, 'register'));
		add_action('add_meta_boxes', array(__CLASS__, 'add_meta_boxes'));
		add_action('save_post', array(__CLASS__, 'save_fields'), 10, 2);
		add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_admin_assets'));
		add_action('admin_init', array(__CLASS__, 'grant_capabilities'));
		add_filter('wp_insert_post_data', array(__CLASS__, 'validate_classic_save'), 10, 4);
		add_filter('post_row_actions', array(__CLASS__, 'remove_view_action'), 10, 2);
		add_filter('get_sample_permalink_html', array(__CLASS__, 'hide_sample_permalink'), 10, 2);
		add_filter('preview_post_link', array(__CLASS__, 'hide_preview_link'), 10, 2);
		foreach (self::public_post_types() as $post_type) {
			add_filter("rest_pre_insert_{$post_type}", array(__CLASS__, 'validate_rest_save'), 10, 2);
		}
		register_nav_menus(array('jg_primary_navigation' => 'JaisonG1n 顶部导航'));
	}

	public static function register(): void {
		foreach (self::definitions() as $post_type => $definition) {
			$plural_capability = $post_type . 's';
			$supports = array('title', 'editor', 'revisions', 'custom-fields');
			if (in_array($post_type, array('jg_project', 'jg_timeline', 'jg_diary', 'jg_tech_radar', 'jg_learning_resource'), true)) {
				$supports[] = 'excerpt';
			}
			if ($definition['thumbnail']) {
				$supports[] = 'thumbnail';
			}
			register_post_type($post_type, array(
				'labels' => array(
					'name' => $definition['plural'],
					'singular_name' => $definition['single'],
					'add_new_item' => '新增' . $definition['single'],
					'edit_item' => '编辑' . $definition['single'],
					'not_found' => '暂无内容',
				),
				'public' => false,
				'publicly_queryable' => false,
				'exclude_from_search' => true,
				'show_ui' => empty($definition['deprecated']),
				'show_in_menu' => empty($definition['deprecated']),
				'show_in_rest' => true,
				'show_in_nav_menus' => false,
				'menu_icon' => $definition['icon'],
				'supports' => $supports,
				'capability_type' => array($post_type, $plural_capability),
				'map_meta_cap' => true,
				'has_archive' => false,
				'query_var' => false,
				'rewrite' => false,
			));
			self::register_meta_fields($post_type);
		}
	}

	private static function register_meta_fields(string $post_type): void {
		$fields = self::field_definitions()[$post_type] ?? array();
		foreach ($fields as $key => $field) {
			$is_array = in_array($field['type'], array('specs_repeater', 'links_repeater', 'source_urls_repeater', 'media_repeater'), true);
			$type = $is_array ? 'array' : ($field['type'] === 'checkbox' ? 'boolean' : ($field['type'] === 'number' ? 'number' : 'string'));
			$show_in_rest = $is_array ? array('schema' => $field['schema']) : true;
			register_post_meta($post_type, '_jg_' . $key, array(
				'single' => true,
				'type' => $type,
				'show_in_rest' => $show_in_rest,
				'sanitize_callback' => static fn($value) => self::sanitize_field($value, $field),
				'auth_callback' => static fn($allowed, $meta_key, $post_id) => current_user_can('edit_post', (int) $post_id),
			));
		}
	}

	public static function grant_capabilities(): void {
		foreach (array('administrator', 'editor') as $role_name) {
			$role = get_role($role_name);
			if (!$role) {
				continue;
			}
			foreach (array_keys(self::definitions()) as $post_type) {
				$object = get_post_type_object($post_type);
				if (!$object) {
					continue;
				}
				foreach ((array) $object->cap as $capability) {
					$role->add_cap($capability);
				}
			}
		}
	}

	public static function add_meta_boxes(): void {
		foreach (array_keys(self::definitions()) as $post_type) {
			add_meta_box('jg_content_fields', '博客展示字段', array(__CLASS__, 'render_meta_box'), $post_type, 'normal', 'high');
			remove_meta_box('postcustom', $post_type, 'normal');
		}
	}

	public static function render_meta_box(WP_Post $post): void {
		wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);
		$fields = self::field_definitions()[$post->post_type] ?? array();
		echo '<div class="jg-fields">';
		self::render_order_field($post);
		foreach ($fields as $key => $field) {
			$value = get_post_meta($post->ID, '_jg_' . $key, true);
			if ($value === '' && array_key_exists('default', $field)) {
				$value = $field['default'];
			}
			self::render_field($key, $field, $value);
		}
		echo '</div>';
	}

	private static function render_order_field(WP_Post $post): void {
		echo '<div class="jg-field"><label for="jg-menu-order"><strong>显示顺序</strong></label>';
		echo '<input class="small-text" type="number" min="-100000" max="100000" step="1" id="jg-menu-order" name="jg_menu_order" value="' . esc_attr((string) $post->menu_order) . '">';
		echo '<p class="description">数字越小越靠前；相同数值按发布时间降序、WordPress ID 升序排列。</p></div>';
	}

	private static function render_field(string $key, array $field, $value): void {
		$name = 'jg_fields[' . $key . ']';
		$id = 'jg-field-' . $key;
		$is_repeater = str_ends_with($field['type'], '_repeater');
		echo '<div class="jg-field' . ($is_repeater ? ' jg-field--wide' : '') . '"><label for="' . esc_attr($id) . '"><strong>' . esc_html($field['label']) . '</strong></label>';
		switch ($field['type']) {
			case 'textarea':
				echo '<textarea class="widefat" rows="4" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '">' . esc_textarea((string) $value) . '</textarea>';
				break;
			case 'select':
				echo '<select id="' . esc_attr($id) . '" name="' . esc_attr($name) . '">';
				foreach ($field['options'] as $option_value => $label) {
					echo '<option value="' . esc_attr($option_value) . '" ' . selected((string) $value, (string) $option_value, false) . '>' . esc_html($label) . '</option>';
				}
				echo '</select>';
				break;
			case 'checkbox':
				echo '<input type="hidden" name="' . esc_attr($name) . '" value="0"><label><input type="checkbox" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="1" ' . checked((bool) $value, true, false) . '> 启用</label>';
				break;
			case 'specs_repeater':
			case 'links_repeater':
			case 'source_urls_repeater':
			case 'media_repeater':
				self::render_repeater($key, $field, $value);
				break;
			default:
				$input_type = $field['type'] === 'announcement_url' ? 'url' : ($field['type'] === 'post_id' ? 'number' : $field['type']);
				$attributes = '';
				foreach (array('min', 'max', 'step') as $attribute) {
					if (isset($field[$attribute])) {
						$attributes .= ' ' . $attribute . '="' . esc_attr((string) $field[$attribute]) . '"';
					}
				}
				echo '<input class="regular-text" type="' . esc_attr($input_type) . '" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="' . esc_attr((string) $value) . '"' . $attributes . '>';
		}
		echo '</div>';
	}

	private static function render_repeater(string $key, array $field, $value): void {
		$type = $field['type'];
		$rows = self::sanitize_field($value, $field);
		$name = 'jg_fields[' . $key . ']';
		echo '<div class="jg-repeater" data-repeater-type="' . esc_attr($type) . '" data-name="' . esc_attr($name) . '">';
		echo '<div class="jg-repeater-items">';
		foreach ($rows as $index => $row) {
			self::render_repeater_row($type, $name, (int) $index, $row);
		}
		echo '</div>';
		if ($type === 'media_repeater') {
			echo '<button type="button" class="button jg-add-media-row">从媒体库添加图片</button>';
		} else {
			echo '<button type="button" class="button jg-add-repeater-row">新增一行</button>';
		}
		echo '</div>';
	}

	private static function render_repeater_row(string $type, string $name, int $index, array $row): void {
		echo '<div class="jg-repeater-row" draggable="true">';
		echo '<button type="button" class="button-link jg-drag-handle" aria-label="拖动排序" title="拖动排序">↕</button>';
		if ($type === 'specs_repeater') {
			self::repeater_input($name, $index, 'label', '参数名', $row['label'] ?? '');
			self::repeater_input($name, $index, 'value', '参数值', $row['value'] ?? '');
		} elseif ($type === 'links_repeater') {
			self::repeater_input($name, $index, 'name', '链接名称', $row['name'] ?? '');
			self::repeater_input($name, $index, 'url', 'https://', $row['url'] ?? '', 'url');
			echo '<select data-repeater-key="type" name="' . esc_attr($name . '[' . $index . '][type]') . '">';
			foreach (array('website' => '网站', 'certificate' => '证书', 'project' => '项目', 'other' => '其他') as $value => $label) {
				echo '<option value="' . esc_attr($value) . '" ' . selected((string) ($row['type'] ?? ''), $value, false) . '>' . esc_html($label) . '</option>';
			}
			echo '</select>';
		} elseif ($type === 'source_urls_repeater') {
			self::repeater_input($name, $index, 'label', 'Label', $row['label'] ?? '');
			self::repeater_input($name, $index, 'url', 'https://', $row['url'] ?? '', 'url');
		} else {
			$media_id = absint($row['mediaId'] ?? 0);
			echo '<input type="hidden" data-repeater-key="mediaId" name="' . esc_attr($name . '[' . $index . '][mediaId]') . '" value="' . esc_attr((string) $media_id) . '">';
			echo '<span class="jg-media-preview">' . ($media_id ? wp_get_attachment_image($media_id, array(72, 72), false, array('loading' => 'lazy')) : '') . '</span>';
			echo '<span class="jg-media-label">' . esc_html($media_id ? get_the_title($media_id) . ' (#' . $media_id . ')' : '无效媒体') . '</span>';
		}
		echo '<button type="button" class="button-link-delete jg-remove-repeater-row">删除</button>';
		echo '</div>';
	}

	private static function repeater_input(string $name, int $index, string $key, string $placeholder, $value, string $type = 'text'): void {
		echo '<input class="regular-text" type="' . esc_attr($type) . '" data-repeater-key="' . esc_attr($key) . '" name="' . esc_attr($name . '[' . $index . '][' . $key . ']') . '" value="' . esc_attr((string) $value) . '" placeholder="' . esc_attr($placeholder) . '">';
	}

	public static function save_fields(int $post_id, WP_Post $post): void {
		if (!isset($_POST[self::NONCE_NAME]) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE_NAME])), self::NONCE_ACTION)) {
			return;
		}
		if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id) || !current_user_can('edit_post', $post_id)) {
			return;
		}
		$definitions = self::field_definitions()[$post->post_type] ?? null;
		if (!$definitions) {
			return;
		}

		if (isset($_POST['jg_menu_order'])) {
			self::save_menu_order($post_id, $_POST['jg_menu_order']);
		}

		$submitted = isset($_POST['jg_fields']) && is_array($_POST['jg_fields']) ? wp_unslash($_POST['jg_fields']) : array();
		foreach ($definitions as $key => $field) {
			$value = $submitted[$key] ?? (str_ends_with($field['type'], '_repeater') ? array() : '');
			update_post_meta($post_id, '_jg_' . $key, self::sanitize_field($value, $field));
		}
	}

	private static function save_menu_order(int $post_id, $value): void {
		global $wpdb;
		$order = max(-100000, min(100000, (int) $value));
		$current = (int) get_post_field('menu_order', $post_id);
		if ($current === $order) {
			return;
		}
		$wpdb->update($wpdb->posts, array('menu_order' => $order), array('ID' => $post_id), array('%d'), array('%d'));
		clean_post_cache($post_id);
	}

	public static function validate_rest_save($prepared_post, WP_REST_Request $request) {
		$post_type = $prepared_post->post_type ?? $request->get_param('type');
		$slug_source = (string) ($prepared_post->post_name ?? $request->get_param('slug'));
		if ($slug_source === '') $slug_source = (string) ($prepared_post->post_title ?? $request->get_param('title'));
		$slug = sanitize_title($slug_source);
		$content = (string) ($prepared_post->post_content ?? '');
		$title = (string) ($prepared_post->post_title ?? '');
		$post_id = (int) ($prepared_post->ID ?? $request->get_param('id') ?? 0);
		$error = self::validation_error((string) $post_type, $slug, $title, $content, $post_id);
		return $error ?: $prepared_post;
	}

	public static function validate_classic_save(array $data, array $postarr): array {
		if (defined('REST_REQUEST') && REST_REQUEST) {
			return $data;
		}
		$post_type = (string) ($data['post_type'] ?? 'post');
		if (!in_array($post_type, self::public_post_types(), true) || $data['post_status'] === 'auto-draft') {
			return $data;
		}
		$slug = (string) ($data['post_name'] ?? '');
		if ($slug === '') $slug = sanitize_title((string) ($data['post_title'] ?? ''));
		$error = self::validation_error($post_type, $slug, (string) ($data['post_title'] ?? ''), (string) ($data['post_content'] ?? ''), (int) ($postarr['ID'] ?? 0));
		if ($error) {
			wp_die(esc_html($error->get_error_message()), '无法保存内容', array('response' => 409, 'back_link' => true));
		}
		return $data;
	}

	private static function validation_error(string $post_type, string $slug, string $title, string $content, int $post_id): ?WP_Error {
		if (!in_array($post_type, self::public_post_types(), true)) {
			return null;
		}
		if (strlen($title) > JG_Content_Policy::MAX_SHORT_TEXT_BYTES) {
			return new WP_Error('jg_title_too_large', '标题超过允许长度。', array('status' => 413));
		}
		if (strlen($content) > JG_Content_Policy::MAX_RICH_TEXT_BYTES) {
			return new WP_Error('jg_content_too_large', '正文超过 200 KB 限制。', array('status' => 413));
		}
		if ($slug !== '' && JG_Content_Policy::is_reserved_slug($slug)) {
			return new WP_Error('jg_reserved_slug', '该 slug 为 Astro 保留路由，请使用其他 slug。', array('status' => 409));
		}
		if ($slug !== '' && self::slug_exists($slug, $post_id)) {
			return new WP_Error('jg_duplicate_slug', '该 slug 已被其他公开内容使用。', array('status' => 409));
		}
		return null;
	}

	public static function slug_exists(string $slug, int $exclude_id = 0): bool {
		$query = new WP_Query(array(
			'post_type' => self::public_post_types(),
			'post_status' => array('publish', 'future', 'draft', 'pending', 'private'),
			'name' => sanitize_title($slug),
			'post__not_in' => $exclude_id > 0 ? array($exclude_id) : array(),
			'posts_per_page' => 1,
			'fields' => 'ids',
			'no_found_rows' => true,
		));
		return $query->have_posts();
	}

	public static function remove_view_action(array $actions, WP_Post $post): array {
		if (isset(self::definitions()[$post->post_type])) {
			unset($actions['view']);
		}
		return $actions;
	}

	public static function hide_sample_permalink(string $html, int $post_id): string {
		$post = get_post($post_id);
		return $post instanceof WP_Post && isset(self::definitions()[$post->post_type]) ? '' : $html;
	}

	public static function hide_preview_link(string $link, WP_Post $post): string {
		return isset(self::definitions()[$post->post_type]) ? '' : $link;
	}

	public static function enqueue_admin_assets(string $hook): void {
		$screen = get_current_screen();
		if (!$screen || (!isset(self::definitions()[$screen->post_type]) && $hook !== 'settings_page_jg-site-manager')) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_script('jquery-ui-sortable');
		wp_enqueue_script('jg-site-manager-admin', JG_SITE_MANAGER_URL . 'assets/admin.js', array('jquery', 'jquery-ui-sortable'), JG_SITE_MANAGER_VERSION, true);
		wp_enqueue_style('jg-site-manager-admin', JG_SITE_MANAGER_URL . 'assets/admin.css', array(), JG_SITE_MANAGER_VERSION);
	}

	public static function sanitize_field($value, array $field) {
		switch ($field['type']) {
			case 'checkbox': return !empty($value);
			case 'number':
				$number = (float) $value;
				if (isset($field['min'])) $number = max((float) $field['min'], $number);
				if (isset($field['max'])) $number = min((float) $field['max'], $number);
				return $number;
			case 'post_id': return max(0, absint($value));
			case 'url': return self::sanitize_http_url($value);
			case 'announcement_url': return self::sanitize_announcement_url($value);
			case 'date': return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value) ? (string) $value : '';
			case 'color': return sanitize_hex_color((string) $value) ?: '';
			case 'textarea': return self::limited_textarea($value, 10000);
			case 'select': return isset($field['options'][(string) $value]) ? (string) $value : (string) array_key_first($field['options']);
			case 'specs_repeater': return self::sanitize_specs($value);
			case 'links_repeater': return self::sanitize_links($value);
			case 'source_urls_repeater': return self::sanitize_source_urls($value);
			case 'media_repeater': return self::sanitize_media_rows($value);
			default: return self::limited_text($value, 500);
		}
	}

	private static function sanitize_http_url($value): string {
		$clean = esc_url_raw((string) $value, array('http', 'https'));
		return in_array(strtolower((string) wp_parse_url($clean, PHP_URL_SCHEME)), array('http', 'https'), true) ? $clean : '';
	}

	private static function sanitize_announcement_url($value): string {
		$raw = trim((string) $value);
		if ($raw === '' || preg_match('/[\\\\\r\n]/', $raw)) return '';
		$is_external = preg_match('/^https?:/i', $raw) === 1;
		$decoded = $raw;
		for ($index = 0; $index < 3; $index++) {
			$next = rawurldecode($decoded);
			if ($next === $decoded) break;
			$decoded = $next;
		}
		if (preg_match('/[\\\\\r\n]/', $decoded) || str_starts_with($decoded, '//')) return '';
		if ($is_external) return esc_url_raw($raw, array('http', 'https'));
		if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $decoded)) return '';
		if (!str_starts_with($raw, '/') || str_starts_with($raw, '//')) return '';
		$parts = wp_parse_url($raw);
		if (!is_array($parts) || isset($parts['scheme'], $parts['host'], $parts['user'], $parts['pass']) || !isset($parts['path']) || !str_starts_with($parts['path'], '/')) return '';
		return $raw;
	}

	private static function sanitize_specs($value): array {
		if (is_string($value)) {
			$value = trim($value) === '' ? array() : array(array('label' => '规格', 'value' => $value));
		}
		$rows = array();
		foreach (array_slice(is_array($value) ? $value : array(), 0, self::MAX_REPEATER_ROWS) as $row) {
			if (!is_array($row)) continue;
			$label = self::limited_text($row['label'] ?? '', 200);
			$item_value = self::limited_text($row['value'] ?? '', 500);
			if ($label !== '' || $item_value !== '') $rows[] = array('label' => $label, 'value' => $item_value);
		}
		return $rows;
	}

	private static function sanitize_links($value): array {
		if (is_string($value)) {
			$legacy = array();
			foreach (preg_split('/\R/', $value) as $line) {
				$parts = array_map('trim', explode('|', $line, 3));
				if (count($parts) === 3) $legacy[] = array('name' => $parts[0], 'url' => $parts[1], 'type' => $parts[2]);
			}
			$value = $legacy;
		}
		$allowed = array('website', 'certificate', 'project', 'other');
		$rows = array();
		foreach (array_slice(is_array($value) ? $value : array(), 0, self::MAX_REPEATER_ROWS) as $row) {
			if (!is_array($row)) continue;
			$name = self::limited_text($row['name'] ?? '', 300);
			$url = self::sanitize_http_url($row['url'] ?? '');
			$type = in_array((string) ($row['type'] ?? ''), $allowed, true) ? (string) $row['type'] : 'other';
			if ($name !== '' && $url !== '') $rows[] = array('name' => $name, 'url' => $url, 'type' => $type);
		}
		return $rows;
	}

	private static function sanitize_source_urls($value): array {
		$rows = array();
		foreach (array_slice(is_array($value) ? $value : array(), 0, self::MAX_REPEATER_ROWS) as $row) {
			if (!is_array($row)) continue;
			$label = self::limited_text($row['label'] ?? '', 300);
			$url = self::sanitize_http_url($row['url'] ?? '');
			if ($label !== '' && $url !== '') $rows[] = array('label' => $label, 'url' => $url);
		}
		return $rows;
	}

	private static function sanitize_media_rows($value): array {
		if (is_string($value)) {
			$value = array_map('absint', array_filter(explode(',', $value)));
		}
		$rows = array();
		$seen = array();
		foreach (array_slice(is_array($value) ? $value : array(), 0, self::MAX_REPEATER_ROWS) as $row) {
			$media_id = is_array($row) ? absint($row['mediaId'] ?? $row['media_id'] ?? $row['id'] ?? 0) : absint($row);
			if (!$media_id || isset($seen[$media_id])) continue;
			$seen[$media_id] = true;
			$rows[] = array('mediaId' => $media_id);
		}
		return $rows;
	}

	private static function limited_text($value, int $length): string {
		return mb_substr(sanitize_text_field((string) $value), 0, $length);
	}

	private static function limited_textarea($value, int $length): string {
		return mb_substr(sanitize_textarea_field((string) $value), 0, $length);
	}

	public static function is_deprecated(string $post_type): bool { return !empty(self::definitions()[$post_type]['deprecated']); }
	public static function public_post_types(): array {
		return array_merge(array('page'), array_values(array_filter(array_keys(self::definitions()), static fn($type) => !self::is_deprecated($type))));
	}
	private static function text(string $label): array { return array('label' => $label, 'type' => 'text'); }
	private static function textarea(string $label): array { return array('label' => $label, 'type' => 'textarea'); }
	private static function url(string $label): array { return array('label' => $label, 'type' => 'url'); }
	private static function announcement_url(string $label): array { return array('label' => $label, 'type' => 'announcement_url'); }
	private static function date(string $label): array { return array('label' => $label, 'type' => 'date'); }
	private static function color(string $label): array { return array('label' => $label, 'type' => 'color'); }
	private static function checkbox(string $label, bool $default = false): array { return array('label' => $label, 'type' => 'checkbox', 'default' => $default); }
	private static function select(string $label, array $options): array { return array('label' => $label, 'type' => 'select', 'options' => $options); }
	private static function number(string $label, float $min, float $max, string $step = '1'): array { return array('label' => $label, 'type' => 'number', 'min' => $min, 'max' => $max, 'step' => $step); }
	private static function specs_repeater(string $label): array { return array('label' => $label, 'type' => 'specs_repeater', 'schema' => array('type' => 'array', 'items' => array('type' => 'object', 'properties' => array('label' => array('type' => 'string'), 'value' => array('type' => 'string'))))); }
	private static function links_repeater(string $label): array { return array('label' => $label, 'type' => 'links_repeater', 'schema' => array('type' => 'array', 'items' => array('type' => 'object', 'properties' => array('name' => array('type' => 'string'), 'url' => array('type' => 'string', 'format' => 'uri'), 'type' => array('type' => 'string', 'enum' => array('website', 'certificate', 'project', 'other')))))); }
	private static function media_repeater(string $label): array { return array('label' => $label, 'type' => 'media_repeater', 'schema' => array('type' => 'array', 'items' => array('type' => 'object', 'properties' => array('mediaId' => array('type' => 'integer'))))); }
	private static function source_urls_repeater(string $label): array { return array('label' => $label, 'type' => 'source_urls_repeater', 'schema' => array('type' => 'array', 'items' => array('type' => 'object', 'properties' => array('label' => array('type' => 'string'), 'url' => array('type' => 'string', 'format' => 'uri'))))); }
	private static function post_id(string $label): array { return array('label' => $label, 'type' => 'post_id', 'min' => 0, 'max' => 2147483647); }
}
