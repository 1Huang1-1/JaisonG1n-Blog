# OpenClaw Blog Agent：已发布内容原地修改实施规范

状态：Site Manager 0.10.1 服务端已实现；生产已部署 0.10.0 并开启两个开关。**OpenClaw 侧实现必须等待实时 capabilities 出现 `prepareUpdatePublished` / `updatePublished` 后再启用**，不得伪造支持。

## 1. 前置条件检查

```text
GET /wp-json/jaisong1n/v1/ai/capabilities
```

- diary.operations 必须同时包含 `prepareUpdatePublished` 与 `updatePublished`（article 同理）。
- 缺失任一 operation 时停止，并向用户说明“服务端尚未开放已发布内容修改”，不要调用任何替代接口。
- diary 与 article 的能力互相独立；目标对象必须在其类型自己的 capabilities 中具备该操作。

## 2. Tool 设计

新增两个 Tool（diary/article 共用，用 contentType 区分）：

```text
blog_prepare_update_published({ contentType, contentId, proposedTitle?, proposedExcerpt?, proposedContent? })
blog_update_published({ contentType, contentId, confirmationPhrase, proposedTitle?, proposedExcerpt?, proposedContent? })
```

Transport 层负责：读取最新内容 → 校验 capabilities/ownership/availableOperations → 调用服务端 prepare/execute → 解析错误码 → 查询 deployment-status。模型与用户永远不接触 `confirmationToken`（只在运行时内存中传递）。

## 3. 流程

1. `GET /content/{type}/{id}`，记录 `modifiedAt`、`ownership`、`availableOperations`。
2. 校验：status=publish、`availableOperations` 含 `updatePublished`、用户为作者或 AI owner。
3. `POST /content/{type}/{id}/prepare-update-published`：携带 `expectedModifiedAt` 与至少一个变更的 `proposedTitle`/`proposedExcerpt`/`proposedContent`。
4. 展示变更前后标题、摘要/正文变更概览、受保护字段（slug、发布时间、作者等）、精确确认短语；等待用户输入**完全一致**的短语。
5. 用户回复普通“确认”“可以”“改吧”不执行；必须与 `confirmationPhrase` 逐字符一致。
6. `POST /content/{type}/{id}/update-published`：携带 `expectedModifiedAt`（服务端 prepare 时的值）、`confirmationToken`、稳定 `Idempotency-Key` 与相同 proposed 字段。
7. 回读验证：ID、slug、status=publish、publishedAt 不变，title/excerpt/content 已更新，modifiedAt 变化。
8. `GET /content/{type}/{id}/deployment-status`，分别报告 wordpressStatus、dispatchStatus、buildStatus、deploymentStatus、pageStatus、publicUrl；不得把内容更新成功描述为前台上线。

## 4. 错误码处理

- `jg_ai_stale_content` / `jg_ai_update_published_conflict` / `jg_ai_confirmation_token_conflict`：重新读取、重新预览、重新确认。
- `jg_ai_confirmation_token_expired` / `used` / `invalid`：重新 prepare。
- `jg_ai_update_published_ownership_required` / `not_editable`：停止并报告权限原因。
- `jg_ai_update_published_disabled` / `forbidden`：停止，服务端未开放。
- `jg_ai_protected_field_changed` / `readback_verification_failed`：报告失败，绝不重试、绝不重新发布补救。

## 5. 禁止行为

- 不得调用 `updateDraft` 修改 publish 内容、不得新建副本、不得改 slug/发布时间、不得取消发布再发布、不得直接调用 publish、不得直接调用 GitHub。
- 不得在 capabilities 缺失时启用 operation。
- 不得把 confirmationToken 写入日志、对话历史或 SKILL.md。

## 6. 验收（服务端部署后）

使用专用标记的 AI 验收 diary/article（不使用正式文章），覆盖：prepare→精确确认→execute→回读→deployment-status 全链路，以及能力缺失、权限拒绝、过期/篡改/重用 token、幂等重放、受保护字段回归。
