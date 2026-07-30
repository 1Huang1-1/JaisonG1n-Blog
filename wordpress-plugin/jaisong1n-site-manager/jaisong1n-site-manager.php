<?php
/**
 * Plugin Name: JaisonG1n Site Manager
 * Description: JaisonG1n 博客的内容、安全配置、公开快照与构建通知管理插件。
 * Version: 0.2.3
 * Author: JaisonG1n
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Text Domain: jaisong1n-site-manager
 */

if (!defined('ABSPATH')) {
	exit;
}

define('JG_SITE_MANAGER_VERSION', '0.2.3');
define('JG_SITE_MANAGER_FILE', __FILE__);
define('JG_SITE_MANAGER_DIR', plugin_dir_path(__FILE__));
define('JG_SITE_MANAGER_URL', plugin_dir_url(__FILE__));

require_once JG_SITE_MANAGER_DIR . 'includes/class-jg-content-policy.php';
require_once JG_SITE_MANAGER_DIR . 'includes/class-jg-content-types.php';
require_once JG_SITE_MANAGER_DIR . 'includes/class-jg-settings.php';
require_once JG_SITE_MANAGER_DIR . 'includes/class-jg-snapshot.php';
require_once JG_SITE_MANAGER_DIR . 'includes/class-jg-rest.php';
require_once JG_SITE_MANAGER_DIR . 'includes/class-jg-dispatch.php';

final class JG_Site_Manager {
	public static function init(): void {
		JG_Content_Types::init();
		JG_Settings::init();
		JG_REST::init();
		JG_Dispatch::init();
	}

	public static function activate(): void {
		JG_Content_Types::register();
		JG_Content_Types::grant_capabilities();
		JG_Settings::install_defaults();
		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook(JG_Dispatch::CRON_HOOK);
		flush_rewrite_rules();
	}
}

register_activation_hook(__FILE__, array('JG_Site_Manager', 'activate'));
register_deactivation_hook(__FILE__, array('JG_Site_Manager', 'deactivate'));
add_action('plugins_loaded', array('JG_Site_Manager', 'init'));
