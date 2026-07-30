import type { GeneratedBundle } from "./contracts.mjs";

export function loadStructuredContentSource<TLegacy extends {
	projects: unknown[];
	skills: unknown[];
	aiTools: unknown[];
	timeline: unknown[];
	friends: unknown[];
	announcements: unknown[];
}>(options: {
	enabledValue: string | undefined;
	legacy: TLegacy;
	generatedDir: string;
	readText: (filePath: string) => string;
}):
	| ({ source: "legacy"; meta: null } & TLegacy)
	| ({ source: "wordpress" } & GeneratedBundle);
