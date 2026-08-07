# 2026-08-08 production build 改为排队而非取消

## 背景

单台 self-hosted VPS runner（ubuntu-xxtg）上 pnpm/Astro build 较慢，push、
WordPress dispatch、workflow_dispatch 和每 30 分钟 schedule 可能在上一轮
production build 未完成时触发新 run。此前 `cancel-in-progress: true` 会直接
取消正在执行的生产部署，出现 "The operation was canceled."。

## 修改

`.github/workflows/build-deploy.yml`（1 行）：

- `concurrency.cancel-in-progress`：`true` → `false`，同一 concurrency group
  的新 run 排队等待而非取消进行中的 run。
- 未调整其他 workflow 行为。

## 验证

- YAML 解析通过（PyYAML），`concurrency.cancel-in-progress = false`。
- diff 仅 1 行变更。
- 未在 runner 上实际触发排队场景，线上行为待下次并发触发验证。

## 提交

- commit：`71ce8344` `ci: queue production builds instead of canceling`
- 已推送：`origin/master` `f4d22903..71ce8344`
- 本日志文件为本地未提交文件（按用户要求本次 commit 只含 workflow 一行改动）。
