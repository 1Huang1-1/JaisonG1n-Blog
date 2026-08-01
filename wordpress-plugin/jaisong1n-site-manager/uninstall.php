<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

$settings = get_option('jg_site_settings', array());
$post_types = array(
	'jg_project',
	'jg_skill',
	'jg_ai_tool',
	'jg_timeline',
	'jg_friend',
	'jg_device',
	'jg_diary',
	'jg_album',
	'jg_anime',
	'jg_announcement',
);

if (!empty($settings['cleanup_on_uninstall'])) {
	foreach ($post_types as $post_type) {
		$posts = get_posts(array(
			'post_type' => $post_type,
			'post_status' => 'any',
			'numberposts' => -1,
			'fields' => 'ids',
		));
		foreach ($posts as $post_id) {
			wp_delete_post($post_id, true);
		}
	}

	delete_option('jg_site_settings');
	delete_option('jg_dispatch_status');
	delete_option('jg_dispatch_config');
	delete_option('jg_github_token');
	delete_option('jg_dispatch_history');
	delete_option('jg_last_dispatched_revision');
	delete_option('jg_dispatch_pending');
	delete_option('jg_dispatch_lock');
	delete_option('jg_ai_content_settings');
	delete_option('jg_ai_content_idempotency');
	delete_option('jg_ai_content_audit');
	delete_option('jg_ai_publish_confirmation_tokens');
	global $wpdb;
	$wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'jg_media_refs');
	delete_option('jg_media_index_version');
}

$ai_role = get_role('jg_ai_content_editor');
if ($ai_role) {
	$ai_role->remove_cap('jg_ai_publish_diary_drafts');
}

foreach (array('administrator', 'editor') as $role_name) {
	$role = get_role($role_name);
	if (!$role) {
		continue;
	}
	foreach ($post_types as $post_type) {
		$plural = $post_type . 's';
		foreach (array(
			"edit_{$post_type}", "read_{$post_type}", "delete_{$post_type}",
			"edit_{$plural}", "edit_others_{$plural}", "publish_{$plural}",
			"read_private_{$plural}", "delete_{$plural}", "delete_private_{$plural}",
			"delete_published_{$plural}", "delete_others_{$plural}", "edit_private_{$plural}",
			"edit_published_{$plural}", "create_{$plural}",
		) as $capability) {
			$role->remove_cap($capability);
		}
	}
}
