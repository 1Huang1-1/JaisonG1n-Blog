# 修复周报草稿中文乱码

- 时间：2026-07-31 18:19（Asia/Shanghai）
- 会话或模块：diary 草稿 76 / AI Content API
- 当前分支：`codex/ai-content-api-0-7`
- 工作目录：`D:\Blog\JaisonG1n-Blog`
- 状态：已完成
- 是否已提交：否，本地未提交
- 是否已部署：否，仅更新 WordPress 草稿

## 任务目标

修复 slug 为 `dev-weekly-2026-w31` 的 diary 草稿中文显示为问号的问题，不创建第二篇，不发布。

## 实际完成

- 保留现有 WordPress diary ID `76`，通过 AI Content API 更新标题、摘要和正文。
- 保留 slug `dev-weekly-2026-w31` 和 `draft` 状态。
- 使用重新读取到的 `modifiedAt` 完成乐观并发检查。
- 未调用普通文章接口、GitHub API 或强制构建入口。

## 修改文件

- `docs/project/development-log/2026-07-31/1819-diary-encoding-repair.md`

## 测试与验证

- 命令或验证方式：AI Content API 详情读取后 PATCH 更新 diary `76`。
  - 实际结果：读取 HTTP 200，更新 HTTP 200；返回 `contentType=diary`、`status=draft`、目标 slug 和有效 `modifiedAt`。
  - 是否通过：是
- 命令或验证方式：再次读取详情并按目标 slug 查询 diary 列表。
  - 实际结果：标题与预期文本匹配，正文非空且包含中文章节标记；标题和正文均不包含问号替代字符；匹配草稿数量为 1。
  - 是否通过：是
- 命令或验证方式：`git diff --check`、`git status --short`。
  - 实际结果：`git diff --check` 无输出；仅本日志为本地未提交文件。
  - 是否通过：是

## 遇到的问题

创建草稿时通过 PowerShell 管道把包含中文的脚本传给 Node，系统代码页在进入脚本前将中文转换成了 `?`。第一次更新尝试还复用了无效的修改时间，服务端返回 `jg_ai_stale_content`。

## 解决过程

改用 UTF-8 文件直接运行 Node 脚本，先重新读取当前草稿的 `modifiedAt`，再通过 AI Content API PATCH 写回 Unicode 标题和正文。随后重新读取详情和列表，确认没有重复草稿。

## 关键决定

- 只修复现有 ID `76`，不使用相同 slug 创建第二篇。
- 保持草稿状态，不执行发布，不触发自动构建。
- 不在日志中记录凭据、Authorization、环境变量值或 WordPress 私密数据。

## 未完成内容

- AI Content API `0.7.0` 的完整生产验收仍按项目状态记录为待完成。

## 下一步

- 用户在 WordPress 编辑器中重新打开草稿，确认浏览器界面显示正常；本次 API 复核已确认返回内容为正确 Unicode 文本。

## 资料来源

- AI Content API capabilities 和 diary `76` 详情读取结果。
- 本周开发周报既有事实素材、`docs/project/current-state.md` 和 `docs/project/decisions.md`。
- 本次命令验证结果。

