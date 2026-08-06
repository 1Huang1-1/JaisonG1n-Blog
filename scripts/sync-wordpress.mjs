import { access, mkdir, mkdtemp, readFile, rm, writeFile } from "node:fs/promises";
import path from "node:path";
import { pathToFileURL } from "node:url";
import he from "he";
import { parse } from "node-html-parser";
import TurndownService from "turndown";
import { gfm } from "turndown-plugin-gfm";
import {
	parseGeneratedBundle,
	parseSiteSnapshot,
	parseStructuredContentFlag,
	SYNC_LIMITS,
} from "./wordpress-sync/contracts.mjs";
import { MediaMirror, rewriteStructuredMedia } from "./wordpress-sync/media.mjs";
import { buildFetchDispatcher, SYNC_USER_AGENT } from "./wordpress-sync/network.mjs";
import { describeNetworkError, withNetworkRetries } from "./wordpress-sync/retry.mjs";
import { commitDirectoryTransaction } from "./wordpress-sync/transaction.mjs";
import { resolvePostPath } from "./wordpress-sync/post-path.mjs";

const DEFAULT_BASE_URL = "https://cms.jaisong1n.com";
const DEFAULT_AUTHOR = "JaisonG1n";
const WINDOWS_RESERVED_NAMES = /^(con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|$)/i;

function decodeHtml(value) {
	return he.decode(String(value ?? ""), { isAttributeValue: false }).trim();
}

function removeUnsafeHtml(html) {
	return String(html ?? "")
		.replace(/<!--\s*\/?wp:[\s\S]*?-->/gi, "")
		.replace(/<script\b[^>]*>[\s\S]*?<\/script\s*>/gi, "")
		.replace(/<script\b[^>]*\/?\s*>/gi, "");
}

export function htmlToPlainText(html) {
	const root = parse(removeUnsafeHtml(html));
	for (const node of root.querySelectorAll("style, noscript, template")) node.remove();
	return decodeHtml(root.textContent).replace(/\s+/g, " ").trim();
}

export function htmlToMarkdown(html) {
	const turndown = new TurndownService({
		bulletListMarker: "-",
		codeBlockStyle: "fenced",
		emDelimiter: "_",
		headingStyle: "atx",
		strongDelimiter: "**",
	});
	turndown.use(gfm);
	turndown.keep(["iframe", "video", "audio"]);
	return turndown.turndown(removeUnsafeHtml(html)).trim();
}

function normalizeWordPressDate(gmtValue, localValue, fieldName) {
	for (const value of [gmtValue, localValue]) {
		if (!value || String(value).startsWith("0000-00-00")) continue;
		const raw = String(value).trim();
		const withZone = /(?:Z|[+-]\d{2}:?\d{2})$/i.test(raw) ? raw : `${raw}Z`;
		const date = new Date(withZone);
		if (!Number.isNaN(date.getTime())) return date.toISOString();
	}
	throw new Error(`WordPress post has no valid ${fieldName} date`);
}

function decodeSlug(value) {
	const slug = String(value ?? "").trim();
	if (!slug) return "";
	try {
		return decodeURIComponent(slug);
	} catch {
		return slug;
	}
}

function truncateCodePoints(value, length) {
	return Array.from(value).slice(0, length).join("");
}

export function createSafeFilename(slug, postId, usedNames = new Set()) {
	let base = decodeSlug(slug)
		.replace(/[<>:"/\\|?*\u0000-\u001f]/g, "-")
		.replace(/\.\.+/g, "-")
		.replace(/\s+/g, "-")
		.replace(/-+/g, "-")
		.replace(/^[.\s-]+|[.\s-]+$/g, "");
	base = truncateCodePoints(base, 120);
	if (!base) base = `post-${postId}`;
	if (WINDOWS_RESERVED_NAMES.test(base)) base = `post-${postId}-${base}`;

	let candidate = base;
	let sequence = 2;
	while (usedNames.has(candidate.toLowerCase())) {
		candidate = `${base}-${postId}${sequence === 2 ? "" : `-${sequence}`}`;
		sequence += 1;
	}
	usedNames.add(candidate.toLowerCase());
	return `${candidate}.md`;
}

function embeddedTerms(post, taxonomy) {
	const groups = post?._embedded?.["wp:term"];
	if (!Array.isArray(groups)) return [];
	return groups
		.flatMap((group) => (Array.isArray(group) ? group : []))
		.filter((term) => term?.taxonomy === taxonomy)
		.map((term) => decodeHtml(term.name))
		.filter(Boolean);
}

function embeddedAuthor(post) {
	const name = decodeHtml(post?._embedded?.author?.[0]?.name);
	if (!name || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(name)) return DEFAULT_AUTHOR;
	return name;
}

function featuredImage(post) {
	return decodeHtml(post?._embedded?.["wp:featuredmedia"]?.[0]?.source_url);
}

function yamlString(value) {
	return JSON.stringify(String(value ?? ""));
}

export function buildPostMarkdown(post) {
	if (!post || typeof post !== "object") throw new Error("Invalid WordPress post object");
	if (post.status !== "publish") throw new Error(`Post ${post.id ?? "unknown"} is not published`);

	const title = decodeHtml(post.title?.rendered) || `Untitled post ${post.id}`;
	const body = htmlToMarkdown(post.content?.rendered);
	const excerpt = htmlToPlainText(post.excerpt?.rendered);
	const description = excerpt || truncateCodePoints(htmlToPlainText(post.content?.rendered), 180);
	const published = normalizeWordPressDate(post.date_gmt, post.date, "published");
	const updated = normalizeWordPressDate(post.modified_gmt ?? post.date_gmt, post.modified ?? post.date, "updated");
	const tags = embeddedTerms(post, "post_tag");
	const category = embeddedTerms(post, "category")[0] || "Uncategorized";
	const alias = decodeSlug(post.slug) || `post-${post.id}`;

	const frontmatter = [
		"---",
		`title: ${yamlString(title)}`,
		`published: ${published}`,
		`updated: ${updated}`,
		`description: ${yamlString(description)}`,
		`image: ${yamlString(featuredImage(post))}`,
		`tags: ${JSON.stringify(tags)}`,
		`category: ${yamlString(category)}`,
		"draft: false",
		`pinned: ${Boolean(post.sticky)}`,
		`comment: ${post.comment_status === "open"}`,
		`author: ${yamlString(embeddedAuthor(post))}`,
		`alias: ${yamlString(alias)}`,
		"---",
	];
	return `${frontmatter.join("\n")}\n\n${body}\n`;
}

function normalizeBaseUrl(baseUrl) {
	let url;
	try {
		url = new URL(baseUrl);
	} catch (error) {
		throw new Error(`Invalid WP_BASE_URL: ${baseUrl}`, { cause: error });
	}
	if (!["http:", "https:"].includes(url.protocol)) throw new Error("WP_BASE_URL must use HTTP(S)");
	if (url.username || url.password) throw new Error("WP_BASE_URL must not contain credentials");
	return url.origin;
}

function buildPostsUrl(baseUrl, page) {
	const url = new URL("/wp-json/wp/v2/posts", baseUrl);
	for (const [key, value] of Object.entries({
		status: "publish",
		per_page: "100",
		page: String(page),
		_embed: "1",
		orderby: "date",
		order: "desc",
	})) url.searchParams.set(key, value);
	return url;
}

const fetchDispatchers = new Map();

async function dispatcherFor(target, resolver) {
	// With an injected resolver (tests), always build a fresh dispatcher.
	if (resolver) return buildFetchDispatcher(target, { resolver });
	const origin = target.origin;
	if (!fetchDispatchers.has(origin)) {
		fetchDispatchers.set(origin, buildFetchDispatcher(target));
	}
	return fetchDispatchers.get(origin);
}

async function fetchJsonResponse(url, fetchImpl, description, retryOptions = {}, resolver) {
	let response;
	try {
		response = await withNetworkRetries(
			async () => {
				const options = {
					headers: { Accept: "application/json", "User-Agent": SYNC_USER_AGENT },
					signal: AbortSignal.timeout(SYNC_LIMITS.requestTimeoutMs),
				};
				if (fetchImpl === fetch) {
					options.dispatcher = await dispatcherFor(new URL(url), resolver);
				}
				return fetchImpl(url, options);
			},
			{
				maxRetries: retryOptions.maxRetries ?? SYNC_LIMITS.maxRetries,
				retryDelayMs: retryOptions.retryDelayMs ?? SYNC_LIMITS.retryDelayMs,
				sleep: retryOptions.sleep,
			},
		);
	} catch (error) {
		throw new Error(`${description} request failed: ${describeNetworkError(error)}`, { cause: error });
	}
	if (!response.ok) {
		const detail = (await response.text()).slice(0, 300).replace(/\s+/g, " ");
		throw new Error(`${description} request failed: HTTP ${response.status} ${response.statusText}${detail ? ` - ${detail}` : ""}`);
	}
	return response;
}

async function fetchPage(baseUrl, page, fetchImpl, retryOptions, resolver) {
	const response = await fetchJsonResponse(buildPostsUrl(baseUrl, page), fetchImpl, `WordPress page ${page}`, retryOptions, resolver);
	let posts;
	try {
		posts = await response.json();
	} catch (error) {
		throw new Error(`WordPress returned invalid JSON for page ${page}`, { cause: error });
	}
	if (!Array.isArray(posts)) throw new Error(`WordPress page ${page} did not return an array`);
	return { posts, response };
}

export async function fetchPublishedPosts({ baseUrl, fetchImpl = fetch, logger = console, retryOptions, resolver }) {
	const first = await fetchPage(baseUrl, 1, fetchImpl, retryOptions, resolver);
	const totalPagesHeader = first.response.headers.get("x-wp-totalpages");
	const totalPages = totalPagesHeader === null ? 1 : Number.parseInt(totalPagesHeader, 10);
	if (!Number.isInteger(totalPages) || totalPages < 0) throw new Error(`Invalid X-WP-TotalPages header: ${totalPagesHeader}`);
	logger.info(`WordPress page 1/${Math.max(totalPages, 1)}`);
	const posts = [...first.posts];
	for (let page = 2; page <= totalPages; page += 1) {
		logger.info(`WordPress page ${page}/${totalPages}`);
		posts.push(...(await fetchPage(baseUrl, page, fetchImpl, retryOptions, resolver)).posts);
	}
	return posts.filter((post) => post?.status === "publish");
}

export async function fetchSiteSnapshot({ baseUrl, fetchImpl = fetch, retryOptions, resolver }) {
	const response = await fetchJsonResponse(
		new URL("/wp-json/jaisong1n/v1/site-snapshot", baseUrl),
		fetchImpl,
		"WordPress site snapshot",
		retryOptions,
		resolver,
	);
	const declaredLength = Number.parseInt(response.headers.get("content-length") ?? "", 10);
	if (Number.isFinite(declaredLength) && declaredLength > SYNC_LIMITS.maxSnapshotBytes) throw new Error("WordPress site snapshot exceeds 2 MiB");
	const text = await response.text();
	if (Buffer.byteLength(text) > SYNC_LIMITS.maxSnapshotBytes) throw new Error("WordPress site snapshot exceeds 2 MiB");
	let value;
	try {
		value = JSON.parse(text);
	} catch (error) {
		throw new Error("WordPress site snapshot returned invalid JSON", { cause: error });
	}
	return parseSiteSnapshot(value);
}

async function generatePosts(posts, outputDir, logger) {
	await mkdir(outputDir, { recursive: true });
	const usedNames = new Set();
	const files = [];
	const failures = [];
	for (const post of posts) {
		try {
			const fileName = createSafeFilename(post.slug, post.id, usedNames);
			await writeFile(path.join(outputDir, fileName), buildPostMarkdown(post), "utf8");
			files.push(fileName);
			logger.info(`Generated article: ${fileName}`);
		} catch (error) {
			failures.push(error);
			logger.error(`Failed post ${post?.id ?? "unknown"}: ${error.message}`);
		}
	}
	if (failures.length > 0) throw new AggregateError(failures, `Failed to convert ${failures.length} WordPress post(s)`);
	return files;
}

async function writeJson(filePath, value) {
	await writeFile(filePath, `${JSON.stringify(value, null, 2)}\n`, "utf8");
}

async function readGeneratedBundle(generatedDir) {
	const readJson = async (name) => JSON.parse(await readFile(path.join(generatedDir, name), "utf8"));
	return parseGeneratedBundle({
		meta: await readJson("snapshot-meta.json"),
		projects: await readJson("projects.json"),
		skills: await readJson("skills.json"),
		aiTools: await readJson("ai-tools.json"),
		timeline: await readJson("timeline.json"),
		friends: await readJson("friends.json"),
		announcements: await readJson("announcements.json"),
		techRadar: await readJson("tech-radar.json"),
		learningResources: await readJson("learning-resources.json"),
		diary: await readJson("diary.json"),
		albums: await readJson("albums.json"),
	});
}

export async function validateExistingGenerated(generatedDir, mediaDir) {
	const bundle = await readGeneratedBundle(generatedDir);
	for (const media of bundle.meta.media) {
		await access(path.join(mediaDir, path.basename(media.url)));
	}
	return bundle;
}

function enrichRelatedPost(value, posts, permalinkConfig) {
	if (!value) return null;
	const post = posts.find((candidate) => Number(candidate?.id) === Number(value.postId) && candidate?.status === "publish");
	if (!post) return null;
	const pathValue = resolvePostPath({
		...post,
		title: decodeHtml(post.title?.rendered),
		alias: decodeSlug(post.slug),
	}, permalinkConfig);
	if (!pathValue) return null;
	return {
		postId: Number(post.id),
		title: decodeHtml(post.title?.rendered) || `Post ${post.id}`,
		slug: decodeSlug(post.slug),
		path: pathValue,
	};
}

function enrichStructuredRelations(rewritten, posts, permalinkConfig) {
	return {
		...rewritten,
		techRadar: rewritten.techRadar.map((item) => ({ ...item, relatedPost: enrichRelatedPost(item.relatedPost, posts, permalinkConfig) })),
		learningResources: rewritten.learningResources.map((item) => ({ ...item, relatedPost: enrichRelatedPost(item.relatedPost, posts, permalinkConfig) })),
	};
}

async function generateStructured({ snapshot, baseUrl, posts, generatedDir, mediaDir, mediaOptions, permalinkConfig }) {
	await mkdir(generatedDir, { recursive: true });
	await mkdir(mediaDir, { recursive: true });
	const allowedHost = new URL(baseUrl).hostname;
	const mirror = new MediaMirror({ allowedHost, outputDir: mediaDir, ...mediaOptions });
	const rewritten = enrichStructuredRelations(
		await rewriteStructuredMedia(snapshot, mirror, baseUrl),
		posts,
		permalinkConfig,
	);
	const meta = {
		schemaVersion: 5,
		revision: snapshot.revision,
		generatedAt: snapshot.generatedAt,
		syncedAt: new Date().toISOString(),
		sourceUrl: baseUrl,
		counts: {
			posts: posts.length,
			projects: rewritten.projects.length,
			skills: rewritten.skills.length,
			aiTools: rewritten.aiTools.length,
			timeline: rewritten.timeline.length,
			friends: rewritten.friends.length,
			announcements: rewritten.announcements.length,
		techRadar: rewritten.techRadar.length,
			learningResources: rewritten.learningResources.length,
			diary: rewritten.diary.length,
			albums: rewritten.albums.length,
			media: mirror.getRecords().length,
		},
		media: mirror.getRecords(),
	};
	const bundle = parseGeneratedBundle({ meta, ...rewritten });
	await Promise.all([
		writeJson(path.join(generatedDir, "snapshot-meta.json"), bundle.meta),
		writeJson(path.join(generatedDir, "projects.json"), bundle.projects),
		writeJson(path.join(generatedDir, "skills.json"), bundle.skills),
		writeJson(path.join(generatedDir, "ai-tools.json"), bundle.aiTools),
		writeJson(path.join(generatedDir, "timeline.json"), bundle.timeline),
		writeJson(path.join(generatedDir, "friends.json"), bundle.friends),
		writeJson(path.join(generatedDir, "announcements.json"), bundle.announcements),
		writeJson(path.join(generatedDir, "tech-radar.json"), bundle.techRadar),
		writeJson(path.join(generatedDir, "learning-resources.json"), bundle.learningResources),
		writeJson(path.join(generatedDir, "diary.json"), bundle.diary),
		writeJson(path.join(generatedDir, "albums.json"), bundle.albums),
	]);
	return bundle;
}

export async function syncWordPress({
	baseUrl = process.env.WP_BASE_URL || DEFAULT_BASE_URL,
	repoRoot = path.resolve("."),
	structuredEnabled = parseStructuredContentFlag(process.env.WORDPRESS_STRUCTURED_CONTENT_ENABLED),
	allowStale = false,
	fetchImpl = fetch,
	logger = console,
	mediaOptions = {},
	permalinkConfig = { permalinkEnabled: false, permalinkFormat: "%postname%" },
	retryOptions,
	resolver,
	afterReplace,
} = {}) {
	const sourceUrl = normalizeBaseUrl(baseUrl);
	const targets = {
		posts: path.join(repoRoot, "src/content/posts/wordpress"),
		generated: path.join(repoRoot, "src/generated/wordpress"),
		media: path.join(repoRoot, "public/generated/wordpress-media"),
	};
	logger.info(`WordPress source: ${sourceUrl}`);

	// Native posts are always strict and must finish before the optional snapshot path.
	const posts = await fetchPublishedPosts({ baseUrl: sourceUrl, fetchImpl, logger, retryOptions, resolver });
	const transactionRoot = await mkdtemp(path.join(repoRoot, ".wordpress-sync-"));
	const stagingRoot = path.join(transactionRoot, "stage");
	const staged = {
		posts: path.join(stagingRoot, "posts"),
		generated: path.join(stagingRoot, "generated"),
		media: path.join(stagingRoot, "media"),
	};
	let committed = false;
	try {
		const files = await generatePosts(posts, staged.posts, logger);
		let structuredStatus = "fresh";
		let structuredBundle = null;
		let structuredError = null;
		try {
			const snapshot = await fetchSiteSnapshot({ baseUrl: sourceUrl, fetchImpl, retryOptions, resolver });
			structuredBundle = await generateStructured({
				snapshot,
				baseUrl: sourceUrl,
				posts,
				generatedDir: staged.generated,
				mediaDir: staged.media,
				mediaOptions,
				permalinkConfig,
			});
		} catch (error) {
			structuredError = error;
			if (structuredEnabled && allowStale) {
				try {
					structuredBundle = await validateExistingGenerated(targets.generated, targets.media);
					structuredStatus = "stale";
					(logger.warn ?? logger.info)(`STALE WORDPRESS STRUCTURED CONTENT: ${error.message}`);
				} catch (staleError) {
					throw new AggregateError([error, staleError], "Structured sync failed and no valid stale snapshot is available");
				}
			} else if (structuredEnabled) {
				throw error;
			} else {
				structuredStatus = "unavailable";
				(logger.warn ?? logger.info)(`WordPress structured sync skipped after warning: ${error.message}`);
			}
		}

		const replaceStructured = structuredError === null;
		await commitDirectoryTransaction({
			transactionRoot,
			afterReplace,
			entries: [
				{ name: "posts", target: targets.posts, staged: staged.posts, mode: "replace" },
				{ name: "generated", target: targets.generated, staged: staged.generated, mode: replaceStructured ? "replace" : "preserve" },
				{ name: "media", target: targets.media, staged: staged.media, mode: replaceStructured ? "replace" : "preserve" },
			],
		});
		committed = true;
		logger.info(`WordPress article sync complete: ${files.length} article(s)`);
		if (structuredBundle) {
			logger.info(`WordPress structured sync ${structuredStatus}: projects=${structuredBundle.projects.length}, skills=${structuredBundle.skills.length}, aiTools=${structuredBundle.aiTools.length}, timeline=${structuredBundle.timeline.length}, friends=${structuredBundle.friends.length}, announcements=${structuredBundle.announcements.length}, techRadar=${structuredBundle.techRadar.length}, learningResources=${structuredBundle.learningResources.length}, diary=${structuredBundle.diary.length}, albums=${structuredBundle.albums.length}`);
		}
		return {
			count: files.length,
			files,
			structured: {
				status: structuredStatus,
				counts: structuredBundle?.meta.counts ?? null,
			},
		};
	} finally {
		await rm(transactionRoot, { recursive: true, force: true });
		if (!committed) logger.error("WordPress sync transaction was not committed");
	}
}

const isDirectExecution = process.argv[1]
	? pathToFileURL(path.resolve(process.argv[1])).href === import.meta.url
	: false;

if (isDirectExecution) {
	const allowedArgs = new Set(["--allow-stale"]);
	const unknownArgs = process.argv.slice(2).filter((arg) => !allowedArgs.has(arg));
	if (unknownArgs.length > 0) {
		console.error(`Unknown sync argument(s): ${unknownArgs.join(", ")}`);
		process.exitCode = 1;
	} else {
		syncWordPress({ allowStale: process.argv.includes("--allow-stale") }).catch((error) => {
			console.error(`WordPress sync failed: ${error.message}`);
			process.exitCode = 1;
		});
	}
}
