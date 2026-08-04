<?php

if (!defined('ABSPATH')) require_once '/wordpress/wp-load.php';
require_once dirname(__DIR__) . '/jaisong1n-site-manager.php';

$jg_media_assertions = 0;
function jg_media_assert(bool $condition, string $message): void {
	global $jg_media_assertions;
	$jg_media_assertions++;
	if (!$condition) throw new RuntimeException($message);
}
function jg_media_clear_rate(int $user_id): void {
	delete_transient('jg_ai_rate_' . $user_id . '_media');
}
function jg_media_request(string $method, string $route, array $body = array()): WP_REST_Response {
	$request = new WP_REST_Request($method, $route);
	if ($body) $request->set_body_params($body);
	return rest_do_request($request);
}
function jg_media_upload(int $user_id, array $file, array $body): WP_REST_Response {
	jg_media_clear_rate($user_id);
	wp_set_current_user($user_id);
	$_FILES['file'] = $file;
	$request = new WP_REST_Request('POST', '/jaisong1n/v1/ai/media');
	$request->set_file_params($_FILES);
	if ($body) $request->set_body_params($body);
	return rest_do_request($request);
}
function jg_media_get(int $user_id, int $id): WP_REST_Response {
	jg_media_clear_rate($user_id);
	wp_set_current_user($user_id);
	return jg_media_request('GET', '/jaisong1n/v1/ai/media/' . $id);
}
function jg_media_error_code(WP_REST_Response $response): string {
	$data = $response->get_data();
	return is_array($data) ? (string) ($data['code'] ?? '') : '';
}
function jg_media_make_image(string $kind, int $width, int $height): string {
	$image = imagecreatetruecolor($width, $height);
	$color = imagecolorallocate($image, 200, 100, 50);
	imagefill($image, 0, 0, $color);
	$tmp = tempnam(sys_get_temp_dir(), 'jg-media-');
	if ($kind === 'png') imagepng($image, $tmp);
	elseif ($kind === 'jpg') imagejpeg($image, $tmp, 90);
	else imagewebp($image, $tmp, 90);
	imagedestroy($image);
	return $tmp;
}
function jg_media_text_file(string $content): string {
	$tmp = tempnam(sys_get_temp_dir(), 'jg-media-');
	file_put_contents($tmp, $content);
	return $tmp;
}
function jg_media_bytes_file(string $bytes): string {
	$tmp = tempnam(sys_get_temp_dir(), 'jg-media-');
	file_put_contents($tmp, $bytes);
	return $tmp;
}
function jg_media_attachment_count(): int {
	return (int) wp_count_posts('attachment')->inherit;
}
function jg_media_normal_attachment(int $author_id, string $kind): int {
	$tmp = jg_media_make_image($kind, 4, 3);
	$uploads = wp_upload_dir();
	$name = 'ordinary-' . wp_generate_password(6, false) . ($kind === 'png' ? '.png' : ($kind === 'jpg' ? '.jpg' : '.webp'));
	$target = $uploads['path'] . '/' . $name;
	copy($tmp, $target);
	unlink($tmp);
	$attachment_id = wp_insert_attachment(array(
		'post_mime_type' => $kind === 'png' ? 'image/png' : ($kind === 'jpg' ? 'image/jpeg' : 'image/webp'),
		'post_title' => 'Ordinary media',
		'post_status' => 'inherit',
		'post_author' => $author_id,
	), $target);
	wp_generate_attachment_metadata($attachment_id, $target);
	return (int) $attachment_id;
}

JG_Content_Types::register();
JG_AI_Content::install();
JG_AI_Media::install();
do_action('rest_api_init');

$ai_user_1 = wp_create_user('jg-media-ai-1', wp_generate_password(24), 'jg-media-ai-1@example.test');
$ai_user_2 = wp_create_user('jg-media-ai-2', wp_generate_password(24), 'jg-media-ai-2@example.test');
$normal_user = wp_create_user('jg-media-normal', wp_generate_password(24), 'jg-media-normal@example.test');
jg_media_assert(!is_wp_error($ai_user_1) && !is_wp_error($ai_user_2) && !is_wp_error($normal_user), 'Could not create media test users.');
(new WP_User((int) $ai_user_1))->set_role('jg_ai_content_editor');
(new WP_User((int) $ai_user_2))->set_role('jg_ai_content_editor');
(new WP_User((int) $normal_user))->set_role('subscriber');
$ai_role = get_role('jg_ai_content_editor');
jg_media_assert($ai_role && $ai_role->has_cap('jg_ai_upload_media') && $ai_role->has_cap('upload_files'), 'AI role media capabilities are missing.');
jg_media_assert(!$ai_role->has_cap('manage_options') && !$ai_role->has_cap('edit_others_posts'), 'AI role was expanded beyond the media scope.');

wp_set_current_user((int) $ai_user_1);
$capabilities = jg_media_request('GET', '/jaisong1n/v1/ai/capabilities');
jg_media_assert($capabilities->get_status() === 200, 'Capabilities failed for the AI user.');
$capability_data = $capabilities->get_data();
jg_media_assert(in_array('uploadMedia', $capability_data['media']['operations'], true) && in_array('readMedia', $capability_data['media']['operations'], true), 'AI media operations are missing from capabilities.');
wp_set_current_user((int) $normal_user);
$capabilities_normal = jg_media_request('GET', '/jaisong1n/v1/ai/capabilities');
$capability_normal_data = $capabilities_normal->get_data();
jg_media_assert($capability_normal_data['media']['operations'] === array(), 'Normal user must not see AI media operations.');

// Authentication and permission guards.
wp_set_current_user(0);
$anonymous = jg_media_request('POST', '/jaisong1n/v1/ai/media');
jg_media_assert($anonymous->get_status() === 401 && jg_media_error_code($anonymous) === 'jg_ai_authentication_required', 'Anonymous upload was not rejected with 401.');
wp_set_current_user((int) $normal_user);
$normal_upload = jg_media_upload((int) $normal_user, array('name' => 'x.png', 'type' => 'image/png', 'tmp_name' => jg_media_make_image('png', 1, 1), 'error' => 0, 'size' => 1), array('idempotencyKey' => 'normal-key'));
jg_media_assert($normal_upload->get_status() === 403 && jg_media_error_code($normal_upload) === 'jg_ai_media_forbidden', 'Normal user upload was not rejected with 403.');

wp_set_current_user((int) $ai_user_1);
delete_option('jg_dispatch_pending');
wp_clear_scheduled_hook(JG_Dispatch::CRON_HOOK);
$base_attachments = jg_media_attachment_count();

// 1. PNG upload creates a real attachment.
$png_tmp = jg_media_make_image('png', 3, 2);
$png_bytes = (string) file_get_contents($png_tmp);
$png_body = array(
	'idempotencyKey' => 'media-png-0001',
	'title' => 'AI PNG',
	'altText' => 'Alt PNG',
	'caption' => 'Caption PNG',
	'description' => 'Description PNG',
	'attribution' => 'Example author',
	'sourceUrl' => 'https://example.com/source.png',
	'license' => 'CC BY 4.0',
	'licenseUrl' => 'https://creativecommons.org/licenses/by/4.0/',
);
$png = jg_media_upload((int) $ai_user_1, array('name' => 'ai-image.png', 'type' => 'image/png', 'tmp_name' => $png_tmp, 'error' => 0, 'size' => filesize($png_tmp)), $png_body);
$png_data = $png->get_data();
jg_media_assert($png->get_status() === 200 && $png_data['success'] === true && $png_data['reused'] === false, 'PNG upload did not create media: status=' . $png->get_status() . ' code=' . jg_media_error_code($png) . ' data=' . wp_json_encode($png_data));
jg_media_assert($png_data['mediaId'] > 0 && is_int($png_data['mediaId']), 'PNG upload returned no media ID.');
jg_media_assert($png_data['mimeType'] === 'image/png' && $png_data['width'] === 3 && $png_data['height'] === 2, 'PNG dimensions or MIME are wrong.');
jg_media_assert(preg_match('/^[a-f0-9]{64}$/', $png_data['sha256']) === 1, 'PNG SHA-256 is missing.');
jg_media_assert(str_starts_with($png_data['url'], 'http'), 'PNG upload returned no real URL.');
$png_id = (int) $png_data['mediaId'];
$png_attachment = get_post($png_id);
jg_media_assert($png_attachment && $png_attachment->post_type === 'attachment' && $png_attachment->post_mime_type === 'image/png', 'PNG attachment post was not created.');
jg_media_assert((int) get_post_meta($png_id, '_jg_ai_media_owner_user_id', true) === (int) $ai_user_1, 'AI media owner meta is wrong.');
jg_media_assert(get_post_meta($png_id, '_jg_ai_media_created', true) == 1, 'AI media created flag is missing.');
jg_media_assert(get_post_meta($png_id, '_jg_ai_media_idempotency_key', true) === 'media-png-0001', 'AI media idempotency meta is missing.');
jg_media_assert(get_post_meta($png_id, '_jg_ai_media_sha256', true) === $png_data['sha256'], 'AI media SHA meta is missing.');
jg_media_assert(get_post_meta($png_id, '_jg_ai_media_attribution', true) === 'Example author', 'Attribution meta is missing.');
jg_media_assert(get_post_meta($png_id, '_jg_ai_media_source_url', true) === 'https://example.com/source.png', 'Source URL meta is missing.');
jg_media_assert(get_post_meta($png_id, '_jg_ai_media_license', true) === 'CC BY 4.0', 'License meta is missing.');
jg_media_assert(get_post_meta($png_id, '_wp_attachment_image_alt', true) === 'Alt PNG', 'Alt text was not saved.');
$png_metadata = wp_get_attachment_metadata($png_id);
jg_media_assert((int) ($png_metadata['width'] ?? 0) === 3 && (int) ($png_metadata['height'] ?? 0) === 2, 'Attachment metadata dimensions are missing.');

// 2. JPEG and WebP uploads.
foreach (array('jpg' => 'image/jpeg', 'webp' => 'image/webp') as $kind => $expected_mime) {
	$tmp = jg_media_make_image($kind, 5, 4);
	$response = jg_media_upload((int) $ai_user_1, array('name' => 'ai-image.' . ($kind === 'jpg' ? 'jpg' : $kind), 'type' => $expected_mime, 'tmp_name' => $tmp, 'error' => 0, 'size' => filesize($tmp)), array('idempotencyKey' => 'media-' . $kind . '-0001'));
	$data = $response->get_data();
	jg_media_assert($response->get_status() === 200 && $data['success'] === true && $data['mimeType'] === $expected_mime && $data['width'] === 5 && $data['height'] === 4, 'Upload failed for ' . $kind . '.');
	jg_media_assert(get_post((int) $data['mediaId'])->post_mime_type === $expected_mime, 'Attachment MIME is wrong for ' . $kind . '.');
}

// 3. GET reads back the AI-owned media with full metadata.
$read_back = jg_media_get((int) $ai_user_1, $png_id);
$read_data = $read_back->get_data();
jg_media_assert($read_back->get_status() === 200 && $read_data['success'] === true && $read_data['mediaId'] === $png_id, 'GET media failed for the owner.');
$png_post_check = get_post($png_id);
jg_media_assert($read_data['title'] === 'AI PNG' && $read_data['altText'] === 'Alt PNG' && $read_data['caption'] === 'Caption PNG' && $read_data['description'] === 'Description PNG', 'GET media text fields are wrong: ' . wp_json_encode(array($read_data['title'], $read_data['altText'], $read_data['caption'], $read_data['description'], 'excerpt=' . $png_post_check->post_excerpt, 'content=' . $png_post_check->post_content)));
jg_media_assert($read_data['mimeType'] === 'image/png' && $read_data['width'] === 3 && $read_data['height'] === 2, 'GET media dimensions are wrong.');
jg_media_assert($read_data['attribution'] === 'Example author' && $read_data['sourceUrl'] === 'https://example.com/source.png' && $read_data['license'] === 'CC BY 4.0' && $read_data['licenseUrl'] === 'https://creativecommons.org/licenses/by/4.0/', 'GET media metadata is wrong.');
jg_media_assert($read_data['aiOwned'] === true && !empty($read_data['createdAt']) && !empty($read_data['modifiedAt']), 'GET media AI ownership or dates are wrong.');

// 4. Same key + same file is idempotent.
$before_reuse = jg_media_attachment_count();
$replay_tmp = jg_media_bytes_file($png_bytes);
$replayed = jg_media_upload((int) $ai_user_1, array('name' => 'ai-image.png', 'type' => 'image/png', 'tmp_name' => $replay_tmp, 'error' => 0, 'size' => strlen($png_bytes)), $png_body);
$replayed_data = $replayed->get_data();
jg_media_assert($replayed->get_status() === 200 && $replayed_data['reused'] === true && $replayed_data['mediaId'] === $png_id, 'Idempotency replay did not reuse the same media: status=' . $replayed->get_status() . ' code=' . jg_media_error_code($replayed) . ' data=' . wp_json_encode($replayed_data));
jg_media_assert(jg_media_attachment_count() === $before_reuse, 'Idempotency replay created a duplicate attachment.');

// 5. Same key + different content conflicts.
$different_tmp = jg_media_make_image('png', 6, 6);
$conflict = jg_media_upload((int) $ai_user_1, array('name' => 'ai-image.png', 'type' => 'image/png', 'tmp_name' => $different_tmp, 'error' => 0, 'size' => filesize($different_tmp)), $png_body);
jg_media_assert($conflict->get_status() === 409 && jg_media_error_code($conflict) === 'jg_ai_media_idempotency_conflict', 'Same key with different content was not rejected with 409.');

// 6. Same SHA + different key reuses the same media.
$same_sha_tmp = jg_media_bytes_file($png_bytes);
$same_sha = jg_media_upload((int) $ai_user_1, array('name' => 'ai-image.png', 'type' => 'image/png', 'tmp_name' => $same_sha_tmp, 'error' => 0, 'size' => strlen($png_bytes)), array('idempotencyKey' => 'media-png-0002', 'altText' => 'New alt'));
$same_sha_data = $same_sha->get_data();
jg_media_assert($same_sha->get_status() === 200 && $same_sha_data['reused'] === true && $same_sha_data['mediaId'] === $png_id, 'Same SHA with a different key did not reuse the media.');

// 7. Dedup is scoped to the AI owner.
$other_owner_tmp = jg_media_bytes_file($png_bytes);
$other_owner = jg_media_upload((int) $ai_user_2, array('name' => 'ai-image.png', 'type' => 'image/png', 'tmp_name' => $other_owner_tmp, 'error' => 0, 'size' => strlen($png_bytes)), array('idempotencyKey' => 'media-other-0001'));
$other_owner_data = $other_owner->get_data();
jg_media_assert($other_owner->get_status() === 200 && $other_owner_data['reused'] === false && (int) $other_owner_data['mediaId'] !== $png_id, 'Dedup leaked across AI owners.');
jg_media_assert((int) get_post_meta((int) $other_owner_data['mediaId'], '_jg_ai_media_owner_user_id', true) === (int) $ai_user_2, 'Second owner media ownership is wrong.');

// 8. Ordinary user media is not exposed through the AI GET endpoint.
$ordinary = jg_media_normal_attachment((int) $normal_user, 'png');
wp_set_current_user((int) $ai_user_1);
$ordinary_read = jg_media_get((int) $ai_user_1, $ordinary);
jg_media_assert($ordinary_read->get_status() === 403 && jg_media_error_code($ordinary_read) === 'jg_ai_media_forbidden', 'Ordinary user media was exposed through the AI endpoint.');
$missing = jg_media_get((int) $ai_user_1, 999999);
jg_media_assert($missing->get_status() === 404, 'Missing media did not return 404.');

// 9. Dangerous content is rejected.
$svg_tmp = jg_media_text_file('<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"></svg>');
$svg = jg_media_upload((int) $ai_user_1, array('name' => 'x.svg', 'type' => 'image/svg+xml', 'tmp_name' => $svg_tmp, 'error' => 0, 'size' => filesize($svg_tmp)), array('idempotencyKey' => 'media-bad-svg'));
jg_media_assert($svg->get_status() === 415, 'SVG upload was not rejected.');
$php_tmp = jg_media_text_file('<?php echo "pwned";');
$php = jg_media_upload((int) $ai_user_1, array('name' => 'shell.php', 'type' => 'image/png', 'tmp_name' => $php_tmp, 'error' => 0, 'size' => filesize($php_tmp)), array('idempotencyKey' => 'media-bad-php'));
jg_media_assert($php->get_status() === 415, 'PHP file upload was not rejected.');
$html_tmp = jg_media_text_file('<!DOCTYPE html><html><body>bad</body></html>');
$html = jg_media_upload((int) $ai_user_1, array('name' => 'x.html', 'type' => 'text/html', 'tmp_name' => $html_tmp, 'error' => 0, 'size' => filesize($html_tmp)), array('idempotencyKey' => 'media-bad-html'));
jg_media_assert($html->get_status() === 415, 'HTML upload was not rejected.');
$fake_tmp = jg_media_text_file('this is plain text pretending to be an image');
$fake = jg_media_upload((int) $ai_user_1, array('name' => 'fake.png', 'type' => 'image/png', 'tmp_name' => $fake_tmp, 'error' => 0, 'size' => filesize($fake_tmp)), array('idempotencyKey' => 'media-bad-fake'));
jg_media_assert(in_array($fake->get_status(), array(415, 422), true), 'Text masquerading as PNG was not rejected.');
$corrupt_tmp = jg_media_text_file("\x89PNG\r\n\x1a\n" . str_repeat("\x00", 64));
$corrupt = jg_media_upload((int) $ai_user_1, array('name' => 'corrupt.png', 'type' => 'image/png', 'tmp_name' => $corrupt_tmp, 'error' => 0, 'size' => filesize($corrupt_tmp)), array('idempotencyKey' => 'media-bad-corrupt'));
jg_media_assert(in_array($corrupt->get_status(), array(415, 422), true), 'Corrupt image was not rejected: status=' . $corrupt->get_status() . ' code=' . jg_media_error_code($corrupt));

// 10. Oversized files are rejected (filtered limit for the test).
add_filter('jg_ai_media_max_bytes', static fn() => 1024);
$big_image = imagecreatetruecolor(80, 80);
for ($x = 0; $x < 80; $x++) {
	for ($y = 0; $y < 80; $y++) {
		imagesetpixel($big_image, $x, $y, imagecolorallocate($big_image, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
	}
}
$big_tmp = tempnam(sys_get_temp_dir(), 'jg-media-');
imagepng($big_image, $big_tmp);
imagedestroy($big_image);
$big = jg_media_upload((int) $ai_user_1, array('name' => 'big.png', 'type' => 'image/png', 'tmp_name' => $big_tmp, 'error' => 0, 'size' => filesize($big_tmp)), array('idempotencyKey' => 'media-bad-big'));
jg_media_assert($big->get_status() === 413 && jg_media_error_code($big) === 'jg_ai_media_file_too_large', 'Oversized upload was not rejected with 413.');
remove_filter('jg_ai_media_max_bytes', static fn() => 1024);

// 11. Path traversal filenames are sanitized.
$traversal_tmp = jg_media_make_image('png', 2, 2);
$traversal = jg_media_upload((int) $ai_user_1, array('name' => '../../../../tmp/evil.png', 'type' => 'image/png', 'tmp_name' => $traversal_tmp, 'error' => 0, 'size' => filesize($traversal_tmp)), array('idempotencyKey' => 'media-path-0001'));
$traversal_data = $traversal->get_data();
jg_media_assert($traversal->get_status() === 200 && (int) $traversal_data['mediaId'] > 0, 'Path traversal upload failed.');
$original_name = (string) get_post_meta((int) $traversal_data['mediaId'], '_jg_ai_media_original_filename', true);
jg_media_assert(!str_contains($original_name, '..') && !str_contains($original_name, '/') && !str_contains($original_name, '\\'), 'Path traversal survived filename sanitization.');

// 12. Uploading media does not create dispatch pending or Cron work.
jg_media_assert(get_option('jg_dispatch_pending', array()) === array() && !wp_next_scheduled(JG_Dispatch::CRON_HOOK), 'Media upload triggered the dispatch/build pipeline.');

// 13. Existing content APIs still work after the media feature is enabled.
wp_set_current_user((int) $ai_user_1);
jg_media_clear_rate((int) $ai_user_1);
$draft = jg_media_request('POST', '/jaisong1n/v1/ai/content', array(
	'contentType' => 'article',
	'title' => 'Media regression draft',
	'contentHtml' => '<p>ok</p>',
	'idempotencyKey' => 'media-regression-0001',
));
jg_media_assert($draft->get_status() === 201, 'Article draft creation regressed after enabling media.');
$draft_id = (int) $draft->get_data()['id'];
$draft_read = jg_media_request('GET', '/jaisong1n/v1/ai/content/article/' . $draft_id);
jg_media_assert($draft_read->get_status() === 200, 'Content read regressed after enabling media.');

echo wp_json_encode(array('ok' => true, 'assertions' => $jg_media_assertions, 'pngMediaId' => $png_id, 'otherOwnerMediaId' => (int) $other_owner_data['mediaId'])) . "\n";
