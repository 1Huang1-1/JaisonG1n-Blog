<?php

if (!defined('ABSPATH')) require_once '/wordpress/wp-load.php';
require_once dirname(__DIR__) . '/jaisong1n-site-manager.php';

function jg_ai_test_assert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function jg_ai_test_request(string $method, string $route, array $body = array(), array $headers = array()): WP_REST_Response {
	$request = new WP_REST_Request($method, $route);
	foreach ($headers as $name => $value) $request->set_header($name, $value);
	if ($body) $request->set_body_params($body);
	return rest_do_request($request);
}

JG_Content_Types::register();
JG_AI_Content::install();
do_action('rest_api_init');
$admin = get_users(array('role' => 'administrator', 'number' => 1))[0];
wp_set_current_user($admin->ID);

$capabilities = jg_ai_test_request('GET', '/jaisong1n/v1/ai/capabilities');
jg_ai_test_assert($capabilities->get_status() === 200, 'Capabilities failed.');
$capability_data = $capabilities->get_data();
jg_ai_test_assert(isset($capability_data['contentTypes']['article']), 'Article capability is missing.');
jg_ai_test_assert(!str_contains(wp_json_encode($capability_data), '_jg_'), 'Capabilities expose internal meta keys.');

$body = array('contentType' => 'article', 'title' => 'AI draft', 'contentHtml' => '<p>safe</p><script>bad()</script>', 'idempotencyKey' => 'playground-ai-content-0001');
$created = jg_ai_test_request('POST', '/jaisong1n/v1/ai/content', $body, array('Idempotency-Key' => 'playground-ai-content-0001'));
jg_ai_test_assert($created->get_status() === 201, 'Draft creation failed.');
$created_data = $created->get_data();
jg_ai_test_assert($created_data['status'] === 'draft' && $created_data['id'] > 0, 'Draft result is invalid.');
$replayed = jg_ai_test_request('POST', '/jaisong1n/v1/ai/content', $body, array('Idempotency-Key' => 'playground-ai-content-0001'));
jg_ai_test_assert($replayed->get_status() === 200 && $replayed->get_data()['id'] === $created_data['id'], 'Idempotency replay failed.');
$conflict = jg_ai_test_request('POST', '/jaisong1n/v1/ai/content', array_merge($body, array('title' => 'Different')), array('Idempotency-Key' => 'playground-ai-content-0001'));
jg_ai_test_assert($conflict->get_status() === 409, 'Idempotency conflict was not rejected.');
$stale = jg_ai_test_request('PATCH', '/jaisong1n/v1/ai/content/article/' . $created_data['id'], array('expectedModifiedAt' => '2000-01-01T00:00:00Z', 'title' => 'No change'));
jg_ai_test_assert($stale->get_status() === 409, 'Stale update was not rejected.');
$publish = jg_ai_test_request('POST', '/jaisong1n/v1/ai/content/article/' . $created_data['id'] . '/publish', array('expectedModifiedAt' => $created_data['modifiedAt']));
jg_ai_test_assert($publish->get_status() === 403, 'Publishing must be disabled by default.');

echo wp_json_encode(array('ok' => true, 'articleId' => $created_data['id'], 'schemaVersion' => $capability_data['schemaVersion'])) . "\n";
