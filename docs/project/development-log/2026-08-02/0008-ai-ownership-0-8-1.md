# Site Manager 0.8.1 受控发布权限模型修复

- 时间：2026-08-02 00:08（Asia/Shanghai）
- 会话或模块：Codex / Site Manager 受控发布权限模型统一
- 当前分支：`codex/ai-content-api-0-7`
- 工作目录：`D:\Blog\JaisonG1n-Blog`
- 状态：已完成（本地实现、测试、打包、本地提交；未部署）
- 是否已提交：是（本地提交，未 push）
- 是否已部署：否；未上传生产 WordPress，未执行任何 publish

## 任务目标

修复 Site Manager 受控发布权限模型不一致：`can_publish()` 额外要求 `current_user_can('edit_post')`，导致 AI Content API 已按 `_jg_ai_owner_user_id` / `_jg_ai_editable` 授权的草稿无法进入 `prepare-publish`。统一 `updateDraft` 与 reviewed publish 的文章级授权，发布补丁版本 0.8.1。

## 实际完成（仓库可确认）

- 插件版本从 `0.8.0` 升级到 `0.8.1`；`schemaVersion` 保持 `5`。
- 新增统一对象级授权 `can_manage_ai_content()`：原生 `edit_post` 通过，或（当前用户是 AI 所有者：原生作者或 `_jg_ai_owner_user_id`，且 `_jg_ai_editable` 为真）。
- `can_read()` 增加 AI 所有者（`_jg_ai_owner_user_id`）读取条件；`can_update()` 与 `can_publish()` 均改用 `can_manage_ai_content()`。
- `prepare-publish` 与 `publish` 的拒绝审计细分为 `setting_disabled`、`missing_publish_capability`、`ownership_denied`、`edit_denied`、`not_publishable`、`not_draft`；外部仍统一返回 403 `jg_ai_publish_forbidden`。
- 新增管理员受控所有权修复：`repair_ai_ownership()` 与后台 `jg_ai_sync_owner` 按钮，仅在 `_jg_ai_owner_user_id` 为有效用户、`_jg_ai_created` 为真、作者与所有者不一致时同步作者；不批量改写普通日记作者。
- `createDraft` 确认显式写入 `post_author = get_current_user_id()`，并写入 `_jg_ai_owner_user_id`、`_jg_ai_created`、`_jg_ai_editable`、`_jg_ai_publishable`。

## 修改文件

- `wordpress-plugin/jaisong1n-site-manager/jaisong1n-site-manager.php`（版本 0.8.1）
- `wordpress-plugin/jaisong1n-site-manager/readme.txt`（Stable tag 与 changelog）
- `wordpress-plugin/jaisong1n-site-manager/includes/class-jg-ai-content.php`（授权模型）
- `wordpress-plugin/jaisong1n-site-manager/tests/playground-ai-content.php`（新增覆盖）
- `tests/wordpress-plugin.test.mjs`（版本与授权源码断言）
- `tests/wordpress-plugin-upgrade.php`、`scripts/test-wordpress-plugin-upgrade.mjs`（0.8.0 -> 0.8.1 升级测试）
- `package.json`（verify 脚本指向 0.8.1 ZIP）
- `docs/ai-content-api.md`、`docs/agents/ai-content-api-usage.md`、`docs/agents/publishing-policy.md`、`docs/agents/openclaw-adapter-guide.md`（0.8.1 模型）
- `docs/project/decisions.md`、`docs/project/current-state.md`（本任务按用户要求更新）
- `docs/project/development-log/2026-08-02/0008-ai-ownership-0-8-1.md`（本日志）

## 测试与验证

- 命令或验证方式：`pnpm test`。
  - 实际结果：54/54 通过，0 失败（含 0.8.1 版本断言与统一授权源码断言）。
  - 是否通过：通过。
- 命令或验证方式：WordPress Playground AI Content 测试（`npx @wp-playground/cli php --auto-mount ... tests/playground-ai-content.php`）。
  - 实际结果：`{"ok":true,"assertions":111,"schemaVersion":5}`；覆盖 AI 草稿作者写入、owner 一致、AI owner updateDraft/preparePublish、作者漂移场景、非 owner 拒绝、设置关闭/缺 capability/未标记/非 draft 拒绝、prepare 不改变状态且不触发构建、审计拒绝原因、受控所有权修复。
  - 是否通过：通过。
- 命令或验证方式：WordPress Playground smoke（`playground-smoke.php`）。
  - 实际结果：退出码 0；schemaVersion 5、12 类公开 post type、dispatch API 2026-03-10 正常。
  - 是否通过：通过。
- 命令或验证方式：`pnpm test:wordpress-upgrade`（0.8.0 -> 0.8.1 同目录替换）。
  - 实际结果：`ok=true`、`replacementVersion=0.8.1`、`ownershipRepairApplied=true`、`strangerManageRejected=true`；设置/内容/pending/dispatch history 保留，受控发布保持默认关闭，AI 草稿 meta 保留，作者漂移可受控修复，陌生用户仍无法管理。
  - 是否通过：通过。
- 命令或验证方式：`pnpm check`。
  - 实际结果：320 文件，0 errors、0 warnings、0 hints。
  - 是否通过：通过。
- 命令或验证方式：`npx @biomejs/biome check`（改动的 JS/MJS）。
  - 实际结果：2 个文件检查通过，无修改。
  - 是否通过：通过。
- 命令或验证方式：`git diff --check`。
  - 实际结果：退出码 0，无空白错误输出。
  - 是否通过：通过。
- 命令或验证方式：`pnpm package:wordpress-plugin` + `verify:wordpress-plugin-package`。
  - 实际结果：ZIP `wordpress-plugin/dist/jaisong1n-site-manager-0.8.1.zip`，13 个条目，唯一根目录 `jaisong1n-site-manager/`；SHA-256 `e6bbec4ddb1b5a2583520160fb6706fa1a61267613a1a2c06e10f5f1d905a560`。
  - 是否通过：通过。

## 遇到的问题与解决过程

- 测试输出可确认：首次 Playground AI Content 运行出现 WordPress 致命错误页且无错误详情；改用 `--define-bool WP_DEBUG true --define-bool WP_DEBUG_DISPLAY true` 后定位到漂移场景断言错误。
- 测试输出可确认：漂移场景的 `jg_dispatch_pending` 断言失败并非 prepare 触发构建，而是测试先前插入已发布日记 fixture 遗留的 pending；在漂移 prepare 前显式清理 pending 后断言通过。
- 仓库可确认：`JG_Dispatch::post_saved`/`post_meta_changed` 仅对 `publish` 状态内容调度 pending，草稿更新与 prepare 不产生构建（代码可确认）。

## 关键决定

- 不删除 `edit_post` 检查，而是保留为附加允许条件，与 AI ownership+editable 形成 OR 关系；不给 AI 角色增加 `edit_others_jg_diarys`。
- editable 标记只授予读取，不授予写入或发布；`_jg_ai_publishable` 单独存在不能授权发布其他用户的文章。
- 拒绝原因只在审计中记录，外部错误码与消息保持不变，避免泄露内部原因。
- 所有权修复仅限 AI 创建且作者漂移的受控场景；正常日记作者不做批量修改。

## 未完成内容

- 0.8.1 未安装到真实 WordPress，未执行生产端到端验收。
- 草稿 #91 的生产数据修复（post_author=2、`_jg_ai_owner_user_id=2`）需管理员在 WordPress 后台执行后另行验证。

## 下一步

- 管理员备份 WordPress，上传并替换 0.8.1 ZIP，确认 capabilities 行为不变。
- 对草稿 #91：编辑并设置作者为 jaisong1n-ai-writer（用户 ID 2）；如 `_jg_ai_created` 与 `_jg_ai_owner_user_id` 已存在且作者漂移，可使用"同步作者为 AI 所有者"按钮或 `repair_ai_ownership()`。
- 以 jaisong1n-ai-writer 验证：capabilities → `POST diary/91/prepare-publish`（只报告 token 存在性/长度/短指纹，不输出明文）→ 回读草稿仍为 draft → 审计出现 `publish_prepare` 且无 `publish_success`；不执行真正 publish。

## 资料来源

- Git commit：`2753ca5`（0.8.0 基线）与本会话本地提交 `fix: align AI ownership and reviewed publishing`。
- 0.8.1 源码与测试：`wordpress-plugin/jaisong1n-site-manager/`、`tests/`、`scripts/`。
- 项目文档：`docs/project/current-state.md`、`docs/project/decisions.md`、`docs/ai-content-api.md`、`docs/agents/`。
- 生产只读证据：此前 403 诊断会话的 capabilities、users/me、diary 读取结果（见 `2026-08-01/2258-prepare-publish-403-diagnosis.md`）。

禁止记录凭据、Token、Authorization、Cookie、Application Password、环境变量值和私密用户数据；本日志未记录这些信息。
