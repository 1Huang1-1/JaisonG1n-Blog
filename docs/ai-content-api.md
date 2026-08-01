# AI Content API

`JaisonG1n Site Manager 0.8.2` provides the authenticated API at `/wp-json/jaisong1n/v1/ai`.

Use a dedicated WordPress user with the `jg_ai_content_editor` role and an Application Password. Clients must first request `GET /capabilities`; the response is the live authority for content types, fields, and operations. It never exposes internal meta keys, confirmation tokens, or site secrets.

Supported content types are `article`, `diary`, `project`, `timeline`, `skill`, `aiTool`, `friend`, `announcement`, `techRadar`, and `learningResource`. `page` and `album` are rejected. There are no delete endpoints or album write operations.

## Draft Operations

Create drafts with `POST /content` and an `Idempotency-Key` header. Read with `GET /content` or `GET /content/{contentType}/{id}`. `modifiedAt` is an ISO 8601 UTC string or `null` for an invalid WordPress zero date.

Only diary drafts expose `updateDraft`. Send `PATCH /content/diary/{id}` with the exact `expectedModifiedAt` from the latest read and at least one changed `title`, `content`, `excerpt`, or `slug` field. The endpoint never changes status, ownership, dates, permissions, or internal metadata.

## Diary Ownership And Object Authorization

`updateDraft` and reviewed publishing share one object-level authorization check. A diary is manageable by the current user when native WordPress `edit_post` passes, or when the user is the AI owner (`_jg_ai_owner_user_id` meta or the native post author) and the diary carries the `_jg_ai_editable` grant. This intentionally does not depend on `edit_others_jg_diarys`: an AI-created draft remains manageable by its AI owner even if its native author drifted.

Creating a diary through `POST /content` always writes `post_author = get_current_user_id()`, `_jg_ai_owner_user_id = get_current_user_id()`, `_jg_ai_created = true`, and `_jg_ai_editable = true`. Read access requires being the native author, the AI owner, or an explicitly editable grant. The editable grant alone grants read, not write or publish; publishing additionally requires AI ownership, the reviewed-publish capability, and the per-content publishable mark.

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

The server verifies that the token exists, is unexpired and unused, belongs to the current user and diary, represents the publish action, and matches the unchanged draft version. It also rechecks the live setting, role capability, shared object authorization, per-content publishable grant, and draft status. A stale draft returns `409`; an expired token returns `410`; invalid authorization or token binding is rejected without publishing. The public error remains `jg_ai_publish_forbidden` (HTTP 403) for every authorization failure.

A successful request consumes the token and records the idempotency result. Replaying the same key and request returns the original result with `idempotentReplay: true`; using another key after publication returns a stable conflict. Publishing enters the existing WordPress status/save automation and creates one debounced build pending record. The API client does not call GitHub directly.

## Security And Audit

The API uses explicit field allowlists, per-user rate limits, optimistic concurrency, action-scoped idempotency, and a separate reviewed-publish capability. Unpublishing remains unavailable.

Audit records retain at most 100 operation summaries. Publishing records `publish_prepare`, `publish_success`, `publish_rejected`, `publish_conflict`, and `idempotent_replay`. Rejections carry one internal reason: `setting_disabled`, `missing_publish_capability`, `ownership_denied`, `edit_denied`, `not_publishable`, or `not_draft`. They may include a 12-character irreversible token or idempotency fingerprint, but never full content, confirmation tokens, Application Passwords, Authorization headers, or request bodies.

## Ownership Repair For Existing Drafts

For an AI-created draft whose native author drifted from its AI owner, an administrator can synchronize the author without touching other content. The repair only runs when the draft is a supported content type, `_jg_ai_owner_user_id` is a valid user, `_jg_ai_created` is true, and the native author differs from that owner. The AI Content Assistant panel shows a "同步作者为 AI 所有者" button under exactly those conditions, and the guarded `JG_AI_Content::repair_ai_ownership()` helper applies the same rules for one-off or scripted repairs. Normal diaries are never rewritten in bulk.

## Deployment Status

Read-only deployment status is available at:

```http
GET /wp-json/jaisong1n/v1/ai/content/{contentType}/{id}/deployment-status
```

The endpoint requires Application Password authentication and the same object read permission as `GET /content/{contentType}/{id}`. It never requires `manage_options`, never triggers a build, and never exposes GitHub tokens, raw logs, or internal option dumps. Response statuses keep five layers separate: WordPress content, dispatch acceptance, GitHub build, front-end deployment, and public page availability.

```json
{
  "contentType": "diary",
  "contentId": 91,
  "title": "...",
  "wordpressStatus": "publish",
  "dispatchStatus": "accepted",
  "buildStatus": "success",
  "buildConclusion": "success",
  "deploymentStatus": "deployed",
  "pageStatus": "reachable",
  "publicUrl": "https://jaisong1n.com/diary/...",
  "cmsUrl": "https://cms.jaisong1n.com/wp-admin/post.php?post=91&action=edit",
  "workflowRunId": 123,
  "workflowRunUrl": "https://api.github.com/repos/1Huang1-1/JaisonG1n-Blog/actions/runs/123",
  "triggeredAt": "2026-08-02T00:00:00+00:00",
  "startedAt": "2026-08-02T00:01:00+00:00",
  "completedAt": "2026-08-02T00:03:00+00:00",
  "lastCheckedAt": "2026-08-02T00:03:30+00:00",
  "errorCode": null,
  "errorSummary": null
}
```

Status semantics:

- `wordpressStatus` is the raw WordPress post status; it is never derived from build state.
- `dispatchStatus` is `accepted`, `failed`, `unchanged`, `busy`, or `null`. Dispatch acceptance never means the build succeeded.
- `buildStatus` is `not_triggered`, `pending`, `queued`, `in_progress`, `success`, `failed`, `cancelled`, or `unknown`, read from the GitHub Actions run when a run ID exists. GitHub success never directly means the front-end is deployed.
- `deploymentStatus` is `deployed`, `pending`, or `unknown`. It is set to `deployed` only after a successful build and a reachable public page probe on the configured production host.
- `pageStatus` is `reachable`, `not_found`, `unavailable`, or `unchecked`. A reachable page proves the URL serves content, not that it is the newest build.
- `publicUrl` comes from the server-side canonical URL helper and the configured production site URL; `cmsUrl` remains the WordPress edit address.

Dispatch records persist in `jg_dispatch_history` and retain every merged content reference, so a debounced build covering several diaries is associated with each of them instead of a single post ID. The endpoint picks the latest record containing the requested content that was triggered at or after the content's last modification; it never hard-binds the site's latest build to unrelated content.
