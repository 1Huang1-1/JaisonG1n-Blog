# WordPress REST API 与 AI Content API 历史验证

- 时间：2026-07-31 17:36（Asia/Shanghai）
- 会话或模块：WordPress REST API、AI Content API 与博客自动构建链路
- 当前分支：`codex/ai-content-api-0-7`
- 工作目录：`D:\Blog\JaisonG1n-Blog`
- 状态：部分完成
- 是否已提交：历史实现已提交；本日志为本地未提交
- 是否已部署：本会话未部署；真实环境结果仅记录用户确认

## 任务目标

根据本会话中可确认的历史内容，记录受限的 WordPress REST API 写入、草稿审核、明确发布和自动构建链路，以及仓库中已实现的 AI Content API 约束。不得记录任何凭据或敏感认证材料。

## 实际完成

### 仓库可确认

- 提交 `c73037e` 增加了 JaisonG1n Site Manager `0.7.0` 的受控 AI Content API、专用 `jg_ai_content_editor` 角色、接口文档和相关测试覆盖文件。
- API 以 capabilities 作为运行时契约入口，公开白名单内容字段和操作，不暴露内部 meta key、站点配置或认证材料。
- 支持 `article`、`diary`、`project`、`timeline`、`skill`、`aiTool`、`friend`、`announcement`、`techRadar` 和 `learningResource`；明确拒绝 `page`、`album`，且没有删除接口。
- 创建默认使用草稿并要求 `Idempotency-Key`；更新和发布使用 `expectedModifiedAt` 进行乐观并发控制。发布默认关闭，并使用独立发布接口和服务端授权边界。
- `c4f5b50`、`a13a27f` 建立并迁移了 Codex、OpenClaw 和未来 Agent 共用的写作、研究、发布、日记和 API 使用规则。

### 测试输出可确认

- 仓库提交统计可确认 `c73037e` 包含插件实现、API 文档、升级测试调整、WordPress 插件测试和 Playground AI Content 测试文件。
- 本补录会话只执行了 Git 状态、提交历史、文档读取和路径检查；没有运行项目测试，也没有发起 WordPress 请求。

### 用户在真实环境中确认

- WordPress REST API Application Password 认证已通过。
- 通过 WordPress REST API 成功创建过文章草稿；此前同 slug 内容被检查以避免重复创建。
- 测试文章 ID `73` 已由 `draft` 更新为 `publish`，并用于验证自动构建链路。
- WordPress 自动构建已触发，GitHub Actions 构建部署成功，文章已在博客前台出现。

以上真实环境结果来自用户确认，不是本补录会话自动验证结果。

## 修改文件

### API 实现提交 `c73037e`

- `docs/ai-content-api.md`
- `package.json`
- `scripts/test-wordpress-plugin-upgrade.mjs`
- `tests/wordpress-plugin-upgrade.php`
- `tests/wordpress-plugin.test.mjs`
- `wordpress-plugin/jaisong1n-site-manager/includes/class-jg-ai-content.php`
- `wordpress-plugin/jaisong1n-site-manager/jaisong1n-site-manager.php`
- `wordpress-plugin/jaisong1n-site-manager/readme.txt`
- `wordpress-plugin/jaisong1n-site-manager/tests/playground-ai-content.php`

### 后续共享规则文档

- `AGENTS.md`
- `docs/agents/diary-workflow.md`
- `docs/agents/writing-style.md`
- `docs/agents/research-policy.md`
- `docs/agents/publishing-policy.md`
- `docs/agents/ai-content-api-usage.md`
- `docs/agents/openclaw-adapter-guide.md`

这些文件分别由 `c4f5b50`、`a13a27f` 和后续项目记录提交维护；本日志未修改它们。

## 测试与验证

- 命令或验证方式：`git show --stat c73037e`。
  - 实际结果：确认 API 实现、文档和测试文件已包含在提交中。
  - 是否通过：仓库范围检查通过；不等同于测试执行通过。

- 命令或验证方式：读取 `docs/project/current-state.md`、`docs/project/decisions.md` 和 `docs/ai-content-api.md`。
  - 实际结果：确认 capabilities 优先、草稿优先、幂等键、`expectedModifiedAt`、独立发布接口、相册排除和删除禁止等规则。
  - 是否通过：文档一致性读取完成。

- 命令或验证方式：本补录会话的 `git status --short --branch`、`git log` 和 `git diff --check`。
  - 实际结果：确认当前分支为 `codex/ai-content-api-0-7`，当前工作区在写入本日志前无未提交文件；`git diff --check` 无空白错误。
  - 是否通过：通过；新增本日志后工作区将出现本地未提交文件。

- 项目测试命令：本会话未运行 `pnpm test`、WordPress 插件测试、Playground 测试或真实 API 测试。
  - 实际结果：无法确认本会话的测试通过数量或生产 API 验收状态。
  - 是否通过：未执行，不标记为通过。

## 真实环境验证结果

用户确认了认证、草稿创建、文章 73 发布、自动构建、GitHub Actions 部署和前台可见性。当前无法从本地仓库独立复核这些生产结果；AI Content API `0.7.0` 的完整生产验收仍未确认完成。

## 遇到的问题

- 测试草稿使用的 slug 曾需要先检查已有状态，避免创建重复内容；后续用户确认旧的同 slug 测试文章已从回收站永久删除。
- WordPress REST API 的普通文章操作与受控 AI Content API 是不同接口范围，不能用普通文章接口行为推断 AI API 已完成生产验收。
- 本会话没有可用于重新验证生产 API 的自动化测试输出。

## 解决过程

- 使用固定 slug 查询和作者/状态检查约束草稿复用或创建，避免重复文章。
- 将 AI 写入限制在专用角色、公开字段白名单和默认草稿流程内。
- 将发布限制到独立接口、当前 `expectedModifiedAt`、内容授权、WordPress 发布能力和服务端开关，不通过草稿更新绕过权限。
- 将 Codex 与未来 OpenClaw 的规则集中到 `docs/agents/`，避免维护两套内容规则。

## 关键决定

- Agent 默认只创建草稿；“写”或“保存”不等于发布。
- 不开放相册写入、删除、用户、插件、主题或站点设置管理。
- 创建必须使用幂等键，更新和发布必须使用当前 `expectedModifiedAt`。
- 不记录或输出密码、Token、Authorization、Cookie、Application Password 或环境变量值。
- 生产环境的用户确认与本地测试输出分开记录，不能互相替代。

## 尚未完成

- AI Content API `0.7.0` 的真实生产环境完整验收尚未在本会话执行。
- 本会话未运行项目测试，测试通过数量无法确认。
- OpenClaw 正式 Blog Skill 尚未实现；目前只有适配指南。
- 当前分支相对远端存在未推送的项目记录提交状态；本日志本身也尚未提交。

## 下一步

- 在不使用真实凭据泄露的前提下，使用本地 mock 或 Playground 完成 AI Content API 测试。
- 如需生产验收，按 capabilities、草稿、读取、幂等重放和明确发布顺序逐项记录真实结果。
- 由专门汇总任务根据独立日志、实际测试输出和 Git 状态更新 `current-state.md`；本任务不修改该文件。

## 资料来源

### 仓库可确认

- Git 提交：`c73037e`、`c4f5b50`、`a13a27f`、`1276739`。
- `docs/ai-content-api.md`、`docs/project/current-state.md`、`docs/project/decisions.md`。
- 插件实现、测试文件、插件 changelog 和自动构建文档。

### 用户在真实环境中确认

- WordPress REST API 认证、草稿创建、文章 ID 73 发布、自动构建、GitHub Actions 部署和前台文章可见性。

### 当前无法确认

- 本补录会话未重新调用生产接口，无法独立确认当前线上状态。
- 本补录会话未运行完整项目测试，无法提供新的测试通过数量。
