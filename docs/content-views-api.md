# Content Views API (Site Manager 0.11.0)

Public, privacy-safe view counting for published **article** and **diary**
detail pages. This API is intentionally separate from the authenticated AI
Content API: any visitor may report a view, and the server never exposes
counts of draft, private, or non-existent content.

## Endpoint

```
POST /wp-json/jg-public/v1/content/{contentType}/{id}/view
```

- `contentType`: `article` or `diary` only.
- `id`: numeric post ID or the public URL slug (ASCII, or percent-encoded /
  decoded CJK slug; all common WordPress `post_name` storage forms are tried).
- No authentication required.

Request body (JSON, at most 1 KiB):

```json
{
  "eventId": "123e4567-e89b-12d3-a456-426614174000"
}
```

`eventId` must be a valid UUIDv4-shaped value. It is never stored in plain
text; the server stores only its SHA-256 hash, bound to
`contentType:postId:eventId`, so the same eventId on a different content
counts independently.

Success response:

```json
{
  "contentType": "diary",
  "id": 102,
  "views": 1286,
  "counted": true
}
```

- First submission of an event: `counted: true`, count incremented atomically.
- Retry of the same event (within the 30-day event window): `counted: false`,
  `views` is the current count and is not increased.

## Error responses

| HTTP | code | meaning |
| --- | --- | --- |
| 400 | `jg_view_invalid_content_type` | contentType is not article/diary |
| 400 | `jg_view_invalid_body` / `jg_view_invalid_event_id` | body is not JSON or eventId is not a UUID |
| 400 | `jg_view_invalid_id` | id is empty or has no resolvable slug form |
| 404 | `jg_view_not_found` | content does not exist, is not published, or is a different type |
| 413 | `jg_view_body_too_large` | body exceeds 1 KiB |
| 429 | `jg_view_rate_limited` | per-IP (hashed) limit of 60 requests/minute exceeded |
| 403 | `jg_view_origin_forbidden` | request carries a disallowed Origin header |

## Guards and privacy

- Only `status=publish` content is countable; drafts, private, and missing
  content return 404 and are never counted.
- Client-supplied view counts are ignored; only the boolean/atomic increment
  path exists.
- Bot and link-preview user agents (`bot|crawler|spider|preview|...`) are
  detected and answered with the current count and `counted: false`.
- Raw IP addresses are never stored; rate limiting uses a truncated SHA-256 of
  the IP in a short-lived transient.
- CORS is restricted to `https://jaisong1n.com`,
  `https://www.jaisong1n.com`, `http://localhost:4321`, and
  `http://localhost:3000`; `OPTIONS` preflight returns 204 with the allowed
  origin echoed back.
- View recording never calls `wp_update_post`, never writes `wp_posts`, and
  never enters the content-change/dispatch/build pipeline, so `modifiedAt`
  stays unchanged and no GitHub Actions run is triggered.

## Storage

Created by the 0.10.1 → 0.11.0 migration (`JG_Content_Stats::install()`):

- `{prefix}jg_content_stats`: `content_type`, `content_id`, `view_count`,
  `updated_at`; primary key `(content_type, content_id)`.
- `{prefix}jg_view_events`: `event_hash` (SHA-256), `content_type`,
  `content_id`, `expires_at`; primary key `event_hash`; index
  `(content_type, content_id, expires_at)`.

Expired events are purged probabilistically (1% of write requests). Uninstall
follows the plugin's existing policy and does not delete statistics.
