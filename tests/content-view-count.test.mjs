import assert from "node:assert/strict";
import { describe, it } from "node:test";

import {
	formatViewCount,
	readViewEvent,
	resolveViewEvent,
	shouldSubmitView,
} from "../src/utils/content-view-count.ts";

const EVENT_A = "123e4567-e89b-12d3-a456-426614174000";
const EVENT_B = "123e4567-e89b-12d3-a456-426614174111";

function historyFrom(initialState = null) {
	const history = {
		state: initialState,
		replaceState(data) {
			history.state = data;
		},
	};
	return history;
}

describe("readViewEvent", () => {
	it("returns a valid event id for the matching content", () => {
		const state = {
			contentView: {
				contentType: "article",
				contentId: "hello",
				viewEventId: EVENT_A,
			},
		};
		assert.equal(readViewEvent(state, "article", "hello"), EVENT_A);
	});

	it("rejects a mismatched content type or id", () => {
		const state = {
			contentView: {
				contentType: "article",
				contentId: "hello",
				viewEventId: EVENT_A,
			},
		};
		assert.equal(readViewEvent(state, "diary", "hello"), null);
		assert.equal(readViewEvent(state, "article", "other"), null);
	});

	it("rejects a malformed event id", () => {
		const state = {
			contentView: {
				contentType: "article",
				contentId: "hello",
				viewEventId: "not-a-uuid",
			},
		};
		assert.equal(readViewEvent(state, "article", "hello"), null);
	});
});

describe("resolveViewEvent", () => {
	it("creates and persists a new event id on first entry", () => {
		const history = historyFrom({ swup: { scroll: 0 } });
		const result = resolveViewEvent({
			contentType: "diary",
			contentId: "my-diary",
			history,
			randomUUID: () => EVENT_A,
		});
		assert.equal(result.eventId, EVENT_A);
		assert.equal(result.isNew, true);
		assert.equal(history.state.swup.scroll, 0);
		assert.deepEqual(history.state.contentView, {
			contentType: "diary",
			contentId: "my-diary",
			viewEventId: EVENT_A,
		});
	});

	it("reuses the same event id on refresh, back and forward", () => {
		const history = historyFrom(null);
		resolveViewEvent({
			contentType: "article",
			contentId: "post-1",
			history,
			randomUUID: () => EVENT_A,
		});
		const repeated = resolveViewEvent({
			contentType: "article",
			contentId: "post-1",
			history,
			randomUUID: () => EVENT_B,
		});
		assert.equal(repeated.eventId, EVENT_A);
		assert.equal(repeated.isNew, false);
	});

	it("creates a new event id when the same history entry is absent for another content", () => {
		const history = historyFrom(null);
		resolveViewEvent({
			contentType: "article",
			contentId: "post-1",
			history,
			randomUUID: () => EVENT_A,
		});
		// Re-entering a different content means a different history entry in
		// practice; the resolver must not carry the previous content's event.
		const other = resolveViewEvent({
			contentType: "article",
			contentId: "post-2",
			history,
			randomUUID: () => EVENT_B,
		});
		assert.equal(other.eventId, EVENT_B);
		assert.equal(other.isNew, true);
	});

	it("treats numeric and string ids as the same content", () => {
		const history = historyFrom(null);
		resolveViewEvent({
			contentType: "diary",
			contentId: 82,
			history,
			randomUUID: () => EVENT_A,
		});
		const same = resolveViewEvent({
			contentType: "diary",
			contentId: "82",
			history,
			randomUUID: () => EVENT_B,
		});
		assert.equal(same.eventId, EVENT_A);
		assert.equal(same.isNew, false);
	});
});

describe("formatViewCount", () => {
	it("formats with thousands separators", () => {
		assert.equal(formatViewCount(1286), "1,286");
		assert.equal(formatViewCount(0), "0");
		assert.equal(formatViewCount(1000000), "1,000,000");
	});
});

describe("shouldSubmitView", () => {
	it("requires the page to stay visible for at least one second", () => {
		assert.equal(shouldSubmitView({ visibleMs: 999, visible: true }), false);
		assert.equal(shouldSubmitView({ visibleMs: 1000, visible: true }), true);
		assert.equal(shouldSubmitView({ visibleMs: 5000, visible: true }), true);
		assert.equal(shouldSubmitView({ visibleMs: 5000, visible: false }), false);
	});
});
