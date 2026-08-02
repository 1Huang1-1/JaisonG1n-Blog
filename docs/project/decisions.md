# 重要技术决策

## 2026-08-02：AI 自建日记草稿自动进入受控发布流程（Site Manager 0.8.3）

### 问题

AI 通过 createDraft 创建的 diary 草稿仍需管理员进入 WordPress 后台逐篇勾选 publishable，破坏“微信创建→确认→发布”的完整流程。

### 最终选择

在“内容安全”新增开关 `auto_publishable_ai_diaries`（默认关闭）。开启后，仅当 createDraft 创建时同时满足：contentType=diary、状态 draft、`reviewed_diary_publish` 已开启、当前用户拥有 `jg_ai_publish_diary_drafts`、`post_author` 与 `_jg_ai_owner_user_id` 均为当前用户，才自动写入 `_jg_ai_publishable=1`。

自动标记只意味着进入两阶段发布流程；`preparePublish`、一次性 confirmationToken、`expectedModifiedAt`、幂等键、精确确认短语与 status=draft 检查全部保留。后台人工创建、导入内容、其他作者内容、非 diary 类型、缺少 capability、全局设置关闭时一律不自动标记，仍需管理员逐篇授权。

### 放弃或暂缓的方案

- 不对历史草稿做批量修改；已手动设置的 `_jg_ai_publishable` 保持不变。
- 不给角色增加 `edit_others_*`，不自动发布，不扩大管理员权限。

### 影响

schemaVersion 保持 5；0.8.2 → 0.8.3 升级保留设置、内容与 dispatch history。

### 后续条件

生产启用该开关前必须确认 reviewed diary publishing 与 `jg_ai_publish_diary_drafts` 已就绪；改变自动标记条件时需同步更新 API 文档、安全模型与测试。

## 2026-08-02：部署状态以五层分离并通过可信探测确认（Site Manager 0.8.2）

### 问题

AI 发布后缺少服务端可查询的构建/部署状态，OpenClaw 只能猜测前台是否上线；GitHub 接受 `workflow_dispatch` 与构建成功、GitHub 构建成功与 Cloudflare 部署完成均不是同一回事。

### 最终选择

在 AI Content API 中提供只读 `GET /content/{type}/{id}/deployment-status`，权限与内容读取一致，不要求 `manage_options`。状态五层分离：`wordpressStatus`、`dispatchStatus`、`buildStatus`、`deploymentStatus`、`pageStatus`。

- dispatch 记录扩展自 `jg_dispatch_pending`/`jg_dispatch_history`/`jg_dispatch_status`，不新建表；debounce 期间累积多内容 `contentRefs`，查询按“包含该内容引用且 triggeredAt 不早于内容最后修改时间”的最新记录关联，禁止把全站最新构建硬绑定到任意内容。
- GitHub Actions run 查询使用官方 run API，20 秒缓存；403/404/429/500/网络错误保留最后已知状态并记录脱敏错误，不自动重跑或触发。
- `workflowRunId` 只从 200 响应解析；204 只记 `dispatchStatus=accepted` 且 runId 为 null，不伪造。
- canonical public URL 由服务端生成（diary `/diary/{slug}/`、article `/posts/{slug}/`，基址取配置的 `public_site_url`），与 WordPress CMS 编辑地址分离。
- 页面探测仅允许配置的生产博客域名、限制重定向、限制下载大小并设置超时；`deploymentStatus=deployed` 仅在构建成功且页面探测 reachable 时给出；`pageStatus=reachable` 不代表内容为最新版本。

### 放弃或暂缓的方案

- 不接 Cloudflare Pages API（本版本无部署 ID 查询），不把 GitHub success 直接映射为 deployed。
- 不在 Astro 前端大规模增加 build ID/commit 标识（列为后续版本）。
- 不开放任何可触发构建、取消构建或读取 GitHub token 的接口；状态查询不得修改内容。

### 影响

capabilities 新增只读 `deploymentStatus` operation，schemaVersion 保持 5（向后兼容新增）。dispatch 历史记录结构扩展但仍兼容旧条目（无 contentRefs 的旧记录不会被硬绑定）。

### 后续条件

若接入 Cloudflare Pages API 或增加页面 build ID，必须同步更新契约文档、安全模型与测试。

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
