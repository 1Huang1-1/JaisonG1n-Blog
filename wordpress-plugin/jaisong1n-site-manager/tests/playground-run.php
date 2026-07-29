<?php

if (!defined('ABSPATH')) {
	require_once '/wordpress/wp-load.php';
}

require_once dirname(__DIR__) . '/jaisong1n-site-manager.php';
JG_Site_Manager::activate();
JG_Site_Manager::init();

require __DIR__ . '/playground-smoke.php';
