# Deployment Status API Requirements

Final contract for Site Manager 0.8.2, aligned with the real build chain (WordPress publish -> debounced `workflow_dispatch` -> GitHub Actions -> `deploy` branch -> Cloudflare Pages) and the Astro routes (`/diary/{slug}/`, `/posts/{slug}/`). This document is the source of truth for the server implementation; the client skeleton in the OpenClaw repository consumes the live `capabilities` response.

## Goals

Give OpenClaw a read-only, least-privilege, auditable way to distinguish:

1. WordPress content state
2. whether a build was triggered
3. whether GitHub Actions is queued, running, successful, or failed
4. whether the front-end deployment completed
5. whether the final public page is reachable
6. the CMS address versus the final blog address

GitHub accepting a `workflow_dispatch` is never treated as build success.

## Endpoint

```http
GET /wp-json/jaisong1n/v1/ai/content/{contentType}/{id}/deployment-status
```

- Application Password authentication required.
- Same object read permission as `GET /content/{contentType}/{id}`; no `manage_options` requirement.
- Unreadable content returns the same obfuscated `jg_ai_content_not_found` 404 as content reads.
- Read-only: never dispatches, re-runs, cancels, or modifies content.

## Response

All timestamps are ISO 8601 UTC. Unknown values are `null` or an explicit `unknown`/`unchecked` value; no state is invented.

| Field | Values | Meaning |
| --- | --- | --- |
| `wordpressStatus` | draft / publish / ... | Raw WordPress post status |
| `dispatchStatus` | accepted / failed / unchanged / busy / null | Whether the debounced workflow dispatch was accepted |
| `buildStatus` | not_triggered / pending / queued / in_progress / success / failed / cancelled / unknown | GitHub Actions run state |
| `buildConclusion` | success / failure / cancelled / timed_out / null | GitHub run conclusion when completed |
| `deploymentStatus` | deployed / pending / unknown | Front-end deployment evidence |
| `pageStatus` | reachable / not_found / unavailable / unchecked | Trusted-host page probe result |
| `publicUrl` | URL or null | Server-side canonical public URL |
| `cmsUrl` | URL | WordPress edit URL |
| `workflowRunId` | int or null | Parsed from the GitHub 200 dispatch response; never fabricated from 204 |
| `workflowRunUrl` | URL or null | GitHub API run URL |
| `triggeredAt` / `startedAt` / `completedAt` / `lastCheckedAt` | ISO 8601 or null | Dispatch and run timeline |
| `errorCode` / `errorSummary` | string or null | Sanitized failure metadata |

## Persistence and association

- Dispatch records live in the existing `jg_dispatch_history` option; the latest view stays in `jg_dispatch_status`.
- A debounced pending batch accumulates `contentRefs` (`contentType`, `contentId`, `modifiedAt`), so one record can cover several diaries or articles.
- A content query finds the newest record that contains its content reference and was triggered at or after the content's last modification.
- History entries without `contentRefs` are never hard-bound to a content query.

## GitHub dispatch

- Dispatch uses `X-GitHub-Api-Version: 2026-03-10`.
- HTTP 200 responses are parsed for `workflow_run_id`, `run_url`, and `html_url`.
- HTTP 204 (legacy) records `dispatchStatus=accepted` with `workflowRunId=null`; no run ID is fabricated.
- GitHub tokens never appear in logs, REST responses, or audit details.

## GitHub run queries

- Run state is queried through the official Actions run API and cached for 20 seconds.
- 403, 404, 429, 500, and network failures preserve the last known build state and set `errorCode`/`errorSummary`.
- Queries never mutate content and never auto-trigger or cancel workflows.

## Public URL and page probe

- Canonical URLs come from a server-side helper using the configured production site URL (`public_site_url`, default `https://jaisong1n.com`).
- diary -> `/diary/{slug}/`; article -> `/posts/{slug}/`; unsupported types or empty/invalid slugs return `null`.
- The page probe only accepts the configured production host, blocks redirects, caps the response at 64 KiB, and applies a 10-second timeout.
- HTTP 200 means the URL serves content; it does not prove the newest build is live.

## Security model

- `deploymentStatus` is a read-only operation exposed for readable content types; it grants no publish or build authority.
- No credentials, tokens, Authorization headers, full GitHub responses, page bodies, or internal options are returned or logged.
- Audit entries store the user, content type/id, workflow run ID, resulting build state, and timestamp.

## Out of scope for 0.8.2

- Cloudflare Pages API integration (no deployment ID query); front-end deployment is confirmed by build success plus trusted page probe.
- Build IDs embedded in generated pages (future freshness check).
- Article controlled publishing (Site Manager 0.9.0 roadmap).
