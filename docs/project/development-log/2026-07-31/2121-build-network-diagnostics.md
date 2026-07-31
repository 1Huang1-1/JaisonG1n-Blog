# 补充 WordPress fetch 连接错误诊断

- 时间：2026-07-31 21:21（Asia/Shanghai）
- 会话或模块：WordPress JSON 同步连接错误诊断
- 当前分支：`codex/ai-content-api-0-7`
- 工作目录：`D:\Blog\JaisonG1n-Blog`
- 状态：已完成（诊断增强已本地验证，生产环境未验证）
- 是否已提交：否（本日志和代码改动为本地未提交）
- 是否已部署：否

## 任务目标

处理新的构建日志中只显示 `fetch failed` 的连接错误，保留底层安全错误码，补充常见临时无路由错误的有限重试，并确认不会把生产网络故障误报为 Astro 构建故障。

## 实际完成

- `scripts/wordpress-sync/retry.mjs` 现在识别 `ENETUNREACH` 和 `EHOSTUNREACH`，仍遵守最多两次额外重试和指数退避。
- 新增 `describeNetworkError`，沿 error cause 链提取安全的错误码。
- `scripts/sync-wordpress.mjs` 在 JSON 请求失败时保留底层错误码，例如 `fetch failed (ENETUNREACH)`，不输出认证材料或环境变量值。
- 新增回归测试，确认底层 `ENETUNREACH` 不会被顶层 `fetch failed` 隐藏。

## 修改文件

- `scripts/wordpress-sync/retry.mjs`
- `scripts/sync-wordpress.mjs`
- `tests/sync-wordpress.test.mjs`
- `docs/project/development-log/2026-07-31/2121-build-network-diagnostics.md`

未修改 `current-state.md`、`decisions.md`、GitHub Actions、插件代码或其他会话日志。

## 测试与验证

- 命令：`pnpm exec node --test tests/sync-wordpress.test.mjs tests/wordpress-structured-content.test.mjs`
  - 实际结果：33 通过、0 失败、0 取消。
  - 是否通过：是。

- 命令：`pnpm test`
  - 实际结果：51 通过、0 失败、0 取消。
  - 是否通过：是。

- 命令：`pnpm check`
  - 实际结果：320 个文件，0 errors、0 warnings、0 hints。
  - 是否通过：是。

- 命令：对本轮脚本和测试文件执行 `pnpm exec biome check`。
  - 实际结果：通过，无自动修复。
  - 是否通过：是。

- 命令：使用本地模拟 `TypeError("fetch failed")`，其 cause 为 `ENETUNREACH`，调用同步入口。
  - 实际结果：错误摘要为 `WordPress page 1 request failed: fetch failed (ENETUNREACH)`。
  - 是否通过：是；未进行实际网络连接。

- 命令：`git diff --check`。
  - 实际结果：通过。
  - 是否通过：是。

## 真实环境验证结果

本会话未访问真实 WordPress、未调用生产 API、未运行 GitHub Actions，也未部署。用户提供的失败日志只能确认生产构建在 WordPress page 1 请求阶段失败，不能确认具体底层错误码；新的错误码输出需要下一次 CI 构建日志确认。

## 遇到的问题

- Node fetch 默认只暴露 `fetch failed`，原有包装逻辑没有展示 cause 链，无法判断 DNS、路由、拒绝连接、TLS 或远端重置。
- 上一次响应头重试修复不能解决持续的生产网络不可达；重试后仍失败时需要可操作的底层错误信息。

## 解决过程

1. 读取同步入口和网络重试器，确认错误码在 cause 中而非顶层 `TypeError`。
2. 增加 `EHOSTUNREACH`、`ENETUNREACH` 的有限重试。
3. 让同步错误摘要只追加错误码，不追加可能包含请求细节的完整 cause 文本。
4. 用本地模拟错误链验证最终诊断文本，并运行完整测试和静态检查。

## 关键决定

- 不把所有 `fetch failed` 无条件视为可重试错误；只有明确的网络错误码或超时错误才重试。
- 不在生产网络不可达时静默使用空内容或旧内容，继续保持文章同步的严格失败语义。
- 不通过本会话访问生产站点推断底层错误码。

## 尚未完成

- 尚未获得生产 CI 下一次运行的真实底层错误码。
- 尚未确认 `cms.jaisong1n.com` 在 CI runner 上是 DNS、路由、TLS 还是服务端拒绝问题。
- 当前改动尚未提交、推送或部署。

## 下一步

- 在受控 CI 运行中重新执行构建，读取新的安全错误摘要。
- 根据错误码检查 CI runner 到 WordPress 的 DNS、出站 HTTPS、证书链或服务端防火墙配置。
- 确认网络恢复后再判断是否需要进一步调整同步策略；不增加无限重试。

## 资料来源

### 仓库可确认

- `scripts/wordpress-sync/retry.mjs`
- `scripts/sync-wordpress.mjs`
- `tests/sync-wordpress.test.mjs`
- 前一任务提交：`2bbb48d`

### 测试输出可确认

- 本会话运行的 33 项同步测试、51 项完整测试、`pnpm check`、Biome 和本地错误链路模拟。

### 用户提供

- 新的 `pnpm build` 日志，其中显示 `WordPress page 1 request failed: fetch failed`。

### 当前无法确认

- 生产网络故障的底层错误码、具体原因和修复后线上构建结果。
