import { setTimeout as delay } from "node:timers/promises";

const RETRYABLE_ERROR_CODES = new Set([
	"EAI_AGAIN",
	"ECONNREFUSED",
	"ECONNRESET",
	"ETIMEDOUT",
	"UND_ERR_BODY_TIMEOUT",
	"UND_ERR_CONNECT_TIMEOUT",
	"UND_ERR_HEADERS_TIMEOUT",
	"UND_ERR_SOCKET",
]);

const RETRYABLE_ERROR_NAMES = new Set([
	"AbortError",
	"BodyTimeoutError",
	"ConnectTimeoutError",
	"HeadersTimeoutError",
	"TimeoutError",
]);

function errorChain(error) {
	const chain = [];
	const seen = new Set();
	let current = error;
	while (current && !seen.has(current)) {
		seen.add(current);
		chain.push(current);
		current = current.cause;
	}
	return chain;
}

export function isRetryableNetworkError(error) {
	return errorChain(error).some((candidate) => {
		if (RETRYABLE_ERROR_CODES.has(candidate.code)) return true;
		if (RETRYABLE_ERROR_NAMES.has(candidate.name)) return true;
		return /(?:body|connect|headers)\s+timeout|socket hang up|timed out/i.test(
			String(candidate.message ?? ""),
		);
	});
}

export async function withNetworkRetries(
	operation,
	{ maxRetries = 0, retryDelayMs = 0, sleep = delay, onRetry } = {},
) {
	for (let attempt = 0; ; attempt += 1) {
		try {
			return await operation(attempt);
		} catch (error) {
			if (attempt >= maxRetries || !isRetryableNetworkError(error)) throw error;
			const waitMs = Math.max(0, retryDelayMs) * 2 ** attempt;
			if (waitMs > 0) await sleep(waitMs);
			onRetry?.({ attempt: attempt + 1, error });
		}
	}
}
