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
jg_upgrade_assert($baseline_validation === 0, '0.5.0 baseline validate_plugin failed: ' . wp_json_encode($baseline_validation));
$activation = activate_plugin($basename, '', false, false);
jg_upgrade_assert(!is_wp_error($activation), '0.5.0 activation failed: ' . wp_json_encode($activation));
$active_before = get_option('active_plugins', array());
jg_upgrade_assert(in_array($basename, $active_before, true), '0.5.0 basename was not stored in active_plugins.');
jg_upgrade_assert(get_option('jg_site_settings', array())['site_title'] === 'Upgrade preserved', '0.5.0 setting fixture is missing.');
jg_upgrade_assert(count(get_posts(array('post_type' => 'jg_project', 'post_status' => 'publish', 'numberposts' => -1))) === 1, '0.5.0 content fixture is missing.');

jg_upgrade_clear_directory($plugin_directory);
jg_upgrade_copy_directory($replacement_directory, $plugin_directory);
clearstatcache(true);
wp_clean_plugins_cache(true);

$active_after = get_option('active_plugins', array());
jg_upgrade_assert($active_after === $active_before, 'active_plugins changed during same-directory replacement.');
jg_upgrade_assert(is_readable($main_file), 'Replacement main plugin file is not readable.');
$replacement_validation = validate_plugin($basename);
jg_upgrade_assert($replacement_validation === 0, '0.6.0 validate_plugin failed: ' . wp_json_encode($replacement_validation));

require $main_file;
jg_upgrade_assert(defined('JG_SITE_MANAGER_VERSION') && JG_SITE_MANAGER_VERSION === '0.6.0', '0.6.0 plugin did not load.');
JG_Site_Manager::init();
JG_Content_Types::register();
JG_REST::register_routes();
jg_upgrade_assert(post_type_exists('jg_project'), 'Custom content type disappeared after upgrade.');
jg_upgrade_assert(JG_Settings::get()['site_title'] === 'Upgrade preserved', 'Plugin settings did not survive the upgrade.');
global $wpdb;
$token_autoload = $wpdb->get_var($wpdb->prepare('SELECT autoload FROM ' . $wpdb->options . ' WHERE option_name = %s', 'jg_github_token'));
jg_upgrade_assert($token_autoload === 'no', 'GitHub token option was not corrected to autoload=no: ' . (string) $token_autoload);

$snapshot_response = rest_do_request(new WP_REST_Request('GET', '/jaisong1n/v1/site-snapshot'));
$snapshot = $snapshot_response->get_data();
jg_upgrade_assert($snapshot_response->get_status() === 200, 'Snapshot failed after upgrade: ' . wp_json_encode($snapshot));
jg_upgrade_assert(($snapshot['schemaVersion'] ?? null) === 5, 'schemaVersion is not v5 after upgrade.');

echo wp_json_encode(array(
	'ok' => true,
	'baselineVersion' => '0.5.0',
	'replacementVersion' => JG_SITE_MANAGER_VERSION,
	'pluginBasename' => $basename,
	'activePluginsBefore' => $active_before,
	'activePluginsAfter' => $active_after,
	'baselineValidatePlugin' => $baseline_validation,
	'replacementValidatePlugin' => $replacement_validation,
	'settingsPreserved' => true,
	'contentTypePreserved' => post_type_exists('jg_project'),
	'schemaVersion' => $snapshot['schemaVersion'],
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
