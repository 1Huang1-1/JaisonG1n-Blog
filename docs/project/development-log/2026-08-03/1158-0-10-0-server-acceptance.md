# Site Manager 0.10.0 生产部署与服务端验收

- 时间：2026-08-03 11:58（Asia/Shanghai）
- 会话或模块：Codex / Site Manager 0.10.0 生产验收（仅服务端）
- 当前分支：`master`（51c9edb，与 origin/master 一致）
- 工作目录：`D:\Blog\JaisonG1n-Blog`
- 状态：已完成服务端验收（部署已由管理员先行完成）
- 是否已提交：本会话未创建 commit/tag/release；本日志为本地未跟踪文件
- 是否已部署：生产 WordPress 实时版本为 0.10.0（capabilities 确认），部署动作由管理员先行执行

## 任务目标

按用户授权完成 Site Manager 0.10.0 生产部署验收（仅服务端，不操作 OpenClaw）：核验版本与工作区、确认新能力门控、用专用验收对象执行原地修改验收，不修改 #102、不创建 commit/tag/release、不手动调用 GitHub workflow。

## 实际完成（生产只读与受控验收）

- 仓库与包核验：`master` = `origin/master` = `51c9edb`，工作区干净；ZIP SHA-256 `b6acb045507f9eed3ce2466f1d9c92f0fa7ec7cd4266dc0aa4de1e863c0aae19` 与用户给定值一致；包校验 13 条目、10 个 PHP、唯一根目录。
- 生产状态：capabilities 实时版本 `0.10.0`、schemaVersion `5`；diary 与 article 的 operations 均包含 `prepareUpdatePublished`/`updatePublished`，说明插件与两个新开关已由管理员先行部署并开启（本会话无管理员凭据，无法执行“先关闭再开启”的切换）。
- 回归验证（专用对象）：createDraft、read（含 publishedAt/canonicalUrl/ownership/availableOperations）、updateDraft、preparePublish、publish、deploymentStatus 全部 200/201。
- 原地修改验收对象：#108（diary，slug `ai-acceptance-diary-2026-08-03`）、#109（article，slug `ai-acceptance-article-2026-08-03`）。
- 原地修改验收：两个对象均 prepareUpdatePublished 200（返回精确确认短语与一次性 token）→ updatePublished 200 → 回读核验：ID/slug/status=publish/publishedAt/ownership/canonicalUrl 不变，modifiedAt 更新，title/content 按字节更新；按 slug 查询仅 1 个对象（无重复）；diary 幂等重放 200（idempotentReplay=true）。
- 部署状态：两个验收对象 deployment-status 200，wordpressStatus=publish，buildStatus=not_triggered，dispatchStatus=null（防抖构建尚未运行；构建记录将在 workflow 执行后出现）。

## 遇到的问题与处理

- article 首次 publish 与 updatePublished 幂等重放各遇到一次 `jg_ai_rate_limited`（429，publish/updatePublished 每分钟 5 次的共享限流），等待限流窗口后重试成功；非安全异常，未绕过限流。
- 控制台输出中文显示为 `?` 是终端编码显示问题；已通过字节级比对确认标题/正文存储无乱码。
- 管理员专属步骤（数据库/插件目录备份、ZIP 上传替换、开关切换）本会话无对应凭据，无法复核备份位置；部署已由管理员先行完成。

## 关键决定

- 仅使用专用验收对象，未触碰 #102 或任何正式内容。
- 不执行发布补救、不重试受保护字段失败（本验收未出现此类失败）。
- 两个开关最终状态为开启（由管理员先行开启），可供 OpenClaw 客户端验收。

## 未完成内容

- 生产 dispatch 记录与前台页面：验收对象的构建/部署尚未运行，dispatchStatus/pageStatus 待 GitHub Actions 执行后复核。
- “开关关闭时 capabilities 不含新 operation”的生产态验证：当前生产已开启，无法复现；该门控由本地 Playground（`jg_ai_update_published_disabled`）与 0.9.0→0.10.0 升级测试（默认关闭、无新能力）覆盖。

## 下一步

- 由管理员确认数据库/插件备份位置并归档。
- GitHub Actions 执行后，复核 #108/#109 的 deployment-status（dispatchStatus/buildStatus/deploymentStatus/pageStatus）。
- 可交给 Claude 进行 OpenClaw 客户端验收（生产 capabilities 已包含两个新 operation）。

## 资料来源

- 生产 API 只读/受控调用：capabilities、content 创建/读取/更新/发布、prepare-publish/publish、prepare-update-published/update-published、deployment-status。
- 本仓库提交：`51c9edb`、`4ad293c`；0.10.0 契约与本地 Playground 222 断言。

禁止记录凭据、Token、Authorization、Cookie、Application Password、环境变量值和私密用户数据；本日志未记录这些信息。
