import { lookup as dnsLookup } from "node:dns/promises";
import net from "node:net";
import { Agent } from "undici";

import { SYNC_LIMITS } from "./contracts.mjs";

/**
 * Explicit client identifier for WordPress sync requests. Native fetch would
 * otherwise send the bare `node` User-Agent, which hosting bot protections
 * (e.g. SiteGround Bot Verification, Cloudflare Bot Fight Mode) frequently
 * flag when requests originate from datacenter IPs such as GitHub Actions.
 */
export const SYNC_USER_AGENT =
	"JaisonG1n-Blog-WordPress-Sync/1.0 (+https://github.com/1Huang1-1/JaisonG1n-Blog)";

/**
 * Shared headers for WordPress sync requests. When both WP_API_USERNAME and
 * WP_API_APPLICATION_PASSWORD are configured, a Basic Authorization header is
 * attached so every request is authenticated as the WordPress application
 * password user. Hostinger support reports authenticated requests are less
 * likely to trigger platform bot verification. Credentials are never logged;
 * without them the header is omitted and behavior stays unchanged.
 */
export function buildSyncHeaders(extra = {}, env = process.env) {
	const username = String(env.WP_API_USERNAME ?? "").trim();
	const appPassword = String(env.WP_API_APPLICATION_PASSWORD ?? "").trim();
	const headers = { "User-Agent": SYNC_USER_AGENT, ...extra };
	if (username && appPassword) {
		headers.Authorization = `Basic ${Buffer.from(`${username}:${appPassword}`).toString("base64")}`;
	}
	return headers;
}

/**
 * Orders resolved addresses so IPv4 records come first while preserving the
 * relative order within each family. Native fetch otherwise connects to the
 * first resolved record, which can be an unreachable IPv6 address on networks
 * without a working route (the recurring CI ETIMEDOUT).
 */
export function preferIpv4Addresses(records) {
	return [...records].sort((left, right) => {
		const leftRank = Number(left.family) === 4 ? 0 : 1;
		const rightRank = Number(right.family) === 4 ? 0 : 1;
		return leftRank - rightRank;
	});
}

/**
 * A lookup that always returns the pre-resolved, IPv4-first address list.
 * Mirrors the media mirror's pinned lookup so the native WordPress fetch no
 * longer depends on the resolver's family ordering at connect time.
 */
export function createPinnedLookup(addresses) {
	const pinned = addresses.map((record) => ({
		address: record.address,
		family: Number(record.family),
	}));
	return (_hostname, options, callback) => {
		if (options?.all) {
			callback(null, pinned.map((record) => ({ ...record })));
			return;
		}
		callback(null, pinned[0].address, pinned[0].family);
	};
}

export async function resolveHostAddresses(hostname, resolver = dnsLookup) {
	const literalFamily = net.isIP(hostname);
	const records = literalFamily
		? [{ address: hostname, family: literalFamily }]
		: await resolver(hostname, { all: true, verbatim: true });
	if (!Array.isArray(records) || records.length === 0) {
		throw new Error(`Sync host did not resolve: ${hostname}`);
	}
	return records.map((record) => ({
		address: record.address,
		family: Number(record.family),
	}));
}

/**
 * Builds an Undici Agent whose connect uses the pinned IPv4-first lookup and a
 * bounded connect timeout. autoSelectFamily lets a usable family win when both
 * IPv4 and IPv6 records exist, instead of hanging on the first unreachable one.
 */
export function fetchDispatcherConnectOptions(url, addresses, connectTimeoutMs) {
	return {
		lookup: createPinnedLookup(preferIpv4Addresses(addresses)),
		timeout: connectTimeoutMs,
		servername: url.hostname,
		autoSelectFamily: true,
	};
}

export async function buildFetchDispatcher(
	url,
	{ resolver, connectTimeoutMs = SYNC_LIMITS.connectTimeoutMs } = {},
) {
	const addresses = await resolveHostAddresses(url.hostname, resolver);
	return new Agent({ connect: fetchDispatcherConnectOptions(url, addresses, connectTimeoutMs) });
}
