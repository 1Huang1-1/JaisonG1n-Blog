# Site Manager 0.10.0 已发布内容原地修改

- 时间：2026-08-03 09:53（Asia/Shanghai）
- 会话或模块：Codex / Site Manager 已发布内容原地修改
- 当前分支：`master`（本地）
- 工作目录：`D:\Blog\JaisonG1n-Blog`
- 状态：已完成（本地实现、定向与全量检查、打包，待提交）
- 是否已提交：将随本会话本地提交创建
- 是否已部署：否；未上传生产 WordPress，未执行真实发布/修改，未触发 workflow

## 任务目标

为 diary 与 article 实现“已发布内容原地修改”：两阶段（prepare → 精确确认 → execute），仅允许修改 title/excerpt/content，保证 ID、contentType、slug、status、post_date/post_date_gmt、作者与 AI 所有权不变，并复用既有部署状态跟踪。

## 实际完成（仓库可确认）

- 新增 operation `prepareUpdatePublished` / `updatePublished`（diary/article 分别声明），路由为 `POST /content/{type}/{id}/prepare-update-published` 与 `/update-published`。
- 独立 capability `jg_ai_update_published_diaries` / `jg_ai_update_published_articles`，内容安全新增“审核制已发布日记修改”“审核制已发布文章修改”（默认关闭），不授予原生权限；diary/article 能力隔离。
- prepare：校验类型/存在/status=publish/设置/能力/所有权/editable/expectedModifiedAt/字段白名单（proposedTitle/proposedExcerpt/proposedContent）/非空/大小/no-op；返回变更预览、精确确认短语、10 分钟一次性 token（绑定用户、类型、对象、版本、内容哈希、update_published action）、expiresAt 与 protectedFields；不写库不触发构建。
- execute：token 校验（无效/过期/已用/不匹配）、幂等键重放与冲突、锁防并发；更新前保存受保护字段，更新后逐项比对；变化返回 `jg_ai_protected_field_changed`，回读不一致返回 `jg_ai_readback_verification_failed`，均不做重新发布补救；成功后 `post_modified` 更新并进入既有防抖构建（`JG_Dispatch::post_saved`），不再触发重复 pending。
- read 契约补充：稳定 `publishedAt`、`canonicalUrl`、安全 `ownership`（isAuthor/isAiOwner/aiOwned/editable）、`availableOperations`。
- 稳定错误码覆盖需求语义（见 API 文档与契约）。
- OpenClaw 侧：本机无 OpenClaw 仓库；输出 `docs/contracts/openclaw-update-published-spec.md` 精确实施规范，客户端必须等服务端 capabilities 出现后再启用，不伪造支持。

## 修改文件

- `wordpress-plugin/jaisong1n-site-manager/jaisong1n-site-manager.php`（版本 0.10.0）
- `wordpress-plugin/jaisong1n-site-manager/readme.txt`（Stable tag 与 changelog）
- `wordpress-plugin/jaisong1n-site-manager/includes/class-jg-ai-content.php`（核心实现）
- `wordpress-plugin/jaisong1n-site-manager/includes/class-jg-settings.php`（两个新开关）
- `wordpress-plugin/jaisong1n-site-manager/uninstall.php`（移除两个新 capability）
- `wordpress-plugin/jaisong1n-site-manager/tests/playground-ai-content.php`（0.10.0 场景）
- `tests/wordpress-plugin.test.mjs`、`tests/wordpress-plugin-upgrade.php`、`scripts/test-wordpress-plugin-upgrade.mjs`
- `package.json`
- `docs/ai-content-api.md`、`docs/agents/ai-content-api-usage.md`、`docs/agents/publishing-policy.md`、`docs/agents/openclaw-adapter-guide.md`
- `docs/contracts/openclaw-update-published-spec.md`（新增）
- `docs/project/decisions.md`、`docs/project/current-state.md`
- `docs/project/development-log/2026-08-03/0953-published-inplace-update.md`（本日志）

## 测试与验证

开发期间运行定向测试（AI Content Playground），完成后统一全量检查：

- `pnpm test`：全部通过（含 0.10.0 版本断言与原地修改源码断言）。
- AI Content Playground：`{"ok":true,"assertions":222,"schemaVersion":5}`；覆盖 diary/article 准备成功、精确确认后原地修改成功、ID/slug/status/publishedAt/post_date/post_date_gmt/作者/所有权不变、modifiedAt 更新、草稿拒绝、updateDraft 修改 publish 拒绝、类型不匹配、diary/article 能力隔离、跨作者拒绝、editable 缺失拒绝、开关关闭拒绝、stale 409、token 过期/篡改/重用拒绝、准备后内容变化 token 失效、幂等不重复、同 key 不同 payload 冲突、不重复 dispatch、受保护字段变化检测、响应与日志不泄露 token。
- 部署状态 Playground：97 条断言通过；smoke 通过；升级测试 0.9.0→0.10.0 通过（新能力默认关闭、不扩大权限）。
- `pnpm check` 320 文件 0 问题；Biome 通过；secret scan 无凭据；`git diff --check` 通过；包校验通过。

## 遇到的问题与解决过程

- 测试输出可确认：`jg_ai_test_update_published` 辅助函数会在请求体加入 `idempotencyKey`，`normalize_published_update` 白名单未放行导致 400；补充 `confirmationToken`/`idempotencyKey` 为传输字段后通过。
- 测试输出可确认：跨作者测试用户缺少类型能力时先命中 capability 门；改用拥有 diary 能力但非所有者的编辑器用户后正确命中 ownership_required。
- 测试输出可确认：article 更新会触发 WordPress 默认分类指派（set_object_terms → taxonomy pending），构建 pending 断言放宽为“包含 content 且重放不重复”；diary 保持严格单类型断言。
- npx/Playground CLI 多次 `fetch failed` 瞬时网络错误，重试后全部通过。

## 关键决定

- 与 `updateDraft` 完全分离，不扩展其接受 publish 状态；分类/标签/特色图保留到媒体版本。
- 受保护字段显式保存并逐项比对，不依赖 WordPress 隐式行为；检测到变化只报错不做补救。
- token 绑定内容哈希，内容变化即失效；幂等键按 `update_published:{type}` 隔离。

## 未完成内容

- 0.10.0 未安装到真实 WordPress，未执行生产验收。
- OpenClaw Tool 未实现（仓库不在本机；规范已输出，待服务端部署与 capabilities 出现）。
- `docs/agents/diary-workflow.md` 为 GBK 乱码文件（历史编码问题），未改动以免损坏，需单独修复后补充说明。

## 下一步

- 管理员备份 WordPress，上传并替换 0.10.0 ZIP，在内容安全开启两个已发布修改开关。
- 用专用 AI 验收 diary/article 执行 prepare→精确确认→execute→回读→deployment-status 全链路；不使用正式文章。
- OpenClaw 侧按 `docs/contracts/openclaw-update-published-spec.md` 实现两个 Tool，capabilities 出现后再启用。

## 资料来源

- Git commit：`e19fa2a`（0.9.0 生产基线）与本会话本地提交。
- 0.10.0 源码与测试：`wordpress-plugin/jaisong1n-site-manager/`、`tests/`、`scripts/`。
- 项目文档：`docs/project/current-state.md`、`docs/project/decisions.md`、`docs/ai-content-api.md`、`docs/agents/`。

禁止记录凭据、Token、Authorization、Cookie、Application Password、环境变量值和私密用户数据；本日志未记录这些信息。
