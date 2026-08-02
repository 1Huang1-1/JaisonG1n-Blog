# Site Manager 0.9.0 article 受控发布

- 时间：2026-08-02 20:03（Asia/Shanghai）
- 会话或模块：Codex / Site Manager 文章受控发布
- 当前分支：`codex/ai-content-api-0-7`
- 工作目录：`D:\Blog\JaisonG1n-Blog`
- 状态：已完成（本地实现、定向测试与全量检查、打包，待提交）
- 是否已提交：将随本会话本地提交创建
- 是否已部署：否；未上传生产 WordPress，未执行 publish，未触发 workflow

## 任务目标

让 article 复用 diary 已验证的受控发布基础，实现 updateDraft、preparePublish、publish 与自动发布资格；不重新设计发布系统，不复制整份 diary 逻辑。

## article 数据模型审计结论

- content type：`article`；原生 post type：`post`；标题/正文/摘要/slug 均为原生字段（post_title/post_content/post_excerpt/post_name）。
- Astro 最终路由：`/posts/{slug}/`（`permalinkConfig.enable=false`，WordPress alias 即 slug）。
- 分类/标签为原生 taxonomy，createDraft 契约已有 `fields.tags/categories`；特色图无稳定契约，按需求保留到媒体版本，未纳入 0.9.0。
- 作者字段：post_author 与 `_jg_ai_owner_user_id`（AI owner）。

## 实际完成（仓库可确认）

- `updateDraft` 扩展到 article：白名单 title/content/excerpt/slug；status 必须 draft；`expectedModifiedAt` 必填且支持 null；no-op 400；白名单外字段 400；stale 409；非 owner 404；更新后回读验证；审计只记字段名。diary 与 article 复用同一更新内部实现（`normalize_draft_update`）。
- 新增独立 capability `jg_ai_publish_article_drafts`；内容安全新增“审核制文章发布”与“AI 自建文章自动允许进入受控发布流程”（默认关闭）；不授予原生 publish_posts/edit_others。
- `prepare-publish`/`publish`/token 存储/校验/幂等键全部参数化为内容类型：token 绑定 contentType/ID/modifiedAt/publish action；发布只产生一次构建 pending；diary 与 article 能力互相独立。
- 自动 publishable：article 通过 AI API 创建、draft、作者与 AI owner 均为当前用户、article 受控能力与开关开启时写入 `_jg_ai_publishable=1`；后台人工/导入/其他作者/仅 editable 不自动标记。
- 部署状态复用：article canonical URL `/posts/{slug}/`；deployment-status 按 article contentRef 关联正确 dispatch 记录。

## 修改文件

- `wordpress-plugin/jaisong1n-site-manager/jaisong1n-site-manager.php`（版本 0.9.0）
- `wordpress-plugin/jaisong1n-site-manager/readme.txt`（Stable tag 与 changelog）
- `wordpress-plugin/jaisong1n-site-manager/includes/class-jg-ai-content.php`（article 更新/发布/能力/自动标记）
- `wordpress-plugin/jaisong1n-site-manager/includes/class-jg-settings.php`（两个新开关）
- `wordpress-plugin/jaisong1n-site-manager/uninstall.php`（移除 article capability）
- `wordpress-plugin/jaisong1n-site-manager/tests/playground-ai-content.php`（article 场景）
- `wordpress-plugin/jaisong1n-site-manager/tests/playground-deployment-status.php`（article 部署状态）
- `tests/wordpress-plugin.test.mjs`、`tests/wordpress-plugin-upgrade.php`、`scripts/test-wordpress-plugin-upgrade.mjs`
- `package.json`
- `docs/ai-content-api.md`、`docs/agents/ai-content-api-usage.md`、`docs/agents/publishing-policy.md`、`docs/agents/openclaw-adapter-guide.md`
- `docs/project/decisions.md`、`docs/project/current-state.md`、`docs/project/JaisonG1n-personal-content-os-roadmap.md`
- `docs/project/development-log/2026-08-02/2003-article-reviewed-publishing.md`（本日志）

## 测试与验证

开发期间使用定向测试，功能完成后统一执行全量检查：

- `pnpm test`：全通过（含 0.9.0 版本断言与 article 受控发布源码断言）。
- AI Content Playground：`{"ok":true,"assertions":171,"schemaVersion":5}`；覆盖 article 作者/owner 一致、updateDraft 全字段与回读、白名单、no-op、stale 409、非 owner 404、diary/article 能力独立、缺 article 能力不暴露 publish、设置关闭拒绝、自动 publishable、后台创建不自动标记、prepare 不写入不触发构建、token 缺失/伪造/过期/消费后重放、publish 成功、幂等重放、构建 pending 只一次、diary 原有流程全部保留。
- 部署状态 Playground：97 条断言（新增 article canonical URL、article dispatch 记录关联、五层状态）。
- smoke：通过。
- 升级测试 0.8.3→0.9.0：通过（默认关闭、不扩大权限、设置/内容/history 保留）。
- `pnpm check`：320 文件 0 errors/warnings/hints；Biome 通过；secret scan 无凭据；`git diff --check` 通过；包校验通过。

## 遇到的问题与解决过程

- 测试输出可确认：`wp_set_current_user($user_id)` 在用户 ID 未变时返回缓存的旧 WP_User 对象，导致角色新增能力对当前用户不可见；测试中先 `wp_set_current_user(0)` 再设回用户以强制刷新（生产环境设置变更发生在独立请求，无此问题）。
- 测试输出可确认：既有 `jg_ai_test_prepare/publish` 辅助函数硬编码 diary 路由；增加可选 contentType 参数并保持默认 diary，既有调用不变。
- 测试输出可确认：npx/Playground CLI 多次出现 `fetch failed` 瞬时网络错误；改用本机缓存 CLI 并重试后通过。

## 关键决定

- 分类/标签/特色图写入不纳入 0.9.0（契约未稳定，保留到媒体版本）。
- article 使用独立 capability 与设置，与 diary 完全隔离；能力缺失或设置关闭时 capabilities 不暴露 publish。
- 自动 publishable 只授予两阶段流程资格；发布仍需 token、expectedModifiedAt、幂等键与精确确认。

## 未完成内容

- 0.9.0 未安装到真实 WordPress，未执行生产验收。
- 文章分类/标签/特色图更新与媒体工作流未实现（后续版本）。

## 下一步

- 管理员备份 WordPress，上传并替换 0.9.0 ZIP。
- 在内容安全开启“审核制文章发布”与“AI 自建文章自动允许进入受控发布流程”，确认 capabilities 为 article 暴露 preparePublish/publish。
- 用测试文章验证：AI 创建 article 草稿即 publishable → preparePublish 成功 → 回读仍为 draft → 不执行真实发布。

## 资料来源

- Git commit：`9116ff4`（0.8.3 生产基线）与本会话本地提交。
- 0.9.0 源码与测试：`wordpress-plugin/jaisong1n-site-manager/`、`tests/`、`scripts/`。
- Astro 路由：`src/pages/posts/[...slug].astro`、`src/utils/post-url.ts`、`src/config/permalinkConfig.ts`。
- 项目文档：`docs/project/current-state.md`、`docs/project/decisions.md`、`docs/ai-content-api.md`、`docs/agents/`。

禁止记录凭据、Token、Authorization、Cookie、Application Password、环境变量值和私密用户数据；本日志未记录这些信息。
