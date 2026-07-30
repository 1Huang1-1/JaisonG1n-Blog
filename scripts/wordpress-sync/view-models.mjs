import { createHash } from "node:crypto";

function stableText(value) {
	return typeof value === "string" ? value.replace(/\s+/gu, " ").trim() : "";
}

export function announcementDismissKey(value) {
	const payload = JSON.stringify({
		title: stableText(value.title),
		content: stableText(value.content),
		closable: Boolean(value.closable),
		link: {
			enable: Boolean(value.link?.enable),
			text: stableText(value.link?.text),
			url: stableText(value.link?.url),
			external: Boolean(value.link?.external),
		},
	});
	return createHash("sha256").update(payload).digest("hex").slice(0, 20);
}

export function adaptFriends(items) {
	return items.map((friend) => ({
		name: stableText(friend.title),
		icon: stableText(friend.icon),
		description: stableText(friend.desc),
		avatar: friend.avatarMedia?.url || stableText(friend.imgurl),
		url: friend.siteurl,
		tags: Array.isArray(friend.tags) ? friend.tags.map(stableText).filter(Boolean) : [],
	}));
}

export function adaptAnnouncements(items) {
	return items.map((item) => {
		const link = {
			enable: item.link?.enable !== false,
			text: stableText(item.link?.text),
			url: stableText(item.link?.url),
			external: Boolean(item.link?.external),
		};
		const result = {
			title: stableText(item.title),
			content: typeof item.content === "string" ? item.content.replace(/\r\n?/gu, "\n").trim() : "",
			closable: Boolean(item.closable),
			link,
		};
		return { ...result, dismissKey: announcementDismissKey(result) };
	});
}
