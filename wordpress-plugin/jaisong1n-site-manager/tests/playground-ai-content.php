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

JG_Content_Types::register();
JG_AI_Content::install();
do_action('rest_api_init');

$user_id = wp_create_user('jg-ai-draft-editor', wp_generate_password(24), 'jg-ai-draft-editor@example.test');
jg_ai_test_assert(!is_wp_error($user_id), 'Could not create the AI content editor test user.');
$user = new WP_User((int) $user_id);
$user->set_role('jg_ai_content_editor');
wp_set_current_user((int) $user_id);

$capabilities = jg_ai_test_request('GET', '/jaisong1n/v1/ai/capabilities');
jg_ai_test_assert($capabilities->get_status() === 200, 'Capabilities failed.');
$capability_data = $capabilities->get_data();
jg_ai_test_assert(isset($capability_data['contentTypes']['article']), 'Article capability is missing.');
jg_ai_test_assert(in_array('updateDraft', $capability_data['contentTypes']['diary']['operations'], true), 'Diary updateDraft capability is missing.');
jg_ai_test_assert(!in_array('updateDraft', $capability_data['contentTypes']['article']['operations'], true), 'Article must not expose updateDraft.');
jg_ai_test_assert(($capability_data['contentTypes']['diary']['fields']['content']['update'] ?? false) === true, 'Diary content update field is missing.');
jg_ai_test_assert(($capability_data['contentTypes']['diary']['fields']['contentHtml']['update'] ?? true) === false, 'Diary contentHtml must not be updateable.');
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

echo wp_json_encode(array('ok' => true, 'assertions' => $jg_ai_assertions, 'articleId' => $article_data['id'], 'diaryId' => $created_data['id'], 'schemaVersion' => $capability_data['schemaVersion'])) . "\n";
