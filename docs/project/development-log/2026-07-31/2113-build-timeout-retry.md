# 修复 WordPress 同步响应头超时重试

- 时间：2026-07-31 21:13（Asia/Shanghai）
- 会话或模块：WordPress 同步、结构化内容媒体镜像与 Astro 构建
- 当前分支：`codex/ai-content-api-0-7`
- 工作目录：`D:\Blog\JaisonG1n-Blog`
- 状态：已完成（本地验证完成，生产环境未验证）
- 是否已提交：否（本任务改动和本日志均为本地未提交）
- 是否已部署：否

## 任务目标

修复 `pnpm build` 中 WordPress 结构化内容同步遇到瞬时响应头超时时直接失败的问题，同时保持文章同步严格性、事务一致性和非瞬时错误的失败语义。

## 实际完成

- 新增 `scripts/wordpress-sync/retry.mjs`，统一识别可重试的临时网络错误，并执行最多两次重试，退避间隔为 500ms、1000ms。
- 将 WordPress 文章列表和站点快照的 JSON 请求接入该重试器。
- 将 `MediaMirror` 的媒体请求接入同一重试器；每次失败尝试都会关闭其 dispatcher，再创建新的 dispatcher 重试。
- 仅对响应头、响应体、连接、套接字、临时 DNS 和请求超时等网络错误重试；HTTP 状态错误、数据校验错误、内容转换错误和事务错误仍直接失败。
- 为 JSON 请求和媒体响应头超时分别新增回归测试。

## 修改文件

- `scripts/sync-wordpress.mjs`
- `scripts/wordpress-sync/contracts.mjs`
- `scripts/wordpress-sync/contracts.d.mts`
- `scripts/wordpress-sync/media.mjs`
- `scripts/wordpress-sync/retry.mjs`
- `tests/sync-wordpress.test.mjs`
- `tests/wordpress-structured-content.test.mjs`
- `docs/project/development-log/2026-07-31/2113-build-timeout-retry.md`

未修改 `current-state.md`、`decisions.md`、GitHub Actions、插件代码或其他会话日志。

## 测试与验证

- 命令：`pnpm exec node --test tests/sync-wordpress.test.mjs tests/wordpress-structured-content.test.mjs`
  - 实际结果：32 通过、0 失败、0 取消。
  - 是否通过：是。

- 命令：`pnpm test`
  - 实际结果：50 通过、0 失败、0 取消。
  - 是否通过：是。

- 命令：`pnpm check`
  - 实际结果：320 个文件，0 errors、0 warnings、0 hints。
  - 是否通过：是。

- 命令：对本任务脚本与测试文件执行 `pnpm exec biome check`。
  - 实际结果：通过，无自动修复。
  - 是否通过：是。

- 命令：以 `tests/mock-wordpress-v3-server.mjs` 启动本地 `127.0.0.1` mock，并设置本地 WordPress 地址和结构化同步开启后执行 `pnpm build`。
  - 实际结果：WordPress mock 同步成功；schemaVersion 5 结构化内容与相册 fixture 成功生成；Astro 生成 21 个静态页面；Pagefind 完成索引。
  - 是否通过：是。

- 命令：`git diff --check`。
  - 实际结果：无输出。
  - 是否通过：是。

## 真实环境验证结果

本会话未访问真实 WordPress、未调用生产 API、未运行 GitHub Actions，也未部署。生产环境的实际超时媒体资源和修复后构建结果当前无法确认。

## 遇到的问题

- 原构建日志在文章生成后出现 `Headers Timeout Error`，导致同步事务不提交，Astro 构建不会开始。
- 结构化同步开启时，媒体响应头超时会传播为整个同步失败；原实现没有网络重试。
- PowerShell 后台进程启动受终端策略限制，最终改用单个前台 Node 进程临时启动本地 mock 并执行安全构建。

## 解决过程

1. 追踪 `pnpm build` 到 `sync-wordpress`，确认失败发生在结构化内容的媒体镜像边界。
2. 保留 15 秒单次响应头上限，避免单次请求无限等待。
3. 在网络边界增加有界重试，而非放宽 HTTP、数据或事务错误。
4. 使用 mock 先让第一次调用抛出 `UND_ERR_HEADERS_TIMEOUT`，第二次成功，确认两条网络路径均会恢复。
5. 使用本地 schemaVersion 5 mock 完整构建验证同步、相册页面、Astro 和 Pagefind。

## 关键决定

- 不改变默认草稿、内容权限、媒体来源限制、事务提交或结构化同步开关语义。
- 不将生产构建改为无条件使用 stale snapshot；严格或 stale 策略仍由现有显式开关决定。
- 对临时网络错误最多执行两次额外尝试，使用短指数退避；永久性错误继续快速失败。

## 未完成内容

- 未在生产 WordPress 或 GitHub Actions 中验证本次修复。
- 未确认导致原始超时的具体媒体资源或上游服务状态。

## 下一步

- 在下一次受控的 GitHub Actions 构建中观察是否仍出现响应头超时。
- 若同一媒体持续失败，应单独检查该资源和 WordPress/CDN 响应，不继续增加无限重试。
- 在用户要求后再提交、推送或部署本次改动。

## 资料来源

### 仓库可确认

- `package.json`
- `scripts/sync-wordpress.mjs`
- `scripts/wordpress-sync/contracts.mjs`
- `scripts/wordpress-sync/media.mjs`
- `tests/sync-wordpress.test.mjs`
- `tests/wordpress-structured-content.test.mjs`

### 测试输出可确认

- 本会话运行的 32 项同步测试、50 项完整测试、`pnpm check`、Biome、`git diff --check` 和本地 mock `pnpm build`。

### 用户提供

- 原始 `pnpm build` 失败日志及 `Headers Timeout Error`。

### 当前无法确认

- 修复后的生产构建状态和具体超时资源。
