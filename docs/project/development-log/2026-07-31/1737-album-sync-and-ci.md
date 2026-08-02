# WordPress 相册同步与中文 slug 构建修复

- 时间：2026-07-31 01:37（Asia/Shanghai）
- 会话或模块：WordPress 相册结构化同步、Astro 相册详情路由、GitHub Actions 构建
- 工作目录：`D:\Blog\JaisonG1n-Blog`
- 状态：已完成历史任务补录
- 是否提交：是，已创建 `7c9465f` 与 `62dc5fa`
- 是否部署：GitHub Actions 部署步骤已成功；本会话未直接验证最终公开站点页面

## 任务目标

将 WordPress 相册接入结构化快照与 Astro 相册详情页，并处理发布构建中的快照版本和中文相册 slug 问题。

## 实际完成

- 相册同步契约升级到 `schemaVersion: 5`，生成 `albums.json` 并接入结构化数据网关、媒体镜像与原子事务。
- 相册详情路由从 `src/pages/albums/[id]/index.astro` 迁移到 `src/pages/albums/[slug]/index.astro`；列表、详情相邻导航均使用相册源数据顺序。
- legacy 模式保留密码相册分支；WordPress true 模式不读取 legacy 相册扫描器。
- 修复 WordPress 中文 `post_name` 可能已百分号编码时的路由不匹配：数据层先规范化解码，链接输出只编码一次。
- 为 v5 本地 WordPress mock 增加中文相册 slug 夹具，实际构建生成 `/albums/测试/index.html`。

## 修改文件

主要涉及以下文件与目录：

- `scripts/sync-wordpress.mjs`
- `scripts/wordpress-sync/contracts.mjs`
- `scripts/wordpress-sync/contracts.d.mts`
- `scripts/wordpress-sync/gateway.mjs`
- `scripts/wordpress-sync/media.mjs`
- `src/data/structured-content.ts`
- `src/pages/albums.astro`
- 删除 `src/pages/albums/[id]/index.astro`
- 新增 `src/pages/albums/[slug]/index.astro`
- `src/components/features/albums/`
- `src/scripts/handlers/fancybox-handler.ts`
- `src/styles/albums.css`
- `wordpress-plugin/jaisong1n-site-manager/` 中的相册快照、内容类型和测试文件
- `tests/mock-wordpress-v3-server.mjs`、`tests/wordpress-structured-content.test.mjs` 及相关同步/插件测试

## 测试与验证

### 测试输出可确认

- `pnpm test`：46 项通过，0 项失败。
- `pnpm check`：320 个文件，0 errors、0 warnings、0 hints。
- 本轮涉及文件的 Biome 检查通过；全仓 Biome 当时仍有未触及旧文件的既有问题，未记为全仓通过。
- `git diff --check`：通过。
- 使用本地 v5 mock、`WORDPRESS_STRUCTURED_CONTENT_ENABLED=true` 执行同步与 `astro build`：构建成功，生成 21 个静态页面，其中包含 `/albums/测试/index.html`。
- GitHub Actions 运行 `30566861263`：Build site、Verify build output、Publish dist to deploy branch 均成功。

### 仓库可确认

- `7c9465f feat: sync WordPress albums` 与 `62dc5fa fix: normalize encoded album slugs` 均可由 Git 对象库读取，且当前本地与远端分支包含这两个提交。
- `62dc5fa` 的修复将相册 ID 交给共享 slug 规范化逻辑，避免中文路径重复编码。

### 用户在真实环境中确认

- 本会话未获得可独立复核的最终公开站点相册页面验收结论。

### 当前无法确认

- WordPress Playground 媒体选择器的本轮实际运行曾受外部 `fetch failed` 阻断，不能记录为通过。
- Fancybox 连续三次 Swup 往返和桌面/移动端浏览器截图未形成有效可复核产物。

## 遇到的问题与解决过程

1. 旧同步代码要求 schema 4，而 WordPress 端已返回新版快照，严格模式阻断构建。提交相册 v5 同步代码后，快照同步恢复成功。
2. 新版构建随后在 `/albums/测试/` 报 `NoMatchingStaticPathFound`。原因是 WordPress 中文 `post_name` 的编码边界不一致。修复后以中文 mock slug 复现并确认静态页面生成成功。

## 关键决定

- 旧密码相册能力不删除：只在 legacy 扫描模式继续使用；WordPress v5 公开快照暂不加入密码字段。
- 相册详情路由参数统一为 slug/post_name，不使用数字 ID、数组索引或附件 ID。
- true 模式严格使用 WordPress 生成数据；相册为空是有效结果，不回退模板相册。

## 尚未完成与下一步

- 后续若继续调整相册功能，应先在当前插件 `0.7.0`、schema 5 的代码基线上复核兼容性，不以本次历史 ZIP 作为当前版本依据。
- 如需补齐相册验收，应单独解决 Playground 网络依赖，并完成 Fancybox/SWUP 与桌面、移动端浏览器验证。

## 资料来源

- 仓库 Git 提交：`7c9465f`、`62dc5fa`
- 本会话命令输出：Node 测试、Astro 检查、本地 mock 构建、GitHub Actions 运行 `30566861263`
- 本会话用户提供的 Actions 失败日志
