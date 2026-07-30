function cleanSegment(value) {
	return String(value ?? "").replace(/^\/+|\/+$/g, "");
}

function postDateParts(post) {
	const value = post?.date_gmt || post?.date || post?.published || "";
	const date = new Date(/(?:Z|[+-]\d{2}:?\d{2})$/i.test(String(value)) ? value : `${value}Z`);
	if (Number.isNaN(date.getTime())) return { year: "0000", month: "00", day: "00", hour: "00", minute: "00", second: "00" };
	return {
		year: String(date.getUTCFullYear()).padStart(4, "0"),
		month: String(date.getUTCMonth() + 1).padStart(2, "0"),
		day: String(date.getUTCDate()).padStart(2, "0"),
		hour: String(date.getUTCHours()).padStart(2, "0"),
		minute: String(date.getUTCMinutes()).padStart(2, "0"),
		second: String(date.getUTCSeconds()).padStart(2, "0"),
	};
}

export function resolvePostPath(post, { permalinkEnabled = false, permalinkFormat = "%postname%", postId = 0 } = {}) {
	if (!post || typeof post !== "object") return null;
	const slug = cleanSegment(post.slug || post.alias || `post-${post.id ?? ""}`);
	if (!slug) return null;
	const custom = cleanSegment(post.permalink);
	if (custom) return `/${encodePath(custom)}/`;
	if (!permalinkEnabled) return `/posts/${encodePath(cleanSegment(post.alias || slug))}/`;
	const parts = postDateParts(post);
	const category = cleanSegment(post.category || "uncategorized");
	const sequence = Number.isInteger(postId) && postId > 0 ? String(postId) : String(post.id ?? "");
	const generated = String(permalinkFormat || "%postname%")
		.replace(/%year%/g, parts.year)
		.replace(/%monthnum%/g, parts.month)
		.replace(/%day%/g, parts.day)
		.replace(/%hour%/g, parts.hour)
		.replace(/%minute%/g, parts.minute)
		.replace(/%second%/g, parts.second)
		.replace(/%post_id%/g, sequence)
		.replace(/%postname%/g, slug)
		.replace(/%raw_postname%/g, slug)
		.replace(/%category%/g, category);
	const normalized = cleanSegment(generated);
	return normalized ? `/${encodePath(normalized)}/` : null;
}

function encodePath(value) {
	return value
		.split("/")
		.filter(Boolean)
		.map((segment) => {
			try {
				return encodeURIComponent(decodeURIComponent(segment));
			} catch {
				return encodeURIComponent(segment);
			}
		})
		.join("/");
}
