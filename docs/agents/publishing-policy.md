# Publishing Policy

## Reviewed diary publishing

Site Manager 0.9.0 requires a two-stage server flow for AI diary and article publishing. A direct status update is prohibited. The Agent must first obtain a short-lived confirmation token from `prepare-publish`, then submit that token with the unchanged `expectedModifiedAt` and a stable idempotency key to `publish`.

The preparation step is not publication and must not be described as success. A content change after preparation invalidates the confirmation and requires a new read, a new user confirmation, and a new token. The Agent must not expose or retain confirmation tokens, must not attempt unpublishing, and must not invoke GitHub deployment APIs directly.

Draft updates and reviewed publishing share one object-level authorization: AI ownership (native author or AI-owner meta) plus the editable grant, or native WordPress `edit_post`. The editable grant alone is read-only; the publishable mark alone never permits publishing another user's diary.

Publish confirmation must be followed by a deployment status read, not by an assumption that the front-end is live. `dispatchStatus=accepted` does not mean the build succeeded, GitHub build success does not mean Cloudflare has deployed, and a reachable public page does not prove the newest content is served. Only the deployment status endpoint's positive `deploymentStatus` and `pageStatus` signals may be reported as front-end availability.

An auto-publishable mark on an AI-created diary draft only admits it to the two-stage flow. It is not publication, does not trigger a build, and never applies to manually created, imported, or other-author content.

Article publication uses the same two-stage rules with the separate `jg_ai_publish_article_drafts` capability; an auto-publishable article mark is eligibility only, never automatic publication.

本政策适用于 Codex、OpenClaw 和任何未来 Blog Agent。

## Intent And Quality

- “写”只生成内容；“保存到博客”默认创建草稿；只有明确“发布”才进入发布流程。
- 写入前完成适用的风格检查，并满足重复检查、事实核验和 API 能力要求。
- 用户的模糊措辞不能绕过权限、安全或发布边界。

## Permission And Failure Boundaries

- 默认草稿优先。发布必须使用独立发布接口，并同时满足服务端发布开关、内容发布授权、当前 `expectedModifiedAt` 和能力声明。
- 不得以 `PATCH status=publish` 或其他客户端技巧绕过服务端权限。
- 发布被拒绝或失败时保持草稿，不重试其他凭据，不绕过权限，并返回可用的编辑地址（如接口响应提供）。
- 除非用户明确请求更新已有内容，否则创建新的草稿；更新必须验证归属和并发版本。

## Prohibited Operations

不得删除内容、处理相册、创建相册、修改固定页面、管理用户、插件、主题或站点设置。不得手动触发 GitHub、强制重建或声称部署已经成功。成功发布只应说明现有保存或状态自动化路径可能继续处理构建。
