# Current project state

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
- 当前插件版本：JaisonG1n Site Manager `0.7.0`
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
