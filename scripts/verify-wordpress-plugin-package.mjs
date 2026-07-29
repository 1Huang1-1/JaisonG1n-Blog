import { createWriteStream } from "node:fs";
import {
	access,
	mkdir,
	mkdtemp,
	readFile,
	rm,
} from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import { pipeline } from "node:stream/promises";
import { createRequire } from "node:module";
import { fileURLToPath } from "node:url";

const require = createRequire(import.meta.url);
const yauzl = require("yauzl");
const PhpParser = require("php-parser");

const SCRIPT_DIR = path.dirname(fileURLToPath(import.meta.url));
const REPOSITORY_ROOT = path.resolve(SCRIPT_DIR, "..");
const PLUGIN_SLUG = "jaisong1n-site-manager";
const MAIN_FILE = `${PLUGIN_SLUG}/${PLUGIN_SLUG}.php`;
const FORBIDDEN_SEGMENTS = new Set([
	".git",
	"node_modules",
	"tests",
	"dist",
	".DS_Store",
	"Thumbs.db",
]);

function openZip(zipPath, options = {}) {
	return new Promise((resolve, reject) => {
		yauzl.open(zipPath, { lazyEntries: true, ...options }, (error, zipFile) => {
			if (error) reject(error);
			else resolve(zipFile);
		});
	});
}

async function readEntries(zipPath) {
	let zipFile;
	try {
		zipFile = await openZip(zipPath, { strictFileNames: true });
	} catch (error) {
		throw new Error(`Cannot open ZIP: ${error.message}`, { cause: error });
	}
	return new Promise((resolve, reject) => {
		const entries = [];
		zipFile.on("entry", (entry) => {
			entries.push({
				name: entry.fileName,
				isDirectory: entry.fileName.endsWith("/"),
				isSymlink:
					((entry.externalFileAttributes >>> 16) & 0o170000) === 0o120000,
			});
			zipFile.readEntry();
		});
		zipFile.on("end", () => {
			zipFile.close();
			resolve(entries);
		});
		zipFile.on("error", (error) => {
			zipFile.close();
			reject(
				new Error(`Invalid ZIP entry name: ${error.message}`, { cause: error }),
			);
		});
		zipFile.readEntry();
	});
}

function validateEntries(entries) {
	if (entries.length === 0) throw new Error("ZIP is empty.");
	const names = new Set();
	const caseInsensitiveNames = new Set();
	const roots = new Set();

	for (const entry of entries) {
		const { name } = entry;
		if (name.includes("\\")) throw new Error(`ZIP entry uses backslash: ${name}`);
		if (name.startsWith("/") || /^[A-Za-z]:/.test(name)) {
			throw new Error(`ZIP entry is absolute: ${name}`);
		}
		const segments = name.split("/").filter(Boolean);
		if (segments.includes("..")) throw new Error(`ZIP entry traverses paths: ${name}`);
		if (segments.some((segment) => FORBIDDEN_SEGMENTS.has(segment))) {
			throw new Error(`ZIP contains a forbidden path: ${name}`);
		}
		if (entry.isSymlink) throw new Error(`ZIP contains a symbolic link: ${name}`);
		if (names.has(name)) throw new Error(`ZIP contains a duplicate entry: ${name}`);
		names.add(name);
		const folded = name.toLocaleLowerCase("en-US");
		if (caseInsensitiveNames.has(folded)) {
			throw new Error(`ZIP contains case-colliding entries: ${name}`);
		}
		caseInsensitiveNames.add(folded);
		if (segments[0]) roots.add(segments[0]);
	}

	if (roots.size !== 1 || !roots.has(PLUGIN_SLUG)) {
		throw new Error(`ZIP root must only be ${PLUGIN_SLUG}/; found: ${[...roots]}`);
	}
	if (!names.has(MAIN_FILE)) throw new Error(`Plugin file does not exist: ${MAIN_FILE}`);
	for (const entry of entries.filter((candidate) => candidate.isDirectory)) {
		if (!entries.some((candidate) => !candidate.isDirectory && candidate.name.startsWith(entry.name))) {
			throw new Error(`ZIP contains an empty directory: ${entry.name}`);
		}
	}

	return { names, roots: [...roots] };
}

async function extractZip(zipPath, targetDirectory) {
	const zipFile = await openZip(zipPath, { strictFileNames: true });
	await new Promise((resolve, reject) => {
		zipFile.on("entry", async (entry) => {
			try {
				const destination = path.join(targetDirectory, ...entry.fileName.split("/"));
				if (entry.fileName.endsWith("/")) {
					await mkdir(destination, { recursive: true });
					zipFile.readEntry();
					return;
				}
				await mkdir(path.dirname(destination), { recursive: true });
				zipFile.openReadStream(entry, async (error, input) => {
					if (error) {
						reject(error);
						return;
					}
					try {
						await pipeline(input, createWriteStream(destination, { flags: "wx" }));
						zipFile.readEntry();
					} catch (streamError) {
						reject(streamError);
					}
				});
			} catch (error) {
				reject(error);
			}
		});
		zipFile.on("end", resolve);
		zipFile.on("error", reject);
		zipFile.readEntry();
	});
	zipFile.close();
}

export async function extractVerifiedPackage(zipPath, targetDirectory) {
	const resolvedZip = path.resolve(zipPath);
	const entries = await readEntries(resolvedZip);
	validateEntries(entries);
	await mkdir(targetDirectory, { recursive: true });
	await extractZip(resolvedZip, targetDirectory);
	return entries.map((entry) => entry.name);
}

function assertPluginHeader(source) {
	const header = source.slice(0, 8192);
	for (const field of [
		"Plugin Name",
		"Description",
		"Version",
		"Author",
		"Text Domain",
	]) {
		if (!new RegExp(`^[ \\t*#/@]*${field}:\\s*\\S.+$`, "mi").test(header)) {
			throw new Error(`Main plugin file is missing a valid ${field} header.`);
		}
	}
	if (!/Text Domain:\s*jaisong1n-site-manager\s*$/mi.test(header)) {
		throw new Error("Unexpected Text Domain in main plugin file.");
	}
}

async function validatePhpFiles(extractedRoot) {
	const parser = new PhpParser.Engine({
		parser: { suppressErrors: false },
		ast: { withPositions: true },
	});
	const pending = [extractedRoot];
	const phpFiles = [];
	while (pending.length > 0) {
		const directory = pending.pop();
		const { readdir } = await import("node:fs/promises");
		for (const entry of await readdir(directory, { withFileTypes: true })) {
			const target = path.join(directory, entry.name);
			if (entry.isDirectory()) pending.push(target);
			else if (entry.name.toLowerCase().endsWith(".php")) phpFiles.push(target);
		}
	}
	phpFiles.sort();
	for (const phpFile of phpFiles) {
		const source = await readFile(phpFile, "utf8");
		try {
			parser.parseCode(source, phpFile);
		} catch (error) {
			throw new Error(`PHP syntax error in ${path.relative(extractedRoot, phpFile)}: ${error.message}`, { cause: error });
		}
	}
	return phpFiles;
}

async function validateIncludes(extractedRoot, phpFiles) {
	for (const phpFile of phpFiles) {
		const source = await readFile(phpFile, "utf8");
		const patterns = [
			{ base: extractedRoot, expression: /(?:require|require_once|include|include_once)\s+JG_SITE_MANAGER_DIR\s*\.\s*['"]([^'"]+)['"]/g },
			{ base: path.dirname(phpFile), expression: /(?:require|require_once|include|include_once)\s+__DIR__\s*\.\s*['"]([^'"]+)['"]/g },
		];
		for (const { base, expression } of patterns) {
			for (const match of source.matchAll(expression)) {
				const target = path.resolve(base, match[1].replaceAll("/", path.sep));
				try {
					await access(target);
				} catch {
					throw new Error(`Missing or case-mismatched include target: ${match[1]}`);
				}
			}
		}
	}
}

export async function verifyPackage(zipPath) {
	const resolvedZip = path.resolve(zipPath);
	await access(resolvedZip);
	const entries = await readEntries(resolvedZip);
	const { roots } = validateEntries(entries);
	const temporaryDirectory = await mkdtemp(path.join(os.tmpdir(), "jg-plugin-verify-"));
	try {
		await extractZip(resolvedZip, temporaryDirectory);
		const extractedRoot = path.join(temporaryDirectory, PLUGIN_SLUG);
		const mainPath = path.join(extractedRoot, `${PLUGIN_SLUG}.php`);
		const mainSource = await readFile(mainPath, "utf8");
		assertPluginHeader(mainSource);
		const phpFiles = await validatePhpFiles(extractedRoot);
		await validateIncludes(extractedRoot, phpFiles);
		return {
			zipPath: resolvedZip,
			rootDirectories: roots,
			mainFile: MAIN_FILE,
			pluginBasename: MAIN_FILE,
			entryCount: entries.length,
			phpFileCount: phpFiles.length,
			entries: entries.map((entry) => entry.name),
		};
	} finally {
		await rm(temporaryDirectory, { recursive: true, force: true });
	}
}

async function main() {
	const zipPath = process.argv[2];
	if (!zipPath) {
		throw new Error("Usage: node scripts/verify-wordpress-plugin-package.mjs <zip-path>");
	}
	const result = await verifyPackage(path.resolve(REPOSITORY_ROOT, zipPath));
	process.stdout.write(`${JSON.stringify({ ...result, entries: result.entries.slice(0, 30) }, null, 2)}\n`);
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
	main().catch((error) => {
		process.stderr.write(`WordPress plugin package verification failed: ${error.message}\n`);
		process.exitCode = 1;
	});
}
