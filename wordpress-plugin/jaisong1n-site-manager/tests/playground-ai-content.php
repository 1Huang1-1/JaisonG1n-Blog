<?php

if (!defined('ABSPATH')) require_once '/wordpress/wp-load.php';
require_once dirname(__DIR__) . '/jaisong1n-site-manager.php';

$jg_ai_assertions = 0;
function jg_ai_test_assert(bool $condition, string $message): void {
	global $jg_ai_assertions;
	$jg_ai_assertions++;
	if (!$condition) throw new RuntimeException($message);
}
function jg_ai_test_request(string $method, string $route, array $body = array(), array $headers = array()): WP_REST_Response {
	$request = new WP_REST_Request($method, $route);
	foreach ($headers as $name => $value) $request->set_header($name, $value);
	if ($body) $request->set_body_params($body);
	return rest_do_request($request);
}
function jg_ai_test_error_code(WP_REST_Response $response): string {
	$data = $response->get_data();
	return is_array($data) ? (string) ($data['code'] ?? '') : '';
}
function jg_ai_test_clear_publish_limit(int $user_id): void {
	delete_transient('jg_ai_rate_' . $user_id . '_publish');
}
function jg_ai_test_prepare(int $user_id, int $post_id): WP_REST_Response {
	jg_ai_test_clear_publish_limit($user_id);
	return jg_ai_test_request('POST', '/jaisong1n/v1/ai/content/diary/' . $post_id . '/prepare-publish');
}
function jg_ai_test_publish(int $user_id, int $post_id, array $body, string $key): WP_REST_Response {
	jg_ai_test_clear_publish_limit($user_id);
	$body['idempotencyKey'] = $key;
	return jg_ai_test_request('POST', '/jaisong1n/v1/ai/content/diary/' . $post_id . '/publish', $body, array('Idempotency-Key' => $key));
}

JG_Content_Types::register();
JG_AI_Content::install();
do_action('rest_api_init');

$user_id = wp_create_user('jg-ai-draft-editor', wp_generate_password(24), 'jg-ai-draft-editor@example.test');
jg_ai_test_assert(!is_wp_error($user_id), 'Could not create the AI content editor test user.');
$user = new WP_User((int) $user_id);
$user->set_role('jg_ai_content_editor');
wp_set_current_user((int) $user_id);
$diary_object = get_post_type_object('jg_diary');

$capabilities = jg_ai_test_request('GET', '/jaisong1n/v1/ai/capabilities');
jg_ai_test_assert($capabilities->get_status() === 200, 'Capabilities failed.');
$capability_data = $capabilities->get_data();
jg_ai_test_assert(isset($capability_data['contentTypes']['article']), 'Article capability is missing.');
jg_ai_test_assert(in_array('updateDraft', $capability_data['contentTypes']['diary']['operations'], true), 'Diary updateDraft capability is missing.');
jg_ai_test_assert(!in_array('updateDraft', $capability_data['contentTypes']['article']['operations'], true), 'Article must not expose updateDraft.');
jg_ai_test_assert(($capability_data['contentTypes']['diary']['fields']['content']['update'] ?? false) === true, 'Diary content update field is missing.');
jg_ai_test_assert(($capability_data['contentTypes']['diary']['fields']['contentHtml']['update'] ?? true) === false, 'Diary contentHtml must not be updateable.');
jg_ai_test_assert(!in_array('preparePublish', $capability_data['contentTypes']['diary']['operations'], true) && !in_array('publish', $capability_data['contentTypes']['diary']['operations'], true), 'Reviewed publishing must not be exposed by default.');
jg_ai_test_assert(!$user->has_cap('jg_ai_publish_diary_drafts') && !$user->has_cap($diary_object->cap->publish_posts), 'AI editor received a publish capability by default.');
jg_ai_test_assert(!str_contains(wp_json_encode($capability_data), '_jg_'), 'Capabilities expose internal meta keys.');

$article_body = array('contentType' => 'article', 'title' => 'AI draft', 'contentHtml' => '<p>safe</p><script>bad()</script>', 'idempotencyKey' => 'playground-ai-content-0001');
$article = jg_ai_test_request('POST', '/jaisong1n/v1/ai/content', $article_body, array('Idempotency-Key' => 'playground-ai-content-0001'));
jg_ai_test_assert($article->get_status() === 201, 'Article draft creation failed.');
$article_data = $article->get_data();
$replayed = jg_ai_test_request('POST', '/jaisong1n/v1/ai/content', $article_body, array('Idempotency-Key' => 'playground-ai-content-0001'));
jg_ai_test_assert($replayed->get_status() === 200 && $replayed->get_data()['id'] === $article_data['id'], 'Idempotency replay failed.');
$conflict = jg_ai_test_request('POST', '/jaisong1n/v1/ai/content', array_merge($article_body, array('title' => 'Different')), array('Idempotency-Key' => 'playground-ai-content-0001'));
jg_ai_test_assert($conflict->get_status() === 409, 'Idempotency conflict was not rejected.');
$article_update = jg_ai_test_request('PATCH', '/jaisong1n/v1/ai/content/article/' . $article_data['id'], array('expectedModifiedAt' => '2025-01-01T00:00:00Z', 'title' => 'Forbidden'));
jg_ai_test_assert($article_update->get_status() === 403 && jg_ai_test_error_code($article_update) === 'jg_ai_update_draft_unsupported', 'Non-diary draft update was not rejected.');

$diary_body = array('contentType' => 'diary', 'title' => 'Diary draft', 'slug' => 'diary-draft', 'excerpt' => 'Initial excerpt', 'contentHtml' => '<p>Initial body</p>', 'idempotencyKey' => 'playground-ai-diary-0001');
$created = jg_ai_test_request('POST', '/jaisong1n/v1/ai/content', $diary_body, array('Idempotency-Key' => 'playground-ai-diary-0001'));
jg_ai_test_assert($created->get_status() === 201, 'Diary draft creation failed.');
$created_data = $created->get_data();
jg_ai_test_assert($created_data['status'] === 'draft' && $created_data['id'] > 0, 'Diary draft result is invalid.');

global $wpdb;
$wpdb->update($wpdb->posts, array('post_modified' => '2025-01-01 00:00:00', 'post_modified_gmt' => '2025-01-01 00:00:00'), array('ID' => $created_data['id']));
clean_post_cache($created_data['id']);
$current = jg_ai_test_request('GET', '/jaisong1n/v1/ai/content/diary/' . $created_data['id']);
$current_data = $current->get_data();
jg_ai_test_assert($current->get_status() === 200 && $current_data['modifiedAt'] === '2025-01-01T00:00:00Z', 'Valid modifiedAt was not normalized to UTC.');

delete_option('jg_dispatch_pending');
wp_clear_scheduled_hook(JG_Dispatch::CRON_HOOK);

$title_update = jg_ai_test_request('PATCH', '/jaisong1n/v1/ai/content/diary/' . $created_data['id'], array('expectedModifiedAt' => $current_data['modifiedAt'], 'title' => 'Updated diary title'));
jg_ai_test_assert($title_update->get_status() === 200 && $title_update->get_data()['title'] === 'Updated diary title', 'Diary title update failed.');
$after_title = $title_update->get_data();

$content_update = jg_ai_test_request('PATCH', '/jaisong1n/v1/ai/content/diary/' . $created_data['id'], array('expectedModifiedAt' => $after_title['modifiedAt'], 'content' => '<p>Updated body</p><script>bad()</script>'));
$updated_content = (string) ($content_update->get_data()['contentHtml'] ?? '');
jg_ai_test_assert($content_update->get_status() === 200 && str_contains($updated_content, '<p>Updated body</p>') && !str_contains($updated_content, '<script'), 'Diary content update or HTML cleanup failed.');
$after_content = $content_update->get_data();

$multi_update = jg_ai_test_request('PATCH', '/jaisong1n/v1/ai/content/diary/' . $created_data['id'], array('expectedModifiedAt' => $after_content['modifiedAt'], 'excerpt' => '<b>Updated excerpt</b>', 'slug' => 'updated-diary-slug'));
$multi_data = $multi_update->get_data();
jg_ai_test_assert($multi_update->get_status() === 200 && $multi_data['excerpt'] === 'Updated excerpt' && $multi_data['slug'] === 'updated-diary-slug', 'Multi-field diary update failed.');
jg_ai_test_assert($multi_data['status'] === 'draft' && is_string($multi_data['editUrl']) && $multi_data['editUrl'] !== '', 'Diary update response is incomplete.');

$read_back = jg_ai_test_request('GET', '/jaisong1n/v1/ai/content/diary/' . $created_data['id']);
$read_back_data = $read_back->get_data();
jg_ai_test_assert($read_back->get_status() === 200 && $read_back_data['title'] === 'Updated diary title' && $read_back_data['contentHtml'] === $updated_content && $read_back_data['excerpt'] === 'Updated excerpt' && $read_back_data['slug'] === 'updated-diary-slug', 'Updated diary did not read back consistently.');
jg_ai_test_assert($read_back_data['status'] === 'draft', 'Draft update changed publication status.');
jg_ai_test_assert(get_option('jg_dispatch_pending', false) === false && !wp_next_scheduled(JG_Dispatch::CRON_HOOK), 'Draft update scheduled a production build.');

$no_fields = jg_ai_test_request('PATCH', '/jaisong1n/v1/ai/content/diary/' . $created_data['id'], array('expectedModifiedAt' => $read_back_data['modifiedAt']));
jg_ai_test_assert($no_fields->get_status() === 400 && jg_ai_test_error_code($no_fields) === 'jg_ai_no_changes', 'Missing diary update fields were not rejected.');
$same_value = jg_ai_test_request('PATCH', '/jaisong1n/v1/ai/content/diary/' . $created_data['id'], array('expectedModifiedAt' => $read_back_data['modifiedAt'], 'title' => $read_back_data['title']));
jg_ai_test_assert($same_value->get_status() === 400 && jg_ai_test_error_code($same_value) === 'jg_ai_no_changes', 'Unchanged diary value was not rejected.');
$missing_expected = jg_ai_test_request('PATCH', '/jaisong1n/v1/ai/content/diary/' . $created_data['id'], array('title' => 'No lock'));
jg_ai_test_assert($missing_expected->get_status() === 400, 'Missing expectedModifiedAt was not rejected.');
$stale = jg_ai_test_request('PATCH', '/jaisong1n/v1/ai/content/diary/' . $created_data['id'], array('expectedModifiedAt' => '2000-01-01T00:00:00Z', 'title' => 'Stale overwrite'));
jg_ai_test_assert($stale->get_status() === 409 && jg_ai_test_error_code($stale) === 'jg_ai_stale_content', 'Stale diary update was not rejected.');

foreach (array('status' => 'publish', 'author' => 1, 'meta' => array('secret' => true)) as $field => $value) {
	$injection = jg_ai_test_request('PATCH', '/jaisong1n/v1/ai/content/diary/' . $created_data['id'], array('expectedModifiedAt' => $read_back_data['modifiedAt'], $field => $value));
	jg_ai_test_assert($injection->get_status() === 400 && jg_ai_test_error_code($injection) === 'jg_ai_unknown_field', $field . ' injection was not rejected.');
}

$missing = jg_ai_test_request('PATCH', '/jaisong1n/v1/ai/content/diary/999999', array('expectedModifiedAt' => $read_back_data['modifiedAt'], 'title' => 'Missing'));
jg_ai_test_assert($missing->get_status() === 404, 'Missing diary content was not rejected.');

$published_id = wp_insert_post(array('post_type' => 'jg_diary', 'post_status' => 'publish', 'post_author' => (int) $user_id, 'post_title' => 'Published diary'));
$published_read = jg_ai_test_request('GET', '/jaisong1n/v1/ai/content/diary/' . $published_id)->get_data();
$published_update = jg_ai_test_request('PATCH', '/jaisong1n/v1/ai/content/diary/' . $published_id, array('expectedModifiedAt' => $published_read['modifiedAt'], 'title' => 'Forbidden published update'));
jg_ai_test_assert($published_update->get_status() === 409 && jg_ai_test_error_code($published_update) === 'jg_ai_draft_required', 'Non-draft diary update was not rejected.');

$subscriber_id = wp_create_user('jg-ai-subscriber', wp_generate_password(24), 'jg-ai-subscriber@example.test');
jg_ai_test_assert(!is_wp_error($subscriber_id), 'Could not create the insufficient-permission test user.');
(new WP_User((int) $subscriber_id))->set_role('subscriber');
wp_set_current_user((int) $subscriber_id);
$forbidden = jg_ai_test_request('PATCH', '/jaisong1n/v1/ai/content/diary/' . $created_data['id'], array('expectedModifiedAt' => $read_back_data['modifiedAt'], 'title' => 'Forbidden user'));
jg_ai_test_assert($forbidden->get_status() === 404, 'Insufficient diary permission was not rejected.');
wp_set_current_user((int) $user_id);

$zero_date_id = wp_insert_post(array('post_type' => 'jg_diary', 'post_status' => 'draft', 'post_author' => (int) $user_id, 'post_title' => 'Zero date diary'));
$wpdb->update($wpdb->posts, array('post_modified' => '0000-00-00 00:00:00', 'post_modified_gmt' => '0000-00-00 00:00:00'), array('ID' => $zero_date_id));
clean_post_cache($zero_date_id);
$zero_date = jg_ai_test_request('GET', '/jaisong1n/v1/ai/content/diary/' . $zero_date_id);
jg_ai_test_assert($zero_date->get_status() === 200 && $zero_date->get_data()['modifiedAt'] === null, 'Zero modifiedAt must be null.');
$zero_date_update = jg_ai_test_request('PATCH', '/jaisong1n/v1/ai/content/diary/' . $zero_date_id, array('expectedModifiedAt' => null, 'title' => 'Zero date repaired'));
jg_ai_test_assert($zero_date_update->get_status() === 200 && is_string($zero_date_update->get_data()['modifiedAt']), 'An explicitly read null modifiedAt could not be updated safely: ' . wp_json_encode($zero_date_update->get_data()));

$audit = get_option('jg_ai_content_audit', array());
$audit_json = wp_json_encode($audit);
jg_ai_test_assert(str_contains($audit_json, 'updateDraft') && !str_contains($audit_json, 'Updated body'), 'Audit data is missing the action or exposes full content.');

$publish = jg_ai_test_request('POST', '/jaisong1n/v1/ai/content/article/' . $article_data['id'] . '/publish', array('expectedModifiedAt' => '2025-01-01T00:00:00Z'));
jg_ai_test_assert($publish->get_status() === 403, 'Publishing must be disabled by default.');

$admin_id = wp_create_user('jg-ai-publish-admin', wp_generate_password(24), 'jg-ai-publish-admin@example.test');
jg_ai_test_assert(!is_wp_error($admin_id), 'Could not create the publish administrator.');
(new WP_User((int) $admin_id))->set_role('administrator');
wp_set_current_user((int) $admin_id);
$claim = jg_ai_test_request('POST', '/jaisong1n/v1/ai/content/diary/' . $created_data['id'] . '/claim', array('editable' => true, 'publishable' => true));
jg_ai_test_assert($claim->get_status() === 200 && (bool) get_post_meta($created_data['id'], '_jg_ai_publishable', true), 'Administrator could not grant diary publish access.');

$old_settings = JG_AI_Content::settings();
$publish_settings = $old_settings;
$publish_settings['reviewed_diary_publish'] = true;
update_option('jg_ai_content_settings', $publish_settings, false);
JG_AI_Content::settings_updated($old_settings, $publish_settings);
wp_set_current_user((int) $user_id);
$user = new WP_User((int) $user_id);
jg_ai_test_assert($user->has_cap('jg_ai_publish_diary_drafts') && !$user->has_cap($diary_object->cap->publish_posts), 'Reviewed publish grant must not grant native WordPress publishing.');

jg_ai_test_clear_publish_limit((int) $user_id);
$enabled_capabilities = jg_ai_test_request('GET', '/jaisong1n/v1/ai/capabilities')->get_data();
jg_ai_test_assert(in_array('preparePublish', $enabled_capabilities['contentTypes']['diary']['operations'], true) && in_array('publish', $enabled_capabilities['contentTypes']['diary']['operations'], true), 'Diary reviewed publish capability is missing after authorization.');
foreach ($enabled_capabilities['contentTypes'] as $type => $definition) {
	if ($type === 'diary') continue;
	jg_ai_test_assert(!in_array('preparePublish', $definition['operations'], true) && !in_array('publish', $definition['operations'], true), 'Reviewed publishing leaked to ' . $type . '.');
}

delete_option('jg_dispatch_pending');
wp_clear_scheduled_hook(JG_Dispatch::CRON_HOOK);
$prepared = jg_ai_test_prepare((int) $user_id, (int) $created_data['id']);
$prepared_data = $prepared->get_data();
jg_ai_test_assert($prepared->get_status() === 200 && preg_match('/^[a-f0-9]{64}$/', $prepared_data['confirmationToken']) === 1, 'Publish preparation did not return a high-entropy token.');
jg_ai_test_assert($prepared_data['status'] === 'draft' && $prepared_data['modifiedAt'] === $read_back_data['modifiedAt'] && !empty($prepared_data['editUrl']), 'Publish preparation summary is incomplete.');
jg_ai_test_assert(get_post_status($created_data['id']) === 'draft' && get_option('jg_dispatch_pending', false) === false && !wp_next_scheduled(JG_Dispatch::CRON_HOOK), 'Publish preparation changed state or scheduled a build.');
$token_entries = get_option('jg_ai_publish_confirmation_tokens', array());
$prepared_hash = hash('sha256', $prepared_data['confirmationToken']);
jg_ai_test_assert(isset($token_entries[$prepared_hash]) && !str_contains(wp_json_encode($token_entries), $prepared_data['confirmationToken']), 'Publish token was not stored as a one-way hash.');
jg_ai_test_assert(($token_entries[$prepared_hash]['expiresAt'] - $token_entries[$prepared_hash]['createdAt']) === 600, 'Publish token lifetime is not ten minutes.');

jg_ai_test_clear_publish_limit((int) $user_id);
$non_diary_prepare = jg_ai_test_request('POST', '/jaisong1n/v1/ai/content/article/' . $article_data['id'] . '/prepare-publish');
jg_ai_test_assert($non_diary_prepare->get_status() === 403 && jg_ai_test_error_code($non_diary_prepare) === 'jg_ai_publish_unsupported', 'Non-diary publish preparation was not rejected.');
update_post_meta($published_id, '_jg_ai_editable', true);
update_post_meta($published_id, '_jg_ai_publishable', true);
$published_prepare = jg_ai_test_prepare((int) $user_id, (int) $published_id);
jg_ai_test_assert($published_prepare->get_status() === 409 && jg_ai_test_error_code($published_prepare) === 'jg_ai_publish_draft_required', 'Published diary preparation was not rejected.');

$missing_expected_publish = jg_ai_test_publish((int) $user_id, (int) $created_data['id'], array('confirmationToken' => $prepared_data['confirmationToken']), 'publish-missing-expected-0001');
jg_ai_test_assert($missing_expected_publish->get_status() === 400 && jg_ai_test_error_code($missing_expected_publish) === 'jg_ai_expected_modified_at_required', 'Publish accepted a missing expectedModifiedAt.');
$missing_token_publish = jg_ai_test_publish((int) $user_id, (int) $created_data['id'], array('expectedModifiedAt' => $prepared_data['modifiedAt']), 'publish-missing-token-0001');
jg_ai_test_assert($missing_token_publish->get_status() === 403 && jg_ai_test_error_code($missing_token_publish) === 'jg_ai_confirmation_token_invalid', 'Publish accepted a missing confirmation token.');
$forged_publish = jg_ai_test_publish((int) $user_id, (int) $created_data['id'], array('expectedModifiedAt' => $prepared_data['modifiedAt'], 'confirmationToken' => str_repeat('a', 64)), 'publish-forged-token-0001');
jg_ai_test_assert($forged_publish->get_status() === 403 && jg_ai_test_error_code($forged_publish) === 'jg_ai_confirmation_token_invalid', 'Forged publish token was not rejected.');

foreach (array(
	'expired' => array('expiresAt' => time() - 1),
	'used' => array('usedAt' => time()),
	'user' => array('userId' => 999999),
	'content' => array('contentId' => 999999),
	'action' => array('action' => 'unpublish'),
	'version' => array('expectedModifiedAt' => '2000-01-01T00:00:00Z'),
) as $case => $change) {
	$case_prepared = jg_ai_test_prepare((int) $user_id, (int) $created_data['id'])->get_data();
	$case_hash = hash('sha256', $case_prepared['confirmationToken']);
	$entries = get_option('jg_ai_publish_confirmation_tokens', array());
	$entries[$case_hash] = array_replace($entries[$case_hash], $change);
	update_option('jg_ai_publish_confirmation_tokens', $entries, false);
	$case_response = jg_ai_test_publish((int) $user_id, (int) $created_data['id'], array('expectedModifiedAt' => $case_prepared['modifiedAt'], 'confirmationToken' => $case_prepared['confirmationToken']), 'publish-token-' . $case . '-0001');
	$expected_status = $case === 'expired' ? 410 : (($case === 'used' || $case === 'version') ? 409 : 403);
	jg_ai_test_assert($case_response->get_status() === $expected_status, 'Publish token ' . $case . ' case was not rejected correctly: ' . wp_json_encode($case_response->get_data()));
}

delete_option('jg_dispatch_pending');
wp_clear_scheduled_hook(JG_Dispatch::CRON_HOOK);
$stale_prepared = jg_ai_test_prepare((int) $user_id, (int) $created_data['id'])->get_data();
$wpdb->update($wpdb->posts, array('post_modified' => '2025-01-02 00:00:00', 'post_modified_gmt' => '2025-01-02 00:00:00'), array('ID' => $created_data['id']));
clean_post_cache($created_data['id']);
$stale_publish = jg_ai_test_publish((int) $user_id, (int) $created_data['id'], array('expectedModifiedAt' => $stale_prepared['modifiedAt'], 'confirmationToken' => $stale_prepared['confirmationToken']), 'publish-stale-content-0001');
jg_ai_test_assert($stale_publish->get_status() === 409 && jg_ai_test_error_code($stale_publish) === 'jg_ai_publish_conflict', 'Publish did not reject a post-prepare modification.');
jg_ai_test_assert(get_post_status($created_data['id']) === 'draft' && get_option('jg_dispatch_pending', false) === false, 'Rejected publish changed state or created build pending.');

$fresh_read = jg_ai_test_request('GET', '/jaisong1n/v1/ai/content/diary/' . $created_data['id'])->get_data();
$fresh_prepared = jg_ai_test_prepare((int) $user_id, (int) $created_data['id'])->get_data();
delete_option('jg_dispatch_pending');
wp_clear_scheduled_hook(JG_Dispatch::CRON_HOOK);
$publish_body = array('expectedModifiedAt' => $fresh_read['modifiedAt'], 'confirmationToken' => $fresh_prepared['confirmationToken']);
$successful_publish = jg_ai_test_publish((int) $user_id, (int) $created_data['id'], $publish_body, 'publish-success-0001');
$successful_data = $successful_publish->get_data();
jg_ai_test_assert($successful_publish->get_status() === 200 && $successful_data['status'] === 'publish' && $successful_data['idempotentReplay'] === false, 'Reviewed diary publish failed.');
$pending_after_publish = get_option('jg_dispatch_pending', array());
$scheduled_after_publish = wp_next_scheduled(JG_Dispatch::CRON_HOOK);
jg_ai_test_assert(in_array('content', $pending_after_publish['types'] ?? array(), true) && count((array) ($pending_after_publish['types'] ?? array())) === 1 && $scheduled_after_publish, 'Successful publish did not create one debounced content pending record.');

$publish_replay = jg_ai_test_publish((int) $user_id, (int) $created_data['id'], $publish_body, 'publish-success-0001');
jg_ai_test_assert($publish_replay->get_status() === 200 && ($publish_replay->get_data()['idempotentReplay'] ?? false) === true, 'Publish idempotency replay did not return the original result: ' . wp_json_encode($publish_replay->get_data()));
jg_ai_test_assert(get_option('jg_dispatch_pending', array()) === $pending_after_publish && wp_next_scheduled(JG_Dispatch::CRON_HOOK) === $scheduled_after_publish, 'Publish replay duplicated the pending build or Cron event.');
$repeat_publish = jg_ai_test_publish((int) $user_id, (int) $created_data['id'], $publish_body, 'publish-repeat-0002');
jg_ai_test_assert($repeat_publish->get_status() === 409 && jg_ai_test_error_code($repeat_publish) === 'jg_ai_already_published', 'Repeated publication did not return a stable conflict.');

$audit = get_option('jg_ai_content_audit', array());
$audit_json = wp_json_encode($audit);
foreach (array('publish_prepare', 'publish_success', 'publish_rejected', 'publish_conflict', 'idempotent_replay') as $action) {
	jg_ai_test_assert(str_contains($audit_json, $action), 'Audit is missing ' . $action . '.');
}
jg_ai_test_assert(!str_contains($audit_json, $prepared_data['confirmationToken']) && !str_contains($audit_json, 'Updated body') && !str_contains($audit_json, 'Authorization'), 'Publish audit exposed a token, body, or credential header.');
jg_ai_test_assert(get_option('jg_dispatch_status', array()) === array(), 'AI publish called the GitHub dispatch worker directly.');

echo wp_json_encode(array('ok' => true, 'assertions' => $jg_ai_assertions, 'articleId' => $article_data['id'], 'diaryId' => $created_data['id'], 'schemaVersion' => $capability_data['schemaVersion'])) . "\n";
