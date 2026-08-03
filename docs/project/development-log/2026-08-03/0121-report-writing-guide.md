# 整理日报与周报写作规范

- 时间：2026-08-03 01:21（Asia/Shanghai）
- 会话或模块：项目文档 / 博客内容规范
- 当前分支：`master`
- 工作目录：`D:\Blog\JaisonG1n-Blog`
- 状态：已完成
- 是否已提交：否，本地未提交
- 是否已部署：不适用；本次只新增 Markdown 文档

## 任务目标

把此前分散在博客日记工作流、写作风格、研究政策、发布政策和 AI Content API 约定中的日报与周报规则，整理成一份便于审查和后续会话复用的 Markdown 文档。

## 实际完成

- 新增 `docs/agents/report-writing-guide.md`。
- 覆盖开发日报、开发周报、AI 科技日报和 AI 科技周报四种内容类型。
- 集中整理时间范围、篇幅、事件/来源数量、文章结构、证据等级、状态词汇、草稿与发布边界、审查清单和可复用模板。
- 未修改业务代码、插件、前端、现有规范文件、`current-state.md` 或 `decisions.md`。

## 修改文件

- `docs/agents/report-writing-guide.md`（新增）
- `docs/project/development-log/2026-08-03/0121-report-writing-guide.md`（新增，本日志）

## 测试与验证

- 命令或验证方式：读取现有 `docs/agents/*.md`、`AGENTS.md`、产品路线图和项目状态资料。
  - 实际结果：新文档以现有规则为来源，未引入博客 API 写入操作。
  - 是否通过：通过。
- 命令或验证方式：`git diff --check`。
  - 实际结果：退出码 0，无空白错误输出。
  - 是否通过：通过。
- 命令或验证方式：`git status --short`。
  - 实际结果：`AGENTS.md` 为用户已有修改；本次新增整理文档和任务日志为未跟踪；未修改其他已有变更。
  - 是否通过：通过，状态已如实记录。

## 遇到的问题

- 规则分布在多个文件中，且开发类与 AI 科技类日报/周报的证据要求不同。
- 当前工作区存在用户对 `AGENTS.md` 的修改，本次未触碰该文件。

## 解决过程

- 将共同原则和类型差异分成独立章节。
- 为开发记录保留 Git/测试/部署证据，为科技内容保留联网核验和来源数量要求。
- 单独写明“草稿、准备发布、WordPress 已发布、构建成功、前台可访问”的状态边界。

## 关键决定

- 使用新的共享文档，而不是覆盖已有规范文件，便于用户审查并逐步替换引用。
- 将模板和发布前检查清单放入同一份文档，方便后续会话直接复制使用。

## 未完成内容

- 用户审查后可能需要根据偏好调整篇幅、标题风格或模板字段。
- 本次未提交、未推送、未调用外部 API，也未对真实 WordPress 执行任何操作。

## 下一步

- 用户审查 `docs/agents/report-writing-guide.md` 后，再决定是否将 `AGENTS.md` 或其他入口文档链接到该整理版。

## 资料来源

- `AGENTS.md`
- `docs/agents/diary-workflow.md`
- `docs/agents/writing-style.md`
- `docs/agents/research-policy.md`
- `docs/agents/publishing-policy.md`
- `docs/agents/ai-content-api-usage.md`
- `docs/project/JaisonG1n-personal-content-os-roadmap.md`

本日志和整理文档不包含 Password、Token、Authorization、Cookie、Application Password、环境变量值或私密用户数据。
