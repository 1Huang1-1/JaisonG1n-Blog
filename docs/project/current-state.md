# Current project state

## 2026-08-03 Site Manager 0.11.0 内容浏览量（本地实现）

- 插件代码版本为 `0.11.0`，公开快照 `schemaVersion` 继续为 `5`。
- 新增公开只写接口 `POST /wp-json/jg-public/v1/content/{contentType}/{id}/view`（仅 article/diary 且 status=publish）：请求体 `eventId` 必须为 UUID，相同 eventId 不重复计数；浏览量只写入独立统计表，不修改 `wp_posts`、不进入 dispatch/构建链路。
- 统计结构：`jg_content_stats`（content_type/content_id/view_count/updated_at，联合主键）与 `jg_view_events`（event_hash 主键；SHA-256 绑定 contentType:contentId:eventId；TTL 30 天；1% 概率清理）。计数使用 `INSERT IGNORE` + `ON DUPLICATE KEY UPDATE` 原子自增。
- 防刷与隐私：不保存 eventId 明文与原始 IP；按 IP 哈希 60 次/分钟限流；明显机器人 UA 不计数；CORS 仅允许 `https://jaisong1n.com`、`https://www.jaisong1n.com` 与 `http://localhost:4321`、`http://localhost:3000`；请求体 ≤1KB。
- 前端：article（`/posts/{slug}/` 与自定义 permalink 页）和 diary 详情页在标题右侧显示浏览量（桌面）/元信息行（移动端）；通过 history.state + `crypto.randomUUID()` 生成事件 ID，刷新/后退/前进复用同一事件，离开详情页后重新进入生成新事件；页面可见满 1 秒才提交；请求失败仅显示占位不影响正文；移除旧 Umami 页面浏览量展示块（Umami 分析采集保留）。
- 本地测试：content-stats Playground 45 断言（首次 +1、同 eventId 去重、跨内容同 eventId 隔离、数字/ASCII slug/编码与解码 CJK slug 解析、草稿与缺失拒绝、bot 不计数、限流 429、并发原子性、CORS/OPTIONS、modifiedAt 不变、无 dispatch）；AI Content Playground 222 断言、deployment-status 108 断言、smoke、0.10.1→0.11.0 升级（新建两表）、`pnpm test` 73 项全通过、`pnpm check` 322 文件 0 错误。
- 本版本为本地实现与本地测试结果；尚未安装到真实 WordPress，未执行生产验收。生产实时版本仍为 `0.10.1`。

## 2026-08-03 Site Manager 0.10.1 合并批次 dispatch 关联修复（本地实现）

- 插件代码版本为 `0.10.1`，公开快照 `schemaVersion` 继续为 `5`。
- 修复已发布内容原地修改后 deployment-status 关联：dispatch 记录新增实际 `dispatchedAt`；旧记录（无 dispatchedAt）回退可信的 `lastCheckedAt`（记录创建时间=实际 dispatch 尝试时间），再回退批次 `triggeredAt`。
- 新记录与历史记录均能正确关联到合并批次中后加入的 diary/article 变更，无需重新修改内容或重复 dispatch；时间早于内容变更的记录仍明确不可追溯（返回无记录）。
- 本地测试：部署状态 Playground 113 条断言（新增历史记录兼容：新格式带 dispatchedAt、旧格式无 dispatchedAt、后加入内容、可关联/不可追溯、不影响新记录、不重复 dispatch）、AI Content 222 条断言、smoke、0.10.0→0.10.1 升级、`pnpm test`、`pnpm check` 320 文件零问题。
- 生产实时版本仍为 `0.10.0`；0.10.1 为待部署热修复包。

## 2026-08-03 Site Manager 0.10.0 已发布内容原地修改（本地实现）

- 插件代码版本为 `0.10.0`，公开快照 `schemaVersion` 继续为 `5`。
- 新增 `prepareUpdatePublished` / `updatePublished`（diary 与 article 分别声明）：两阶段准备→精确确认→执行；只允许修改 title/excerpt/content。
- 受保护字段策略：ID、contentType、slug、status、post_date、post_date_gmt、作者与 AI 所有权元数据在更新前后逐项比对；变化返回 `jg_ai_protected_field_changed` / `jg_ai_readback_verification_failed`，不执行重新发布补救。
- token 绑定用户/类型/对象/expectedModifiedAt/内容哈希/update_published action，10 分钟一次性；幂等键防重放；成功后进入既有防抖构建与部署状态跟踪。
- 独立 capability `jg_ai_update_published_diaries` / `jg_ai_update_published_articles` 与“审核制已发布日记修改”“审核制已发布文章修改”开关（默认关闭）；diary/article 隔离，不授予原生权限。
- read 契约补充稳定 `publishedAt`、`canonicalUrl`、安全 ownership 信息（isAuthor/isAiOwner/aiOwned/editable）与 `availableOperations`。
- 本地测试：AI Content Playground 222 条断言（含 diary/article 原地修改、受保护字段、token、幂等、并发、能力隔离）、部署状态 97 条断言、smoke、0.9.0→0.10.0 升级（默认关闭、不扩大权限）、`pnpm test` 全通过、`pnpm check` 320 文件零问题。
- 本版本为本地实现与本地测试结果；尚未安装到真实 WordPress，未执行生产验收。生产实时版本仍为 `0.9.0`。

## 2026-08-02 Site Manager 0.9.0 article 受控发布（本地实现）

- 插件代码版本为 `0.9.0`，公开快照 `schemaVersion` 继续为 `5`。
- article 新增 `updateDraft`（title/content/excerpt/slug 白名单、draft 限定、expectedModifiedAt 并发、no-op 拒绝、非 owner 404、审计只记字段名）。
- article 新增受控发布：独立 capability `jg_ai_publish_article_drafts`，内容安全新增“审核制文章发布”与“AI 自建文章自动允许进入受控发布流程”（默认关闭）；不授予原生 publish_posts/edit_others。
- prepare/publish/token/幂等/部署状态参数化为内容类型；token 绑定 contentType/ID/modifiedAt/action；发布只产生一次构建 pending；diary 与 article 权限互相独立。
- 自动 publishable 条件：AI API 创建、draft、作者与 AI owner 均为当前用户、article 受控能力与开关开启；后台人工/导入/其他作者/仅 editable 不自动标记。
- 本地测试：AI Content Playground 171 条断言（含 article 更新/权限/token/幂等/自动资格）、部署状态 97 条断言（含 article canonical URL 与记录关联）、smoke、0.8.3→0.9.0 升级（默认关闭、不扩大权限）、`pnpm test` 全通过、`pnpm check` 320 文件零问题。
- 本版本为本地实现与本地测试结果；尚未安装到真实 WordPress，未执行生产验收。生产实时版本仍为 `0.8.3`。

## 2026-08-02 Site Manager 0.8.3 自动受控发布资格（生产已验收）

- 插件代码版本为 `0.8.3`，公开快照 `schemaVersion` 继续为 `5`。
- 新增内容安全开关 `auto_publishable_ai_diaries`（默认关闭）：AI 通过 AI Content API 自建 diary 草稿时，在同时满足 contentType=diary、状态 draft、`reviewed_diary_publish` 开启、用户拥有 `jg_ai_publish_diary_drafts`、`post_author` 与 `_jg_ai_owner_user_id` 均为当前用户时，自动写入 `_jg_ai_publishable=1`。
- 自动标记仅授予两阶段发布流程资格；confirmationToken、expectedModifiedAt、幂等键、精确确认与 draft 检查全部保留；不自动发布、不触发构建。
- 后台人工创建、导入、其他作者、非 diary、缺 capability、设置关闭时不自动标记；历史草稿不批量修改；已手动设置保持不变。
- 本地测试：AI Content Playground 129 条断言、部署状态 94 条断言、smoke、0.8.2→0.8.3 升级（默认关闭且不批量改历史）、`pnpm test` 55/55、`pnpm check` 320 文件零问题。
- 用户已在真实环境确认端到端验收：AI 自建 diary 自动获得受控发布资格、preparePublish 成功、精确确认后只发布一次、deployment-status 正确关联 workflowRunId、GitHub build success、deployment deployed、page reachable、canonical publicUrl 正确。
- 生产实时版本为 `0.8.3`；0.8.1/0.8.2 与 0.8.3 实现已合并归档到主分支（本归档会话按用户指令执行）。

## 2026-08-02 Site Manager 0.8.2 部署状态 API（本地实现）

- 插件代码版本为 `0.8.2`，公开快照 `schemaVersion` 继续为 `5`。
- 新增只读 `GET /content/{type}/{id}/deployment-status`：Application Password 认证、与内容读取相同的对象权限、不要求 `manage_options`、不触发构建。
- 状态五层分离：`wordpressStatus` / `dispatchStatus` / `buildStatus` / `deploymentStatus` / `pageStatus`；`workflowRunId` 只从 GitHub 200 响应解析，204 不伪造；GitHub success 不直接映射 deployed，需可信页面探测确认。
- dispatch 记录扩展：`jg_dispatch_pending` 累积多内容 `contentRefs`，`jg_dispatch_history` 保存完整记录（triggerId/source/contentRefs/workflowRunId/runUrl/时间戳/错误），`jg_dispatch_status` 保留旧面板视图；无 contentRefs 的旧记录不会被硬绑定到内容。
- GitHub Actions run 查询 20 秒缓存；403/404/429/500/网络错误保留最后已知状态并返回脱敏错误。
- canonical public URL：diary `/diary/{slug}/`、article `/posts/{slug}/`，基址取 `JG_Settings::public_site_url`（默认 `https://jaisong1n.com`）；空 slug、含路径分隔符与不支持类型返回 null；与 CMS editUrl 分离。
- 页面探测仅允许配置的生产域名、限制重定向、64 KiB 上限与 10 秒超时；capabilities 为可读类型新增只读 `deploymentStatus`。
- 本地测试：部署状态 Playground 94 条断言、AI Content 111 条断言、smoke、0.8.1→0.8.2 升级、`pnpm test` 55/55、`pnpm check` 320 文件零问题。
- 本版本为本地实现与本地测试结果；尚未安装到真实 WordPress，未执行生产验收。生产实时版本仍为 `0.8.1`。

## 2026-08-01 Site Manager 0.8.1 权限模型修复状态（本地实现）

- 插件代码版本为 `0.8.1`，公开快照 `schemaVersion` 继续为 `5`。
- 统一 `updateDraft` 与 reviewed publish 的文章级授权：`can_manage_ai_content()` 通过当且仅当原生 `edit_post` 通过，或（当前用户是 AI 所有者：原生作者或 `_jg_ai_owner_user_id`，且 `_jg_ai_editable` 为真）。
- 发布仍额外要求 `reviewed_diary_publish` 开启、`jg_ai_publish_diary_drafts` capability、diary 类型、draft 状态与 `_jg_ai_publishable` 为真；不依赖 `edit_others_jg_diarys`。
- 拒绝时外部统一返回 403 `jg_ai_publish_forbidden`；审计记录细分原因：`setting_disabled`、`missing_publish_capability`、`ownership_denied`、`edit_denied`、`not_publishable`、`not_draft`。
- 新增管理员受控所有权修复：`repair_ai_ownership()` 与后台"同步作者为 AI 所有者"按钮，仅在 `_jg_ai_owner_user_id` 有效、`_jg_ai_created` 为真且作者与所有者不一致时生效；不批量改写普通日记作者。
- 本地测试覆盖：AI 草稿作者写入、owner 与作者一致、AI owner 可 updateDraft/preparePublish、非 owner 拒绝、设置关闭/缺 capability/未标记/非 draft 拒绝、prepare 不改变状态且不触发构建、0.8.0→0.8.1 升级不扩大权限。
- 本版本为本地实现与本地测试结果；尚未安装到真实 WordPress，未执行生产验收。生产实时版本仍为 `0.8.0`。

## 2026-08-01 Site Manager 0.8.0 实施状态

- 插件代码版本为 `0.8.0`，公开快照 `schemaVersion` 继续为 `5`。
- 日记草稿发布实现为 `preparePublish` 后接 `publish`，要求 10 分钟一次性令牌、`expectedModifiedAt` 和幂等键。
- 令牌只以 SHA-256 摘要保存，并绑定用户、日记 ID、内容版本和 publish 动作。
- `jg_ai_content_editor` 默认没有发布权限；管理员开关只授予 `jg_ai_publish_diary_drafts`，不授予 WordPress 原生日记发布 capability。
- 发布成功沿用 WordPress 状态/保存 Hook 与现有防抖构建 pending；AI API 不直接调用 GitHub。
- 本次只完成本地实现、文档和测试；尚未安装到真实 WordPress，也没有执行生产全链路验收。
- 精确命令结果和提交证据记录在本次独立任务日志中。

## 基本信息

- 项目名称：JaisonG1n-Blog
- 当前插件版本：JaisonG1n Site Manager `0.11.0`（本地实现；生产实时版本为 `0.10.1`，未安装 0.11.0）
- 当前快照 schemaVersion：`5`
- 主分支：`master`（`origin/HEAD` 指向 `origin/master`）
- 当前工作分支：`codex/ai-content-api-0-7`
- Git 状态基线：当前分支包含本地提交 `1276739`，相对 `origin/codex/ai-content-api-0-7` 领先 1 个提交；汇总开始前 `git diff --stat` 无输出，但有 5 份未跟踪的 2026-07-31 独立任务日志。
- 最后更新时间：2026-07-31 17:47（Asia/Shanghai；项目汇总已推送）

## 已完成能力

### 项目记录机制（已提交）

- `1276739 docs: add cross-session project records` 已提交 `AGENTS.md`、`docs/project/README.md`、初始 `current-state.md`、`decisions.md` 和 `1721-project-records.md`。
- 记录规则要求每个会话使用独立任务文件，汇总状态与重要决策由专门任务维护；本次未修改或删除任何既有独立日志。

### WordPress 结构化内容与相册（代码已提交，部分线上结果待验证）

- `7c9465f` 接入相册结构化同步、schemaVersion 5、媒体镜像、原子事务和 Astro 相册详情页；`62dc5fa` 修复中文或已编码 slug 的规范化与单次编码输出。
- WordPress true 模式使用结构化源数据；legacy 模式保留原有密码相册分支；相册详情使用 slug 路由。
- 相册同步和构建的本地报告已通过，但最终公开相册页面和完整浏览器交互验收未形成可复核结论。

### WordPress 自动构建（代码已提交，生产链路用户已确认）

- `dfa5abd` 实现 GitHub Actions `workflow_dispatch`，GitHub API 版本为 `2026-03-10`，支持 200/204 响应、公开 revision 去重、30--60 秒防抖、锁、重试、手动强制构建和媒体反向引用索引。
- `.github/workflows/build-deploy.yml` 保留旧 `repository_dispatch` 作为兼容入口；当前插件不发送该事件。工作流包含过期提交保护和 deploy 分支发布步骤。
- 用户已在真实环境确认：自动构建触发成功、GitHub Actions 自动构建部署成功、最终文章已在博客前台出现。该证据是用户确认，不是本次汇总会话自动验证。

### WordPress REST API 写入链路（用户已在真实环境确认）

- 用户已确认 WordPress Application Password 认证成功。
- 用户已确认 Codex 通过 WordPress REST API 成功创建文章草稿。
- 用户已确认测试文章 ID `73` 从 `draft` 更新为 `publish`。
- 上述普通 WordPress REST API 生产结果不等同于 AI Content API `0.7.0` 生产验收。

### AI Content API 0.7.0（代码与本地测试已提交，生产验收待完成）

- `c73037e` 增加受控 AI Content API、`jg_ai_content_editor` 角色、capabilities 契约、接口文档和测试覆盖。
- 已实现内容字段白名单、专用角色、默认草稿、独立发布接口、`Idempotency-Key` 幂等、`expectedModifiedAt` 并发检查、按用户限流和有界审计。
- 支持 `article`、`diary`、`project`、`timeline`、`skill`、`aiTool`、`friend`、`announcement`、`techRadar`、`learningResource`；拒绝 `page`、`album`，没有删除接口。
- AI Content API `0.7.0` 的真实生产环境验收尚未完成；不能用普通 REST API 的文章 73 结果代替。

### Agent 规则与 OpenClaw（共享规则已提交，正式接入未实现）

- `c4f5b50` 和 `a13a27f` 建立了 Codex、OpenClaw 与未来 Agent 共用的日记、写作、研究、发布和 API 使用规则。
- OpenClaw 目前只有适配指南；正式 Blog Skill、生产接入和与本博客的稳定写入链路尚未实现。
- 2026-07-31 的微信通道只读审查确认入站记录在 Gateway 后缺少 Agent dispatch/outbound 记录；该问题仍是外部 OpenClaw 环境的待验证事项，不是本仓库已完成的接入能力。

## 当前可用链路

### 已由用户确认的生产链路

```text
WordPress Application Password
  -> WordPress REST API 创建文章草稿
  -> 测试文章 73 从 draft 更新为 publish
  -> WordPress 自动构建触发
  -> GitHub Actions 构建与部署
  -> 文章出现在博客前台
```

证据等级：用户已在真实环境确认；本次汇总未访问 WordPress、GitHub Actions 或博客前台，未把它标为本会话自动验证。

### 仓库实现的自动构建链路

```text
WordPress 公开内容变化
  -> Site Manager revision 去重与防抖
  -> GitHub workflow_dispatch
  -> Astro 构建与 Pagefind
  -> deploy 分支
```

证据等级：代码、工作流、本地 mock/Playground 测试和历史 Actions 报告可确认；当前汇总未重新运行或访问线上服务。

### AI Agent 链路（实现存在，生产验收待完成）

```text
Codex / Agent
  -> AI Content API capabilities
  -> WordPress 草稿
  -> 人工审核
  -> 独立发布接口与服务端授权
  -> 既有保存/状态自动化路径
```

证据等级：仓库实现、文档和本地测试覆盖可确认；AI Content API `0.7.0` 真实生产链路待验收。

## 当前支持内容类型

- AI Content API：`article`、`diary`、`project`、`timeline`、`skill`、`aiTool`、`friend`、`announcement`、`techRadar`、`learningResource`。
- AI Content API 排除：`page`、`album`；删除操作未提供。
- WordPress 结构化快照：历史 Playground 报告确认公开 post type 共 12 类；相册、日记、项目、时间线、技能、友链、公告、AI 工具、Tech Radar、Learning Resource 等数据由插件注册表和 snapshot 契约管理。具体可写入类型以插件注册表为准，不将 AI API 支持范围扩展到未列出的类型。

## 测试与验证状态

### 已测试（独立日志中的历史输出）

- 自动构建任务报告：`pnpm test` 48 通过、0 失败；`pnpm test:wordpress-plugin` 17/17；WordPress Playground smoke、升级测试、`pnpm check`（320 文件，0 errors/0 warnings/0 hints）、本地 schema v5 false/true 构建、插件 ZIP 校验均通过。
- 相册同步任务报告：`pnpm test` 46 通过、0 失败；`pnpm check` 320 文件无 errors/warnings/hints；本地 v5 mock 构建成功并生成 `/albums/测试/index.html`；GitHub Actions 运行 `30566861263` 的构建、输出检查和 deploy 步骤成功。
- 这些是日志中已有的测试报告，不是本次汇总重新运行的结果；不同日志的测试数量反映各自执行时点，不能相加为当前测试总数。

### 本次汇总执行

- 已读取全部 2026-07-31 独立任务日志、Git 历史、插件 changelog、测试文件和状态信息。
- 本次未运行 `pnpm test`、`pnpm check`、WordPress Playground、构建或真实 API 测试。
- `git diff --check`：本次汇总完成后运行，通过且无输出。
- `git status --short`：`current-state.md` 已修改；5 份既有独立任务日志和本次 `1742-project-summary.md` 为本地未提交未跟踪文件。

## 当前变更与证据等级

- 已提交：插件和工作流能力见 `c73037e`、`dfa5abd`、`7c9465f`、`62dc5fa`；跨会话记录机制见 `1276739`。
- 已提交并推送：本次汇总对 `current-state.md` 的更新，以及 5 份既有 2026-07-31 独立任务日志和 `1742-project-summary.md`，已同步到当前远端分支。
- 用户确认已部署：文章 73 的 REST API 草稿/发布、自动构建、GitHub Actions 部署和前台可见性。
- 进行中：AI Content API `0.7.0` 真实生产验收；后续需要按 capabilities、草稿、读取、幂等重放、更新和独立发布顺序记录结果。
- 待验证：最终公开相册页面和桌面/移动浏览器交互；OpenClaw 稳定重启后的微信全链路；AI Content API 生产验收；非 AI 媒体上传流程和短期确认令牌是否存在。
- 未实现：OpenClaw 正式 Blog Skill/生产接入、AI Content API 相册写入、删除接口，以及已确认存在之前的短期确认令牌机制。

## 当前限制

- 默认草稿优先；发布必须经过独立发布接口、服务端开关、内容授权、当前 `expectedModifiedAt` 和 WordPress 能力校验。
- AI API 不处理相册，不提供删除，也不管理用户、插件、主题或站点设置。
- 不能把普通 WordPress REST API 生产结果写成 AI Content API 生产验收结果。
- 不能把本地 mock、Playground 或历史 Actions 输出写成当前线上状态；线上状态以用户确认或可复核平台记录为准。
- 任何记录不包含凭据、认证材料或私密用户数据。

## 正在进行

- AI Content API `0.7.0` 生产验收尚未完成。
- 跨会话汇总文档已提交并推送到当前远端分支。

## 下一步

- 使用不泄露敏感信息的方式完成 AI Content API `0.7.0` 生产验收，并分别记录每个 API 阶段的结果。
- 在稳定的 OpenClaw Gateway 上复查新的微信消息是否经过 inbound、Agent dispatch、模型和 outbound 全链路；在确认前不称为正式接入。
- 需要相册上线验收时，补齐 Playground 媒体选择器和桌面/移动端浏览器交互证据。
- 由后续汇总任务根据新的独立日志、Git 状态和测试输出再次更新本文件；没有新的重要架构、安全、权限或兼容性决策时不修改 `decisions.md`。
