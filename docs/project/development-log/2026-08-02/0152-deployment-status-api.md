# Site Manager 0.8.2 部署状态 API

- 时间：2026-08-02 01:52（Asia/Shanghai）
- 会话或模块：Codex / Site Manager 部署状态 API
- 当前分支：`codex/ai-content-api-0-7`
- 工作目录：`D:\Blog\JaisonG1n-Blog`
- 状态：已完成（本地实现、测试、打包、待提交）
- 是否已提交：将随本会话本地提交创建
- 是否已部署：否；未上传生产 WordPress，未执行 publish，未触发真实 workflow

## 任务目标

为 AI Content API 提供只读、最小权限、可审计的部署状态接口，使 OpenClaw 能区分 WordPress 内容状态、构建是否触发、GitHub Actions 状态、前台部署状态与公开页面可访问性，并返回服务端生成的 canonical public URL。

## 实际完成（仓库可确认）

- 新增 `GET /content/{contentType}/{id}/deployment-status`：Application Password 认证、与内容读取相同的对象权限（不可读内容返回 404）、不要求 `manage_options`、只读不触发构建。
- capabilities 为全部可读类型新增只读 `deploymentStatus` operation；schemaVersion 保持 5。
- `JG_Dispatch` 扩展：
  - `jg_dispatch_pending` 累积 `contentRefs`（contentType/contentId/modifiedAt）与 triggerId/triggeredAt/source/workflowId/ref，debounce 合并多内容。
  - `jg_dispatch_history` 保存完整记录（dispatchStatus、buildStatus、deploymentStatus、workflowRunId、runUrl、runHtmlUrl、时间戳、errorCode/errorSummary），`jg_dispatch_status` 保留旧面板视图（state/message/workflow_run_id 等）。
  - `workflowRunId` 仅从 GitHub 200 响应解析；204 只记 `dispatchStatus=accepted` 且 runId 为 null。
  - `query_run()` 通过官方 run API 查询状态并 20 秒缓存；403/404/429/500/网络错误保留最后已知状态并返回脱敏错误。
  - `find_latest_record_for_content()` 按“包含该内容引用且 triggeredAt 不早于内容最后修改时间”的最新记录关联；无 contentRefs 的旧记录不硬绑定。
- canonical URL：`get_canonical_public_url()` 基于 `JG_Settings::public_site_url`（默认 `https://jaisong1n.com`），diary `/diary/{slug}/`、article `/posts/{slug}/`；空 slug、含路径分隔符、不支持类型返回 null；与 CMS editUrl 分离。
- 页面探测：仅允许配置的生产域名、`redirection=0`、64 KiB 响应上限、10 秒超时、30 秒结果缓存；HTTP 200=reachable、404=not_found、其余/网络错误=unavailable。
- 状态语义：`deploymentStatus=deployed` 仅在 buildStatus=success 且页面 reachable 时给出；GitHub success 不直接映射 deployed；page reachable 不代表内容为最新版本。
- 审计：`deploymentStatus` 查询记录用户、类型、ID、build state 与 workflowRunId，不记录正文、token、Authorization 或完整 GitHub 响应。

## 修改文件

- `wordpress-plugin/jaisong1n-site-manager/jaisong1n-site-manager.php`（版本 0.8.2）
- `wordpress-plugin/jaisong1n-site-manager/readme.txt`（Stable tag 与 changelog）
- `wordpress-plugin/jaisong1n-site-manager/includes/class-jg-dispatch.php`（记录/查询/关联）
- `wordpress-plugin/jaisong1n-site-manager/includes/class-jg-ai-content.php`（路由/能力/URL/探测/响应）
- `wordpress-plugin/jaisong1n-site-manager/includes/class-jg-settings.php`（`public_site_url`）
- `wordpress-plugin/jaisong1n-site-manager/tests/playground-deployment-status.php`（新增）
- `tests/wordpress-plugin.test.mjs`、`tests/wordpress-plugin-upgrade.php`、`scripts/test-wordpress-plugin-upgrade.mjs`
- `package.json`
- `docs/ai-content-api.md`、`docs/agents/ai-content-api-usage.md`、`docs/agents/publishing-policy.md`、`docs/agents/openclaw-adapter-guide.md`
- `docs/contracts/deployment-status-api-requirements.md`（新增契约）
- `docs/project/decisions.md`、`docs/project/current-state.md`、`docs/project/JaisonG1n-personal-content-os-roadmap.md`
- `docs/project/development-log/2026-08-02/0152-deployment-status-api.md`（本日志）

## 测试与验证

- 命令或验证方式：`pnpm test`。
  - 实际结果：55/55 通过（含部署状态源码断言与 0.8.2 版本断言）。
  - 是否通过：通过。
- 命令或验证方式：WordPress Playground 部署状态测试（`playground-deployment-status.php`）。
  - 实际结果：`{"ok":true,"assertions":94,"schemaVersion":5,"recordRunId":123}`；覆盖 canonical URL（中英文/空/斜杠 slug/不支持类型）、页面探测（200/404/超时/重定向/SSRF）、dispatch 200/204/失败、debounce 合并 contentRefs、记录持久化、GitHub run 状态映射（queued/in_progress/success/failure/cancelled/timed_out/unknown）、403/404/429/500/网络错误回退、20 秒缓存、内容关联隔离、REST 权限（不可读 404/未认证 401）、capabilities、审计与响应脱敏。
  - 是否通过：通过。
- 命令或验证方式：WordPress Playground AI Content（`playground-ai-content.php`）。
  - 实际结果：`{"ok":true,"assertions":111,"schemaVersion":5}`。
  - 是否通过：通过。
- 命令或验证方式：WordPress Playground smoke（`playground-smoke.php`）。
  - 实际结果：退出码 0；dispatch 200/204/unchanged/重试与状态面板兼容断言通过。
  - 是否通过：通过。
- 命令或验证方式：`pnpm test:wordpress-upgrade`（0.8.1 -> 0.8.2 同目录替换）。
  - 实际结果：`ok=true`、`replacementVersion=0.8.2`、`ownershipRepairApplied=true`、`strangerManageRejected=true`；设置/内容/pending/history 保留，`public_site_url` 默认值存在，旧历史无 contentRefs 不被硬绑定。
  - 是否通过：通过（首次因 npx 下载 Playground CLI 网络瞬时失败，重试通过）。
- 命令或验证方式：`pnpm check`。
  - 实际结果：320 文件，0 errors、0 warnings、0 hints。
  - 是否通过：通过。
- 命令或验证方式：Biome（改动的 JS/MJS）。
  - 实际结果：通过，无修改。
  - 是否通过：通过。
- 命令或验证方式：`git diff --check`。
  - 实际结果：退出码 0。
  - 是否通过：通过。
- 命令或验证方式：密钥扫描（diff 内 token/secret 模式）。
  - 实际结果：未发现凭据模式；测试使用 fixture token。
  - 是否通过：通过。
- 命令或验证方式：`pnpm package:wordpress-plugin` + 包校验。
  - 实际结果：`wordpress-plugin/dist/jaisong1n-site-manager-0.8.2.zip`，13 条目，唯一根目录，10 个 PHP 文件；最终 SHA-256 见本会话提交报告。
  - 是否通过：通过。

## 遇到的问题与解决过程

- 测试输出可确认：`probe_public_page()` 最初为 private，Playground 直接调用触发 Error；改为 public（仅探测可信主机，REST 访问仍受 can_read 门控）。
- 测试输出可确认：`deployment_status()` 声明 `: WP_REST_Response` 但错误分支返回 WP_Error，触发 TypeError；去掉返回类型声明与其余 handler 一致。
- 测试输出可确认：contentRefs 断言未对期望数组排序导致 ID 顺序失败；修正后通过。
- 测试输出可确认：升级测试首次因 `npx @wp-playground/cli` 下载 `fetch failed` 退出（历史记录中的已知瞬时问题）；重试通过。

## 关键决定

- 不把 GitHub `workflow_dispatch` 接受视为构建成功；不把 GitHub success 直接映射为 `deploymentStatus=deployed`。
- 状态持久化扩展现有 dispatch options，不新建数据表；记录必须支持多 contentRefs 关联。
- canonical public URL 由服务端基于配置的 `public_site_url` 生成，客户端不得猜测。
- 页面探测只访问配置的生产域名并施加 SSRF/重定向/大小/超时限制。
- schemaVersion 保持 5：新增 operation 与响应字段向后兼容。

## 未完成内容

- 0.8.2 未安装到真实 WordPress，未执行生产端到端验收。
- Cloudflare Pages 部署 ID 查询与页面 build ID/commit 标识未实现（列为后续版本）。

## 下一步

- 管理员备份 WordPress，上传并替换 0.8.2 ZIP。
- 配置/确认 `public_site_url` 为 `https://jaisong1n.com`，确认 capabilities 出现 `deploymentStatus`。
- 用测试草稿执行 prepare/publish 后查询 deployment-status，验证五层状态与 canonical URL；不执行真实发布。

## 资料来源

- Git commit：`72272ab`（0.8.1 基线）与本会话本地提交。
- 0.8.2 源码与测试：`wordpress-plugin/jaisong1n-site-manager/`、`tests/`、`scripts/`。
- Astro 路由：`src/pages/diary/[slug].astro`、`src/pages/posts/[...slug].astro`、`src/utils/post-url.ts`、`src/utils/structured-detail.ts`、`src/config/siteConfig.ts`。
- GitHub Actions：`.github/workflows/build-deploy.yml`（工作流推送到 `deploy` 分支，Cloudflare 在其外异步部署）。

禁止记录凭据、Token、Authorization、Cookie、Application Password、环境变量值和私密用户数据；本日志未记录这些信息。
