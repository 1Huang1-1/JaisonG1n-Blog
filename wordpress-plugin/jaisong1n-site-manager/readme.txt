=== JaisonG1n Site Manager ===
Contributors: jaisong1n
Tags: headless, cms, rest-api, astro
Requires at least: 6.5
Requires PHP: 8.1
Stable tag: 0.11.0
License: GPLv2 or later

Headless WordPress content and safe public configuration manager for jaisong1n.com.

== Description ==

Adds independently permissioned content types, a Chinese settings page, a normalized public site snapshot, ETag support, reserved-slug enforcement, and debounced GitHub workflow_dispatch notifications.

The plugin accepts a fine-grained GitHub token with Actions Read and write for the target repository. Define JAISONG1N_GITHUB_TOKEN in wp-config.php or the server environment, or store it in the private plugin field. It is never included in snapshots, exports, HTML, JavaScript or logs.

== Installation ==

1. Back up WordPress.
2. Upload and activate the plugin ZIP.
3. Open Settings > Blog Manager.
4. Configure public settings and assign a WordPress menu to "JaisonG1n Top Navigation".

Deactivation retains all data. Normal uninstall removes plugin-added role capabilities but retains content and settings. Destructive data cleanup runs only when an administrator explicitly enables it before uninstalling.

== Changelog ==

= 0.11.0 =

* Adds privacy-safe public view counting for published article and diary detail pages through `POST /wp-json/jg-public/v1/content/{type}/{id}/view`.
* Stores aggregated counts in dedicated `jg_content_stats`/`jg_view_events` tables with atomic increments, SHA-256 event hashes bound to content type, content id, and event id, bounded rate limiting, bot user-agent filtering, and CORS restricted to the production site and explicit dev origins.
* View recording never modifies `wp_posts`, never calls `wp_update_post`, and never enters the content change or dispatch/build pipeline.

= 0.10.1 =

* Fixes deployment-status association for content merged into a debounce batch after the batch started: dispatch records now store the actual `dispatchedAt`, and legacy records without it fall back to their trusted `lastCheckedAt` (record creation time) before the batch `triggeredAt`.
* New and historical dispatch records therefore associate correctly with later-merged diary/article changes without re-modifying content or re-dispatching.

= 0.10.0 =

* Adds two-stage in-place updates for published diary and article content: `prepareUpdatePublished` returns a change preview, exact confirmation phrase, and a short-lived token bound to the user, content type, object, content version, and proposed content hash; `updatePublished` applies only title/excerpt/content while verifying that ID, slug, status, publish dates, author, and AI ownership metadata stay unchanged.
* Adds separate `jg_ai_update_published_diaries` and `jg_ai_update_published_articles` capabilities plus "审核制已发布日记修改" and "审核制已发布文章修改" settings (default off); draft `updateDraft` remains unchanged and published content cannot be modified through it.
* Extends content reads with stable `publishedAt`, `canonicalUrl`, safe ownership information, and per-object `availableOperations`.

= 0.9.0 =

* Adds article `updateDraft` with the same draft-only, allowlisted, optimistic-concurrency, and ownership rules as diary.
* Adds reviewed article publishing through the shared prepare/token/publish pipeline and a separate `jg_ai_publish_article_drafts` capability.
* Adds "审核制文章发布" and "AI 自建文章自动允许进入受控发布流程" settings; AI-created, AI-owned article drafts can auto-enter the two-stage flow without automatic publication.
* Keeps schemaVersion 5 and reuses the verified diary ownership, token, idempotency, audit, canonical URL, and deployment status foundations.

= 0.8.3 =

* Automatically grants reviewed-publish eligibility to diary drafts created by AI users through the AI Content API when reviewed diary publishing, the diary publish capability, and the new "auto publishable AI diaries" setting are active.
* Keeps the grant limited to API-created, AI-owned draft diaries; manual, imported, other-author, and non-diary content still requires the administrator's per-content publishable mark.
* Adds an explicit "AI 自建日记自动允许进入受控发布流程" option under content security; enabling it only admits drafts to the two-stage prepare/publish flow, never publishes automatically.

= 0.8.2 =

* Adds a read-only deployment status endpoint under the AI Content API: `GET /content/{type}/{id}/deployment-status`.
* Tracks per-dispatch records with merged content references, workflow run IDs, GitHub run URLs, and WordPress/dispatch/build/deployment/page status layers.
* Queries GitHub Actions run state with short caching and safe failure fallbacks; never maps dispatch acceptance to build success or GitHub success to a deployed front-end.
* Adds a canonical public URL helper for diary and article routes and a restricted, trusted-host page probe for deployment confirmation.
* Exposes `deploymentStatus` as a read-only capability for readable content types without granting publish or build triggers.

= 0.8.1 =

* Unifies diary draft updates and reviewed publishing behind one AI ownership check: native WordPress edit capability or explicit AI ownership plus the editable grant.
* Allows API-owned diary drafts to enter `prepare-publish` without the `edit_others_jg_diarys` capability while keeping publish behind the reviewed setting, the diary-only publish capability, and the per-content publishable grant.
* Adds granular audit rejection reasons (`setting_disabled`, `missing_publish_capability`, `ownership_denied`, `edit_denied`, `not_publishable`, `not_draft`) while keeping the public 403 code unchanged.
* Adds an administrator-only, guarded ownership repair path for AI-created drafts whose author drifted from the AI owner.

= 0.8.0 =

* Adds reviewed diary publishing through a prepare step and a ten-minute, one-time confirmation token.
* Keeps native WordPress publishing unavailable to the AI editor role and grants a separate diary-only capability only when an administrator enables it.
* Adds optimistic concurrency, publish idempotency, token-safe audit events, and the existing debounced build path without direct GitHub calls.

= 0.7.1 =

* Safely exposes `updateDraft` for diary drafts only, with an explicit public field allowlist and optimistic concurrency checks.
* Returns `modifiedAt` as an ISO 8601 UTC string or `null`; zero and invalid WordPress dates are no longer converted to epoch-like values.
* Keeps publishing, status changes, other content types, internal metadata, authorship and GitHub workflow dispatch outside the draft update endpoint.

= 0.7.0 =

* Adds the authenticated JaisonG1n AI Content API for controlled draft creation, reads, draft updates, explicit publish transitions, idempotency, concurrency checks, rate limits and bounded audit records.
* Adds the `jg_ai_content_editor` role and administrator-only per-content AI edit and publish grants. Existing content remains unavailable until explicitly granted.
* Keeps schemaVersion 5, public snapshots, existing content, and the existing WordPress save/status automation path unchanged.

= 0.5.0 =

* Adds schemaVersion 5 album records with ordered local-media-ready photo metadata.
* Keeps existing album post meta and attachments intact while exposing excerpts, revisions, captions and featured state.

= 0.6.0 =

* Triggers GitHub Actions through workflow_dispatch using API version 2026-03-10 and handles both 200 and 204 responses.
* Adds private debounce, revision de-duplication, retry state, manual force rebuilds and a bounded 20-entry history.
* Adds a lightweight reverse media reference index; attachment changes no longer scan every post and post meta.
* Keeps the workflow repository_dispatch trigger as deprecated compatibility for older external callers.

= 0.4.0 =

* Adds WordPress diary synchronization, article-style diary pages, and schemaVersion 4 snapshot collections.
* Keeps legacy device and anime records hidden and intact; they are not migrated or exposed in the v4 snapshot.
* Adds structured related post IDs, source links, dates, progress and rating fields for the new content types.

= 0.2.4 =

* Adds an optional Iconify icon name for friend cards.

= 0.2.3 =

* Allows announcements to use validated root-relative site links without loosening other content URL fields.

= 0.2.2 =

* Adds native excerpts for projects and timeline entries.
* Uses post excerpts for list descriptions and post content for detail pages.
* Adds Unicode-safe automatic summaries for entries without excerpts.

= 0.2.1 =

* Uses a stable, POSIX-normalized package layout for Linux WordPress hosts.
* Keeps the plugin basename fixed during uploaded-version replacement.

= 0.2.0 =

* Adds schemaVersion 2 content fields and normalized media metadata.
* Adds sortable structured editors for device specs, timeline links, diary images, and album photos.
* Adds deterministic display ordering and removes preview links for headless content types.

= 0.1.0 =

* Initial headless content, settings, snapshot, and dispatch implementation.
