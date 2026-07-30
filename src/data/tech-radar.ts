export interface TechRadarItem {
	id: string;
	title: string;
	description: string;
	contentHtml?: string;
	icon?: string;
	image?: string;
	imageMedia?: unknown | null;
	domain: string;
	stage: "adopt" | "trial" | "assess" | "hold";
	trend: "rising" | "stable" | "declining" | "uncertain";
	maturity: number;
	tags: string[];
	officialUrl: string;
	sourceUrls: Array<{ label: string; url: string }>;
	firstObservedAt: string;
	lastReviewedAt: string;
	relatedPost: null | { postId: number; title: string; slug: string; path: string };
	featured: boolean;
}

export const techRadarData: TechRadarItem[] = [];
