=== JaisonG1n Site Manager ===
Contributors: jaisong1n
Tags: headless, cms, rest-api, astro
Requires at least: 6.5
Requires PHP: 8.1
Stable tag: 0.4.1
License: GPLv2 or later

Headless WordPress content and safe public configuration manager for jaisong1n.com.

== Description ==

Adds independently permissioned content types, a Chinese settings page, a normalized public site snapshot, ETag support, reserved-slug enforcement, and debounced GitHub repository_dispatch notifications.

The plugin never stores GitHub tokens in WordPress. Define JG_GITHUB_TOKEN in wp-config.php or the server environment.

== Installation ==

1. Back up WordPress.
2. Upload and activate the plugin ZIP.
3. Open Settings > Blog Manager.
4. Configure public settings and assign a WordPress menu to "JaisonG1n Top Navigation".

Deactivation retains all data. Normal uninstall removes plugin-added role capabilities but retains content and settings. Destructive data cleanup runs only when an administrator explicitly enables it before uninstalling.

== Changelog ==

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
