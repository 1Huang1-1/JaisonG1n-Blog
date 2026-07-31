# Project Instructions

## Blog diary assistant

Detailed diary-writing and publishing rules are in `docs/codex/diary-workflow.md`.

### Trigger Intent

Treat requests such as "write a diary", "weekly diary", "AI weekly", "technology weekly", "development diary", "project diary", "turn this into a blog diary", "post to the blog diary", and "create a diary draft" as blog diary tasks.

### Default Behavior

1. Read `docs/codex/diary-workflow.md` first.
2. For a real operation, read `GET {WP_BASE_URL}/wp-json/jaisong1n/v1/ai/capabilities` before writing. Do not guess diary fields or use WordPress internal meta keys.
3. Create a `diary` draft by default. Only consider the separate publish endpoint when the user explicitly asks to publish.
4. Publishing additionally requires capabilities support, server-side publishing enabled, content publish authorization, and the latest `expectedModifiedAt`. Never use `PATCH status=publish` as a bypass.
5. If publication is refused, retain the draft, do not bypass permissions, and return the WordPress edit URL.
6. Never create albums, alter fixed pages, delete content, or manage users, plugins, themes, or site settings for a diary task.
7. Never expose Application Passwords, Authorization headers, Basic-auth encodings, tokens, cookies, or environment-variable values.
8. Use a stable unique Idempotency-Key, search for same-slug or same-topic/date duplicates before creating, and verify one replay does not create another draft.
9. Unless the user explicitly requests an existing-content change, create a new diary draft.
10. For current news, AI developments, products, or technology events, browse and verify dates from primary sources or credible reporting. Stop rather than inventing unverifiable facts.
11. For development diaries, use the current conversation, git diff/log, test output, project documents, and explicit user facts. Do not turn plans into completed work or invent tests, deployments, or personal experiences.
12. After a real task, report only a concise execution summary. Do not print the full diary body unless asked.

### Daily And Weekly Recognition

Treat “daily report”, “today's summary”, “what I did today”, “today's development record”, “today's technology news”, and “today's AI news” as daily diaries. Treat “weekly report”, “weekly summary”, “what I did this week”, “weekly technology news”, “this week's AI developments”, and “weekly technology changes” as weekly diaries.

- “Today” and daily reports cover local midnight through now; “yesterday” covers the preceding local calendar day; weekly reports cover Monday 00:00 through now. Honor an explicit date range and never hardcode a date.
- For “write a daily report” or “write a weekly report”, use a development diary when the session is mainly development, testing, deployment, or learning; use a technology/AI diary when the user mentions technology, AI, news, industry changes, or terms. If context is insufficient, ask: “这次写开发日报，还是 AI 科技日报？”
- Never invent a day's experience. Detailed rules remain in `docs/codex/diary-workflow.md`.

### Writing Style

Before writing any blog content, read `docs/codex/writing-style.md`. It applies to diaries, articles, project descriptions, learning-resource descriptions, technology commentary, development recaps, and tutorials.

Priority is: explicit user request, applicable content-type workflow, `docs/codex/writing-style.md`, then project defaults. Do not imitate named authors or bloggers. The writing may adopt clear structure, evidence, technical depth, and process recording, but must remain original JaisonG1n writing.
