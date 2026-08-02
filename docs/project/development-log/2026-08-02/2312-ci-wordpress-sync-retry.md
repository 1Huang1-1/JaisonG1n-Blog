# CI WordPress 同步瞬时超时修复

- 时间：2026-08-02 23:12（Asia/Shanghai）
- 会话或模块：Codex / GitHub Actions 构建稳定性
- 当前分支：`master`（本地）
- 工作目录：`D:\Blog\JaisonG1n-Blog`
- 状态：已完成（本地实现、验证，待提交/推送）
- 是否已提交：将随本会话本地提交创建
- 是否已部署：否；未推送，未触发真实部署

## 任务目标

修复 GitHub Actions 中 `pnpm sync-wordpress` 因 WordPress 瞬时不可达（`fetch failed (ETIMEDOUT)`）导致整个构建失败的问题，保持严格语义（不部署过期内容），不改变内容与部署契约。

## 实际完成（仓库可确认）

- `scripts/wordpress-sync/contracts.mjs` 的 `SYNC_LIMITS` 提升为有界重试与超时：连接 10s→20s、headers 15s→20s、body 30s→45s、单请求总超时 30s→45s、重试 2→4 次、退避 500ms→1000ms（1s/2s/4s/8s）。原生文章同步与快照请求均走该常量。
- `.github/workflows/build-deploy.yml` 将“Build site”拆为两步：新增“Sync WordPress content”步骤，bash 循环最多 3 次调用 `pnpm sync-wordpress`，每次失败打印明确日志并等待 10 秒；同步成功后“Build site”执行 `pnpm astro build && pnpm pagefind --site dist`。工作流其余行为（deploy 分支推送、过期保护、并发取消）不变。
- 不启用 `--allow-stale`：内容触发构建时若 WordPress 不可达，重试耗尽后仍失败，避免静默部署过期内容。

## 修改文件

- `scripts/wordpress-sync/contracts.mjs`
- `.github/workflows/build-deploy.yml`
- `tests/wordpress-structured-content.test.mjs`（同步更新媒体限制契约断言中的超时常量）
- `docs/project/development-log/2026-08-02/2312-ci-wordpress-sync-retry.md`（本日志）

## 测试与验证

- 命令或验证方式：`pnpm test`。
  - 实际结果：全部通过（含 sync-wordpress 与 wordpress-structured-content 测试；媒体限制断言已更新为新超时常量）。
  - 是否通过：通过。
- 命令或验证方式：PyYAML 解析 `.github/workflows/build-deploy.yml`。
  - 实际结果：workflow 与步骤结构正确，sync 重试步骤与 build 步骤顺序正确。
  - 是否通过：通过。
- 命令或验证方式：真实 CMS 只读同步（`fetchPublishedPosts("https://cms.jaisong1n.com")`，不写文件）。
  - 实际结果：成功拉取 3 篇已发布文章，耗时约 7.1s；确认 CMS 当前可达，CI 失败为瞬时网络问题。
  - 是否通过：通过。
- 命令或验证方式：Biome 与 `git diff --check`。
  - 实际结果：通过，无格式或空白错误。
  - 是否通过：通过。

## 遇到的问题

- 测试输出可确认：`wordpress-structured-content.test.mjs` 的“central media limits”断言固定了旧超时常量（connect 10s/headers 15s/body 30s），未随 `SYNC_LIMITS` 更新；同步更新断言后通过。
- 本机无 js-yaml/yaml 直接可用，改用 Python PyYAML 校验 workflow 语法。

## 关键决定

- 只做有界网络韧性提升，不降低同步严格性（不使用 `--allow-stale` 兜底）。
- 重试仅限 WordPress 同步步骤，不重跑 Astro 构建，避免把确定性构建错误与瞬时网络错误混为一谈。

## 未完成内容

- 尚未推送 master；CI 需推送后才会使用新步骤。
- 本次未运行完整 Playground/升级测试（feature-first：仅变更同步与 CI，未触碰插件/内容契约）。

## 下一步

- 推送 master 后观察下一次 `workflow_dispatch`/定时构建，确认瞬时 CMS 超时不再导致整体失败。
- 若 CMS 长时间不可达，另行评估监控与告警，而不是放宽部署语义。

## 资料来源

- GitHub Actions 失败日志：`WordPress sync failed: WordPress page 1 request failed: fetch failed (ETIMEDOUT)`。
- 现有代码：`scripts/sync-wordpress.mjs`、`scripts/wordpress-sync/retry.mjs`、`scripts/wordpress-sync/contracts.mjs`、`.github/workflows/build-deploy.yml`。

禁止记录凭据、Token、Authorization、Cookie、Application Password、环境变量值和私密用户数据；本日志未记录这些信息。
