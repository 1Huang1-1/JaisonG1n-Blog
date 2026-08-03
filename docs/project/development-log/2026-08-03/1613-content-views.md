# 内容浏览量功能（Site Manager 0.11.0）

- 时间：2026-08-03 16:13（Asia/Shanghai）
- 会话或模块：Codex / 浏览量统计（服务端 + Astro 前端）
- 当前分支：`master`（本地）
- 工作目录：`D:\Blog\JaisonG1n-Blog`
- 状态：已完成（实现、定向与全量检查、本地待提交）
- 是否已提交：否，随本会话本地提交创建
- 是否已部署：否；未 push/tag/release，未安装到生产 WordPress

## 任务目标

为 article 与 diary 的公开详情页实现浏览量功能：用户真正进入详情页并保持页面可见 1 秒记 1 次浏览；浏览量显示在桌面标题右侧、移动端元信息行；不得修改 `modifiedAt`、不得触发 dispatch/GitHub 构建。

## 服务端（WordPress 插件 0.11.0）

- 新增 `includes/class-jg-content-stats.php`：公开只写路由 `POST /wp-json/jg-public/v1/content/{contentType}/{id}/view`，与 AI Content API 完全分离。
- 仅 article/diary 且 `status=publish` 可计数；`eventId` 必须为 UUID；请求体 ≤1KB；按 IP 哈希 60 次/分钟限流；明显机器人 UA 不计数；CORS 白名单仅含生产站点与两个本地开发来源，`OPTIONS` 返回 204。
- 建表 `jg_content_stats`（content_type/content_id/view_count/updated_at，联合主键）与 `jg_view_events`（event_hash 主键，TTL 30 天，1% 概率清理）。
- 事件哈希绑定 `sha256(contentType + ":" + postId + ":" + eventId)`：同内容同 eventId 只计一次，不同内容同 eventId 各自计数，同内容不同 eventId 分别计数。
- 计数原子性：`INSERT IGNORE` 事件行成功后 `ON DUPLICATE KEY UPDATE view_count+1`。
- 修复：bot 分支原先在解析文章前引用未定义的 `$post_id`，已改为先解析再响应（返回真实 id 且不计数）。
- slug 解析支持数字 ID、ASCII slug、编码与解码 CJK slug（直接按候选列表查询 `post_name`，候选为原样/rawurldecode/sanitize_title 三种形态）。
- 视图写入不调用 `wp_update_post`、不写 `wp_posts`、不进入 dispatch/构建链路。

## 前端（Astro）

- 新增 `src/utils/content-view-count.ts`：`resolveViewEvent` 用 history.state + `crypto.randomUUID()` 生成/复用事件 ID；刷新、后退、前进复用同一历史记录与事件；重新点击进入生成新事件；页面可见满 1 秒才 POST；提交去重；成功渲染最新数字，失败显示占位符。
- 新增 `src/components/features/content/ContentViewCount.astro`：`variant="title"`（桌面标题右侧）与 `variant="meta"`（移动端元信息行）两个挂载共享同一事件，只提交一次；复用 `material-symbols:visibility-outline-rounded` 图标；数字 `tabular-nums`，`title`/`aria-label` 显示“1,286 次浏览”。
- 接入 article `/posts/{slug}/`、自定义 permalink 页与 diary 详情页；`ContentDetailLayout` 增加 `viewCount` 插槽。
- `PostMeta.astro`：移除旧 Umami 页面浏览量展示块及其 inline script（Umami 分析采集保留），新增 `extraMeta` 插槽。
- 端点指向 `https://cms.jaisong1n.com/wp-json/jg-public/v1/content/...`（CORS 已允许 jaisong1n.com）。

## 测试

- content-stats Playground：45 断言通过（首次 +1、同 eventId 去重、跨内容隔离、数字/ASCII/编码与解码 CJK slug、草稿与缺失 404、非法输入、bot 不计数、限流 429、并发原子性、CORS/OPTIONS、modifiedAt 不变、无 dispatch）。
- AI Content Playground 222 断言、deployment-status 108 断言、smoke 通过。
- 前端单元测试 `tests/content-view-count.test.mjs`：9 项通过（readViewEvent/resolveViewEvent 复用与新建、格式化、可见时长）。
- `pnpm test` 73 项全部通过；`pnpm astro check` 322 文件 0 错误 0 警告。
- 0.10.1→0.11.0 升级测试更新为验证两张统计表创建。

## 修改文件

- `wordpress-plugin/jaisong1n-site-manager/includes/class-jg-content-stats.php`（新增）
- `wordpress-plugin/jaisong1n-site-manager/tests/playground-content-stats.php`（新增）
- `wordpress-plugin/jaisong1n-site-manager/jaisong1n-site-manager.php`、`readme.txt`
- `src/utils/content-view-count.ts`（新增）、`src/components/features/content/ContentViewCount.astro`（新增）
- `src/pages/posts/[...slug].astro`、`src/pages/[...permalink].astro`、`src/pages/diary/[slug].astro`
- `src/layouts/ContentDetailLayout.astro`、`src/components/features/posts/PostMeta.astro`
- `tests/content-view-count.test.mjs`（新增）、`tests/wordpress-plugin.test.mjs`、`tests/wordpress-plugin-upgrade.php`
- `scripts/test-wordpress-plugin-upgrade.mjs`、`package.json`
- `docs/content-views-api.md`（新增）、`docs/project/current-state.md`、`docs/project/decisions.md`、本日志

## 遇到的关键问题

- Playground CLI 偶发 `fetch failed`，重试即可。
- 已发布 fixture 会触发 dispatch pending，stats 测试将清理动作放在 fixture 创建之后。
- 两个 CJK slug fixture 的候选集互相覆盖导致解析歧义，改用不同 CJK slug 后通过。

## 下一步

- 生成本地提交（`feat: add content view counting`）；不 push/tag/release。
- 生产部署需先备份数据库与插件目录、安装 0.11.0 ZIP，再做 article/diary 计数与防刷验收；本环境无生产管理员凭据，需用户授权执行。

禁止记录凭据、Token、Authorization、Cookie、Application Password、环境变量值或私密用户数据；本日志未记录此类信息。
