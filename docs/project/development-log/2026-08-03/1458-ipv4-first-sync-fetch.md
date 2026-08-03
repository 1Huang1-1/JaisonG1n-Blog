# WordPress 同步 fetch IPv4 优先修复

- 时间：2026-08-03 14:58（Asia/Shanghai）
- 会话或模块：Codex / WordPress 同步网络韧性
- 当前分支：`master`（本地）
- 工作目录：`D:\Blog\JaisonG1n-Blog`
- 状态：已完成（实现、定向与全量检查、本地提交）
- 是否已提交：将随本会话本地提交创建
- 是否已部署：否；未 push/tag/release，未部署

## 任务目标

解决 GitHub Actions 中 `pnpm sync-wordpress` 对 `cms.jaisong1n.com` 反复 `fetch failed (ETIMEDOUT)`：原生文章/快照拉取此前使用裸 `fetch`，连接地址族由解析顺序决定；当第一个地址（常为 IPv6 AAAA）从 CI 网络不可达时直接悬挂超时，不回退 IPv4。

## 根因

- `cms.jaisong1n.com` 同时解析出 2 个 IPv4 与 2 个 IPv6；本机 IPv4/IPv6 均连通，GitHub 部分 runner 到 IPv6（或个别 IP）连接超时。
- `sync-wordpress.mjs` 的 `fetchJsonResponse` 使用裸 `fetch`（无地址族控制），而媒体下载（`media.mjs`）早已使用固定 lookup + connectTimeout。
- 项目此前已记录过同类 “Node fetch IPv6 超时”问题。

## 修复

- 新增 `scripts/wordpress-sync/network.mjs`：`preferIpv4Addresses`（IPv4 优先、族内保序）、`createPinnedLookup`（固定地址表，`all:true` 返回全部）、`resolveHostAddresses`（支持注入 resolver 与字面 IP）、`fetchDispatcherConnectOptions`（pinned IPv4-first lookup + `connectTimeoutMs` + `servername` + `autoSelectFamily`）、`buildFetchDispatcher`（返回 Undici Agent）。
- `sync-wordpress.mjs`：`fetchJsonResponse` 在 `fetchImpl === fetch`（生产路径）时附加 pinned dispatcher（按 origin 缓存；注入 resolver 时新建）；`syncWordPress`/`fetchPublishedPosts`/`fetchSiteSnapshot` 贯通可选 `resolver` 参数供测试注入。
- 未改动媒体下载、发布包、WordPress 插件或部署工作流（CI 现有重试保持不变）。

## 修改文件

- `scripts/wordpress-sync/network.mjs`（新增）
- `scripts/sync-wordpress.mjs`
- `tests/sync-wordpress.test.mjs`
- `docs/project/development-log/2026-08-03/1458-ipv4-first-sync-fetch.md`（本日志）

## 测试与验证

- 同步定向测试（`pnpm test:wordpress-sync`）：13/13 通过（新增：IPv4 优先排序、resolver 全记录/字面 IP、pinned lookup 顺序与 connect 选项、Agent 实例、原生 fetch 附加 dispatcher）。
- `pnpm test`：全部通过（含 media/structured/sync 全套）。
- 真实只读拉取：`fetchPublishedPosts("https://cms.jaisong1n.com")` 经新 dispatcher 成功（约 3.2s，2 篇已发布文章）。
- `pnpm check`：320 文件 0 errors/warnings/hints；Biome 通过；`git diff --check` 通过；secret scan 0 命中。

## 遇到的问题

- 测试输出可确认：undici `Agent.connect` 不对外暴露 connect 选项，改用导出的 `fetchDispatcherConnectOptions` 断言连接配置。
- 测试输出可确认：lookup 回调返回值会被忽略（Node 回调风格），测试改为闭包捕获。

## 关键决定

- 仅对生产默认 fetch 附加 dispatcher；测试注入自定义 fetchImpl 时保持原行为，不破坏现有 Mock 测试。
- 不收紧允许主机策略（未加 public-address 强制校验），保持 `WP_BASE_URL` 可配置性。

## 下一步

- 推送 master 后观察 CI 是否仍出现 `ETIMEDOUT`；若仍失败，排查 Hostinger 防火墙/GitHub runner IP 段，再考虑自托管 runner。

## 资料来源

- GitHub Actions 失败日志（多次 `ETIMEDOUT`）、DNS A/AAAA 记录、本机 IPv4/IPv6 连通性测试。
- 代码：`scripts/sync-wordpress.mjs`、`scripts/wordpress-sync/media.mjs`、`scripts/wordpress-sync/contracts.mjs`。

禁止记录凭据、Token、Authorization、Cookie、Application Password、环境变量值和私密用户数据；本日志未记录这些信息。
