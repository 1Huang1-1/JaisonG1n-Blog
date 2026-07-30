import type { MarkdownHeading } from "astro";
import { parse } from "node-html-parser";

export type StructuredDetailCollection = "projects" | "timeline" | "diary";

export interface PreparedStructuredHtml {
	html: string;
	headings: MarkdownHeading[];
}

function decodeSlug(value: string): string {
	try {
		return decodeURIComponent(value);
	} catch {
		return value;
	}
}

export function getStructuredRouteSlug(
	value: unknown,
	context: string,
): string {
	if (typeof value !== "string" || value.trim() === "") {
		throw new Error(`${context} has an empty slug`);
	}
	const slug = decodeSlug(value.trim());
	if (
		slug === "" ||
		slug.includes("/") ||
		slug.includes("\\") ||
		slug.includes("\0")
	) {
		throw new Error(`${context} has an invalid slug`);
	}
	return slug;
}

export function getStructuredDetailHref(
	collection: StructuredDetailCollection,
	id: unknown,
): string {
	const slug = getStructuredRouteSlug(id, `${collection} item`);
	return `/${collection}/${encodeURIComponent(slug)}/`;
}

export function assertUniqueStructuredSlugs<T extends { id: string }>(
	items: T[],
	collection: StructuredDetailCollection,
): Map<string, T> {
	const bySlug = new Map<string, T>();
	for (const item of items) {
		const slug = getStructuredRouteSlug(item.id, `${collection} item`);
		if (bySlug.has(slug)) {
			throw new Error(`Duplicate ${collection} detail slug: ${slug}`);
		}
		bySlug.set(slug, item);
	}
	return bySlug;
}

function headingSlug(text: string, fallback: string): string {
	const normalized = text
		.toLocaleLowerCase()
		.trim()
		.replace(/[^\p{L}\p{N}]+/gu, "-")
		.replace(/^-+|-+$/g, "");
	return normalized || fallback;
}

export function prepareStructuredHtml(
	contentHtml: unknown,
): PreparedStructuredHtml {
	const root = parse(typeof contentHtml === "string" ? contentHtml : "", {
		comment: true,
		lowerCaseTagName: false,
	});
	const usedIds = new Set<string>();
	const headings: MarkdownHeading[] = [];

	for (const [index, heading] of root
		.querySelectorAll("h1,h2,h3,h4,h5,h6")
		.entries()) {
		const depth = Number(heading.tagName.slice(1));
		const text = heading.textContent.trim().replace(/\s+/g, " ");
		let id =
			heading.getAttribute("id")?.trim() ||
			headingSlug(text, `section-${index + 1}`);
		const baseId = id;
		let suffix = 2;
		while (usedIds.has(id)) id = `${baseId}-${suffix++}`;
		usedIds.add(id);
		heading.setAttribute("id", id);
		headings.push({ depth, slug: id, text });
	}

	for (const image of root.querySelectorAll("img")) {
		if (!image.getAttribute("data-fancybox")) {
			image.setAttribute("data-fancybox", "structured-content");
		}
		if (!image.getAttribute("loading")) image.setAttribute("loading", "lazy");
	}

	return { html: root.toString(), headings };
}
