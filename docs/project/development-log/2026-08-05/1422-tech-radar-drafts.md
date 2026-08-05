# 2026-08-05 Tech Radar 草稿写入 WordPress（本地未提交）

## 背景

用户要求：把一些常用工具和科技加入科技雷达，图标参考官方图（使用 simple-icons 官方 logo 图标集），每个条目标签独立，并直接在 WordPress 上修改。

## 完成内容

- 通过 WordPress REST API（`/wp-json/wp/v2/jg_tech_radar`，Application Password 认证）在真实生产 WordPress 创建 25 条 Tech Radar 草稿，状态均为 `draft`，ID 范围 140–164。
- 每条草稿包含：标题、slug、中文简介（excerpt + content）、Iconify 图标（`simple-icons:*` 官方 logo）、domain/stage/trend/maturity、独立标签（tags 逗号分隔）、official_url、first/last reviewed（2026-08-05）、featured=false。
- 25 个图标名已逐一对照本地 `@iconify-json/simple-icons` 校验，全部存在，不会破坏前端构建。
- 条目覆盖：frontend（Astro、React、TypeScript、Tailwind CSS、Vite、Svelte）、backend（Node.js、Python、Go、Rust）、data（PostgreSQL、Redis、SQLite、Supabase）、infrastructure（Docker、Kubernetes、Cloudflare、Vercel）、developer-tools（Git、GitHub Actions、VS Code、pnpm、Biome）、ai（OpenAI、Anthropic Claude）。

## 发现的接口缺陷（重要、可复用结论）

- AI Content API 0.12.0 的 `POST /wp-json/jaisong1n/v1/ai/content` 创建路由对 `contentType` 参数使用 `sanitize_key`，会把驼峰类型强制转小写（`techRadar` → `techradar`），而插件注册表键为驼峰 `techRadar`/`aiTool`/`learningResource`，导致这些驼峰类型无法通过 AI Content API 创建草稿，返回 `jg_ai_unsupported_content_type`（HTTP 400）。
- 全小写类型（article/diary/project/timeline/skill/friend/announcement）不受影响；`GET /content` 列表使用 `sanitize_text_field`，读取不受影响。
- 本会话未修改插件代码、未部署；修复需另行决策（例如注册表增加小写别名或调整 sanitize 逻辑），并需在本地测试后由用户确认再上生产。

## 未完成 / 边界

- 草稿未发布：techRadar 没有 AI 发布接口，需用户在 WordPress 后台审核并发布。
- 未发布前，前端科技雷达页不会显示新条目（同步脚本只拉取 `status=publish` 的帖子）。
- 发布后由既有链路生效：WordPress 状态变更 → dispatch 自动触发 GitHub Actions → `pnpm sync-wordpress` 拉取快照 → Astro 构建部署。
- 未修改任何本地代码文件、未提交、未触发构建。

## 追加批次（同日，全栈 + AI 相关）

- 应新增 17 条草稿，状态均为 `draft`，ID 范围 165–181。
- 全栈相关：Next.js、Nuxt、Remix、tRPC、GraphQL、Prisma、Drizzle ORM、FastAPI。
- AI 相关：LangChain、Hugging Face、Ollama、vLLM、DeepSeek、Dify、PyTorch，以及 AI 编程工具 Cursor、GitHub Copilot。
- 17 个图标名（`simple-icons:*`）均已对照本地 `@iconify-json/simple-icons` 校验存在。
- 字段结构与第一批一致：中文简介、独立标签、官方网址、domain/stage/trend/maturity、first/last reviewed 2026-08-05、featured=false。
- 发布与上线边界不变：均为草稿，需用户在 WordPress 后台审核发布，发布后由既有同步/构建链路生效。

## 追加批次 2（同日，Java 生态）

- 应新增 3 条草稿：Java（id=184）、Spring（id=185）、Spring Boot（id=186），状态均为 `draft`。
- 图标：Java 使用 `simple-icons:openjdk`（本机 `@iconify-json/simple-icons` 已无 `java` 图标）；Spring 使用 `simple-icons:spring`；Spring Boot 使用 `simple-icons:springboot`。
- 注意：slug `spring-boot` 与站内其他公开内容冲突（`jg_duplicate_slug`），Spring Boot 实际使用 slug `springboot`。

## 前端布局统一（同日，本地未提交）

- 线上雷达页已有 37 条已发布卡片（网格 `grid md:grid-cols-2` 等宽），但卡片 `<article>` 未撑满网格行高，内容长短不同导致卡片视觉参差。
- 修改 `src/pages/radar.astro`：`data-radar-item` 包裹层增加 `h-full`。
- 修改 `src/components/features/resources/RadarCard.astro`：卡片 `<article>` 增加 `h-full w-full`；底部按钮区增加 `mt-auto`，使按钮行在等高卡片底部对齐。
- 验证：`pnpm astro build` 通过，`dist/radar/index.html` 中三类类名均已生成。
- 未提交、未 push；上线需 commit + push master 触发现有构建部署链路。

## 安全

- 全程未打印、未保存任何凭据、token 或 Authorization 头。
- 所有写入均为草稿，可恢复（后台可删除）。
