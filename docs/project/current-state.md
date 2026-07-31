# Current project state

## 基本信息

- 项目名称：JaisonG1n-Blog
- 当前插件版本：JaisonG1n Site Manager `0.7.0`
- schemaVersion：`5`
- 主分支：`master`（本地和 `origin/HEAD` 均指向该分支）
- 最后更新时间：2026-07-31 17:28（Asia/Shanghai；提交后状态更新）

## 已完成能力

### WordPress 内容与快照

- 插件声明为 headless 内容、安全配置、公开快照和构建通知管理器。
- Git 历史和插件 changelog 记录了结构化内容、日记、相册同步及 schemaVersion 5 相册记录；现有内容和快照兼容性在 `0.7.0` 中保持不变。
- WordPress 同步脚本和对应测试文件已存在；本次未运行这些测试。

### 自动构建

- 插件 `0.6.0` 实现使用 GitHub `workflow_dispatch` 触发构建，包含修订去重、30--60 秒防抖、重试状态和手动强制重建入口。
- `.github/workflows/build-deploy.yml` 支持 `push`、`workflow_dispatch`、定时任务和保留的兼容性 `repository_dispatch` 触发；工作流会跳过过期提交的部署。
- 用户已在真实环境确认：WordPress 自动构建已成功触发，GitHub Actions 自动构建部署已成功，最终文章已在博客前台出现。

### AI Content API

- 插件 `0.7.0` 提供经认证的 AI Content API，并有专用 `jg_ai_content_editor` 角色。
- API 先暴露 capabilities，仅公开内容字段与可用操作；请求字段和内容字段均按白名单校验。
- 默认创建草稿；发布默认关闭，并要求独立发布接口、当前 `expectedModifiedAt`、内容授权、WordPress 发布能力和服务端开关。
- 草稿创建要求幂等键；同调用者同操作的相同请求可重放，冲突复用返回 `409`。草稿更新和发布状态转换均检查 `expectedModifiedAt`。
- API 有按用户限流和有界审计记录；审计不保存请求正文、摘要、凭据、Authorization 或 token。

### Agent 写作规则

- `docs/agents/` 已提供 Codex、OpenClaw 和未来 Agent 共用的日记工作流、写作风格、研究、发布和 API 使用规则。
- 日报与周报规则要求以可核验证据为准，明确区分完成、进行中和计划；周报按项目成果组织。

## 当前可用链路

```text
WordPress 已发布内容
  -> Site Manager 自动构建调度
  -> GitHub Actions workflow_dispatch
  -> Astro 构建
  -> deploy 分支

Codex / Agent
  -> AI Content API
  -> WordPress 草稿
  -> 人工审核
  -> 明确发布
  -> 既有保存或状态自动化路径
```

第一条链路的真实环境端到端结果由用户确认成功。用户还确认 WordPress Application Password 认证成功、Codex 通过 WordPress REST API 成功创建文章草稿，且测试文章 ID 73 已由 `draft` 更新为 `publish`。这些均为用户已在真实环境确认，不是本次会话自动验证。AI Content API `0.7.0` 的真实生产环境验收仍待完成。

## 当前支持内容类型

- AI Content API：`article`、`diary`、`project`、`timeline`、`skill`、`aiTool`、`friend`、`announcement`、`techRadar`、`learningResource`。
- AI Content API 明确拒绝：`page`、`album`。
- 删除接口：未提供。

## 测试与验证状态

- 已存在：Node 测试、WordPress 插件测试、WordPress Playground smoke/AI Content 测试、同步测试和插件升级检查命令。
- 本次汇总未运行项目测试；当前测试通过状态待以实际命令输出确认。
- 本次文档任务仅运行 `git diff --check` 和 `git status --short`。
- 用户已在真实环境确认：Application Password 认证、WordPress REST API 草稿创建、测试文章 ID 73 的 `draft` 到 `publish` 更新、自动构建触发、GitHub Actions 构建部署和前台文章可见。该确认不构成本会话自动化测试结果。
- AI Content API `0.7.0` 的真实生产环境验收：待完成。

## 当前限制

- 默认只创建草稿；未获明确用户发布请求和服务端全部条件时不得发布。
- AI API 不处理相册，不提供删除，也不管理用户、插件、主题或站点设置。
- AI API 未见媒体上传端点；是否存在其他非 AI 媒体上传流程：待确认。
- 未发现短期确认令牌机制的实现证据：待确认。
- OpenClaw 只有未来接入指南；正式 Skill 或生产接入未完成。

## 正在进行

- 并行安全的跨会话项目记录机制已建立，并已提交到当前分支。

## 下一步

- 由专门汇总会话在独立任务日志积累后，结合 Git 状态和实际测试输出更新本文件。
- AI Content API `0.7.0` 在真实环境验收完成前，不将其生产可用性标为已验证。
