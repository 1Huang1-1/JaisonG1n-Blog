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
	private const MAX_AUDIT_ENTRIES = 100;
	private const MAX_IDEMPOTENCY_ENTRIES = 200;

	public static function init(): void {
		add_action('rest_api_init', array(__CLASS__, 'register_routes'));
		add_action('add_meta_boxes', array(__CLASS__, 'add_meta_box'));
		add_action('save_post', array(__CLASS__, 'save_claim'), 20, 2);
		add_action('admin_menu', array(__CLASS__, 'add_settings_page'));
		add_action('admin_init', array(__CLASS__, 'register_settings'));
		add_action('admin_post_jg_ai_clear_audit', array(__CLASS__, 'clear_audit'));
		add_action('admin_init', array(__CLASS__, 'install'));
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
		// Publish and destructive capabilities are intentionally never granted here.
	}

	public static function settings(): array {
		$defaults = array('enabled' => true, 'create_drafts' => true, 'update_drafts' => true, 'allow_claims' => true, 'allow_publish' => false);
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
		<?php foreach (array('enabled' => 'Enable AI Content API', 'create_drafts' => 'Allow draft creation', 'update_drafts' => 'Allow draft updates', 'allow_claims' => 'Allow administrator content claims', 'allow_publish' => 'Allow AI publishing') as $key => $label) : ?>
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
		self::route($namespace, '/content/(?P<contentType>[A-Za-z][A-Za-z0-9]*)/(?P<id>\d+)/publish', WP_REST_Server::CREATABLE, 'publish_content', 'publish', self::expected_args());
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
			if (self::can_update($contract, null)) $operations[] = 'updateDraft';
			if (self::can_publish($contract, null)) $operations[] = 'publish';
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
		if (is_array($replay)) return new WP_REST_Response($replay + array('idempotentReplay' => true), 200);
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
		$post = self::post_for_contract((int) $request['id'], $contract); if (is_wp_error($post)) return $post;
		if (!self::can_update($contract, $post)) return self::not_found();
		if ($post->post_status !== 'draft') return self::error('jg_ai_published_update_requires_confirmation', 'Published content cannot be changed through the draft update endpoint.', 409, array('requiresConfirmation' => true));
		$input = $request->get_json_params(); if (!is_array($input)) $input = $request->get_params();
		if (!self::modified_matches($post, $input['expectedModifiedAt'] ?? '')) return self::error('jg_ai_stale_content', 'Content has changed. Read it again before updating.', 409);
		$normalized = self::normalize_input($input, $contract, false); if (is_wp_error($normalized)) return $normalized;
		$data = array('ID' => $post->ID);
		foreach (array('title' => 'post_title', 'slug' => 'post_name', 'excerpt' => 'post_excerpt', 'contentHtml' => 'post_content') as $input_key => $post_key) if (array_key_exists($input_key, $normalized)) $data[$post_key] = $normalized[$input_key];
		$result = wp_update_post($data, true); if (is_wp_error($result)) return self::safe_wp_error($result);
		if (!self::write_fields($post->ID, $contract, $normalized['fields'])) return self::error('jg_ai_update_failed', 'The content could not be saved.', 500);
		$updated = get_post($post->ID); self::record('updateDraft', $contract['apiType'], $post->ID, 200, array_keys($normalized['fields']), false);
		return new WP_REST_Response(self::project($updated, $contract, true), 200);
	}

	public static function publish_content(WP_REST_Request $request) { return self::change_status($request, 'publish'); }
	public static function unpublish_content(WP_REST_Request $request) { return self::change_status($request, 'draft'); }

	private static function change_status(WP_REST_Request $request, string $status) {
		$contract = self::contract((string) $request['contentType']); if (is_wp_error($contract)) return $contract;
		$post = self::post_for_contract((int) $request['id'], $contract); if (is_wp_error($post)) return $post;
		if (!self::can_publish($contract, $post)) return self::error('jg_ai_publish_forbidden', 'Publishing is not allowed for this content.', 403);
		if (!self::modified_matches($post, $request->get_param('expectedModifiedAt'))) return self::error('jg_ai_stale_content', 'Content has changed. Read it again before updating.', 409);
		$result = wp_update_post(array('ID' => $post->ID, 'post_status' => $status), true); if (is_wp_error($result)) return self::safe_wp_error($result);
		$updated = get_post($post->ID); self::record($status === 'publish' ? 'publish' : 'unpublish', $contract['apiType'], $post->ID, 200, array(), false);
		return new WP_REST_Response(self::project($updated, $contract, true), 200);
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

	private static function contract(string $type) { $all = self::registry(); return $all[$type] ?? self::error('jg_ai_unsupported_content_type', 'This content type is not supported.', 400); }
	private static function post_for_contract(int $id, array $contract) { $post = get_post($id); return (!$post || $post->post_type !== $contract['postType']) ? self::not_found() : $post; }
	private static function not_found(): WP_Error { return self::error('jg_ai_content_not_found', 'Content was not found.', 404); }
	private static function error(string $code, string $message, int $status, array $extra = array()): WP_Error { return new WP_Error($code, $message, array_merge(array('status' => $status, 'correlationId' => wp_generate_uuid4()), $extra)); }
	private static function safe_wp_error(WP_Error $error): WP_Error { return self::error('jg_ai_content_write_failed', 'The content could not be saved.', 400); }

	private static function can_create(array $contract): bool { $object = get_post_type_object($contract['postType']); return !empty(self::settings()['create_drafts']) && $object && current_user_can($object->cap->create_posts); }
	private static function can_read(array $contract, ?WP_Post $post): bool { return $post === null || (int) $post->post_author === get_current_user_id() || (bool) get_post_meta($post->ID, '_jg_ai_editable', true); }
	private static function can_update(array $contract, ?WP_Post $post): bool { return !empty(self::settings()['update_drafts']) && $post !== null && current_user_can('edit_post', $post->ID) && self::can_read($contract, $post); }
	private static function can_publish(array $contract, ?WP_Post $post): bool { $object = get_post_type_object($contract['postType']); return !empty(self::settings()['allow_publish']) && $post !== null && current_user_can($object->cap->publish_posts) && self::can_read($contract, $post) && (bool) get_post_meta($post->ID, '_jg_ai_publishable', true); }

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

	private static function write_fields(int $post_id, array $contract, array $fields): bool { foreach ($fields as $key => $value) { if (($contract['taxonomy'] ?? false) && in_array($key, array('tags', 'categories'), true)) { if (is_wp_error(wp_set_post_terms($post_id, $value, $key === 'tags' ? 'post_tag' : 'category', false))) return false; continue; } if (update_post_meta($post_id, '_jg_' . $key, $value) === false && get_post_meta($post_id, '_jg_' . $key, true) !== $value) return false; } return true; }
	private static function modified_matches(WP_Post $post, $expected): bool { return is_string($expected) && hash_equals(gmdate('Y-m-d\\TH:i:s\\Z', strtotime($post->post_modified_gmt . ' GMT')), $expected); }
	private static function project(WP_Post $post, array $contract, bool $detail): array { $result = array('id' => $post->ID, 'contentType' => $contract['apiType'], 'status' => $post->post_status, 'title' => $post->post_title, 'slug' => $post->post_name, 'modifiedAt' => gmdate('Y-m-d\\TH:i:s\\Z', strtotime($post->post_modified_gmt . ' GMT'))); if (!$detail) return $result; $fields = array(); foreach (self::contract_fields($contract) as $key => $definition) $fields[$key] = !empty($definition['taxonomy']) ? wp_get_post_terms($post->ID, $key === 'tags' ? 'post_tag' : 'category', array('fields' => 'ids')) : get_post_meta($post->ID, '_jg_' . $key, true); $result += array('excerpt' => $post->post_excerpt, 'contentHtml' => $post->post_content, 'fields' => $fields, 'editUrl' => admin_url('post.php?post=' . $post->ID . '&action=edit'), 'previewUrl' => $post->post_status === 'publish' ? get_permalink($post) : null); return $result; }
	private static function public_fields(array $contract): array { $fields = array('title' => array('type' => 'string', 'required' => true, 'maxLength' => 200, 'create' => true, 'update' => true), 'slug' => array('type' => 'string', 'required' => false, 'maxLength' => 200, 'create' => true, 'update' => true), 'excerpt' => array('type' => 'string', 'required' => false, 'maxLength' => 1000, 'create' => true, 'update' => true), 'contentHtml' => array('type' => 'html', 'required' => false, 'create' => true, 'update' => true)); foreach (self::contract_fields($contract) as $key => $field) $fields[$key] = array_filter(array('type' => !empty($field['taxonomy']) ? 'array' : $field['type'], 'enum' => $field['options'] ?? null, 'minimum' => $field['min'] ?? null, 'maximum' => $field['max'] ?? null, 'create' => true, 'update' => true), static fn($value) => $value !== null); return $fields; }
	private static function contract_fields(array $contract): array { $fields = JG_Content_Types::field_definitions()[$contract['postType']] ?? array(); if (!empty($contract['taxonomy'])) { $fields['tags'] = array('type' => 'array', 'taxonomy' => true); $fields['categories'] = array('type' => 'array', 'taxonomy' => true); } return $fields; }
	private static function statuses($value): array { $allowed = array('draft', 'publish', 'pending', 'private', 'future'); return in_array($value, $allowed, true) ? array($value) : $allowed; }
	private static function idempotency_key(WP_REST_Request $request) { $key = $request->get_header('Idempotency-Key') ?: (string) $request->get_param('idempotencyKey'); return preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $key) ? $key : self::error('jg_ai_idempotency_required', 'A valid idempotency key is required.', 400); }
	private static function idempotency_replay(int $user_id, string $action, string $key, string $hash) { $entries = get_option(self::IDEMPOTENCY_OPTION, array()); $entry = $entries[$user_id . ':' . $action . ':' . $key] ?? null; if (!$entry || ($entry['expiresAt'] ?? 0) < time()) return null; return !hash_equals($entry['hash'], $hash) ? self::error('jg_ai_idempotency_conflict', 'This idempotency key was used with a different request.', 409) : $entry['result']; }
	private static function store_idempotency(int $user_id, string $action, string $key, string $hash, array $result, int $status): void { $entries = array_filter(get_option(self::IDEMPOTENCY_OPTION, array()), static fn($entry) => ($entry['expiresAt'] ?? 0) >= time()); $entries[$user_id . ':' . $action . ':' . $key] = array('hash' => $hash, 'result' => $result, 'status' => $status, 'createdAt' => time(), 'expiresAt' => time() + DAY_IN_SECONDS); if (count($entries) > self::MAX_IDEMPOTENCY_ENTRIES) $entries = array_slice($entries, -self::MAX_IDEMPOTENCY_ENTRIES, null, true); update_option(self::IDEMPOTENCY_OPTION, $entries, false); }
	private static function rate_limit(string $operation) { $limits = array('create' => 10, 'update' => 30, 'publish' => 5, 'read' => 60, 'audit' => 60, 'claim' => 10); $limit = $limits[$operation] ?? 10; $key = 'jg_ai_rate_' . get_current_user_id() . '_' . $operation; $count = (int) get_transient($key); if ($count >= $limit) return self::error('jg_ai_rate_limited', 'Too many requests. Try again later.', 429, array('retryAfter' => 60)); set_transient($key, $count + 1, MINUTE_IN_SECONDS); return true; }
	private static function record(string $action, string $type, int $post_id, int $status, array $fields, bool $replay): void { $items = get_option(self::AUDIT_OPTION, array()); $items[] = array('at' => gmdate('c'), 'userId' => get_current_user_id(), 'action' => $action, 'contentType' => $type, 'postId' => $post_id, 'status' => $status, 'fields' => array_values($fields), 'idempotentReplay' => $replay, 'correlationId' => wp_generate_uuid4()); update_option(self::AUDIT_OPTION, array_slice($items, -self::MAX_AUDIT_ENTRIES), false); }

	public static function add_meta_box(): void { foreach (self::registry() as $contract) add_meta_box('jg_ai_content_access', 'AI Content Assistant', array(__CLASS__, 'render_meta_box'), $contract['postType'], 'side', 'default'); }
	public static function render_meta_box(WP_Post $post): void { if (!current_user_can('manage_options')) return; wp_nonce_field('jg_ai_content_access', 'jg_ai_content_access_nonce'); echo '<p><label><input type="checkbox" name="jg_ai_editable" value="1" ' . checked((bool) get_post_meta($post->ID, '_jg_ai_editable', true), true, false) . '> Allow AI Content Assistant to edit</label></p><p><label><input type="checkbox" name="jg_ai_publishable" value="1" ' . checked((bool) get_post_meta($post->ID, '_jg_ai_publishable', true), true, false) . '> Allow AI Content Assistant to publish</label></p><p><strong>AI created:</strong> ' . esc_html(get_post_meta($post->ID, '_jg_ai_created', true) ? 'Yes' : 'No') . '</p><p><strong>AI owner:</strong> ' . esc_html((string) get_post_meta($post->ID, '_jg_ai_owner_user_id', true)) . '</p>'; }
	public static function save_claim(int $post_id, WP_Post $post): void { if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id) || !isset($_POST['jg_ai_content_access_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['jg_ai_content_access_nonce'])), 'jg_ai_content_access') || !current_user_can('manage_options')) return; if (!isset(array_flip(array_column(self::registry(), 'postType'))[$post->post_type])) return; update_post_meta($post_id, '_jg_ai_editable', !empty($_POST['jg_ai_editable'])); update_post_meta($post_id, '_jg_ai_publishable', !empty($_POST['jg_ai_publishable'])); }
	private static function render_audit(): void { $items = get_option(self::AUDIT_OPTION, array()); echo '<table class="widefat"><thead><tr><th>Time</th><th>Action</th><th>Type</th><th>Content</th><th>Status</th></tr></thead><tbody>'; foreach (array_reverse($items) as $item) echo '<tr><td>' . esc_html($item['at']) . '</td><td>' . esc_html($item['action']) . '</td><td>' . esc_html($item['contentType']) . '</td><td>' . esc_html((string) $item['postId']) . '</td><td>' . esc_html((string) $item['status']) . '</td></tr>'; echo '</tbody></table><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'; wp_nonce_field('jg_ai_clear_audit'); echo '<input type="hidden" name="action" value="jg_ai_clear_audit"><p><button class="button">Clear audit log</button></p></form>'; }
	public static function clear_audit(): void { if (!current_user_can('manage_options') || !check_admin_referer('jg_ai_clear_audit')) wp_die('Invalid request.'); update_option(self::AUDIT_OPTION, array(), false); wp_safe_redirect(admin_url('options-general.php?page=jg-ai-content')); exit; }
	private static function list_args(): array { return array('contentType' => array('sanitize_callback' => 'sanitize_text_field'), 'status' => array('sanitize_callback' => 'sanitize_key'), 'search' => array('sanitize_callback' => 'sanitize_text_field'), 'slug' => array('sanitize_callback' => 'sanitize_title'), 'page' => array('validate_callback' => static fn($v) => is_numeric($v)), 'perPage' => array('validate_callback' => static fn($v) => is_numeric($v))); }
	private static function create_args(): array { return array('contentType' => array('required' => true, 'sanitize_callback' => 'sanitize_key'), 'idempotencyKey' => array('sanitize_callback' => 'sanitize_text_field')); }
	private static function detail_args(): array { return array('contentType' => array('validate_callback' => static fn($v) => is_string($v)), 'id' => array('validate_callback' => static fn($v) => ctype_digit((string) $v))); }
	private static function update_args(): array { return self::detail_args() + array('expectedModifiedAt' => array('required' => true, 'sanitize_callback' => 'sanitize_text_field')); }
	private static function expected_args(): array { return self::detail_args() + array('expectedModifiedAt' => array('required' => true, 'sanitize_callback' => 'sanitize_text_field')); }
	private static function claim_args(): array { return self::detail_args() + array('editable' => array('sanitize_callback' => 'rest_sanitize_boolean'), 'publishable' => array('sanitize_callback' => 'rest_sanitize_boolean')); }
}
