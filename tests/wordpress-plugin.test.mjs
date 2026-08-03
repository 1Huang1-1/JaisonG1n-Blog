import assert from "node:assert/strict";
import { readdir, readFile } from "node:fs/promises";
import path from "node:path";
import test from "node:test";

const pluginRoot = path.resolve("wordpress-plugin/jaisong1n-site-manager");

async function phpFiles(directory = pluginRoot) {
	const entries = await readdir(directory, { withFileTypes: true });
	const files = [];
	for (const entry of entries) {
		const target = path.join(directory, entry.name);
		if (entry.isDirectory()) files.push(...(await phpFiles(target)));
		else if (entry.name.endsWith(".php")) files.push(target);
	}
	return files;
}

test("plugin exposes a public snapshot and an authenticated AI content interface", async () => {
	const source = await readFile(
		path.join(pluginRoot, "includes/class-jg-rest.php"),
		"utf8",
	);
	assert.match(source, /jaisong1n\/v1/);
	assert.match(source, /site-snapshot/);
	assert.match(source, /'permission_callback'\s*=>\s*'__return_true'/);
	const aiSource = await readFile(
		path.join(pluginRoot, "includes/class-jg-ai-content.php"),
		"utf8",
	);
	assert.match(aiSource, /jaisong1n\/v1\/ai/);
	for (const route of [
		"capabilities",
		"content",
		"publish",
		"unpublish",
		"claim",
		"audit",
	])
		assert.match(aiSource, new RegExp(route));
	assert.doesNotMatch(aiSource, /DELETE\s+\/content/);
	assert.doesNotMatch(source, /register_rest_route[\s\S]*manual_dispatch/);
});

test("custom content types use mapped, independent capabilities", async () => {
	const source = await readFile(
		path.join(pluginRoot, "includes/class-jg-content-types.php"),
		"utf8",
	);
	for (const type of [
		"jg_project",
		"jg_skill",
		"jg_ai_tool",
		"jg_timeline",
		"jg_friend",
		"jg_device",
		"jg_diary",
		"jg_album",
		"jg_anime",
		"jg_announcement",
		"jg_tech_radar",
		"jg_learning_resource",
	]) {
		assert.match(source, new RegExp(`'${type}'`));
	}
	assert.match(source, /'capability_type'\s*=>\s*array\(\$post_type/);
	assert.match(source, /'map_meta_cap'\s*=>\s*true/);
	assert.match(source, /'show_in_rest'\s*=>\s*true/);
	assert.match(source, /'publicly_queryable'\s*=>\s*false/);
	assert.match(source, /'exclude_from_search'\s*=>\s*true/);
	assert.match(source, /'has_archive'\s*=>\s*false/);
	assert.doesNotMatch(source, /get_role\(['"]author/);
});

test("structured content types expose native excerpts", async () => {
	const source = await readFile(
		path.join(pluginRoot, "includes/class-jg-content-types.php"),
		"utf8",
	);
	assert.match(
		source,
		/in_array\(\$post_type, array\('jg_project', 'jg_timeline', 'jg_diary', 'jg_album', 'jg_tech_radar', 'jg_learning_resource'\), true\)/,
	);
	assert.match(source, /\$supports\[\] = 'excerpt'/);
	assert.match(source, /'jg_diary' => array\([^\n]*'thumbnail' => true/);
	assert.match(source, /'diary_date'\s*=>\s*self::date/);
});

test("structured summaries prefer post excerpts and keep full content", async () => {
	const source = await readFile(
		path.join(pluginRoot, "includes/class-jg-snapshot.php"),
		"utf8",
	);
	assert.match(
		source,
		/\$description = \$this->summary\(\$post->post_excerpt, \$content_html, \$post\)/,
	);
	assert.match(source, /'contentHtml' => \$content_html/);
	assert.match(source, /private function truncate_summary/);
	assert.match(source, /function_exists\('mb_strlen'\)/);
	assert.match(source, /preg_match_all\('\/.\/us'/);
});

test("version 0.10.0 preserves schemaVersion 5 and deterministic ordering", async () => {
	const [entry, readme, snapshot] = await Promise.all([
		readFile(path.join(pluginRoot, "jaisong1n-site-manager.php"), "utf8"),
		readFile(path.join(pluginRoot, "readme.txt"), "utf8"),
		readFile(path.join(pluginRoot, "includes/class-jg-snapshot.php"), "utf8"),
	]);
	assert.match(entry, /Version:\s*0\.10\.0/);
	assert.match(entry, /JG_SITE_MANAGER_VERSION', '0\.10\.0'/);
	assert.match(readme, /Stable tag:\s*0\.10\.0/);
	assert.match(snapshot, /'schemaVersion'\s*=>\s*5/);
	assert.match(
		snapshot,
		/'orderby'\s*=>\s*array\('menu_order'\s*=>\s*'ASC',\s*'date'\s*=>\s*'DESC',\s*'ID'\s*=>\s*'ASC'\)/,
	);
});

test("AI draft updates are diary/article-only and modifiedAt is nullable", async () => {
	const source = await readFile(
		path.join(pluginRoot, "includes/class-jg-ai-content.php"),
		"utf8",
	);
	assert.match(
		source,
		/!in_array\(\$contract\['apiType'\], array\('diary', 'article'\), true\)/,
	);
	assert.match(source, /jg_ai_update_draft_unsupported/);
	assert.match(
		source,
		/array\('contentType', 'id', 'expectedModifiedAt', 'title', 'slug', 'excerpt', 'content'\)/,
	);
	assert.match(source, /jg_ai_no_changes/);
	assert.match(
		source,
		/private static function modified_at\(WP_Post \$post\): \?string/,
	);
	assert.match(source, /'0000-00-00 00:00:00'/);
	assert.match(source, /\$timestamp === false \? null/);
	assert.match(source, /'modifiedAt' => self::modified_at\(\$post\)/);
	assert.match(source, /self::record\('updateDraft'.*\$changed/);
});

test("reviewed diary publishing uses a separate capability and one-time tokens", async () => {
	const source = await readFile(
		path.join(pluginRoot, "includes/class-jg-ai-content.php"),
		"utf8",
	);
	assert.match(source, /prepare-publish/);
	assert.match(source, /jg_ai_publish_diary_drafts/);
	assert.match(source, /PUBLISH_TOKEN_TTL = 600/);
	assert.match(source, /bin2hex\(random_bytes\(32\)\)/);
	assert.match(source, /hash\('sha256', \$token\)/);
	assert.match(source, /'action' => 'publish'/);
	assert.match(source, /jg_ai_confirmation_token_expired/);
	assert.match(source, /jg_ai_confirmation_token_used/);
	assert.match(source, /idempotent_replay/);
	assert.match(source, /publish_prepare/);
	assert.match(source, /publish_success/);
	assert.match(source, /publish_rejected/);
	assert.match(source, /publish_conflict/);
	assert.doesNotMatch(source, /add_cap\([^\n]*publish_jg_diary/);
	assert.doesNotMatch(source, /workflow_dispatch|api\.github\.com/);
});

test("diary updates and reviewed publishing share one AI ownership check", async () => {
	const source = await readFile(
		path.join(pluginRoot, "includes/class-jg-ai-content.php"),
		"utf8",
	);
	assert.match(source, /can_manage_ai_content/);
	assert.match(source, /_jg_ai_owner_user_id/);
	assert.match(source, /function can_manage_ai_content/);
	assert.match(source, /current_user_can\('edit_post', \$post->ID\)/);
	assert.match(source, /is_ai_owner\(\$post\)/);
	assert.match(source, /_jg_ai_editable/);
	assert.match(
		source,
		/can_publish\(array \$contract, \?WP_Post \$post\): bool \{[\s\S]*?can_manage_ai_content/,
	);
	assert.match(
		source,
		/can_manage_ai_content[\s\S]*?current_user_can\('edit_post', \$post->ID\)[\s\S]*?is_ai_owner\(\$post\)/,
	);
	assert.match(source, /function publish_rejection_reason/);
	for (const reason of [
		"setting_disabled",
		"missing_publish_capability",
		"ownership_denied",
		"edit_denied",
		"not_publishable",
	]) {
		assert.match(source, new RegExp(reason));
	}
	assert.match(source, /function repair_ai_ownership/);
	assert.match(source, /jg_ai_sync_owner/);
});

test("deployment status API is read-only and keeps status layers separate", async () => {
	const [aiSource, dispatchSource, settingsSource] = await Promise.all([
		readFile(path.join(pluginRoot, "includes/class-jg-ai-content.php"), "utf8"),
		readFile(path.join(pluginRoot, "includes/class-jg-dispatch.php"), "utf8"),
		readFile(path.join(pluginRoot, "includes/class-jg-settings.php"), "utf8"),
	]);
	assert.match(aiSource, /deployment-status/);
	assert.match(aiSource, /deployment_status/);
	assert.match(aiSource, /deploymentStatus/);
	assert.match(aiSource, /function get_canonical_public_url/);
	assert.match(aiSource, /function probe_public_page/);
	assert.match(aiSource, /'\/diary\/'/);
	assert.match(aiSource, /'\/posts\/'/);
	assert.match(aiSource, /rawurlencode/);
	assert.match(aiSource, /limit_response_size/);
	assert.match(aiSource, /'redirection'\s*=>\s*0/);
	assert.match(aiSource, /public_site_url/);
	assert.match(dispatchSource, /find_latest_record_for_content/);
	assert.match(dispatchSource, /contentRefs/);
	assert.match(dispatchSource, /query_run/);
	assert.match(dispatchSource, /map_run_status/);
	assert.match(dispatchSource, /RUN_CACHE_TTL = 20/);
	assert.match(dispatchSource, /MAX_RECORDS = 50/);
	assert.match(dispatchSource, /'dispatchStatus'\s*=>/);
	assert.match(dispatchSource, /'buildStatus'\s*=>/);
	assert.match(dispatchSource, /'deploymentStatus'\s*=>/);
	assert.match(dispatchSource, /GITHUB_API_VERSION = '2026-03-10'/);
	assert.match(dispatchSource, /workflow_run_id/);
	assert.match(settingsSource, /public_site_url/);
	assert.doesNotMatch(
		dispatchSource,
		/Authorization.*error_log|error_log.*Authorization/,
	);
});

test("AI-owned diary drafts auto-enable reviewed publishing only under full conditions", async () => {
	const [aiSource, settingsSource] = await Promise.all([
		readFile(path.join(pluginRoot, "includes/class-jg-ai-content.php"), "utf8"),
		readFile(path.join(pluginRoot, "includes/class-jg-settings.php"), "utf8"),
	]);
	assert.match(aiSource, /auto_publishable_draft/);
	assert.match(
		aiSource,
		/update_post_meta\(\(int\) \$post_id, '_jg_ai_publishable', true\)/,
	);
	assert.match(
		aiSource,
		/!in_array\(\$contract\['apiType'\], array\('diary', 'article'\), true\)/,
	);
	assert.match(aiSource, /reviewed_diary_publish/);
	assert.match(aiSource, /PUBLISH_CAPABILITY/);
	assert.match(aiSource, /_jg_ai_owner_user_id/);
	assert.match(settingsSource, /auto_publishable_ai_diaries/);
	assert.match(settingsSource, /AI 自建日记自动允许进入受控发布流程/);
	assert.match(settingsSource, /不是自动公开发布/);
});

test("article reviewed publishing reuses the shared publish pipeline", async () => {
	const [aiSource, settingsSource] = await Promise.all([
		readFile(path.join(pluginRoot, "includes/class-jg-ai-content.php"), "utf8"),
		readFile(path.join(pluginRoot, "includes/class-jg-settings.php"), "utf8"),
	]);
	assert.match(aiSource, /jg_ai_publish_article_drafts/);
	assert.match(aiSource, /sync_article_publish_capability/);
	assert.match(aiSource, /site_settings_updated/);
	assert.match(aiSource, /'publish:' \. \$contract\['apiType'\]/);
	assert.match(
		aiSource,
		/issue_publish_token\(\$post, \$contract\['apiType'\]\)/,
	);
	assert.match(
		aiSource,
		/validate_publish_token\([^)]*\$contract\['apiType'\]\)/,
	);
	assert.match(aiSource, /'reviewed_article_publish'/);
	assert.match(aiSource, /'auto_publishable_ai_articles'/);
	assert.match(settingsSource, /reviewed_article_publish/);
	assert.match(settingsSource, /auto_publishable_ai_articles/);
	assert.match(settingsSource, /审核制文章发布/);
	assert.match(settingsSource, /AI 自建文章自动允许进入受控发布流程/);
});

test("published in-place updates are two-stage and protect immutable fields", async () => {
	const [aiSource, settingsSource] = await Promise.all([
		readFile(path.join(pluginRoot, "includes/class-jg-ai-content.php"), "utf8"),
		readFile(path.join(pluginRoot, "includes/class-jg-settings.php"), "utf8"),
	]);
	for (const route of ["prepare-update-published", "update-published"]) {
		assert.match(aiSource, new RegExp(route));
	}
	for (const op of ["prepareUpdatePublished", "updatePublished"]) {
		assert.match(aiSource, new RegExp(op));
	}
	for (const cap of [
		"jg_ai_update_published_diaries",
		"jg_ai_update_published_articles",
	]) {
		assert.match(aiSource, new RegExp(cap));
	}
	assert.match(aiSource, /normalize_published_update/);
	assert.match(aiSource, /published_content_hash/);
	assert.match(aiSource, /issue_update_published_token/);
	assert.match(aiSource, /validate_update_published_token/);
	assert.match(aiSource, /protected_fields_match/);
	assert.match(aiSource, /jg_ai_protected_field_changed/);
	assert.match(aiSource, /jg_ai_readback_verification_failed/);
	assert.match(aiSource, /confirmationPhrase/);
	assert.match(aiSource, /publishedAt/);
	assert.match(aiSource, /availableOperations/);
	assert.match(aiSource, /proposedTitle/);
	assert.match(aiSource, /proposedExcerpt/);
	assert.match(aiSource, /proposedContent/);
	assert.match(settingsSource, /update_published_diaries/);
	assert.match(settingsSource, /update_published_articles/);
	assert.match(settingsSource, /审核制已发布日记修改/);
	assert.match(settingsSource, /审核制已发布文章修改/);
});

test("only announcement links opt into validated root-relative paths", async () => {
	const source = await readFile(
		path.join(pluginRoot, "includes/class-jg-content-types.php"),
		"utf8",
	);
	assert.match(source, /'link_url'\s*=>\s*self::announcement_url/);
	assert.match(
		source,
		/case 'announcement_url': return self::sanitize_announcement_url/,
	);
	assert.match(source, /case 'url': return self::sanitize_http_url/);
	assert.match(source, /str_starts_with\(\$decoded, '\/\/'\)/);
	assert.match(source, /rawurldecode/);
});

test("schema v5 fields use structured repeaters and normalized media", async () => {
	const [types, snapshot, admin] = await Promise.all([
		readFile(
			path.join(pluginRoot, "includes/class-jg-content-types.php"),
			"utf8",
		),
		readFile(path.join(pluginRoot, "includes/class-jg-snapshot.php"), "utf8"),
		readFile(path.join(pluginRoot, "assets/admin.js"), "utf8"),
	]);
	for (const type of ["specs_repeater", "links_repeater", "media_repeater"]) {
		assert.match(types, new RegExp(type));
	}
	assert.match(types, /'onhold'\s*=>/);
	assert.match(types, /'dropped'\s*=>/);
	assert.match(types, /jg_menu_order/);
	assert.match(snapshot, /'contentHtml'\s*=>/);
	assert.match(snapshot, /'imageMedia'\s*=>/);
	assert.match(snapshot, /'avatarMedia'\s*=>/);
	assert.match(snapshot, /'coverMedia'\s*=>/);
	assert.match(snapshot, /'coverImage'\s*=>/);
	assert.match(snapshot, /private function album_media_refs/);
	for (const field of ["mediaId", "mimeType", "width", "height"]) {
		assert.match(snapshot, new RegExp(`'${field}'\\s*=>`));
	}
	assert.match(admin, /sortable/);
	assert.match(admin, /jg-add-media-row/);
	assert.doesNotMatch(types, /'live_demo'\s*=>/);
	assert.doesNotMatch(types, /'episodes'\s*=>/);
});

test("headless content preview links are removed without changing pages", async () => {
	const source = await readFile(
		path.join(pluginRoot, "includes/class-jg-content-types.php"),
		"utf8",
	);
	assert.match(source, /post_row_actions/);
	assert.match(source, /get_sample_permalink_html/);
	assert.match(source, /preview_post_link/);
	assert.match(source, /isset\(self::definitions\(\)\[\$post->post_type\]\)/);
});

test("token is not stored in plugin settings or returned by REST", async () => {
	const files = await phpFiles();
	const sources = await Promise.all(
		files.map((file) => readFile(file, "utf8")),
	);
	const settings = sources.join("\n");
	assert.doesNotMatch(settings, /['"]github_token['"]\s*=>/i);
	assert.match(settings, /'JAISONG1N_GITHUB_TOKEN'/);
	assert.match(settings, /getenv\(\$name\)/);
	assert.match(settings, /jg_github_token/);
	assert.match(settings, /autoload.*false|false.*autoload/i);
	assert.match(settings, /1Huang1-1\/JaisonG1n-Blog/);
});

test("uninstall defaults to retaining all plugin data", async () => {
	const source = await readFile(path.join(pluginRoot, "uninstall.php"), "utf8");
	assert.match(source, /!empty\(\$settings\['cleanup_on_uninstall'\]\)/);
	assert.match(source, /delete_option\('jg_site_settings'\)/);
	assert.doesNotMatch(source, /if \(empty\([\s\S]+return;/);
});

test("trusted host configuration rejects local and private network targets", async () => {
	const source = await readFile(
		path.join(pluginRoot, "includes/class-jg-content-policy.php"),
		"utf8",
	);
	assert.match(source, /\$host === 'localhost'/);
	assert.match(source, /str_ends_with\(\$host, '\.local'\)/);
	assert.match(source, /FILTER_FLAG_NO_PRIV_RANGE/);
	assert.match(source, /FILTER_FLAG_NO_RES_RANGE/);
});

test("snapshot hash excludes generatedAt and revision", async () => {
	const source = await readFile(
		path.join(pluginRoot, "includes/class-jg-content-policy.php"),
		"utf8",
	);
	assert.match(
		source,
		/unset\(\$snapshot\['generatedAt'\], \$snapshot\['revision'\]\)/,
	);
	assert.match(source, /ksort\(\$value, SORT_STRING\)/);
	assert.match(source, /hash\('sha256'/);
});

test("workflow accepts debounced WordPress dispatches", async () => {
	const workflow = await readFile(".github/workflows/build-deploy.yml", "utf8");
	assert.match(workflow, /repository_dispatch:/);
	assert.match(workflow, /wordpress_content_changed/);
	assert.match(workflow, /workflow_dispatch:/);
	assert.match(workflow, /trigger_source:/);
	assert.match(workflow, /change_types:/);
	assert.match(workflow, /change_actions:/);
	assert.match(workflow, /requested_at:/);
	assert.match(workflow, /force:/);
	assert.match(workflow, /Deprecated compatibility/);
	assert.match(workflow, /group: jaisong1n-production-build/);
	assert.match(workflow, /cancel-in-progress: true/);
});

test("dispatch covers public post metadata and still debounces by revision", async () => {
	const source = await readFile(
		path.join(pluginRoot, "includes/class-jg-dispatch.php"),
		"utf8",
	);
	assert.match(source, /GITHUB_API_VERSION = '2026-03-10'/);
	assert.match(source, /actions\/workflows/);
	assert.match(source, /code === 200/);
	assert.match(source, /code === 204/);
	assert.match(source, /MAX_HTTP_ATTEMPTS/);
	assert.match(source, /RETRY_DELAYS/);
	assert.match(source, /PENDING_OPTION/);
	assert.match(source, /force/);
	assert.doesNotMatch(source, /event_type|client_payload/);
	assert.match(source, /'sticky_posts'/);
	assert.match(source, /'edited_term'/);
	assert.match(source, /'profile_update'/);
	assert.match(source, /'edit_attachment'/);
	assert.match(source, /'delete_attachment'/);
	assert.match(source, /function attachment_deleted/);
	assert.match(source, /'author'\s*=>\s*get_the_author_meta/);
	assert.match(source, /'featuredImageUrl'/);
	assert.match(source, /get_option\(self::REVISION_OPTION/);
	assert.match(source, /actions\/workflows/);
});

test("supported dispatch types are registry-driven and exclude deprecated CPTs", async () => {
	const [source, types] = await Promise.all([
		readFile(path.join(pluginRoot, "includes/class-jg-dispatch.php"), "utf8"),
		readFile(
			path.join(pluginRoot, "includes/class-jg-content-types.php"),
			"utf8",
		),
	]);
	for (const type of [
		"post",
		"page",
		"jg_project",
		"jg_skill",
		"jg_ai_tool",
		"jg_timeline",
		"jg_friend",
		"jg_announcement",
		"jg_tech_radar",
		"jg_learning_resource",
		"jg_diary",
		"jg_album",
	])
		assert.match(
			type === "post" || type === "page" ? source : types,
			new RegExp(`['"]${type}['"]`),
		);
	assert.match(source, /JG_Content_Types::definitions/);
	assert.match(source, /is_deprecated/);
});

test("media changes use the reverse index instead of a full content scan", async () => {
	const source = await readFile(
		path.join(pluginRoot, "includes/class-jg-media-index.php"),
		"utf8",
	);
	const dispatch = await readFile(
		path.join(pluginRoot, "includes/class-jg-dispatch.php"),
		"utf8",
	);
	assert.match(source, /jg_media_refs/);
	assert.match(source, /attachment_lookup/);
	assert.match(source, /attachment_url_to_postid/);
	assert.match(dispatch, /JG_Media_Index::has_public_reference/);
	assert.doesNotMatch(
		dispatch,
		/get_posts\(array\([\s\S]*post_type.*attachment/,
	);
});

test("plugin packaging fixes the basename and normalizes ZIP paths", async () => {
	const [packager, verifier, packageJson] = await Promise.all([
		readFile("scripts/package-wordpress-plugin.mjs", "utf8"),
		readFile("scripts/verify-wordpress-plugin-package.mjs", "utf8"),
		readFile("package.json", "utf8"),
	]);
	assert.match(packager, /relativePath\.split\(path\.sep\)\.join\("\/"\)/);
	assert.match(packager, /mkdtemp/);
	assert.match(packager, /await rm\(stagingDirectory/);
	assert.match(verifier, /strictFileNames:\s*true/);
	assert.match(verifier, /ZIP entry uses backslash/);
	assert.match(
		verifier,
		/const MAIN_FILE = `\$\{PLUGIN_SLUG\}\/\$\{PLUGIN_SLUG\}\.php`/,
	);
	assert.match(packageJson, /package:wordpress-plugin/);
	assert.match(packageJson, /verify:wordpress-plugin-package/);
	assert.match(packageJson, /jaisong1n-site-manager-0\.10\.0\.zip/);
	assert.match(packageJson, /test:wordpress-upgrade/);
});
