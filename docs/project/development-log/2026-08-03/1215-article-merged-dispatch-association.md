# article 已发布原地修改的 dispatch 关联修复

- 时间：2026-08-03 12:15（Asia/Shanghai）
- 会话或模块：Codex / Site Manager dispatch/debounce 关联排查
- 当前分支：`master`（本地）
- 工作目录：`D:\Blog\JaisonG1n-Blog`
- 状态：已完成（根因定位、修复、定向与全量检查、本地提交）
- 是否已提交：将随本会话本地修复提交创建
- 是否已部署：否；未 push/tag/release，未重新部署生产

## 任务目标

排查 article 已发布内容原地修改（updatePublished）成功后未进入 dispatch/debounce 关联的问题：diary #114 正常（accepted→success→deployed→reachable），article #116 修改后 12+ 分钟仍为 not_triggered、无 dispatch 记录。

## 根因（生产证据链）

- 生产事实：article #116 `modifiedAt=2026-08-03T04:15:45Z`；GitHub run `30783914974` 创建时间同为 `04:15:45Z`（合并批次实际 dispatch）；diary #114 `modifiedAt=04:13:48Z` 且其 deployment-status 关联到该 run（accepted/success/deployed）。
- 时序：diary #114 的 updatePublished 于 04:13:48 创建 pending（`triggeredAt=04:13:48`）并计划 cron；04:14:33 无流量触发 WP-Cron；04:15:45 article #116 的 updatePublished 请求把 article ref 合并进同一 pending（`triggeredAt` 保持批次起始时间），该请求触发 cron，04:15:45 一次 dispatch 覆盖两个内容（run 30783914974）。
- 代码缺陷：`record_dispatch()` 记录 `triggeredAt` = pending 批次首次创建时间；`find_latest_record_for_content()` 用 `triggeredAt >= 内容 modifiedAt` 判定覆盖。article 的 `modifiedAt(04:15:45) > 记录 triggeredAt(04:13:48)` → 该记录被过滤 → deployment-status 返回无记录（dispatch=null、build=not_triggered）。diary 的 modifiedAt 恰等于批次起始时间，未被过滤。
- 结论：article 的 updatePublished **已进入** 防抖构建并已被 04:15:45 的 run 部署（page=reachable），缺陷在 deployment-status 的记录关联判定，而非调度缺失。

## 修复

- `record_dispatch()` 在记录中新增 `dispatchedAt = gmdate('c')`（实际 dispatch 尝试时间）。
- `find_latest_record_for_content()` 的覆盖判定改为优先使用 `dispatchedAt`，旧记录（无 dispatchedAt）回退 `triggeredAt`。批次中后合并的内容（modifiedAt 晚于批次起始）现在能正确关联到覆盖它的 dispatch 记录。

## 修改文件

- `wordpress-plugin/jaisong1n-site-manager/includes/class-jg-dispatch.php`
- `wordpress-plugin/jaisong1n-site-manager/tests/playground-deployment-status.php`（回归测试）
- `docs/project/development-log/2026-08-03/1215-article-merged-dispatch-association.md`（本日志）

## 测试与验证

- 部署状态 Playground：105 条断言通过（原 97 + 新增 8 条回归：批次合并后 triggeredAt 不重置、单次 dispatch、dispatchedAt 覆盖后合并内容、diary/article 均能关联）。
- AI Content Playground：222 条断言通过（diary/article updatePublished 对称：各自只触发一次 pending、幂等重放不重复）。
- smoke 通过；0.9.0→0.10.0 升级通过；`pnpm test` 通过；`pnpm check` 320 文件 0 问题；Biome 通过；secret scan 0 命中；包校验通过；`git diff --check` 通过。
- 打包：`wordpress-plugin/dist/jaisong1n-site-manager-0.10.0.zip`，SHA-256 `34307a892c53a50ace9c6478815a39e0054770e6821d0a31a0d7e4cc8fe702cc`。

## 遇到的问题

- npx/Playground CLI 多次瞬时 `fetch failed`（网络抖动），重试后通过。
- 未修改生产验收对象 #116（根因判定不依赖修改对象）；未触碰 #102。

## 关键决定

- 以 `dispatchedAt`（实际 dispatch 时间）作为内容覆盖判定，语义更准确：内容变更于 dispatch 之前即被覆盖；批次起始时间仅用于防抖报告。
- 旧记录回退 `triggeredAt`，保持向后兼容。

## 下一步

- 管理员将修复版 0.10.0 ZIP（`34307a89...`）重新部署到生产后，article #116 的 deployment-status 即可关联到 04:15:45 的 run；新产生的内容变更将直接正常关联。
- 若需验证存量记录，可等待下一次内容触发构建后核对。

## 资料来源

- 生产只读证据：deployment-status（#114/#116）、GitHub Actions runs API。
- 本仓库代码：`includes/class-jg-dispatch.php`（schedule/record_dispatch/find_latest_record_for_content）。

禁止记录凭据、Token、Authorization、Cookie、Application Password、环境变量值和私密用户数据；本日志未记录这些信息。
