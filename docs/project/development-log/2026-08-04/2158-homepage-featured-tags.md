# 主页精选技术标签

- 时间：2026-08-04 21:58（Asia/Shanghai）
- 会话或模块：Codex / Astro 主页标签组件
- 当前分支：`master`（本地）
- 状态：已完成，本地未提交
- 是否部署：否

## 任务目标

在主页标签组件中固定增加用户选定的技术标签：Java、Spring Boot、Spring AI、
AI Agent、RAG、MCP、Docker、Linux，同时保留已有文章自动生成的标签。

## 实现

- 在站点配置中新增 `featuredTags`，集中维护主页精选标签及展示顺序。
- 标签组件将精选标签放在前面，再追加文章标签。
- 标签名称在合并时执行去空白、忽略大小写的去重；已有同名文章标签的计数继续保留。
- 未修改文章内容、WordPress 数据、发布流程或权限配置。

## 验证

- `pnpm check`：通过，322 个文件无错误；存在 1 个既有提示（`PostMeta.astro`
  的 `id` 未使用），与本次改动无关。
- `pnpm exec biome check src/types/config.ts src/config/siteConfig.ts src/components/widgets/tags/Tags.astro`：通过。
- `git diff --check`：通过。

## 修改文件

- `src/config/siteConfig.ts`
- `src/types/config.ts`
- `src/components/widgets/tags/Tags.astro`
- 本任务日志

## 已知行为

精选标签会立即显示；尚无对应文章的标签入口会进入空的归档筛选结果，后续文章使用该
标签后会自动出现内容。
