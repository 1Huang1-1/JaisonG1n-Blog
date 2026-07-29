<?php

define('ABSPATH', __DIR__ . '/');
require_once dirname(__DIR__) . '/includes/class-jg-content-policy.php';

function expect(bool $condition, string $message): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

$first = array(
	'generatedAt' => '2026-01-01T00:00:00Z',
	'revision' => 'ignored',
	'site' => array('title' => 'JaisonG1n', 'subtitle' => 'Blog'),
	'items' => array(array('id' => 1, 'name' => 'A')),
);
$second = array(
	'items' => array(array('name' => 'A', 'id' => 1)),
	'site' => array('subtitle' => 'Blog', 'title' => 'JaisonG1n'),
	'generatedAt' => '2026-12-31T23:59:59Z',
);

expect(JG_Content_Policy::revision($first) === JG_Content_Policy::revision($second), 'Dynamic fields or key order changed the revision.');
$second['site']['title'] = 'Changed';
expect(JG_Content_Policy::revision($first) !== JG_Content_Policy::revision($second), 'A public content change did not change the revision.');

$etag = '"abc"';
expect(JG_Content_Policy::etag_matches($etag, $etag), 'Exact ETag did not match.');
expect(JG_Content_Policy::etag_matches('W/"abc"', $etag), 'Weak ETag did not match.');
expect(JG_Content_Policy::etag_matches('"old", "abc"', $etag), 'ETag list did not match.');
expect(!JG_Content_Policy::etag_matches('"old"', $etag), 'Stale ETag matched unexpectedly.');
expect(!JG_Content_Policy::etag_matches(null, $etag), 'Missing ETag matched unexpectedly.');

echo "Content policy tests passed.\n";
