# 2026-08-08 self-hosted build timeout 20 → 60 分钟

## 背景

self-hosted VPS 为 2 vCPU / 约 2GB RAM，pnpm install 需处理 1122 个
packages，构建较慢，`timeout-minutes: 20` 可能触发 job timeout，表现为
"The operation was canceled."。`cancel-in-progress` 已为 `false`（排队模式），
本次保持不动。

## 修改

`.github/workflows/build-deploy.yml`（1 行）：

- `jobs.build-deploy.timeout-minutes`：`20` → `60`。
- 未修改其他逻辑；`concurrency.cancel-in-progress: false` 保持不变。

## 验证

- YAML 解析通过（PyYAML）；`timeout-minutes = 60`、`cancel-in-progress = false`。
- 推送过程：SSH 22 / SSH 443 通道遭网络层 RST 约 20 分钟（复用 2026-08-07
  日志中已记录的间歇不可达问题）；HTTPS 直连可用但 OAuth token 缺少
  `workflow` scope，GitHub 拒绝通过 HTTPS/API 更新 workflow 文件；
  最终 SSH 通道恢复后于 09:40 推送成功，本地 commit SHA 原样上云。

## 提交

- commit：`0e14870d` `ci: increase self-hosted build timeout`
- 已推送：`origin/master` `9b49e270..0e14870d`
- 本日志文件为本地未提交文件（按用户要求本次 commit 只含 workflow 一行改动）。
