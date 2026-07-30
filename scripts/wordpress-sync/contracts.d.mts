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
export const techRadarSchema: z.ZodTypeAny;
export const learningResourceSchema: z.ZodTypeAny;
export const friendSchema: z.ZodTypeAny;
export const announcementSchema: z.ZodTypeAny;
export const projectsSchema: z.ZodTypeAny;
export const skillsSchema: z.ZodTypeAny;
export const aiToolsSchema: z.ZodTypeAny;
export const timelineItemsSchema: z.ZodTypeAny;
export const techRadarItemsSchema: z.ZodTypeAny;
export const learningResourceItemsSchema: z.ZodTypeAny;
export const friendsSchema: z.ZodTypeAny;
export const announcementsSchema: z.ZodTypeAny;
export const siteSnapshotSchema: z.ZodTypeAny;
export const generatedMediaObjectSchema: z.ZodTypeAny;
export const generatedProjectSchema: z.ZodTypeAny;
export const generatedFriendSchema: z.ZodTypeAny;
export const generatedFriendsSchema: z.ZodTypeAny;
export const generatedTechRadarSchema: z.ZodTypeAny;
export const generatedLearningResourceSchema: z.ZodTypeAny;
export const generatedTechRadarItemsSchema: z.ZodTypeAny;
export const generatedLearningResourceItemsSchema: z.ZodTypeAny;
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

export interface GeneratedFriend {
	title: string;
	icon: string;
	imgurl: string;
	avatarMedia: GeneratedMediaObject | null;
	desc: string;
	siteurl: string;
	tags: string[];
}

export interface GeneratedAnnouncement {
	title: string;
	content: string;
	closable: boolean;
	link: { enable: boolean; text: string; url: string; external: boolean };
}

export interface GeneratedRelatedPost {
	postId: number;
	title: string;
	slug: string;
	path: string;
}

export interface GeneratedTechRadar {
	id: string;
	title: string;
	description: string;
	contentHtml: string;
	icon: string;
	image: string;
	imageMedia: GeneratedMediaObject | null;
	domain: "ai" | "frontend" | "backend" | "data" | "infrastructure" | "security" | "hardware" | "developer-tools" | "other";
	stage: "adopt" | "trial" | "assess" | "hold";
	trend: "rising" | "stable" | "declining" | "uncertain";
	maturity: number;
	tags: string[];
	officialUrl: string;
	sourceUrls: Array<{ label: string; url: string }>;
	firstObservedAt: string;
	lastReviewedAt: string;
	relatedPost: GeneratedRelatedPost | null;
	featured: boolean;
}

export interface GeneratedLearningResource {
	id: string;
	title: string;
	description: string;
	contentHtml: string;
	icon: string;
	cover: string;
	coverMedia: GeneratedMediaObject | null;
	type: "book" | "course" | "paper" | "docs" | "tutorial" | "video" | "other";
	status: "planned" | "learning" | "completed" | "paused";
	author: string;
	publishedYear: "" | number;
	rating: number;
	progress: number;
	totalUnits: number;
	sourceUrl: string;
	tags: string[];
	startedAt: string;
	completedAt: string;
	relatedPost: GeneratedRelatedPost | null;
	featured: boolean;
}

export interface SnapshotMeta {
	schemaVersion: 3;
	revision: string;
	generatedAt: string;
	syncedAt: string;
	sourceUrl: string;
	counts: { posts: number; projects: number; skills: number; aiTools: number; timeline: number; friends: number; announcements: number; techRadar: number; learningResources: number; media: number };
	media: unknown[];
}

export interface GeneratedBundle {
	meta: SnapshotMeta;
	projects: GeneratedProject[];
	skills: GeneratedSkill[];
	aiTools: GeneratedAITool[];
	timeline: GeneratedTimelineItem[];
	friends: GeneratedFriend[];
	announcements: GeneratedAnnouncement[];
	techRadar: GeneratedTechRadar[];
	learningResources: GeneratedLearningResource[];
}

export function parseStructuredContentFlag(value: string | undefined): boolean;
export function parseSiteSnapshot(value: unknown): Record<string, unknown> & {
	schemaVersion: 3;
	revision: string;
	generatedAt: string;
	projects: GeneratedProject[];
	skills: GeneratedSkill[];
	aiTools: GeneratedAITool[];
	timeline: GeneratedTimelineItem[];
	friends: GeneratedFriend[];
	announcements: GeneratedAnnouncement[];
	techRadar: GeneratedTechRadar[];
	learningResources: GeneratedLearningResource[];
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
