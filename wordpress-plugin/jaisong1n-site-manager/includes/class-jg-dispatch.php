<?php

if (!defined('ABSPATH')) {
	exit;
}

final class JG_Dispatch {
	public const CRON_HOOK = 'jg_dispatch_wordpress_content_changed';
	public const EVENT_TYPE = 'wordpress_content_changed';
	public const REPOSITORY = '1Huang1-1/JaisonG1n-Blog';
	public const GITHUB_API_VERSION = '2026-03-10';
	private const CONFIG_OPTION = 'jg_dispatch_config';
	private const TOKEN_OPTION = 'jg_github_token';
	private const STATUS_OPTION = 'jg_dispatch_status';
	private const HISTORY_OPTION = 'jg_dispatch_history';
	private const REVISION_OPTION = 'jg_last_dispatched_revision';
	private const PENDING_OPTION = 'jg_dispatch_pending';
	private const LOCK_OPTION = 'jg_dispatch_lock';
	private const MAX_HTTP_ATTEMPTS = 3;
	private const RETRY_DELAYS = array(60, 300, 900);
	private const MAX_RECORDS = 50;
	private const RUN_CACHE_TTL = 20;
	private const RUN_CACHE_PREFIX = 'jg_dispatch_run_';

	public static function init(): void {
		self::install_defaults();
		add_action('transition_post_status', array(__CLASS__, 'post_status_changed'), 10, 3);
		add_action('save_post', array(__CLASS__, 'post_saved'), 110, 3);
		add_action('updated_option', array(__CLASS__, 'option_changed'), 10, 3);
		add_action('wp_update_nav_menu', array(__CLASS__, 'navigation_changed'));
		add_action('set_object_terms', array(__CLASS__, 'terms_changed'), 10, 6);
		add_action('edited_term', array(__CLASS__, 'taxonomy_changed'), 10, 3);
		add_action('delete_term', array(__CLASS__, 'taxonomy_changed'), 10, 3);
		add_action('profile_update', array(__CLASS__, 'profile_changed'), 10, 1);
		add_action('edit_attachment', array(__CLASS__, 'attachment_changed'), 10, 1);
		add_action('delete_attachment', array(__CLASS__, 'attachment_deleted'), 10, 1);
		add_action('added_post_meta', array(__CLASS__, 'post_meta_changed'), 10, 4);
		add_action('updated_post_meta', array(__CLASS__, 'post_meta_changed'), 10, 4);
		add_action('deleted_post_meta', array(__CLASS__, 'post_meta_changed'), 10, 4);
		add_action(self::CRON_HOOK, array(__CLASS__, 'dispatch_if_changed'));
		add_action('admin_post_jg_manual_dispatch', array(__CLASS__, 'manual_dispatch'));
		add_action('admin_post_jg_save_dispatch_settings', array(__CLASS__, 'save_settings'));
	}

	public static function install_defaults(): void {
		self::ensure_private_option(self::CONFIG_OPTION, self::config_defaults());
		self::ensure_private_option(self::STATUS_OPTION, array());
		self::ensure_private_option(self::HISTORY_OPTION, array());
		self::ensure_private_option(self::REVISION_OPTION, '');
		self::ensure_private_option(self::PENDING_OPTION, array());
		if (get_option(self::LOCK_OPTION, null) === '') delete_option(self::LOCK_OPTION);
		self::fix_autoload(self::TOKEN_OPTION);
	}

	public static function activate(): void {
		self::install_defaults();
		$pending = get_option(self::PENDING_OPTION, array());
		if (self::auto_enabled() && is_array($pending) && !empty($pending) && !wp_next_scheduled(self::CRON_HOOK)) {
			wp_schedule_single_event(time() + self::debounce(), self::CRON_HOOK);
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook(self::CRON_HOOK);
		delete_option(self::LOCK_OPTION);
	}

	public static function supported_post_types(): array {
		return array_merge(
			array('post', 'page'),
			array_values(array_filter(array_keys(JG_Content_Types::definitions()), static fn($type) => !JG_Content_Types::is_deprecated($type)))
		);
	}

	public static function post_status_changed(string $new_status, string $old_status, WP_Post $post): void {
		if (!in_array($post->post_type, self::supported_post_types(), true) || ($new_status !== 'publish' && $old_status !== 'publish')) return;
		self::schedule('content', 'status', $post);
	}

	public static function post_saved(int $post_id, WP_Post $post, bool $update = false): void {
		if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id) || $post->post_status !== 'publish') return;
		if (in_array($post->post_type, self::supported_post_types(), true)) self::schedule('content', $update ? 'updated' : 'created', $post);
	}

	public static function option_changed(string $option, $old_value, $value): void {
		if ($option === JG_Settings::OPTION && self::normalized_hash($old_value) !== self::normalized_hash($value)) {
			self::schedule('settings', 'updated');
		}
		if ($option === 'sticky_posts' && self::normalized_hash($old_value) !== self::normalized_hash($value)) {
			self::schedule('content', 'sticky');
		}
	}

	public static function navigation_changed(): void { self::schedule('navigation', 'updated'); }

	public static function terms_changed(int $object_id): void {
		$post = get_post($object_id);
		if ($post instanceof WP_Post && $post->post_status === 'publish' && in_array($post->post_type, self::supported_post_types(), true)) self::schedule('taxonomy', 'updated', $post);
	}

	public static function taxonomy_changed(int $term_id, int $term_taxonomy_id, string $taxonomy): void {
		if (in_array($taxonomy, array('category', 'post_tag'), true)) self::schedule('taxonomy', 'updated');
	}

	public static function profile_changed(int $user_id): void {
		$published = get_posts(array('post_type' => 'post', 'post_status' => 'publish', 'author' => $user_id, 'posts_per_page' => 1, 'fields' => 'ids'));
		if ($published) self::schedule('author', 'updated');
	}

	public static function attachment_changed(int $attachment_id = 0): void {
		if ($attachment_id > 0 && JG_Media_Index::has_public_reference($attachment_id)) self::schedule('media', 'updated');
	}

	public static function attachment_deleted(int $attachment_id = 0): void {
		self::attachment_changed($attachment_id);
		if ($attachment_id > 0) JG_Media_Index::remove_attachment($attachment_id);
	}

	public static function post_meta_changed($meta_id, int $post_id, string $meta_key): void {
		if ($meta_key !== '_thumbnail_id' && !str_starts_with($meta_key, '_jg_')) return;
		$post = get_post($post_id);
		if ($post instanceof WP_Post && $post->post_status === 'publish' && in_array($post->post_type, self::supported_post_types(), true)) self::schedule('media', 'updated', $post);
	}

	private static function schedule(string $change_type, string $change_action, ?WP_Post $post = null): void {
		$pending = get_option(self::PENDING_OPTION, array());
		if (!is_array($pending)) $pending = array();
		$pending['types'] = array_values(array_unique(array_merge((array) ($pending['types'] ?? array()), array(sanitize_key($change_type)))));
		$pending['actions'] = array_values(array_unique(array_merge((array) ($pending['actions'] ?? array()), array(sanitize_key($change_action)))));
		$pending['first_seen'] = $pending['first_seen'] ?? gmdate('c');
		$pending['attempts'] = absint($pending['attempts'] ?? 0);
		$ref = $post instanceof WP_Post ? self::content_ref($post) : null;
		if ($ref) {
			$refs = (array) ($pending['contentRefs'] ?? array());
			$refs[$ref['contentType'] . ':' . $ref['contentId']] = $ref;
			$pending['contentRefs'] = array_values($refs);
		}
		$config = self::config();
		$pending['triggerId'] = sanitize_key((string) ($pending['triggerId'] ?? wp_generate_uuid4()));
		$pending['triggeredAt'] = (string) ($pending['triggeredAt'] ?? gmdate('c'));
		$pending['source'] = sanitize_key((string) ($pending['source'] ?? 'wordpress'));
		$pending['workflowId'] = sanitize_file_name((string) ($pending['workflowId'] ?? $config['workflow']));
		$pending['ref'] = sanitize_text_field((string) ($pending['ref'] ?? $config['ref']));
		update_option(self::PENDING_OPTION, $pending, false);
		if (self::auto_enabled() && !wp_next_scheduled(self::CRON_HOOK)) wp_schedule_single_event(time() + self::debounce(), self::CRON_HOOK);
	}

	public static function dispatch_if_changed(): void {
		if (!self::acquire_lock()) return;
		try {
			$pending = get_option(self::PENDING_OPTION, array());
			if (!is_array($pending) || empty($pending)) return;
			$revision = self::public_revision();
			if (is_wp_error($revision)) {
				self::failed($pending, $revision);
				return;
			}
			if (hash_equals((string) get_option(self::REVISION_OPTION, ''), $revision)) {
				delete_option(self::PENDING_OPTION);
				self::record_dispatch($pending, 'unchanged', array('message' => 'Public revision is unchanged; no workflow was dispatched.'));
				return;
			}
			$result = self::send($revision, $pending, false);
			if (is_wp_error($result)) {
				self::failed($pending, $result);
				return;
			}
			update_option(self::REVISION_OPTION, $revision, false);
			delete_option(self::PENDING_OPTION);
			self::record_dispatch($pending, 'accepted', array_merge(array('message' => 'Workflow dispatched.'), $result));
		} finally {
			delete_option(self::LOCK_OPTION);
		}
	}

	public static function manual_dispatch(): void {
		if (!current_user_can('manage_options')) wp_die('Insufficient permissions.', '', array('response' => 403));
		check_admin_referer('jg_manual_dispatch');
		$revision = self::public_revision();
		if (is_wp_error($revision)) {
			self::record_dispatch(array('source' => 'manual'), 'failed', array('message' => $revision->get_error_message(), 'error_code' => $revision->get_error_code(), 'error_summary' => $revision->get_error_message()));
		} elseif (!self::acquire_lock()) {
			self::record_dispatch(array('source' => 'manual'), 'busy', array('message' => 'Another dispatch is already running.'));
		} else {
			try {
				$result = self::send($revision, array('types' => array('manual'), 'actions' => array('force')), true);
				if (is_wp_error($result)) self::failed(get_option(self::PENDING_OPTION, array()), $result);
				else {
					update_option(self::REVISION_OPTION, $revision, false);
					delete_option(self::PENDING_OPTION);
					self::record_dispatch(array('source' => 'manual'), 'accepted', array_merge(array('message' => 'Forced workflow dispatch completed.'), $result));
				}
			} finally {
				delete_option(self::LOCK_OPTION);
			}
		}
		wp_safe_redirect(add_query_arg('page', 'jg-site-manager', admin_url('options-general.php')));
		exit;
	}

	public static function save_settings(): void {
		if (!current_user_can('manage_options')) wp_die('Insufficient permissions.', '', array('response' => 403));
		check_admin_referer('jg_dispatch_settings');
		$input = isset($_POST['jg_dispatch']) && is_array($_POST['jg_dispatch']) ? wp_unslash($_POST['jg_dispatch']) : array();
		$config = self::sanitize_config($input);
		update_option(self::CONFIG_OPTION, $config, false);
		$token = trim((string) ($input['token'] ?? ''));
		if (!empty($input['clear_token'])) delete_option(self::TOKEN_OPTION);
		elseif ($token !== '') update_option(self::TOKEN_OPTION, $token, false);
		self::fix_autoload(self::TOKEN_OPTION);
		if ($config['auto_enabled'] && get_option(self::PENDING_OPTION, array()) && !wp_next_scheduled(self::CRON_HOOK)) wp_schedule_single_event(time() + self::debounce(), self::CRON_HOOK);
		wp_safe_redirect(add_query_arg('page', 'jg-site-manager', admin_url('options-general.php')));
		exit;
	}

	private static function send(string $revision, array $pending, bool $force) {
		$token = self::token();
		if ($token === '') return new WP_Error('jg_dispatch_not_configured', 'GitHub token is not configured.');
		$config = self::config();
		$endpoint = 'https://api.github.com/repos/' . rawurlencode($config['owner']) . '/' . rawurlencode($config['repository']) . '/actions/workflows/' . rawurlencode($config['workflow']) . '/dispatches';
		$body = array('ref' => $config['ref'], 'inputs' => array(
			'trigger_source' => $force ? 'wordpress-manual' : 'wordpress',
			'change_types' => implode(',', array_map('sanitize_key', (array) ($pending['types'] ?? array('content')))),
			'change_actions' => implode(',', array_map('sanitize_key', (array) ($pending['actions'] ?? array('updated')))),
			'requested_at' => gmdate('c'),
			'force' => $force ? 'true' : 'false',
		));
		for ($attempt = 1; $attempt <= self::MAX_HTTP_ATTEMPTS; $attempt++) {
			$response = wp_remote_post($endpoint, array(
				'timeout' => 15,
				'redirection' => 0,
				'headers' => array(
					'Accept' => 'application/vnd.github+json',
					'Authorization' => 'Bearer ' . $token,
					'Content-Type' => 'application/json',
					'User-Agent' => 'JaisonG1n-Site-Manager/' . JG_SITE_MANAGER_VERSION,
					'X-GitHub-Api-Version' => self::GITHUB_API_VERSION,
				),
				'body' => wp_json_encode($body),
			));
			if (is_wp_error($response)) {
				if ($attempt < self::MAX_HTTP_ATTEMPTS) continue;
				return new WP_Error('jg_dispatch_http', 'GitHub request failed: ' . sanitize_text_field($response->get_error_message()));
			}
			$code = (int) wp_remote_retrieve_response_code($response);
			if ($code === 204) return array('status' => 204);
			if ($code === 200) {
				$decoded = json_decode((string) wp_remote_retrieve_body($response), true);
				if (!is_array($decoded)) return new WP_Error('jg_dispatch_response', 'GitHub returned an invalid 200 response.');
				return array(
					'status' => 200,
					'workflow_run_id' => absint($decoded['workflow_run_id'] ?? $decoded['id'] ?? 0) ?: null,
					'run_url' => self::safe_github_url($decoded['run_url'] ?? ''),
					'html_url' => self::safe_github_url($decoded['html_url'] ?? ''),
				);
			}
			if (in_array($code, array(429, 500, 502, 503, 504), true) && $attempt < self::MAX_HTTP_ATTEMPTS) continue;
			return new WP_Error('jg_dispatch_http', 'GitHub returned HTTP ' . $code . '.');
		}
		return new WP_Error('jg_dispatch_http', 'GitHub request failed.');
	}

	private static function failed(array $pending, WP_Error $error): void {
		$attempts = absint($pending['attempts'] ?? 0) + 1;
		$pending['attempts'] = $attempts;
		update_option(self::PENDING_OPTION, $pending, false);
		self::record_dispatch($pending, 'failed', array('message' => $error->get_error_message(), 'error_code' => $error->get_error_code(), 'error_summary' => $error->get_error_message()));
		if (self::auto_enabled() && $attempts <= count(self::RETRY_DELAYS)) wp_schedule_single_event(time() + self::RETRY_DELAYS[$attempts - 1], self::CRON_HOOK);
	}

	private static function public_revision() {
		$snapshot_revision = (new JG_Snapshot())->revision();
		if (is_wp_error($snapshot_revision)) return $snapshot_revision;
		$posts = get_posts(array('post_type' => 'post', 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'ID', 'order' => 'ASC'));
		$post_state = array_map(static function (WP_Post $post): array {
			$terms = wp_get_object_terms($post->ID, array('category', 'post_tag'));
			if (is_wp_error($terms)) $terms = array();
			$terms = array_map(static fn(WP_Term $term): array => array('taxonomy' => $term->taxonomy, 'name' => $term->name, 'slug' => $term->slug, 'termTaxonomyId' => $term->term_taxonomy_id), $terms);
			return array('id' => $post->ID, 'slug' => $post->post_name, 'title' => $post->post_title, 'contentHash' => hash('sha256', $post->post_content), 'excerptHash' => hash('sha256', $post->post_excerpt), 'terms' => $terms, 'author' => get_the_author_meta('display_name', $post->post_author), 'featuredImageId' => get_post_thumbnail_id($post->ID), 'featuredImageUrl' => get_the_post_thumbnail_url($post->ID, 'full') ?: '', 'sticky' => is_sticky($post->ID), 'commentStatus' => $post->comment_status, 'status' => $post->post_status);
		}, $posts);
		return hash('sha256', $snapshot_revision . '|' . wp_json_encode($post_state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
	}

	private static function token(): string {
	foreach (array('JAISONG1N_GITHUB_TOKEN', 'JG_GITHUB_TOKEN') as $name) {
		if (defined($name)) {
			$value = trim((string) constant($name));
			if ($value !== '') return $value;
		}
		$value = getenv($name);
		if ($value !== false && trim((string) $value) !== '') return trim((string) $value);
	}
	return trim((string) get_option(self::TOKEN_OPTION, ''));
	}

	private static function config_defaults(): array { return array('auto_enabled' => true, 'owner' => '1Huang1-1', 'repository' => 'JaisonG1n-Blog', 'workflow' => 'build-deploy.yml', 'ref' => 'master', 'debounce' => 45); }
	private static function config(): array { return array_replace(self::config_defaults(), is_array(get_option(self::CONFIG_OPTION, array())) ? get_option(self::CONFIG_OPTION, array()) : array()); }
	private static function sanitize_config(array $input): array {
		$defaults = self::config_defaults();
		$owner = preg_replace('/[^A-Za-z0-9_.-]/', '', (string) ($input['owner'] ?? $defaults['owner']));
		$repository = preg_replace('/[^A-Za-z0-9_.-]/', '', (string) ($input['repository'] ?? $defaults['repository']));
		$workflow = preg_replace('/[^A-Za-z0-9_.-]/', '', basename((string) ($input['workflow'] ?? $defaults['workflow'])));
		$ref = preg_replace('/[^A-Za-z0-9_./-]/', '', (string) ($input['ref'] ?? $defaults['ref']));
		return array('auto_enabled' => !empty($input['auto_enabled']), 'owner' => $owner ?: $defaults['owner'], 'repository' => $repository ?: $defaults['repository'], 'workflow' => $workflow ?: $defaults['workflow'], 'ref' => $ref ?: $defaults['ref'], 'debounce' => min(60, max(30, absint($input['debounce'] ?? $defaults['debounce']))));
	}
	private static function auto_enabled(): bool { return !empty(self::config()['auto_enabled']); }
	private static function debounce(): int { return absint(self::config()['debounce']); }
	private static function normalized_hash($value): string { return hash('sha256', wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); }
	private static function safe_github_url($value): ?string {
		$url = esc_url_raw((string) $value, array('https'));
		return preg_match('#^https://(?:github\\.com|api\\.github\\.com)/[^\\s]+$#', $url) ? $url : null;
	}
	private static function acquire_lock(): bool {
		$current = get_option(self::LOCK_OPTION, '');
		if ($current !== '') {
			if (is_numeric($current) && (time() - (int) $current) > 900) delete_option(self::LOCK_OPTION);
			else return false;
		}
		return add_option(self::LOCK_OPTION, (string) time(), '', false);
	}
	private static function ensure_private_option(string $name, $default): void { if (get_option($name, null) === null) add_option($name, $default, '', false); self::fix_autoload($name); }
	private static function fix_autoload(string $name): void { global $wpdb; $wpdb->update($wpdb->options, array('autoload' => 'no'), array('option_name' => $name), array('%s'), array('%s')); }
	public static function find_latest_record_for_content(string $content_type, int $content_id, string $modified_at_gmt = ''): ?array {
		$history = get_option(self::HISTORY_OPTION, array());
		if (!is_array($history)) return null;
		$modified_ts = $modified_at_gmt !== '' ? strtotime($modified_at_gmt) : 0;
		foreach ($history as $record) {
			if (!is_array($record)) continue;
			$found = false;
			foreach ((array) ($record['contentRefs'] ?? array()) as $ref) {
				if (is_array($ref) && ($ref['contentType'] ?? '') === $content_type && (int) ($ref['contentId'] ?? 0) === $content_id) {
					$found = true;
					break;
				}
			}
			if (!$found) continue;
			// Coverage time: prefer the actual dispatch time. Legacy records
			// predating dispatchedAt store lastCheckedAt at record creation,
			// which equals the dispatch attempt time and stays trusted.
			$coverage_ts = isset($record['dispatchedAt']) && is_string($record['dispatchedAt']) ? strtotime($record['dispatchedAt']) : 0;
			if ($coverage_ts <= 0 && isset($record['lastCheckedAt']) && is_string($record['lastCheckedAt'])) $coverage_ts = strtotime($record['lastCheckedAt']);
			if ($coverage_ts <= 0 && isset($record['triggeredAt']) && is_string($record['triggeredAt'])) $coverage_ts = strtotime($record['triggeredAt']);
			if ($modified_ts > 0 && $coverage_ts > 0 && $coverage_ts < $modified_ts) continue;
			return $record;
		}
		return null;
	}

	public static function query_run(int $workflow_run_id, array $record = array()): array {
		$cache_key = self::RUN_CACHE_PREFIX . $workflow_run_id;
		$cached = get_transient($cache_key);
		if (is_array($cached)) return $cached;

		$now = gmdate('c');
		$fallback = array(
			'workflowRunId' => $workflow_run_id,
			'buildStatus' => isset($record['buildStatus']) && $record['buildStatus'] !== '' ? $record['buildStatus'] : 'unknown',
			'buildConclusion' => $record['buildConclusion'] ?? null,
			'startedAt' => $record['startedAt'] ?? null,
			'completedAt' => $record['completedAt'] ?? null,
			'lastCheckedAt' => $now,
			'errorCode' => null,
			'errorSummary' => null,
		);

		$config = self::config();
		$token = self::token();
		$endpoint = 'https://api.github.com/repos/' . rawurlencode($config['owner']) . '/' . rawurlencode($config['repository']) . '/actions/runs/' . $workflow_run_id;
		$response = wp_remote_get($endpoint, array(
			'timeout' => 10,
			'redirection' => 0,
			'headers' => array(
				'Accept' => 'application/vnd.github+json',
				'Authorization' => $token !== '' ? 'Bearer ' . $token : '',
				'User-Agent' => 'JaisonG1n-Site-Manager/' . JG_SITE_MANAGER_VERSION,
				'X-GitHub-Api-Version' => self::GITHUB_API_VERSION,
			),
		));

		if (is_wp_error($response)) {
			$fallback['errorCode'] = 'jg_dispatch_run_network';
			$fallback['errorSummary'] = mb_substr(sanitize_text_field($response->get_error_message()), 0, 200);
			return $fallback;
		}
		$code = (int) wp_remote_retrieve_response_code($response);
		if ($code !== 200) {
			$fallback['errorCode'] = 'jg_dispatch_run_http_' . $code;
			$fallback['errorSummary'] = 'GitHub run query returned HTTP ' . $code . '.';
			return $fallback;
		}
		$decoded = json_decode((string) wp_remote_retrieve_body($response), true);
		if (!is_array($decoded)) {
			$fallback['errorCode'] = 'jg_dispatch_run_invalid_response';
			$fallback['errorSummary'] = 'GitHub returned an invalid run response.';
			return $fallback;
		}
		$status = sanitize_key((string) ($decoded['status'] ?? ''));
		$conclusion = isset($decoded['conclusion']) && $decoded['conclusion'] !== null && $decoded['conclusion'] !== '' ? sanitize_key((string) $decoded['conclusion']) : null;
		$result = array(
			'workflowRunId' => $workflow_run_id,
			'buildStatus' => self::map_run_status($status, $conclusion),
			'buildConclusion' => $conclusion,
			'startedAt' => self::iso_time((string) ($decoded['started_at'] ?? '')),
			'completedAt' => self::iso_time((string) ($decoded['completed_at'] ?? '')),
			'lastCheckedAt' => $now,
			'errorCode' => null,
			'errorSummary' => null,
		);
		set_transient($cache_key, $result, self::RUN_CACHE_TTL);
		return $result;
	}

	private static function map_run_status(string $status, ?string $conclusion): string {
		if ($status === 'queued') return 'queued';
		if ($status === 'in_progress') return 'in_progress';
		if ($status === 'completed') {
			if ($conclusion === 'success') return 'success';
			if ($conclusion === 'failure') return 'failed';
			if ($conclusion === 'cancelled') return 'cancelled';
			if ($conclusion === 'timed_out') return 'failed';
			return 'unknown';
		}
		return 'unknown';
	}

	private static function content_ref(WP_Post $post): ?array {
		$api_type = JG_AI_Content::api_type_for_post_type($post->post_type);
		if ($api_type === null) return null;
		$modified = self::iso_time(trim((string) $post->post_modified_gmt));
		return array('contentType' => $api_type, 'contentId' => (int) $post->ID, 'modifiedAt' => $modified ?? gmdate('c'));
	}

	private static function iso_time(string $value): ?string {
		if ($value === '' || $value === '0000-00-00 00:00:00') return null;
		$timestamp = strtotime($value . (str_contains($value, 'T') || str_contains($value, '+') || str_contains($value, 'Z') ? '' : ' UTC'));
		return $timestamp === false ? null : gmdate('c', $timestamp);
	}

	private static function record_dispatch(array $pending, string $dispatch_status, array $meta = array()): void {
		$config = self::config();
		$record = array(
			'triggerId' => sanitize_key((string) ($pending['triggerId'] ?? wp_generate_uuid4())),
			'source' => sanitize_key((string) ($pending['source'] ?? 'wordpress')),
			'contentRefs' => array_values(array_filter((array) ($pending['contentRefs'] ?? array()), static fn($ref) => is_array($ref))),
			'workflowId' => sanitize_file_name((string) ($pending['workflowId'] ?? $config['workflow'])),
			'workflowRunId' => isset($meta['workflow_run_id']) && is_numeric($meta['workflow_run_id']) && (int) $meta['workflow_run_id'] > 0 ? absint($meta['workflow_run_id']) : null,
			'runUrl' => isset($meta['run_url']) ? $meta['run_url'] : null,
			'runHtmlUrl' => isset($meta['html_url']) ? $meta['html_url'] : null,
			'ref' => sanitize_text_field((string) ($pending['ref'] ?? $config['ref'])),
			'dispatchStatus' => sanitize_key($dispatch_status),
			'buildStatus' => in_array($dispatch_status, array('failed', 'not_configured', 'unchanged', 'busy'), true) ? 'not_triggered' : 'pending',
			'buildConclusion' => null,
			'deploymentStatus' => 'unknown',
			'triggeredAt' => isset($pending['triggeredAt']) && is_string($pending['triggeredAt']) ? $pending['triggeredAt'] : gmdate('c'),
			'dispatchedAt' => gmdate('c'),
			'startedAt' => null,
			'completedAt' => null,
			'lastCheckedAt' => gmdate('c'),
			'errorCode' => isset($meta['error_code']) ? sanitize_key((string) $meta['error_code']) : null,
			'errorSummary' => isset($meta['error_summary']) ? mb_substr(sanitize_text_field((string) $meta['error_summary']), 0, 200) : null,
		);

		$status = array(
			'state' => self::legacy_state($dispatch_status),
			'message' => sanitize_text_field((string) ($meta['message'] ?? '')),
			'time' => $record['triggeredAt'],
			'record' => $record,
		);
		foreach (array('status', 'workflow_run_id', 'run_url', 'html_url') as $key) {
			if (array_key_exists($key, $meta)) $status[$key] = $meta[$key];
		}
		update_option(self::STATUS_OPTION, $status, false);

		$history = get_option(self::HISTORY_OPTION, array());
		if (!is_array($history)) $history = array();
		array_unshift($history, $record);
		update_option(self::HISTORY_OPTION, array_slice(array_values($history), 0, self::MAX_RECORDS), false);
	}

	private static function legacy_state(string $dispatch_status): string {
		if ($dispatch_status === 'accepted') return 'success';
		if ($dispatch_status === 'failed' || $dispatch_status === 'not_configured') return 'error';
		return sanitize_key($dispatch_status);
	}

	public static function render_status_panel(): void {
		if (!current_user_can('manage_options')) return;
		$config = self::config();
		$status = get_option(self::STATUS_OPTION, array());
		?>
		<hr>
		<h2>GitHub 自动构建</h2>
		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
			<input type="hidden" name="action" value="jg_save_dispatch_settings">
			<?php wp_nonce_field('jg_dispatch_settings'); ?>
			<table class="form-table"><tbody>
			<tr><th>自动构建</th><td><label><input type="checkbox" name="jg_dispatch[auto_enabled]" value="1" <?php checked($config['auto_enabled'], true); ?>> 内容写入后自动触发</label></td></tr>
			<tr><th>仓库</th><td><input class="regular-text" name="jg_dispatch[owner]" value="<?php echo esc_attr($config['owner']); ?>"> / <input class="regular-text" name="jg_dispatch[repository]" value="<?php echo esc_attr($config['repository']); ?>"></td></tr>
			<tr><th>Workflow</th><td><input class="regular-text" name="jg_dispatch[workflow]" value="<?php echo esc_attr($config['workflow']); ?>"> <input class="regular-text" name="jg_dispatch[ref]" value="<?php echo esc_attr($config['ref']); ?>" aria-label="ref"></td></tr>
			<tr><th>Debounce</th><td><input type="number" min="30" max="60" name="jg_dispatch[debounce]" value="<?php echo esc_attr((string) $config['debounce']); ?>"> 秒</td></tr>
			<tr><th>Fine-grained PAT</th><td><input class="regular-text" type="password" autocomplete="new-password" name="jg_dispatch[token]" value=""><label><input type="checkbox" name="jg_dispatch[clear_token]" value="1"> 清除 Token</label><p class="description">当前状态：<?php echo self::token() !== '' ? '已配置' : '未配置'; ?>。只需要目标仓库 Actions Read and write。</p></td></tr>
			</tbody></table>
			<?php submit_button('保存构建配置', 'secondary', 'submit', false); ?>
		</form>
		<table class="widefat striped" style="max-width:760px;margin-top:12px"><tbody>
			<tr><th>API 版本</th><td><code><?php echo esc_html(self::GITHUB_API_VERSION); ?></code></td></tr>
			<tr><th>最近状态</th><td><?php echo esc_html((string) ($status['message'] ?? '暂无记录')); ?><?php if (!empty($status['time'])) echo ' (' . esc_html($status['time']) . ')'; ?></td></tr>
		</tbody></table>
		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:12px"><input type="hidden" name="action" value="jg_manual_dispatch"><?php wp_nonce_field('jg_manual_dispatch'); ?><?php submit_button('立即强制重建', 'secondary', 'submit', false); ?></form>
		<?php
	}
}
