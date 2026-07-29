import assert from "node:assert/strict";
import {
	mkdir,
	mkdtemp,
	readdir,
	readFile,
	rm,
	writeFile,
} from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import test from "node:test";
import {
	buildPostMarkdown,
	createSafeFilename,
	fetchPublishedPosts,
	htmlToMarkdown,
	syncWordPress,
} from "../scripts/sync-wordpress.mjs";

function post(overrides = {}) {
	return {
		id: 6,
		status: "publish",
		slug: "jaisong1n-%e5%8d%9a%e5%ae%a2",
		date: "2026-07-28T14:46:27",
		date_gmt: "2026-07-28T06:46:27",
		modified: "2026-07-28T14:50:00",
		modified_gmt: "2026-07-28T06:50:00",
		sticky: true,
		comment_status: "open",
		title: { rendered: '标题："你好" &amp; 世界' },
		excerpt: { rendered: "" },
		content: {
			rendered: `<!-- wp:paragraph --><p>正文 &amp; 内容</p><!-- /wp:paragraph -->
				<script>alert("no")</script><del>删除</del>
				<table><thead><tr><th>A</th></tr></thead><tbody><tr><td>B</td></tr></tbody></table>
				<iframe src="https://example.com/embed"></iframe>`,
		},
		_embedded: {
			author: [{ name: "author@example.com" }],
			"wp:featuredmedia": [
				{ source_url: "https://cms.example/cover.jpg?a=1&amp;b=2" },
			],
			"wp:term": [
				[{ taxonomy: "category", name: "开发 &amp; 笔记" }],
				[{ taxonomy: "post_tag", name: "Astro" }],
			],
		},
		...overrides,
	};
}

function snapshot(overrides = {}) {
	return {
		schemaVersion: 2,
		revision: "a".repeat(64),
		generatedAt: "2026-07-29T00:00:00+00:00",
		projects: [],
		skills: [],
		aiTools: [],
		timeline: [],
		mediaManifest: [],
		friends: [],
		...overrides,
	};
}

test("converts WordPress HTML and safe frontmatter", () => {
	const markdown = buildPostMarkdown(post());
	assert.match(markdown, /title: "标题：\\"你好\\" & 世界"/);
	assert.match(markdown, /published: 2026-07-28T06:46:27\.000Z/);
	assert.match(markdown, /updated: 2026-07-28T06:50:00\.000Z/);
	assert.match(markdown, /description: "正文 & 内容 删除 AB"/);
	assert.match(markdown, /tags: \["Astro"\]/);
	assert.match(markdown, /category: "开发 & 笔记"/);
	assert.match(markdown, /author: "JaisonG1n"/);
	assert.match(markdown, /alias: "jaisong1n-博客"/);
	assert.match(markdown, /\| A \|/);
	assert.match(markdown, /~{1,2}删除~{1,2}/);
	assert.match(
		markdown,
		/<iframe src="https:\/\/example.com\/embed"><\/iframe>/,
	);
	assert.doesNotMatch(markdown, /alert|wp:paragraph/);
});

test("keeps supported embedded media but removes scripts", () => {
	const markdown = htmlToMarkdown(
		'<video controls><source src="a.mp4"></video><audio src="a.mp3"></audio><script src="bad.js"></script>',
	);
	assert.match(markdown, /<video controls(?:="")?>/);
	assert.match(markdown, /<audio src="a.mp3"><\/audio>/);
	assert.doesNotMatch(markdown, /bad\.js|script/);
});

test("sanitizes unsafe and colliding Windows file names", () => {
	const used = new Set();
	assert.equal(createSafeFilename("../CON", 1, used), "post-1-CON.md");
	assert.equal(createSafeFilename("same/name", 2, used), "same-name.md");
	assert.equal(createSafeFilename("same\\name", 3, used), "same-name-3.md");
	assert.equal(createSafeFilename("", 4, used), "post-4.md");
});

test("fetches all pages and filters non-published responses", async () => {
	const requestedPages = [];
	const fetchImpl = async (url) => {
		const page = Number(url.searchParams.get("page"));
		requestedPages.push(page);
		const body =
			page === 1
				? [post()]
				: [post({ id: 7 }), post({ id: 8, status: "draft" })];
		return new Response(JSON.stringify(body), {
			status: 200,
			headers: { "X-WP-TotalPages": "2", "Content-Type": "application/json" },
		});
	};
	const posts = await fetchPublishedPosts({
		baseUrl: "https://cms.example",
		fetchImpl,
		logger: { info() {} },
	});
	assert.deepEqual(requestedPages, [1, 2]);
	assert.deepEqual(
		posts.map((value) => value.id),
		[6, 7],
	);
});

test("replaces only the generated output after a successful sync", async () => {
	const root = await mkdtemp(path.join(os.tmpdir(), "wordpress-sync-test-"));
	const outputDir = path.join(root, "src/content/posts/wordpress");
	const fetchImpl = async (url) =>
		new Response(
			JSON.stringify(
				new URL(url).pathname.includes("site-snapshot") ? snapshot() : [post()],
			),
			{
				status: 200,
				headers: { "X-WP-TotalPages": "1", "Content-Type": "application/json" },
			},
		);
	try {
		const result = await syncWordPress({
			baseUrl: "https://cms.example",
			repoRoot: root,
			structuredEnabled: false,
			fetchImpl,
			logger: { info() {}, error() {} },
		});
		assert.equal(result.count, 1);
		assert.deepEqual(await readdir(outputDir), ["jaisong1n-博客.md"]);
		assert.match(
			await readFile(path.join(outputDir, result.files[0]), "utf8"),
			/draft: false/,
		);
	} finally {
		await rm(root, { recursive: true, force: true });
	}
});

test("keeps the previous output when conversion fails", async () => {
	const root = await mkdtemp(
		path.join(os.tmpdir(), "wordpress-sync-failure-test-"),
	);
	const outputDir = path.join(root, "src/content/posts/wordpress");
	await mkdir(outputDir, { recursive: true });
	await writeFile(path.join(outputDir, "existing.md"), "existing", "utf8");
	const invalidPost = post({ date: "", date_gmt: "" });
	const fetchImpl = async () =>
		new Response(JSON.stringify([invalidPost]), {
			status: 200,
			headers: { "X-WP-TotalPages": "1", "Content-Type": "application/json" },
		});

	try {
		await assert.rejects(
			syncWordPress({
				baseUrl: "https://cms.example",
				repoRoot: root,
				structuredEnabled: false,
				fetchImpl,
				logger: { info() {}, error() {} },
			}),
			/Failed to convert 1 WordPress post/,
		);
		assert.deepEqual(await readdir(outputDir), ["existing.md"]);
		assert.equal(
			await readFile(path.join(outputDir, "existing.md"), "utf8"),
			"existing",
		);
	} finally {
		await rm(root, { recursive: true, force: true });
	}
});
