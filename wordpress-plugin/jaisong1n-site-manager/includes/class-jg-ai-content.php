<?php

if (!defined('ABSPATH')) {
	exit;
}

/**
 * The private AI-content interface. Callers see content contracts, never meta keys.
 */
final class JG_AI_Content {
	private const ROLE = 'jg_ai_content_editor';
	private const SETTINGS_OPTION = 'jg_ai_content_settings';
	private const IDEMPOTENCY_OPTION = 'jg_ai_content_idempotency';
	private const AUDIT_OPTION = 'jg_ai_content_audit';
	private const PUBLISH_TOKENS_OPTION = 'jg_ai_publish_confirmation_tokens';
	private const PUBLISH_CAPABILITY = 'jg_ai_publish_diary_drafts';
	private const ARTICLE_PUBLISH_CAPABILITY = 'jg_ai_publish_article_drafts';
	private const UPDATE_PUBLISHED_DIARY_CAPABILITY = 'jg_ai_update_published_diaries';
	private const UPDATE_PUBLISHED_ARTICLE_CAPABILITY = 'jg_ai_update_published_articles';
	private const UPDATE_PUBLISHED_ACTION = 'update_published';
	private const PUBLISH_TOKEN_TTL = 600;
	private const MAX_AUDIT_ENTRIES = 100;
	private const MAX_IDEMPOTENCY_ENTRIES = 200;
	private const MAX_PUBLISH_TOKENS = 200;

	public static function init(): void {
		add_action('rest_api_init', array(__CLASS__, 'register_routes'));
		add_action('add_meta_boxes', array(__CLASS__, 'add_meta_box'));
		add_action('save_post', array(__CLASS__, 'save_claim'), 20, 2);
		add_action('admin_menu', array(__CLASS__, 'add_settings_page'));
		add_action('admin_init', array(__CLASS__, 'register_settings'));
		add_action('admin_post_jg_ai_clear_audit', array(__CLASS__, 'clear_audit'));
		add_action('admin_post_jg_ai_sync_owner', array(__CLASS__, 'handle_sync_owner'));
		add_action('admin_init', array(__CLASS__, 'install'));
		add_action('update_option_' . self::SETTINGS_OPTION, array(__CLASS__, 'settings_updated'), 10, 2);
		add_action('update_option_' . JG_Settings::OPTION, array(__CLASS__, 'site_settings_updated'), 10, 2);
	}

	public static function install(): void {
		$role = get_role(self::ROLE);
		if (!$role) {
			$role = add_role(self::ROLE, 'JaisonG1n AI Content Editor', array('read' => true));
		}
		if (!$role) return;
		$role->add_cap('read');
		foreach (self::registry() as $contract) {
			$object = get_post_type_object($contract['postType']);
			if (!$object) continue;
			foreach (array('edit_posts', 'edit_published_posts', 'read_private_posts') as $name) {
				if (!empty($object->cap->$name)) $role->add_cap($object->cap->$name);
			}
		}
		self::sync_publish_capability(!empty(self::settings()['reviewed_diary_publish']));
		self::sync_article_publish_capability(!empty(JG_Settings::get()['reviewed_article_publish']));
		self::sync_update_published_capabilities(!empty(JG_Settings::get()['update_published_diaries']), !empty(JG_Settings::get()['update_published_articles']));
		if (get_option(self::PUBLISH_TOKENS_OPTION, null) === null) add_option(self::PUBLISH_TOKENS_OPTION, array(), '', false);
		else update_option(self::PUBLISH_TOKENS_OPTION, get_option(self::PUBLISH_TOKENS_OPTION, array()), false);
		// Native publish and destructive capabilities are intentionally never granted here.
	}

	public static function settings_updated($old_value, $new_value): void {
		self::sync_publish_capability(is_array($new_value) && !empty($new_value['reviewed_diary_publish']));
	}

	public static function site_settings_updated($old_value, $new_value): void {
		$new = is_array($new_value) ? $new_value : array();
		self::sync_article_publish_capability(!empty($new['reviewed_article_publish']));
		self::sync_update_published_capabilities(!empty($new['update_published_diaries']), !empty($new['update_published_articles']));
	}

	private static function sync_publish_capability(bool $enabled): void {
		$role = get_role(self::ROLE);
		if (!$role) return;
		if ($enabled) $role->add_cap(self::PUBLISH_CAPABILITY);
		else $role->remove_cap(self::PUBLISH_CAPABILITY);
	}

	private static function sync_article_publish_capability(bool $enabled): void {
		$role = get_role(self::ROLE);
		if (!$role) return;
		if ($enabled) $role->add_cap(self::ARTICLE_PUBLISH_CAPABILITY);
		else $role->remove_cap(self::ARTICLE_PUBLISH_CAPABILITY);
	}

	private static function sync_update_published_capabilities(bool $diary, bool $article): void {
		$role = get_role(self::ROLE);
		if (!$role) return;
		if ($diary) $role->add_cap(self::UPDATE_PUBLISHED_DIARY_CAPABILITY);
		else $role->remove_cap(self::UPDATE_PUBLISHED_DIARY_CAPABILITY);
		if ($article) $role->add_cap(self::UPDATE_PUBLISHED_ARTICLE_CAPABILITY);
		else $role->remove_cap(self::UPDATE_PUBLISHED_ARTICLE_CAPABILITY);
	}

	public static function settings(): array {
		$defaults = array('enabled' => true, 'create_drafts' => true, 'update_drafts' => true, 'allow_claims' => true, 'allow_publish' => false, 'reviewed_diary_publish' => false);
		$value = get_option(self::SETTINGS_OPTION, array());
		return array_replace($defaults, is_array($value) ? $value : array());
	}

	public static function register_settings(): void {
		register_setting('jg_ai_content', self::SETTINGS_OPTION, array('type' => 'object', 'sanitize_callback' => array(__CLASS__, 'sanitize_settings'), 'default' => self::settings(), 'show_in_rest' => false));
	}

	public static function sanitize_settings($input): array {
		$input = is_array($input) ? $input : array();
		return array(
			'enabled' => !empty($input['enabled']),
			'create_drafts' => !empty($input['create_drafts']),
			'update_drafts' => !empty($input['update_drafts']),
			'allow_claims' => !empty($input['allow_claims']),
			'allow_publish' => !empty($input['allow_publish']),
			'reviewed_diary_publish' => !empty($input['reviewed_diary_publish']),
		);
	}

	public static function add_settings_page(): void {
		add_options_page('AI Content API', 'AI Content API', 'manage_options', 'jg-ai-content', array(__CLASS__, 'render_settings_page'));
	}

	public static function render_settings_page(): void {
		if (!current_user_can('manage_options')) wp_die('Insufficient permission.');
		$s = self::settings();
		?>
		<div class="wrap"><h1>AI Content API</h1><form method="post" action="options.php"><?php settings_fields('jg_ai_content'); ?>
		<?php foreach (array('enabled' => 'Enable AI Content API', 'create_drafts' => 'Allow draft creation', 'update_drafts' => 'Allow draft updates', 'allow_claims' => 'Allow administrator content claims', 'reviewed_diary_publish' => 'Enable reviewed diary publishing for the AI Content Editor role') as $key => $label) : ?>
			<label style="display:block;margin:10px 0"><input type="checkbox" name="<?php echo esc_attr(self::SETTINGS_OPTION . '[' . $key . ']'); ?>" value="1" <?php checked($s[$key]); ?>> <?php echo esc_html($label); ?></label>
		<?php endforeach; submit_button(); ?></form><h2>Recent AI operations</h2><?php self::render_audit(); ?></div>
		<?php
	}

	public static function register_routes(): void {
		$namespace = 'jaisong1n/v1/ai';
		self::route($namespace, '/capabilities', WP_REST_Server::READABLE, 'capabilities', 'read');
		self::route($namespace, '/content', WP_REST_Server::READABLE, 'list_content', 'read', self::list_args());
		self::route($namespace, '/content', WP_REST_Server::CREATABLE, 'create_content', 'create', self::create_args());
		self::route($namespace, '/content/(?P<contentType>[A-Za-z][A-Za-z0-9]*)/(?P<id>\d+)', WP_REST_Server::READABLE, 'get_content', 'read', self::detail_args());
		self::route($namespace, '/content/(?P<contentType>[A-Za-z][A-Za-z0-9]*)/(?P<id>\d+)', 'PATCH', 'update_content', 'update', self::update_args());
		self::route($namespace, '/content/(?P<contentType>[A-Za-z][A-Za-z0-9]*)/(?P<id>\d+)/deployment-status', WP_REST_Server::READABLE, 'deployment_status', 'deploymentStatus', self::detail_args());
		self::route($namespace, '/content/(?P<contentType>[A-Za-z][A-Za-z0-9]*)/(?P<id>\d+)/prepare-publish', WP_REST_Server::CREATABLE, 'prepare_publish', 'publish', self::detail_args());
		self::route($namespace, '/content/(?P<contentType>[A-Za-z][A-Za-z0-9]*)/(?P<id>\d+)/publish', WP_REST_Server::CREATABLE, 'publish_content', 'publish', self::publish_args());
		self::route($namespace, '/content/(?P<contentType>[A-Za-z][A-Za-z0-9]*)/(?P<id>\d+)/prepare-update-published', WP_REST_Server::CREATABLE, 'prepare_update_published', 'prepareUpdatePublished', self::detail_args());
		self::route($namespace, '/content/(?P<contentType>[A-Za-z][A-Za-z0-9]*)/(?P<id>\d+)/update-published', WP_REST_Server::CREATABLE, 'update_published_content', 'updatePublished', self::detail_args());
		self::route($namespace, '/content/(?P<contentType>[A-Za-z][A-Za-z0-9]*)/(?P<id>\d+)/unpublish', WP_REST_Server::CREATABLE, 'unpublish_content', 'publish', self::expected_args());
		self::route($namespace, '/content/(?P<contentType>[A-Za-z][A-Za-z0-9]*)/(?P<id>\d+)/claim', WP_REST_Server::CREATABLE, 'claim_content', 'claim', self::claim_args());
		self::route($namespace, '/audit', WP_REST_Server::READABLE, 'audit', 'audit');
	}

	private static function route(string $namespace, string $route, $methods, string $callback, string $operation, array $args = array()): void {
		register_rest_route($namespace, $route, array('methods' => $methods, 'callback' => array(__CLASS__, $callback), 'permission_callback' => static fn(WP_REST_Request $request) => self::permission($request, $operation), 'args' => $args));
	}

	public static function permission(WP_REST_Request $request, string $operation) {
		if (!is_user_logged_in()) return self::error('jg_ai_authentication_required', 'Authentication is required.', 401);
		if (!self::settings()['enabled']) return self::error('jg_ai_disabled', 'The AI Content API is disabled.', 403);
		if ($operation === 'claim' || $operation === 'audit') {
			if (!current_user_can('manage_options')) return self::error('jg_ai_forbidden', 'You do not have permission for this operation.', 403);
		} elseif (!current_user_can('read')) return self::error('jg_ai_forbidden', 'You do not have permission for this operation.', 403);
		return self::rate_limit($operation);
	}

	public static function capabilities(WP_REST_Request $request): WP_REST_Response {
		$types = array();
		foreach (self::registry() as $name => $contract) {
			$operations = array();
			if (self::can_create($contract)) $operations[] = 'createDraft';
			if (self::can_read($contract, null)) $operations[] = 'read';
			if (self::can_read($contract, null)) $operations[] = 'deploymentStatus';
			if (self::can_update_type($contract)) $operations[] = 'updateDraft';
			if (self::can_publish_type($contract)) { $operations[] = 'preparePublish'; $operations[] = 'publish'; }
			if (self::can_update_published_type($contract)) { $operations[] = 'prepareUpdatePublished'; $operations[] = 'updatePublished'; }
			$types[$name] = array('operations' => $operations, 'fields' => self::public_fields($contract));
		}
		return new WP_REST_Response(array('version' => JG_SITE_MANAGER_VERSION, 'schemaVersion' => 5, 'contentTypes' => $types), 200);
	}

	public static function create_content(WP_REST_Request $request) {
		$contract = self::contract((string) $request->get_param('contentType'));
		if (is_wp_error($contract)) return $contract;
		if (!self::can_create($contract)) return self::error('jg_ai_forbidden', 'You cannot create this content type.', 403);
		$key = self::idempotency_key($request);
		if (is_wp_error($key)) return $key;
		$input = $request->get_json_params();
		if (!is_array($input)) $input = $request->get_params();
		$hash = hash('sha256', wp_json_encode($input));
		$replay = self::idempotency_replay(get_current_user_id(), 'create:' . $contract['apiType'], $key, $hash);
		if (is_wp_error($replay)) return $replay;
		if (is_array($replay)) return new WP_REST_Response(array_replace($replay, array('idempotentReplay' => true)), 200);
		$normalized = self::normalize_input($input, $contract, true);
		if (is_wp_error($normalized)) return $normalized;
		$lock = 'jg_ai_lock_' . substr(hash('sha256', get_current_user_id() . ':' . $key), 0, 40);
		if (!add_option($lock, time(), '', false)) return self::error('jg_ai_idempotency_in_progress', 'A request with this idempotency key is in progress.', 409);
		try {
			$post_id = wp_insert_post(array('post_type' => $contract['postType'], 'post_status' => 'draft', 'post_author' => get_current_user_id(), 'post_title' => $normalized['title'], 'post_name' => $normalized['slug'] ?? '', 'post_excerpt' => $normalized['excerpt'] ?? '', 'post_content' => $normalized['contentHtml'] ?? ''), true);
			if (is_wp_error($post_id)) return self::safe_wp_error($post_id);
			if (!self::write_fields((int) $post_id, $contract, $normalized['fields'])) { wp_delete_post((int) $post_id, true); return self::error('jg_ai_create_failed', 'The draft could not be saved.', 500); }
			update_post_meta((int) $post_id, '_jg_ai_created', true);
			update_post_meta((int) $post_id, '_jg_ai_owner_user_id', get_current_user_id());
			update_post_meta((int) $post_id, '_jg_ai_editable', true);
			update_post_meta((int) $post_id, '_jg_ai_publishable', false);
			if (self::auto_publishable_draft($contract, (int) $post_id)) {
				update_post_meta((int) $post_id, '_jg_ai_publishable', true);
			}
			$post = get_post((int) $post_id);
			$result = self::project($post, $contract, true) + array('idempotentReplay' => false);
			self::store_idempotency(get_current_user_id(), 'create:' . $contract['apiType'], $key, $hash, $result, 201);
			self::record('createDraft', $contract['apiType'], (int) $post_id, 201, array_keys($normalized['fields']), false);
			return new WP_REST_Response($result, 201);
		} finally { delete_option($lock); }
	}

	public static function get_content(WP_REST_Request $request) {
		$contract = self::contract((string) $request['contentType']); if (is_wp_error($contract)) return $contract;
		$post = self::post_for_contract((int) $request['id'], $contract); if (is_wp_error($post)) return $post;
		if (!self::can_read($contract, $post)) return self::not_found();
		return new WP_REST_Response(self::project($post, $contract, true), 200);
	}

	public static function deployment_status(WP_REST_Request $request) {
		$contract = self::contract((string) $request['contentType']); if (is_wp_error($contract)) return $contract;
		$post = self::post_for_contract((int) $request['id'], $contract); if (is_wp_error($post)) return $post;
		if (!self::can_read($contract, $post)) return self::not_found();

		$public_url = self::get_canonical_public_url($contract['apiType'], $post);
		$record = JG_Dispatch::find_latest_record_for_content($contract['apiType'], (int) $post->ID, trim((string) $post->post_modified_gmt));
		$response = array(
			'contentType' => $contract['apiType'],
			'contentId' => (int) $post->ID,
			'title' => $post->post_title,
			'wordpressStatus' => $post->post_status,
			'dispatchStatus' => null,
			'buildStatus' => 'not_triggered',
			'buildConclusion' => null,
			'deploymentStatus' => 'unknown',
			'pageStatus' => 'unchecked',
			'publicUrl' => $public_url,
			'cmsUrl' => admin_url('post.php?post=' . $post->ID . '&action=edit'),
			'workflowRunId' => null,
			'workflowRunUrl' => null,
			'triggeredAt' => null,
			'startedAt' => null,
			'completedAt' => null,
			'lastCheckedAt' => gmdate('c'),
			'errorCode' => null,
			'errorSummary' => null,
		);

		if (is_array($record)) {
			$response['dispatchStatus'] = $record['dispatchStatus'] ?? null;
			$response['workflowRunId'] = isset($record['workflowRunId']) && $record['workflowRunId'] !== null ? (int) $record['workflowRunId'] : null;
			$response['workflowRunUrl'] = $record['runUrl'] ?? null;
			$response['triggeredAt'] = $record['triggeredAt'] ?? null;
			$response['startedAt'] = $record['startedAt'] ?? null;
			$response['completedAt'] = $record['completedAt'] ?? null;
			$response['lastCheckedAt'] = $record['lastCheckedAt'] ?? $response['lastCheckedAt'];
			$response['errorCode'] = $record['errorCode'] ?? null;
			$response['errorSummary'] = $record['errorSummary'] ?? null;
			$run_id = $response['workflowRunId'];
			if ($run_id > 0) {
				$run = JG_Dispatch::query_run($run_id, $record);
				$response['buildStatus'] = $run['buildStatus'];
				$response['buildConclusion'] = $run['buildConclusion'];
				if ($run['startedAt'] !== null) $response['startedAt'] = $run['startedAt'];
				if ($run['completedAt'] !== null) $response['completedAt'] = $run['completedAt'];
				$response['lastCheckedAt'] = $run['lastCheckedAt'];
				if ($run['errorCode'] !== null) {
					$response['errorCode'] = $run['errorCode'];
					$response['errorSummary'] = $run['errorSummary'];
				}
			} else {
				$response['buildStatus'] = $record['buildStatus'] ?? 'pending';
			}
		}

		if ($post->post_status === 'publish' && $public_url !== null) {
			$page = self::probe_public_page($public_url);
			$response['pageStatus'] = $page;
			if ($response['buildStatus'] === 'success' && $page === 'reachable') {
				$response['deploymentStatus'] = 'deployed';
			} elseif (in_array($response['buildStatus'], array('success', 'queued', 'in_progress', 'pending'), true)) {
				$response['deploymentStatus'] = 'pending';
			}
		}

		$details = array('state' => (string) $response['buildStatus']);
		if (is_int($response['workflowRunId'])) $details['workflowRunId'] = $response['workflowRunId'];
		self::record('deploymentStatus', $contract['apiType'], (int) $post->ID, 200, array(), false, $details);
		return new WP_REST_Response($response, 200);
	}

	public static function get_canonical_public_url(string $content_type, WP_Post $post): ?string {
		$base = esc_url_raw((string) (JG_Settings::get()['public_site_url'] ?? ''), array('https'));
		if ($base === '' || strtolower((string) wp_parse_url($base, PHP_URL_HOST)) === '') return null;
		$slug = trim(rawurldecode((string) $post->post_name));
		if ($slug === '' || str_contains($slug, '/') || str_contains($slug, '\\')) return null;
		if ($content_type === 'article') $path = '/posts/' . rawurlencode($slug) . '/';
		elseif ($content_type === 'diary') $path = '/diary/' . rawurlencode($slug) . '/';
		else return null;
		return untrailingslashit($base) . $path;
	}

	public static function probe_public_page(string $url): string {
		$allowed = strtolower((string) wp_parse_url(esc_url_raw((string) (JG_Settings::get()['public_site_url'] ?? ''), array('https')), PHP_URL_HOST));
		$host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
		if ($allowed === '' || $host !== $allowed) return 'unavailable';
		$cache_key = 'jg_page_probe_' . md5($url);
		$cached = get_transient($cache_key);
		if (is_string($cached) && in_array($cached, array('reachable', 'not_found', 'unavailable'), true)) return $cached;
		$response = wp_remote_get($url, array(
			'timeout' => 10,
			'redirection' => 0,
			'sslverify' => true,
			'limit_response_size' => 65536,
			'user-agent' => 'JaisonG1n-Site-Manager/' . JG_SITE_MANAGER_VERSION . ' status-probe',
			'headers' => array('Accept' => 'text/html'),
		));
		$status = 'unavailable';
		if (!is_wp_error($response)) {
			$code = (int) wp_remote_retrieve_response_code($response);
			if ($code >= 200 && $code < 300) $status = 'reachable';
			elseif ($code === 404) $status = 'not_found';
		}
		set_transient($cache_key, $status, 30);
		return $status;
	}

	public static function list_content(WP_REST_Request $request): WP_REST_Response {
		$type = (string) $request->get_param('contentType'); $contracts = $type === '' ? self::registry() : array($type => self::contract($type));
		$items = array();
		foreach ($contracts as $name => $contract) {
			if (is_wp_error($contract)) return $contract;
			$query = array('post_type' => $contract['postType'], 'post_status' => self::statuses($request->get_param('status')), 'posts_per_page' => min(50, max(1, absint($request->get_param('perPage') ?: 20))), 'paged' => max(1, absint($request->get_param('page') ?: 1)), 's' => sanitize_text_field((string) $request->get_param('search')), 'name' => sanitize_title((string) $request->get_param('slug')));
			foreach (get_posts($query) as $post) if (self::can_read($contract, $post)) $items[] = self::project($post, $contract, false);
		}
		return new WP_REST_Response(array('items' => $items), 200);
	}

	public static function update_content(WP_REST_Request $request) {
		$contract = self::contract((string) $request['contentType']); if (is_wp_error($contract)) return $contract;
		if (!in_array($contract['apiType'], array('diary', 'article'), true)) return self::error('jg_ai_update_draft_unsupported', 'Draft updates are only available for diary and article content.', 403);
		$post = self::post_for_contract((int) $request['id'], $contract); if (is_wp_error($post)) return $post;
		if (!self::can_update($contract, $post)) return self::not_found();
		if ($post->post_status !== 'draft') return self::error('jg_ai_draft_required', 'Only diary and article drafts can be changed through this endpoint.', 409);
		$input = $request->get_json_params(); if (!is_array($input)) $input = $request->get_params();
		if (!array_key_exists('expectedModifiedAt', $input) || (!is_string($input['expectedModifiedAt']) && $input['expectedModifiedAt'] !== null) || $input['expectedModifiedAt'] === '') return self::error('jg_ai_expected_modified_at_required', 'expectedModifiedAt is required.', 400);
		if (!self::modified_matches($post, $input['expectedModifiedAt'])) return self::error('jg_ai_stale_content', 'Content has changed. Read it again before updating.', 409);
		$normalized = self::normalize_draft_update($input); if (is_wp_error($normalized)) return $normalized;
		$data = array('ID' => $post->ID);
		$changed = array();
		foreach (array('title' => 'post_title', 'slug' => 'post_name', 'excerpt' => 'post_excerpt', 'content' => 'post_content') as $input_key => $post_key) {
			if (!array_key_exists($input_key, $normalized) || $normalized[$input_key] === $post->$post_key) continue;
			$data[$post_key] = $normalized[$input_key];
			$changed[] = $input_key;
		}
		if (!$changed) return self::error('jg_ai_no_changes', 'At least one draft field must contain a new value.', 400);
		$result = wp_update_post($data, true); if (is_wp_error($result)) return self::safe_wp_error($result);
		$updated = get_post($post->ID); self::record('updateDraft', $contract['apiType'], $post->ID, 200, $changed, false);
		return new WP_REST_Response(self::project($updated, $contract, true), 200);
	}

	public static function prepare_publish(WP_REST_Request $request) {
		$post_id = (int) $request['id'];
		$contract = self::contract((string) $request['contentType']);
		if (is_wp_error($contract)) {
			self::record('publish_rejected', sanitize_key((string) $request['contentType']), $post_id, 400, array(), false, array('reason' => 'unsupported_type'));
			return $contract;
		}
		if (!in_array($contract['apiType'], array('diary', 'article'), true)) {
			self::record('publish_rejected', $contract['apiType'], $post_id, 403, array(), false, array('reason' => 'unsupported_type'));
			return self::error('jg_ai_publish_unsupported', 'Reviewed publishing is only available for diary and article drafts.', 403);
		}
		$post = self::post_for_contract($post_id, $contract);
		if (is_wp_error($post)) {
			self::record('publish_rejected', $contract['apiType'], $post_id, 404, array(), false, array('reason' => 'not_found'));
			return $post;
		}
		$denial_reason = self::publish_rejection_reason($contract, $post);
		if ($denial_reason !== 'ok') {
			self::record('publish_rejected', $contract['apiType'], $post_id, 403, array(), false, array('reason' => $denial_reason));
			return self::error('jg_ai_publish_forbidden', 'Reviewed publishing is not enabled or authorized.', 403);
		}
		if ($post->post_status !== 'draft') {
			self::record('publish_conflict', $contract['apiType'], $post_id, 409, array(), false, array('reason' => 'not_draft'));
			return self::error('jg_ai_publish_draft_required', 'Only diary and article drafts can be prepared for publishing.', 409);
		}
		$issued = self::issue_publish_token($post, $contract['apiType']);
		if (is_wp_error($issued)) {
			self::record('publish_rejected', $contract['apiType'], $post_id, 500, array(), false, array('reason' => 'token_issue_failed'));
			return $issued;
		}
		self::record('publish_prepare', $contract['apiType'], $post_id, 200, array(), false, array('tokenFingerprint' => $issued['fingerprint']));
		return new WP_REST_Response(array(
			'id' => $post->ID,
			'contentType' => $contract['apiType'],
			'title' => $post->post_title,
			'slug' => $post->post_name,
			'excerpt' => $post->post_excerpt,
			'status' => 'draft',
			'modifiedAt' => self::modified_at($post),
			'editUrl' => admin_url('post.php?post=' . $post->ID . '&action=edit'),
			'confirmationToken' => $issued['token'],
			'expiresAt' => $issued['expiresAt'],
		), 200);
	}

	public static function publish_content(WP_REST_Request $request) {
		$post_id = (int) $request['id'];
		$contract = self::contract((string) $request['contentType']);
		if (is_wp_error($contract)) {
			self::record('publish_rejected', sanitize_key((string) $request['contentType']), $post_id, 400, array(), false, array('reason' => 'unsupported_type'));
			return $contract;
		}
		if (!in_array($contract['apiType'], array('diary', 'article'), true)) {
			self::record('publish_rejected', $contract['apiType'], $post_id, 403, array(), false, array('reason' => 'unsupported_type'));
			return self::error('jg_ai_publish_unsupported', 'Reviewed publishing is only available for diary and article drafts.', 403);
		}
		$post = self::post_for_contract($post_id, $contract);
		if (is_wp_error($post)) {
			self::record('publish_rejected', $contract['apiType'], $post_id, 404, array(), false, array('reason' => 'not_found'));
			return $post;
		}
		$denial_reason = self::publish_rejection_reason($contract, $post);
		if ($denial_reason !== 'ok') {
			self::record('publish_rejected', $contract['apiType'], $post_id, 403, array(), false, array('reason' => $denial_reason));
			return self::error('jg_ai_publish_forbidden', 'Reviewed publishing is not enabled or authorized.', 403);
		}
		$input = $request->get_json_params(); if (!is_array($input)) $input = $request->get_params();
		$key = self::idempotency_key($request);
		if (is_wp_error($key)) {
			self::record('publish_rejected', $contract['apiType'], $post_id, 400, array(), false, array('reason' => 'idempotency_required'));
			return $key;
		}
		if (!array_key_exists('expectedModifiedAt', $input) || (!is_string($input['expectedModifiedAt']) && $input['expectedModifiedAt'] !== null) || $input['expectedModifiedAt'] === '') {
			self::record('publish_rejected', $contract['apiType'], $post_id, 400, array(), false, array('reason' => 'expected_modified_at_required'));
			return self::error('jg_ai_expected_modified_at_required', 'expectedModifiedAt is required.', 400);
		}
		$token = $input['confirmationToken'] ?? '';
		if (!is_string($token) || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
			self::record('publish_rejected', $contract['apiType'], $post_id, 403, array(), false, array('reason' => 'token_invalid'));
			return self::error('jg_ai_confirmation_token_invalid', 'The publish confirmation token is invalid.', 403);
		}
		$token_hash = hash('sha256', $token);
		$request_hash = hash('sha256', wp_json_encode(array('contentType' => $contract['apiType'], 'contentId' => $post_id, 'expectedModifiedAt' => $input['expectedModifiedAt'], 'confirmationTokenHash' => $token_hash)));
		$replay = self::idempotency_replay(get_current_user_id(), 'publish:' . $contract['apiType'], $key, $request_hash);
		if (is_wp_error($replay)) {
			self::record('publish_conflict', $contract['apiType'], $post_id, 409, array(), false, array('reason' => 'idempotency_conflict'));
			return $replay;
		}
		if (is_array($replay)) {
			self::record('idempotent_replay', $contract['apiType'], $post_id, 200, array(), true, array('idempotencyFingerprint' => substr(hash('sha256', $key), 0, 12)));
			return new WP_REST_Response(array_replace($replay, array('idempotentReplay' => true)), 200);
		}
		if ($post->post_status === 'publish') {
			self::record('publish_conflict', $contract['apiType'], $post_id, 409, array(), false, array('reason' => 'already_published'));
			return self::error('jg_ai_already_published', 'This content is already published.', 409);
		}
		if ($post->post_status !== 'draft' || !self::modified_matches($post, $input['expectedModifiedAt'])) {
			self::record('publish_conflict', $contract['apiType'], $post_id, 409, array(), false, array('reason' => $post->post_status !== 'draft' ? 'not_draft' : 'modified'));
			return self::error('jg_ai_publish_conflict', 'The content changed after publish preparation. Prepare it again.', 409);
		}
		$lock = 'jg_ai_publish_lock_' . substr($token_hash, 0, 40);
		if (!add_option($lock, time(), '', false)) return self::error('jg_ai_publish_in_progress', 'This publish confirmation is already being processed.', 409);
		try {
			$post = get_post($post_id);
			$validated = self::validate_publish_token($token_hash, $post_id, $input['expectedModifiedAt'], $contract['apiType']);
			if (is_wp_error($validated)) {
				$error_data = (array) $validated->get_error_data();
				self::record('publish_rejected', $contract['apiType'], $post_id, (int) ($error_data['status'] ?? 403), array(), false, array('reason' => $validated->get_error_code(), 'tokenFingerprint' => substr($token_hash, 0, 12)));
				return $validated;
			}
			if (!$post || $post->post_status !== 'draft' || !self::modified_matches($post, $input['expectedModifiedAt']) || !self::can_publish($contract, $post)) {
				self::record('publish_conflict', $contract['apiType'], $post_id, 409, array(), false, array('reason' => 'changed_during_publish'));
				return self::error('jg_ai_publish_conflict', 'The content changed while publishing. Prepare it again.', 409);
			}
			$result = wp_update_post(array('ID' => $post_id, 'post_status' => 'publish'), true);
			if (is_wp_error($result)) {
				self::record('publish_rejected', $contract['apiType'], $post_id, 500, array(), false, array('reason' => 'write_failed', 'tokenFingerprint' => substr($token_hash, 0, 12)));
				return self::safe_wp_error($result);
			}
			self::consume_publish_token($token_hash);
			$updated = get_post($post_id);
			$response = self::project($updated, $contract, true) + array('idempotentReplay' => false);
			self::store_idempotency(get_current_user_id(), 'publish:' . $contract['apiType'], $key, $request_hash, $response, 200);
			self::record('publish_success', $contract['apiType'], $post_id, 200, array(), false, array('tokenFingerprint' => substr($token_hash, 0, 12), 'idempotencyFingerprint' => substr(hash('sha256', $key), 0, 12)));
			return new WP_REST_Response($response, 200);
		} finally { delete_option($lock); }
	}

	public static function prepare_update_published(WP_REST_Request $request) {
		$post_id = (int) $request['id'];
		$contract = self::contract((string) $request['contentType']);
		if (is_wp_error($contract)) {
			self::record('prepareUpdatePublished', sanitize_key((string) $request['contentType']), $post_id, 400, array(), false, array('reason' => 'unsupported_type'));
			return $contract;
		}
		if (!in_array($contract['apiType'], array('diary', 'article'), true)) {
			self::record('prepareUpdatePublished', $contract['apiType'], $post_id, 403, array(), false, array('reason' => 'unsupported_type'));
			return self::error('jg_ai_update_published_unsupported', 'In-place updates are only available for published diary and article content.', 403);
		}
		$post = get_post($post_id);
		if (!$post) {
			self::record('prepareUpdatePublished', $contract['apiType'], $post_id, 404, array(), false, array('reason' => 'not_found'));
			return self::not_found();
		}
		if ($post->post_type !== $contract['postType']) {
			self::record('prepareUpdatePublished', $contract['apiType'], $post_id, 400, array(), false, array('reason' => 'content_type_mismatch'));
			return self::error('jg_ai_content_type_mismatch', 'The content type does not match the requested object.', 400);
		}
		$reason = self::update_published_rejection_reason($contract, $post);
		if ($reason !== 'ok') {
			$status = $reason === 'not_published' ? 409 : ($reason === 'not_found' ? 404 : 403);
			self::record('prepareUpdatePublished', $contract['apiType'], $post_id, $status, array(), false, array('reason' => $reason));
			return self::update_published_error($reason);
		}
		$input = $request->get_json_params(); if (!is_array($input)) $input = $request->get_params();
		if (!array_key_exists('expectedModifiedAt', $input) || (!is_string($input['expectedModifiedAt']) && $input['expectedModifiedAt'] !== null) || $input['expectedModifiedAt'] === '') {
			self::record('prepareUpdatePublished', $contract['apiType'], $post_id, 400, array(), false, array('reason' => 'expected_modified_at_required'));
			return self::error('jg_ai_expected_modified_at_required', 'expectedModifiedAt is required.', 400);
		}
		if (!self::modified_matches($post, $input['expectedModifiedAt'])) {
			self::record('prepareUpdatePublished', $contract['apiType'], $post_id, 409, array(), false, array('reason' => 'modified'));
			return self::error('jg_ai_stale_content', 'Content has changed. Read it again before updating.', 409);
		}
		$normalized = self::normalize_published_update($input);
		if (is_wp_error($normalized)) return $normalized;
		$changed = self::changed_published_fields($post, $normalized);
		if (!$changed) {
			self::record('prepareUpdatePublished', $contract['apiType'], $post_id, 400, array(), false, array('reason' => 'no_changes'));
			return self::error('jg_ai_no_changes', 'No published field contains a new value.', 400);
		}
		$content_hash = self::published_content_hash($normalized);
		$issued = self::issue_update_published_token($post, $contract['apiType'], $content_hash);
		if (is_wp_error($issued)) {
			self::record('prepareUpdatePublished', $contract['apiType'], $post_id, 500, array(), false, array('reason' => 'token_issue_failed'));
			return $issued;
		}
		self::record('prepareUpdatePublished', $contract['apiType'], $post_id, 200, $changed, false, array('tokenFingerprint' => $issued['fingerprint']));
		return new WP_REST_Response(array(
			'contentType' => $contract['apiType'],
			'id' => $post->ID,
			'slug' => $post->post_name,
			'status' => $post->post_status,
			'publishedAt' => self::published_at($post),
			'modifiedAt' => self::modified_at($post),
			'currentTitle' => $post->post_title,
			'proposedTitle' => $normalized['title'] ?? null,
			'currentExcerpt' => $post->post_excerpt,
			'proposedExcerpt' => $normalized['excerpt'] ?? null,
			'titleChanged' => in_array('title', $changed, true),
			'excerptChanged' => in_array('excerpt', $changed, true),
			'contentChanged' => in_array('content', $changed, true),
			'confirmationPhrase' => self::update_confirmation_phrase($contract['apiType'], $post->ID, $issued['fingerprint']),
			'confirmationToken' => $issued['token'],
			'expiresAt' => $issued['expiresAt'],
			'protectedFields' => array('id', 'contentType', 'slug', 'status', 'publishedAt', 'postDate', 'postDateGmt', 'author', 'ownership'),
		), 200);
	}

	public static function update_published_content(WP_REST_Request $request) {
		$post_id = (int) $request['id'];
		$contract = self::contract((string) $request['contentType']);
		if (is_wp_error($contract)) {
			self::record('updatePublished', sanitize_key((string) $request['contentType']), $post_id, 400, array(), false, array('reason' => 'unsupported_type'));
			return $contract;
		}
		if (!in_array($contract['apiType'], array('diary', 'article'), true)) {
			self::record('updatePublished', $contract['apiType'], $post_id, 403, array(), false, array('reason' => 'unsupported_type'));
			return self::error('jg_ai_update_published_unsupported', 'In-place updates are only available for published diary and article content.', 403);
		}
		$post = get_post($post_id);
		if (!$post) {
			self::record('updatePublished', $contract['apiType'], $post_id, 404, array(), false, array('reason' => 'not_found'));
			return self::not_found();
		}
		if ($post->post_type !== $contract['postType']) {
			self::record('updatePublished', $contract['apiType'], $post_id, 400, array(), false, array('reason' => 'content_type_mismatch'));
			return self::error('jg_ai_content_type_mismatch', 'The content type does not match the requested object.', 400);
		}
		$reason = self::update_published_rejection_reason($contract, $post);
		if ($reason !== 'ok') {
			$status = $reason === 'not_published' ? 409 : ($reason === 'not_found' ? 404 : 403);
			self::record('updatePublished', $contract['apiType'], $post_id, $status, array(), false, array('reason' => $reason));
			return self::update_published_error($reason);
		}
		$input = $request->get_json_params(); if (!is_array($input)) $input = $request->get_params();
		if (!array_key_exists('expectedModifiedAt', $input) || (!is_string($input['expectedModifiedAt']) && $input['expectedModifiedAt'] !== null) || $input['expectedModifiedAt'] === '') {
			self::record('updatePublished', $contract['apiType'], $post_id, 400, array(), false, array('reason' => 'expected_modified_at_required'));
			return self::error('jg_ai_expected_modified_at_required', 'expectedModifiedAt is required.', 400);
		}
		$key = self::idempotency_key($request);
		if (is_wp_error($key)) {
			self::record('updatePublished', $contract['apiType'], $post_id, 400, array(), false, array('reason' => 'idempotency_required'));
			return $key;
		}
		$token = $input['confirmationToken'] ?? '';
		if (!is_string($token) || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
			self::record('updatePublished', $contract['apiType'], $post_id, 403, array(), false, array('reason' => 'token_invalid'));
			return self::error('jg_ai_confirmation_token_invalid', 'The confirmation token is invalid.', 403);
		}
		$normalized = self::normalize_published_update($input);
		if (is_wp_error($normalized)) return $normalized;
		$token_hash = hash('sha256', $token);
		$content_hash = self::published_content_hash($normalized);
		$request_hash = hash('sha256', wp_json_encode(array('contentType' => $contract['apiType'], 'contentId' => $post_id, 'expectedModifiedAt' => $input['expectedModifiedAt'], 'confirmationTokenHash' => $token_hash, 'contentHash' => $content_hash)));
		$action = 'update_published:' . $contract['apiType'];
		$replay = self::idempotency_replay(get_current_user_id(), $action, $key, $request_hash);
		if (is_wp_error($replay)) {
			self::record('updatePublished', $contract['apiType'], $post_id, 409, array(), false, array('reason' => 'idempotency_conflict'));
			return $replay;
		}
		if (is_array($replay)) {
			self::record('idempotent_replay', $contract['apiType'], $post_id, 200, array(), true, array('idempotencyFingerprint' => substr(hash('sha256', $key), 0, 12)));
			return new WP_REST_Response(array_replace($replay, array('idempotentReplay' => true)), 200);
		}
		$lock = 'jg_ai_update_published_lock_' . substr($token_hash, 0, 40);
		if (!add_option($lock, time(), '', false)) return self::error('jg_ai_update_published_in_progress', 'This update confirmation is already being processed.', 409);
		try {
			$post = get_post($post_id);
			$validated = self::validate_update_published_token($token_hash, $post_id, $input['expectedModifiedAt'], $contract['apiType'], $content_hash);
			if (is_wp_error($validated)) {
				$error_data = (array) $validated->get_error_data();
				self::record('updatePublished', $contract['apiType'], $post_id, (int) ($error_data['status'] ?? 403), array(), false, array('reason' => $validated->get_error_code(), 'tokenFingerprint' => substr($token_hash, 0, 12)));
				return $validated;
			}
			$before = self::protected_fields($post);
			$reason = self::update_published_rejection_reason($contract, $post);
			if ($reason !== 'ok' || !self::modified_matches($post, $input['expectedModifiedAt'])) {
				self::record('updatePublished', $contract['apiType'], $post_id, 409, array(), false, array('reason' => 'changed_during_prepare'));
				return self::error('jg_ai_update_published_conflict', 'The content changed after preparation. Prepare it again.', 409);
			}
			$changed = self::changed_published_fields($post, $normalized);
			if (!$changed) {
				self::record('updatePublished', $contract['apiType'], $post_id, 400, array(), false, array('reason' => 'no_changes'));
				return self::error('jg_ai_no_changes', 'No published field contains a new value.', 400);
			}
			$data = array('ID' => $post_id);
			foreach (array('title' => 'post_title', 'excerpt' => 'post_excerpt', 'content' => 'post_content') as $input_key => $post_key) {
				if (in_array($input_key, $changed, true)) $data[$post_key] = $normalized[$input_key];
			}
			$result = wp_update_post($data, true);
			if (is_wp_error($result)) {
				self::record('updatePublished', $contract['apiType'], $post_id, 500, array(), false, array('reason' => 'write_failed', 'tokenFingerprint' => substr($token_hash, 0, 12)));
				return self::safe_wp_error($result);
			}
			$after = get_post($post_id);
			if (!self::protected_fields_match($before, $after)) {
				self::record('updatePublished', $contract['apiType'], $post_id, 409, array(), false, array('reason' => 'protected_field_changed', 'tokenFingerprint' => substr($token_hash, 0, 12)));
				return self::error('jg_ai_protected_field_changed', 'A protected field changed during the update. No publish recovery was attempted.', 409);
			}
			foreach ($changed as $field) {
				$expected = $normalized[$field];
				$actual = $field === 'title' ? $after->post_title : ($field === 'excerpt' ? $after->post_excerpt : $after->post_content);
				if ($actual !== $expected) {
					self::record('updatePublished', $contract['apiType'], $post_id, 500, array(), false, array('reason' => 'readback_verification_failed', 'tokenFingerprint' => substr($token_hash, 0, 12)));
					return self::error('jg_ai_readback_verification_failed', 'The updated content could not be verified.', 500);
				}
			}
			self::consume_publish_token($token_hash);
			$response = self::project($after, $contract, true) + array('idempotentReplay' => false);
			self::store_idempotency(get_current_user_id(), $action, $key, $request_hash, $response, 200);
			self::record('updatePublished', $contract['apiType'], $post_id, 200, $changed, false, array('tokenFingerprint' => substr($token_hash, 0, 12), 'idempotencyFingerprint' => substr(hash('sha256', $key), 0, 12)));
			return new WP_REST_Response($response, 200);
		} finally { delete_option($lock); }
	}

	public static function unpublish_content(WP_REST_Request $request) {
		self::record('publish_rejected', sanitize_key((string) $request['contentType']), (int) $request['id'], 403, array(), false, array('reason' => 'unpublish_unsupported'));
		return self::error('jg_ai_unpublish_unsupported', 'AI unpublishing is not available.', 403);
	}

	public static function claim_content(WP_REST_Request $request) {
		if (!self::settings()['allow_claims']) return self::error('jg_ai_claims_disabled', 'Content claims are disabled.', 403);
		$contract = self::contract((string) $request['contentType']); if (is_wp_error($contract)) return $contract;
		$post = self::post_for_contract((int) $request['id'], $contract); if (is_wp_error($post)) return $post;
		$editable = rest_sanitize_boolean($request->get_param('editable')); $publishable = rest_sanitize_boolean($request->get_param('publishable'));
		update_post_meta($post->ID, '_jg_ai_editable', $editable); update_post_meta($post->ID, '_jg_ai_publishable', $publishable);
		self::record('claim', $contract['apiType'], $post->ID, 200, array('editable', 'publishable'), false);
		return new WP_REST_Response(self::project(get_post($post->ID), $contract, true), 200);
	}

	public static function audit(): WP_REST_Response { return new WP_REST_Response(array('items' => get_option(self::AUDIT_OPTION, array())), 200); }

	private static function registry(): array {
		return array(
			'article' => array('apiType' => 'article', 'postType' => 'post', 'taxonomy' => true),
			'diary' => array('apiType' => 'diary', 'postType' => 'jg_diary'), 'project' => array('apiType' => 'project', 'postType' => 'jg_project'),
			'timeline' => array('apiType' => 'timeline', 'postType' => 'jg_timeline'), 'skill' => array('apiType' => 'skill', 'postType' => 'jg_skill'),
			'aiTool' => array('apiType' => 'aiTool', 'postType' => 'jg_ai_tool'), 'friend' => array('apiType' => 'friend', 'postType' => 'jg_friend'),
			'announcement' => array('apiType' => 'announcement', 'postType' => 'jg_announcement'), 'techRadar' => array('apiType' => 'techRadar', 'postType' => 'jg_tech_radar'),
			'learningResource' => array('apiType' => 'learningResource', 'postType' => 'jg_learning_resource'),
		);
	}

	public static function api_type_for_post_type(string $post_type): ?string {
		foreach (self::registry() as $name => $contract) {
			if ($contract['postType'] === $post_type) return $name;
		}
		return null;
	}

	private static function contract(string $type) { $all = self::registry(); return $all[$type] ?? self::error('jg_ai_unsupported_content_type', 'This content type is not supported.', 400); }
	private static function post_for_contract(int $id, array $contract) { $post = get_post($id); return (!$post || $post->post_type !== $contract['postType']) ? self::not_found() : $post; }
	private static function not_found(): WP_Error { return self::error('jg_ai_content_not_found', 'Content was not found.', 404); }
	private static function error(string $code, string $message, int $status, array $extra = array()): WP_Error { return new WP_Error($code, $message, array_merge(array('status' => $status, 'correlationId' => wp_generate_uuid4()), $extra)); }
	private static function safe_wp_error(WP_Error $error): WP_Error { return self::error('jg_ai_content_write_failed', 'The content could not be saved.', 400); }

	private static function can_create(array $contract): bool { $object = get_post_type_object($contract['postType']); return !empty(self::settings()['create_drafts']) && $object && current_user_can($object->cap->create_posts); }
	private static function auto_publishable_draft(array $contract, int $post_id): bool {
		if (!in_array($contract['apiType'], array('diary', 'article'), true)) return false;
		$site_settings = JG_Settings::get();
		if ($contract['apiType'] === 'diary') {
			if (empty(self::settings()['reviewed_diary_publish'])) return false;
			if (!current_user_can(self::PUBLISH_CAPABILITY)) return false;
			if (empty($site_settings['auto_publishable_ai_diaries'])) return false;
		} else {
			if (empty($site_settings['reviewed_article_publish'])) return false;
			if (!current_user_can(self::ARTICLE_PUBLISH_CAPABILITY)) return false;
			if (empty($site_settings['auto_publishable_ai_articles'])) return false;
		}
		$post = get_post($post_id);
		if (!$post || $post->post_status !== 'draft') return false;
		$owner = (int) get_post_meta($post_id, '_jg_ai_owner_user_id', true);
		return (int) $post->post_author === get_current_user_id() && $owner === get_current_user_id();
	}
	private static function can_read(array $contract, ?WP_Post $post): bool {
		if ($post === null) return true;
		$owner_id = (int) get_post_meta($post->ID, '_jg_ai_owner_user_id', true);
		return (int) $post->post_author === get_current_user_id()
			|| ($owner_id > 0 && $owner_id === get_current_user_id())
			|| (bool) get_post_meta($post->ID, '_jg_ai_editable', true);
	}
	private static function can_update_type(array $contract): bool {
		$object = get_post_type_object($contract['postType']);
		return in_array($contract['apiType'], array('diary', 'article'), true) && !empty(self::settings()['update_drafts']) && $object && current_user_can($object->cap->edit_posts);
	}
	private static function can_update(array $contract, ?WP_Post $post): bool { return self::can_update_type($contract) && self::can_manage_ai_content($contract, $post); }
	private static function can_publish_type(array $contract): bool {
		if ($contract['apiType'] === 'diary') {
			return !empty(self::settings()['reviewed_diary_publish']) && current_user_can(self::PUBLISH_CAPABILITY);
		}
		if ($contract['apiType'] === 'article') {
			return !empty(JG_Settings::get()['reviewed_article_publish']) && current_user_can(self::ARTICLE_PUBLISH_CAPABILITY);
		}
		return false;
	}

	private static function can_publish(array $contract, ?WP_Post $post): bool {
		return self::can_publish_type($contract)
			&& self::can_manage_ai_content($contract, $post)
			&& (bool) get_post_meta($post->ID, '_jg_ai_publishable', true);
	}

	private static function is_ai_owner(WP_Post $post): bool {
		$owner_id = (int) get_post_meta($post->ID, '_jg_ai_owner_user_id', true);
		return (int) $post->post_author === get_current_user_id()
			|| ($owner_id > 0 && $owner_id === get_current_user_id());
	}

	private static function can_manage_ai_content(array $contract, ?WP_Post $post): bool {
		if ($post === null || !self::can_read($contract, $post)) return false;
		// Native WordPress edit remains an allowed path for role-granted or directly owned posts.
		if (current_user_can('edit_post', $post->ID)) return true;
		// AI ownership plus the explicit editable grant covers API-owned drafts
		// without relying on edit_others_* capabilities.
		return self::is_ai_owner($post) && (bool) get_post_meta($post->ID, '_jg_ai_editable', true);
	}

	private static function publish_rejection_reason(array $contract, ?WP_Post $post): string {
		if (!self::can_publish_type($contract)) {
			$setting = $contract['apiType'] === 'diary' ? self::settings()['reviewed_diary_publish'] : JG_Settings::get()['reviewed_article_publish'];
			return empty($setting) ? 'setting_disabled' : 'missing_publish_capability';
		}
		if ($post === null) return 'not_found';
		if (!self::can_manage_ai_content($contract, $post)) {
			return self::can_read($contract, $post) ? 'edit_denied' : 'ownership_denied';
		}
		if (!(bool) get_post_meta($post->ID, '_jg_ai_publishable', true)) return 'not_publishable';
		return 'ok';
	}

	private static function can_update_published_type(array $contract): bool {
		$site_settings = JG_Settings::get();
		if ($contract['apiType'] === 'diary') {
			return !empty($site_settings['update_published_diaries']) && current_user_can(self::UPDATE_PUBLISHED_DIARY_CAPABILITY);
		}
		if ($contract['apiType'] === 'article') {
			return !empty($site_settings['update_published_articles']) && current_user_can(self::UPDATE_PUBLISHED_ARTICLE_CAPABILITY);
		}
		return false;
	}

	private static function can_update_published(array $contract, ?WP_Post $post): bool {
		if ($post === null || $post->post_status !== 'publish') return false;
		return self::can_update_published_type($contract) && self::can_manage_ai_content($contract, $post);
	}

	private static function update_published_rejection_reason(array $contract, ?WP_Post $post): string {
		if (!in_array($contract['apiType'], array('diary', 'article'), true)) return 'unsupported_type';
		if ($post === null) return 'not_found';
		if ($post->post_status !== 'publish') return 'not_published';
		if (!self::can_update_published_type($contract)) {
			$site_settings = JG_Settings::get();
			$setting = $contract['apiType'] === 'diary' ? $site_settings['update_published_diaries'] : $site_settings['update_published_articles'];
			return empty($setting) ? 'setting_disabled' : 'missing_capability';
		}
		if (!self::is_ai_owner($post)) return 'ownership_denied';
		if (!(bool) get_post_meta($post->ID, '_jg_ai_editable', true) && !current_user_can('edit_post', $post->ID)) return 'edit_denied';
		return 'ok';
	}

	private static function available_operations(array $contract, WP_Post $post): array {
		$operations = array('read', 'deploymentStatus');
		if (self::can_update($contract, $post)) $operations[] = 'updateDraft';
		if ($post->post_status === 'draft' && self::can_publish($contract, $post)) {
			$operations[] = 'preparePublish';
			$operations[] = 'publish';
		}
		if (self::can_update_published($contract, $post)) {
			$operations[] = 'prepareUpdatePublished';
			$operations[] = 'updatePublished';
		}
		return $operations;
	}

	private static function published_at(WP_Post $post): ?string {
		$value = trim((string) $post->post_date_gmt);
		if ($value === '' || $value === '0000-00-00 00:00:00') return null;
		$timestamp = strtotime($value . ' GMT');
		return $timestamp === false ? null : gmdate('Y-m-d\\TH:i:s\\Z', $timestamp);
	}

	private static function normalize_input(array $input, array $contract, bool $creating) {
		$allowed = array('contentType', 'title', 'slug', 'excerpt', 'contentHtml', 'fields', 'idempotencyKey', 'expectedModifiedAt');
		foreach (array_keys($input) as $key) if (!in_array($key, $allowed, true)) return self::error('jg_ai_unknown_field', 'An unsupported request field was provided.', 400);
		$output = array();
		foreach (array('title', 'slug', 'excerpt', 'contentHtml') as $key) {
			if (!array_key_exists($key, $input)) continue;
			$value = $input[$key];
			if ($key === 'title') { $value = mb_substr(sanitize_text_field((string) $value), 0, 200); if ($value === '') return self::error('jg_ai_invalid_title', 'Title cannot be empty.', 400); }
			elseif ($key === 'slug') { if (preg_match('#[:/?\\\\]#', (string) $value)) return self::error('jg_ai_invalid_slug', 'Slug cannot contain a path, protocol, or query.', 400); $value = sanitize_title((string) $value); }
			elseif ($key === 'excerpt') { $value = mb_substr(wp_strip_all_tags((string) $value), 0, 1000); }
			else { $value = wp_kses_post((string) $value); }
			$output[$key] = $value;
		}
		if ($creating && empty($output['title'])) return self::error('jg_ai_invalid_title', 'Title is required.', 400);
		$fields = $input['fields'] ?? array(); if (!is_array($fields)) return self::error('jg_ai_invalid_fields', 'Fields must be an object.', 400);
		$definitions = self::contract_fields($contract);
		foreach ($fields as $key => $value) {
			if (!isset($definitions[$key])) return self::error('jg_ai_unknown_field', 'An unsupported content field was provided.', 400);
			if (($definitions[$key]['taxonomy'] ?? false) === true) {
				if (!is_array($value)) return self::error('jg_ai_invalid_fields', 'Taxonomy fields must be arrays of term IDs.', 400);
				$output['fields'][$key] = array_values(array_filter(array_unique(array_map('absint', $value))));
			} else $output['fields'][$key] = JG_Content_Types::sanitize_field($value, $definitions[$key]);
		}
		if (!isset($output['fields'])) $output['fields'] = array();
		return $output;
	}

	private static function normalize_draft_update(array $input) {
		$allowed = array('contentType', 'id', 'expectedModifiedAt', 'title', 'slug', 'excerpt', 'content');
		foreach (array_keys($input) as $key) if (!in_array($key, $allowed, true)) return self::error('jg_ai_unknown_field', 'An unsupported request field was provided.', 400);
		$output = array();
		foreach (array('title', 'slug', 'excerpt', 'content') as $key) {
			if (!array_key_exists($key, $input)) continue;
			if (!is_string($input[$key])) return self::error('jg_ai_invalid_field_type', 'Draft update fields must be strings.', 400);
			$value = $input[$key];
			if ($key === 'title') {
				$value = mb_substr(sanitize_text_field($value), 0, 200);
				if ($value === '') return self::error('jg_ai_invalid_title', 'Title cannot be empty.', 400);
			} elseif ($key === 'slug') {
				if (preg_match('#[:/?\\\\]#', $value)) return self::error('jg_ai_invalid_slug', 'Slug cannot contain a path, protocol, or query.', 400);
				$value = sanitize_title($value);
				if ($value === '') return self::error('jg_ai_invalid_slug', 'Slug cannot be empty.', 400);
			} elseif ($key === 'excerpt') {
				$value = mb_substr(wp_strip_all_tags($value), 0, 1000);
			} else {
				$value = wp_kses_post($value);
			}
			$output[$key] = $value;
		}
		if (!$output) return self::error('jg_ai_no_changes', 'At least one draft field must be provided.', 400);
		return $output;
	}

	private static function normalize_published_update(array $input) {
		$allowed = array('contentType', 'id', 'expectedModifiedAt', 'confirmationToken', 'idempotencyKey', 'proposedTitle', 'proposedExcerpt', 'proposedContent');
		foreach (array_keys($input) as $key) if (!in_array($key, $allowed, true)) return self::error('jg_ai_unknown_field', 'An unsupported request field was provided.', 400);
		$output = array();
		foreach (array('proposedTitle' => 'title', 'proposedExcerpt' => 'excerpt', 'proposedContent' => 'content') as $input_key => $output_key) {
			if (!array_key_exists($input_key, $input)) continue;
			if (!is_string($input[$input_key])) return self::error('jg_ai_invalid_field_type', 'Published update fields must be strings.', 400);
			$value = $input[$input_key];
			if ($output_key === 'title') {
				$value = mb_substr(sanitize_text_field($value), 0, 200);
				if ($value === '') return self::error('jg_ai_invalid_title', 'Title cannot be empty.', 400);
			} elseif ($output_key === 'excerpt') {
				$value = mb_substr(wp_strip_all_tags($value), 0, 1000);
			} else {
				if ($value === '') return self::error('jg_ai_invalid_content', 'Content cannot be empty.', 400);
				if (strlen($value) > JG_Content_Policy::MAX_RICH_TEXT_BYTES) return self::error('jg_ai_content_too_large', 'Content exceeds the size limit.', 413);
				$value = wp_kses_post($value);
			}
			$output[$output_key] = $value;
		}
		if (!$output) return self::error('jg_ai_no_changes', 'At least one published field must be provided.', 400);
		return $output;
	}

	private static function published_content_hash(array $normalized): string {
		return hash('sha256', wp_json_encode(array(
			'title' => $normalized['title'] ?? null,
			'excerpt' => $normalized['excerpt'] ?? null,
			'content' => $normalized['content'] ?? null,
		)));
	}

	private static function changed_published_fields(WP_Post $post, array $normalized): array {
		$changed = array();
		foreach (array('title' => 'post_title', 'excerpt' => 'post_excerpt', 'content' => 'post_content') as $input_key => $post_key) {
			if (isset($normalized[$input_key]) && $normalized[$input_key] !== $post->$post_key) $changed[] = $input_key;
		}
		return $changed;
	}

	private static function update_confirmation_phrase(string $content_type, int $post_id, string $fingerprint): string {
		$label = $content_type === 'article' ? '文章' : '日记';
		return sprintf('确认修改%s #%d %s', $label, $post_id, strtoupper(substr($fingerprint, 0, 6)));
	}

	private static function write_fields(int $post_id, array $contract, array $fields): bool { foreach ($fields as $key => $value) { if (($contract['taxonomy'] ?? false) && in_array($key, array('tags', 'categories'), true)) { if (is_wp_error(wp_set_post_terms($post_id, $value, $key === 'tags' ? 'post_tag' : 'category', false))) return false; continue; } if (update_post_meta($post_id, '_jg_' . $key, $value) === false && get_post_meta($post_id, '_jg_' . $key, true) !== $value) return false; } return true; }
	private static function modified_at(WP_Post $post): ?string {
		$value = trim((string) $post->post_modified_gmt);
		if ($value === '' || $value === '0000-00-00 00:00:00') return null;
		$timestamp = strtotime($value . ' GMT');
		return $timestamp === false ? null : gmdate('Y-m-d\\TH:i:s\\Z', $timestamp);
	}
	private static function modified_matches(WP_Post $post, $expected): bool { $current = self::modified_at($post); if ($current === null) return $expected === null; return is_string($expected) && hash_equals($current, $expected); }

	private static function issue_publish_token(WP_Post $post, string $content_type) {
		try {
			$token = bin2hex(random_bytes(32));
		} catch (Throwable $error) {
			return self::error('jg_ai_confirmation_token_unavailable', 'A publish confirmation could not be created.', 500);
		}

		$now = time();
		$hash = hash('sha256', $token);
		$entries = array_filter(
			(array) get_option(self::PUBLISH_TOKENS_OPTION, array()),
			static fn($entry) => is_array($entry) && (int) ($entry['expiresAt'] ?? 0) + DAY_IN_SECONDS >= $now
		);
		$entries[$hash] = array(
			'userId' => get_current_user_id(),
			'contentType' => $content_type,
			'contentId' => $post->ID,
			'expectedModifiedAt' => self::modified_at($post),
			'action' => 'publish',
			'createdAt' => $now,
			'expiresAt' => $now + self::PUBLISH_TOKEN_TTL,
			'usedAt' => null,
		);
		if (count($entries) > self::MAX_PUBLISH_TOKENS) {
			$entries = array_slice($entries, -self::MAX_PUBLISH_TOKENS, null, true);
		}
		if (!update_option(self::PUBLISH_TOKENS_OPTION, $entries, false)
			&& get_option(self::PUBLISH_TOKENS_OPTION, array()) !== $entries) {
			return self::error('jg_ai_confirmation_token_unavailable', 'A publish confirmation could not be created.', 500);
		}

		return array(
			'token' => $token,
			'expiresAt' => gmdate('Y-m-d\\TH:i:s\\Z', $now + self::PUBLISH_TOKEN_TTL),
			'fingerprint' => substr($hash, 0, 12),
		);
	}

	private static function validate_publish_token(string $hash, int $post_id, $expected, string $content_type) {
		$entries = (array) get_option(self::PUBLISH_TOKENS_OPTION, array());
		$entry = $entries[$hash] ?? null;
		if (!is_array($entry)) return self::error('jg_ai_confirmation_token_invalid', 'The publish confirmation token is invalid.', 403);
		if (!empty($entry['usedAt'])) return self::error('jg_ai_confirmation_token_used', 'The publish confirmation token has already been used.', 409);
		if ((int) ($entry['expiresAt'] ?? 0) <= time()) return self::error('jg_ai_confirmation_token_expired', 'The publish confirmation token has expired.', 410);
		if ((int) ($entry['userId'] ?? 0) !== get_current_user_id()
			|| ($entry['contentType'] ?? '') !== $content_type
			|| (int) ($entry['contentId'] ?? 0) !== $post_id
			|| ($entry['action'] ?? '') !== 'publish') {
			return self::error('jg_ai_confirmation_token_mismatch', 'The publish confirmation token does not match this request.', 403);
		}
		$bound = $entry['expectedModifiedAt'] ?? null;
		if (($bound === null && $expected !== null)
			|| (is_string($bound) && (!is_string($expected) || !hash_equals($bound, $expected)))) {
			return self::error('jg_ai_confirmation_token_conflict', 'The publish confirmation does not match the expected content version.', 409);
		}
		return true;
	}

	private static function consume_publish_token(string $hash): void {
		$entries = (array) get_option(self::PUBLISH_TOKENS_OPTION, array());
		if (!isset($entries[$hash]) || !is_array($entries[$hash])) return;
		$entries[$hash]['usedAt'] = time();
		update_option(self::PUBLISH_TOKENS_OPTION, $entries, false);
	}

	private static function issue_update_published_token(WP_Post $post, string $content_type, string $content_hash) {
		try {
			$token = bin2hex(random_bytes(32));
		} catch (Throwable $error) {
			return self::error('jg_ai_confirmation_token_unavailable', 'A confirmation token could not be created.', 500);
		}
		$now = time();
		$hash = hash('sha256', $token);
		$entries = array_filter((array) get_option(self::PUBLISH_TOKENS_OPTION, array()), static fn($entry) => is_array($entry) && (int) ($entry['expiresAt'] ?? 0) + DAY_IN_SECONDS >= $now);
		$entries[$hash] = array(
			'userId' => get_current_user_id(),
			'contentType' => $content_type,
			'contentId' => $post->ID,
			'expectedModifiedAt' => self::modified_at($post),
			'action' => self::UPDATE_PUBLISHED_ACTION,
			'contentHash' => $content_hash,
			'createdAt' => $now,
			'expiresAt' => $now + self::PUBLISH_TOKEN_TTL,
			'usedAt' => null,
		);
		if (count($entries) > self::MAX_PUBLISH_TOKENS) {
			$entries = array_slice($entries, -self::MAX_PUBLISH_TOKENS, null, true);
		}
		if (!update_option(self::PUBLISH_TOKENS_OPTION, $entries, false)
			&& get_option(self::PUBLISH_TOKENS_OPTION, array()) !== $entries) {
			return self::error('jg_ai_confirmation_token_unavailable', 'A confirmation token could not be created.', 500);
		}
		return array(
			'token' => $token,
			'expiresAt' => gmdate('Y-m-d\\TH:i:s\\Z', $now + self::PUBLISH_TOKEN_TTL),
			'fingerprint' => substr($hash, 0, 12),
		);
	}

	private static function validate_update_published_token(string $hash, int $post_id, $expected, string $content_type, string $content_hash) {
		$entries = (array) get_option(self::PUBLISH_TOKENS_OPTION, array());
		$entry = $entries[$hash] ?? null;
		if (!is_array($entry)) return self::error('jg_ai_confirmation_token_invalid', 'The confirmation token is invalid.', 403);
		if (!empty($entry['usedAt'])) return self::error('jg_ai_confirmation_token_used', 'The confirmation token has already been used.', 409);
		if ((int) ($entry['expiresAt'] ?? 0) <= time()) return self::error('jg_ai_confirmation_token_expired', 'The confirmation token has expired.', 410);
		if ((int) ($entry['userId'] ?? 0) !== get_current_user_id()
			|| ($entry['contentType'] ?? '') !== $content_type
			|| (int) ($entry['contentId'] ?? 0) !== $post_id
			|| ($entry['action'] ?? '') !== self::UPDATE_PUBLISHED_ACTION
			|| ($entry['contentHash'] ?? '') !== $content_hash) {
			return self::error('jg_ai_confirmation_token_mismatch', 'The confirmation token does not match this request.', 403);
		}
		$bound = $entry['expectedModifiedAt'] ?? null;
		if (($bound === null && $expected !== null)
			|| (is_string($bound) && (!is_string($expected) || !hash_equals($bound, $expected)))) {
			return self::error('jg_ai_confirmation_token_conflict', 'The confirmation does not match the expected content version.', 409);
		}
		return true;
	}

	private static function protected_fields(WP_Post $post): array {
		return array(
			'id' => (int) $post->ID,
			'postType' => $post->post_type,
			'slug' => $post->post_name,
			'status' => $post->post_status,
			'postDate' => $post->post_date,
			'postDateGmt' => $post->post_date_gmt,
			'postAuthor' => (int) $post->post_author,
			'aiOwner' => (int) get_post_meta($post->ID, '_jg_ai_owner_user_id', true),
			'aiCreated' => (bool) get_post_meta($post->ID, '_jg_ai_created', true),
			'editable' => (bool) get_post_meta($post->ID, '_jg_ai_editable', true),
			'publishable' => (bool) get_post_meta($post->ID, '_jg_ai_publishable', true),
		);
	}

	private static function protected_fields_match(array $before, WP_Post $after): bool {
		return $before === self::protected_fields($after);
	}

	private static function update_published_error(string $reason): WP_Error {
		switch ($reason) {
			case 'unsupported_type':
				return self::error('jg_ai_update_published_unsupported', 'In-place updates are only available for published diary and article content.', 403);
			case 'not_found':
				return self::not_found();
			case 'not_published':
				return self::error('jg_ai_update_published_not_published', 'Only published content can be updated in place.', 409);
			case 'setting_disabled':
				return self::error('jg_ai_update_published_disabled', 'In-place published updates are disabled for this content type.', 403);
			case 'missing_capability':
				return self::error('jg_ai_update_published_forbidden', 'You do not have permission to update published content.', 403);
			case 'ownership_denied':
				return self::error('jg_ai_update_published_ownership_required', 'You must be the author or AI owner of this content.', 403);
			case 'edit_denied':
				return self::error('jg_ai_update_published_not_editable', 'This content is not editable by the AI content editor.', 403);
		}
		return self::error('jg_ai_update_published_forbidden', 'In-place published updates are not authorized.', 403);
	}

	private static function project(WP_Post $post, array $contract, bool $detail): array {
		$result = array('id' => $post->ID, 'contentType' => $contract['apiType'], 'status' => $post->post_status, 'title' => $post->post_title, 'slug' => $post->post_name, 'modifiedAt' => self::modified_at($post));
		if (!$detail) return $result;
		$fields = array();
		foreach (self::contract_fields($contract) as $key => $definition) $fields[$key] = !empty($definition['taxonomy']) ? wp_get_post_terms($post->ID, $key === 'tags' ? 'post_tag' : 'category', array('fields' => 'ids')) : get_post_meta($post->ID, '_jg_' . $key, true);
		$owner_id = (int) get_post_meta($post->ID, '_jg_ai_owner_user_id', true);
		$result += array(
			'excerpt' => $post->post_excerpt,
			'contentHtml' => $post->post_content,
			'fields' => $fields,
			'editUrl' => admin_url('post.php?post=' . $post->ID . '&action=edit'),
			'previewUrl' => $post->post_status === 'publish' ? get_permalink($post) : null,
			'publishedAt' => self::published_at($post),
			'canonicalUrl' => self::get_canonical_public_url($contract['apiType'], $post),
			'ownership' => array(
				'isAuthor' => (int) $post->post_author === get_current_user_id(),
				'isAiOwner' => $owner_id > 0 && $owner_id === get_current_user_id(),
				'aiOwned' => self::is_ai_owner($post),
				'editable' => (bool) get_post_meta($post->ID, '_jg_ai_editable', true),
			),
			'availableOperations' => self::available_operations($contract, $post),
		);
		return $result;
	}
	private static function public_fields(array $contract): array { $can_update = in_array($contract['apiType'], array('diary', 'article'), true); $fields = array('title' => array('type' => 'string', 'required' => true, 'maxLength' => 200, 'create' => true, 'update' => $can_update), 'slug' => array('type' => 'string', 'required' => false, 'maxLength' => 200, 'create' => true, 'update' => $can_update), 'excerpt' => array('type' => 'string', 'required' => false, 'maxLength' => 1000, 'create' => true, 'update' => $can_update), 'contentHtml' => array('type' => 'html', 'required' => false, 'create' => true, 'update' => false)); if ($can_update) $fields['content'] = array('type' => 'html', 'required' => false, 'create' => false, 'update' => true); foreach (self::contract_fields($contract) as $key => $field) $fields[$key] = array_filter(array('type' => !empty($field['taxonomy']) ? 'array' : $field['type'], 'enum' => $field['options'] ?? null, 'minimum' => $field['min'] ?? null, 'maximum' => $field['max'] ?? null, 'create' => true, 'update' => false), static fn($value) => $value !== null); return $fields; }
	private static function contract_fields(array $contract): array { $fields = JG_Content_Types::field_definitions()[$contract['postType']] ?? array(); if (!empty($contract['taxonomy'])) { $fields['tags'] = array('type' => 'array', 'taxonomy' => true); $fields['categories'] = array('type' => 'array', 'taxonomy' => true); } return $fields; }
	private static function statuses($value): array { $allowed = array('draft', 'publish', 'pending', 'private', 'future'); return in_array($value, $allowed, true) ? array($value) : $allowed; }
	private static function idempotency_key(WP_REST_Request $request) { $key = $request->get_header('Idempotency-Key') ?: (string) $request->get_param('idempotencyKey'); return preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $key) ? $key : self::error('jg_ai_idempotency_required', 'A valid idempotency key is required.', 400); }
	private static function idempotency_replay(int $user_id, string $action, string $key, string $hash) { $entries = get_option(self::IDEMPOTENCY_OPTION, array()); $entry = $entries[$user_id . ':' . $action . ':' . $key] ?? null; if (!$entry || ($entry['expiresAt'] ?? 0) < time()) return null; return !hash_equals($entry['hash'], $hash) ? self::error('jg_ai_idempotency_conflict', 'This idempotency key was used with a different request.', 409) : $entry['result']; }
	private static function store_idempotency(int $user_id, string $action, string $key, string $hash, array $result, int $status): void { $entries = array_filter(get_option(self::IDEMPOTENCY_OPTION, array()), static fn($entry) => ($entry['expiresAt'] ?? 0) >= time()); $entries[$user_id . ':' . $action . ':' . $key] = array('hash' => $hash, 'result' => $result, 'status' => $status, 'createdAt' => time(), 'expiresAt' => time() + DAY_IN_SECONDS); if (count($entries) > self::MAX_IDEMPOTENCY_ENTRIES) $entries = array_slice($entries, -self::MAX_IDEMPOTENCY_ENTRIES, null, true); update_option(self::IDEMPOTENCY_OPTION, $entries, false); }
	private static function rate_limit(string $operation) { $limits = array('create' => 10, 'update' => 30, 'publish' => 5, 'prepareUpdatePublished' => 10, 'updatePublished' => 5, 'read' => 60, 'deploymentStatus' => 60, 'audit' => 60, 'claim' => 10); $limit = $limits[$operation] ?? 10; $key = 'jg_ai_rate_' . get_current_user_id() . '_' . $operation; $count = (int) get_transient($key); if ($count >= $limit) return self::error('jg_ai_rate_limited', 'Too many requests. Try again later.', 429, array('retryAfter' => 60)); set_transient($key, $count + 1, MINUTE_IN_SECONDS); return true; }
	private static function record(string $action, string $type, int $post_id, int $status, array $fields, bool $replay, array $details = array()): void {
		$safe_details = array();
		if (isset($details['reason']) && is_string($details['reason'])) $safe_details['reason'] = sanitize_key($details['reason']);
		foreach (array('tokenFingerprint', 'idempotencyFingerprint') as $key) {
			if (isset($details[$key]) && is_string($details[$key]) && preg_match('/^[a-f0-9]{12}$/', $details[$key]) === 1) $safe_details[$key] = $details[$key];
		}
		if (isset($details['state']) && is_string($details['state']) && preg_match('/^[a-z_]+$/', $details['state']) === 1) $safe_details['state'] = $details['state'];
		if (isset($details['workflowRunId']) && is_int($details['workflowRunId']) && $details['workflowRunId'] >= 0) $safe_details['workflowRunId'] = $details['workflowRunId'];
		$items = get_option(self::AUDIT_OPTION, array());
		$entry = array('at' => gmdate('c'), 'userId' => get_current_user_id(), 'action' => $action, 'contentType' => $type, 'postId' => $post_id, 'status' => $status, 'fields' => array_values($fields), 'idempotentReplay' => $replay, 'correlationId' => wp_generate_uuid4());
		if ($safe_details) $entry['details'] = $safe_details;
		$items[] = $entry;
		update_option(self::AUDIT_OPTION, array_slice($items, -self::MAX_AUDIT_ENTRIES), false);
	}

	public static function add_meta_box(): void { foreach (self::registry() as $contract) add_meta_box('jg_ai_content_access', 'AI Content Assistant', array(__CLASS__, 'render_meta_box'), $contract['postType'], 'side', 'default'); }
	public static function render_meta_box(WP_Post $post): void {
		if (!current_user_can('manage_options')) return;
		wp_nonce_field('jg_ai_content_access', 'jg_ai_content_access_nonce');
		echo '<p><label><input type="checkbox" name="jg_ai_editable" value="1" ' . checked((bool) get_post_meta($post->ID, '_jg_ai_editable', true), true, false) . '> Allow AI Content Assistant to edit</label></p><p><label><input type="checkbox" name="jg_ai_publishable" value="1" ' . checked((bool) get_post_meta($post->ID, '_jg_ai_publishable', true), true, false) . '> Allow AI Content Assistant to publish</label></p><p><strong>AI created:</strong> ' . esc_html(get_post_meta($post->ID, '_jg_ai_created', true) ? 'Yes' : 'No') . '</p><p><strong>AI owner:</strong> ' . esc_html((string) get_post_meta($post->ID, '_jg_ai_owner_user_id', true)) . '</p>';
		$owner_id = (int) get_post_meta($post->ID, '_jg_ai_owner_user_id', true);
		if ((bool) get_post_meta($post->ID, '_jg_ai_created', true) && $owner_id > 0 && get_userdata($owner_id) && (int) $post->post_author !== $owner_id) {
			echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:8px"><input type="hidden" name="action" value="jg_ai_sync_owner"><input type="hidden" name="post_id" value="' . esc_attr((string) $post->ID) . '">';
			wp_nonce_field('jg_ai_sync_owner', 'jg_ai_sync_owner_nonce');
			echo '<button class="button">同步作者为 AI 所有者</button></form>';
		}
	}
	public static function save_claim(int $post_id, WP_Post $post): void { if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id) || !isset($_POST['jg_ai_content_access_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['jg_ai_content_access_nonce'])), 'jg_ai_content_access') || !current_user_can('manage_options')) return; if (!isset(array_flip(array_column(self::registry(), 'postType'))[$post->post_type])) return; update_post_meta($post_id, '_jg_ai_editable', !empty($_POST['jg_ai_editable'])); update_post_meta($post_id, '_jg_ai_publishable', !empty($_POST['jg_ai_publishable'])); }
	public static function handle_sync_owner(): void {
		if (!current_user_can('manage_options') || !check_admin_referer('jg_ai_sync_owner', 'jg_ai_sync_owner_nonce')) wp_die('Invalid request.');
		$post_id = absint($_POST['post_id'] ?? 0);
		$repaired = self::repair_ai_ownership($post_id);
		$target = $post_id > 0 ? admin_url('post.php?post=' . $post_id . '&action=edit') : admin_url('edit.php');
		wp_safe_redirect(add_query_arg('jg_ai_sync', $repaired ? '1' : '0', $target));
		exit;
	}
	public static function repair_ai_ownership(int $post_id): bool {
		$post = get_post($post_id);
		if (!$post) return false;
		if (!in_array($post->post_type, array_column(self::registry(), 'postType'), true)) return false;
		$owner_id = (int) get_post_meta($post_id, '_jg_ai_owner_user_id', true);
		if ($owner_id <= 0 || !get_userdata($owner_id)) return false;
		if (!(bool) get_post_meta($post_id, '_jg_ai_created', true)) return false;
		if ((int) $post->post_author === $owner_id) return true;
		$updated = wp_update_post(array('ID' => $post_id, 'post_author' => $owner_id), true);
		if (is_wp_error($updated)) return false;
		clean_post_cache($post_id);
		return true;
	}
	private static function render_audit(): void { $items = get_option(self::AUDIT_OPTION, array()); echo '<table class="widefat"><thead><tr><th>Time</th><th>Action</th><th>Type</th><th>Content</th><th>Status</th></tr></thead><tbody>'; foreach (array_reverse($items) as $item) echo '<tr><td>' . esc_html($item['at']) . '</td><td>' . esc_html($item['action']) . '</td><td>' . esc_html($item['contentType']) . '</td><td>' . esc_html((string) $item['postId']) . '</td><td>' . esc_html((string) $item['status']) . '</td></tr>'; echo '</tbody></table><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'; wp_nonce_field('jg_ai_clear_audit'); echo '<input type="hidden" name="action" value="jg_ai_clear_audit"><p><button class="button">Clear audit log</button></p></form>'; }
	public static function clear_audit(): void { if (!current_user_can('manage_options') || !check_admin_referer('jg_ai_clear_audit')) wp_die('Invalid request.'); update_option(self::AUDIT_OPTION, array(), false); wp_safe_redirect(admin_url('options-general.php?page=jg-ai-content')); exit; }
	private static function list_args(): array { return array('contentType' => array('sanitize_callback' => 'sanitize_text_field'), 'status' => array('sanitize_callback' => 'sanitize_key'), 'search' => array('sanitize_callback' => 'sanitize_text_field'), 'slug' => array('sanitize_callback' => 'sanitize_title'), 'page' => array('validate_callback' => static fn($v) => is_numeric($v)), 'perPage' => array('validate_callback' => static fn($v) => is_numeric($v))); }
	private static function create_args(): array { return array('contentType' => array('required' => true, 'sanitize_callback' => 'sanitize_key'), 'idempotencyKey' => array('sanitize_callback' => 'sanitize_text_field')); }
	private static function detail_args(): array { return array('contentType' => array('validate_callback' => static fn($v) => is_string($v)), 'id' => array('validate_callback' => static fn($v) => ctype_digit((string) $v))); }
	private static function update_args(): array { return self::detail_args() + array('expectedModifiedAt' => array('required' => false, 'validate_callback' => static fn($value) => $value === null || (is_string($value) && preg_match('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}Z$/', $value) === 1))); }
	private static function publish_args(): array { return self::detail_args() + array(
		'expectedModifiedAt' => array('required' => false, 'validate_callback' => static fn($value) => $value === null || (is_string($value) && preg_match('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}Z$/', $value) === 1)),
		'confirmationToken' => array('required' => false, 'validate_callback' => static fn($value) => is_string($value)),
		'idempotencyKey' => array('required' => false, 'sanitize_callback' => 'sanitize_text_field'),
	); }
	private static function expected_args(): array { return self::detail_args() + array('expectedModifiedAt' => array('required' => true, 'sanitize_callback' => 'sanitize_text_field')); }
	private static function claim_args(): array { return self::detail_args() + array('editable' => array('sanitize_callback' => 'rest_sanitize_boolean'), 'publishable' => array('sanitize_callback' => 'rest_sanitize_boolean')); }
}
