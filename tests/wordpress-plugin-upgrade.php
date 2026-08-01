<?php

if (!defined('ABSPATH')) {
	require_once '/wordpress/wp-load.php';
}
require_once ABSPATH . 'wp-admin/includes/plugin.php';

function jg_upgrade_assert(bool $condition, string $message): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function jg_upgrade_clear_directory(string $directory): void {
	foreach (new FilesystemIterator($directory) as $item) {
		if ($item->isDir() && !$item->isLink()) {
			jg_upgrade_clear_directory($item->getPathname());
			rmdir($item->getPathname());
		} else {
			unlink($item->getPathname());
		}
	}
}

function jg_upgrade_copy_directory(string $source, string $destination): void {
	if (!is_dir($destination) && !mkdir($destination, 0777, true) && !is_dir($destination)) {
		throw new RuntimeException('Could not create upgrade destination: ' . $destination);
	}
	foreach (new FilesystemIterator($source) as $item) {
		$target = $destination . DIRECTORY_SEPARATOR . $item->getBasename();
		if ($item->isDir() && !$item->isLink()) {
			jg_upgrade_copy_directory($item->getPathname(), $target);
		} elseif (!copy($item->getPathname(), $target)) {
			throw new RuntimeException('Could not copy upgrade file: ' . $item->getPathname());
		}
	}
}

$basename = 'jaisong1n-site-manager/jaisong1n-site-manager.php';
$plugin_directory = WP_PLUGIN_DIR . '/jaisong1n-site-manager';
$main_file = WP_PLUGIN_DIR . '/' . $basename;
$replacement_directory = '/workspace/replacement/jaisong1n-site-manager';

$baseline_validation = validate_plugin($basename);
jg_upgrade_assert($baseline_validation === 0, '0.8.0 baseline validate_plugin failed: ' . wp_json_encode($baseline_validation));
$activation = activate_plugin($basename, '', false, false);
jg_upgrade_assert(!is_wp_error($activation), '0.8.0 activation failed: ' . wp_json_encode($activation));
$active_before = get_option('active_plugins', array());
jg_upgrade_assert(in_array($basename, $active_before, true), '0.7.0 basename was not stored in active_plugins.');
jg_upgrade_assert(get_option('jg_site_settings', array())['site_title'] === 'Upgrade preserved', '0.8.0 setting fixture is missing.');
jg_upgrade_assert(count(get_posts(array('post_type' => 'jg_project', 'post_status' => 'publish', 'numberposts' => -1))) === 1, '0.8.0 content fixture is missing.');
jg_upgrade_assert((int) get_option('jg_upgrade_ai_draft', 0) > 0, '0.8.0 AI draft fixture is missing.');

jg_upgrade_clear_directory($plugin_directory);
jg_upgrade_copy_directory($replacement_directory, $plugin_directory);
clearstatcache(true);
wp_clean_plugins_cache(true);

$active_after = get_option('active_plugins', array());
jg_upgrade_assert($active_after === $active_before, 'active_plugins changed during same-directory replacement.');
jg_upgrade_assert(is_readable($main_file), 'Replacement main plugin file is not readable.');
$replacement_validation = validate_plugin($basename);
jg_upgrade_assert($replacement_validation === 0, '0.8.1 validate_plugin failed: ' . wp_json_encode($replacement_validation));

require $main_file;
jg_upgrade_assert(defined('JG_SITE_MANAGER_VERSION') && JG_SITE_MANAGER_VERSION === '0.8.1', '0.8.1 plugin did not load.');
JG_Site_Manager::init();
JG_Content_Types::register();
JG_AI_Content::install();
JG_REST::register_routes();
do_action('rest_api_init');
jg_upgrade_assert(get_role('jg_ai_content_editor') !== null, 'AI content editor role was not registered during upgrade.');
jg_upgrade_assert(post_type_exists('jg_project'), 'Custom content type disappeared after upgrade.');
jg_upgrade_assert(JG_Settings::get()['site_title'] === 'Upgrade preserved', 'Plugin settings did not survive the upgrade.');
global $wpdb;
$token_autoload = $wpdb->get_var($wpdb->prepare('SELECT autoload FROM ' . $wpdb->options . ' WHERE option_name = %s', 'jg_github_token'));
jg_upgrade_assert($token_autoload === 'no', 'GitHub token option was not corrected to autoload=no: ' . (string) $token_autoload);
jg_upgrade_assert(get_option('jg_dispatch_pending', array())['revision'] === 'a' . str_repeat('0', 63), 'Pending dispatch state did not survive the upgrade.');
jg_upgrade_assert(count(get_option('jg_dispatch_history', array())) === 1, 'Dispatch history did not survive the upgrade.');
jg_upgrade_assert(!get_post_meta(1, '_jg_ai_editable', true), 'Existing content was unexpectedly made AI editable.');
jg_upgrade_assert(!JG_AI_Content::settings()['allow_publish'], 'AI publishing must remain disabled after upgrade.');
jg_upgrade_assert(!JG_AI_Content::settings()['reviewed_diary_publish'], 'Reviewed diary publishing must remain disabled after upgrade.');
$ai_role = get_role('jg_ai_content_editor');
jg_upgrade_assert($ai_role && !$ai_role->has_cap('jg_ai_publish_diary_drafts'), 'Upgrade unexpectedly granted reviewed publish permission.');
$publish_tokens_autoload = $wpdb->get_var($wpdb->prepare('SELECT autoload FROM ' . $wpdb->options . ' WHERE option_name = %s', 'jg_ai_publish_confirmation_tokens'));
jg_upgrade_assert(!in_array($publish_tokens_autoload, array('yes', 'on', 'auto-on', 'auto'), true), 'Publish confirmation tokens must not autoload: ' . (string) $publish_tokens_autoload);
$editor = get_role('editor');
jg_upgrade_assert($editor && $editor->has_cap('jg_fixture_capability') && !$editor->has_cap('manage_options'), 'Existing user roles were changed during upgrade.');

$upgrade_draft_id = (int) get_option('jg_upgrade_ai_draft', 0);
$upgrade_owner = get_user_by('login', 'jg_upgrade_ai_owner');
jg_upgrade_assert($upgrade_owner instanceof WP_User, 'Upgrade fixture AI owner is missing.');
$upgrade_draft = get_post($upgrade_draft_id);
jg_upgrade_assert($upgrade_draft instanceof WP_Post && (int) $upgrade_draft->post_author === 1, 'Upgrade AI draft fixture author is invalid.');
jg_upgrade_assert((int) get_post_meta($upgrade_draft_id, '_jg_ai_owner_user_id', true) === (int) $upgrade_owner->ID, 'AI owner meta did not survive the upgrade.');
jg_upgrade_assert((bool) get_post_meta($upgrade_draft_id, '_jg_ai_created', true), 'AI created flag did not survive the upgrade.');
jg_upgrade_assert(JG_AI_Content::repair_ai_ownership($upgrade_draft_id), 'Guarded ownership repair failed after upgrade.');
jg_upgrade_assert((int) get_post($upgrade_draft_id)->post_author === (int) $upgrade_owner->ID, 'Ownership repair did not sync the author.');
$upgrade_stranger_id = wp_create_user('jg_upgrade_stranger', wp_generate_password(24), 'jg-upgrade-stranger@example.test');
jg_upgrade_assert(!is_wp_error($upgrade_stranger_id), 'Could not create the upgrade stranger.');
(new WP_User((int) $upgrade_stranger_id))->set_role('jg_ai_content_editor');
wp_set_current_user((int) $upgrade_stranger_id);
$stranger_patch = new WP_REST_Request('PATCH', '/jaisong1n/v1/ai/content/diary/' . $upgrade_draft_id);
$stranger_patch->set_body_params(array('expectedModifiedAt' => null, 'title' => 'Stranger update'));
$stranger_patch_result = rest_do_request($stranger_patch);
jg_upgrade_assert($stranger_patch_result->get_status() === 404, 'A stranger managed a diary after upgrade: ' . wp_json_encode($stranger_patch_result->get_data()));
wp_set_current_user(1);

$snapshot_response = rest_do_request(new WP_REST_Request('GET', '/jaisong1n/v1/site-snapshot'));
$snapshot = $snapshot_response->get_data();
jg_upgrade_assert($snapshot_response->get_status() === 200, 'Snapshot failed after upgrade: ' . wp_json_encode($snapshot));
jg_upgrade_assert(($snapshot['schemaVersion'] ?? null) === 5, 'schemaVersion is not v5 after upgrade.');

echo wp_json_encode(array(
	'ok' => true,
	'baselineVersion' => '0.8.0',
	'replacementVersion' => JG_SITE_MANAGER_VERSION,
	'pluginBasename' => $basename,
	'activePluginsBefore' => $active_before,
	'activePluginsAfter' => $active_after,
	'baselineValidatePlugin' => $baseline_validation,
	'replacementValidatePlugin' => $replacement_validation,
	'settingsPreserved' => true,
	'contentTypePreserved' => post_type_exists('jg_project'),
	'schemaVersion' => $snapshot['schemaVersion'],
	'pendingPreserved' => true,
	'dispatchHistoryPreserved' => true,
	'oldContentAiEditable' => false,
	'publishEnabled' => false,
	'reviewedPublishEnabled' => false,
	'publishTokenAutoload' => $publish_tokens_autoload,
	'ownershipRepairApplied' => true,
	'strangerManageRejected' => true,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
