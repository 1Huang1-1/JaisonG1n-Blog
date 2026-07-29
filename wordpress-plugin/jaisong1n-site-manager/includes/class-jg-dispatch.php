<?php

if (!defined('ABSPATH')) {
	exit;
}

final class JG_Dispatch {
	public const CRON_HOOK = 'jg_dispatch_wordpress_content_changed';
	public const EVENT_TYPE = 'wordpress_content_changed';
	public const REPOSITORY = '1Huang1-1/JaisonG1n-Blog';
	private const STATUS_OPTION = 'jg_dispatch_status';
	private const REVISION_OPTION = 'jg_last_dispatched_revision';

	public static function init(): void {
		add_action('transition_post_status', array(__CLASS__, 'post_status_changed'), 10, 3);
		add_action('updated_option', array(__CLASS__, 'option_changed'), 10, 3);
		add_action('wp_update_nav_menu', array(__CLASS__, 'navigation_changed'));
		add_action('set_object_terms', array(__CLASS__, 'terms_changed'), 10, 6);
		add_action('edited_term', array(__CLASS__, 'taxonomy_changed'), 10, 3);
		add_action('delete_term', array(__CLASS__, 'taxonomy_changed'), 10, 3);
		add_action('profile_update', array(__CLASS__, 'profile_changed'), 10, 1);
		add_action('edit_attachment', array(__CLASS__, 'attachment_changed'));
		add_action('delete_attachment', array(__CLASS__, 'attachment_changed'));
		add_action('added_post_meta', array(__CLASS__, 'post_meta_changed'), 10, 4);
		add_action('updated_post_meta', array(__CLASS__, 'post_meta_changed'), 10, 4);
		add_action('deleted_post_meta', array(__CLASS__, 'post_meta_changed'), 10, 4);
		add_action(self::CRON_HOOK, array(__CLASS__, 'dispatch_if_changed'));
		add_action('admin_post_jg_manual_dispatch', array(__CLASS__, 'manual_dispatch'));
	}

	public static function post_status_changed(string $new_status, string $old_status, WP_Post $post): void {
		$types = array_merge(array('post', 'page'), array_keys(JG_Content_Types::definitions()));
		if (!in_array($post->post_type, $types, true) || ($new_status !== 'publish' && $old_status !== 'publish')) {
			return;
		}
		self::schedule();
	}

	public static function option_changed(string $option, $old_value, $value): void {
		if (in_array($option, array(JG_Settings::OPTION, 'sticky_posts'), true) && $old_value !== $value) {
			self::schedule();
		}
	}

	public static function navigation_changed(): void {
		self::schedule();
	}

	public static function terms_changed(int $object_id): void {
		$post = get_post($object_id);
		if ($post instanceof WP_Post && $post->post_status === 'publish') self::post_status_changed('publish', 'publish', $post);
	}

	public static function taxonomy_changed(int $term_id, int $term_taxonomy_id, string $taxonomy): void {
		if (in_array($taxonomy, array('category', 'post_tag'), true)) self::schedule();
	}

	public static function profile_changed(int $user_id): void {
		$published = get_posts(array(
			'post_type' => 'post',
			'post_status' => 'publish',
			'author' => $user_id,
			'posts_per_page' => 1,
			'fields' => 'ids',
		));
		if ($published) self::schedule();
	}

	public static function attachment_changed(): void {
		self::schedule();
	}

	public static function post_meta_changed($meta_id, int $post_id, string $meta_key): void {
		if ($meta_key !== '_thumbnail_id' && !str_starts_with($meta_key, '_jg_')) return;
		$post = get_post($post_id);
		if ($post instanceof WP_Post && $post->post_status === 'publish') self::post_status_changed('publish', 'publish', $post);
	}

	private static function schedule(): void {
		if (!wp_next_scheduled(self::CRON_HOOK)) {
			wp_schedule_single_event(time() + 45, self::CRON_HOOK);
		}
	}

	public static function dispatch_if_changed(): void {
		$revision = self::public_revision();
		if (is_wp_error($revision)) {
			self::store_status('error', '无法计算公开内容 revision：' . $revision->get_error_message());
			return;
		}
		if (hash_equals((string) get_option(self::REVISION_OPTION, ''), $revision)) {
			self::store_status('unchanged', '公开内容未发生有效变化，未触发构建。');
			return;
		}
		if (self::send($revision)) {
			update_option(self::REVISION_OPTION, $revision, false);
		}
	}

	public static function manual_dispatch(): void {
		if (!current_user_can('manage_options')) {
			wp_die('权限不足。', '', array('response' => 403));
		}
		check_admin_referer('jg_manual_dispatch');
		$revision = self::public_revision();
		if (is_wp_error($revision)) {
			self::store_status('error', $revision->get_error_message());
		} elseif (self::send($revision)) {
			update_option(self::REVISION_OPTION, $revision, false);
		}
		wp_safe_redirect(add_query_arg('page', 'jg-site-manager', admin_url('options-general.php')));
		exit;
	}

	private static function send(string $revision): bool {
		$token = self::token();
		if ($token === '') {
			self::store_status('not_configured', 'GitHub Token 未配置，未触发构建。');
			return false;
		}
		$response = wp_remote_post('https://api.github.com/repos/' . self::REPOSITORY . '/dispatches', array(
			'timeout' => 15,
			'redirection' => 0,
			'headers' => array(
				'Accept' => 'application/vnd.github+json',
				'Authorization' => 'Bearer ' . $token,
				'Content-Type' => 'application/json',
				'User-Agent' => 'JaisonG1n-Site-Manager/' . JG_SITE_MANAGER_VERSION,
				'X-GitHub-Api-Version' => '2022-11-28',
			),
			'body' => wp_json_encode(array(
				'event_type' => self::EVENT_TYPE,
				'client_payload' => array('revision' => $revision),
			)),
		));
		if (is_wp_error($response)) {
			self::store_status('error', 'GitHub 请求失败：' . $response->get_error_message());
			return false;
		}
		$code = wp_remote_retrieve_response_code($response);
		if ($code !== 204) {
			self::store_status('error', 'GitHub 返回 HTTP ' . absint($code) . '。');
			return false;
		}
		self::store_status('success', '已发送 wordpress_content_changed。');
		return true;
	}

	private static function public_revision() {
		$snapshot_revision = (new JG_Snapshot())->revision();
		if (is_wp_error($snapshot_revision)) return $snapshot_revision;
		$posts = get_posts(array(
			'post_type' => 'post',
			'post_status' => 'publish',
			'numberposts' => -1,
			'orderby' => 'ID',
			'order' => 'ASC',
		));
		$post_state = array_map(static function (WP_Post $post): array {
			$terms = wp_get_object_terms($post->ID, array('category', 'post_tag'));
			if (is_wp_error($terms)) $terms = array();
			$terms = array_map(static fn(WP_Term $term): array => array(
				'taxonomy' => $term->taxonomy,
				'name' => $term->name,
				'slug' => $term->slug,
				'termTaxonomyId' => $term->term_taxonomy_id,
			), $terms);
			return array(
			'id' => $post->ID,
			'slug' => $post->post_name,
			'title' => $post->post_title,
			'contentHash' => hash('sha256', $post->post_content),
			'excerptHash' => hash('sha256', $post->post_excerpt),
				'terms' => $terms,
				'author' => get_the_author_meta('display_name', $post->post_author),
				'featuredImageId' => get_post_thumbnail_id($post->ID),
				'featuredImageUrl' => get_the_post_thumbnail_url($post->ID, 'full') ?: '',
			'sticky' => is_sticky($post->ID),
			'commentStatus' => $post->comment_status,
			'status' => $post->post_status,
			);
		}, $posts);
		return hash('sha256', $snapshot_revision . '|' . wp_json_encode($post_state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
	}

	private static function token(): string {
		if (defined('JG_GITHUB_TOKEN')) {
			return trim((string) constant('JG_GITHUB_TOKEN'));
		}
		$value = getenv('JG_GITHUB_TOKEN');
		return $value === false ? '' : trim((string) $value);
	}

	private static function store_status(string $state, string $message): void {
		update_option(self::STATUS_OPTION, array(
			'state' => sanitize_key($state),
			'message' => sanitize_text_field($message),
			'time' => gmdate('c'),
		), false);
	}

	public static function render_status_panel(): void {
		if (!current_user_can('manage_options')) return;
		$status = get_option(self::STATUS_OPTION, array());
		?>
		<hr>
		<h2>GitHub 即时构建</h2>
		<table class="widefat striped" style="max-width:760px"><tbody>
			<tr><th>目标仓库</th><td><code><?php echo esc_html(self::REPOSITORY); ?></code></td></tr>
			<tr><th>Token</th><td><?php echo self::token() !== '' ? '已配置' : '未配置'; ?></td></tr>
			<tr><th>自动防抖</th><td>45 秒</td></tr>
			<tr><th>最近状态</th><td><?php echo esc_html((string) ($status['message'] ?? '暂无记录')); ?><?php if (!empty($status['time'])) echo '（' . esc_html($status['time']) . '）'; ?></td></tr>
		</tbody></table>
		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:12px">
			<input type="hidden" name="action" value="jg_manual_dispatch">
			<?php wp_nonce_field('jg_manual_dispatch'); ?>
			<?php submit_button('手动触发构建', 'secondary', 'submit', false); ?>
		</form>
		<p>Token 只从 <code>wp-config.php</code> 的 <code>JG_GITHUB_TOKEN</code> 常量或同名服务器环境变量读取，不写入数据库。</p>
		<?php
	}
}
