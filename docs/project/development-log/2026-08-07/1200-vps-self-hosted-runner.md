# 2026-08-07 VPS 自托管 runner 上线（解决 Hostinger Bot Verification 403）

## 背景

- Hostinger 平台级 Bot Verification 会间歇拦截 GitHub-hosted runner 对 `cms.jaisong1n.com/wp-json/` 的请求（2026-08-06 14:56–14:57 UTC 三次 403 已确认），hPanel 无路径级白名单、runner 出口 IP 动态，无法固定放行。
- 采用方案 A：共享主机保留（CMS + 静态站点），新增一台固定 IP 的 VPS 作为 GitHub Actions 自托管 runner，并在 Hostinger IP Manager 白名单放行该 IP。

## 已完成

- 阿里云轻量应用服务器：新加坡、国际型、2C2G、40 GiB 系统盘、200 Mbps 峰值、Ubuntu 24.04，公网 IP `47.79.227.173`（固定）。
- GitHub Actions self-hosted runner：`ubuntu-xxtg`，目录 `/opt/actions-runner`，以 `admin` 用户运行，systemd 服务 `actions.runner.1Huang1-1-JaisonG1n-Blog.ubuntu-xxtg.service` 开机自启；runner 不能以 root 运行（`Must not run with sudo`），已用非 root 用户配置。
- Hostinger hPanel → 网站 cms.jaisong1n.com → 高级功能 → IP 限制管理器 → 允许列表添加 `47.79.227.173`（用户后台操作）。
- workflow `build-deploy.yml` 的 `runs-on` 从 `ubuntu-latest` 改为 `[self-hosted, linux, x64]`，提交 `622ef26e`（含 Basic Auth 提交 `f2910870` 一并生效）。
- OOM 修复：2 GiB 物理内存（可用 1.6 GiB）跑 pnpm + Astro + Pagefind 时内核 OOM 杀 runner（11:01、11:33 两次），任务以 canceled 失败；新增 4 GiB swap（`/swapfile` + `/etc/fstab`）后构建稳定。

## 验证

- run `31142992003`（push，commit `622ef26e`）conclusion=success，全部步骤通过；`Sync WordPress content` 显示 `WordPress sync succeeded on attempt 1`、`WordPress page 1/1`，无 403。
- deploy 分支已更新，`https://jaisong1n.com/` HTTP 200。
- runner 掉线时可 `sudo systemctl restart actions.runner.1Huang1-1-JaisonG1n-Blog.ubuntu-xxtg` 恢复；掉线根因已由 swap 解决。

## 待办

- OpenClaw 迁移到该 VPS（用户目标）：Node 环境、配置/secret 迁移、systemd/PM2 常驻、微信重新扫码、全链路验证。
- 观察后续 schedule run（每 30 分钟）与内容发布触发是否持续稳定；如有偶发 403 再评估 Hostinger 平台侧跟进。

## 备注

- 本地到 github.com 的 git 通道（SSH 22 / HTTPS 443）当日出现过间歇不可达（疑似网络层拦截），`api.github.com` 正常；推送重试后成功。
- 全程未在日志/聊天中输出任何凭据值；runner 注册令牌为一次性短时效令牌。
