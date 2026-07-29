export interface Project {
	id: string;
	title: string;
	description: string;
	image?: string;
	category: string;
	techStack: string[];
	status: "completed" | "in-progress" | "planned";
	demoUrl?: string;
	sourceUrl?: string;
	liveDemo?: string;
	sourceCode?: string;
	visitUrl?: string;
	startDate?: string;
	endDate?: string;
	featured?: boolean;
	tags?: string[];
	showImage?: boolean;
	contentHtml?: string;
	imageMedia?: {
		id: number;
		url: string;
		alt: string;
		mimeType:
			| "image/jpeg"
			| "image/png"
			| "image/webp"
			| "image/gif"
			| "image/avif";
		width: number;
		height: number;
	} | null;
}

export interface ProjectCardProps {
	project: Project;
	size?: "small" | "medium" | "large";
	showImage?: boolean;
	maxTechStack?: number;
}
