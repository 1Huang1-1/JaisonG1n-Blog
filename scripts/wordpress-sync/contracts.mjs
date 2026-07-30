import { z } from "zod";

export const ALLOWED_IMAGE_MIME_TYPES = [
	"image/jpeg",
	"image/png",
	"image/webp",
	"image/gif",
	"image/avif",
];

export const SYNC_LIMITS = Object.freeze({
	maxFileBytes: 15 * 1024 * 1024,
	maxFiles: 1_000,
	maxTotalBytes: 250 * 1024 * 1024,
	maxRedirects: 3,
	connectTimeoutMs: 10_000,
	headersTimeoutMs: 15_000,
	bodyTimeoutMs: 30_000,
	requestTimeoutMs: 30_000,
	maxSnapshotBytes: 2 * 1024 * 1024,
});

const boundedText = (max = 10_000) => z.string().max(max);
const requiredText = (max = 300) => boundedText(max).min(1);
const slug = requiredText(200).regex(/^[^\\/\0]+$/u);
const color = z.union([
	z.literal(""),
	z.string().regex(/^#[0-9a-f]{3}(?:[0-9a-f]{3})?$/i),
]);

function isHttpUrl(value) {
	if (value === "") return true;
	try {
		return ["http:", "https:"].includes(new URL(value).protocol);
	} catch {
		return false;
	}
}

function isSafeSitePath(value) {
	if (value === "") return true;
	if (typeof value !== "string" || /[\\\r\n]/u.test(value)) return false;
	try {
		const decoded = decodeURIComponent(value);
		if (/^[a-z][a-z0-9+.-]*:/iu.test(decoded) || decoded.startsWith("//")) return false;
		const url = new URL(value, "https://jaisong1n.invalid");
		return url.origin === "https://jaisong1n.invalid" && url.pathname.startsWith("/");
	} catch {
		return false;
	}
}

function isIsoDate(value) {
	if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return false;
	const parsed = new Date(`${value}T00:00:00.000Z`);
	return !Number.isNaN(parsed.getTime()) && parsed.toISOString().startsWith(value);
}

function isIsoDateTime(value) {
	return /^\d{4}-\d{2}-\d{2}T/.test(value) && !Number.isNaN(Date.parse(value));
}

const optionalHttpUrl = boundedText(2_048).refine(isHttpUrl, "Expected an HTTP(S) URL or an empty string");
const isoDate = boundedText(10).refine(isIsoDate, "Expected a valid YYYY-MM-DD date");
const optionalIsoDate = z.union([z.literal(""), isoDate]);
const stringList = z.array(boundedText(300)).max(500);
const localeZhCn = z.object({ zh_CN: boundedText(20_000) }).strict();
const relatedPostRawSchema = z.object({ postId: z.number().int().positive() }).strict();
const relatedPostSchema = z.object({
	postId: z.number().int().positive(),
	title: requiredText(500),
	slug,
	path: z.string().regex(/^\/[^\\\0]+\/$/u),
}).strict();

export const mediaObjectSchema = z
	.object({
		id: z.number().int().positive(),
		url: boundedText(2_048).refine(isHttpUrl, "Expected an HTTP(S) media URL"),
		alt: boundedText(1_000),
		mimeType: z.enum(ALLOWED_IMAGE_MIME_TYPES),
		width: z.number().int().positive(),
		height: z.number().int().positive(),
	})
	.strict();

export const projectSchema = z
	.object({
		id: slug,
		title: requiredText(500),
		description: boundedText(20_000),
		contentHtml: boundedText(250_000),
		image: optionalHttpUrl,
		imageMedia: mediaObjectSchema.nullable(),
		category: z.enum(["web", "mobile", "desktop", "other"]),
		techStack: stringList,
		status: z.enum(["completed", "in-progress", "planned"]),
		sourceCode: optionalHttpUrl,
		visitUrl: optionalHttpUrl,
		featured: z.boolean(),
		showImage: z.boolean(),
	})
	.strict();

export const skillSchema = z
	.object({
		id: slug,
		name: requiredText(500),
		description: boundedText(20_000),
		icon: boundedText(300).default(""),
		category: z.enum(["frontend", "backend", "database", "tools", "other"]),
		level: z.enum(["beginner", "intermediate", "advanced", "expert"]),
		experience: z
			.object({
				years: z.number().int().min(0).max(80),
				months: z.number().int().min(0).max(11),
			})
			.strict(),
		color,
	})
	.strict();

export const aiToolSchema = z
	.object({
		id: slug,
		name: requiredText(500),
		description: localeZhCn,
		icon: boundedText(300),
		category: z.enum(["chat", "coding", "image", "audio", "video", "writing", "search", "other"]),
		frequency: z.enum(["daily", "weekly", "occasional", "experimental"]),
		url: optionalHttpUrl,
		usage: localeZhCn,
		tags: stringList,
		color,
	})
	.strict();

const timelineLinkSchema = z
	.object({
		name: requiredText(300),
		url: boundedText(2_048).refine((value) => value !== "" && isHttpUrl(value), "Expected an HTTP(S) URL"),
		type: z.enum(["website", "certificate", "project", "other"]),
	})
	.strict();

export const timelineSchema = z
	.object({
		id: slug,
		title: requiredText(500),
		description: boundedText(20_000),
		contentHtml: boundedText(250_000),
		type: z.enum(["education", "work", "project", "achievement"]),
		startDate: isoDate,
		endDate: optionalIsoDate,
		location: boundedText(500),
		organization: boundedText(500),
		position: boundedText(500),
		skills: stringList,
		achievements: stringList,
		links: z.array(timelineLinkSchema).max(500),
		icon: boundedText(300),
		color,
		featured: z.boolean(),
	})
	.strict();

const sourceUrlSchema = z.object({
	label: requiredText(300),
	url: boundedText(2_048).refine((value) => value !== "" && isHttpUrl(value), "Expected an HTTP(S) URL"),
}).strict();
const iconifyName = boundedText(300).refine(
	(value) => value === "" || /^[a-z0-9-]+:[a-z0-9-]+$/i.test(value),
	"Expected a safe Iconify icon name",
);

export const techRadarSchema = z.object({
	id: slug,
	title: requiredText(500),
	description: boundedText(20_000),
	contentHtml: boundedText(250_000),
	icon: iconifyName,
	image: optionalHttpUrl,
	imageMedia: mediaObjectSchema.nullable(),
	domain: z.enum(["ai", "frontend", "backend", "data", "infrastructure", "security", "hardware", "developer-tools", "other"]),
	stage: z.enum(["adopt", "trial", "assess", "hold"]),
	trend: z.enum(["rising", "stable", "declining", "uncertain"]),
	maturity: z.number().int().min(0).max(100),
	tags: stringList,
	officialUrl: optionalHttpUrl,
	sourceUrls: z.array(sourceUrlSchema).max(100),
	firstObservedAt: optionalIsoDate,
	lastReviewedAt: optionalIsoDate,
	relatedPost: relatedPostRawSchema.nullable(),
	featured: z.boolean(),
}).strict().superRefine((value, ctx) => {
	if (value.firstObservedAt && value.lastReviewedAt && value.lastReviewedAt < value.firstObservedAt) {
		ctx.addIssue({ code: "custom", path: ["lastReviewedAt"], message: "lastReviewedAt cannot precede firstObservedAt" });
	}
});

export const learningResourceSchema = z.object({
	id: slug,
	title: requiredText(500),
	description: boundedText(20_000),
	contentHtml: boundedText(250_000),
	icon: iconifyName,
	cover: optionalHttpUrl,
	coverMedia: mediaObjectSchema.nullable(),
	type: z.enum(["book", "course", "paper", "docs", "tutorial", "video", "other"]),
	status: z.enum(["planned", "learning", "completed", "paused"]),
	author: boundedText(500),
	publishedYear: z.union([z.literal(""), z.number().int().min(1000).max(3000)]),
	rating: z.number().finite().min(0).max(10).refine((value) => Number.isInteger(value * 10), "Rating must have at most one decimal"),
	progress: z.number().int().min(0),
	totalUnits: z.number().int().min(0),
	sourceUrl: optionalHttpUrl,
	tags: stringList,
	startedAt: optionalIsoDate,
	completedAt: optionalIsoDate,
	relatedPost: relatedPostRawSchema.nullable(),
	featured: z.boolean(),
}).strict().superRefine((value, ctx) => {
	if (value.totalUnits > 0 && value.progress > value.totalUnits) {
		ctx.addIssue({ code: "custom", path: ["progress"], message: "progress cannot exceed totalUnits" });
	}
	if (value.startedAt && value.completedAt && value.completedAt < value.startedAt) {
		ctx.addIssue({ code: "custom", path: ["completedAt"], message: "completedAt cannot precede startedAt" });
	}
});

const uniqueIds = (items) => new Set(items.map((item) => item.id)).size === items.length;
const collection = (schema) => z.array(schema).max(500).refine(uniqueIds, "Collection IDs must be unique");

export const projectsSchema = collection(projectSchema);
export const skillsSchema = collection(skillSchema);
export const aiToolsSchema = collection(aiToolSchema);
export const timelineItemsSchema = collection(timelineSchema);
export const techRadarItemsSchema = collection(techRadarSchema);
export const learningResourceItemsSchema = collection(learningResourceSchema);

export const friendSchema = z
	.object({
		title: requiredText(500),
		icon: boundedText(300),
		imgurl: optionalHttpUrl,
		avatarMedia: mediaObjectSchema.nullable(),
		desc: boundedText(20_000),
		siteurl: boundedText(2_048).refine((value) => value !== "" && isHttpUrl(value), "Expected an HTTP(S) URL"),
		tags: stringList,
	})
	.strict();

const announcementLinkSchema = z
	.object({
		enable: z.boolean(),
		text: boundedText(500),
		url: boundedText(2_048).refine((value) => isHttpUrl(value) || isSafeSitePath(value), "Expected a safe HTTP(S) URL or root-relative site path"),
		external: z.boolean(),
	})
	.strict();

export const announcementSchema = z
	.object({
		title: boundedText(500),
		content: boundedText(20_000),
		closable: z.boolean(),
		link: announcementLinkSchema,
	})
	.strict();

export const friendsSchema = z.array(friendSchema).max(500);
export const announcementsSchema = z.array(announcementSchema).max(100);

export const siteSnapshotSchema = z
	.object({
		schemaVersion: z.literal(3),
		revision: z.string().regex(/^[a-f0-9]{64}$/i),
		generatedAt: boundedText(64).refine(isIsoDateTime, "Expected an ISO 8601 generatedAt value"),
		projects: projectsSchema,
		skills: skillsSchema,
		aiTools: aiToolsSchema,
		timeline: timelineItemsSchema,
		friends: friendsSchema,
		announcements: announcementsSchema,
		techRadar: techRadarItemsSchema,
		learningResources: learningResourceItemsSchema,
		mediaManifest: z.array(mediaObjectSchema).max(SYNC_LIMITS.maxFiles),
	})
	.passthrough()
	.superRefine((value, ctx) => {
		for (const deprecated of ["devices", "anime"]) {
			if (Object.prototype.hasOwnProperty.call(value, deprecated)) {
				ctx.addIssue({ code: "custom", path: [deprecated], message: `${deprecated} is not part of schemaVersion 3` });
			}
		}
	});

const localMediaPath = z.string().regex(/^\/generated\/wordpress-media\/[a-f0-9]{64}\.(?:jpg|png|webp|gif|avif)$/);
export const generatedMediaObjectSchema = mediaObjectSchema.extend({ url: localMediaPath }).strict();
export const generatedProjectSchema = projectSchema
	.extend({
		image: z.union([z.literal(""), localMediaPath]),
		imageMedia: generatedMediaObjectSchema.nullable(),
	})
	.strict();

export const generatedFriendSchema = friendSchema
	.extend({
		imgurl: z.union([z.literal(""), localMediaPath]),
		avatarMedia: generatedMediaObjectSchema.nullable(),
	})
	.strict();
export const generatedFriendsSchema = z.array(generatedFriendSchema).max(500);
export const generatedTechRadarSchema = techRadarSchema
	.safeExtend({
		image: z.union([z.literal(""), localMediaPath]),
		imageMedia: generatedMediaObjectSchema.nullable(),
		relatedPost: relatedPostSchema.nullable(),
	})
	.strict();
export const generatedLearningResourceSchema = learningResourceSchema
	.safeExtend({
		cover: z.union([z.literal(""), localMediaPath]),
		coverMedia: generatedMediaObjectSchema.nullable(),
		relatedPost: relatedPostSchema.nullable(),
	})
	.strict();
export const generatedTechRadarItemsSchema = z.array(generatedTechRadarSchema).max(500);
export const generatedLearningResourceItemsSchema = z.array(generatedLearningResourceSchema).max(500);

export const mirroredMediaSchema = z
	.object({
		wordpressId: z.number().int().positive().nullable(),
		sourceUrl: boundedText(2_048).refine((value) => value !== "" && isHttpUrl(value), "Expected an HTTP(S) source URL"),
		url: localMediaPath,
		alt: boundedText(1_000),
		mimeType: z.enum(ALLOWED_IMAGE_MIME_TYPES),
		width: z.number().int().positive(),
		height: z.number().int().positive(),
		sha256: z.string().regex(/^[a-f0-9]{64}$/),
	})
	.strict();

export const snapshotMetaSchema = z
	.object({
		schemaVersion: z.literal(3),
		revision: z.string().regex(/^[a-f0-9]{64}$/i),
		generatedAt: boundedText(64).refine(isIsoDateTime),
		syncedAt: boundedText(64).refine(isIsoDateTime),
		sourceUrl: boundedText(2_048).refine((value) => value !== "" && isHttpUrl(value)),
		counts: z
			.object({
				posts: z.number().int().min(0),
				projects: z.number().int().min(0),
				skills: z.number().int().min(0),
				aiTools: z.number().int().min(0),
				timeline: z.number().int().min(0),
				friends: z.number().int().min(0),
				announcements: z.number().int().min(0),
				techRadar: z.number().int().min(0),
				learningResources: z.number().int().min(0),
				media: z.number().int().min(0),
			})
			.strict(),
		media: z.array(mirroredMediaSchema).max(SYNC_LIMITS.maxFiles),
	})
	.strict();

export const generatedProjectsSchema = z.array(generatedProjectSchema).max(500);

export function parseStructuredContentFlag(value) {
	if (value === undefined || value === "") return false;
	if (value === "true") return true;
	if (value === "false") return false;
	throw new Error("WORDPRESS_STRUCTURED_CONTENT_ENABLED must be exactly 'true' or 'false'");
}

export function parseSiteSnapshot(value) {
	return siteSnapshotSchema.parse(value);
}

export function parseGeneratedBundle(value) {
	const bundle = {
		meta: snapshotMetaSchema.parse(value.meta),
		projects: generatedProjectsSchema.parse(value.projects),
		skills: skillsSchema.parse(value.skills),
		aiTools: aiToolsSchema.parse(value.aiTools),
		timeline: timelineItemsSchema.parse(value.timeline),
		friends: generatedFriendsSchema.parse(value.friends),
		announcements: announcementsSchema.parse(value.announcements),
		techRadar: generatedTechRadarItemsSchema.parse(value.techRadar),
		learningResources: generatedLearningResourceItemsSchema.parse(value.learningResources),
	};
	const { counts } = bundle.meta;
	for (const [name, items] of Object.entries({
		projects: bundle.projects,
		skills: bundle.skills,
		aiTools: bundle.aiTools,
		timeline: bundle.timeline,
		friends: bundle.friends,
		announcements: bundle.announcements,
		techRadar: bundle.techRadar,
		learningResources: bundle.learningResources,
	})) {
		if (counts[name] !== items.length) throw new Error(`Generated WordPress ${name} count does not match snapshot metadata`);
	}
	return bundle;
}
