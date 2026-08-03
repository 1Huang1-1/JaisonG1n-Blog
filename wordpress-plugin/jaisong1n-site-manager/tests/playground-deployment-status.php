<?php

if (!defined('ABSPATH')) require_once '/wordpress/wp-load.php';
require_once dirname(__DIR__) . '/jaisong1n-site-manager.php';

$jg_ds_assertions = 0;
function jg_ds_assert(bool $condition, string $message): void {
	global $jg_ds_assertions;
	$jg_ds_assertions++;
	if (!$condition) throw new RuntimeException($message);
}
function jg_ds_request(string $method, string $route, array $body = array(), array $headers = array()): WP_REST_Response {
	$request = new WP_REST_Request($method, $route);
	foreach ($headers as $name => $value) $request->set_header($name, $value);
	if ($body) $request->set_body_params($body);
	return rest_do_request($request);
}
function jg_ds_error_code(WP_REST_Response $response): string {
	$data = $response->get_data();
	return is_array($data) ? (string) ($data['code'] ?? '') : '';
}
function jg_ds_iso_time(string $value): ?string {
	if ($value === '' || $value === '0000-00-00 00:00:00') return null;
	$timestamp = strtotime($value . (str_contains($value, 'T') || str_contains($value, '+') || str_contains($value, 'Z') ? '' : ' UTC'));
	return $timestamp === false ? null : gmdate('c', $timestamp);
}

$jg_ds_http_log = array();
$jg_ds_http_responses = array();
$jg_ds_http_filter = static function ($pre, $args, $url) use (&$jg_ds_http_log, &$jg_ds_http_responses) {
	$jg_ds_http_log[] = (string) $url;
	if (isset($jg_ds_http_responses[$url])) {
		$config = $jg_ds_http_responses[$url];
		if (isset($config['error'])) return $config['error'];
		return array(
			'headers' => array(),
			'body' => (string) ($config['body'] ?? ''),
			'response' => array('code' => (int) ($config['code'] ?? 500), 'message' => ''),
			'cookies' => array(),
		);
	}
	return array('headers' => array(), 'body' => '', 'response' => array('code' => 500, 'message' => ''), 'cookies' => array());
};
add_filter('pre_http_request', $jg_ds_http_filter, 10, 3);

JG_Content_Types::register();
JG_AI_Content::install();
JG_Settings::install_defaults();
do_action('rest_api_init');
if (!defined('JAISONG1N_GITHUB_TOKEN')) define('JAISONG1N_GITHUB_TOKEN', 'deployment-status-fixture-token');

$settings = JG_Settings::get();
$settings['public_site_url'] = 'https://jaisong1n.com';
update_option(JG_Settings::OPTION, $settings, false);

$user_id = wp_create_user('jg-ds-editor', wp_generate_password(24), 'jg-ds-editor@example.test');
jg_ds_assert(!is_wp_error($user_id), 'Could not create the deployment status test user.');
(new WP_User((int) $user_id))->set_role('jg_ai_content_editor');
wp_set_current_user((int) $user_id);

// ---- Canonical public URL -------------------------------------------------
$diary_post = wp_insert_post(array(
	'post_type' => 'jg_diary',
	'post_status' => 'publish',
	'post_author' => (int) $user_id,
	'post_title' => 'English diary',
	'post_name' => 'openclaw-runtime-integration-2026-08-01',
	'post_content' => '<p>body</p>',
));
jg_ds_assert(!is_wp_error($diary_post), 'Could not create the English-slug diary fixture.');
jg_ds_assert(JG_AI_Content::get_canonical_public_url('diary', get_post($diary_post)) === 'https://jaisong1n.com/diary/openclaw-runtime-integration-2026-08-01/', 'English diary canonical URL is incorrect.');

$chinese_post = wp_insert_post(array('post_type' => 'jg_diary', 'post_status' => 'draft', 'post_author' => (int) $user_id, 'post_title' => '中文日记', 'post_name' => '%e4%b8%ad%e6%96%87'));
$chinese_url = JG_AI_Content::get_canonical_public_url('diary', get_post($chinese_post));
jg_ds_assert($chinese_url === 'https://jaisong1n.com/diary/%E4%B8%AD%E6%96%87/', 'Chinese diary canonical URL is incorrect: ' . (string) $chinese_url);

$article_post = wp_insert_post(array('post_type' => 'post', 'post_status' => 'publish', 'post_author' => (int) $user_id, 'post_title' => 'Article', 'post_name' => 'my-article', 'post_content' => '<p>body</p>'));
jg_ds_assert(JG_AI_Content::get_canonical_public_url('article', get_post($article_post)) === 'https://jaisong1n.com/posts/my-article/', 'Article canonical URL is incorrect.');

$empty_slug_post = wp_insert_post(array('post_type' => 'jg_diary', 'post_status' => 'draft', 'post_author' => (int) $user_id, 'post_title' => 'No slug', 'post_name' => 'empty-slug-fixture'));
global $wpdb;
$wpdb->update($wpdb->posts, array('post_name' => ''), array('ID' => (int) $empty_slug_post), array('%s'), array('%d'));
clean_post_cache((int) $empty_slug_post);
jg_ds_assert(JG_AI_Content::get_canonical_public_url('diary', get_post($empty_slug_post)) === null, 'Empty slug must not produce a URL.');
$slashed_slug_post = wp_insert_post(array('post_type' => 'jg_diary', 'post_status' => 'draft', 'post_author' => (int) $user_id, 'post_title' => 'Bad slug', 'post_name' => 'slash-slug-fixture'));
$wpdb->update($wpdb->posts, array('post_name' => 'a/b'), array('ID' => (int) $slashed_slug_post), array('%s'), array('%d'));
clean_post_cache((int) $slashed_slug_post);
jg_ds_assert(JG_AI_Content::get_canonical_public_url('diary', get_post($slashed_slug_post)) === null, 'Slug with a path separator must not produce a URL.');
jg_ds_assert(JG_AI_Content::get_canonical_public_url('project', get_post($diary_post)) === null, 'Unsupported content type must return null.');

// ---- Page probe (trusted host only) ---------------------------------------
$probe_url = 'https://jaisong1n.com/diary/openclaw-runtime-integration-2026-08-01/';
$jg_ds_http_responses[$probe_url] = array('code' => 200, 'body' => '<html>ok</html>');
delete_transient('jg_page_probe_' . md5($probe_url));
jg_ds_assert(JG_AI_Content::get_canonical_public_url('diary', get_post($diary_post)) !== null, 'Diary probe URL is unavailable.');

$reachable_probe_url = 'https://jaisong1n.com/probe-200/';
$jg_ds_http_responses[$reachable_probe_url] = array('code' => 200, 'body' => '<html>ok</html>');
delete_transient('jg_page_probe_' . md5($reachable_probe_url));
$probe_result = JG_AI_Content::probe_public_page($reachable_probe_url);
jg_ds_assert($probe_result === 'reachable', 'HTTP 200 probe did not map to reachable.');

$missing_probe_url = 'https://jaisong1n.com/probe-404/';
$jg_ds_http_responses[$missing_probe_url] = array('code' => 404, 'body' => 'not found');
delete_transient('jg_page_probe_' . md5($missing_probe_url));
jg_ds_assert(JG_AI_Content::probe_public_page($missing_probe_url) === 'not_found', 'HTTP 404 probe did not map to not_found.');

$redirect_probe_url = 'https://jaisong1n.com/probe-redirect/';
$jg_ds_http_responses[$redirect_probe_url] = array('code' => 302, 'body' => '');
delete_transient('jg_page_probe_' . md5($redirect_probe_url));
jg_ds_assert(JG_AI_Content::probe_public_page($redirect_probe_url) === 'unavailable', 'Redirected probe must be unavailable.');

$timeout_probe_url = 'https://jaisong1n.com/probe-timeout/';
$jg_ds_http_responses[$timeout_probe_url] = array('error' => new WP_Error('http_request_failed', 'fixture timeout'));
delete_transient('jg_page_probe_' . md5($timeout_probe_url));
jg_ds_assert(JG_AI_Content::probe_public_page($timeout_probe_url) === 'unavailable', 'Timed-out probe must be unavailable.');

$http_log_before_ssrf = count($jg_ds_http_log);
$ssrf_result = JG_AI_Content::probe_public_page('https://localhost/secret');
jg_ds_assert($ssrf_result === 'unavailable', 'SSRF target must be rejected.');
$ssrf_result = JG_AI_Content::probe_public_page('http://10.0.0.1/x');
jg_ds_assert($ssrf_result === 'unavailable', 'Private-network probe must be rejected.');
jg_ds_assert(count($jg_ds_http_log) === $http_log_before_ssrf, 'Rejected SSRF targets must not be requested.');

// ---- Dispatch records and content references ------------------------------
JG_Content_Types::grant_capabilities();
$published_a = wp_insert_post(array('post_type' => 'jg_diary', 'post_status' => 'publish', 'post_author' => (int) $user_id, 'post_title' => 'Dispatch diary A', 'post_name' => 'dispatch-diary-a', 'post_content' => '<p>A</p>'));
$published_b = wp_insert_post(array('post_type' => 'jg_diary', 'post_status' => 'publish', 'post_author' => (int) $user_id, 'post_title' => 'Dispatch diary B', 'post_name' => 'dispatch-diary-b', 'post_content' => '<p>B</p>'));
$published_c = wp_insert_post(array('post_type' => 'jg_diary', 'post_status' => 'publish', 'post_author' => (int) $user_id, 'post_title' => 'Dispatch diary C', 'post_name' => 'dispatch-diary-c', 'post_content' => '<p>C</p>'));
jg_ds_assert(!is_wp_error($published_a) && !is_wp_error($published_b) && !is_wp_error($published_c), 'Could not create dispatch fixtures.');

delete_option('jg_dispatch_pending');
wp_clear_scheduled_hook(JG_Dispatch::CRON_HOOK);
JG_Dispatch::post_saved((int) $published_a, get_post($published_a), true);
JG_Dispatch::post_saved((int) $published_b, get_post($published_b), true);
$pending = get_option('jg_dispatch_pending', array());
$refs = $pending['contentRefs'] ?? array();
jg_ds_assert(count($refs) === 2, 'Debounce did not merge two content references.');
$ref_keys = array_map(static fn($ref) => $ref['contentType'] . ':' . $ref['contentId'], $refs);
sort($ref_keys);
$expected_ref_keys = array('diary:' . $published_a, 'diary:' . $published_b);
sort($expected_ref_keys);
jg_ds_assert($ref_keys === $expected_ref_keys, 'Merged content references are incorrect: ' . wp_json_encode($ref_keys));
jg_ds_assert(!empty($pending['triggerId']) && !empty($pending['triggeredAt']) && $pending['source'] === 'wordpress', 'Pending record metadata is missing.');

$dispatch_endpoint = 'https://api.github.com/repos/1Huang1-1/JaisonG1n-Blog/actions/workflows/build-deploy.yml/dispatches';
$jg_ds_http_responses[$dispatch_endpoint] = array('code' => 200, 'body' => wp_json_encode(array(
	'workflow_run_id' => 123,
	'run_url' => 'https://api.github.com/repos/1Huang1-1/JaisonG1n-Blog/actions/runs/123',
	'html_url' => 'https://github.com/1Huang1-1/JaisonG1n-Blog/actions/runs/123',
)));
delete_option('jg_last_dispatched_revision');
JG_Dispatch::dispatch_if_changed();
$history = get_option('jg_dispatch_history', array());
$first_record = $history[0] ?? null;
jg_ds_assert(is_array($first_record) && ($first_record['dispatchStatus'] ?? '') === 'accepted', 'Dispatch record was not persisted as accepted.');
jg_ds_assert((int) ($first_record['workflowRunId'] ?? 0) === 123, 'workflowRunId was not parsed from the 200 response.');
jg_ds_assert(($first_record['runUrl'] ?? '') === 'https://api.github.com/repos/1Huang1-1/JaisonG1n-Blog/actions/runs/123', 'runUrl was not persisted.');
jg_ds_assert(($first_record['runHtmlUrl'] ?? '') === 'https://github.com/1Huang1-1/JaisonG1n-Blog/actions/runs/123', 'runHtmlUrl was not persisted.');
jg_ds_assert(($first_record['buildStatus'] ?? '') === 'pending', 'Fresh dispatch must be pending, not success.');
jg_ds_assert(count($first_record['contentRefs'] ?? array()) === 2, 'Dispatch record lost merged content references.');
jg_ds_assert(($first_record['triggeredAt'] ?? '') === ($pending['triggeredAt'] ?? ''), 'Record triggeredAt does not match the pending trigger.');
$status_view = get_option('jg_dispatch_status', array());
jg_ds_assert(($status_view['state'] ?? '') === 'success' && (int) ($status_view['workflow_run_id'] ?? 0) === 123, 'Legacy status view was not updated.');

// 204 legacy fallback: accepted, no run ID, no invented URLs.
delete_option('jg_last_dispatched_revision');
update_option('jg_dispatch_pending', array('types' => array('content'), 'actions' => array('updated'), 'attempts' => 0, 'contentRefs' => array(array('contentType' => 'diary', 'contentId' => (int) $published_c, 'modifiedAt' => jg_ds_iso_time(get_post($published_c)->post_modified_gmt))), 'triggerId' => 'trigger-204-test', 'triggeredAt' => gmdate('c'), 'source' => 'wordpress', 'workflowId' => 'build-deploy.yml', 'ref' => 'master'), false);
$jg_ds_http_responses[$dispatch_endpoint] = array('code' => 204, 'body' => '');
JG_Dispatch::dispatch_if_changed();
$history = get_option('jg_dispatch_history', array());
$second_record = $history[0] ?? null;
jg_ds_assert(is_array($second_record) && ($second_record['dispatchStatus'] ?? '') === 'accepted', '204 dispatch must still be accepted.');
jg_ds_assert($second_record['workflowRunId'] === null && $second_record['runUrl'] === null && $second_record['runHtmlUrl'] === null, '204 dispatch must not fabricate a run ID or run URLs.');

// Dispatch failure: not_triggered plus a sanitized error, pending retained for retry.
delete_option('jg_last_dispatched_revision');
update_option('jg_dispatch_pending', array('types' => array('content'), 'actions' => array('updated'), 'attempts' => 0, 'contentRefs' => array(array('contentType' => 'diary', 'contentId' => (int) $published_c, 'modifiedAt' => jg_ds_iso_time(get_post($published_c)->post_modified_gmt))), 'triggerId' => 'trigger-fail-test', 'triggeredAt' => gmdate('c'), 'source' => 'wordpress', 'workflowId' => 'build-deploy.yml', 'ref' => 'master'), false);
$jg_ds_http_responses[$dispatch_endpoint] = array('code' => 500, 'body' => '');
JG_Dispatch::dispatch_if_changed();
$history = get_option('jg_dispatch_history', array());
$failed_record = $history[0] ?? null;
jg_ds_assert(is_array($failed_record) && ($failed_record['dispatchStatus'] ?? '') === 'failed', 'Dispatch failure was not recorded.');
jg_ds_assert(($failed_record['buildStatus'] ?? '') === 'not_triggered', 'Failed dispatch must not be build pending or success.');
jg_ds_assert(($failed_record['errorCode'] ?? '') === 'jg_dispatch_http' && !empty($failed_record['errorSummary']), 'Dispatch failure error metadata is missing.');
$pending_after_fail = get_option('jg_dispatch_pending', array());
jg_ds_assert((int) ($pending_after_fail['attempts'] ?? 0) === 1, 'Failed dispatch did not retain pending retry state.');
wp_clear_scheduled_hook(JG_Dispatch::CRON_HOOK);

// ---- GitHub run status mapping + caching + failure fallback --------------
$run_url = 'https://api.github.com/repos/1Huang1-1/JaisonG1n-Blog/actions/runs/123';
$mappings = array(
	'queued' => 'queued',
	'in_progress' => 'in_progress',
	'completed-success' => 'success',
	'completed-failure' => 'failed',
	'completed-cancelled' => 'cancelled',
	'completed-timed_out' => 'failed',
	'weird' => 'unknown',
);
foreach ($mappings as $case => $expected) {
	list($status, $conclusion) = explode('-', $case) + array(1 => '');
	$jg_ds_http_responses[$run_url] = array('code' => 200, 'body' => wp_json_encode(array(
		'status' => $status,
		'conclusion' => $conclusion !== '' ? $conclusion : null,
		'started_at' => '2026-08-01T10:00:00Z',
		'completed_at' => $status === 'completed' ? '2026-08-01T10:05:00Z' : null,
	)));
	delete_transient('jg_dispatch_run_123');
	$run = JG_Dispatch::query_run(123, array());
	jg_ds_assert($run['buildStatus'] === $expected, 'Run status mapping failed for ' . $case . ': ' . (string) $run['buildStatus']);
	if ($status === 'completed' && $conclusion === 'success') {
		jg_ds_assert($run['buildConclusion'] === 'success' && $run['startedAt'] !== null && $run['completedAt'] !== null, 'Run timestamps were not parsed.');
	}
}

delete_transient('jg_dispatch_run_123');
$jg_ds_http_responses[$run_url] = array('code' => 200, 'body' => wp_json_encode(array('status' => 'queued', 'conclusion' => null)));
$calls_before_cache = count($jg_ds_http_log);
$run_first = JG_Dispatch::query_run(123, array());
$run_second = JG_Dispatch::query_run(123, array());
jg_ds_assert($run_first['buildStatus'] === 'queued' && $run_second['buildStatus'] === 'queued', 'Run query did not return queued.');
jg_ds_assert(count($jg_ds_http_log) === $calls_before_cache + 1, 'Run query did not reuse the short-lived cache.');

foreach (array(403, 404, 429, 500) as $http_code) {
	delete_transient('jg_dispatch_run_456');
	$jg_ds_http_responses['https://api.github.com/repos/1Huang1-1/JaisonG1n-Blog/actions/runs/456'] = array('code' => $http_code, 'body' => '');
	$fallback = JG_Dispatch::query_run(456, array('buildStatus' => 'in_progress', 'buildConclusion' => null));
	jg_ds_assert($fallback['buildStatus'] === 'in_progress', 'HTTP ' . $http_code . ' run query must keep the last known state.');
	jg_ds_assert(($fallback['errorCode'] ?? '') === 'jg_dispatch_run_http_' . $http_code, 'HTTP ' . $http_code . ' run query error code is incorrect.');
}
delete_transient('jg_dispatch_run_456');
$jg_ds_http_responses['https://api.github.com/repos/1Huang1-1/JaisonG1n-Blog/actions/runs/456'] = array('error' => new WP_Error('http_request_failed', 'fixture network error'));
$fallback = JG_Dispatch::query_run(456, array('buildStatus' => 'success', 'buildConclusion' => 'success'));
jg_ds_assert($fallback['buildStatus'] === 'success' && ($fallback['errorCode'] ?? '') === 'jg_dispatch_run_network', 'Network error run query did not keep the last known state.');

// ---- REST deployment status endpoint --------------------------------------
$caps = jg_ds_request('GET', '/jaisong1n/v1/ai/capabilities')->get_data();
jg_ds_assert(($caps['schemaVersion'] ?? null) === 5, 'schemaVersion must stay 5 for the additive deployment status capability.');
jg_ds_assert(in_array('deploymentStatus', $caps['contentTypes']['diary']['operations'], true), 'diary capabilities are missing deploymentStatus.');
jg_ds_assert(in_array('deploymentStatus', $caps['contentTypes']['article']['operations'], true), 'article capabilities are missing deploymentStatus.');
foreach ($caps['contentTypes'] as $type => $definition) {
	jg_ds_assert(in_array('deploymentStatus', $definition['operations'], true), $type . ' must expose the read-only deploymentStatus operation.');
	if ($type !== 'diary') jg_ds_assert(!in_array('preparePublish', $definition['operations'], true), 'deploymentStatus leaked publish capability to ' . $type . '.');
}

// Draft with no record: five status layers stay separated.
$draft_status = jg_ds_request('GET', '/jaisong1n/v1/ai/content/diary/' . $chinese_post . '/deployment-status')->get_data();
jg_ds_assert(($draft_status['wordpressStatus'] ?? '') === 'draft', 'Draft wordpressStatus is incorrect.');
jg_ds_assert(($draft_status['buildStatus'] ?? '') === 'not_triggered' && ($draft_status['dispatchStatus'] ?? null) === null, 'Draft must be not_triggered without a dispatch record.');
jg_ds_assert(($draft_status['pageStatus'] ?? '') === 'unchecked', 'Draft pageStatus must be unchecked.');
jg_ds_assert(($draft_status['deploymentStatus'] ?? '') === 'unknown', 'Draft deploymentStatus must be unknown.');
jg_ds_assert(!empty($draft_status['cmsUrl']) && str_contains((string) $draft_status['publicUrl'], 'https://jaisong1n.com/diary/'), 'cmsUrl and publicUrl must be separate.');

// Published content + accepted record + run success + page 200 -> deployed.
$record_has_run = JG_Dispatch::find_latest_record_for_content('diary', (int) $published_a, trim((string) get_post($published_a)->post_modified_gmt));
jg_ds_assert(is_array($record_has_run) && (int) ($record_has_run['workflowRunId'] ?? 0) === 123, 'Content association did not find the record containing diary A.');
$published_probe_url = 'https://jaisong1n.com/diary/dispatch-diary-a/';
$jg_ds_http_responses[$published_probe_url] = array('code' => 200, 'body' => '<html>ok</html>');
$jg_ds_http_responses[$run_url] = array('code' => 200, 'body' => wp_json_encode(array('status' => 'completed', 'conclusion' => 'success', 'started_at' => '2026-08-01T10:00:00Z', 'completed_at' => '2026-08-01T10:05:00Z')));
delete_transient('jg_dispatch_run_123');
delete_transient('jg_page_probe_' . md5($published_probe_url));
$published_status = jg_ds_request('GET', '/jaisong1n/v1/ai/content/diary/' . $published_a . '/deployment-status')->get_data();
jg_ds_assert(($published_status['buildStatus'] ?? '') === 'success' && ($published_status['buildConclusion'] ?? '') === 'success', 'Published build status was not mapped to success.');
jg_ds_assert(($published_status['pageStatus'] ?? '') === 'reachable' && ($published_status['deploymentStatus'] ?? '') === 'deployed', 'Reachable page after build success must map to deployed.');
jg_ds_assert((int) ($published_status['workflowRunId'] ?? 0) === 123 && !empty($published_status['workflowRunUrl']), 'Run metadata is missing from the response.');
jg_ds_assert(!empty($published_status['triggeredAt']) && !empty($published_status['startedAt']) && !empty($published_status['completedAt']) && !empty($published_status['lastCheckedAt']), 'Response timestamps are incomplete.');

// Build success + page 404 -> deployment pending, page not_found.
$missing_probe = 'https://jaisong1n.com/diary/dispatch-diary-b/';
$jg_ds_http_responses[$missing_probe] = array('code' => 404, 'body' => '');
delete_transient('jg_page_probe_' . md5($missing_probe));
$published_b_status = jg_ds_request('GET', '/jaisong1n/v1/ai/content/diary/' . $published_b . '/deployment-status')->get_data();
jg_ds_assert(($published_b_status['pageStatus'] ?? '') === 'not_found' && ($published_b_status['deploymentStatus'] ?? '') === 'pending', '404 page after build success must stay deployment pending.');

// A later dispatch for another content must not hijack the association.
delete_option('jg_last_dispatched_revision');
update_option('jg_dispatch_pending', array('types' => array('content'), 'actions' => array('updated'), 'attempts' => 0, 'contentRefs' => array(array('contentType' => 'article', 'contentId' => (int) $article_post, 'modifiedAt' => jg_ds_iso_time(get_post($article_post)->post_modified_gmt))), 'triggerId' => 'trigger-article-test', 'triggeredAt' => gmdate('c'), 'source' => 'wordpress', 'workflowId' => 'build-deploy.yml', 'ref' => 'master'), false);
$jg_ds_http_responses[$dispatch_endpoint] = array('code' => 200, 'body' => wp_json_encode(array('workflow_run_id' => 789, 'run_url' => 'https://api.github.com/repos/1Huang1-1/JaisonG1n-Blog/actions/runs/789', 'html_url' => 'https://github.com/1Huang1-1/JaisonG1n-Blog/actions/runs/789')));
JG_Dispatch::dispatch_if_changed();
$still_associated = JG_Dispatch::find_latest_record_for_content('diary', (int) $published_a, trim((string) get_post($published_a)->post_modified_gmt));
jg_ds_assert(is_array($still_associated) && (int) ($still_associated['workflowRunId'] ?? 0) === 123, 'A later unrelated dispatch hijacked the content association.');
$no_record_diary = JG_Dispatch::find_latest_record_for_content('diary', (int) $diary_post, trim((string) get_post($diary_post)->post_modified_gmt));
jg_ds_assert($no_record_diary === null, 'Legacy/no-ref records must not be bound to arbitrary content.');

// Article deployment status: associates its own dispatch record and canonical URL.
$jg_ds_http_responses['https://api.github.com/repos/1Huang1-1/JaisonG1n-Blog/actions/runs/789'] = array('code' => 200, 'body' => wp_json_encode(array('status' => 'completed', 'conclusion' => 'success', 'started_at' => '2026-08-01T11:00:00Z', 'completed_at' => '2026-08-01T11:05:00Z')));
$jg_ds_http_responses['https://jaisong1n.com/posts/my-article/'] = array('code' => 200, 'body' => '<html>ok</html>');
delete_transient('jg_dispatch_run_789');
delete_transient('jg_page_probe_' . md5('https://jaisong1n.com/posts/my-article/'));
$article_status = jg_ds_request('GET', '/jaisong1n/v1/ai/content/article/' . $article_post . '/deployment-status')->get_data();
jg_ds_assert(($article_status['contentType'] ?? '') === 'article' && (int) ($article_status['workflowRunId'] ?? 0) === 789, 'Article deployment status did not associate its dispatch record.');
jg_ds_assert(($article_status['publicUrl'] ?? '') === 'https://jaisong1n.com/posts/my-article/', 'Article canonical public URL is incorrect in deployment status.');
jg_ds_assert(($article_status['buildStatus'] ?? '') === 'success' && ($article_status['pageStatus'] ?? '') === 'reachable' && ($article_status['deploymentStatus'] ?? '') === 'deployed', 'Article deployment status layers are incorrect.');

// Regression: content merged into an existing debounce batch after the batch
// started must still associate with the resulting dispatch record. The record
// stores the batch-start triggeredAt, so association must use the actual
// dispatch time (dispatchedAt) when deciding whether a change was covered.
$batch_diary_id = wp_insert_post(array('post_type' => 'jg_diary', 'post_status' => 'publish', 'post_author' => (int) $user_id, 'post_title' => 'Batch diary', 'post_name' => 'batch-diary', 'post_content' => '<p>d</p>'));
$batch_article_id = wp_insert_post(array('post_type' => 'post', 'post_status' => 'publish', 'post_author' => (int) $user_id, 'post_title' => 'Batch article', 'post_name' => 'batch-article', 'post_content' => '<p>a</p>'));
update_post_meta($batch_diary_id, '_jg_ai_owner_user_id', (int) $user_id);
update_post_meta($batch_article_id, '_jg_ai_owner_user_id', (int) $user_id);
delete_option('jg_dispatch_pending');
wp_clear_scheduled_hook(JG_Dispatch::CRON_HOOK);
JG_Dispatch::post_saved((int) $batch_diary_id, get_post($batch_diary_id), true);
$batch_start = get_option('jg_dispatch_pending', array())['triggeredAt'] ?? '';
jg_ds_assert($batch_start !== '', 'Debounce batch start is missing.');
global $wpdb;
$wpdb->update($wpdb->posts, array('post_modified' => '2026-08-03 05:00:00', 'post_modified_gmt' => '2026-08-03 05:00:00'), array('ID' => (int) $batch_article_id));
clean_post_cache((int) $batch_article_id);
JG_Dispatch::post_saved((int) $batch_article_id, get_post($batch_article_id), true);
$merged_pending = get_option('jg_dispatch_pending', array());
jg_ds_assert(($merged_pending['triggeredAt'] ?? '') === $batch_start, 'Debounce merge reset the batch start.');
jg_ds_assert(count($merged_pending['contentRefs'] ?? array()) === 2, 'Debounce merge did not accumulate both content refs.');
$batch_article_mod = trim((string) get_post($batch_article_id)->post_modified_gmt);
$jg_ds_http_responses[$dispatch_endpoint] = array('code' => 200, 'body' => wp_json_encode(array('workflow_run_id' => 555, 'run_url' => 'https://api.github.com/repos/1Huang1-1/JaisonG1n-Blog/actions/runs/555', 'html_url' => 'https://github.com/1Huang1-1/JaisonG1n-Blog/actions/runs/555')));
delete_option('jg_last_dispatched_revision');
JG_Dispatch::dispatch_if_changed();
$batch_history = get_option('jg_dispatch_history', array());
$batch_record = $batch_history[0] ?? null;
jg_ds_assert(is_array($batch_record) && (int) ($batch_record['workflowRunId'] ?? 0) === 555, 'Merged batch did not dispatch once with run id 555.');
jg_ds_assert(count($batch_record['contentRefs'] ?? array()) === 2, 'Merged batch record lost content refs.');
jg_ds_assert(isset($batch_record['dispatchedAt']) && strtotime($batch_record['dispatchedAt']) >= strtotime($batch_article_mod), 'Dispatch record dispatchedAt predates a merged content change.');
$batch_article_found = JG_Dispatch::find_latest_record_for_content('article', (int) $batch_article_id, $batch_article_mod);
jg_ds_assert(is_array($batch_article_found) && (int) ($batch_article_found['workflowRunId'] ?? 0) === 555, 'Later-merged article could not associate with the dispatch record.');
$batch_diary_found = JG_Dispatch::find_latest_record_for_content('diary', (int) $batch_diary_id, trim((string) get_post($batch_diary_id)->post_modified_gmt));
jg_ds_assert(is_array($batch_diary_found) && (int) ($batch_diary_found['workflowRunId'] ?? 0) === 555, 'First content of the merged batch lost its dispatch record.');

// Legacy history entries without contentRefs are never hard-bound.
$legacy_history = get_option('jg_dispatch_history', array());
$legacy_history[] = array('state' => 'success', 'message' => 'legacy', 'time' => gmdate('c'), 'workflow_run_id' => 999);
update_option('jg_dispatch_history', $legacy_history, false);
$legacy_lookup = JG_Dispatch::find_latest_record_for_content('diary', 999999, '');
jg_ds_assert($legacy_lookup === null, 'Legacy history entry without contentRefs was hard-bound to content.');

// Permission: an unreadable subscriber cannot see the endpoint.
$subscriber_id = wp_create_user('jg-ds-subscriber', wp_generate_password(24), 'jg-ds-subscriber@example.test');
(new WP_User((int) $subscriber_id))->set_role('subscriber');
wp_set_current_user((int) $subscriber_id);
$forbidden = jg_ds_request('GET', '/jaisong1n/v1/ai/content/diary/' . $published_a . '/deployment-status');
jg_ds_assert($forbidden->get_status() === 404, 'Unreadable content must not expose deployment status.');
wp_set_current_user(0);
$unauthenticated = jg_ds_request('GET', '/jaisong1n/v1/ai/content/diary/' . $published_a . '/deployment-status');
jg_ds_assert($unauthenticated->get_status() === 401, 'Deployment status must require authentication.');
wp_set_current_user((int) $user_id);

// Audit and redaction.
$audit_json = wp_json_encode(get_option('jg_ai_content_audit', array()));
jg_ds_assert(str_contains($audit_json, 'deploymentStatus'), 'Audit is missing deploymentStatus queries.');
jg_ds_assert(!str_contains($audit_json, 'Bearer') && !str_contains($audit_json, 'fixture-token') && !str_contains($audit_json, 'Authorization'), 'Audit leaked credential material.');
$response_json = wp_json_encode($published_status);
jg_ds_assert(!str_contains($response_json, 'jg_github_token') && !str_contains($response_json, 'Bearer') && !str_contains($response_json, 'fixture-token'), 'Deployment status response leaked secrets.');

echo wp_json_encode(array(
	'ok' => true,
	'assertions' => $jg_ds_assertions,
	'schemaVersion' => $caps['schemaVersion'],
	'recordRunId' => $first_record['workflowRunId'] ?? null,
	'deployedDiary' => (int) $published_a,
), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
