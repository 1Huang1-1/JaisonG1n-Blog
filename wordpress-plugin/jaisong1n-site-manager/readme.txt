=== JaisonG1n Site Manager ===
Contributors: jaisong1n
Tags: headless, cms, rest-api, astro
Requires at least: 6.5
Requires PHP: 8.1
Stable tag: 0.8.1
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
