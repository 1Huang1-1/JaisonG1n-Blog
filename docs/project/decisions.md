# 重要技术决策

## 2026-08-01：AI 内容管理统一使用所有权授权模型（Site Manager 0.8.1）

### 问题

Site Manager 0.8.0 中 `can_publish()` 额外要求 `current_user_can('edit_post', post_id)`，而 AI 角色的读取/编辑授权使用 `_jg_ai_owner_user_id` 与 `_jg_ai_editable`。当 AI 已授权的草稿原生作者与 AI 所有者不一致时（例如管理员创建的测试草稿），`prepare-publish` 即使通过全局设置、发布 capability 和文章级 publishable 检查，仍返回 403。

### 最终选择

引入统一的文章级授权函数 `can_manage_ai_content()`，`updateDraft` 与 reviewed publish 共用：原生 `edit_post` 通过，或（当前用户是 AI 所有者：原生作者或 `_jg_ai_owner_user_id`，且 `_jg_ai_editable` 为真）。发布仍额外要求 `reviewed_diary_publish` 开启、用户拥有 `jg_ai_publish_diary_drafts`、内容类型为 diary、状态为 draft、`_jg_ai_publishable` 为真。

拒绝时外部统一返回 403 `jg_ai_publish_forbidden`，审计记录细分原因：`setting_disabled`、`missing_publish_capability`、`ownership_denied`、`edit_denied`、`not_publishable`、`not_draft`。

### 放弃或暂缓的方案

- 不简单删除 `edit_post` 检查；它保留为附加允许条件，避免依赖 `edit_others_jg_diarys` 扩大 AI 角色范围。
- 不允许仅凭 `_jg_ai_publishable` 发布其他用户的文章；editable 标记只授予读取，不授予写入或发布。
- 不批量改写普通日记作者。仅当 `_jg_ai_owner_user_id` 有效、`_jg_ai_created` 为真、作者与所有者不一致时，管理员可通过受控修复（后台按钮或 `repair_ai_ownership()`）同步作者。

### 影响

AI 创建的草稿在其所有者名下始终可更新和进入受控发布流程；管理员创建并标记 editable 的草稿仍只读，除非显式修复所有权。schemaVersion 保持 5；无数据迁移要求。

### 后续条件

改变文章级授权语义或审计原因枚举时，必须同步更新 API 文档、安全模型、测试与 Agent 使用规则。

## 2026-08-01：AI 日记发布采用服务端两阶段确认

Site Manager 0.8.0 将受控日记发布与草稿修改、WordPress 原生发布权限分离。AI 角色默认不具备发布权限；管理员开关只授予 `jg_ai_publish_diary_drafts`，且每篇日记仍需单独标记为允许发布。

发布必须先调用 `prepare-publish` 获取 10 分钟有效的一次性令牌，再携带未变化的 `expectedModifiedAt` 和幂等键调用 `publish`。服务端只存储令牌 SHA-256 摘要，并将其绑定到用户、日记 ID、内容版本和 publish 动作。

Agent 不直接调用 GitHub；WordPress 发布成功后只进入既有防抖构建链路。该设计避免草稿修改权限隐式升级为发布权限，阻止过期确认覆盖新内容，并保证重试不会重复发布或重复创建构建 pending。

仅记录重要架构、权限、安全和兼容性决策。以下条目根据本地 Git 历史、插件 changelog、实现和项目文档补录；不包含凭据。

## 2026-07-31：以 workflow_dispatch 作为 WordPress 自动构建入口

### 问题

WordPress 的公开内容变化需要触发 Astro 构建，同时避免重复或过期部署。

### 最终选择

Site Manager `0.6.0` 通过 GitHub `workflow_dispatch` 触发构建，使用修订去重、防抖、重试状态和工作流中的过期提交检查。

### 放弃或暂缓的方案

`repository_dispatch` 仅作为旧外部调用方的兼容触发保留；当前插件不再发出该事件。

### 选择原因

插件 changelog 和自动构建文档将 `workflow_dispatch` 记为当前实现，并明确记录兼容事件不由当前插件发出。

### 影响

构建调度与已发布内容保存/状态自动化衔接；实际部署成功仍需以 GitHub Actions 和部署平台记录确认。

### 后续条件

修改触发协议、权限范围或部署分支前，应复核插件调度实现和工作流的过期部署保护。

## 2026-07-31：AI 写入使用专用角色、最小权限与默认草稿

### 问题

Agent 需要创建和维护内容，但不应获得站点管理或默认发布权限。

### 最终选择

使用 `jg_ai_content_editor` 专用角色；默认允许创建和更新草稿，发布默认关闭，且需要管理员授权、发布能力、服务端开关和独立发布接口。

### 放弃或暂缓的方案

未将发布或破坏性能力默认授予 AI 角色；不允许通过草稿更新接口改变已发布内容。

### 选择原因

AI Content API 实现、插件 `0.7.0` changelog 和发布规则均要求草稿优先及服务端权限边界。

### 影响

Agent 写入需经过人工审核和显式发布流程，不能把“写”或“保存”视为发布授权。

### 后续条件

若要放开发布，必须同时审查服务端设置、按内容的授权、并发版本和审计要求。

## 2026-07-31：AI Content API 采用白名单、幂等与乐观并发控制

### 问题

多次重试或并行 Agent 写入可能造成重复草稿、字段越权或覆盖较新的内容。

### 最终选择

仅接受公开契约定义的字段；创建要求幂等键，更新和发布要求 `expectedModifiedAt`，过期内容返回冲突。

### 放弃或暂缓的方案

不暴露内部元键、站点配置或任意字段写入；不以静默覆盖解决并发冲突。

### 选择原因

AI Content API 文档和实现均记录了字段白名单、调用者范围的幂等重放和 `expectedModifiedAt` 检查。

### 影响

调用方必须先读取 capabilities 和当前内容，再以稳定幂等键及当前修改时间写入；审计与限流提供有限的操作追踪和保护。

### 后续条件

改变内容契约或并发语义时，必须同步更新 API 文档、测试和 Agent 使用规则。

## 2026-07-31：共享 Agent 规则，排除相册与删除

### 问题

Codex、OpenClaw 和未来 Agent 需要一致的写作与写入边界，且相册和删除操作的风险较高。

### 最终选择

公共规则集中在 `docs/agents/`；AI Content API 拒绝 `album` 和 `page`，不提供删除端点。OpenClaw 当前仅有接入指南，不创建重复维护的规则副本。

### 放弃或暂缓的方案

不为 Codex 或 OpenClaw 维护彼此分叉的写作规则；不开放 AI 相册写入、删除或站点设置管理。

### 选择原因

`a13a27f` 引入共享规则，OpenClaw 指南要求复用这些文件；AI API 文档和发布政策明确排除相册与删除。

### 影响

Agent 使用范围被限制在明确的内容类型和草稿流程内，避免通过通用接口触及高风险操作。

### 后续条件

OpenClaw 正式接入或扩展内容类型前，需验证其规则同步方式、最小权限和服务端 API 能力。
