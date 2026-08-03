<?php

if (!defined('ABSPATH')) require_once '/wordpress/wp-load.php';
require_once dirname(__DIR__) . '/jaisong1n-site-manager.php';

$jg_stats_assertions = 0;
function jg_stats_assert(bool $condition, string $message): void {
	global $jg_stats_assertions;
	$jg_stats_assertions++;
	if (!$condition) throw new RuntimeException($message);
}
function jg_stats_request(string $method, string $route, $body = null, array $headers = array()): WP_REST_Response {
	$request = new WP_REST_Request($method, $route);
	if ($body !== null) {
		$request->set_body(is_string($body) ? $body : wp_json_encode($body));
		$request->set_header('Content-Type', 'application/json');
	}
	foreach ($headers as $name => $value) $request->set_header($name, $value);
	return rest_do_request($request);
}
function jg_stats_uuid(string $seed): string {
	$hash = hash('sha256', $seed);
	return substr($hash, 0, 8) . '-' . substr($hash, 8, 4) . '-4' . substr($hash, 13, 3) . '-' . substr($hash, 16, 4) . '-' . substr($hash, 20, 12);
}

JG_Content_Types::register();
JG_Content_Stats::install();
do_action('rest_api_init');

$article_id = wp_insert_post(array('post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Stats article', 'post_name' => 'stats-article', 'post_content' => '<p>a</p>'));
$diary_id = wp_insert_post(array('post_type' => 'jg_diary', 'post_status' => 'publish', 'post_title' => 'Stats diary', 'post_name' => 'stats-diary', 'post_content' => '<p>d</p>'));
$draft_id = wp_insert_post(array('post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'Stats draft', 'post_name' => 'stats-draft', 'post_content' => '<p>x</p>'));
$encoded_slug = '%e4%bd%a0%e5%a5%bd';
$decoded_slug = rawurldecode($encoded_slug);
$decoded_storage_slug = rawurldecode('%e5%86%8d%e8%a7%81');
$cjk_encoded_id = wp_insert_post(array('post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Stats CJK encoded', 'post_content' => '<p>c</p>'));
$wpdb->update($wpdb->posts, array('post_name' => $encoded_slug), array('ID' => $cjk_encoded_id));
clean_post_cache($cjk_encoded_id);
$cjk_decoded_id = wp_insert_post(array('post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Stats CJK decoded', 'post_content' => '<p>d</p>'));
$wpdb->update($wpdb->posts, array('post_name' => $decoded_storage_slug), array('ID' => $cjk_decoded_id));
clean_post_cache($cjk_decoded_id);
jg_stats_assert(!is_wp_error($article_id) && !is_wp_error($diary_id) && !is_wp_error($draft_id) && !is_wp_error($cjk_encoded_id) && !is_wp_error($cjk_decoded_id), 'Could not create stats fixtures.');

global $wpdb;
$stats_table = $wpdb->prefix . 'jg_content_stats';
$events_table = $wpdb->prefix . 'jg_view_events';
jg_stats_assert((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $stats_table)) === $stats_table, 'Content stats table is missing.');
jg_stats_assert((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $events_table)) === $events_table, 'View events table is missing.');

$rate_ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
delete_transient('jg_view_rate_' . substr(hash('sha256', $rate_ip), 0, 24));
delete_option('jg_dispatch_pending');
wp_clear_scheduled_hook(JG_Dispatch::CRON_HOOK);
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; TestBrowser)';

$modified_before = get_post($article_id)->post_modified_gmt;
$event_a = jg_stats_uuid('article-first');
$response = jg_stats_request('POST', '/jg-public/v1/content/article/' . $article_id . '/view', array('eventId' => $event_a));
$data = $response->get_data();
jg_stats_assert($response->get_status() === 200 && $data['counted'] === true && $data['views'] === 1, 'First article view did not count once.');

$response = jg_stats_request('POST', '/jg-public/v1/content/article/' . $article_id . '/view', array('eventId' => $event_a));
$data = $response->get_data();
jg_stats_assert($response->get_status() === 200 && $data['counted'] === false && $data['views'] === 1, 'Repeated eventId increased the article view count.');

$response = jg_stats_request('POST', '/jg-public/v1/content/article/' . $article_id . '/view', array('eventId' => jg_stats_uuid('article-second')));
$data = $response->get_data();
jg_stats_assert($data['counted'] === true && $data['views'] === 2, 'Distinct eventId for the same article did not increment.');

// Same eventId on a different content must count independently (hash binding).
$response = jg_stats_request('POST', '/jg-public/v1/content/diary/' . $diary_id . '/view', array('eventId' => $event_a));
$data = $response->get_data();
jg_stats_assert($data['counted'] === true && $data['views'] === 1, 'Same eventId on a different content did not count independently.');
$response = jg_stats_request('POST', '/jg-public/v1/content/diary/' . $diary_id . '/view', array('eventId' => $event_a));
$data = $response->get_data();
jg_stats_assert($data['counted'] === false && $data['views'] === 1, 'Repeated eventId on the second content was not deduplicated.');
$response = jg_stats_request('POST', '/jg-public/v1/content/article/' . $article_id . '/view', array('eventId' => $event_a));
$data = $response->get_data();
jg_stats_assert($data['counted'] === false && $data['views'] === 2, 'Cross-content eventId replay leaked into the article count.');

// Slug-based resolution (the frontend only knows URL slugs).
$response = jg_stats_request('POST', '/jg-public/v1/content/article/stats-article/view', array('eventId' => jg_stats_uuid('slug-ascii')));
$data = $response->get_data();
jg_stats_assert($response->get_status() === 200 && $data['counted'] === true && $data['id'] === $article_id && $data['views'] === 3, 'ASCII slug resolution failed.');

// WordPress may store CJK post_name percent-encoded; the URL slug arrives
// percent-encoded as well. Both stored forms must resolve.
$cjk_encoded_slug_response = jg_stats_request('POST', '/jg-public/v1/content/article/' . $encoded_slug . '/view', array('eventId' => jg_stats_uuid('slug-encoded')));
$data = $cjk_encoded_slug_response->get_data();
jg_stats_assert($cjk_encoded_slug_response->get_status() === 200 && $data['counted'] === true && $data['id'] === $cjk_encoded_id, 'Percent-encoded CJK slug did not resolve.');

$cjk_decoded_slug_response = jg_stats_request('POST', '/jg-public/v1/content/article/' . rawurlencode($decoded_storage_slug) . '/view', array('eventId' => jg_stats_uuid('slug-decoded')));
$data = $cjk_decoded_slug_response->get_data();
jg_stats_assert($cjk_decoded_slug_response->get_status() === 200 && $data['counted'] === true && $data['id'] === $cjk_decoded_id, 'Decoded CJK slug did not resolve.');

jg_stats_assert(get_post($article_id)->post_modified_gmt === $modified_before, 'View counting changed post_modified.');
jg_stats_assert(get_option('jg_dispatch_pending', array()) === array() && !wp_next_scheduled(JG_Dispatch::CRON_HOOK), 'View counting created dispatch pending or a Cron event.');

// Invalid inputs.
jg_stats_assert(jg_stats_request('POST', '/jg-public/v1/content/project/' . $article_id . '/view', array('eventId' => jg_stats_uuid('x')))->get_status() === 400, 'Invalid content type was accepted.');
jg_stats_assert(in_array(jg_stats_request('POST', '/jg-public/v1/content/article/abc/view', array('eventId' => jg_stats_uuid('x')))->get_status(), array(400, 404), true), 'Non-numeric id was not rejected.');
jg_stats_assert(jg_stats_request('POST', '/jg-public/v1/content/article/' . $article_id . '/view', array('eventId' => 'not-a-uuid'))->get_status() === 400, 'Invalid eventId was accepted.');
jg_stats_assert(jg_stats_request('POST', '/jg-public/v1/content/article/' . $article_id . '/view', '{"eventId":"' . jg_stats_uuid('big') . '","padding":"' . str_repeat('x', 2048) . '"}')->get_status() === 413, 'Oversized body was accepted.');
jg_stats_assert(jg_stats_request('POST', '/jg-public/v1/content/article/999999/view', array('eventId' => jg_stats_uuid('missing')))->get_status() === 404, 'Missing content was not rejected.');
jg_stats_assert(jg_stats_request('POST', '/jg-public/v1/content/article/' . $draft_id . '/view', array('eventId' => jg_stats_uuid('draft')))->get_status() === 404, 'Draft content was counted.');

// Bot user agent does not count.
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
$bot_response = jg_stats_request('POST', '/jg-public/v1/content/article/' . $article_id . '/view', array('eventId' => jg_stats_uuid('bot')));
$bot_data = $bot_response->get_data();
jg_stats_assert($bot_response->get_status() === 200 && $bot_data['counted'] === false && $bot_data['views'] === 3 && $bot_data['id'] === $article_id, 'Bot user agent was counted or resolved to the wrong id.');
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; TestBrowser)';

// Rate limiting.
$rate_ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
$rate_key = 'jg_view_rate_' . substr(hash('sha256', $rate_ip), 0, 24);
set_transient($rate_key, 60, MINUTE_IN_SECONDS);
$limited = jg_stats_request('POST', '/jg-public/v1/content/article/' . $article_id . '/view', array('eventId' => jg_stats_uuid('rate')));
jg_stats_assert($limited->get_status() === 429, 'Rate limit did not trigger.');
delete_transient($rate_key);

// Concurrency: distinct events never lose counts (atomic increments).
$start = jg_stats_request('POST', '/jg-public/v1/content/diary/' . $diary_id . '/view', array('eventId' => jg_stats_uuid('c0')))->get_data()['views'];
for ($i = 1; $i <= 20; $i++) {
	$result = jg_stats_request('POST', '/jg-public/v1/content/diary/' . $diary_id . '/view', array('eventId' => jg_stats_uuid('c' . $i)));
	jg_stats_assert($result->get_data()['counted'] === true, 'Concurrent distinct event was not counted.');
}
$final_views = jg_stats_request('POST', '/jg-public/v1/content/diary/' . $diary_id . '/view', array('eventId' => jg_stats_uuid('c-check')))->get_data()['views'];
jg_stats_assert($final_views === $start + 21, 'Atomic increment lost counts: ' . $final_views . ' != ' . ($start + 21));

// CORS: allowed origin passes preflight, disallowed origin is rejected.
$cors_server_origin = $_SERVER['HTTP_ORIGIN'] ?? null;
$_SERVER['HTTP_ORIGIN'] = 'https://evil.example';
$blocked = jg_stats_request('POST', '/jg-public/v1/content/article/' . $article_id . '/view', array('eventId' => jg_stats_uuid('origin')), array('Origin' => 'https://evil.example'));
jg_stats_assert($blocked->get_status() === 403, 'Disallowed origin was not rejected.');
$_SERVER['HTTP_ORIGIN'] = 'https://jaisong1n.com';
$preflight = jg_stats_request('OPTIONS', '/jg-public/v1/content/article/' . $article_id . '/view', null, array('Origin' => 'https://jaisong1n.com', 'Access-Control-Request-Method' => 'POST'));
jg_stats_assert(in_array($preflight->get_status(), array(200, 204), true) && ($preflight->get_headers()['Access-Control-Allow-Origin'] ?? '') === 'https://jaisong1n.com', 'Allowed-origin preflight failed: status=' . $preflight->get_status() . ' headers=' . wp_json_encode($preflight->get_headers()));
if ($cors_server_origin === null) unset($_SERVER['HTTP_ORIGIN']);
else $_SERVER['HTTP_ORIGIN'] = $cors_server_origin;

echo wp_json_encode(array('ok' => true, 'assertions' => $jg_stats_assertions, 'articleId' => $article_id, 'diaryId' => $diary_id)) . "\n";
