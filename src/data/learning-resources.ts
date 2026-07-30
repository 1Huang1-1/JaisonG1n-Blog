export interface LearningResource {
	id: string;
	title: string;
	description: string;
	contentHtml?: string;
	icon?: string;
	cover?: string;
	coverMedia?: unknown | null;
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
	relatedPost: null | { postId: number; title: string; slug: string; path: string };
	featured: boolean;
}

export const learningResourcesData: LearningResource[] = [];
