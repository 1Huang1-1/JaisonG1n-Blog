export interface Photo {
	id: string | number;
	src: string;
	alt?: string;
	title?: string;
	thumbnail?: string;
	tags?: string[];
	description?: string;
	date?: string;
	location?: string;
	width?: number;
	height?: number;
	caption?: string;
	order?: number;
}

export interface AlbumGroup {
	id: string;
	title: string;
	description?: string;
	cover: string;
	date: string;
	location?: string;
	tags?: string[];
	photos: Photo[];
	password?: string;
	passwordHint?: string;
	contentHtml?: string;
	publishedAt?: string;
	updatedAt?: string;
	featured?: boolean;
}
