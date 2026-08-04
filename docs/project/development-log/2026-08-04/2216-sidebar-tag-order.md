# 首页标签隐藏与短标签优先排序

- 时间：2026-08-04 22:16（Asia/Shanghai）
- 会话或模块：Astro / 首页侧边栏标签组件与文章卡片
- 当前分支：`master`
- 状态：已完成、已提交、已部署并完成生产验收
- 提交：`fec2c1f`（`fix: tidy homepage tag layout`）
- 是否已部署：是；Build and deploy `30918746157` 成功，deploy commit 为 `c451c6f`

## 任务目标

从首页标签展示中隐藏 `Astro`、`Mizuki` 和 `WordPress`，并让其余标签按显示名称长度从短到长排列，减少长短标签交错造成的布局不整齐。

## 实际修改

- 在 `src/components/widgets/tags/Tags.astro` 中增加大小写不敏感的隐藏标签集合。
- 隐藏规则同时应用于固定推荐标签和文章内容自动汇总标签，不修改文章本身的标签或归档数据。
- 合并标签后按 Unicode 字符数量升序排列；长度相同时按规范化名称稳定排序。
- `src/components/features/posts/PostCard.astro` 使用相同的隐藏与排序规则，避免首页文章卡片继续输出这三个标签；过滤后没有可展示标签时不渲染空标签行。

## 验证

- `pnpm exec biome check src/components/widgets/tags/Tags.astro`：通过，未修改文件。
- `pnpm exec biome check src/components/widgets/tags/Tags.astro src/components/features/posts/PostCard.astro`：通过，未修改文件。
- `pnpm exec astro check`：322 个文件，0 error、0 warning、1 个既有 hint（`src/components/features/posts/PostMeta.astro` 的未使用 `id`）。
- `pnpm test`：74/74 通过。
- `git diff --check`：通过。
- `pnpm build`：WordPress 同步成功，40 个页面构建成功，Pagefind 完成索引。
- 修改文章卡片后再次运行 `pnpm exec astro build`：40 个页面构建成功。
- 检查 `dist/index.html`：首页全部标签链接的唯一顺序为 `MCP`、`RAG`、`Java`、`Linux`、`Docker`、`AI Agent`、`Spring AI`、`Spring Boot`；未出现 `Astro`、`Mizuki` 或 `WordPress`。
- GitHub Lint `30918743227`：Biome、Build、Astro Check 全部成功；仅有 GitHub Actions Node.js 20 弃用提示，不影响本次结果。
- GitHub Build and deploy `30918746157`：同步、构建、产物检查和 deploy 分支发布全部成功；deploy commit `c451c6f` 对应源码提交 `fec2c1f`。
- 生产首页 `https://jaisong1n.com/`：HTTP 200；实际标签顺序与本地一致，三个隐藏标签均未出现。

## 说明

- 本次只调整首页展示，不删除 WordPress 文章的原始标签，也不移除归档筛选数据。
