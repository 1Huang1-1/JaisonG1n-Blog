import { readFileSync } from "node:fs";
import path from "node:path";
import type { GeneratedBundle } from "../../scripts/wordpress-sync/contracts.mjs";
import { loadStructuredContentSource } from "../../scripts/wordpress-sync/gateway.mjs";
import { announcementConfig as legacyAnnouncement } from "../config/announcementConfig";
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
		},
		generatedDir: options?.generatedDir ?? defaultGeneratedDir,
		readText:
			options?.readText ?? ((filePath) => readFileSync(filePath, "utf8")),
	}) as StructuredContent;
	if (!options) cached = content;
	return content;
}
