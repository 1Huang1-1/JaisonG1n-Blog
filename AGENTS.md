# Project Instructions

## Blog Content Assistant

This file is the Codex entry point for the cross-agent blog rules in `docs/agents/`. Those rules are shared by Codex and future agents such as OpenClaw; do not create a separate Codex-only copy.

For requests to write a daily report, weekly report, diary, technology update, development record, or to organize material as blog content, read in order:

1. `docs/agents/diary-workflow.md`
2. `docs/agents/writing-style.md`
3. `docs/agents/research-policy.md` when the content contains external facts
4. `docs/agents/publishing-policy.md` when content may be saved or published
5. `docs/agents/ai-content-api-usage.md` before calling a blog API

Explicit user requirements take precedence over default writing style. They do not override safety, authorization, credential-protection, deletion, album-exclusion, or server-side permission boundaries. Do not infer a request to publish from “write”, “save”, or other ambiguous wording.
