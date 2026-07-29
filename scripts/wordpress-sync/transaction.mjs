import { mkdir, rename, rm, stat } from "node:fs/promises";
import path from "node:path";
import { setTimeout as delay } from "node:timers/promises";

const RETRYABLE_RENAME_ERRORS = new Set(["EACCES", "EBUSY", "EPERM"]);

async function exists(target) {
	try {
		await stat(target);
		return true;
	} catch (error) {
		if (error.code === "ENOENT") return false;
		throw error;
	}
}

export async function renameWithRetry(source, destination, renameImpl = rename) {
	for (let attempt = 1; ; attempt += 1) {
		try {
			await renameImpl(source, destination);
			return;
		} catch (error) {
			if (attempt >= 10 || !RETRYABLE_RENAME_ERRORS.has(error.code)) throw error;
			await delay(attempt * 100);
		}
	}
}

export async function commitDirectoryTransaction({
	entries,
	transactionRoot,
	renameImpl = rename,
	afterReplace,
}) {
	const replacements = entries.filter((entry) => entry.mode === "replace");
	const backupRoot = path.join(transactionRoot, "backup");
	await mkdir(backupRoot, { recursive: true });
	const ledger = [];

	try {
		for (const [index, entry] of replacements.entries()) {
			await mkdir(path.dirname(entry.target), { recursive: true });
			const backup = path.join(backupRoot, `${index}-${entry.name}`);
			const hadPrevious = await exists(entry.target);
			if (hadPrevious) await renameWithRetry(entry.target, backup, renameImpl);
			const state = { ...entry, backup, hadPrevious, installed: false };
			ledger.push(state);
			try {
				await renameWithRetry(entry.staged, entry.target, renameImpl);
				state.installed = true;
				if (afterReplace) await afterReplace({ index, entry });
			} catch (error) {
				throw new Error(`Failed to replace ${entry.name}`, { cause: error });
			}
		}
	} catch (error) {
		const rollbackErrors = [];
		for (const state of [...ledger].reverse()) {
			try {
				if (state.installed) await rm(state.target, { recursive: true, force: true });
				if (state.hadPrevious && (await exists(state.backup))) {
					await renameWithRetry(state.backup, state.target, renameImpl);
				}
			} catch (rollbackError) {
				rollbackErrors.push(rollbackError);
			}
		}
		if (rollbackErrors.length > 0) {
			throw new AggregateError([error, ...rollbackErrors], "Directory transaction and rollback failed");
		}
		throw error;
	}

	await rm(backupRoot, { recursive: true, force: true });
}
