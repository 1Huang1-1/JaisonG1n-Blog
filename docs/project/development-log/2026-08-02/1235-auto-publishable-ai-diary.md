# Site Manager 0.8.3 AI 自建日记自动受控发布资格

- 时间：2026-08-02 12:35（Asia/Shanghai）
- 会话或模块：Codex / Site Manager 自动 publishable
- 当前分支：`codex/ai-content-api-0-7`
- 工作目录：`D:\Blog\JaisonG1n-Blog`
- 状态：已完成（本地实现、测试、打包、待提交）
- 是否已提交：将随本会话本地提交创建
- 是否已部署：否；未上传生产 WordPress，未执行 publish，未触发 workflow

## 任务目标

让 AI 通过受控 AI Content API 自建的 diary 草稿在满足权限条件时自动具备受控发布资格（`_jg_ai_publishable=1`），取消每篇草稿人工进入 WordPress 后台勾选的步骤，同时保留全部发布安全边界。

## 实际完成（仓库可确认）

- 新增内容安全开关 `auto_publishable_ai_diaries`（默认 `false`），标签“AI 自建日记自动允许进入受控发布流程”，说明“自动允许的只是进入两阶段发布流程，不是自动公开发布”。
- `create_content` 创建 diary 草稿后调用新增 `auto_publishable_diary()`：仅当 contentType=diary、状态 draft、`reviewed_diary_publish` 开启、当前用户拥有 `jg_ai_publish_diary_drafts`、`post_author` 与 `_jg_ai_owner_user_id` 均为当前用户、开关开启时，才把 `_jg_ai_publishable` 从 false 覆写为 true。
- 自动标记只授予进入 `preparePublish` 的资格；confirmationToken、expectedModifiedAt、幂等键、精确确认与 draft 检查全部保留；不自动发布、不触发构建。
- 后台人工创建、导入、其他作者、非 diary、缺 capability、全局设置关闭时不自动标记；历史草稿不批量修改；已手动设置保持不变。

## 修改文件

- `wordpress-plugin/jaisong1n-site-manager/jaisong1n-site-manager.php`（版本 0.8.3）
- `wordpress-plugin/jaisong1n-site-manager/readme.txt`（Stable tag 与 changelog）
- `wordpress-plugin/jaisong1n-site-manager/includes/class-jg-ai-content.php`（自动标记逻辑）
- `wordpress-plugin/jaisong1n-site-manager/includes/class-jg-settings.php`（内容安全开关）
- `wordpress-plugin/jaisong1n-site-manager/tests/playground-ai-content.php`（新增场景）
- `tests/wordpress-plugin.test.mjs`、`tests/wordpress-plugin-upgrade.php`、`scripts/test-wordpress-plugin-upgrade.mjs`
- `package.json`
- `docs/ai-content-api.md`、`docs/agents/ai-content-api-usage.md`、`docs/agents/publishing-policy.md`、`docs/agents/openclaw-adapter-guide.md`
- `docs/project/decisions.md`、`docs/project/current-state.md`
- `docs/project/development-log/2026-08-02/1235-auto-publishable-ai-diary.md`（本日志）

## 测试与验证

- 命令或验证方式：`pnpm test`。
  - 实际结果：55/55 通过（含 0.8.3 版本断言与自动 publishable 源码断言）。
  - 是否通过：通过。
- 命令或验证方式：WordPress Playground AI Content（`playground-ai-content.php`）。
  - 实际结果：`{"ok":true,"assertions":129,"schemaVersion":5}`；新增覆盖：AI owner 创建 diary 自动 publishable 且保持 draft、不触发构建、prepare 无需人工 claim、无 token 发布被拒、article 不自动标记、后台人工创建不标记、非 owner 不标记、缺 capability 不标记、设置关闭不标记；原有创建/更新/发布/幂等/审计断言全部保留。
  - 是否通过：通过。
- 命令或验证方式：WordPress Playground 部署状态（`playground-deployment-status.php`）。
  - 实际结果：94 条断言通过。
  - 是否通过：通过。
- 命令或验证方式：WordPress Playground smoke（`playground-smoke.php`）。
  - 实际结果：退出码 0。
  - 是否通过：通过。
- 命令或验证方式：`pnpm test:wordpress-upgrade`（0.8.2 -> 0.8.3）。
  - 实际结果：`ok=true`、`replacementVersion=0.8.3`；设置/内容/history 保留；`auto_publishable_ai_diaries` 默认关闭；历史 AI 草稿 `_jg_ai_publishable` 保持 false（未批量修改）。
  - 是否通过：通过。
- 命令或验证方式：`pnpm check`。
  - 实际结果：320 文件，0 errors、0 warnings、0 hints。
  - 是否通过：通过。
- 命令或验证方式：Biome（改动的 JS/MJS）。
  - 实际结果：通过，无修改。
  - 是否通过：通过。
- 命令或验证方式：`git diff --check`。
  - 实际结果：退出码 0。
  - 是否通过：通过。
- 命令或验证方式：密钥扫描（diff 内 token/secret 模式）。
  - 实际结果：未发现凭据模式。
  - 是否通过：通过。
- 命令或验证方式：`pnpm package:wordpress-plugin` + 包校验。
  - 实际结果：`wordpress-plugin/dist/jaisong1n-site-manager-0.8.3.zip`，13 条目，10 个 PHP 文件；SHA-256 见本会话提交报告。
  - 是否通过：通过。

## 遇到的问题与解决过程

- 测试输出可确认：JS 源码断言中 `$contract` 的 `$` 被正则当作行尾锚点导致断言失败；转义为 `\$contract` 后通过。
- 测试输出可确认：Biome 要求长 assert.match 换行；按格式修正后通过。

## 关键决定

- 开关默认关闭（安全优先），生产启用后微信创建→自动标记→prepare→精确确认→publish 流程成立。
- 自动标记仅限 createDraft 创建的 AI-owned draft diary；不批量修改历史，不授予 `edit_others_*`，不自动发布。

## 未完成内容

- 0.8.3 未安装到真实 WordPress，未执行生产端到端验收。

## 下一步

- 管理员备份 WordPress，上传并替换 0.8.3 ZIP。
- 在 博客管理→内容安全 显式开启“AI 自建日记自动允许进入受控发布流程”，并确认 reviewed diary publishing 与 `jg_ai_publish_diary_drafts` 已就绪。
- 用测试草稿验证：AI 创建 diary 草稿即 publishable → preparePublish 成功 → 回读仍为 draft → 不执行真实发布。

## 资料来源

- Git commit：`96bef3a`（0.8.2 基线）与本会话本地提交。
- 0.8.3 源码与测试：`wordpress-plugin/jaisong1n-site-manager/`、`tests/`、`scripts/`。
- 项目文档：`docs/project/current-state.md`、`docs/project/decisions.md`、`docs/ai-content-api.md`、`docs/agents/`。

禁止记录凭据、Token、Authorization、Cookie、Application Password、环境变量值和私密用户数据；本日志未记录这些信息。
