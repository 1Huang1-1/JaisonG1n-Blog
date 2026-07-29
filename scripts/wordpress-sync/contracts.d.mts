import type { z } from "zod";

export const ALLOWED_IMAGE_MIME_TYPES: readonly [
	"image/jpeg",
	"image/png",
	"image/webp",
	"image/gif",
	"image/avif",
];

export const SYNC_LIMITS: Readonly<{
	maxFileBytes: number;
	maxFiles: number;
	maxTotalBytes: number;
	maxRedirects: number;
	connectTimeoutMs: number;
	headersTimeoutMs: number;
	bodyTimeoutMs: number;
	requestTimeoutMs: number;
	maxSnapshotBytes: number;
}>;

export const mediaObjectSchema: z.ZodTypeAny;
export const projectSchema: z.ZodTypeAny;
export const skillSchema: z.ZodTypeAny;
export const aiToolSchema: z.ZodTypeAny;
export const timelineSchema: z.ZodTypeAny;
export const projectsSchema: z.ZodTypeAny;
export const skillsSchema: z.ZodTypeAny;
export const aiToolsSchema: z.ZodTypeAny;
export const timelineItemsSchema: z.ZodTypeAny;
export const siteSnapshotSchema: z.ZodTypeAny;
export const generatedMediaObjectSchema: z.ZodTypeAny;
export const generatedProjectSchema: z.ZodTypeAny;
export const mirroredMediaSchema: z.ZodTypeAny;
export const snapshotMetaSchema: z.ZodTypeAny;
export const generatedProjectsSchema: z.ZodTypeAny;

export interface GeneratedMediaObject {
	id: number;
	url: string;
	alt: string;
	mimeType: "image/jpeg" | "image/png" | "image/webp" | "image/gif" | "image/avif";
	width: number;
	height: number;
}

export interface GeneratedProject {
	id: string;
	title: string;
	description: string;
	contentHtml: string;
	image: string;
	imageMedia: GeneratedMediaObject | null;
	category: "web" | "mobile" | "desktop" | "other";
	techStack: string[];
	status: "completed" | "in-progress" | "planned";
	sourceCode: string;
	visitUrl: string;
	featured: boolean;
	showImage: boolean;
}

export interface GeneratedSkill {
	id: string;
	name: string;
	description: string;
	icon: string;
	category: "frontend" | "backend" | "database" | "tools" | "other";
	level: "beginner" | "intermediate" | "advanced" | "expert";
	experience: { years: number; months: number };
	color: string;
}

export interface GeneratedAITool {
	id: string;
	name: string;
	description: { zh_CN: string };
	icon: string;
	category: "chat" | "coding" | "image" | "audio" | "video" | "writing" | "search" | "other";
	frequency: "daily" | "weekly" | "occasional" | "experimental";
	url: string;
	usage: { zh_CN: string };
	tags: string[];
	color: string;
}

export interface GeneratedTimelineItem {
	id: string;
	title: string;
	description: string;
	contentHtml: string;
	type: "education" | "work" | "project" | "achievement";
	startDate: string;
	endDate: string;
	location: string;
	organization: string;
	position: string;
	skills: string[];
	achievements: string[];
	links: Array<{ name: string; url: string; type: "website" | "certificate" | "project" | "other" }>;
	icon: string;
	color: string;
	featured: boolean;
}

export interface SnapshotMeta {
	schemaVersion: 2;
	revision: string;
	generatedAt: string;
	syncedAt: string;
	sourceUrl: string;
	counts: { posts: number; projects: number; skills: number; aiTools: number; timeline: number; media: number };
	media: unknown[];
}

export interface GeneratedBundle {
	meta: SnapshotMeta;
	projects: GeneratedProject[];
	skills: GeneratedSkill[];
	aiTools: GeneratedAITool[];
	timeline: GeneratedTimelineItem[];
}

export function parseStructuredContentFlag(value: string | undefined): boolean;
export function parseSiteSnapshot(value: unknown): Record<string, unknown> & {
	schemaVersion: 2;
	revision: string;
	generatedAt: string;
	projects: GeneratedProject[];
	skills: GeneratedSkill[];
	aiTools: GeneratedAITool[];
	timeline: GeneratedTimelineItem[];
	mediaManifest: Array<{
		id: number;
		url: string;
		alt: string;
		mimeType: GeneratedMediaObject["mimeType"];
		width: number;
		height: number;
	}>;
};
export function parseGeneratedBundle(value: unknown): GeneratedBundle;
