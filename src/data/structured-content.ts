import { readFileSync } from "node:fs";
import path from "node:path";
import type { GeneratedBundle } from "../../scripts/wordpress-sync/contracts.mjs";
import { loadStructuredContentSource } from "../../scripts/wordpress-sync/gateway.mjs";
import { aiToolsData as legacyAiTools } from "./ai-tools";
import { projectsData as legacyProjects } from "./projects";
import { skillsData as legacySkills } from "./skills";
import { timelineData as legacyTimeline } from "./timeline";
import { friendsData as legacyFriends } from "./friends";
import { announcementConfig as legacyAnnouncement } from "../config/announcementConfig";
import { techRadarData as legacyTechRadar } from "./tech-radar";
import { learningResourcesData as legacyLearningResources } from "./learning-resources";

export interface StructuredContent {
	source: "legacy" | "wordpress";
	meta: GeneratedBundle["meta"] | null;
	projects: GeneratedBundle["projects"] | typeof legacyProjects;
	skills: GeneratedBundle["skills"] | typeof legacySkills;
	aiTools: GeneratedBundle["aiTools"] | typeof legacyAiTools;
	timeline: GeneratedBundle["timeline"] | typeof legacyTimeline;
	friends: GeneratedBundle["friends"] | typeof legacyFriends;
	announcements: GeneratedBundle["announcements"] | typeof legacyAnnouncement[];
	techRadar: GeneratedBundle["techRadar"] | typeof legacyTechRadar;
	learningResources: GeneratedBundle["learningResources"] | typeof legacyLearningResources;
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
		},
		generatedDir: options?.generatedDir ?? defaultGeneratedDir,
		readText:
			options?.readText ?? ((filePath) => readFileSync(filePath, "utf8")),
	}) as StructuredContent;
	if (!options) cached = content;
	return content;
}
