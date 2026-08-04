# 首页推荐标签增加 ChatGPT

- 时间：2026-08-04 22:42（Asia/Shanghai）
- 会话或模块：Astro / 首页标签组件
- 当前分支：`master`
- 状态：已完成、已提交、已部署并完成生产验收
- 提交：`88b9156`（`feat: add ChatGPT homepage tag`）
- 是否已部署：是；Build and deploy `30920734529` 成功，deploy commit 为 `036d676`

## 任务目标

在首页推荐标签中增加 `ChatGPT`，沿用现有按显示名称长度从短到长的排序规则，使标签区域保持三排布局。

## 实际修改

- 在 `src/config/siteConfig.ts` 的 `featuredTags` 中增加 `ChatGPT`。
- 未修改文章标签、归档数据或标签隐藏规则。

## 验证

- `pnpm exec biome check src/config/siteConfig.ts`：通过，未修改文件。
- `pnpm exec astro check`：322 个文件，0 error、0 warning、1 个既有 hint（`src/components/features/posts/PostMeta.astro` 的未使用 `id`）。
- `pnpm exec astro build`：通过，生成 40 个页面。
- 检查 `dist/index.html`：首页标签顺序为 `MCP`、`RAG`、`Java`、`Linux`、`Docker`、`ChatGPT`、`AI Agent`、`Spring AI`、`Spring Boot`。
- 发布前 `pnpm test`：74/74 通过。
- 发布前 `pnpm build`：WordPress 同步成功，40 个页面构建成功，Pagefind 完成索引。
- `git diff --check`：通过。
- GitHub Lint `30920732395`：Biome、Build、Astro Check 全部成功；仅有 GitHub Actions Node.js 20 弃用提示，不影响本次结果。
- GitHub Build and deploy `30920734529`：同步、构建、产物检查和 deploy 分支发布全部成功；deploy commit `036d676` 对应源码提交 `88b9156`。
- 生产首页 `https://jaisong1n.com/`：Playwright 打开成功，页面无控制台错误；标签实际分为三排：
  - 第一排：`MCP`、`RAG`、`Java`、`Linux`
  - 第二排：`Docker`、`ChatGPT`、`AI Agent`
  - 第三排：`Spring AI`、`Spring Boot`

## 说明

- 本次只增加首页推荐标签，不修改 WordPress 文章标签或归档数据。
