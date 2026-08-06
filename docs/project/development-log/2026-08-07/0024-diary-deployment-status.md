# 2026-08-07 日记部署状态查询与修复（本地未提交）

## 背景

用户要求查询日记部署情况。

## 查询结果（9 条已发布日记，AI Content API deployment-status）

- 最新日记「AI 科技日报｜谷歌 AI 组织大洗牌与「越界」的评测」（id=335，2026-08-06）：此前 `build=failed`、`page=not_found`，页面 404。
- 其余 8 条：08-05/08-04/08-03/08-02 均为 `deployed + page=reachable`；08-01/07-31/dev-weekly 页面可达但无 dispatch 记录（旧内容）；id=59 旧日记部署接口 404（无记录）。

## 根因（重要、可复用结论）

- 失败 run `31117580417` 及 schedule run `31113369890` 的「Sync WordPress content」步骤报：
  `WordPress sync failed: WordPress page 1 request failed: HTTP 403 Forbidden - <title>Bot Verification</title>`。
- 即 CMS（cms.jaisong1n.com，Cloudflare 前置）对 GitHub Actions 构建机（数据中心 IP）的公开 REST 请求触发了 bot 防护挑战；本机直连 CMS 公开接口为 200，说明为构建机 IP/UA 维度拦截，且可能随时间缓解。
- 另有一个 run（31118854269）从 08-06 16:10 起卡在 `in_progress` 约 20 小时，已用 `gh run cancel` 清理。

## 修复与验证

- 用 `gh run rerun 31117580417` 重跑失败构建，结论 `success`。
- 复测 id=335：`wp=publish dispatch=accepted build=success/success deploy=deployed page=reachable`；线上详情页 HTTP 200 且标题正确。
- 若后续构建再次出现 403 Bot Verification，需在 Cloudflare/主机面板放行 GitHub Actions 请求（IP 或 `/wp-json` 路径），否则下次发布仍可能失败。

## 安全

- 全程未打印任何凭据；仅使用只读状态查询与 `gh run rerun/cancel` 恢复构建。

## 跟进：Bot 防护 403 的根治（同日）

- 根因定位（已修正）：cms.jaisong1n.com 前置 Cloudflare，源站为 **Hostinger**（响应头 `platform: hostinger`、`panel: hpanel`、LiteSpeed）。"Bot Verification" 页面为 Hostinger 平台级 bot 防护注入（社区多起报告：面板开关关闭、DNS 移至 Cloudflare 后仍随机出现）；GitHub Actions 构建机的数据中心 IP 被启发式误判，故障间歇性。
- 代码加固：`scripts/wordpress-sync/network.mjs` 新增 `SYNC_USER_AGENT`（明确的项目标识，非伪装浏览器），`scripts/sync-wordpress.mjs` 与 `scripts/wordpress-sync/media.mjs` 的请求头统一携带；本地语法检查通过，带新 UA 请求 site-snapshot 返回 200。
- 面板级根治（需用户操作）：Hostinger hPanel → Websites → Security，确认 Bot Protection 关闭、检查 WAF 日志与白名单；若均关闭则联系 Hostinger 客服放行 GitHub Actions 对 `/wp-json/*` 的请求（平台层防护，用户开关不生效）。
- 本改动已提交并推送，由 CI 实测同步是否稳定恢复。
- CI 验证期间 GitHub Actions 自身出现基础设施故障：`Set up job` 报 `Failed to resolve action download info. Error: Service Unavailable`，后续多个 run 长时间排队/被并发取消；属 GitHub 侧问题，非 bot 防护。待 GitHub 恢复后重跑验证。
