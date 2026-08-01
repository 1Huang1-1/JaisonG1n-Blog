# AI diary updateDraft 0.7.1

- 日期：2026-08-01
- 会话或模块：OpenClaw Blog Agent 0.2.0-B / WordPress AI Content API
- 证据范围：仓库实现、本地 Node 测试、WordPress Playground、升级模拟和插件包验证

## 任务目标

为 AI Content API 安全开放 diary 草稿修改能力，修复 `modifiedAt` 对 WordPress 零日期和无效日期的错误转换，并生成可升级的 Site Manager 插件包。

## 实际完成

- 仓库可确认：现有 PATCH 路由和通用更新函数已经存在，但 capabilities 因使用空 post 调用权限检查而不会公开 `updateDraft`，同时旧实现可作用于多个内容类型并写入结构化字段。
- 仓库可确认：`updateDraft` 现仅向 diary 暴露；PATCH 仅接受 `title`、`content`、`excerpt`、`slug` 和并发字段，且目标必须为 diary draft。
- 仓库可确认：拒绝 status、author、meta 和其他未知字段；不调用结构化 meta 写入、发布接口、删除接口或 GitHub dispatch。
- 仓库可确认：请求必须显式携带最近读取的 `expectedModifiedAt`。零日期读取为 `null` 时允许显式提交 JSON `null`；首次成功更新后返回正常 UTC 时间。
- 仓库可确认：`modifiedAt` 统一返回 ISO 8601 UTC 字符串或 `null`，零日期、空日期和 `strtotime()` 失败均返回 `null`。
- 仓库可确认：成功审计只记录操作、类型、post ID、状态和发生变化的字段名，不记录正文。
- 仓库可确认：插件版本从 `0.7.0` 升级到 `0.7.1`，schemaVersion 保持 `5`；未修改或占用后续 `0.8.0` 版本。

## 修改文件

- `wordpress-plugin/jaisong1n-site-manager/includes/class-jg-ai-content.php`
- `wordpress-plugin/jaisong1n-site-manager/tests/playground-ai-content.php`
- `wordpress-plugin/jaisong1n-site-manager/jaisong1n-site-manager.php`
- `wordpress-plugin/jaisong1n-site-manager/readme.txt`
- `docs/ai-content-api.md`
- `docs/agents/ai-content-api-usage.md`
- `tests/wordpress-plugin.test.mjs`
- `tests/wordpress-plugin-upgrade.php`
- `scripts/test-wordpress-plugin-upgrade.mjs`
- `package.json`
- 本日志

## 测试和验证结果

- 测试输出可确认：`pnpm test` 通过 `52/52`。
- 测试输出可确认：`pnpm test:wordpress-plugin` 通过 `18/18`。
- 测试输出可确认：`pnpm test:wordpress-ai-content` 通过 `37` 项 Playground 断言，覆盖 capabilities、标题/正文/多字段更新、无变化、非 diary、非 draft、字段注入、并发冲突、缺失内容、权限、时间契约、回读一致性和不生成构建 pending。
- 测试输出可确认：`pnpm test:wordpress-playground` 成功，schemaVersion 5、ETag 304、12 类公开内容触发注册和 GitHub API 版本检查均通过。
- 测试输出可确认：`pnpm test:wordpress-upgrade` 成功完成 `0.7.0 -> 0.7.1` 同目录替换；设置、内容类型、pending、dispatch history、角色边界和 schemaVersion 5 保留。
- 测试输出可确认：`pnpm check` 检查 320 个文件，0 errors、0 warnings、0 hints。
- 测试输出可确认：本轮 JavaScript/JSON 的 Biome 检查通过；`git diff --check` 通过。
- 测试输出可确认：ZIP 包结构验证通过，唯一根目录为 `jaisong1n-site-manager/`，主文件为 `jaisong1n-site-manager/jaisong1n-site-manager.php`，包含 10 个 PHP 文件。
- 测试输出可确认：ZIP SHA-256 为 `3bed1f134a6028e4ce83509c23a6fecdb0b113f88858cb46c02a75ca1865fde5`。

## 真实环境验证结果

- 当前无法确认：本会话没有访问或修改真实 WordPress，没有上传插件，也没有调用真实 GitHub API。
- 当前无法确认：Hostinger 上升级后的实时 capabilities 和 OpenClaw 端到端更新仍待管理员安装 `0.7.1` 后验证。

## 遇到的问题与解决过程

- 测试输出可确认：Playground 新建草稿可能使用 WordPress 零 GMT 修改日期。最初只允许字符串会阻塞首次更新，随后改为要求参数必须显式存在，并允许最新读取值为 JSON `null`；成功更新后恢复正常 UTC 字符串并发锁。
- 测试输出可确认：一次升级模拟因 Playground 依赖下载 `fetch failed` 退出；网络恢复后重试成功，功能测试结果有效。
- 仓库可确认：现有 PATCH 的结构化字段写入范围过宽；实现改用独立 diary 更新清理器，不再调用通用 meta 写入。

## 关键决定

- 使用补丁版本 `0.7.1`，不覆盖后续 `0.8.0` 发布确认设计。
- capabilities 只按当前服务端设置和 WordPress 内容类型编辑能力向 diary 暴露 `updateDraft`。
- API 创建仍兼容 `contentHtml`；本次 diary 更新契约使用 `content`，避免开放其他结构化字段。
- 遵循项目记录规则，本会话不直接修改 `docs/project/current-state.md`；本日志作为后续专门项目汇总任务的事实来源。

## 尚未完成与下一步

- 当前无法确认：真实 WordPress 后台尚未安装 `0.7.1` ZIP。
- 当前无法确认：生产 capabilities、真实 diary 草稿的并发冲突和 OpenClaw 0.2.0-B 调用尚未验收。
- 下一步：管理员备份 WordPress，上传并替换 `0.7.1`，确认 capabilities 仅对 diary 出现 `updateDraft`，再用测试草稿执行读取、更新、冲突和回读验证。

## 提交与部署

- 记录生成时：本地改动尚未提交；本日志与实现将纳入本会话的本地提交。
- 部署：未部署，未 push，未上传真实 WordPress。
