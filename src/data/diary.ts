export interface DiaryImage {
	/** Legacy local data uses url; generated WordPress data uses src. */
	url?: string;
	src?: string;
	alt: string;
	width: number;
	height: number;
}

export interface DiaryItem {
	id: string;
	title: string;
	description: string;
	contentHtml: string;
	date: string;
	publishedAt: string;
	updatedAt: string;
	location: string;
	mood:
		| ""
		| "happy"
		| "calm"
		| "fulfilled"
		| "excited"
		| "thinking"
		| "tired"
		| "anxious"
		| "sad"
		| "other";
	tags: string[];
	images: DiaryImage[];
	coverImage: DiaryImage | null;
	featured: boolean;
}

const diaryData: DiaryItem[] = [
	{
		id: "legacy-diary-2025-01-15",
		title: "",
		description:
			"The falling speed of cherry blossoms is five centimeters per second!",
		contentHtml:
			"<p>The falling speed of cherry blossoms is five centimeters per second!</p>",
		date: "2025-01-15",
		publishedAt: "2025-01-15T10:30:00.000Z",
		updatedAt: "2025-01-15T10:30:00.000Z",
		location: "",
		mood: "",
		tags: [],
		images: [
			{ url: "/images/diary/sakura.jpg", alt: "", width: 1200, height: 800 },
			{ url: "/images/diary/1.webp", alt: "", width: 1200, height: 800 },
		],
		coverImage: {
			url: "/images/diary/sakura.jpg",
			alt: "",
			width: 1200,
			height: 800,
		},
		featured: false,
	},
];

export function sortDiary(items: DiaryItem[]): DiaryItem[] {
	return [...items].sort((a, b) => {
		const dateOrder = b.date.localeCompare(a.date);
		if (dateOrder !== 0) return dateOrder;
		const publishedOrder = b.publishedAt.localeCompare(a.publishedAt);
		if (publishedOrder !== 0) return publishedOrder;
		return a.id.localeCompare(b.id);
	});
}

export const getDiaryList = (limit?: number): DiaryItem[] => {
	const sorted = sortDiary(diaryData);
	return limit && limit > 0 ? sorted.slice(0, limit) : sorted;
};

export const getAllTags = (): string[] => {
	const tags = new Set<string>();
	for (const item of diaryData) for (const tag of item.tags) tags.add(tag);
	return Array.from(tags).sort();
};
