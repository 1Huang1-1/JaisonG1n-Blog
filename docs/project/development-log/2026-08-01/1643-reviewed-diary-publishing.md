# Site Manager 0.8.0 reviewed diary publishing

## 任务目标

- 为 AI Content API 的 diary 草稿增加“准备发布 -> 一次性确认令牌 -> 执行发布”的服务端闭环。
- 将草稿修改权限、受控发布权限和 WordPress 原生发布权限分离。
- 保留 `schemaVersion: 5` 和既有 WordPress 发布后防抖构建链路。

## 实际完成

- 仓库可确认：插件从 `0.7.1` 升级到 `0.8.0`。
- 仓库可确认：新增 `POST /content/diary/{id}/prepare-publish`，原 `/publish` 路由改为必须提交确认令牌、`expectedModifiedAt` 和幂等键。
- 仓库可确认：确认令牌由 `random_bytes(32)` 生成，默认 10 分钟有效；数据库只保存 SHA-256 摘要，并绑定用户、内容类型、内容 ID、修改时间和 `publish` 动作。
- 仓库可确认：新增独立 capability `jg_ai_publish_diary_drafts`。默认角色不拥有该权限；管理员启用设置后只向 `jg_ai_content_editor` 授予该权限，不授予 WordPress 原生 diary publish capability。
- 测试输出可确认：prepare 不改变草稿、不创建 pending；成功 publish 只形成一个合并后的 pending 和一个 Cron；幂等重放不重复形成 pending。
- 仓库可确认：审计新增 `publish_prepare`、`publish_success`、`publish_rejected`、`publish_conflict`、`idempotent_replay`，只保留不可逆短指纹，不保存正文或令牌明文。
- 仓库可确认：AI API 没有 GitHub 调用；发布成功沿用 WordPress 状态/保存 Hook 和现有防抖构建路径。

## 修改文件

- `wordpress-plugin/jaisong1n-site-manager/includes/class-jg-ai-content.php`
- `wordpress-plugin/jaisong1n-site-manager/jaisong1n-site-manager.php`
- `wordpress-plugin/jaisong1n-site-manager/uninstall.php`
- `wordpress-plugin/jaisong1n-site-manager/readme.txt`
- `wordpress-plugin/jaisong1n-site-manager/tests/playground-ai-content.php`
- `tests/wordpress-plugin.test.mjs`
- `tests/wordpress-plugin-upgrade.php`
- `scripts/test-wordpress-plugin-upgrade.mjs`
- `package.json`
- `docs/ai-content-api.md`
- `docs/agents/ai-content-api-usage.md`
- `docs/agents/publishing-policy.md`
- `docs/agents/openclaw-adapter-guide.md`
- `docs/project/current-state.md`
- `docs/project/decisions.md`
- 本日志。

## 测试和验证结果

- 测试输出可确认：`pnpm test` 通过 53/53。
- 测试输出可确认：`pnpm test:wordpress-plugin` 通过 19/19。
- 测试输出可确认：AI Content Playground 通过 82 条断言，覆盖默认权限、显式授权、prepare、令牌缺失/伪造/过期/消费/用户/内容/动作/版本绑定、并发冲突、成功发布、幂等重放、重复发布、审计脱敏和 pending 去重。
- 测试输出可确认：`pnpm test:wordpress-playground` 通过；snapshot schema 5、ETag 304、12 个公开 post type 和 dispatch 测试保持正常。
- 测试输出可确认：`pnpm test:wordpress-upgrade` 完成 `0.7.1 -> 0.8.0` 同目录升级；设置、内容、pending、dispatch history 和 schema 5 保留，受控发布保持默认关闭，令牌 option 实测 `autoload=off`。
- 测试输出可确认：`pnpm check` 检查 320 个文件，0 errors、0 warnings、0 hints。
- 测试输出可确认：Biome 检查通过，未修改文件。
- 测试输出可确认：`WORDPRESS_STRUCTURED_CONTENT_ENABLED=false` 下直接运行 Astro build 与 Pagefind 成功，生成 36 个页面，Pagefind 索引 4 个页面。
- 测试输出可确认：插件包结构验证通过，包含 13 个条目、10 个 PHP 文件；主文件为 `jaisong1n-site-manager/jaisong1n-site-manager.php`。
- 测试输出可确认：`git diff --check` 无输出。

## 真实环境验证结果

- 当前无法确认：本次没有访问或修改真实 WordPress，没有上传插件，没有调用真实 GitHub API，也没有执行生产发布。
- 当前无法确认：Hostinger 上的 0.8.0 实时 capabilities、令牌发布和部署链路需管理员升级后另行验证。

## 遇到的问题与解决过程

- 测试输出可确认：首次两次 `npx` Playground 启动因外部 `fetch failed` 未进入测试。随后使用本机已缓存的 Playground CLI 并重试，取得真实 PHP 断言结果。
- 测试输出可确认：首次行为测试发现 PHP 数组联合不会覆盖原响应中的 `idempotentReplay: false`。创建与发布重放分支改为 `array_replace`，之后重放测试通过。
- 测试输出可确认：WordPress 7 对新建 `autoload=false` option 的实际数据库值为 `off`，因此升级测试改为验证“不属于任何自动加载值”，并保留实际值报告。

## 关键决定

- 发布权限使用 `jg_ai_publish_diary_drafts`，不复用或授予 WordPress 原生发布 capability。
- confirmation token 仅以摘要存储，成功使用后消费；prepare 后内容变化必须重新读取和确认。
- Agent 不直接触发 GitHub，发布只进入既有 WordPress 自动构建链路。
- unpublish 继续拒绝，不随 0.8.0 开放。

## 尚未完成与下一步

- 尚未完成：真实 WordPress 0.8.0 升级和生产端到端验收。
- 下一步：备份 WordPress，上传 0.8.0 ZIP，确认升级替换；在 AI Content API 设置中显式启用 reviewed diary publishing，并确认目标 diary 的 publishable claim。
- 下一步：用专用 AI 用户读取实时 capabilities，依次验证 prepare、人工确认、publish、幂等重放和最终自动部署，且不记录令牌或凭据。

## 提交与部署

- 本日志创建时：本次改动尚未提交；计划在最终 Git 检查后使用 `feat: add reviewed diary publishing` 创建本地提交。
- 部署：未部署，未 push，未上传生产 WordPress。
