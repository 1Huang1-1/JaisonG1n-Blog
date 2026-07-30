import { readFileSync } from "node:fs";
import path from "node:path";
import type { GeneratedBundle } from "../../scripts/wordpress-sync/contracts.mjs";
import { loadStructuredContentSource } from "../../scripts/wordpress-sync/gateway.mjs";
import { announcementConfig as legacyAnnouncement } from "../config/announcementConfig";
import type { AlbumGroup } from "../types/album";
import { scanAlbums } from "../utils/album-scanner";
import { getStructuredRouteSlug } from "../utils/structured-detail";
import { aiToolsData as legacyAiTools } from "./ai-tools";
import { getDiaryList as getLegacyDiaryList } from "./diary";
import { friendsData as legacyFriends } from "./friends";
import { learningResourcesData as legacyLearningResources } from "./learning-resources";
import { projectsData as legacyProjects } from "./projects";
import { skillsData as legacySkills } from "./skills";
import { techRadarData as legacyTechRadar } from "./tech-radar";
import { timelineData as legacyTimeline } from "./timeline";

export interface StructuredContent {
	source: "legacy" | "wordpress";
	meta: GeneratedBundle["meta"] | null;
	projects: GeneratedBundle["projects"] | typeof legacyProjects;
	skills: GeneratedBundle["skills"] | typeof legacySkills;
	aiTools: GeneratedBundle["aiTools"] | typeof legacyAiTools;
	timeline: GeneratedBundle["timeline"] | typeof legacyTimeline;
	friends: GeneratedBundle["friends"] | typeof legacyFriends;
	announcements:
		| GeneratedBundle["announcements"]
		| (typeof legacyAnnouncement)[];
	techRadar: GeneratedBundle["techRadar"] | typeof legacyTechRadar;
	learningResources:
		| GeneratedBundle["learningResources"]
		| typeof legacyLearningResources;
	diary: GeneratedBundle["diary"] | ReturnType<typeof getLegacyDiaryList>;
	albums: GeneratedBundle["albums"] | AlbumGroup[];
}

interface LoadOptions {
	enabledValue?: string;
	generatedDir?: string;
	readText?: (filePath: string) => string;
}

const defaultGeneratedDir = path.resolve(
	process.cwd(),
	"src/generated/wordpress",
);
let cached: StructuredContent | undefined;

export function loadStructuredContent(
	options?: LoadOptions,
): StructuredContent {
	if (!options && cached) return cached;
	const enabledValue =
		options?.enabledValue ??
		process.env.WORDPRESS_STRUCTURED_CONTENT_ENABLED ??
		"false";
	const content = loadStructuredContentSource({
		enabledValue,
		legacy: {
			projects: legacyProjects,
			skills: legacySkills,
			aiTools: legacyAiTools,
			timeline: legacyTimeline,
			friends: legacyFriends,
			announcements: legacyAnnouncement.content ? [legacyAnnouncement] : [],
			techRadar: legacyTechRadar,
			learningResources: legacyLearningResources,
			diary: getLegacyDiaryList(),
			albums: [],
		},
		generatedDir: options?.generatedDir ?? defaultGeneratedDir,
		readText:
			options?.readText ?? ((filePath) => readFileSync(filePath, "utf8")),
	}) as StructuredContent;
	if (!options) cached = content;
	return content;
}

export async function loadAlbums(): Promise<AlbumGroup[]> {
	const content = loadStructuredContent();
	if (content.source === "legacy") return scanAlbums();
	return (content.albums as GeneratedBundle["albums"]).map((album) => ({
		// WordPress may return an already percent-encoded post_name for CJK
		// slugs. Normalize it once here; route links encode it exactly once.
		id: getStructuredRouteSlug(album.id, "albums item"),
		title: album.title,
		description: album.description,
		cover: album.coverImage?.url ?? "",
		date: album.date,
		location: album.location,
		tags: album.tags,
		photos: album.images.map((image) => ({
			id: image.id,
			src: image.url,
			alt: image.alt,
			title: image.caption,
			caption: image.caption,
			width: image.width,
			height: image.height,
			order: image.order,
		})),
		contentHtml: album.contentHtml,
		publishedAt: album.publishedAt,
		updatedAt: album.updatedAt,
		featured: album.featured,
	}));
}
