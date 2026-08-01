import { createWriteStream } from "node:fs";
import { mkdir, mkdtemp, rm, writeFile } from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import { pipeline } from "node:stream/promises";
import { spawn } from "node:child_process";
import { createRequire } from "node:module";
import { fileURLToPath } from "node:url";
import {
	extractVerifiedPackage,
	verifyPackage,
} from "./verify-wordpress-plugin-package.mjs";

const require = createRequire(import.meta.url);
const yazl = require("yazl");

const SCRIPT_DIR = path.dirname(fileURLToPath(import.meta.url));
const REPOSITORY_ROOT = path.resolve(SCRIPT_DIR, "..");
const PLUGIN_SLUG = "jaisong1n-site-manager";
const PLUGIN_BASENAME = `${PLUGIN_SLUG}/${PLUGIN_SLUG}.php`;
const REPLACEMENT_ZIP = path.join(
	REPOSITORY_ROOT,
	"wordpress-plugin",
	"dist",
	`${PLUGIN_SLUG}-0.8.0.zip`,
);

const baselinePlugin = `<?php
/**
 * Plugin Name: JaisonG1n Site Manager
 * Description: Minimal 0.7.1 upgrade-path fixture for reviewed diary publishing testing.
 * Version: 0.7.1
 * Author: JaisonG1n
 * Text Domain: jaisong1n-site-manager
 */

if (!defined('ABSPATH')) { exit; }

function jg_upgrade_fixture_activate(): void {
	add_option('jg_site_settings', array('site_title' => 'Upgrade preserved'), '', false);
	add_option('jg_github_token', 'upgrade-fixture-token', '', true);
	add_option('jg_dispatch_pending', array('revision' => 'a' . str_repeat('0', 63)), '', false);
	add_option('jg_dispatch_history', array(array('status' => 'success')), '', false);
	register_post_type('jg_project', array('public' => false, 'show_ui' => true));
	wp_insert_post(array(
		'post_type' => 'jg_project',
		'post_status' => 'publish',
		'post_title' => 'Upgrade fixture project',
		'post_name' => 'upgrade-fixture-project',
		'post_content' => 'Preserved content',
	));
	$editor = get_role('editor');
	if ($editor) $editor->add_cap('jg_fixture_capability');
}

register_activation_hook(__FILE__, 'jg_upgrade_fixture_activate');
`;

async function createBaselineZip(zipPath) {
	const sourcePath = `${zipPath}.php`;
	await writeFile(sourcePath, baselinePlugin, "utf8");
	const zipFile = new yazl.ZipFile();
	const completion = pipeline(
		zipFile.outputStream,
		createWriteStream(zipPath, { flags: "wx" }),
	);
	zipFile.addFile(sourcePath, PLUGIN_BASENAME, {
		mtime: new Date("2000-01-01T00:00:00.000Z"),
		mode: 0o100644,
	});
	zipFile.end();
	await completion;
}

function run(command, args) {
	return new Promise((resolve, reject) => {
		const child = spawn(command, args, {
			cwd: REPOSITORY_ROOT,
			stdio: ["ignore", "pipe", "pipe"],
			shell: false,
			env: process.env,
		});
		let stdout = "";
		let stderr = "";
		child.stdout.on("data", (chunk) => {
			stdout += chunk;
		});
		child.stderr.on("data", (chunk) => {
			stderr += chunk;
		});
		child.on("error", reject);
		child.on("close", (code) => {
			if (code === 0) resolve({ stdout, stderr });
			else reject(new Error(`${command} exited with ${code}:\n${stdout}\n${stderr}`));
		});
	});
}

async function main() {
	await verifyPackage(REPLACEMENT_ZIP);
	const temporaryDirectory = await mkdtemp(path.join(os.tmpdir(), "jg-upgrade-test-"));
	const baselineZip = path.join(temporaryDirectory, `${PLUGIN_SLUG}-0.7.1.zip`);
	const pluginsDirectory = path.join(temporaryDirectory, "plugins");
	const replacementDirectory = path.join(temporaryDirectory, "replacement");
	try {
		await mkdir(pluginsDirectory, { recursive: true });
		await createBaselineZip(baselineZip);
		const baselineVerification = await verifyPackage(baselineZip);
		await extractVerifiedPackage(baselineZip, pluginsDirectory);
		await extractVerifiedPackage(REPLACEMENT_ZIP, replacementDirectory);

		const testScript = path.join(
			REPOSITORY_ROOT,
			"tests",
			"wordpress-plugin-upgrade.php",
		);
		const args = [
			"--yes",
			"@wp-playground/cli@latest",
			"php",
			"--mount-dir",
			path.join(pluginsDirectory, PLUGIN_SLUG),
			`/wordpress/wp-content/plugins/${PLUGIN_SLUG}`,
			"--mount-dir",
			replacementDirectory,
			"/workspace/replacement",
			"--mount-dir",
			path.dirname(testScript),
			"/workspace/tests",
			"--php=8.3",
			"--wp=latest",
			"--define-bool",
			"WP_DEBUG",
			"true",
			"--define-bool",
			"WP_DEBUG_DISPLAY",
			"true",
			"--verbosity=quiet",
			"--",
			"/workspace/tests/wordpress-plugin-upgrade.php",
		];
		const npxCli = path.join(
			path.dirname(process.execPath),
			"node_modules",
			"npm",
			"bin",
			"npx-cli.js",
		);
		const command = process.platform === "win32" ? process.execPath : "npx";
		const commandArgs = process.platform === "win32" ? [npxCli, ...args] : args;
		const { stdout, stderr } = await run(command, commandArgs);
		const jsonStart = stdout.indexOf("{");
		const jsonEnd = stdout.lastIndexOf("}");
		if (jsonStart < 0 || jsonEnd < jsonStart) {
			throw new Error(`Upgrade test did not return JSON:\n${stdout}\n${stderr}`);
		}
		const result = JSON.parse(stdout.slice(jsonStart, jsonEnd + 1));
		if (!result.ok || result.pluginBasename !== PLUGIN_BASENAME) {
			throw new Error(`Upgrade test returned an invalid result: ${JSON.stringify(result)}`);
		}
		process.stdout.write(
			`${JSON.stringify(
				{
					...result,
					baselinePackageEntries: baselineVerification.entries,
					replacementPackage: REPLACEMENT_ZIP,
				},
				null,
				2,
			)}\n`,
		);
	} finally {
		await rm(temporaryDirectory, { recursive: true, force: true });
	}
}

main().catch((error) => {
	process.stderr.write(`WordPress plugin upgrade test failed: ${error.message}\n`);
	process.exitCode = 1;
});
