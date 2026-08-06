import { createHash } from "node:crypto";
import { lookup as dnsLookup } from "node:dns/promises";
import net from "node:net";
import path from "node:path";
import { writeFile } from "node:fs/promises";
import { fileTypeFromBuffer } from "file-type";
import { parse } from "node-html-parser";
import sharp from "sharp";
import { Agent, request as undiciRequest } from "undici";
import { ALLOWED_IMAGE_MIME_TYPES, SYNC_LIMITS } from "./contracts.mjs";
import { buildSyncHeaders } from "./network.mjs";
import { withNetworkRetries } from "./retry.mjs";

const MIME_EXTENSION = new Map([
	["image/jpeg", "jpg"],
	["image/png", "png"],
	["image/webp", "webp"],
	["image/gif", "gif"],
	["image/avif", "avif"],
]);
const REDIRECT_STATUSES = new Set([301, 302, 303, 307, 308]);

function parseIpv4(address) {
	const parts = address.split(".");
	if (parts.length !== 4) return null;
	const numbers = parts.map((part) => Number(part));
	if (numbers.some((part) => !Number.isInteger(part) || part < 0 || part > 255)) return null;
	return numbers;
}

function isPublicIpv4(address) {
	const parts = parseIpv4(address);
	if (!parts) return false;
	const [a, b, c] = parts;
	if (a === 0 || a === 10 || a === 127) return false;
	if (a === 100 && b >= 64 && b <= 127) return false;
	if (a === 169 && b === 254) return false;
	if (a === 172 && b >= 16 && b <= 31) return false;
	if (a === 192 && b === 0 && c === 0) return false;
	if (a === 192 && b === 0 && c === 2) return false;
	if (a === 192 && b === 168) return false;
	if (a === 198 && (b === 18 || b === 19)) return false;
	if (a === 198 && b === 51 && c === 100) return false;
	if (a === 203 && b === 0 && c === 113) return false;
	if (a >= 224) return false;
	return true;
}

function expandIpv6(address) {
	let normalized = address.toLowerCase().split("%")[0];
	const ipv4Match = normalized.match(/^(.*:)(\d+\.\d+\.\d+\.\d+)$/);
	if (ipv4Match) {
		const bytes = parseIpv4(ipv4Match[2]);
		if (!bytes) return null;
		normalized = `${ipv4Match[1]}${((bytes[0] << 8) | bytes[1]).toString(16)}:${((bytes[2] << 8) | bytes[3]).toString(16)}`;
	}
	const halves = normalized.split("::");
	if (halves.length > 2) return null;
	const left = halves[0] ? halves[0].split(":") : [];
	const right = halves[1] ? halves[1].split(":") : [];
	if (halves.length === 1 && left.length !== 8) return null;
	const missing = 8 - left.length - right.length;
	if (missing < 0 || (halves.length === 2 && missing < 1)) return null;
	const groups = [...left, ...Array(missing).fill("0"), ...right].map((part) => Number.parseInt(part || "0", 16));
	if (groups.length !== 8 || groups.some((part) => !Number.isInteger(part) || part < 0 || part > 0xffff)) return null;
	return groups;
}

function mappedIpv4(groups) {
	if (groups.slice(0, 5).some((group) => group !== 0) || groups[5] !== 0xffff) return null;
	return `${groups[6] >> 8}.${groups[6] & 0xff}.${groups[7] >> 8}.${groups[7] & 0xff}`;
}

function isPublicIpv6(address) {
	const groups = expandIpv6(address);
	if (!groups) return false;
	const mapped = mappedIpv4(groups);
	if (mapped) return isPublicIpv4(mapped);
	if (groups.every((group) => group === 0)) return false;
	if (groups.slice(0, 7).every((group) => group === 0) && groups[7] === 1) return false;
	if ((groups[0] & 0xfe00) === 0xfc00) return false;
	if ((groups[0] & 0xffc0) === 0xfe80) return false;
	if ((groups[0] & 0xffc0) === 0xfec0) return false;
	if ((groups[0] & 0xff00) === 0xff00) return false;
	if (groups[0] === 0x2001 && groups[1] === 0x0db8) return false;
	return true;
}

export function isPublicIpAddress(address) {
	const family = net.isIP(address);
	if (family === 4) return isPublicIpv4(address);
	if (family === 6) return isPublicIpv6(address);
	return false;
}

export function validateMediaUrl(value, allowedHost) {
	let url;
	try {
		url = new URL(value);
	} catch (error) {
		throw new Error(`Invalid media URL: ${value}`, { cause: error });
	}
	if (!["http:", "https:"].includes(url.protocol)) throw new Error(`Media URL must use HTTP(S): ${url}`);
	if (url.username || url.password) throw new Error(`Media URL must not contain credentials: ${url}`);
	if (url.hostname.toLowerCase() !== allowedHost.toLowerCase()) throw new Error(`Untrusted media host: ${url.hostname}`);
	const expectedPort = url.protocol === "https:" ? "443" : "80";
	if (url.port && url.port !== expectedPort) throw new Error(`Media URL uses a forbidden port: ${url.port}`);
	const lowerHost = url.hostname.toLowerCase();
	if (lowerHost === "localhost" || lowerHost.endsWith(".localhost")) throw new Error(`Local media host is forbidden: ${lowerHost}`);
	if (net.isIP(lowerHost) && !isPublicIpAddress(lowerHost)) throw new Error(`Non-public media address is forbidden: ${lowerHost}`);
	url.hash = "";
	return url;
}

export async function resolvePublicAddresses(hostname, resolver = dnsLookup) {
	const literalFamily = net.isIP(hostname);
	const records = literalFamily
		? [{ address: hostname, family: literalFamily }]
		: await resolver(hostname, { all: true, verbatim: true });
	if (!Array.isArray(records) || records.length === 0) throw new Error(`Media host did not resolve: ${hostname}`);
	for (const record of records) {
		if (!record || !isPublicIpAddress(record.address)) throw new Error(`Media host resolved to a non-public address: ${record?.address ?? "unknown"}`);
	}
	return records.map((record) => ({ address: record.address, family: Number(record.family) }));
}

export function createPinnedLookup(addresses) {
	const pinned = addresses.map((record) => ({ address: record.address, family: record.family }));
	return (_hostname, options, callback) => {
		if (options?.all) {
			callback(null, pinned.map((record) => ({ ...record })));
			return;
		}
		callback(null, pinned[0].address, pinned[0].family);
	};
}

function normalizedMime(value) {
	const mime = String(value ?? "").split(";", 1)[0].trim().toLowerCase();
	return mime === "image/jpg" ? "image/jpeg" : mime;
}

function firstSrcsetUrl(value) {
	const firstCandidate = String(value ?? "").split(",", 1)[0].trim();
	return firstCandidate.split(/\s+/, 1)[0] || "";
}

async function readResponseBody(body, contentLength, limits, state) {
	if (Number.isFinite(contentLength) && contentLength > limits.maxFileBytes) throw new Error(`Media exceeds ${limits.maxFileBytes} bytes`);
	const chunks = [];
	let bytes = 0;
	for await (const chunk of body) {
		const buffer = Buffer.from(chunk);
		bytes += buffer.length;
		if (bytes > limits.maxFileBytes) throw new Error(`Media exceeds ${limits.maxFileBytes} bytes`);
		if (state.totalBytes + bytes > limits.maxTotalBytes) throw new Error(`Media batch exceeds ${limits.maxTotalBytes} bytes`);
		chunks.push(buffer);
	}
	if (bytes === 0) throw new Error("Media response was empty");
	state.totalBytes += bytes;
	return Buffer.concat(chunks, bytes);
}

export class MediaMirror {
	constructor({
		allowedHost,
		outputDir,
		resolver = dnsLookup,
		requestImpl = undiciRequest,
		dispatcherFactory,
		limits = SYNC_LIMITS,
		sleep,
	} = {}) {
		if (!allowedHost || !outputDir) throw new Error("MediaMirror requires allowedHost and outputDir");
		this.allowedHost = allowedHost;
		this.outputDir = outputDir;
		this.resolver = resolver;
		this.requestImpl = requestImpl;
		this.dispatcherFactory = dispatcherFactory;
		this.limits = limits;
		this.sleep = sleep;
		this.bySource = new Map();
		this.byHash = new Map();
		this.records = [];
		this.state = { totalBytes: 0, requestedFiles: 0 };
	}

	async #request(url, accept) {
		let current = validateMediaUrl(url, this.allowedHost);
		for (let redirects = 0; ; redirects += 1) {
			if (redirects > this.limits.maxRedirects) throw new Error(`Media exceeded ${this.limits.maxRedirects} redirects`);
			const addresses = await resolvePublicAddresses(current.hostname, this.resolver);
			const { response, dispatcher } = await withNetworkRetries(
				async () => {
					let requestDispatcher;
					try {
						requestDispatcher = this.dispatcherFactory
							? await this.dispatcherFactory({ url: current, addresses, limits: this.limits })
							: new Agent({
									connect: {
										lookup: createPinnedLookup(addresses),
										timeout: this.limits.connectTimeoutMs,
										servername: current.hostname,
									},
								});
						const requestResponse = await this.requestImpl(current, {
							dispatcher: requestDispatcher,
							method: "GET",
							headers: buildSyncHeaders({ Accept: accept }),
							headersTimeout: this.limits.headersTimeoutMs,
							bodyTimeout: this.limits.bodyTimeoutMs,
							maxRedirections: 0,
						});
						return { response: requestResponse, dispatcher: requestDispatcher };
					} catch (error) {
						if (typeof requestDispatcher?.close === "function") await requestDispatcher.close();
						throw error;
					}
				},
				{
					maxRetries: this.limits.maxRetries,
					retryDelayMs: this.limits.retryDelayMs,
					sleep: this.sleep,
				},
			);
			try {
				if (REDIRECT_STATUSES.has(response.statusCode)) {
					await response.body.dump();
					if (typeof dispatcher.close === "function") await dispatcher.close();
					const location = response.headers.location;
					if (!location) throw new Error(`Media redirect ${response.statusCode} has no Location header`);
					const next = validateMediaUrl(new URL(location, current).href, this.allowedHost);
					if (next.hostname.toLowerCase() !== current.hostname.toLowerCase()) throw new Error("Cross-host media redirects are forbidden");
					current = next;
					continue;
				}
				if (response.statusCode < 200 || response.statusCode >= 300) {
					await response.body.dump();
					if (typeof dispatcher.close === "function") await dispatcher.close();
					throw new Error(`Media request failed with HTTP ${response.statusCode}`);
				}
				return { response, finalUrl: current.href, dispatcher };
			} catch (error) {
				if (typeof dispatcher.close === "function") await dispatcher.close();
				throw error;
			}
		}
	}

	async mirror(sourceUrl, { manifest = null, alt = "" } = {}) {
		const normalized = validateMediaUrl(sourceUrl, this.allowedHost).href;
		if (this.bySource.has(normalized)) return this.bySource.get(normalized);
		this.state.requestedFiles += 1;
		if (this.state.requestedFiles > this.limits.maxFiles) throw new Error(`Media batch exceeds ${this.limits.maxFiles} files`);

		const manifestMime = normalizedMime(manifest?.mimeType);
		const accept = MIME_EXTENSION.has(manifestMime)
			? manifestMime
			: ALLOWED_IMAGE_MIME_TYPES.join(", ");
		const { response, dispatcher } = await this.#request(normalized, accept);
		let buffer;
		try {
			const contentLength = Number.parseInt(String(response.headers["content-length"] ?? ""), 10);
			buffer = await readResponseBody(response.body, contentLength, this.limits, this.state);
		} finally {
			if (typeof dispatcher.close === "function") await dispatcher.close();
		}
		const detected = await fileTypeFromBuffer(buffer);
		if (!detected || !MIME_EXTENSION.has(detected.mime)) throw new Error(`Unsupported media content: ${normalized}`);
		const responseMime = normalizedMime(response.headers["content-type"]);
		if (responseMime !== detected.mime) throw new Error(`Media Content-Type ${responseMime || "missing"} does not match ${detected.mime}`);
		if (manifest && normalizedMime(manifest.mimeType) !== detected.mime) throw new Error(`Snapshot MIME ${manifest.mimeType} does not match ${detected.mime}`);

		const metadata = await sharp(buffer, { animated: true }).metadata();
		if (!metadata.width || !metadata.height) throw new Error(`Media has no valid dimensions: ${normalized}`);
		const sha256 = createHash("sha256").update(buffer).digest("hex");
		const extension = MIME_EXTENSION.get(detected.mime);
		const fileName = `${sha256}.${extension}`;
		const localUrl = `/generated/wordpress-media/${fileName}`;
		if (!this.byHash.has(sha256)) {
			await writeFile(path.join(this.outputDir, fileName), buffer);
			this.byHash.set(sha256, localUrl);
		}
		const record = {
			wordpressId: manifest?.id ?? null,
			sourceUrl: normalized,
			url: localUrl,
			alt: String(manifest?.alt ?? alt ?? ""),
			mimeType: detected.mime,
			width: metadata.width,
			height: metadata.height,
			sha256,
		};
		this.records.push(record);
		this.bySource.set(normalized, record);
		return record;
	}

	getRecords() {
		return this.records.map((record) => ({ ...record }));
	}
}

export async function rewriteContentHtml(html, { baseUrl, manifestByUrl, mirror }) {
	const root = parse(String(html ?? ""), {
		comment: true,
		lowerCaseTagName: false,
	});
	for (const image of root.querySelectorAll("img")) {
		const source = image.getAttribute("data-src") || image.getAttribute("src") || firstSrcsetUrl(image.getAttribute("srcset"));
		image.removeAttribute("data-src");
		image.removeAttribute("srcset");
		image.removeAttribute("sizes");
		if (!source) continue;
		const absolute = new URL(source, baseUrl).href;
		const manifest = manifestByUrl.get(absolute) ?? null;
		const mirrored = await mirror(absolute, {
			manifest,
			alt: image.getAttribute("alt") || "",
		});
		image.setAttribute("src", mirrored.url);
		if (!image.getAttribute("alt") && mirrored.alt) image.setAttribute("alt", mirrored.alt);
	}
	for (const source of root.querySelectorAll("source")) source.removeAttribute("srcset");
	return root.toString();
}

export async function rewriteStructuredMedia(snapshot, mirror, baseUrl) {
	const manifestByUrl = new Map(snapshot.mediaManifest.map((media) => [new URL(media.url).href, media]));
	const projects = [];
	for (const project of snapshot.projects) {
		let image = "";
		let imageMedia = null;
		if (project.image) {
			const manifest = project.imageMedia ?? manifestByUrl.get(new URL(project.image).href) ?? null;
			const mirrored = await mirror.mirror(project.image, { manifest, alt: project.title });
			image = mirrored.url;
			if (project.imageMedia) imageMedia = { ...project.imageMedia, url: mirrored.url };
		}
		projects.push({
			...project,
			image,
			imageMedia,
			contentHtml: await rewriteContentHtml(project.contentHtml, {
				baseUrl,
				manifestByUrl,
				mirror: (url, options) => mirror.mirror(url, options),
			}),
		});
	}
	const timeline = [];
	for (const item of snapshot.timeline) {
		timeline.push({
			...item,
			contentHtml: await rewriteContentHtml(item.contentHtml, {
				baseUrl,
				manifestByUrl,
				mirror: (url, options) => mirror.mirror(url, options),
			}),
		});
	}
	const friends = [];
	for (const friend of snapshot.friends) {
		let imgurl = "";
		let avatarMedia = null;
		if (friend.imgurl) {
			const manifest = friend.avatarMedia ?? manifestByUrl.get(new URL(friend.imgurl).href) ?? null;
			const mirrored = await mirror.mirror(friend.imgurl, { manifest, alt: friend.title });
			imgurl = mirrored.url;
			if (friend.avatarMedia) avatarMedia = { ...friend.avatarMedia, url: mirrored.url };
		}
		friends.push({ ...friend, imgurl, avatarMedia });
	}
	const techRadar = [];
	for (const item of snapshot.techRadar) {
		let image = "";
		let imageMedia = null;
		if (item.image) {
			const manifest = item.imageMedia ?? manifestByUrl.get(new URL(item.image).href) ?? null;
			const mirrored = await mirror.mirror(item.image, { manifest, alt: item.title });
			image = mirrored.url;
			if (item.imageMedia) imageMedia = { ...item.imageMedia, url: mirrored.url };
		}
		techRadar.push({
			...item,
			image,
			imageMedia,
			contentHtml: await rewriteContentHtml(item.contentHtml, { baseUrl, manifestByUrl, mirror: (url, options) => mirror.mirror(url, options) }),
		});
	}
	const learningResources = [];
	for (const item of snapshot.learningResources) {
		let cover = "";
		let coverMedia = null;
		if (item.cover) {
			const manifest = item.coverMedia ?? manifestByUrl.get(new URL(item.cover).href) ?? null;
			const mirrored = await mirror.mirror(item.cover, { manifest, alt: item.title });
			cover = mirrored.url;
			if (item.coverMedia) coverMedia = { ...item.coverMedia, url: mirrored.url };
		}
		learningResources.push({
			...item,
			cover,
			coverMedia,
			contentHtml: await rewriteContentHtml(item.contentHtml, { baseUrl, manifestByUrl, mirror: (url, options) => mirror.mirror(url, options) }),
		});
	}
	const diary = [];
	for (const item of snapshot.diary) {
		const mirrorRef = async (ref) => {
			if (!ref) return null;
			const manifest = manifestByUrl.get(new URL(ref.src, baseUrl).href) ?? null;
			const mirrored = await mirror.mirror(new URL(ref.src, baseUrl).href, { manifest, alt: ref.alt });
			return {
				mediaId: ref.mediaId,
				src: mirrored.url,
				alt: ref.alt || mirrored.alt,
				width: mirrored.width,
				height: mirrored.height,
			};
		};
		diary.push({
			...item,
			images: await Promise.all(item.images.map((ref) => mirrorRef(ref))),
			coverImage: await mirrorRef(item.coverImage),
			contentHtml: await rewriteContentHtml(item.contentHtml, { baseUrl, manifestByUrl, mirror: (url, options) => mirror.mirror(url, options) }),
		});
	}
	const albums = [];
	for (const item of snapshot.albums) {
		const mirrorImage = async (image) => {
			if (!image) return null;
			const sourceUrl = new URL(image.url, baseUrl).href;
			const manifest = manifestByUrl.get(sourceUrl) ?? null;
			const mirrored = await mirror.mirror(sourceUrl, { manifest, alt: image.alt });
			return { ...image, url: mirrored.url, alt: image.alt || mirrored.alt, width: mirrored.width, height: mirrored.height };
		};
		albums.push({
			...item,
			images: await Promise.all(item.images.map(mirrorImage)),
			coverImage: await mirrorImage(item.coverImage),
			contentHtml: await rewriteContentHtml(item.contentHtml, { baseUrl, manifestByUrl, mirror: (url, options) => mirror.mirror(url, options) }),
		});
	}
	return {
		projects,
		skills: snapshot.skills,
		aiTools: snapshot.aiTools,
		timeline,
		friends,
		announcements: snapshot.announcements,
		techRadar,
		learningResources,
		diary,
		albums,
	};
}
