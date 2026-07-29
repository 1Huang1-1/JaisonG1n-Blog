<?php

if (!defined('ABSPATH')) {
	exit;
}

final class JG_REST {
	public static function init(): void {
		add_action('rest_api_init', array(__CLASS__, 'register_routes'));
		add_filter('rest_prepare_post', array(__CLASS__, 'sanitize_core_content'), 10, 3);
		add_filter('rest_prepare_page', array(__CLASS__, 'sanitize_core_content'), 10, 3);
	}

	public static function register_routes(): void {
		register_rest_route('jaisong1n/v1', '/site-snapshot', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array(__CLASS__, 'snapshot'),
			'permission_callback' => '__return_true',
		));
	}

	public static function snapshot(WP_REST_Request $request) {
		try {
			$snapshot = (new JG_Snapshot())->build();
		} catch (Throwable $error) {
			return new WP_Error('jg_snapshot_failed', '公开快照生成失败。', array('status' => 500));
		}
		if (is_wp_error($snapshot)) return $snapshot;
		$etag = '"' . $snapshot['revision'] . '"';
		if (JG_Content_Policy::etag_matches($request->get_header('if-none-match'), $etag)) {
			$response = new WP_REST_Response(null, 304);
			$response->header('ETag', $etag);
			$response->header('Cache-Control', 'public, max-age=60, must-revalidate');
			return $response;
		}
		$response = rest_ensure_response($snapshot);
		$response->header('ETag', $etag);
		$response->header('Cache-Control', 'public, max-age=60, must-revalidate');
		return $response;
	}

	public static function sanitize_core_content(WP_REST_Response $response, WP_Post $post, WP_REST_Request $request): WP_REST_Response {
		if ($request->get_param('context') === 'edit') return $response;
		$data = $response->get_data();
		$hosts = JG_Content_Policy::sanitize_host_list(JG_Settings::get()['embed_hosts'] ?? '');
		foreach (array('content', 'excerpt') as $field) {
			if (isset($data[$field]['rendered'])) $data[$field]['rendered'] = JG_Content_Policy::sanitize_public_html((string) $data[$field]['rendered'], $hosts);
		}
		$response->set_data($data);
		return $response;
	}

}
