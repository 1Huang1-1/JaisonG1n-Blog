export const radarIcons: Record<string, string> = {
	ai: "simple-icons:openai",
	frontend: "material-symbols:web",
	backend: "material-symbols:dns",
	data: "material-symbols:database",
	infrastructure: "material-symbols:cloud",
	security: "material-symbols:security",
	hardware: "material-symbols:memory",
	"developer-tools": "material-symbols:build",
	other: "material-symbols:hub",
};

export const learningIcons: Record<string, string> = {
	book: "material-symbols:menu-book",
	course: "material-symbols:school",
	paper: "material-symbols:article",
	docs: "material-symbols:description",
	tutorial: "material-symbols:integration-instructions",
	video: "material-symbols:play-circle",
	other: "material-symbols:bookmark",
};

// Only icon collections bundled into the static build can be rendered by astro-icon.
// WordPress may contain a syntactically valid Iconify name from another collection;
// those values must use the local semantic fallback instead of breaking SSR builds.
const installedIconCollections = new Set([
	"material-symbols",
	"mdi",
	"fa7-solid",
	"fa7-regular",
	"fa7-brands",
	"simple-icons",
]);

const iconNamePattern = /^[a-z0-9-]+:[a-z0-9-]+$/i;

export function safeResourceIcon(icon: unknown, fallback: string): string {
	if (typeof icon !== "string") return fallback;

	const value = icon.trim();
	if (!iconNamePattern.test(value)) return fallback;

	const collection = value.slice(0, value.indexOf(":")).toLowerCase();
	return installedIconCollections.has(collection) ? value : fallback;
}
