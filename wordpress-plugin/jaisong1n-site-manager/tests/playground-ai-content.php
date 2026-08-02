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
function jg_ai_test_prepare(int $user_id, int $post_id, string $content_type = 'diary'): WP_REST_Response {
	jg_ai_test_clear_publish_limit($user_id);
	return jg_ai_test_request('POST', '/jaisong1n/v1/ai/content/' . $content_type . '/' . $post_id . '/prepare-publish');
}
function jg_ai_test_publish(int $user_id, int $post_id, array $body, string $key, string $content_type = 'diary'): WP_REST_Response {
	jg_ai_test_clear_publish_limit($user_id);
	$body['idempotencyKey'] = $key;
	return jg_ai_test_request('POST', '/jaisong1n/v1/ai/content/' . $content_type . '/' . $post_id . '/publish', $body, array('Idempotency-Key' => $key));
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
jg_ai_test_assert(in_array('updateDraft', $capability_data['contentTypes']['article']['operations'], true), 'Article updateDraft capability is missing.');
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
jg_ai_test_assert($article_update->get_status() === 409 && jg_ai_test_error_code($article_update) === 'jg_ai_stale_content', 'Article draft update must enforce optimistic concurrency.');

$diary_body = array('contentType' => 'diary', 'title' => 'Diary draft', 'slug' => 'diary-draft', 'excerpt' => 'Initial excerpt', 'contentHtml' => '<p>Initial body</p>', 'idempotencyKey' => 'playground-ai-diary-0001');
$created = jg_ai_test_request('POST', '/jaisong1n/v1/ai/content', $diary_body, array('Idempotency-Key' => 'playground-ai-diary-0001'));
jg_ai_test_assert($created->get_status() === 201, 'Diary draft creation failed.');
$created_data = $created->get_data();
jg_ai_test_assert($created_data['status'] === 'draft' && $created_data['id'] > 0, 'Diary draft result is invalid.');
$created_post = get_post($created_data['id']);
jg_ai_test_assert((int) $created_post->post_author === (int) $user_id, 'AI draft was not authored by the creating user.');
$created_owner = (int) get_post_meta($created_data['id'], '_jg_ai_owner_user_id', true);
jg_ai_test_assert($created_owner === (int) $user_id && $created_owner === (int) $created_post->post_author, 'AI owner meta does not match the draft author.');

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

$setting_off_prepare = jg_ai_test_prepare((int) $user_id, (int) $created_data['id']);
jg_ai_test_assert($setting_off_prepare->get_status() === 403 && jg_ai_test_error_code($setting_off_prepare) === 'jg_ai_publish_forbidden', 'Prepare must be rejected while reviewed publishing is disabled.');

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

$no_publish_role = add_role('jg_ai_no_publish_editor', 'AI No Publish Editor', get_role('jg_ai_content_editor')->capabilities);
jg_ai_test_assert($no_publish_role instanceof WP_Role, 'Could not clone the AI editor role.');
$no_publish_role->remove_cap('jg_ai_publish_diary_drafts');
$no_cap_user_id = wp_create_user('jg-ai-no-publish', wp_generate_password(24), 'jg-ai-no-publish@example.test');
jg_ai_test_assert(!is_wp_error($no_cap_user_id), 'Could not create the no-publish test user.');
(new WP_User((int) $no_cap_user_id))->set_role('jg_ai_no_publish_editor');
wp_set_current_user((int) $no_cap_user_id);
$no_cap_prepare = jg_ai_test_prepare((int) $no_cap_user_id, (int) $created_data['id']);
jg_ai_test_assert($no_cap_prepare->get_status() === 403 && jg_ai_test_error_code($no_cap_prepare) === 'jg_ai_publish_forbidden', 'Prepare must be rejected without the diary publish capability.');
wp_set_current_user((int) $user_id);
remove_role('jg_ai_no_publish_editor');

$not_publishable_body = array('contentType' => 'diary', 'title' => 'Not publishable diary', 'slug' => 'not-publishable-diary', 'contentHtml' => '<p>body</p>', 'idempotencyKey' => 'playground-ai-diary-np-0001');
$np_created = jg_ai_test_request('POST', '/jaisong1n/v1/ai/content', $not_publishable_body, array('Idempotency-Key' => 'playground-ai-diary-np-0001'));
jg_ai_test_assert($np_created->get_status() === 201, 'Not-publishable diary creation failed.');
$np_id = (int) $np_created->get_data()['id'];
$np_prepare = jg_ai_test_prepare((int) $user_id, $np_id);
jg_ai_test_assert($np_prepare->get_status() === 403 && jg_ai_test_error_code($np_prepare) === 'jg_ai_publish_forbidden', 'Prepare must be rejected when the diary is not marked publishable.');

$drift_body = array('contentType' => 'diary', 'title' => 'Drifted owner diary', 'slug' => 'drifted-owner-diary', 'contentHtml' => '<p>body</p>', 'idempotencyKey' => 'playground-ai-diary-drift-0001');
$drift_created = jg_ai_test_request('POST', '/jaisong1n/v1/ai/content', $drift_body, array('Idempotency-Key' => 'playground-ai-diary-drift-0001'));
jg_ai_test_assert($drift_created->get_status() === 201, 'Drifted diary creation failed.');
$drift_id = (int) $drift_created->get_data()['id'];
update_post_meta($drift_id, '_jg_ai_publishable', true);
wp_update_post(array('ID' => $drift_id, 'post_author' => (int) $admin_id), true);
clean_post_cache($drift_id);
jg_ai_test_assert((int) get_post($drift_id)->post_author === (int) $admin_id, 'Drift simulation failed.');
jg_ai_test_assert((int) get_post_meta($drift_id, '_jg_ai_owner_user_id', true) === (int) $user_id, 'AI owner meta was lost during drift simulation.');
$drift_read = jg_ai_test_request('GET', '/jaisong1n/v1/ai/content/diary/' . $drift_id)->get_data();
jg_ai_test_assert($drift_read['status'] === 'draft', 'Drifted diary read failed.');
$drift_update = jg_ai_test_request('PATCH', '/jaisong1n/v1/ai/content/diary/' . $drift_id, array('expectedModifiedAt' => $drift_read['modifiedAt'], 'title' => 'Drifted owner updated'));
jg_ai_test_assert($drift_update->get_status() === 200 && $drift_update->get_data()['title'] === 'Drifted owner updated', 'AI owner could not update a draft whose author drifted.');
delete_option('jg_dispatch_pending');
wp_clear_scheduled_hook(JG_Dispatch::CRON_HOOK);
$drift_prepare = jg_ai_test_prepare((int) $user_id, $drift_id);
jg_ai_test_assert($drift_prepare->get_status() === 200 && $drift_prepare->get_data()['status'] === 'draft', 'AI owner could not prepare a draft whose author drifted: ' . wp_json_encode($drift_prepare->get_data()));
jg_ai_test_assert(get_post_status($drift_id) === 'draft' && get_option('jg_dispatch_pending', false) === false && !wp_next_scheduled(JG_Dispatch::CRON_HOOK), 'Drifted prepare changed state or scheduled a build.');

$other_editor_id = wp_create_user('jg-ai-other-editor', wp_generate_password(24), 'jg-ai-other-editor@example.test');
jg_ai_test_assert(!is_wp_error($other_editor_id), 'Could not create the other editor test user.');
(new WP_User((int) $other_editor_id))->set_role('jg_ai_content_editor');
wp_set_current_user((int) $other_editor_id);
$other_prepare = jg_ai_test_prepare((int) $other_editor_id, $drift_id);
jg_ai_test_assert($other_prepare->get_status() === 403 && jg_ai_test_error_code($other_prepare) === 'jg_ai_publish_forbidden', 'A non-owner was allowed to prepare a publishable diary.');
update_post_meta($drift_id, '_jg_ai_editable', false);
$ownerless_prepare = jg_ai_test_prepare((int) $other_editor_id, $drift_id);
jg_ai_test_assert($ownerless_prepare->get_status() === 403 && jg_ai_test_error_code($ownerless_prepare) === 'jg_ai_publish_forbidden', 'A non-owner without the editable grant was not rejected.');
wp_set_current_user((int) $user_id);

$repair_draft_id = wp_insert_post(array('post_type' => 'jg_diary', 'post_status' => 'draft', 'post_author' => (int) $admin_id, 'post_title' => 'Repair target'));
update_post_meta($repair_draft_id, '_jg_ai_created', true);
update_post_meta($repair_draft_id, '_jg_ai_owner_user_id', (int) $user_id);
update_post_meta($repair_draft_id, '_jg_ai_editable', true);
jg_ai_test_assert(JG_AI_Content::repair_ai_ownership((int) $repair_draft_id) === true, 'Guarded ownership repair failed.');
jg_ai_test_assert((int) get_post($repair_draft_id)->post_author === (int) $user_id, 'Ownership repair did not sync the author.');
jg_ai_test_assert(JG_AI_Content::repair_ai_ownership(999999) === false, 'Ownership repair accepted a missing post.');
$repair_page_id = wp_insert_post(array('post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Repair page'));
update_post_meta($repair_page_id, '_jg_ai_created', true);
update_post_meta($repair_page_id, '_jg_ai_owner_user_id', (int) $user_id);
jg_ai_test_assert(JG_AI_Content::repair_ai_ownership((int) $repair_page_id) === false, 'Ownership repair touched a non-registry content type.');
$not_created_id = wp_insert_post(array('post_type' => 'jg_diary', 'post_status' => 'draft', 'post_author' => (int) $admin_id, 'post_title' => 'Not created repair'));
update_post_meta($not_created_id, '_jg_ai_owner_user_id', (int) $user_id);
jg_ai_test_assert(JG_AI_Content::repair_ai_ownership((int) $not_created_id) === false, 'Ownership repair acted without the AI-created flag.');

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
jg_ai_test_assert($non_diary_prepare->get_status() === 403 && jg_ai_test_error_code($non_diary_prepare) === 'jg_ai_publish_forbidden', 'Article publish preparation must be rejected while reviewed article publishing is disabled.');
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

// ---- 0.8.3: AI-owned diary drafts auto-enable reviewed publishing ---------
$auto_settings_before = JG_AI_Content::settings();
$auto_settings = $auto_settings_before;
$auto_settings['reviewed_diary_publish'] = true;
update_option('jg_ai_content_settings', $auto_settings, false);
JG_AI_Content::settings_updated($auto_settings_before, $auto_settings);
$auto_site_settings = JG_Settings::get();
$auto_site_settings['auto_publishable_ai_diaries'] = true;
update_option(JG_Settings::OPTION, $auto_site_settings, false);
wp_set_current_user((int) $user_id);
jg_ai_test_assert($user->has_cap('jg_ai_publish_diary_drafts'), 'Auto-publishable fixture requires the diary publish capability.');

delete_option('jg_dispatch_pending');
wp_clear_scheduled_hook(JG_Dispatch::CRON_HOOK);
$auto_body = array('contentType' => 'diary', 'title' => 'Auto publishable diary', 'slug' => 'auto-publishable-diary', 'contentHtml' => '<p>body</p>', 'idempotencyKey' => 'playground-ai-diary-auto-0001');
$auto_created = jg_ai_test_request('POST', '/jaisong1n/v1/ai/content', $auto_body, array('Idempotency-Key' => 'playground-ai-diary-auto-0001'));
jg_ai_test_assert($auto_created->get_status() === 201, 'Auto-publishable diary creation failed.');
$auto_id = (int) $auto_created->get_data()['id'];
jg_ai_test_assert((bool) get_post_meta($auto_id, '_jg_ai_publishable', true), 'AI-owned diary draft was not auto-marked publishable.');
jg_ai_test_assert(get_post_status($auto_id) === 'draft', 'Auto-publishable diary was not kept as a draft.');
jg_ai_test_assert((int) get_post($auto_id)->post_author === (int) $user_id && (int) get_post_meta($auto_id, '_jg_ai_owner_user_id', true) === (int) $user_id, 'Auto-publishable diary ownership is incorrect.');
jg_ai_test_assert(get_option('jg_dispatch_pending', false) === false && !wp_next_scheduled(JG_Dispatch::CRON_HOOK), 'Auto-publishable diary creation triggered a build.');
$auto_prepare = jg_ai_test_prepare((int) $user_id, $auto_id);
jg_ai_test_assert($auto_prepare->get_status() === 200 && preg_match('/^[a-f0-9]{64}$/', $auto_prepare->get_data()['confirmationToken']) === 1, 'Auto-publishable diary could not enter preparePublish without an admin claim.');
jg_ai_test_assert(get_post_status($auto_id) === 'draft' && get_option('jg_dispatch_pending', false) === false, 'Auto-publishable prepare changed state or scheduled a build.');
$no_token_publish = jg_ai_test_publish((int) $user_id, $auto_id, array('expectedModifiedAt' => $auto_prepare->get_data()['modifiedAt']), 'auto-publish-no-token-0001');
jg_ai_test_assert($no_token_publish->get_status() === 403 && jg_ai_test_error_code($no_token_publish) === 'jg_ai_confirmation_token_invalid', 'Auto-publishable diary bypassed the confirmation token.');

$auto_article_body = array('contentType' => 'article', 'title' => 'Auto article', 'contentHtml' => '<p>a</p>', 'idempotencyKey' => 'playground-ai-article-auto-0001');
$auto_article = jg_ai_test_request('POST', '/jaisong1n/v1/ai/content', $auto_article_body, array('Idempotency-Key' => 'playground-ai-article-auto-0001'));
$auto_article_id = (int) $auto_article->get_data()['id'];
jg_ai_test_assert((bool) get_post_meta($auto_article_id, '_jg_ai_publishable', true) === false, 'Article draft must never be auto-publishable.');

$manual_diary_id = wp_insert_post(array('post_type' => 'jg_diary', 'post_status' => 'draft', 'post_author' => (int) $admin_id, 'post_title' => 'Manual diary'));
jg_ai_test_assert((bool) get_post_meta($manual_diary_id, '_jg_ai_publishable', true) === false, 'Manually created diary must not be auto-publishable.');

$foreign_diary_id = wp_insert_post(array('post_type' => 'jg_diary', 'post_status' => 'draft', 'post_author' => (int) $admin_id, 'post_title' => 'Foreign diary'));
update_post_meta($foreign_diary_id, '_jg_ai_created', true);
update_post_meta($foreign_diary_id, '_jg_ai_owner_user_id', (int) $user_id);
update_post_meta($foreign_diary_id, '_jg_ai_editable', true);
jg_ai_test_assert((bool) get_post_meta($foreign_diary_id, '_jg_ai_publishable', true) === false, 'Non-owner content must not be auto-publishable.');

$auto_no_cap_role = add_role('jg_ai_auto_no_cap', 'AI Auto No Cap', get_role('jg_ai_content_editor')->capabilities);
jg_ai_test_assert($auto_no_cap_role instanceof WP_Role, 'Could not clone the AI editor role for auto-publishable testing.');
$auto_no_cap_role->remove_cap('jg_ai_publish_diary_drafts');
$auto_no_cap_user_id = wp_create_user('jg-ai-auto-no-cap', wp_generate_password(24), 'jg-ai-auto-no-cap@example.test');
jg_ai_test_assert(!is_wp_error($auto_no_cap_user_id), 'Could not create the no-cap auto-publishable user.');
(new WP_User((int) $auto_no_cap_user_id))->set_role('jg_ai_auto_no_cap');
wp_set_current_user((int) $auto_no_cap_user_id);
$no_cap_body = array('contentType' => 'diary', 'title' => 'No cap diary', 'slug' => 'no-cap-diary', 'contentHtml' => '<p>n</p>', 'idempotencyKey' => 'playground-ai-diary-nocap-0001');
$no_cap_created = jg_ai_test_request('POST', '/jaisong1n/v1/ai/content', $no_cap_body, array('Idempotency-Key' => 'playground-ai-diary-nocap-0001'));
jg_ai_test_assert($no_cap_created->get_status() === 201, 'No-cap diary creation failed.');
$no_cap_id = (int) $no_cap_created->get_data()['id'];
jg_ai_test_assert((bool) get_post_meta($no_cap_id, '_jg_ai_publishable', true) === false, 'Diary without the publish capability must not be auto-publishable.');
wp_set_current_user((int) $user_id);
remove_role('jg_ai_auto_no_cap');

$off_settings = JG_AI_Content::settings();
$off_settings['reviewed_diary_publish'] = false;
$off_settings_before = JG_AI_Content::settings();
update_option('jg_ai_content_settings', $off_settings, false);
JG_AI_Content::settings_updated($off_settings_before, $off_settings);
$off_body = array('contentType' => 'diary', 'title' => 'Setting off diary', 'slug' => 'setting-off-diary', 'contentHtml' => '<p>o</p>', 'idempotencyKey' => 'playground-ai-diary-off-0001');
$off_created = jg_ai_test_request('POST', '/jaisong1n/v1/ai/content', $off_body, array('Idempotency-Key' => 'playground-ai-diary-off-0001'));
jg_ai_test_assert($off_created->get_status() === 201, 'Setting-off diary creation failed.');
$off_id = (int) $off_created->get_data()['id'];
jg_ai_test_assert((bool) get_post_meta($off_id, '_jg_ai_publishable', true) === false, 'Setting-off diary was auto-publishable.');
update_option('jg_ai_content_settings', $auto_settings, false);
JG_AI_Content::settings_updated($off_settings, $auto_settings);

// ---- 0.9.0: article updateDraft + reviewed publishing --------------------
$article_caps = jg_ai_test_request('GET', '/jaisong1n/v1/ai/capabilities')->get_data();
jg_ai_test_assert(in_array('updateDraft', $article_caps['contentTypes']['article']['operations'], true), 'Article must expose updateDraft.');
jg_ai_test_assert(!in_array('preparePublish', $article_caps['contentTypes']['article']['operations'], true) && !in_array('publish', $article_caps['contentTypes']['article']['operations'], true), 'Article must not expose publish by default.');

$article_current = jg_ai_test_request('GET', '/jaisong1n/v1/ai/content/article/' . $article_data['id'])->get_data();
$article_title_update = jg_ai_test_request('PATCH', '/jaisong1n/v1/ai/content/article/' . $article_data['id'], array('expectedModifiedAt' => $article_current['modifiedAt'], 'title' => 'Updated article title', 'content' => '<p>Updated article body</p>', 'excerpt' => 'Article excerpt', 'slug' => 'updated-article-slug'));
jg_ai_test_assert($article_title_update->get_status() === 200 && $article_title_update->get_data()['title'] === 'Updated article title' && $article_title_update->get_data()['contentHtml'] === '<p>Updated article body</p>' && $article_title_update->get_data()['excerpt'] === 'Article excerpt' && $article_title_update->get_data()['slug'] === 'updated-article-slug', 'Article draft update failed.');
$article_after_update = $article_title_update->get_data();
$article_readback = jg_ai_test_request('GET', '/jaisong1n/v1/ai/content/article/' . $article_data['id'])->get_data();
jg_ai_test_assert($article_readback['title'] === 'Updated article title' && $article_readback['contentHtml'] === '<p>Updated article body</p>' && $article_readback['excerpt'] === 'Article excerpt' && $article_readback['slug'] === 'updated-article-slug', 'Article update did not read back consistently.');
jg_ai_test_assert($article_readback['status'] === 'draft', 'Article update changed publication status.');
jg_ai_test_assert(get_option('jg_dispatch_pending', false) === false, 'Article draft update triggered a build.');

$article_noop = jg_ai_test_request('PATCH', '/jaisong1n/v1/ai/content/article/' . $article_data['id'], array('expectedModifiedAt' => $article_after_update['modifiedAt'], 'title' => $article_after_update['title']));
jg_ai_test_assert($article_noop->get_status() === 400 && jg_ai_test_error_code($article_noop) === 'jg_ai_no_changes', 'Article no-op update was not rejected.');
foreach (array('status' => 'publish', 'author' => 1, 'meta' => array('x' => 1)) as $field => $value) {
	$inject = jg_ai_test_request('PATCH', '/jaisong1n/v1/ai/content/article/' . $article_data['id'], array('expectedModifiedAt' => $article_after_update['modifiedAt'], $field => $value));
	jg_ai_test_assert($inject->get_status() === 400 && jg_ai_test_error_code($inject) === 'jg_ai_unknown_field', 'Article ' . $field . ' injection was not rejected.');
}
$article_stale = jg_ai_test_request('PATCH', '/jaisong1n/v1/ai/content/article/' . $article_data['id'], array('expectedModifiedAt' => '2000-01-01T00:00:00Z', 'title' => 'Stale'));
jg_ai_test_assert($article_stale->get_status() === 409 && jg_ai_test_error_code($article_stale) === 'jg_ai_stale_content', 'Article stale update was not rejected.');
wp_set_current_user((int) $subscriber_id);
$article_forbidden = jg_ai_test_request('PATCH', '/jaisong1n/v1/ai/content/article/' . $article_data['id'], array('expectedModifiedAt' => $article_after_update['modifiedAt'], 'title' => 'X'));
jg_ai_test_assert($article_forbidden->get_status() === 404, 'Article non-owner update was not rejected.');
wp_set_current_user((int) $user_id);

$article_site_settings = JG_Settings::get();
$article_site_settings['reviewed_article_publish'] = true;
$article_site_settings['auto_publishable_ai_articles'] = true;
update_option(JG_Settings::OPTION, $article_site_settings, false);
wp_set_current_user(0);
wp_set_current_user((int) $user_id);
$article_role = get_role('jg_ai_content_editor');
jg_ai_test_assert(current_user_can('jg_ai_publish_article_drafts') && !current_user_can('publish_posts'), 'Article reviewed grant must not include native WordPress publishing. article_cap=' . var_export(current_user_can('jg_ai_publish_article_drafts'), true) . ' publish_posts=' . var_export(current_user_can('publish_posts'), true) . ' role_cap=' . var_export($article_role ? $article_role->has_cap('jg_ai_publish_article_drafts') : 'no-role', true) . ' did_action=' . did_action('update_option_jg_site_settings'));
$article_enabled_caps = jg_ai_test_request('GET', '/jaisong1n/v1/ai/capabilities')->get_data();
jg_ai_test_assert(in_array('preparePublish', $article_enabled_caps['contentTypes']['article']['operations'], true) && in_array('publish', $article_enabled_caps['contentTypes']['article']['operations'], true), 'Article reviewed publishing missing after authorization.');
foreach ($article_enabled_caps['contentTypes'] as $type => $definition) {
	if (in_array($type, array('diary', 'article'), true)) continue;
	jg_ai_test_assert(!in_array('preparePublish', $definition['operations'], true) && !in_array('publish', $definition['operations'], true), 'Reviewed publishing leaked to ' . $type . '.');
}

$article_no_cap_role = add_role('jg_ai_article_no_cap', 'AI Article No Cap', get_role('jg_ai_content_editor')->capabilities);
jg_ai_test_assert($article_no_cap_role instanceof WP_Role, 'Could not clone the AI editor role for article capability testing.');
$article_no_cap_role->remove_cap('jg_ai_publish_article_drafts');
$article_no_cap_user_id = wp_create_user('jg-ai-article-no-cap', wp_generate_password(24), 'jg-ai-article-no-cap@example.test');
jg_ai_test_assert(!is_wp_error($article_no_cap_user_id), 'Could not create the article no-cap user.');
(new WP_User((int) $article_no_cap_user_id))->set_role('jg_ai_article_no_cap');
wp_set_current_user((int) $article_no_cap_user_id);
$article_no_cap_caps = jg_ai_test_request('GET', '/jaisong1n/v1/ai/capabilities')->get_data();
jg_ai_test_assert(!in_array('preparePublish', $article_no_cap_caps['contentTypes']['article']['operations'], true), 'Article publish leaked without its own capability.');
jg_ai_test_assert(in_array('preparePublish', $article_no_cap_caps['contentTypes']['diary']['operations'], true), 'Diary publishing must remain independent of the article capability.');
wp_set_current_user((int) $user_id);
remove_role('jg_ai_article_no_cap');

delete_option('jg_dispatch_pending');
wp_clear_scheduled_hook(JG_Dispatch::CRON_HOOK);
$article_auto_body = array('contentType' => 'article', 'title' => 'Auto article publishable', 'slug' => 'auto-article-publishable', 'contentHtml' => '<p>body</p>', 'idempotencyKey' => 'playground-ai-article-auto-0002');
$article_auto = jg_ai_test_request('POST', '/jaisong1n/v1/ai/content', $article_auto_body, array('Idempotency-Key' => 'playground-ai-article-auto-0002'));
jg_ai_test_assert($article_auto->get_status() === 201, 'Auto article creation failed.');
$article_auto_id = (int) $article_auto->get_data()['id'];
jg_ai_test_assert((bool) get_post_meta($article_auto_id, '_jg_ai_publishable', true), 'AI-owned article draft was not auto-marked publishable.');
jg_ai_test_assert((int) get_post($article_auto_id)->post_author === (int) $user_id && (int) get_post_meta($article_auto_id, '_jg_ai_owner_user_id', true) === (int) $user_id, 'Article auto-publishable ownership is incorrect.');
jg_ai_test_assert(get_post_status($article_auto_id) === 'draft' && get_option('jg_dispatch_pending', false) === false, 'Auto article creation changed status or triggered a build.');

$manual_article_id = wp_insert_post(array('post_type' => 'post', 'post_status' => 'draft', 'post_author' => (int) $admin_id, 'post_title' => 'Manual article'));
jg_ai_test_assert((bool) get_post_meta($manual_article_id, '_jg_ai_publishable', true) === false, 'Manually created article must not be auto-publishable.');

$article_prepare = jg_ai_test_prepare((int) $user_id, $article_auto_id, 'article');
jg_ai_test_assert($article_prepare->get_status() === 200 && preg_match('/^[a-f0-9]{64}$/', $article_prepare->get_data()['confirmationToken']) === 1 && ($article_prepare->get_data()['contentType'] ?? '') === 'article', 'Article preparePublish failed: ' . wp_json_encode($article_prepare->get_data()));
$article_prepared = $article_prepare->get_data();
jg_ai_test_assert(get_post_status($article_auto_id) === 'draft' && get_option('jg_dispatch_pending', false) === false, 'Article prepare changed state or triggered a build.');
$article_missing_token = jg_ai_test_publish((int) $user_id, $article_auto_id, array('expectedModifiedAt' => $article_prepared['modifiedAt']), 'article-publish-no-token-0001', 'article');
jg_ai_test_assert($article_missing_token->get_status() === 403 && jg_ai_test_error_code($article_missing_token) === 'jg_ai_confirmation_token_invalid', 'Article publish accepted a missing confirmation token.');
$article_forged_token = jg_ai_test_publish((int) $user_id, $article_auto_id, array('expectedModifiedAt' => $article_prepared['modifiedAt'], 'confirmationToken' => str_repeat('b', 64)), 'article-publish-forged-0001', 'article');
jg_ai_test_assert($article_forged_token->get_status() === 403 && jg_ai_test_error_code($article_forged_token) === 'jg_ai_confirmation_token_invalid', 'Article publish accepted a forged confirmation token.');

$expired_article_prepared = jg_ai_test_prepare((int) $user_id, $article_auto_id, 'article')->get_data();
$expired_article_hash = hash('sha256', $expired_article_prepared['confirmationToken']);
$article_token_entries = get_option('jg_ai_publish_confirmation_tokens', array());
$article_token_entries[$expired_article_hash]['expiresAt'] = time() - 1;
update_option('jg_ai_publish_confirmation_tokens', $article_token_entries, false);
$article_expired = jg_ai_test_publish((int) $user_id, $article_auto_id, array('expectedModifiedAt' => $expired_article_prepared['modifiedAt'], 'confirmationToken' => $expired_article_prepared['confirmationToken']), 'article-publish-expired-0001', 'article');
jg_ai_test_assert($article_expired->get_status() === 410 && jg_ai_test_error_code($article_expired) === 'jg_ai_confirmation_token_expired', 'Article publish accepted an expired token.');

$used_article_prepared = jg_ai_test_prepare((int) $user_id, $article_auto_id, 'article')->get_data();
$used_article_hash = hash('sha256', $used_article_prepared['confirmationToken']);
$article_token_entries = get_option('jg_ai_publish_confirmation_tokens', array());
$article_token_entries[$used_article_hash]['usedAt'] = time();
update_option('jg_ai_publish_confirmation_tokens', $article_token_entries, false);
$article_used = jg_ai_test_publish((int) $user_id, $article_auto_id, array('expectedModifiedAt' => $used_article_prepared['modifiedAt'], 'confirmationToken' => $used_article_prepared['confirmationToken']), 'article-publish-used-0001', 'article');
jg_ai_test_assert($article_used->get_status() === 409 && jg_ai_test_error_code($article_used) === 'jg_ai_confirmation_token_used', 'Article publish reused a consumed token.');

$article_fresh = jg_ai_test_request('GET', '/jaisong1n/v1/ai/content/article/' . $article_auto_id)->get_data();
$article_ready = jg_ai_test_prepare((int) $user_id, $article_auto_id, 'article')->get_data();
delete_option('jg_dispatch_pending');
wp_clear_scheduled_hook(JG_Dispatch::CRON_HOOK);
$article_publish_body = array('expectedModifiedAt' => $article_fresh['modifiedAt'], 'confirmationToken' => $article_ready['confirmationToken']);
$article_published = jg_ai_test_publish((int) $user_id, $article_auto_id, $article_publish_body, 'article-publish-success-0001', 'article');
jg_ai_test_assert($article_published->get_status() === 200 && $article_published->get_data()['status'] === 'publish' && $article_published->get_data()['idempotentReplay'] === false, 'Reviewed article publish failed.');
$article_pending = get_option('jg_dispatch_pending', array());
jg_ai_test_assert(in_array('content', $article_pending['types'] ?? array(), true) && count((array) ($article_pending['types'] ?? array())) === 1, 'Article publish did not create one debounced content pending record.');
$article_replay = jg_ai_test_publish((int) $user_id, $article_auto_id, $article_publish_body, 'article-publish-success-0001', 'article');
jg_ai_test_assert($article_replay->get_status() === 200 && ($article_replay->get_data()['idempotentReplay'] ?? false) === true, 'Article publish idempotency replay failed.');
jg_ai_test_assert(get_option('jg_dispatch_pending', array()) === $article_pending, 'Article publish replay duplicated the pending build.');
$article_repeat = jg_ai_test_publish((int) $user_id, $article_auto_id, $article_publish_body, 'article-publish-repeat-0002', 'article');
jg_ai_test_assert($article_repeat->get_status() === 409 && jg_ai_test_error_code($article_repeat) === 'jg_ai_already_published', 'Repeated article publication did not return a stable conflict.');

$audit = get_option('jg_ai_content_audit', array());
$audit_json = wp_json_encode($audit);
foreach (array('publish_prepare', 'publish_success', 'publish_rejected', 'publish_conflict', 'idempotent_replay') as $action) {
	jg_ai_test_assert(str_contains($audit_json, $action), 'Audit is missing ' . $action . '.');
}
foreach (array('setting_disabled', 'missing_publish_capability', 'ownership_denied', 'edit_denied', 'not_publishable', 'not_draft') as $reason) {
	jg_ai_test_assert(str_contains($audit_json, $reason), 'Audit is missing rejection reason ' . $reason . '.');
}
jg_ai_test_assert(!str_contains($audit_json, $prepared_data['confirmationToken']) && !str_contains($audit_json, 'Updated body') && !str_contains($audit_json, 'Authorization'), 'Publish audit exposed a token, body, or credential header.');
jg_ai_test_assert(get_option('jg_dispatch_status', array()) === array(), 'AI publish called the GitHub dispatch worker directly.');

echo wp_json_encode(array('ok' => true, 'assertions' => $jg_ai_assertions, 'articleId' => $article_data['id'], 'diaryId' => $created_data['id'], 'schemaVersion' => $capability_data['schemaVersion'])) . "\n";
