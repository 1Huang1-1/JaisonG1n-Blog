# AI Content API Usage

## Reviewed publish protocol (Site Manager 0.10.1)

Treat `publish` as a two-stage, server-authorized diary-only operation. It is available only when the live diary capabilities include both `preparePublish` and `publish`.

`updateDraft` and reviewed publishing share the same object-level ownership check: the current user must be the AI owner (native author or `_jg_ai_owner_user_id`) with the editable grant, or hold native WordPress `edit_post`. The `_jg_ai_editable` mark alone grants read, not update or publish. A diary created through `POST /content` is authored by and owned by the calling AI user.

`article` uses the same pipeline with its own capability (`jg_ai_publish_article_drafts`) and settings ("审核制文章发布", "AI 自建文章自动允许进入受控发布流程"). The diary capability never grants article publishing and vice versa. Article updates are limited to `title`, `content`, `excerpt`, and `slug`.

## Published in-place update protocol

Modifying an already published diary or article is a high-risk write and must use `prepare-update-published` then `update-published`. Read the latest object first for `modifiedAt`, `ownership`, and `availableOperations`. Prepare with `proposedTitle`/`proposedExcerpt`/`proposedContent`, show the user the change summary and protected fields, wait for the exact confirmation phrase, then execute with the same fields, the one-time token, the unchanged `expectedModifiedAt`, and a stable idempotency key. Never use `updateDraft` for published content, never change slug or publish dates, never unpublish/re-publish, and never call GitHub. Report WordPress update success and deployment status separately.

When the server's auto-publishable setting is enabled, a diary created through the AI Content API arrives already marked publishable, so the WeChat flow can continue to `prepare-publish` without a WordPress admin step. The mark only means eligibility for the two-stage flow; publication still requires the confirmation token, unchanged `expectedModifiedAt`, a stable idempotency key, and the exact user confirmation phrase. Never treat an auto-marked draft as published.

## Deployment status checks

After a publish, read `GET /content/diary/{id}/deployment-status` to separate WordPress state, dispatch acceptance, GitHub build state, front-end deployment, and public page availability. Never report "博客前台已上线" from WordPress `publish` or from a GitHub `workflow_dispatch` acceptance. `deploymentStatus=deployed` and `pageStatus=reachable` are the only positive front-end signals; `pageStatus=reachable` still does not prove the newest content is served. The endpoint is read-only: it never triggers builds and never returns tokens or raw GitHub responses.

1. Read the latest diary and show the user the title, slug, excerpt, and intended publication action.
2. After explicit user confirmation, call `POST /content/diary/{id}/prepare-publish`.
3. Use the returned token once, before `expiresAt`, with the exact returned `modifiedAt` and a stable `Idempotency-Key` in `POST /content/diary/{id}/publish`.
4. On `409`, read the diary again and ask for confirmation again. Never reuse a stale confirmation.

Never print or persist the confirmation token in logs, prompts, task records, or local files. Never call GitHub or `workflow_dispatch` from the Agent. A successful publish only means WordPress accepted the state change; deployment continues through the existing debounced automation.

本约定同时适用于 Codex、OpenClaw 和其他 Blog Agent。权威的公开 API 说明是 [AI Content API](../ai-content-api.md)；运行时以 `GET /wp-json/jaisong1n/v1/ai/capabilities` 返回的版本、字段、枚举和操作权限为准，不猜测字段，也不使用 WordPress 内部 meta key。

## Credentials And Transport

需要 `WP_BASE_URL`、`WP_API_USERNAME` 和 `WP_API_APPLICATION_PASSWORD`，只检查是否存在，绝不打印值、Application Password、Authorization header、Basic 编码、token、cookie 或环境变量内容。要求 HTTPS；凭据只能存在于 Agent 的 secret 或环境配置，不能写入仓库、SKILL.md、提示词或日志。

## Content Types And Operations

支持的 `contentType` 为 `article`、`diary`、`project`、`timeline`、`skill`、`aiTool`、`friend`、`announcement`、`techRadar` 和 `learningResource`。`page` 与 `album` 被拒绝，且没有删除接口。

- 创建：`POST /content`，默认 draft，必须使用稳定且调用方/操作范围内唯一的 `Idempotency-Key`。
- 读取：`GET /content` 或 `GET /content/{contentType}/{id}`，用于确认 ID、归属、类型、slug、状态、正文和修改时间。
- 更新：只有 capabilities 明确包含 `updateDraft` 的 `diary` 草稿可使用 `PATCH /content/diary/{id}`。请求只能提交发生实际变化的 `title`、`content`、`excerpt`、`slug`，并原样携带最新读取结果中的 `expectedModifiedAt`（包括显式 `null`）；过期值为冲突，不覆盖他人或已发布内容。
- 发布或取消发布：只使用 capabilities 声明的独立端点；适用前提由 [Publishing Policy](publishing-policy.md) 定义。

相同幂等键的重放必须复用原结果；冲突复用应处理为 `409`。权限拒绝、并发冲突、限流或其他错误应停止并报告安全的摘要，不尝试其他凭据或绕过限制。接口不会调用 GitHub。
