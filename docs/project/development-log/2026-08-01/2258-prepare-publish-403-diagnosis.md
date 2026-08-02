# Site Manager 0.8.0 prepare-publish 403 诊断

- 时间：2026-08-01 22:58（Asia/Shanghai）
- 会话或模块：Codex / Site Manager 0.8.0 生产授权排查
- 当前分支：`codex/ai-content-api-0-7`（HEAD `2753ca5`）
- 状态：诊断完成；生产数据修复待管理员授权
- 是否已提交：否，本地未提交（本日志与既有未跟踪项目文档一致）
- 是否已部署：否；未执行任何 publish

## 任务目标

定位生产环境 `POST /wp-json/jaisong1n/v1/ai/content/diary/82/prepare-publish` 返回 HTTP 403 `jg_ai_publish_forbidden` 的精确失败条件，在不削弱安全边界的前提下给出修复路径；禁止执行真正 publish。

## 实际完成（仓库可确认）

- 0.8.0 源码（HEAD `2753ca5`；插件目录与 HEAD 无差异）中，`prepare_publish` 在返回 403 前依次执行：contract 校验 → diary 类型校验 → `post_for_contract` → `can_publish()` → draft 状态校验。
- `can_publish()` = `can_publish_type()` 且 `current_user_can('edit_post', id)` 且 `can_read()` 且 `(bool) get_post_meta(id, '_jg_ai_publishable', true)`。
- `can_publish_type()` = diary 且 `settings()['reviewed_diary_publish']` 非空且 `current_user_can('jg_ai_publish_diary_drafts')`；capabilities 端点与 prepare/publish handler 复用同一授权函数。
- 设置关闭、用户无 capability、文章未标记三种原因合并为同一个 403 `jg_ai_publish_forbidden`，消息统一为 "Reviewed diary publishing is not enabled or authorized."，响应无法区分具体原因。
- `_jg_ai_publishable` 在 `createDraft` 时默认写 `false`；只有 claim 端点（需 `manage_options`）和后台元盒（需 `manage_options`）可改为 `true`。

## 生产验证结果（只读；使用 WP_API_* 环境凭据，未记录任何值）

- `GET /jaisong1n/v1/ai/capabilities`：HTTP 200，version=`0.8.0`，schemaVersion=`5`；diary.operations 含 `createDraft, read, updateDraft, preparePublish, publish`；article 仅 `createDraft, read`，其他类型无发布操作。
- `GET /jaisong1n/v1/ai/content/diary/82`：HTTP 200；status=`draft`；modifiedAt=`2026-08-01T12:21:24Z`。
- `GET /wp/v2/users/me?context=edit`：用户 ID 2，角色 `jg_ai_content_editor`；capabilities：`jg_ai_publish_diary_drafts=true`、`edit_jg_diarys=true`、`edit_posts=true`；无任何 `edit_others_*`。
- `PATCH /jaisong1n/v1/ai/content/diary/82`（无字段变化并携带正确 `expectedModifiedAt`）：HTTP 404 `jg_ai_content_not_found`；换用错误 `expectedModifiedAt` 仍 404。该 404 来自 `update_content` 的 `can_update()` 失败路径，证明 `current_user_can('edit_post', 82)` 为假，与内容版本无关。
- `GET /wp/v2/jg_diary/82?context=edit`：HTTP 403 `rest_forbidden_context`（"抱歉，您不能修改这篇文章。"），与 edit_post 失败一致。
- `GET /jaisong1n/v1/site-snapshot`：HTTP 200；不含 `_jg_ai_publishable`，也不含 ID 82（草稿不进公开快照）。

## 结论：403 的精确失败条件

- 全局设置与用户能力均通过：`jg_ai_content_settings['reviewed_diary_publish']` 生效（capabilities 出现 `preparePublish`/`publish` 即证明），用户 ID 2 实时拥有 `jg_ai_publish_diary_drafts`。
- 文章级失败：草稿 82 非用户 ID 2 所有，AI 角色按设计不拥有 `edit_others_jg_diarys`，因此 `current_user_can('edit_post', 82)` 为假，`can_publish()` 在进入 `_jg_ai_publishable` 检查前已返回 false。
- `_jg_ai_publishable` 原始值无法经现有 API 通道读取（无管理员凭据/会话，且不在公开快照）；即使该 meta 已为 true，edit_post 失败仍会产生 403。因此草稿作者归属与 meta 标记是修复所需的两个数据条件。

## 修复路径（生产数据，需管理员操作；不修改代码）

1. WordPress 后台编辑日记 82，将"作者"改为 jaisong1n-ai-writer（用户 ID 2），使 `edit_post` 通过。
2. 勾选 "Allow AI Content Assistant to publish"（保存后 `_jg_ai_publishable` 存储为 `1`，代码以 `(bool)` 读取，格式匹配）。
3. 保存草稿；WordPress 会在保存时刷新 post meta 与用户能力缓存。
4. 不授予 `edit_others_jg_diarys`、不授予管理员权限、不放开文章级限制、不执行 publish。

## 代码一致性结论

- capabilities 与 prepare/publish handler 共享 `can_publish_type`，未发现重复或错位的授权函数。
- option 键 `reviewed_diary_publish`、capability `jg_ai_publish_diary_drafts`、meta `_jg_ai_publishable` 在各处名称一致，未发现名称或值格式错配。
- 可改进项（非本次 403 根因）：三种失败原因合并为同一错误码，无法从响应区分；如需可改为区分错误码并补测试。本会话未改动代码。

## 验证边界（修复后的验收，尚未执行）

- 修复后以 jaisong1n-ai-writer 执行：capabilities → `POST diary/82/prepare-publish` → 回读 diary/82 → 审计。
- prepare 应返回 200；只报告 token 字段名、存在性、长度与不可逆短指纹，不输出明文。
- 当前草稿 82 仍为 draft（已确认）；尚未生成 confirmation token；未触发构建（prepare 路径不含 dispatch 调用，代码可确认）。

## 修改文件

- `docs/project/development-log/2026-08-01/2258-prepare-publish-403-diagnosis.md`（新增，本日志）
- 未修改任何业务代码、插件文件或 WordPress 数据。

## 资料来源

- 0.8.0 源码：`wordpress-plugin/jaisong1n-site-manager/includes/class-jg-ai-content.php`（HEAD `2753ca5`）。
- 生产 API 只读调用：capabilities、content/diary/82、users/me、jg_diary/82、site-snapshot。

禁止记录凭据、Token、Authorization、Cookie、Application Password、环境变量值和私密用户数据；本日志未记录这些信息。
