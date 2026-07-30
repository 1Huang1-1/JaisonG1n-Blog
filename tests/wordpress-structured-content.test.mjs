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
import { Readable } from "node:stream";
import test from "node:test";
import sharp from "sharp";
import { MockAgent } from "undici";
import { syncWordPress } from "../scripts/sync-wordpress.mjs";
import {
	parseGeneratedBundle,
	parseSiteSnapshot,
	parseStructuredContentFlag,
	SYNC_LIMITS,
} from "../scripts/wordpress-sync/contracts.mjs";
import { loadStructuredContentSource } from "../scripts/wordpress-sync/gateway.mjs";
import {
	createPinnedLookup,
	isPublicIpAddress,
	MediaMirror,
	resolvePublicAddresses,
	rewriteContentHtml,
	rewriteStructuredMedia,
	validateMediaUrl,
} from "../scripts/wordpress-sync/media.mjs";
import { commitDirectoryTransaction } from "../scripts/wordpress-sync/transaction.mjs";
import {
	adaptAnnouncements,
	adaptFriends,
	announcementDismissKey,
} from "../scripts/wordpress-sync/view-models.mjs";

const PNG = await sharp({
	create: { width: 2, height: 3, channels: 4, background: "#ff0000ff" },
})
	.png()
	.toBuffer();

function project(overrides = {}) {
	return {
		id: "project-one",
		title: "Project One",
		description: "Description",
		contentHtml: "<p>Details</p>",
		image: "",
		imageMedia: null,
		category: "web",
		techStack: ["Astro"],
		status: "completed",
		sourceCode: "",
		visitUrl: "",
		featured: true,
		showImage: true,
		...overrides,
	};
}

function skill(overrides = {}) {
	return {
		id: "typescript",
		name: "TypeScript",
		description: "Typed JavaScript",
		icon: "simple-icons:typescript",
		category: "frontend",
		level: "advanced",
		experience: { years: 3, months: 2 },
		color: "#3178c6",
		...overrides,
	};
}

function aiTool(overrides = {}) {
	return {
		id: "assistant",
		name: "Assistant",
		description: { zh_CN: "说明" },
		icon: "material-symbols:smart-toy",
		category: "chat",
		frequency: "daily",
		url: "https://example.com",
		usage: { zh_CN: "每天使用" },
		tags: ["AI"],
		color: "#10a37f",
		...overrides,
	};
}

function timeline(overrides = {}) {
	return {
		id: "timeline-one",
		title: "Timeline One",
		description: "Description",
		contentHtml: "<p>Details</p>",
		type: "project",
		startDate: "2026-01-01",
		endDate: "",
		location: "",
		organization: "",
		position: "",
		skills: ["Astro"],
		achievements: ["Done"],
		links: [{ name: "Site", url: "https://example.com", type: "website" }],
		icon: "material-symbols:event",
		color: "#3178c6",
		featured: false,
		...overrides,
	};
}

function friend(overrides = {}) {
	return {
		title: "Friend One",
		icon: "simple-icons:github",
		imgurl: "",
		avatarMedia: null,
		desc: "Friend description",
		siteurl: "https://friend.example",
		tags: ["blog"],
		...overrides,
	};
}

function announcement(overrides = {}) {
	return {
		title: "Notice",
		content: "Announcement content",
		closable: true,
		link: { enable: true, text: "About", url: "/about/", external: false },
		...overrides,
	};
}

function diary(overrides = {}) {
	return {
		id: "diary-one",
		title: "Diary One",
		description: "A diary entry",
		contentHtml: "<p>A diary entry.</p>",
		date: "2026-07-29",
		publishedAt: "2026-07-29T00:00:00.000Z",
		updatedAt: "2026-07-29T00:00:00.000Z",
		location: "",
		mood: "calm",
		tags: [],
		images: [],
		coverImage: null,
		featured: false,
		...overrides,
	};
}

function snapshot(overrides = {}) {
	return {
		schemaVersion: 4,
		revision: "a".repeat(64),
		generatedAt: "2026-07-29T00:00:00+00:00",
		projects: [],
		skills: [],
		aiTools: [],
		timeline: [],
		mediaManifest: [],
		friends: [],
		announcements: [],
		techRadar: [],
		learningResources: [],
		diary: [],
		albums: [],
		...overrides,
	};
}

function generatedBundle(overrides = {}) {
	const bundle = {
		meta: {
			schemaVersion: 4,
			revision: "a".repeat(64),
			generatedAt: "2026-07-29T00:00:00+00:00",
			syncedAt: "2026-07-29T00:01:00.000Z",
			sourceUrl: "https://cms.example",
			counts: {
				posts: 0,
				projects: 0,
				skills: 0,
				aiTools: 0,
				timeline: 0,
				friends: 0,
				announcements: 0,
				techRadar: 0,
				learningResources: 0,
				diary: 0,
				media: 0,
			},
			media: [],
		},
		projects: [],
		skills: [],
		aiTools: [],
		timeline: [],
		friends: [],
		announcements: [],
		techRadar: [],
		learningResources: [],
		diary: [],
		...overrides,
	};
	bundle.meta.counts = {
		...bundle.meta.counts,
		projects: bundle.projects.length,
		skills: bundle.skills.length,
		aiTools: bundle.aiTools.length,
		timeline: bundle.timeline.length,
		friends: bundle.friends.length,
		announcements: bundle.announcements.length,
		techRadar: bundle.techRadar.length,
		learningResources: bundle.learningResources.length,
		diary: bundle.diary.length,
	};
	return bundle;
}

function generatedFiles(bundle) {
	return new Map([
		["snapshot-meta.json", JSON.stringify(bundle.meta)],
		["projects.json", JSON.stringify(bundle.projects)],
		["skills.json", JSON.stringify(bundle.skills)],
		["ai-tools.json", JSON.stringify(bundle.aiTools)],
		["timeline.json", JSON.stringify(bundle.timeline)],
		["friends.json", JSON.stringify(bundle.friends)],
		["announcements.json", JSON.stringify(bundle.announcements)],
		["tech-radar.json", JSON.stringify(bundle.techRadar)],
		["learning-resources.json", JSON.stringify(bundle.learningResources)],
		["diary.json", JSON.stringify(bundle.diary)],
	]);
}

function publishedPost(overrides = {}) {
	return {
		id: 1,
		status: "publish",
		slug: "post-one",
		date: "2026-07-29T00:00:00",
		date_gmt: "2026-07-29T00:00:00",
		modified: "2026-07-29T00:00:00",
		modified_gmt: "2026-07-29T00:00:00",
		sticky: false,
		comment_status: "open",
		title: { rendered: "Post One" },
		excerpt: { rendered: "Summary" },
		content: { rendered: "<p>Body</p>" },
		_embedded: {
			author: [{ name: "JaisonG1n" }],
			"wp:term": [],
			"wp:featuredmedia": [],
		},
		...overrides,
	};
}

function responseBody(buffer) {
	const body = Readable.from([buffer]);
	body.dump = async () => {
		for await (const _chunk of body) {
			// Drain redirects and errors before closing the dispatcher.
		}
	};
	return body;
}

test("structured flag accepts only exact lowercase values", () => {
	assert.equal(parseStructuredContentFlag(undefined), false);
	assert.equal(parseStructuredContentFlag("false"), false);
	assert.equal(parseStructuredContentFlag("true"), true);
	assert.throws(
		() => parseStructuredContentFlag("TRUE"),
		/exactly 'true' or 'false'/,
	);
});

test("friend and announcement view models preserve deterministic source data", () => {
	const friends = adaptFriends([
		friend({
			avatarMedia: {
				url: `/generated/wordpress-media/${"a".repeat(64)}.png`,
			},
		}),
		friend({ title: "No avatar", imgurl: "" }),
	]);
	assert.equal(
		friends[0].avatar,
		`/generated/wordpress-media/${"a".repeat(64)}.png`,
	);
	assert.equal(friends[1].avatar, "");
	const [item] = adaptAnnouncements([
		announcement({ content: "Line one\r\nLine two" }),
	]);
	assert.equal(item.content, "Line one\nLine two");
	assert.match(item.dismissKey, /^[a-f0-9]{20}$/);
	assert.equal(item.dismissKey, announcementDismissKey(item));
	assert.notEqual(
		item.dismissKey,
		adaptAnnouncements([announcement({ content: "Changed" })])[0].dismissKey,
	);
});

test("gateway does not touch generated files while disabled", () => {
	let reads = 0;
	const legacy = {
		projects: ["legacy"],
		skills: [],
		aiTools: [],
		timeline: [],
	};
	const result = loadStructuredContentSource({
		enabledValue: "false",
		legacy,
		generatedDir: "Z:/definitely-missing",
		readText() {
			reads += 1;
			throw new Error("must not read");
		},
	});
	assert.equal(reads, 0);
	assert.equal(result.source, "legacy");
	assert.deepEqual(result.projects, ["legacy"]);
});

test("gateway fails for missing and invalid generated JSON while enabled", () => {
	const options = {
		enabledValue: "true",
		legacy: { projects: [], skills: [], aiTools: [], timeline: [] },
		generatedDir: "X:/missing",
	};
	assert.throws(
		() =>
			loadStructuredContentSource({
				...options,
				readText: () => {
					throw new Error("ENOENT");
				},
			}),
		/Unable to read/,
	);
	assert.throws(
		() => loadStructuredContentSource({ ...options, readText: () => "{" }),
		/Unable to read/,
	);
});

test("gateway accepts real empty arrays and complete fixture data", () => {
	for (const bundle of [
		generatedBundle(),
		generatedBundle({
			projects: [project()],
			skills: [skill()],
			aiTools: [aiTool()],
			timeline: [timeline()],
		}),
	]) {
		const files = generatedFiles(bundle);
		const result = loadStructuredContentSource({
			enabledValue: "true",
			legacy: { projects: [], skills: [], aiTools: [], timeline: [] },
			generatedDir: "C:/generated",
			readText: (filePath) => files.get(path.basename(filePath)),
		});
		assert.equal(result.source, "wordpress");
		assert.equal(result.projects.length, bundle.projects.length);
	}
});

test("snapshot permits unconsumed top-level collections but selected records are strict", () => {
	assert.equal(
		parseSiteSnapshot(snapshot({ projects: [project()] })).friends.length,
		0,
	);
	assert.throws(
		() =>
			parseSiteSnapshot(
				snapshot({ projects: [project({ unexpected: true })] }),
			),
		/unrecognized/i,
	);
	assert.throws(
		() =>
			parseSiteSnapshot(
				snapshot({ skills: [skill({ category: "new-category" })] }),
			),
		/Invalid option/i,
	);
	assert.throws(
		() => parseSiteSnapshot(snapshot({ schemaVersion: 2 })),
		/schemaVersion/i,
	);
});

test("diary records use the v4 contract and reject the previous schema", () => {
	const parsed = parseSiteSnapshot(snapshot({ diary: [diary()] }));
	assert.equal(parsed.diary[0].id, "diary-one");
	assert.equal(parsed.diary[0].mood, "calm");
	assert.throws(
		() => parseSiteSnapshot(snapshot({ schemaVersion: 3, diary: [diary()] })),
		/schemaVersion.*0\.4\.0|schemaVersion/i,
	);
});

test("central media limits match the production contract", () => {
	assert.deepEqual(
		{
			file: SYNC_LIMITS.maxFileBytes,
			files: SYNC_LIMITS.maxFiles,
			total: SYNC_LIMITS.maxTotalBytes,
			redirects: SYNC_LIMITS.maxRedirects,
			connect: SYNC_LIMITS.connectTimeoutMs,
			headers: SYNC_LIMITS.headersTimeoutMs,
			body: SYNC_LIMITS.bodyTimeoutMs,
		},
		{
			file: 15 * 1024 * 1024,
			files: 1000,
			total: 250 * 1024 * 1024,
			redirects: 3,
			connect: 10_000,
			headers: 15_000,
			body: 30_000,
		},
	);
});

test("media URL and IP checks reject credentials, ports, private and mapped addresses", () => {
	assert.throws(
		() =>
			validateMediaUrl("https://user:pass@cms.example/a.png", "cms.example"),
		/credentials/,
	);
	assert.throws(
		() => validateMediaUrl("https://cms.example:8443/a.png", "cms.example"),
		/port/,
	);
	assert.equal(isPublicIpAddress("8.8.8.8"), true);
	assert.equal(isPublicIpAddress("127.0.0.1"), false);
	assert.equal(isPublicIpAddress("169.254.1.1"), false);
	assert.equal(isPublicIpAddress("10.0.0.1"), false);
	assert.equal(isPublicIpAddress("::1"), false);
	assert.equal(isPublicIpAddress("fe80::1"), false);
	assert.equal(isPublicIpAddress("::ffff:192.168.1.1"), false);
});

test("pinned lookup returns only prevalidated addresses without another DNS query", () => {
	const lookup = createPinnedLookup([{ address: "93.184.216.34", family: 4 }]);
	lookup("cms.example", { all: false }, (error, address, family) => {
		assert.ifError(error);
		assert.equal(address, "93.184.216.34");
		assert.equal(family, 4);
	});
});

test("DNS validation rejects a hostname if any resolved address is non-public", async () => {
	await assert.rejects(
		resolvePublicAddresses("cms.example", async () => [
			{ address: "93.184.216.34", family: 4 },
			{ address: "10.0.0.8", family: 4 },
		]),
		/non-public address/,
	);
});

test("MediaMirror uses MockAgent, validates MIME, hashes and deduplicates", async () => {
	const root = await mkdtemp(path.join(os.tmpdir(), "media-mirror-"));
	const agent = new MockAgent();
	agent.disableNetConnect();
	agent
		.get("https://cms.example")
		.intercept({ path: "/cover.png", method: "GET" })
		.reply(200, PNG, {
			headers: {
				"content-type": "image/png",
				"content-length": String(PNG.length),
			},
		});
	try {
		const mirror = new MediaMirror({
			allowedHost: "cms.example",
			outputDir: root,
			resolver: async () => [{ address: "93.184.216.34", family: 4 }],
			dispatcherFactory: async () => agent,
		});
		const manifest = {
			id: 9,
			url: "https://cms.example/cover.png",
			alt: "Cover",
			mimeType: "image/png",
			width: 2,
			height: 3,
		};
		const first = await mirror.mirror(manifest.url, { manifest });
		const second = await mirror.mirror(manifest.url, { manifest });
		assert.deepEqual(second, first);
		assert.equal(first.width, 2);
		assert.equal(first.height, 3);
		assert.match(
			first.url,
			/^\/generated\/wordpress-media\/[a-f0-9]{64}\.png$/,
		);
		assert.equal((await readdir(root)).length, 1);
	} finally {
		await agent.close().catch(() => {});
		await rm(root, { recursive: true, force: true });
	}
});

test("manifest MIME must match while inline-only media needs response compatibility", async () => {
	const root = await mkdtemp(path.join(os.tmpdir(), "media-mime-"));
	const requestImpl = async () => ({
		statusCode: 200,
		headers: {
			"content-type": "image/png",
			"content-length": String(PNG.length),
		},
		body: responseBody(PNG),
	});
	const options = {
		allowedHost: "cms.example",
		outputDir: root,
		resolver: async () => [{ address: "93.184.216.34", family: 4 }],
		requestImpl,
		dispatcherFactory: async () => ({ close: async () => {} }),
	};
	try {
		await assert.rejects(
			new MediaMirror(options).mirror("https://cms.example/wrong.jpg", {
				manifest: {
					id: 1,
					mimeType: "image/jpeg",
					alt: "",
					width: 2,
					height: 3,
				},
			}),
			/Snapshot MIME/,
		);
		const inline = await new MediaMirror(options).mirror(
			"https://cms.example/inline.png",
		);
		assert.equal(inline.mimeType, "image/png");
	} finally {
		await rm(root, { recursive: true, force: true });
	}
});

test("media downloader enforces response MIME, count, size and total limits", async () => {
	const root = await mkdtemp(path.join(os.tmpdir(), "media-limits-"));
	const baseOptions = {
		allowedHost: "cms.example",
		outputDir: root,
		resolver: async () => [{ address: "93.184.216.34", family: 4 }],
		dispatcherFactory: async () => ({ close: async () => {} }),
	};
	try {
		await assert.rejects(
			new MediaMirror({
				...baseOptions,
				requestImpl: async () => ({
					statusCode: 200,
					headers: { "content-type": "image/jpeg" },
					body: responseBody(PNG),
				}),
			}).mirror("https://cms.example/mismatch.png"),
			/Content-Type image\/jpeg does not match image\/png/,
		);

		await assert.rejects(
			new MediaMirror({
				...baseOptions,
				limits: { ...SYNC_LIMITS, maxFileBytes: PNG.length - 1 },
				requestImpl: async () => ({
					statusCode: 200,
					headers: {
						"content-type": "image/png",
						"content-length": String(PNG.length),
					},
					body: responseBody(PNG),
				}),
			}).mirror("https://cms.example/large.png"),
			/Media exceeds/,
		);

		const requestImpl = async () => ({
			statusCode: 200,
			headers: {
				"content-type": "image/png",
				"content-length": String(PNG.length),
			},
			body: responseBody(PNG),
		});
		const countMirror = new MediaMirror({
			...baseOptions,
			limits: { ...SYNC_LIMITS, maxFiles: 1 },
			requestImpl,
		});
		await countMirror.mirror("https://cms.example/one.png");
		await assert.rejects(
			countMirror.mirror("https://cms.example/two.png"),
			/Media batch exceeds 1 files/,
		);

		const totalMirror = new MediaMirror({
			...baseOptions,
			limits: { ...SYNC_LIMITS, maxTotalBytes: PNG.length },
			requestImpl,
		});
		await totalMirror.mirror("https://cms.example/total-one.png");
		await assert.rejects(
			totalMirror.mirror("https://cms.example/total-two.png"),
			/Media batch exceeds/,
		);
	} finally {
		await rm(root, { recursive: true, force: true });
	}
});

test("media downloader controls redirects and forwards timeout settings", async () => {
	const root = await mkdtemp(path.join(os.tmpdir(), "media-redirects-"));
	const observed = [];
	const dispatcherFactory = async ({ limits }) => {
		observed.push({ connectTimeoutMs: limits.connectTimeoutMs });
		return { close: async () => {} };
	};
	try {
		await assert.rejects(
			new MediaMirror({
				allowedHost: "cms.example",
				outputDir: root,
				resolver: async () => [{ address: "93.184.216.34", family: 4 }],
				dispatcherFactory,
				requestImpl: async () => ({
					statusCode: 302,
					headers: { location: "https://other.example/image.png" },
					body: responseBody(Buffer.from("redirect")),
				}),
			}).mirror("https://cms.example/redirect.png"),
			/Untrusted media host|Cross-host/,
		);

		let redirects = 0;
		await assert.rejects(
			new MediaMirror({
				allowedHost: "cms.example",
				outputDir: root,
				resolver: async () => [{ address: "93.184.216.34", family: 4 }],
				dispatcherFactory,
				requestImpl: async () => {
					redirects += 1;
					return {
						statusCode: 302,
						headers: { location: `/redirect-${redirects}.png` },
						body: responseBody(Buffer.from("redirect")),
					};
				},
			}).mirror("https://cms.example/start.png"),
			/exceeded 3 redirects/,
		);

		let requestOptions;
		await new MediaMirror({
			allowedHost: "cms.example",
			outputDir: root,
			resolver: async () => [{ address: "93.184.216.34", family: 4 }],
			dispatcherFactory,
			requestImpl: async (_url, options) => {
				requestOptions = options;
				return {
					statusCode: 200,
					headers: { "content-type": "image/png" },
					body: responseBody(PNG),
				};
			},
		}).mirror("https://cms.example/timeouts.png");
		assert.equal(requestOptions.headersTimeout, SYNC_LIMITS.headersTimeoutMs);
		assert.equal(requestOptions.bodyTimeout, SYNC_LIMITS.bodyTimeoutMs);
		assert.equal(requestOptions.maxRedirections, 0);
		assert.ok(
			observed.every(
				(entry) => entry.connectTimeoutMs === SYNC_LIMITS.connectTimeoutMs,
			),
		);
	} finally {
		await rm(root, { recursive: true, force: true });
	}
});

test("HTML parser mirrors data-src or srcset-only images and removes remote candidates", async () => {
	const seen = [];
	const html = await rewriteContentHtml(
		'<picture><source srcset="https://cms.example/large.png 2x"><img src="https://cms.example/placeholder.png" data-src="https://cms.example/real.png" srcset="https://cms.example/real-2x.png 2x" sizes="100vw" alt="Real"></picture><img srcset="https://cms.example/only.png 1x">',
		{
			baseUrl: "https://cms.example",
			manifestByUrl: new Map(),
			mirror: async (url) => {
				seen.push(url);
				return {
					url: `/generated/wordpress-media/${seen.length}.png`,
					alt: "",
				};
			},
		},
	);
	assert.deepEqual(seen, [
		"https://cms.example/real.png",
		"https://cms.example/only.png",
	]);
	assert.doesNotMatch(html, /data-src|srcset|sizes|cms\.example/);
	assert.match(html, /src="\/generated\/wordpress-media\/1\.png"/);
});

test("contentHtml WordPress image not present in mediaManifest still enters the mirror", async () => {
	const calls = [];
	const mirror = {
		async mirror(url, options) {
			calls.push({ url, options });
			return { url: "/generated/wordpress-media/local.png", alt: options.alt };
		},
	};
	const value = snapshot({
		projects: [
			project({
				contentHtml:
					'<p><img src="https://cms.example/uploads/unlisted.png" alt="Inline"></p>',
			}),
		],
	});
	const rewritten = await rewriteStructuredMedia(
		parseSiteSnapshot(value),
		mirror,
		"https://cms.example",
	);
	assert.equal(calls.length, 1);
	assert.equal(calls[0].options.manifest, null);
	assert.match(
		rewritten.projects[0].contentHtml,
		/\/generated\/wordpress-media\/local\.png/,
	);
});

async function writeSentinel(directory, value) {
	await mkdir(directory, { recursive: true });
	await writeFile(path.join(directory, "value.txt"), value, "utf8");
}

test("three-directory transaction fully rolls back after partial replacement", async () => {
	for (const failAfterIndex of [0, 1]) {
		const root = await mkdtemp(
			path.join(os.tmpdir(), `wordpress-transaction-${failAfterIndex}-`),
		);
		const transactionRoot = path.join(root, "transaction");
		const targets = ["posts", "generated", "media"].map((name) =>
			path.join(root, "target", name),
		);
		const staged = ["posts", "generated", "media"].map((name) =>
			path.join(transactionRoot, "stage", name),
		);
		try {
			for (let index = 0; index < 3; index += 1) {
				await writeSentinel(targets[index], `old-${index}`);
				await writeSentinel(staged[index], `new-${index}`);
			}
			await assert.rejects(
				commitDirectoryTransaction({
					transactionRoot,
					entries: targets.map((target, index) => ({
						name: String(index),
						target,
						staged: staged[index],
						mode: "replace",
					})),
					afterReplace: ({ index }) => {
						if (index === failAfterIndex) throw new Error("injected failure");
					},
				}),
				/Failed to replace/,
			);
			for (let index = 0; index < 3; index += 1) {
				assert.equal(
					await readFile(path.join(targets[index], "value.txt"), "utf8"),
					`old-${index}`,
				);
			}
		} finally {
			await rm(root, { recursive: true, force: true });
		}
	}
});

test("optional structured failure warns but native article failure remains strict", async () => {
	const root = await mkdtemp(
		path.join(os.tmpdir(), "wordpress-failure-policy-"),
	);
	const warnings = [];
	const logger = {
		info() {},
		error() {},
		warn(message) {
			warnings.push(message);
		},
	};
	try {
		const optionalFetch = async (url) => {
			if (new URL(url).pathname.includes("site-snapshot"))
				return new Response("offline", { status: 503 });
			return new Response(JSON.stringify([publishedPost()]), {
				status: 200,
				headers: { "X-WP-TotalPages": "1" },
			});
		};
		const result = await syncWordPress({
			baseUrl: "https://cms.example",
			repoRoot: root,
			structuredEnabled: false,
			fetchImpl: optionalFetch,
			logger,
		});
		assert.equal(result.structured.status, "unavailable");
		assert.equal(warnings.length, 1);
		assert.equal(
			await readFile(
				path.join(root, "src/content/posts/wordpress/post-one.md"),
				"utf8",
			).then((text) => text.includes("Post One")),
			true,
		);

		await assert.rejects(
			syncWordPress({
				baseUrl: "https://cms.example",
				repoRoot: root,
				structuredEnabled: false,
				fetchImpl: async () => new Response("offline", { status: 503 }),
				logger,
			}),
			/WordPress page 1 request failed/,
		);
		await assert.rejects(
			syncWordPress({
				baseUrl: "https://cms.example",
				repoRoot: root,
				structuredEnabled: true,
				fetchImpl: optionalFetch,
				logger,
			}),
			/site snapshot request failed/,
		);
	} finally {
		await rm(root, { recursive: true, force: true });
	}
});

test("enabled local sync may preserve a complete stale structured snapshot", async () => {
	const root = await mkdtemp(path.join(os.tmpdir(), "wordpress-stale-"));
	const generatedDir = path.join(root, "src/generated/wordpress");
	const mediaDir = path.join(root, "public/generated/wordpress-media");
	const bundle = generatedBundle();
	const files = generatedFiles(bundle);
	await mkdir(generatedDir, { recursive: true });
	await mkdir(mediaDir, { recursive: true });
	for (const [name, contents] of files) {
		await writeFile(path.join(generatedDir, name), contents, "utf8");
	}
	const warnings = [];
	try {
		const result = await syncWordPress({
			baseUrl: "https://cms.example",
			repoRoot: root,
			structuredEnabled: true,
			allowStale: true,
			fetchImpl: async (url) => {
				if (new URL(url).pathname.includes("site-snapshot")) {
					return new Response("offline", { status: 503 });
				}
				return new Response(JSON.stringify([publishedPost()]), {
					status: 200,
					headers: { "X-WP-TotalPages": "1" },
				});
			},
			logger: {
				info() {},
				error() {},
				warn(message) {
					warnings.push(message);
				},
			},
		});
		assert.equal(result.structured.status, "stale");
		assert.match(warnings[0], /STALE WORDPRESS STRUCTURED CONTENT/);
		assert.deepEqual(
			JSON.parse(
				await readFile(path.join(generatedDir, "snapshot-meta.json"), "utf8"),
			),
			bundle.meta,
		);
	} finally {
		await rm(root, { recursive: true, force: true });
	}
});

test("full sync writes mapped fixture JSON and deduplicated local media", async () => {
	const root = await mkdtemp(path.join(os.tmpdir(), "wordpress-full-sync-"));
	const coverUrl = "https://cms.example/uploads/cover.png";
	const inlineUrl = "https://cms.example/uploads/inline.png";
	const value = snapshot({
		projects: [
			project({
				image: coverUrl,
				imageMedia: {
					id: 10,
					url: coverUrl,
					alt: "Cover",
					mimeType: "image/png",
					width: 2,
					height: 3,
				},
			}),
		],
		skills: [skill()],
		aiTools: [aiTool()],
		timeline: [
			timeline({
				contentHtml: `<p><img src="${inlineUrl}" alt="Inline"></p>`,
			}),
		],
		diary: [
			diary({
				images: [
					{
						mediaId: 10,
						src: coverUrl,
						alt: "Diary cover",
						width: 2,
						height: 3,
					},
				],
				coverImage: {
					mediaId: 10,
					src: coverUrl,
					alt: "Diary cover",
					width: 2,
					height: 3,
				},
			}),
		],
		mediaManifest: [
			{
				id: 10,
				url: coverUrl,
				alt: "Cover",
				mimeType: "image/png",
				width: 2,
				height: 3,
			},
		],
	});
	try {
		const result = await syncWordPress({
			baseUrl: "https://cms.example",
			repoRoot: root,
			structuredEnabled: true,
			fetchImpl: async (url) => {
				const pathname = new URL(url).pathname;
				return new Response(
					JSON.stringify(
						pathname.includes("site-snapshot") ? value : [publishedPost()],
					),
					{ status: 200, headers: { "X-WP-TotalPages": "1" } },
				);
			},
			mediaOptions: {
				resolver: async () => [{ address: "93.184.216.34", family: 4 }],
				dispatcherFactory: async () => ({ close: async () => {} }),
				requestImpl: async () => ({
					statusCode: 200,
					headers: {
						"content-type": "image/png",
						"content-length": String(PNG.length),
					},
					body: responseBody(PNG),
				}),
			},
			logger: { info() {}, error() {}, warn() {} },
		});
		assert.deepEqual(result.structured.counts, {
			posts: 1,
			projects: 1,
			skills: 1,
			aiTools: 1,
			timeline: 1,
			friends: 0,
			announcements: 0,
			techRadar: 0,
			learningResources: 0,
			diary: 1,
			media: 2,
		});
		const projects = JSON.parse(
			await readFile(
				path.join(root, "src/generated/wordpress/projects.json"),
				"utf8",
			),
		);
		const timelineItems = JSON.parse(
			await readFile(
				path.join(root, "src/generated/wordpress/timeline.json"),
				"utf8",
			),
		);
		const diaryItems = JSON.parse(
			await readFile(
				path.join(root, "src/generated/wordpress/diary.json"),
				"utf8",
			),
		);
		assert.match(
			projects[0].image,
			/^\/generated\/wordpress-media\/[a-f0-9]{64}\.png$/,
		);
		assert.match(
			timelineItems[0].contentHtml,
			/\/generated\/wordpress-media\/[a-f0-9]{64}\.png/,
		);
		assert.match(
			diaryItems[0].images[0].src,
			/^\/generated\/wordpress-media\/[a-f0-9]{64}\.png$/,
		);
		assert.equal(
			(await readdir(path.join(root, "public/generated/wordpress-media")))
				.length,
			1,
		);
	} finally {
		await rm(root, { recursive: true, force: true });
	}
});

test("parseGeneratedBundle rejects invalid JSON shapes at the final build seam", () => {
	assert.throws(
		() =>
			parseGeneratedBundle(
				generatedBundle({ aiTools: [aiTool({ frequency: "hourly" })] }),
			),
		/Invalid option/i,
	);
});
