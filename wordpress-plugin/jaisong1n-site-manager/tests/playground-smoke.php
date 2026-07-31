<?php

if (!defined('ABSPATH')) {
	require_once dirname(__DIR__, 4) . '/wp-load.php';
}

function jg_smoke_assert(bool $condition, string $message): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

register_shutdown_function(static function (): void {
	$error = error_get_last();
	if (is_array($error) && in_array($error['type'] ?? 0, array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
		echo "PLAYGROUND_FATAL: " . wp_json_encode($error, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
	}
});

function jg_create_item(string $post_type, string $slug, string $content, int $menu_order = 0, string $status = 'publish'): int {
	$post_id = wp_insert_post(array(
		'post_type' => $post_type,
		'post_status' => $status,
		'post_title' => ucwords(str_replace('-', ' ', $slug)),
		'post_name' => $slug,
		'post_content' => $content,
		'post_date' => '2026-01-01 00:00:00',
		'post_date_gmt' => '2026-01-01 00:00:00',
		'menu_order' => $menu_order,
	), true);
	jg_smoke_assert(!is_wp_error($post_id), 'Could not create fixture ' . $slug . '.');
	return (int) $post_id;
}

function jg_create_image_attachment(): int {
	$upload = wp_upload_dir(null, true, true);
	wp_mkdir_p($upload['path']);
	$file = trailingslashit($upload['path']) . 'jg-schema-v3-' . uniqid('', true) . '.png';
	$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Zl9sAAAAASUVORK5CYII=', true);
	jg_smoke_assert(is_string($png) && file_put_contents($file, $png) !== false, 'Could not create media fixture.');
	$attachment_id = wp_insert_attachment(array(
		'post_mime_type' => 'image/png',
		'post_title' => 'Schema v3 image',
		'post_status' => 'inherit',
		'guid' => trailingslashit($upload['url']) . 'jg-schema-v2.png',
	), $file, 0, true);
	jg_smoke_assert(!is_wp_error($attachment_id), 'Could not create media attachment.');
	update_attached_file((int) $attachment_id, $file);
	wp_update_attachment_metadata((int) $attachment_id, array(
		'width' => 1,
		'height' => 1,
		'file' => ltrim(str_replace(wp_normalize_path($upload['basedir']), '', wp_normalize_path($file)), '/'),
		'sizes' => array(),
	));
	update_post_meta((int) $attachment_id, '_wp_attachment_image_alt', 'Schema image alt');
	return (int) $attachment_id;
}

function jg_create_intermediate_image_url(int $attachment_id): string {
	$upload = wp_upload_dir(null, true, true);
	$original_file = get_attached_file($attachment_id);
	jg_smoke_assert(is_string($original_file) && is_file($original_file), 'Could not find original image fixture.');
	$original_basename = wp_basename($original_file);
	$derived_name = preg_replace('/(\\.[^.]+)$/', '-1024x567$1', $original_basename);
	$derived_file = trailingslashit($upload['path']) . $derived_name;
	jg_smoke_assert(copy($original_file, $derived_file), 'Could not create intermediate media fixture.');
	$metadata = wp_get_attachment_metadata($attachment_id);
	$metadata['sizes']['fixture-medium'] = array('file' => $derived_name, 'width' => 1024, 'height' => 567);
	wp_update_attachment_metadata($attachment_id, $metadata);
	return trailingslashit($upload['url']) . $derived_name;
}

jg_smoke_assert(class_exists('JG_Site_Manager'), 'The plugin was not activated.');
wp_set_current_user(1);

update_option('home', 'https://cms.example.com');
update_option('siteurl', 'https://cms.example.com');
update_option('upload_url_path', 'https://cms.example.com/wp-content/uploads');
JG_Content_Types::register();
JG_Content_Types::grant_capabilities();
JG_Settings::install_defaults();
$settings = JG_Settings::get();
$settings['trusted_media_hosts'] = 'cms.example.com';
update_option(JG_Settings::OPTION, $settings);
do_action('rest_api_init');
$supported_types = JG_Dispatch::supported_post_types();
jg_smoke_assert(count($supported_types) === 12 && !in_array('jg_device', $supported_types, true) && !in_array('jg_anime', $supported_types, true), 'Dispatch registry did not expose the expected 12 public post types: ' . wp_json_encode($supported_types));

$administrator = get_role('administrator');
$editor = get_role('editor');
$author = get_role('author');
jg_smoke_assert($administrator && $administrator->has_cap('edit_jg_projects'), 'Administrator lacks project capability.');
jg_smoke_assert($editor && $editor->has_cap('edit_jg_projects'), 'Editor lacks project capability.');
jg_smoke_assert($author && !$author->has_cap('edit_jg_projects'), 'Author unexpectedly received project capability.');

foreach (array_keys(JG_Content_Types::definitions()) as $post_type) {
	$object = get_post_type_object($post_type);
	jg_smoke_assert($object instanceof WP_Post_Type, 'Missing post type ' . $post_type . '.');
	jg_smoke_assert(!$object->publicly_queryable, $post_type . ' is publicly queryable.');
	jg_smoke_assert($object->exclude_from_search, $post_type . ' is included in search.');
	$expected_ui = in_array($post_type, array('jg_device', 'jg_anime'), true) ? false : true;
	jg_smoke_assert(!$object->has_archive && $object->show_ui === $expected_ui && $object->show_in_rest, $post_type . ' headless flags are incorrect.');
}

$hosts = JG_Content_Policy::sanitize_host_list(array('cms.example.com', 'localhost', '127.0.0.1', '10.0.0.1', 'media.local'));
jg_smoke_assert($hosts === array('cms.example.com'), 'Private or local media host passed validation.');

$sanitized = JG_Content_Policy::sanitize_public_html(
	'<p onclick="alert(1)">Safe</p><script>alert(1)</script><iframe src="https://evil.example/embed"></iframe>',
	array('player.bilibili.com')
);
jg_smoke_assert(str_contains($sanitized, '<p>Safe</p>'), 'Safe rich text was removed.');
jg_smoke_assert(!str_contains($sanitized, 'onclick'), 'Event handler survived sanitization.');
jg_smoke_assert(!str_contains($sanitized, '<script') && !str_contains($sanitized, 'alert(1)'), 'Script content survived sanitization.');
jg_smoke_assert(!str_contains($sanitized, '<iframe'), 'Disallowed embed survived sanitization.');

$reserved_request = new WP_REST_Request('POST', '/wp/v2/jg_project');
$reserved_request->set_param('type', 'jg_project');
$reserved = JG_Content_Types::validate_rest_save((object) array(
	'post_type' => 'jg_project',
	'post_name' => 'about',
	'post_title' => 'Reserved route',
	'post_content' => '',
), $reserved_request);
jg_smoke_assert(is_wp_error($reserved) && $reserved->get_error_code() === 'jg_reserved_slug', 'Reserved slug was not rejected.');

$fields = JG_Content_Types::field_definitions();
$legacy_specs = JG_Content_Types::sanitize_field('16 GB / 1 TB', $fields['jg_device']['specs']);
$legacy_links = JG_Content_Types::sanitize_field("Demo|https://example.com|website", $fields['jg_timeline']['links']);
$legacy_media = JG_Content_Types::sanitize_field('9,9,10', $fields['jg_album']['photos']);
jg_smoke_assert($legacy_specs === array(array('label' => '规格', 'value' => '16 GB / 1 TB')), 'Legacy device specs were not converted.');
jg_smoke_assert(($legacy_links[0]['type'] ?? '') === 'website', 'Legacy timeline links were not converted.');
jg_smoke_assert($legacy_media === array(array('mediaId' => 9), array('mediaId' => 10)), 'Legacy media IDs were not converted or deduplicated.');

$media_id = jg_create_image_attachment();
$intermediate_media_url = jg_create_intermediate_image_url($media_id);
jg_smoke_assert(attachment_url_to_postid($intermediate_media_url) === 0, 'Intermediate media fixture unexpectedly resolved directly.');
$rich = '<p>Hello&nbsp; <strong>&amp; world</strong></p><script>bad()</script>';

$project_first = jg_create_item('jg_project', 'project-first', $rich, -1);
set_post_thumbnail($project_first, $media_id);
update_post_meta($project_first, '_jg_category', 'web');
update_post_meta($project_first, '_jg_tech_stack', 'Astro, TypeScript');
update_post_meta($project_first, '_jg_status', 'completed');
update_post_meta($project_first, '_jg_source_code', 'https://github.com/example/project');
update_post_meta($project_first, '_jg_visit_url', 'https://example.com/project');
update_post_meta($project_first, '_jg_featured', true);
update_post_meta($project_first, '_jg_show_image', true);
$project_a = jg_create_item('jg_project', 'project-a', 'A', 0);
$project_b = jg_create_item('jg_project', 'project-b', 'B', 0);
jg_create_item('jg_project', 'project-draft', 'Draft', 0, 'draft');

$skill_id = jg_create_item('jg_skill', 'skill-one', 'Skill description');
update_post_meta($skill_id, '_jg_icon', 'logos:typescript-icon');
update_post_meta($skill_id, '_jg_category', 'frontend');
update_post_meta($skill_id, '_jg_level', 'advanced');
update_post_meta($skill_id, '_jg_experience_years', 2);
update_post_meta($skill_id, '_jg_experience_months', 6);

$ai_id = jg_create_item('jg_ai_tool', 'ai-one', 'AI description');
update_post_meta($ai_id, '_jg_icon', 'material-symbols:smart-toy');
update_post_meta($ai_id, '_jg_category', 'chat');
update_post_meta($ai_id, '_jg_frequency', 'daily');
update_post_meta($ai_id, '_jg_usage', '每天使用');

$timeline_id = jg_create_item('jg_timeline', 'timeline-one', $rich);
update_post_meta($timeline_id, '_jg_type', 'work');
update_post_meta($timeline_id, '_jg_start_date', '2025-01-01');
update_post_meta($timeline_id, '_jg_links', array(array('name' => 'Website', 'url' => 'https://example.com', 'type' => 'website')));

$friend_id = jg_create_item('jg_friend', 'friend-one', 'Friend description');
set_post_thumbnail($friend_id, $media_id);
update_post_meta($friend_id, '_jg_icon', 'simple-icons:github');
update_post_meta($friend_id, '_jg_site_url', 'https://example.com');
$friend_without_avatar = jg_create_item('jg_friend', 'friend-two', 'Friend without avatar');
update_post_meta($friend_without_avatar, '_jg_site_url', 'https://friend.example.com');

$device_id = jg_create_item('jg_device', 'device-one', 'Device description');
set_post_thumbnail($device_id, $media_id);
update_post_meta($device_id, '_jg_category', 'Router');
update_post_meta($device_id, '_jg_specs', array(array('label' => '内存', 'value' => '16 GB'), array('label' => '存储', 'value' => '1 TB')));
update_post_meta($device_id, '_jg_link', 'https://example.com/device');

$diary_id = jg_create_item('jg_diary', 'diary-one', $rich . '<p><img src="' . esc_url($intermediate_media_url) . '" alt="Intermediate fixture"></p>');
update_post_meta($diary_id, '_jg_images', array(array('mediaId' => $media_id)));
update_post_meta($diary_id, '_jg_location', 'Shanghai');

$album_id = jg_create_item('jg_album', 'album-one', $rich);
set_post_thumbnail($album_id, $media_id);
$album_media_two = jg_create_image_attachment();
$non_image_id = wp_insert_attachment(array('post_mime_type' => 'text/plain', 'post_title' => 'Not an image', 'post_status' => 'inherit'), '', 0, true);
jg_smoke_assert(!is_wp_error($non_image_id), 'Could not create non-image attachment fixture.');
$_POST = array(
	'jg_content_nonce' => wp_create_nonce('jg_save_content_fields'),
	'jg_fields' => array('photos' => array(
		array('mediaId' => $album_media_two),
		array('mediaId' => $media_id),
		array('mediaId' => $album_media_two),
		array('mediaId' => $non_image_id),
	)),
);
JG_Content_Types::save_fields($album_id, get_post($album_id));
$saved_photos = get_post_meta($album_id, '_jg_photos', true);
jg_smoke_assert($saved_photos === array(array('mediaId' => $album_media_two), array('mediaId' => $media_id)), 'Album media save did not preserve ordering, deduplicate, or reject non-images.');
$_POST['jg_fields']['photos'] = array(array('mediaId' => $media_id));
JG_Content_Types::save_fields($album_id, get_post($album_id));
jg_smoke_assert(get_post_meta($album_id, '_jg_photos', true) === array(array('mediaId' => $media_id)), 'Album media removal did not persist.');
jg_smoke_assert(get_post($album_media_two) instanceof WP_Post, 'Removing an album image deleted the media library attachment.');
$_POST = array();
update_post_meta($album_id, '_jg_album_date', '2026-01-01');

$anime_id = jg_create_item('jg_anime', 'anime-one', 'Anime description');
set_post_thumbnail($anime_id, $media_id);
update_post_meta($anime_id, '_jg_status', 'onhold');
update_post_meta($anime_id, '_jg_rating', 8.5);
update_post_meta($anime_id, '_jg_progress', 3);
update_post_meta($anime_id, '_jg_total_episodes', 12);

$related_post_id = jg_create_item('post', 'radar-related-post', 'Analysis article');
$radar_id = jg_create_item('jg_tech_radar', 'radar-one', 'Radar description', -1);
set_post_thumbnail($radar_id, $media_id);
update_post_meta($radar_id, '_jg_icon', 'simple-icons:openai');
update_post_meta($radar_id, '_jg_domain', 'ai');
update_post_meta($radar_id, '_jg_stage', 'adopt');
update_post_meta($radar_id, '_jg_trend', 'rising');
update_post_meta($radar_id, '_jg_maturity', 80);
update_post_meta($radar_id, '_jg_tags', 'AI,Infra');
update_post_meta($radar_id, '_jg_official_url', 'https://example.com/ai');
update_post_meta($radar_id, '_jg_source_urls', array(array('label' => 'Source', 'url' => 'https://example.com/source')));
update_post_meta($radar_id, '_jg_first_observed_at', '2025-01-01');
update_post_meta($radar_id, '_jg_last_reviewed_at', '2026-01-01');
update_post_meta($radar_id, '_jg_related_post_id', $related_post_id);
$learning_id = jg_create_item('jg_learning_resource', 'resource-one', 'Resource description');
update_post_meta($learning_id, '_jg_icon', 'material-symbols:menu-book');
update_post_meta($learning_id, '_jg_type', 'book');
update_post_meta($learning_id, '_jg_status', 'learning');
update_post_meta($learning_id, '_jg_author', 'Author');
update_post_meta($learning_id, '_jg_published_year', 2026);
update_post_meta($learning_id, '_jg_rating', 8.5);
update_post_meta($learning_id, '_jg_progress', 3);
update_post_meta($learning_id, '_jg_total_units', 10);
update_post_meta($learning_id, '_jg_source_url', 'https://example.com/book');
update_post_meta($learning_id, '_jg_started_at', '2026-01-01');
update_post_meta($learning_id, '_jg_related_post_id', $related_post_id);

$announcement_id = jg_create_item('jg_announcement', 'announcement-one', 'Announcement content');
update_post_meta($announcement_id, '_jg_closable', true);
update_post_meta($announcement_id, '_jg_link_enable', true);
update_post_meta($announcement_id, '_jg_link_text', 'Details');
$internal_announcement_url = JG_Content_Types::sanitize_field('/projects/?type=web#latest', $fields['jg_announcement']['link_url']);
jg_smoke_assert($internal_announcement_url === '/projects/?type=web#latest', 'Safe root-relative announcement link was rejected.');
update_post_meta($announcement_id, '_jg_link_url', $internal_announcement_url);
$announcement_two = jg_create_item('jg_announcement', 'announcement-two', 'External non-closable notice');
update_post_meta($announcement_two, '_jg_closable', false);
update_post_meta($announcement_two, '_jg_link_enable', true);
update_post_meta($announcement_two, '_jg_link_text', 'External');
update_post_meta($announcement_two, '_jg_link_url', 'https://example.com/details');
update_post_meta($announcement_two, '_jg_link_external', true);
jg_smoke_assert(JG_Content_Types::sanitize_field('https://example.com/details', $fields['jg_announcement']['link_url']) === 'https://example.com/details', 'External announcement URL was rejected.');

foreach (array('//evil.example.com', '/\\evil', 'javascript:alert(1)', 'data:text/plain,test', 'file:///tmp/test', '/%2f%2fevil.example.com', "/about/\r\nLocation: https://evil.example") as $unsafe_announcement_url) {
	jg_smoke_assert(JG_Content_Types::sanitize_field($unsafe_announcement_url, $fields['jg_announcement']['link_url']) === '', 'Unsafe announcement URL was accepted.');
}
jg_smoke_assert(JG_Content_Types::sanitize_field('/about/', $fields['jg_friend']['site_url']) === '', 'Friend URL sanitizer was unexpectedly relaxed.');

foreach (array_keys(JG_Content_Types::definitions()) as $post_type) {
	jg_create_item($post_type, 'draft-' . str_replace('jg_', '', $post_type), 'Private draft fixture', 0, 'draft');
}

$page_id = jg_create_item('page', 'snapshot-page', '<p>Page content</p>');
set_post_thumbnail($page_id, $media_id);

$first_request = new WP_REST_Request('GET', '/jaisong1n/v1/site-snapshot');
$first_response = rest_do_request($first_request);
$first_data = $first_response->get_data();
jg_smoke_assert(
	$first_response->get_status() === 200,
	'Snapshot did not return HTTP 200: status=' . $first_response->get_status() . ' data=' . wp_json_encode($first_data)
);
jg_smoke_assert(($first_data['schemaVersion'] ?? null) === 5, 'Unexpected snapshot schema version.');
jg_smoke_assert(count($first_data['projects'] ?? array()) === 3, 'Draft filtering or project count is incorrect.');
foreach (array('skills', 'aiTools', 'timeline', 'techRadar', 'learningResources', 'diary', 'albums') as $collection) {
	jg_smoke_assert(count($first_data[$collection] ?? array()) === 1, 'Draft filtering failed for ' . $collection . '.');
}
jg_smoke_assert(count($first_data['friends'] ?? array()) === 2, 'Friend fixtures were not published correctly.');
jg_smoke_assert(count($first_data['announcements'] ?? array()) === 2, 'Announcement fixtures were not published correctly.');
jg_smoke_assert(array_column($first_data['projects'], 'id') === array('project-first', 'project-a', 'project-b'), 'Deterministic project ordering failed.');
jg_smoke_assert(
	$first_data['projects'][0]['description'] === 'Hello & world',
	'Description was not normalized to plain text: ' . wp_json_encode($first_data['projects'][0]['description'])
);
jg_smoke_assert(str_contains($first_data['projects'][0]['contentHtml'], '<strong>&amp; world</strong>'), 'Safe contentHtml was not preserved.');
jg_smoke_assert(!str_contains($first_data['projects'][0]['contentHtml'], 'bad()'), 'Unsafe script content survived contentHtml cleanup.');
jg_smoke_assert(($first_data['projects'][0]['imageMedia']['mimeType'] ?? '') === 'image/png', 'Project media object is invalid.');
jg_smoke_assert(($first_data['aiTools'][0]['description']['zh_CN'] ?? '') === 'AI description', 'AI description is not a zh_CN LocaleString.');
jg_smoke_assert(($first_data['timeline'][0]['links'][0]['type'] ?? '') === 'website', 'Timeline links are not structured.');
jg_smoke_assert(($first_data['diary'][0]['images'][0]['mediaId'] ?? 0) === $media_id, 'Diary MediaRef is invalid.');
jg_smoke_assert(str_contains($first_data['diary'][0]['contentHtml'] ?? '', $intermediate_media_url), 'WordPress intermediate image URL was not preserved in diary content.');
jg_smoke_assert(($first_data['albums'][0]['images'][0]['url'] ?? '') === wp_get_attachment_url($media_id), 'Album image record is invalid.');
jg_smoke_assert(($first_data['albums'][0]['images'][0]['order'] ?? -1) === 0, 'Album image order was not preserved.');
jg_smoke_assert(($first_data['albums'][0]['coverImage']['id'] ?? 0) === $media_id, 'Album cover fallback is invalid.');
jg_smoke_assert(!str_contains($first_data['timeline'][0]['contentHtml'], 'bad()'), 'Timeline contentHtml was not sanitized.');
jg_smoke_assert(!str_contains($first_data['diary'][0]['contentHtml'], 'bad()'), 'Diary contentHtml was not sanitized.');
jg_smoke_assert(!str_contains($first_data['albums'][0]['contentHtml'], 'bad()'), 'Album contentHtml was not sanitized.');
jg_smoke_assert(($first_data['techRadar'][0]['stage'] ?? '') === 'adopt', 'Tech Radar stage was not preserved.');
jg_smoke_assert(($first_data['techRadar'][0]['relatedPost']['postId'] ?? 0) === $related_post_id, 'Tech Radar related post was not preserved.');
jg_smoke_assert(($first_data['learningResources'][0]['type'] ?? '') === 'book', 'Learning resource type was not preserved.');
jg_smoke_assert(($first_data['announcements'][0]['link']['enable'] ?? false) === true, 'Announcement link enable flag is missing.');
jg_smoke_assert(($first_data['announcements'][0]['link']['url'] ?? '') === '/projects/?type=web#latest', 'Internal announcement link was not preserved.');
jg_smoke_assert(($first_data['announcements'][1]['closable'] ?? true) === false, 'Non-closable announcement was not preserved.');
jg_smoke_assert(($first_data['friends'][1]['avatarMedia'] ?? null) === null, 'Friend without avatar unexpectedly has media.');
jg_smoke_assert(($first_data['friends'][0]['icon'] ?? '') === 'simple-icons:github', 'Friend Iconify icon is missing.');
jg_smoke_assert(count($first_data['mediaManifest'] ?? array()) === 1, 'Media manifest did not deduplicate attachments.');
jg_smoke_assert(($first_data['mediaManifest'][0]['width'] ?? 0) === 1 && ($first_data['mediaManifest'][0]['height'] ?? 0) === 1, 'Media dimensions are invalid.');
$snapshot_pages = array_values(array_filter($first_data['pages'], static fn($page) => ($page['slug'] ?? '') === 'snapshot-page'));
jg_smoke_assert(($snapshot_pages[0]['featuredImageMedia']['id'] ?? 0) === $media_id, 'Page featured media object is missing.');

$etag = $first_response->get_headers()['ETag'] ?? '';
$second_request = new WP_REST_Request('GET', '/jaisong1n/v1/site-snapshot');
$second_request->set_header('If-None-Match', $etag);
$second_response = rest_do_request($second_request);
jg_smoke_assert($etag !== '' && $second_response->get_status() === 304, 'Matching ETag did not return HTTP 304 in Playground.');

$headless_actions = JG_Content_Types::remove_view_action(array('view' => 'View', 'edit' => 'Edit'), get_post($project_first));
$page_actions = JG_Content_Types::remove_view_action(array('view' => 'View'), get_post($page_id));
jg_smoke_assert(!isset($headless_actions['view']), 'Headless view action was not removed.');
jg_smoke_assert(isset($page_actions['view']), 'Normal page view action was changed.');
jg_smoke_assert(JG_Content_Types::hide_preview_link('https://cms.example.com/?preview=1', get_post($project_first)) === '', 'Headless preview link was not removed.');
jg_smoke_assert(JG_Content_Types::hide_preview_link('https://cms.example.com/?preview=1', get_post($page_id)) !== '', 'Normal page preview link was changed.');
jg_smoke_assert(JG_Content_Types::hide_sample_permalink('<span>permalink</span>', $project_first) === '', 'Headless sample permalink was not removed.');
jg_smoke_assert(JG_Content_Types::hide_sample_permalink('<span>permalink</span>', $page_id) !== '', 'Normal page sample permalink was changed.');

$invalid_link = JG_Content_Types::sanitize_field(
	array(array('name' => 'Unsafe', 'url' => 'javascript:alert(1)', 'type' => 'website')),
	$fields['jg_timeline']['links']
);
jg_smoke_assert($invalid_link === array(), 'Unsafe timeline link protocol was not rejected.');

$upload = wp_upload_dir(null, true, true);
$bad_file = trailingslashit($upload['path']) . 'jg-invalid-media.txt';
file_put_contents($bad_file, 'not an image');
$bad_media_id = wp_insert_attachment(array(
	'post_mime_type' => 'text/plain',
	'post_title' => 'Invalid media',
	'post_status' => 'inherit',
	'guid' => trailingslashit($upload['url']) . 'jg-invalid-media.txt',
), $bad_file);
update_attached_file((int) $bad_media_id, $bad_file);
update_post_meta($project_first, '_thumbnail_id', (int) $bad_media_id);
$invalid_snapshot = (new JG_Snapshot())->build();
jg_smoke_assert(is_wp_error($invalid_snapshot) && $invalid_snapshot->get_error_code() === 'jg_media_mime', 'Invalid media MIME did not fail the snapshot.');
update_post_meta($project_first, '_thumbnail_id', $media_id);

$untrusted_settings = JG_Settings::get();
$untrusted_settings['trusted_media_hosts'] = 'media.example.com';
update_option(JG_Settings::OPTION, $untrusted_settings);
$untrusted_snapshot = (new JG_Snapshot())->build();
jg_smoke_assert(is_wp_error($untrusted_snapshot) && $untrusted_snapshot->get_error_code() === 'jg_media_host', 'Untrusted media host did not fail the snapshot.');
update_option(JG_Settings::OPTION, $settings);

if (!defined('JAISONG1N_GITHUB_TOKEN')) define('JAISONG1N_GITHUB_TOKEN', 'playground-fixture-token');
$wpdb_token = $GLOBALS['wpdb'];
delete_option('jg_github_token');
add_option('jg_github_token', 'database-fixture-token', '', true);
JG_Dispatch::install_defaults();
$token_autoload = $wpdb_token->get_var($wpdb_token->prepare('SELECT autoload FROM ' . $wpdb_token->options . ' WHERE option_name = %s', 'jg_github_token'));
jg_smoke_assert($token_autoload === 'no', 'GitHub token database option was not forced to autoload=no.');
$dispatch_calls = 0;
$dispatch_response_code = 200;
$dispatch_sequence = array();
$dispatch_filter = static function ($pre, $args, $url) use (&$dispatch_calls, &$dispatch_response_code, &$dispatch_sequence) {
	if (!str_contains((string) $url, '/actions/workflows/build-deploy.yml/dispatches')) return $pre;
	$dispatch_calls++;
	$response_value = !empty($dispatch_sequence) ? array_shift($dispatch_sequence) : $dispatch_response_code;
	if (is_wp_error($response_value)) return $response_value;
	$response_code = (int) $response_value;
	return array(
		'headers' => array(),
		'body' => $response_code === 200 ? wp_json_encode(array('workflow_run_id' => 123, 'run_url' => 'https://api.github.com/repos/1Huang1-1/JaisonG1n-Blog/actions/runs/123', 'html_url' => 'https://github.com/1Huang1-1/JaisonG1n-Blog/actions/runs/123')) : '',
		'response' => array('code' => $response_code, 'message' => 'fixture'),
		'cookies' => array(),
	);
};
add_filter('pre_http_request', $dispatch_filter, 10, 3);

delete_option('jg_last_dispatched_revision');
update_option('jg_dispatch_pending', array('types' => array('content'), 'actions' => array('updated'), 'attempts' => 0), false);
JG_Dispatch::dispatch_if_changed();
$dispatch_status = get_option('jg_dispatch_status', array());
jg_smoke_assert($dispatch_calls === 1 && ($dispatch_status['state'] ?? '') === 'success' && ($dispatch_status['workflow_run_id'] ?? 0) === 123 && ($dispatch_status['run_url'] ?? '') === 'https://api.github.com/repos/1Huang1-1/JaisonG1n-Blog/actions/runs/123', 'GitHub workflow_dispatch 200 response was not parsed or stored: ' . wp_json_encode(array('calls' => $dispatch_calls, 'status' => $dispatch_status, 'pending' => get_option('jg_dispatch_pending', array()))));

$dispatch_response_code = 204;
delete_option('jg_last_dispatched_revision');
update_option('jg_dispatch_pending', array('types' => array('media'), 'actions' => array('updated'), 'attempts' => 0), false);
JG_Dispatch::dispatch_if_changed();
$dispatch_status = get_option('jg_dispatch_status', array());
jg_smoke_assert($dispatch_calls === 2 && ($dispatch_status['state'] ?? '') === 'success', 'GitHub workflow_dispatch 204 response was not accepted.');

update_option('jg_dispatch_pending', array('types' => array('content'), 'actions' => array('updated'), 'attempts' => 0), false);
JG_Dispatch::dispatch_if_changed();
jg_smoke_assert($dispatch_calls === 2 && (get_option('jg_dispatch_status', array())['state'] ?? '') === 'unchanged', 'Unchanged revision incorrectly dispatched again.');

$dispatch_sequence = array(500, 429, 204);
delete_option('jg_last_dispatched_revision');
update_option('jg_dispatch_pending', array('types' => array('content'), 'actions' => array('updated'), 'attempts' => 0), false);
$retry_calls_before = $dispatch_calls;
JG_Dispatch::dispatch_if_changed();
jg_smoke_assert($dispatch_calls === $retry_calls_before + 3 && (get_option('jg_dispatch_status', array())['state'] ?? '') === 'success', '5xx/429 dispatch retries were not exhausted before success.');

$dispatch_sequence = array(new WP_Error('http_request_failed', 'fixture timeout'), new WP_Error('http_request_failed', 'fixture network error'), 204);
delete_option('jg_last_dispatched_revision');
update_option('jg_dispatch_pending', array('types' => array('content'), 'actions' => array('updated'), 'attempts' => 0), false);
$network_retry_calls_before = $dispatch_calls;
JG_Dispatch::dispatch_if_changed();
jg_smoke_assert($dispatch_calls === $network_retry_calls_before + 3 && (get_option('jg_dispatch_status', array())['state'] ?? '') === 'success', 'Network dispatch retries were not exhausted before success.');

$dispatch_sequence = array();
remove_filter('pre_http_request', $dispatch_filter, 10);

delete_option('jg_dispatch_pending');
wp_clear_scheduled_hook(JG_Dispatch::CRON_HOOK);
for ($read_index = 0; $read_index < 3; $read_index++) rest_do_request(new WP_REST_Request('GET', '/jaisong1n/v1/site-snapshot'));
jg_smoke_assert(get_option('jg_dispatch_pending', array()) === array() && !wp_next_scheduled(JG_Dispatch::CRON_HOOK), 'Snapshot reads created a pending dispatch or Cron event.');

update_option('jg_dispatch_pending', array('types' => array('content'), 'actions' => array('updated')), false);
wp_schedule_single_event(time() + 45, JG_Dispatch::CRON_HOOK);
JG_Dispatch::deactivate();
jg_smoke_assert(is_array(get_option('jg_dispatch_pending', null)) && !wp_next_scheduled(JG_Dispatch::CRON_HOOK), 'Deactivation did not retain pending state while clearing Cron.');
JG_Dispatch::activate();
jg_smoke_assert(wp_next_scheduled(JG_Dispatch::CRON_HOOK) !== false, 'Activation did not reschedule retained pending state.');
wp_clear_scheduled_hook(JG_Dispatch::CRON_HOOK);
delete_option('jg_dispatch_pending');

echo wp_json_encode(array(
	'ok' => true,
	'schemaVersion' => $first_data['schemaVersion'],
	'revision' => $first_data['revision'],
	'projectCount' => count($first_data['projects']),
	'mediaCount' => count($first_data['mediaManifest']),
	'etagStatus' => $second_response->get_status(),
	'dispatchCalls' => $dispatch_calls,
	'supportedPostTypes' => $supported_types,
	'dispatchApiVersion' => JG_Dispatch::GITHUB_API_VERSION,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
