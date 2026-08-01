# AI Content API

`JaisonG1n Site Manager 0.8.0` provides the authenticated API at `/wp-json/jaisong1n/v1/ai`.

Use a dedicated WordPress user with the `jg_ai_content_editor` role and an Application Password. Clients must first request `GET /capabilities`; the response is the live authority for content types, fields, and operations. It never exposes internal meta keys, confirmation tokens, or site secrets.

Supported content types are `article`, `diary`, `project`, `timeline`, `skill`, `aiTool`, `friend`, `announcement`, `techRadar`, and `learningResource`. `page` and `album` are rejected. There are no delete endpoints or album write operations.

## Draft Operations

Create drafts with `POST /content` and an `Idempotency-Key` header. Read with `GET /content` or `GET /content/{contentType}/{id}`. `modifiedAt` is an ISO 8601 UTC string or `null` for an invalid WordPress zero date.

Only diary drafts expose `updateDraft`. Send `PATCH /content/diary/{id}` with the exact `expectedModifiedAt` from the latest read and at least one changed `title`, `content`, `excerpt`, or `slug` field. The endpoint never changes status, ownership, dates, permissions, or internal metadata.

## Reviewed Diary Publishing

Publishing is disabled by default. An administrator must enable reviewed diary publishing in Settings > AI Content API and separately mark the diary as publishable in its AI Content Assistant panel. Enabling the setting grants only `jg_ai_publish_diary_drafts` to the AI role. It never grants WordPress's native diary publish capability.

The live diary capabilities then expose `preparePublish` and `publish`. No other content type receives these operations.

### Prepare

```http
POST /wp-json/jaisong1n/v1/ai/content/diary/{id}/prepare-publish
```

The target must still be a draft. The response contains its current title, slug, excerpt, `modifiedAt`, `editUrl`, a 64-character `confirmationToken`, and `expiresAt`. The token is valid for ten minutes, is bound to the current user, diary ID, current content version, and `publish` action, and is stored only as a SHA-256 digest. Preparing does not write content, change status, or create a build pending record.

### Publish

```http
POST /wp-json/jaisong1n/v1/ai/content/diary/{id}/publish
Idempotency-Key: stable-client-key
Content-Type: application/json

{
  "confirmationToken": "<one-time token returned by prepare>",
  "expectedModifiedAt": "2026-08-01T03:04:05Z",
  "idempotencyKey": "stable-client-key"
}
```

The server verifies that the token exists, is unexpired and unused, belongs to the current user and diary, represents the publish action, and matches the unchanged draft version. It also rechecks the live setting, role capability, per-content grant, edit permission, and draft status. A stale draft returns `409`; an expired token returns `410`; invalid authorization or token binding is rejected without publishing.

A successful request consumes the token and records the idempotency result. Replaying the same key and request returns the original result with `idempotentReplay: true`; using another key after publication returns a stable conflict. Publishing enters the existing WordPress status/save automation and creates one debounced build pending record. The API client does not call GitHub directly.

## Security And Audit

The API uses explicit field allowlists, per-user rate limits, optimistic concurrency, action-scoped idempotency, and a separate reviewed-publish capability. Unpublishing remains unavailable.

Audit records retain at most 100 operation summaries. Publishing records `publish_prepare`, `publish_success`, `publish_rejected`, `publish_conflict`, and `idempotent_replay`. They may include a 12-character irreversible token or idempotency fingerprint, but never full content, confirmation tokens, Application Passwords, Authorization headers, or request bodies.
