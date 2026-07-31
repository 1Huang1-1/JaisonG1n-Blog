# JaisonG1n Blog Diary Workflow

Use this workflow for natural-language diary requests. The authoritative API contract is [AI Content API](../ai-content-api.md); do not duplicate its internal implementation details or permanently hardcode field names and enums.

## Intent Classification

### Development diary

Examples include today's development diary, a completed feature, weekly progress, or a project diary. Use evidence in this order: current conversation, `git diff`, `git log`, test results, project documents, and user-provided facts. Do not claim unfinished work, invented code changes, test counts, successful deployment, or user feelings. Default length: 800-1600 Chinese characters. Cover what changed, problems, resolution, impact, lessons, and next steps.

### Technology and AI weekly diary

Examples include technology weekly, AI weekly, recent AI changes, and new AI terms. Use the user's local time zone; "this week" begins Monday 00:00 and ends now unless a range is supplied. Browse before writing, collect at least six candidates, and select four to six verified events. Prefer official company, research, release, paper, project, government, or standards sources, then credible reporting. Verify event date, publisher, official status, and whether it belongs in the requested range. Clearly label old events used as background.

Default length: 1800-3000 Chinese characters. Cover an overall observation, important changes, five to eight terms, practical implications, next-week watch items, and sources. For each term provide its Chinese and English name or acronym, plain explanation, problem addressed, current relevance, and whether it is new or newly popular. Never invent events or terminology.

### Personal diary

Use only facts the user explicitly gives. Do not infer relationships, health, mood, location, spending, or personal experiences. If there is insufficient information, ask one short question.

### Topic and opinion diary

For opinions on AI agents, learning, or blog automation, distinguish verified facts, user experience, author observation, and unverified judgement. Never claim the user used a product they did not say they used.

## Development Daily Report

Use for today's development report, today's completed work, project progress, work report, or learning report. Use today's current conversation, `git diff`, `git log`, test results, build/deployment results, project documents, and explicit user facts. Record only what actually happened today; never present historical work, tomorrow's plan, unimplemented features, unrun tests, or incomplete deployment as done.

Default length: 600-1200 Chinese characters, shorter on a light day. Use an 80-140-character excerpt and relevant tags such as 开发日报, WordPress, Astro, AI, OpenClaw, Codex, 自动化, REST API, or 项目记录. Structure: completed work, actual problems, solution, lessons, and tomorrow's plan. Do not invent a blocker.

## Development Weekly Report

Use Monday 00:00 through now. Prioritize current conversation, weekly git log/diff, test and deployment records, documents, and user facts. Default length: 1000-2000 Chinese characters. Cover goals, completed work, key problems and resolutions, test/deployment state, lessons, unfinished work, and next-week plans. Clearly distinguish completed, in progress, not started, and planned work; do not concatenate daily reports.

## Technology And AI Daily Report

Use for today's AI technology report, today's technology changes, today's AI news, or today's AI terms. Research the user's local midnight through now. If few valuable events exist, say “截至当前”, choose fewer events, and deepen analysis; never present older news as today's news. Collect at least four candidates and select three to five verified events.

Default length: 1200-2200 Chinese characters, 100-160-character excerpt, and three to six real terms. Separate what happened, why it matters, and personal observation. Cover a trend overview, events, terms, practical implications, next observations, and at least four sources. Confirm event time, publisher, release status, official source, and uncertainty.

## Technology And AI Weekly Report

The existing technology/AI weekly diary is the technology weekly report: Monday 00:00 through now, at least six candidates, four to six verified events, five to eight terms, and 1800-3000 Chinese characters. Daily reports answer “what happened today”; weekly reports explain trend and connection. Do not turn a weekly report into a list of daily headlines.

## Writing Before API Submission

Before drafting the body, read `docs/codex/writing-style.md` and select the applicable diary type: development daily report, development weekly report, technology and AI daily report, or technology and AI weekly report.

After drafting, run the style review checklist in that document. If any required item fails, revise the draft and keep it unpublished until it passes. This requirement supplements, and does not weaken, the existing factual, source, idempotency, draft-first, and publication-safety rules.

## Writing Style

Write natural first-person Chinese with clear personal observation. Avoid press-release language, marketing clickbait, exaggerated adjectives, hollow conclusions, English-acronym dumping, fabricated experiences, quotations, or sources. Explain why a technical term matters when it first appears.

Titles are natural, specific, non-marketing, with no false suspense or excessive exclamation marks. Excerpts are 100-160 Chinese characters. Safe HTML is limited to `h2`, `h3`, `p`, `ul`, `ol`, `li`, `strong`, `em`, `code`, `blockquote`, and `a`; never use scripts, iframes, styles, event attributes, unsafe URLs, or unapproved shortcodes.

## Facts and Sources

External facts require web research. Verify dates; distinguish announcements, previews, tests, papers, reports, and releases. Attribute media reports, state meaningful uncertainty, and provide at least six valid sources when researching a technology weekly diary. List each source's name, title, date, and link. Do not invent URLs or copy long passages. Render source links as `<a href="..." target="_blank" rel="noopener noreferrer">Source</a>`.

## AI Content API Workflow

For a real WordPress operation:

1. Confirm `WP_BASE_URL`, `WP_API_USERNAME`, and `WP_API_APPLICATION_PASSWORD` are present without printing values, then require HTTPS.
2. Request `/wp-json/jaisong1n/v1/ai/capabilities` and read the actual API version, schema version, diary availability, fields, enums, and permitted create/update/publish operations.
3. Search for same slug and obvious same-title/date duplicates. Generate a stable key such as `codex-diary-{type}-{date}-{topic}-v1`.
4. Create with `POST /wp-json/jaisong1n/v1/ai/content`, `contentType: diary`, and draft status only. Do not accept or set a client author.
5. Read the created item again and verify ID, type, title, slug, author, draft status, modified time, and nonempty body. Replay the same idempotency key once to confirm no duplicate.
6. Stop at draft unless the user explicitly asks to publish. For publication use only the separate endpoint, the latest `expectedModifiedAt`, available publish capability, server enablement, and content publish grant. Never bypass this with PATCH.
7. Do not call GitHub, force a rebuild, or claim deployment succeeded. State only that an existing publication automation path is expected to handle publication.

## Slugs And Idempotency

Use WordPress-safe deterministic slugs: `dev-daily-{YYYY-MM-DD}-{topic}`, `ai-tech-daily-{YYYY-MM-DD}`, `dev-weekly-{YYYY}-w{ISO_WEEK}`, and `ai-tech-weekly-{YYYY}-w{ISO_WEEK}`. Check the slug before creation. Create at most one diary of the same type per day or week; if a draft exists, report it and ask whether to update rather than overwrite or use a random slug. Use a stable key such as `codex-diary-{diaryType}-{dateOrWeek}-{topicVersion}`.

## Natural-Language Defaults

- “Write a diary”: write a development diary from actual work in the current conversation.
- “Write today's diary”: use clear current-session events or ask what to record.
- “Write this week's technology diary”: research Monday through now and create a technology-weekly draft.
- “Write about recent AI developments and terms”: use the technology/AI weekly workflow.
- “Post it to the blog”: create a WordPress draft, not a publication.
- Only “publish directly”, “approved, publish”, or “publish diary ID …” enters the publication workflow.
- “写日报”: use a development daily report for development or learning context; use a technology/AI daily report for technology or news context; otherwise ask one short type question.
- “写今天 AI 日报”: research today's AI/technology events, then create a technology/AI daily draft.
- “把今天完成的博客接口写成日报”: use current evidence and create a development daily draft without unrelated news research.
- “写这周科技周报”: research Monday through now, analyze weekly trends, then create a technology/AI weekly draft.
- “写本周项目周报”: inspect weekly development evidence, then create a development weekly draft.

## Final Report

For a real diary operation report only: diary type, time range, whether research occurred, source count, diary ID, title, slug, status, current-user ownership, idempotency replay, publication result, WordPress edit URL, expected automation behavior, sensitive-information result, and any user next step. Do not print the full body by default.
