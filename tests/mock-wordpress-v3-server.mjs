import http from "node:http";

const post = {
	id: 1,
	status: "publish",
	slug: "local-build-post",
	date: "2026-07-29T00:00:00",
	date_gmt: "2026-07-29T00:00:00",
	modified: "2026-07-29T00:00:00",
	modified_gmt: "2026-07-29T00:00:00",
	sticky: false,
	comment_status: "open",
	title: { rendered: "Local build post" },
	excerpt: { rendered: "Local build fixture" },
	content: { rendered: "<p>Local build fixture.</p>" },
	_embedded: {
		author: [{ name: "JaisonG1n" }],
		"wp:term": [],
		"wp:featuredmedia": [],
	},
};

const snapshot = {
	schemaVersion: 5,
	revision: "b".repeat(64),
	generatedAt: "2026-07-29T00:00:00.000Z",
	projects: [],
	skills: [],
	aiTools: [],
	timeline: [],
	friends: [],
	announcements: [],
	techRadar: [],
	learningResources: [],
	diary: [
		{
			id: "local-diary-entry",
			title: "本地日记测试",
			description: "用于 true 构建的日记夹具。",
			contentHtml: "<h2>今天的记录</h2><p>本地 WordPress 日记正文。</p>",
			date: "2026-07-29",
			publishedAt: "2026-07-29T00:00:00.000Z",
			updatedAt: "2026-07-29T00:00:00.000Z",
			location: "本地夹具",
			mood: "calm",
			tags: ["fixture"],
			images: [],
			coverImage: null,
			featured: true,
		},
	],
	albums: [
		{
			id: "测试",
			title: "Fixture Album",
			description: "Generated WordPress album fixture",
			contentHtml: "<p>Fixture album details.</p>",
			date: "2026-07-29",
			publishedAt: "2026-07-29T00:00:00.000Z",
			updatedAt: "2026-07-29T00:00:00.000Z",
			location: "Local fixture",
			tags: ["fixture"],
			images: [],
			coverImage: null,
			featured: false,
		},
	],
	mediaManifest: [],
};

const server = http.createServer((request, response) => {
	const url = new URL(request.url, "http://127.0.0.1");
	const value = url.pathname.endsWith("/site-snapshot") ? snapshot : [post];
	response.writeHead(200, {
		"content-type": "application/json",
		"x-wp-totalpages": "1",
	});
	response.end(JSON.stringify(value));
});

server.listen(Number(process.env.MOCK_WP_PORT || 8787), "127.0.0.1", () => {
	console.log(`mock wordpress listening on ${server.address().port}`);
});
