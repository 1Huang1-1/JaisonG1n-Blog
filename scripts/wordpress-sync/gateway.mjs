import path from "node:path";
import { parseGeneratedBundle, parseStructuredContentFlag } from "./contracts.mjs";

export function loadStructuredContentSource({
	enabledValue,
	legacy,
	generatedDir,
	readText,
}) {
	if (!parseStructuredContentFlag(enabledValue)) {
		return { source: "legacy", meta: null, ...legacy };
	}
	const readJson = (name) => {
		const filePath = path.join(generatedDir, name);
		try {
			return JSON.parse(readText(filePath));
		} catch (error) {
			throw new Error(`Unable to read generated WordPress data: ${filePath}`, { cause: error });
		}
	};
	const bundle = parseGeneratedBundle({
		meta: readJson("snapshot-meta.json"),
		projects: readJson("projects.json"),
		skills: readJson("skills.json"),
		aiTools: readJson("ai-tools.json"),
		timeline: readJson("timeline.json"),
		friends: readJson("friends.json"),
		announcements: readJson("announcements.json"),
		techRadar: readJson("tech-radar.json"),
		learningResources: readJson("learning-resources.json"),
	});
	return { source: "wordpress", ...bundle };
}
