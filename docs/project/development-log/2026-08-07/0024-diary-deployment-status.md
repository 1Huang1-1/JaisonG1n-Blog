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
