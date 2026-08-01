# AI Content API

`JaisonG1n Site Manager 0.7.1` provides the authenticated API at `/wp-json/jaisong1n/v1/ai`.

Use a dedicated WordPress user with the `jg_ai_content_editor` role and an Application Password. The client must first request `GET /capabilities`; it exposes only public content fields and operations, never internal meta keys, tokens, or site configuration.

Supported content types are `article`, `diary`, `project`, `timeline`, `skill`, `aiTool`, `friend`, `announcement`, `techRadar`, and `learningResource`. `page` and `album` are rejected. There are no delete endpoints and no album write interface.

Create drafts with `POST /content` and an `Idempotency-Key` header. The key is required, bounded, scoped to the caller and action, and replaying the same request returns the original result. A conflicting reuse returns `409`.

Read with `GET /content` or `GET /content/{contentType}/{id}`. `modifiedAt` is an ISO 8601 UTC string or `null` when WordPress has a zero or invalid modified date.

`updateDraft` is exposed only for `diary`. Update a diary draft with `PATCH /content/diary/{id}` and the exact `expectedModifiedAt` from the latest read. An explicitly read `null` value must be sent as JSON `null`. At least one of `title`, `content`, `excerpt`, or `slug` must contain a new value:

```json
{
  "expectedModifiedAt": "2026-08-01T03:04:05Z",
  "title": "Updated diary title",
  "content": "<p>Updated diary body.</p>"
}
```

The endpoint rejects other content types, non-drafts, stale timestamps, unchanged requests, and fields such as `status`, `author`, `date`, `meta`, or structured content fields. It never publishes, deletes, changes ownership, or calls GitHub. A stale value returns `409`; only the caller's own AI content or administrator-granted content is editable.

Publishing and unpublishing use their separate endpoints and require `expectedModifiedAt`, the WordPress publish capability, an administrator grant on the content, and the administrator-enabled publishing setting. Publishing is disabled by default. The API does not call GitHub; successful WordPress publication follows the existing save/status automation path.

Administrators can explicitly grant edit and publish access in the AI Content Assistant meta box. The audit page retains the latest 100 operation summaries without request bodies, excerpts, credentials, Authorization headers, or tokens. The API applies per-user rate limits and returns `429` with a retry hint.
