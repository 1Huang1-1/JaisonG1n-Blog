<?php

if (!defined('ABSPATH')) {
	exit;
}
final class JG_Settings {
	public const OPTION = 'jg_site_settings';

	public static function init(): void {
		add_action('admin_menu', array(__CLASS__, 'add_page'));
		add_action('admin_init', array(__CLASS__, 'register_setting'));
	}

	public static function defaults(): array {
		$upload_host = strtolower((string) wp_parse_url(wp_upload_dir()['baseurl'] ?? '', PHP_URL_HOST));
		return array(
			'site_title' => get_bloginfo('name'),
			'site_subtitle' => get_bloginfo('description'),
			'site_start_date' => gmdate('Y-m-d'),
			'profile_name' => 'JaisonG1n',
			'profile_bio' => '',
			'avatar_id' => 0,
			'logo_id' => 0,
			'home_title' => 'JaisonG1n',
			'home_subtitles' => '',
			'banner_desktop_ids' => '',
			'banner_mobile_ids' => '',
			'carousel_interval' => 5,
			'theme_hue' => 260,
			'social_links' => '',
			'trusted_media_hosts' => $upload_host,
			'embed_hosts' => "www.youtube.com\nyoutu.be\nplayer.bilibili.com\nmusic.163.com",
			'music_enabled' => true,
			'music_mode' => 'meting',
			'music_server' => 'netease',
			'music_type' => 'playlist',
			'music_id' => '',
			'music_api' => '',
			'footer_html' => '',
			'comment_provider' => 'none',
			'comment_repository' => '',
			'comment_repo_id' => '',
			'comment_category' => '',
			'comment_category_id' => '',
			'pio_enabled' => false,
			'pio_model_url' => '',
			'pio_position' => 'right',
			'feature_pages' => array_fill_keys(array('radar', 'learning', 'diary', 'friends', 'projects', 'skills', 'timeline', 'albums', 'aiTools'), true),
			'cleanup_on_uninstall' => false,
		);
	}

	public static function install_defaults(): void {
		if (get_option(self::OPTION, null) === null) {
			add_option(self::OPTION, self::defaults(), '', false);
		}
	}

	public static function get(): array {
		$value = get_option(self::OPTION, array());
		return array_replace_recursive(self::defaults(), is_array($value) ? $value : array());
	}

	public static function register_setting(): void {
		register_setting('jg_site_manager', self::OPTION, array(
			'type' => 'object',
			'sanitize_callback' => array(__CLASS__, 'sanitize'),
			'default' => self::defaults(),
			'show_in_rest' => false,
		));
	}

	public static function sanitize($input): array {
		$input = is_array($input) ? $input : array();
		$defaults = self::defaults();
		$output = array();
		foreach (array('site_title', 'site_subtitle', 'profile_name', 'profile_bio', 'home_title', 'music_id', 'comment_repository', 'comment_repo_id', 'comment_category', 'comment_category_id') as $key) {
			$output[$key] = mb_substr(sanitize_text_field((string) ($input[$key] ?? $defaults[$key])), 0, 500);
		}
		$output['site_start_date'] = self::date_value($input['site_start_date'] ?? '') ?: $defaults['site_start_date'];
		$output['home_subtitles'] = self::limited_lines($input['home_subtitles'] ?? '', 20, 300);
		$output['social_links'] = self::sanitize_social_links($input['social_links'] ?? '');
		$output['trusted_media_hosts'] = implode("\n", JG_Content_Policy::sanitize_host_list($input['trusted_media_hosts'] ?? ''));
		$output['embed_hosts'] = implode("\n", JG_Content_Policy::sanitize_host_list($input['embed_hosts'] ?? ''));
		foreach (array('avatar_id', 'logo_id') as $key) {
			$output[$key] = absint($input[$key] ?? 0);
		}
		foreach (array('banner_desktop_ids', 'banner_mobile_ids') as $key) {
			$ids = array_filter(array_map('absint', explode(',', (string) ($input[$key] ?? ''))));
			$output[$key] = implode(',', array_slice(array_unique($ids), 0, 20));
		}
		$output['carousel_interval'] = min(60, max(2, absint($input['carousel_interval'] ?? 5)));
		$output['theme_hue'] = min(360, max(0, absint($input['theme_hue'] ?? 260)));
		$output['music_enabled'] = !empty($input['music_enabled']);
		$output['music_mode'] = self::enum($input['music_mode'] ?? '', array('local', 'meting'), 'meting');
		$output['music_server'] = self::enum($input['music_server'] ?? '', array('netease', 'tencent', 'kugou', 'baidu'), 'netease');
		$output['music_type'] = self::enum($input['music_type'] ?? '', array('playlist', 'song', 'album', 'artist'), 'playlist');
		$output['music_api'] = esc_url_raw((string) ($input['music_api'] ?? ''), array('https'));
		$output['footer_html'] = JG_Content_Policy::sanitize_public_html((string) ($input['footer_html'] ?? ''), array());
		$output['comment_provider'] = self::enum($input['comment_provider'] ?? '', array('none', 'giscus', 'twikoo'), 'none');
		$output['pio_enabled'] = !empty($input['pio_enabled']);
		$output['pio_model_url'] = esc_url_raw((string) ($input['pio_model_url'] ?? ''), array('https'));
		$output['pio_position'] = self::enum($input['pio_position'] ?? '', array('left', 'right'), 'right');
		$output['cleanup_on_uninstall'] = !empty($input['cleanup_on_uninstall']);
		$output['feature_pages'] = array();
		foreach (array_keys($defaults['feature_pages']) as $page) {
			$output['feature_pages'][$page] = !empty($input['feature_pages'][$page]);
		}
		return $output;
	}

	public static function public_settings(): array {
		$s = self::get();
		return array(
			'site' => array(
				'title' => $s['site_title'],
				'subtitle' => $s['site_subtitle'],
				'siteURL' => home_url('/'),
				'siteStartDate' => $s['site_start_date'],
				'lang' => 'zh_CN',
				'featurePages' => $s['feature_pages'],
			),
			'profile' => array(
				'name' => $s['profile_name'],
				'bio' => $s['profile_bio'],
				'avatar' => self::attachment_url($s['avatar_id']),
				'logo' => self::attachment_url($s['logo_id']),
				'links' => self::parse_social_links($s['social_links']),
			),
			'appearance' => array(
				'themeHue' => $s['theme_hue'],
				'homeTitle' => $s['home_title'],
				'homeSubtitles' => self::line_values($s['home_subtitles']),
				'banner' => array(
					'desktop' => self::attachment_urls($s['banner_desktop_ids']),
					'mobile' => self::attachment_urls($s['banner_mobile_ids']),
					'interval' => $s['carousel_interval'],
				),
			),
			'widgets' => array(
				'music' => array(
					'enable' => $s['music_enabled'],
					'mode' => $s['music_mode'],
					'server' => $s['music_server'],
					'type' => $s['music_type'],
					'id' => $s['music_id'],
					'api' => $s['music_api'],
				),
				'footerHtml' => $s['footer_html'],
				'comments' => array(
					'provider' => $s['comment_provider'],
					'repository' => $s['comment_repository'],
					'repoId' => $s['comment_repo_id'],
					'category' => $s['comment_category'],
					'categoryId' => $s['comment_category_id'],
				),
				'pio' => array('enable' => $s['pio_enabled'], 'modelUrl' => $s['pio_model_url'], 'position' => $s['pio_position']),
			),
			'security' => array(
				'trustedMediaHosts' => JG_Content_Policy::sanitize_host_list($s['trusted_media_hosts']),
				'embedHosts' => JG_Content_Policy::sanitize_host_list($s['embed_hosts']),
			),
		);
	}

	public static function add_page(): void {
		add_options_page('JaisonG1n 博客管理', '博客管理', 'manage_options', 'jg-site-manager', array(__CLASS__, 'render_page'));
	}

	public static function render_page(): void {
		if (!current_user_can('manage_options')) {
			wp_die('权限不足。');
		}
		$s = self::get();
		?>
		<div class="wrap">
			<h1>JaisonG1n 博客管理</h1>
			<p>这里只管理内容和允许公开的安全配置。页面结构、CSS、构建和秘密凭据不在 WordPress 中保存。</p>
			<form method="post" action="options.php">
				<?php settings_fields('jg_site_manager'); ?>
				<div class="jg-settings-grid">
					<?php self::render_basic_card($s); ?>
					<?php self::render_profile_card($s); ?>
					<?php self::render_appearance_card($s); ?>
					<?php self::render_integration_card($s); ?>
					<?php self::render_security_card($s); ?>
					<?php self::render_lifecycle_card($s); ?>
				</div>
				<?php submit_button('保存公开配置'); ?>
			</form>
			<hr>
			<h2>顶部导航</h2>
			<p>使用 WordPress 原生菜单管理器维护菜单，位置选择“JaisonG1n 顶部导航”。</p>
			<a class="button" href="<?php echo esc_url(admin_url('nav-menus.php')); ?>">打开菜单管理器</a>
			<?php JG_Dispatch::render_status_panel(); ?>
		</div>
		<?php
	}

	private static function render_basic_card(array $s): void {
		echo '<section class="jg-settings-card"><h2>站点与页面</h2>';
		self::input('site_title', '站点名称', $s['site_title']); self::input('site_subtitle', '站点副标题', $s['site_subtitle']); self::input('site_start_date', '建站日期', $s['site_start_date'], 'date');
		echo '<fieldset><legend><strong>启用页面</strong></legend>';
		foreach ($s['feature_pages'] as $key => $enabled) self::checkbox('feature_pages[' . $key . ']', $key, $enabled);
		echo '</fieldset></section>';
	}

	private static function render_profile_card(array $s): void {
		echo '<section class="jg-settings-card"><h2>个人资料</h2>';
		self::input('profile_name', '昵称', $s['profile_name']); self::input('profile_bio', '简介', $s['profile_bio']);
		self::media_input('avatar_id', '头像', $s['avatar_id']); self::media_input('logo_id', 'Logo', $s['logo_id']);
		self::textarea('social_links', '社交链接（每行：名称|Iconify 图标|URL）', $s['social_links']);
		echo '</section>';
	}

	private static function render_appearance_card(array $s): void {
		echo '<section class="jg-settings-card"><h2>首页与背景</h2>';
		self::input('home_title', '首页标题', $s['home_title']); self::textarea('home_subtitles', '首页副标题（每行一个）', $s['home_subtitles']);
		self::media_input('banner_desktop_ids', '桌面背景', $s['banner_desktop_ids'], true); self::media_input('banner_mobile_ids', '移动背景', $s['banner_mobile_ids'], true);
		self::input('carousel_interval', '轮播间隔（秒）', $s['carousel_interval'], 'number', 'min="2" max="60"'); self::input('theme_hue', '主题色相（0-360）', $s['theme_hue'], 'number', 'min="0" max="360"');
		echo '</section>';
	}

	private static function render_integration_card(array $s): void {
		echo '<section class="jg-settings-card"><h2>音乐、评论与看板娘</h2>';
		self::checkbox('music_enabled', '启用音乐', $s['music_enabled']); self::select('music_mode', '音乐模式', $s['music_mode'], array('meting' => 'Meting', 'local' => '本地'));
		self::select('music_server', '音乐平台', $s['music_server'], array('netease' => '网易云', 'tencent' => 'QQ 音乐', 'kugou' => '酷狗', 'baidu' => '百度')); self::input('music_id', '歌单或资源 ID', $s['music_id']); self::input('music_api', 'Meting API（仅 HTTPS）', $s['music_api'], 'url');
		self::select('comment_provider', '评论系统', $s['comment_provider'], array('none' => '关闭', 'giscus' => 'Giscus', 'twikoo' => 'Twikoo')); self::input('comment_repository', '公开仓库名', $s['comment_repository']); self::input('comment_repo_id', 'Repo ID', $s['comment_repo_id']); self::input('comment_category', '评论分类', $s['comment_category']); self::input('comment_category_id', 'Category ID', $s['comment_category_id']);
		self::checkbox('pio_enabled', '启用看板娘', $s['pio_enabled']); self::input('pio_model_url', '模型 URL（仅 HTTPS）', $s['pio_model_url'], 'url'); self::select('pio_position', '位置', $s['pio_position'], array('right' => '右侧', 'left' => '左侧'));
		self::textarea('footer_html', '页脚 HTML（会自动清理）', $s['footer_html']);
		echo '</section>';
	}

	private static function render_security_card(array $s): void {
		echo '<section class="jg-settings-card"><h2>内容安全</h2>';
		self::textarea('trusted_media_hosts', '可信 WordPress 媒体主机（每行一个）', $s['trusted_media_hosts']); self::textarea('embed_hosts', 'iframe/audio/video 白名单（每行一个）', $s['embed_hosts']);
		echo '<p>媒体同步阶段还会执行协议、IP、MIME、大小、数量和总容量限制。</p></section>';
	}

	private static function render_lifecycle_card(array $s): void {
		echo '<section class="jg-settings-card"><h2>插件数据生命周期</h2><p>停用和默认删除插件时均保留内容与设置。</p>';
		self::checkbox('cleanup_on_uninstall', '删除插件时永久清理全部插件内容和设置', $s['cleanup_on_uninstall']);
		echo '<p><strong>仅在确认不再使用插件时开启。</strong></p></section>';
	}

	private static function input(string $key, string $label, $value, string $type = 'text', string $extra = ''): void { echo '<label><span>' . esc_html($label) . '</span><input class="regular-text" type="' . esc_attr($type) . '" name="' . esc_attr(self::OPTION . '[' . $key . ']') . '" value="' . esc_attr((string) $value) . '" ' . $extra . '></label>'; }
	private static function textarea(string $key, string $label, $value): void { echo '<label><span>' . esc_html($label) . '</span><textarea class="large-text" rows="4" name="' . esc_attr(self::OPTION . '[' . $key . ']') . '">' . esc_textarea((string) $value) . '</textarea></label>'; }
	private static function checkbox(string $key, string $label, bool $value): void { echo '<label><input type="hidden" name="' . esc_attr(self::OPTION . '[' . $key . ']') . '" value="0"><span><input type="checkbox" name="' . esc_attr(self::OPTION . '[' . $key . ']') . '" value="1" ' . checked($value, true, false) . '> ' . esc_html($label) . '</span></label>'; }
	private static function select(string $key, string $label, $value, array $options): void { echo '<label><span>' . esc_html($label) . '</span><select name="' . esc_attr(self::OPTION . '[' . $key . ']') . '">'; foreach ($options as $option => $text) echo '<option value="' . esc_attr($option) . '" ' . selected((string) $value, (string) $option, false) . '>' . esc_html($text) . '</option>'; echo '</select></label>'; }
	private static function media_input(string $key, string $label, $value, bool $multiple = false): void { $id = 'jg-setting-' . $key; echo '<label><span>' . esc_html($label) . '</span><input type="hidden" id="' . esc_attr($id) . '" name="' . esc_attr(self::OPTION . '[' . $key . ']') . '" value="' . esc_attr((string) $value) . '"><span><button type="button" class="button jg-select-media" data-target="#' . esc_attr($id) . '" data-multiple="' . ($multiple ? '1' : '0') . '">选择图片</button> <button type="button" class="button-link-delete jg-clear-media" data-target="#' . esc_attr($id) . '">清空</button></span></label>'; }

	private static function date_value($value): string { return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value) ? (string) $value : ''; }
	private static function enum($value, array $allowed, string $fallback): string { return in_array((string) $value, $allowed, true) ? (string) $value : $fallback; }
	private static function limited_lines($value, int $max_lines, int $max_length): string { return implode("\n", array_slice(array_filter(array_map(static fn($line) => mb_substr(sanitize_text_field($line), 0, $max_length), preg_split('/\R/', (string) $value))), 0, $max_lines)); }
	private static function line_values(string $value): array { return array_values(array_filter(array_map('trim', preg_split('/\R/', $value)))); }
	private static function sanitize_social_links($value): string { $lines = array(); foreach (array_slice(preg_split('/\R/', (string) $value), 0, 30) as $line) { $parts = array_map('trim', explode('|', $line, 3)); if (count($parts) !== 3) continue; $url = esc_url_raw($parts[2], array('http', 'https')); if ($url !== '') $lines[] = sanitize_text_field($parts[0]) . '|' . sanitize_text_field($parts[1]) . '|' . $url; } return implode("\n", $lines); }
	private static function parse_social_links(string $value): array { $links = array(); foreach (preg_split('/\R/', $value) as $line) { $parts = array_map('trim', explode('|', $line, 3)); if (count($parts) === 3) $links[] = array('name' => $parts[0], 'icon' => $parts[1], 'url' => $parts[2]); } return $links; }
	private static function attachment_url($id): string { return $id ? (string) wp_get_attachment_url(absint($id)) : ''; }
	private static function attachment_urls(string $ids): array { return array_values(array_filter(array_map(array(__CLASS__, 'attachment_url'), array_filter(array_map('absint', explode(',', $ids)))))); }
}
