import { mkdir, mkdtemp, rename, rm, writeFile } from "node:fs/promises";
import path from "node:path";
import { setTimeout as delay } from "node:timers/promises";
import { pathToFileURL } from "node:url";
import he from "he";
import { parse } from "node-html-parser";
import TurndownService from "turndown";
import { gfm } from "turndown-plugin-gfm";

const DEFAULT_BASE_URL = "https://cms.jaisong1n.com";
const DEFAULT_OUTPUT_DIR = path.resolve("src/content/posts/wordpress");
const DEFAULT_AUTHOR = "JaisonG1n";
const REQUEST_TIMEOUT_MS = 30_000;
const WINDOWS_RESERVED_NAMES = /^(con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|$)/i;
const RETRYABLE_RENAME_ERRORS = new Set(["EACCES", "EBUSY", "EPERM"]);

async function renameWithRetry(source, destination) {
	for (let attempt = 1; ; attempt += 1) {
		try {
			await rename(source, destination);
			return;
		} catch (error) {
			if (attempt >= 10 || !RETRYABLE_RENAME_ERRORS.has(error.code)) throw error;
			await delay(attempt * 100);
		}
	}
}

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
	for (const node of root.querySelectorAll("style, noscript, template")) {
		node.remove();
	}
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

	const title = decodeHtml(post.title?.rendered) || `未命名文章 ${post.id}`;
	const body = htmlToMarkdown(post.content?.rendered);
	const excerpt = htmlToPlainText(post.excerpt?.rendered);
	const description = excerpt || truncateCodePoints(htmlToPlainText(post.content?.rendered), 180);
	const published = normalizeWordPressDate(post.date_gmt, post.date, "published");
	const updated = normalizeWordPressDate(
		post.modified_gmt ?? post.date_gmt,
		post.modified ?? post.date,
		"updated",
	);
	const tags = embeddedTerms(post, "post_tag");
	const category = embeddedTerms(post, "category")[0] || "未分类";
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

function buildPostsUrl(baseUrl, page) {
	let parsedBase;
	try {
		parsedBase = new URL(baseUrl);
	} catch (error) {
		throw new Error(`Invalid WP_BASE_URL: ${baseUrl}`, { cause: error });
	}
	if (!['http:', 'https:'].includes(parsedBase.protocol)) {
		throw new Error("WP_BASE_URL must use http or https");
	}
	const url = new URL("/wp-json/wp/v2/posts", parsedBase);
	for (const [key, value] of Object.entries({
		status: "publish",
		per_page: "100",
		page: String(page),
		_embed: "1",
		orderby: "date",
		order: "desc",
	})) {
		url.searchParams.set(key, value);
	}
	return url;
}

async function fetchPage(baseUrl, page, fetchImpl) {
	const url = buildPostsUrl(baseUrl, page);
	let response;
	try {
		response = await fetchImpl(url, {
			headers: { Accept: "application/json" },
			signal: AbortSignal.timeout(REQUEST_TIMEOUT_MS),
		});
	} catch (error) {
		throw new Error(`WordPress request failed for page ${page}: ${error.message}`, { cause: error });
	}
	if (!response.ok) {
		const detail = (await response.text()).slice(0, 300).replace(/\s+/g, " ");
		throw new Error(`WordPress request failed for page ${page}: HTTP ${response.status} ${response.statusText}${detail ? ` - ${detail}` : ""}`);
	}
	let posts;
	try {
		posts = await response.json();
	} catch (error) {
		throw new Error(`WordPress returned invalid JSON for page ${page}`, { cause: error });
	}
	if (!Array.isArray(posts)) throw new Error(`WordPress page ${page} did not return an array`);
	return { posts, response };
}

export async function fetchPublishedPosts({ baseUrl, fetchImpl = fetch, logger = console }) {
	const first = await fetchPage(baseUrl, 1, fetchImpl);
	const totalPagesHeader = first.response.headers.get("x-wp-totalpages");
	const totalPages = totalPagesHeader === null ? 1 : Number.parseInt(totalPagesHeader, 10);
	if (!Number.isInteger(totalPages) || totalPages < 0) {
		throw new Error(`Invalid X-WP-TotalPages header: ${totalPagesHeader}`);
	}
	logger.info(`WordPress page 1/${Math.max(totalPages, 1)}`);
	const posts = [...first.posts];
	for (let page = 2; page <= totalPages; page += 1) {
		logger.info(`WordPress page ${page}/${totalPages}`);
		posts.push(...(await fetchPage(baseUrl, page, fetchImpl)).posts);
	}
	return posts.filter((post) => post?.status === "publish");
}

export async function syncWordPress({
	baseUrl = process.env.WP_BASE_URL || DEFAULT_BASE_URL,
	outputDir = DEFAULT_OUTPUT_DIR,
	fetchImpl = fetch,
	logger = console,
} = {}) {
	const normalizedBaseUrl = new URL(baseUrl).origin;
	logger.info(`WordPress source: ${normalizedBaseUrl}`);
	const posts = await fetchPublishedPosts({ baseUrl, fetchImpl, logger });
	const outputParent = path.dirname(outputDir);
	await mkdir(outputParent, { recursive: true });
	const stagingDir = await mkdtemp(path.join(outputParent, ".wordpress-sync-"));
	const backupDir = `${stagingDir}-previous`;
	const usedNames = new Set();
	const generatedFiles = [];
	const failures = [];

	try {
		for (const post of posts) {
			try {
				const fileName = createSafeFilename(post.slug, post.id, usedNames);
				const target = path.join(stagingDir, fileName);
				await writeFile(target, buildPostMarkdown(post), "utf8");
				generatedFiles.push(fileName);
				logger.info(`Generated: ${decodeHtml(post.title?.rendered)} -> ${fileName}`);
			} catch (error) {
				failures.push({ id: post?.id, error });
				logger.error(`Failed post ${post?.id ?? "unknown"}: ${error.message}`);
			}
		}

		if (failures.length > 0) {
			throw new Error(`Failed to convert ${failures.length} WordPress post(s)`);
		}

		let previousOutputMoved = false;
		try {
			await renameWithRetry(outputDir, backupDir);
			previousOutputMoved = true;
		} catch (error) {
			if (error.code !== "ENOENT") throw error;
		}

		try {
			await renameWithRetry(stagingDir, outputDir);
		} catch (error) {
			if (previousOutputMoved) await renameWithRetry(backupDir, outputDir);
			throw error;
		}
		if (previousOutputMoved) await rm(backupDir, { recursive: true, force: true });
		logger.info(`WordPress sync complete: ${generatedFiles.length} article(s)`);
		return { count: generatedFiles.length, files: generatedFiles };
	} catch (error) {
		await rm(stagingDir, { recursive: true, force: true });
		throw error;
	}
}

const isDirectExecution = process.argv[1]
	? pathToFileURL(path.resolve(process.argv[1])).href === import.meta.url
	: false;

if (isDirectExecution) {
	syncWordPress().catch((error) => {
		console.error(`WordPress sync failed: ${error.message}`);
		process.exitCode = 1;
	});
}
