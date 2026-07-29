import { createHash } from "node:crypto";
import { createReadStream, createWriteStream } from "node:fs";
import {
	copyFile,
	lstat,
	mkdir,
	mkdtemp,
	readdir,
	readFile,
	rm,
} from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import { pipeline } from "node:stream/promises";
import { createRequire } from "node:module";
import { fileURLToPath } from "node:url";
import { verifyPackage } from "./verify-wordpress-plugin-package.mjs";

const require = createRequire(import.meta.url);
const yazl = require("yazl");

const SCRIPT_DIR = path.dirname(fileURLToPath(import.meta.url));
const REPOSITORY_ROOT = path.resolve(SCRIPT_DIR, "..");
const PLUGIN_SLUG = "jaisong1n-site-manager";
const SOURCE_ROOT = path.join(REPOSITORY_ROOT, "wordpress-plugin", PLUGIN_SLUG);
const DIST_DIRECTORY = path.join(REPOSITORY_ROOT, "wordpress-plugin", "dist");
const RUNTIME_ENTRIES = [
	`${PLUGIN_SLUG}.php`,
	"assets",
	"includes",
	"readme.txt",
	"uninstall.php",
];
const ZIP_TIMESTAMP = new Date("2000-01-01T00:00:00.000Z");

async function copyRuntimeEntry(source, destination) {
	const stats = await lstat(source);
	if (stats.isSymbolicLink()) throw new Error(`Symbolic links are not allowed: ${source}`);
	if (stats.isDirectory()) {
		await mkdir(destination, { recursive: true });
		const entries = await readdir(source, { withFileTypes: true });
		entries.sort((left, right) => left.name.localeCompare(right.name, "en"));
		for (const entry of entries) {
			await copyRuntimeEntry(
				path.join(source, entry.name),
				path.join(destination, entry.name),
			);
		}
		return;
	}
	if (!stats.isFile()) throw new Error(`Unsupported plugin source entry: ${source}`);
	await mkdir(path.dirname(destination), { recursive: true });
	await copyFile(source, destination);
}

async function listFiles(directory) {
	const files = [];
	const visit = async (current) => {
		const entries = await readdir(current, { withFileTypes: true });
		entries.sort((left, right) => left.name.localeCompare(right.name, "en"));
		for (const entry of entries) {
			const target = path.join(current, entry.name);
			if (entry.isSymbolicLink()) throw new Error(`Symbolic links are not allowed: ${target}`);
			if (entry.isDirectory()) await visit(target);
			else if (entry.isFile()) files.push(target);
			else throw new Error(`Unsupported staging entry: ${target}`);
		}
	};
	await visit(directory);
	return files;
}

async function pluginVersion() {
	const source = await readFile(path.join(SOURCE_ROOT, `${PLUGIN_SLUG}.php`), "utf8");
	const match = source.slice(0, 8192).match(/^\s*\*\s*Version:\s*([0-9]+\.[0-9]+\.[0-9]+)\s*$/m);
	if (!match) throw new Error("Could not read a semantic Version header from the main plugin file.");
	return match[1];
}

async function writeZip(stagingPluginRoot, outputPath) {
	const zipFile = new yazl.ZipFile();
	const completion = pipeline(zipFile.outputStream, createWriteStream(outputPath, { flags: "wx" }));
	for (const file of await listFiles(stagingPluginRoot)) {
		const relativePath = path.relative(stagingPluginRoot, file);
		const archivePath = `${PLUGIN_SLUG}/${relativePath.split(path.sep).join("/")}`;
		zipFile.addFile(file, archivePath, {
			mtime: ZIP_TIMESTAMP,
			mode: 0o100644,
			compress: true,
		});
	}
	zipFile.end();
	await completion;
}

async function sha256(file) {
	const hash = createHash("sha256");
	await pipeline(createReadStream(file), hash);
	return hash.digest("hex");
}

async function main() {
	const version = await pluginVersion();
	await mkdir(DIST_DIRECTORY, { recursive: true });
	const outputPath = path.join(DIST_DIRECTORY, `${PLUGIN_SLUG}-${version}.zip`);
	const stagingDirectory = await mkdtemp(path.join(os.tmpdir(), "jg-plugin-package-"));
	const stagingPluginRoot = path.join(stagingDirectory, PLUGIN_SLUG);

	try {
		await mkdir(stagingPluginRoot, { recursive: true });
		for (const entry of RUNTIME_ENTRIES) {
			await copyRuntimeEntry(
				path.join(SOURCE_ROOT, entry),
				path.join(stagingPluginRoot, entry),
			);
		}
		await rm(outputPath, { force: true });
		await writeZip(stagingPluginRoot, outputPath);
		const verification = await verifyPackage(outputPath);
		process.stdout.write(
			`${JSON.stringify(
				{
					zipPath: outputPath,
					sha256: await sha256(outputPath),
					pluginBasename: verification.pluginBasename,
					entryCount: verification.entryCount,
				},
				null,
				2,
			)}\n`,
		);
	} finally {
		await rm(stagingDirectory, { recursive: true, force: true });
	}
}

main().catch((error) => {
	process.stderr.write(`WordPress plugin packaging failed: ${error.message}\n`);
	process.exitCode = 1;
});
