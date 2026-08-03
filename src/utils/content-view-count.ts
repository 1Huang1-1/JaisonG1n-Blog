/**
 * Detail-page view counting for article and diary pages.
 *
 * Each real detail-page visit is identified by a history entry that carries a
 * contentView event id. Refresh, back, and forward reuse the same entry and
 * event id, while leaving the detail page and re-entering it creates a new
 * entry and event id. The server deduplicates by event hash, so the client
 * never needs session-wide "already counted" state.
 */

export interface ViewEventState {
	contentType: string;
	contentId: string | number;
	viewEventId: string;
}

export interface HistoryLike {
	state: unknown;
	replaceState(data: unknown, unused: string): void;
}

const UUID_PATTERN =
	/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

export function readViewEvent(
	state: unknown,
	contentType: string,
	contentId: string | number,
): string | null {
	const view = (state as { contentView?: ViewEventState } | null)?.contentView;
	if (
		!view ||
		view.contentType !== contentType ||
		String(view.contentId) !== String(contentId)
	) {
		return null;
	}
	return UUID_PATTERN.test(view.viewEventId) ? view.viewEventId : null;
}

export function resolveViewEvent({
	contentType,
	contentId,
	history,
	randomUUID,
}: {
	contentType: string;
	contentId: string | number;
	history: HistoryLike;
	randomUUID: () => string;
}): { eventId: string; isNew: boolean } {
	const existing = readViewEvent(history.state, contentType, contentId);
	if (existing) {
		return { eventId: existing, isNew: false };
	}
	const eventId = randomUUID();
	const current =
		history.state && typeof history.state === "object"
			? (history.state as Record<string, unknown>)
			: {};
	history.replaceState(
		{
			...current,
			contentView: { contentType, contentId, viewEventId: eventId },
		},
		"",
	);
	return { eventId, isNew: true };
}

export function formatViewCount(count: number, locale = "zh-CN"): string {
	return new Intl.NumberFormat(locale).format(count);
}

export function shouldSubmitView({
	visibleMs,
	visible,
}: {
	visibleMs: number;
	visible: boolean;
}): boolean {
	return visible && visibleMs >= 1000;
}

interface ViewMount {
	root: HTMLElement;
	value: HTMLElement;
}

const mountsByKey = new Map<string, ViewMount[]>();
const latestViews = new Map<string, string>();
const submitted = new Set<string>();
const timers = new Map<string, ReturnType<typeof setTimeout>>();
const visibilityHandlers = new Map<string, () => void>();
let pageListenerReady = false;

function keyOf(contentType: string, contentId: string | number): string {
	return `${contentType}:${String(contentId)}`;
}

function renderMounts(key: string, text: string, label: string): void {
	latestViews.set(key, text);
	const described = `${text}${label}`;
	for (const mount of mountsByKey.get(key) ?? []) {
		mount.value.textContent = text;
		mount.root.setAttribute("title", described);
		mount.root.setAttribute("aria-label", described);
	}
}

function clearTimersFor(key: string): void {
	const timer = timers.get(key);
	if (timer !== undefined) {
		clearTimeout(timer);
		timers.delete(key);
	}
	const handler = visibilityHandlers.get(key);
	if (handler && typeof document !== "undefined") {
		document.removeEventListener("visibilitychange", handler);
		visibilityHandlers.delete(key);
	}
}

function clearAllTimers(): void {
	for (const key of [...timers.keys()]) {
		clearTimersFor(key);
	}
}

async function submitView({
	key,
	eventId,
	endpoint,
	label,
	locale,
}: {
	key: string;
	eventId: string;
	endpoint: string;
	label: string;
	locale: string;
}): Promise<void> {
	const submitKey = `${key}:${eventId}`;
	if (submitted.has(submitKey)) {
		return;
	}
	submitted.add(submitKey);
	try {
		const response = await fetch(endpoint, {
			method: "POST",
			headers: { "Content-Type": "application/json" },
			body: JSON.stringify({ eventId }),
		});
		if (!response.ok) {
			throw new Error(`view request failed with status ${response.status}`);
		}
		const data = (await response.json()) as { views?: number };
		const views = typeof data.views === "number" ? data.views : 0;
		renderMounts(key, formatViewCount(views, locale), label);
	} catch {
		renderMounts(key, "—", "");
	}
}

function scheduleEntry(
	key: string,
	contentType: string,
	contentId: string | number,
	eventId: string,
	endpoint: string,
	label: string,
	locale: string,
): void {
	clearTimersFor(key);
	if (
		typeof document === "undefined" ||
		document.visibilityState !== "visible"
	) {
		return;
	}
	const timer = setTimeout(() => {
		timers.delete(key);
		if (document.visibilityState === "visible") {
			void submitView({ key, eventId, endpoint, label, locale });
		}
	}, 1000);
	timers.set(key, timer);
	const onVisibility = () => {
		if (document.visibilityState === "visible") {
			scheduleEntry(
				key,
				contentType,
				contentId,
				eventId,
				endpoint,
				label,
				locale,
			);
		} else {
			clearTimersFor(key);
		}
	};
	visibilityHandlers.set(key, onVisibility);
	document.addEventListener("visibilitychange", onVisibility);
}

function refreshEntry(
	key: string,
	contentType: string,
	contentId: string | number,
	eventId: string,
	endpoint: string,
	label: string,
	locale: string,
): void {
	const submitKey = `${key}:${eventId}`;
	if (submitted.has(submitKey)) {
		return;
	}
	scheduleEntry(key, contentType, contentId, eventId, endpoint, label, locale);
}

export function initViewCountMount(
	root: HTMLElement,
	contentType: string,
	contentId: string | number,
	endpoint: string,
	label: string,
	locale: string,
): void {
	const value = root.querySelector<HTMLElement>(".content-view-count");
	if (!value) {
		return;
	}
	const key = keyOf(contentType, contentId);
	const mounts = mountsByKey.get(key) ?? [];
	if (mounts.some((mount) => mount.root === root)) {
		return;
	}
	mounts.push({ root, value });
	mountsByKey.set(key, mounts);
	if (latestViews.has(key)) {
		renderMounts(key, latestViews.get(key) ?? "", label);
	}
	if (!pageListenerReady && typeof document !== "undefined") {
		document.addEventListener("astro:page-load", () => refreshPage());
		document.addEventListener("astro:page-teardown", () => clearAllTimers());
		pageListenerReady = true;
	}
	const { eventId } = resolveViewEvent({
		contentType,
		contentId,
		history: window.history,
		randomUUID: () => crypto.randomUUID(),
	});
	refreshEntry(key, contentType, contentId, eventId, endpoint, label, locale);
}

function refreshPage(): void {
	for (const [key, mounts] of mountsByKey) {
		const first = mounts[0];
		if (!first) {
			continue;
		}
		const contentType = first.root.dataset.contentType ?? "";
		const contentId = first.root.dataset.contentId ?? "";
		const endpoint = first.root.dataset.endpoint ?? "";
		const label = first.root.dataset.label ?? "";
		const locale = first.root.dataset.locale ?? "zh-CN";
		if (!contentType || contentId === "" || !endpoint) {
			continue;
		}
		const { eventId } = resolveViewEvent({
			contentType,
			contentId,
			history: window.history,
			randomUUID: () => crypto.randomUUID(),
		});
		refreshEntry(key, contentType, contentId, eventId, endpoint, label, locale);
	}
}
