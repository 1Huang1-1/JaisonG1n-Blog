# WordPress 自动构建触发 0.6.0

- 时间：2026-07-31 17:36（Asia/Shanghai）
- 会话或模块：WordPress Site Manager、GitHub Actions 自动构建链路
- 当前分支：`codex/ai-content-api-0-7`（写日志时只读确认）
- 工作目录：`D:\Blog\JaisonG1n-Blog`
- 状态：部分完成；本地实现和测试完成，真实登录会话验证未执行
- 是否已提交：本任务未 commit、未 push
- 是否已部署：本任务未部署；仅运行本地 mock 构建

## 任务目标

将 WordPress 内容变更接入 GitHub Actions `workflow_dispatch`，支持公开 revision 去重、防抖、失败重试、手动强制构建和媒体反向引用索引，并在不访问真实 WordPress/GitHub 的前提下完成本地验证和插件打包。

## 实际完成

- 插件版本更新为 `0.6.0`，快照 schema 保持 `5`。
- GitHub API 版本集中为 `2026-03-10`。
- 插件使用 Actions workflow dispatch endpoint；HTTP 200 和 204 均可接受，200 响应保存 workflow run 元数据。
- 自动变更使用 pending、30--60 秒防抖、执行锁、revision 去重和 60/300/900 秒重试；手动入口使用 `force=true`。
- 新增媒体反向引用索引；附件编辑保留索引，附件删除才移除索引，避免对全库内容做默认扫描。
- 工作流保留旧 `repository_dispatch` 作为 deprecated 兼容入口；插件不再发送该事件。
- GitHub Token 只显示配置状态，数据库 option 实测为 `autoload=no`，未写入公开快照、日志或前端。
- 现有 `lint.yml` 未修改。
- 生成并验证 `jaisong1n-site-manager-0.6.0.zip`。

## 修改文件

- `.github/workflows/build-deploy.yml`
- `package.json`
- `scripts/test-wordpress-plugin-upgrade.mjs`
- `tests/wordpress-plugin-upgrade.php`
- `tests/wordpress-plugin.test.mjs`
- `wordpress-plugin/jaisong1n-site-manager/jaisong1n-site-manager.php`
- `wordpress-plugin/jaisong1n-site-manager/includes/class-jg-dispatch.php`
- `wordpress-plugin/jaisong1n-site-manager/includes/class-jg-media-index.php`（新增）
- `wordpress-plugin/jaisong1n-site-manager/readme.txt`
- `wordpress-plugin/jaisong1n-site-manager/tests/playground-smoke.php`
- `wordpress-plugin/jaisong1n-site-manager/uninstall.php`
- `docs/AUTO_BUILD_TRIGGER.md`
- `docs/DEPLOYMENT.md`
- `docs/WORDPRESS_DEPLOYMENT.md`
- `docs/WORDPRESS_SITE_MANAGER_PHASE1.md`

## 测试与验证

- 命令：`pnpm test`
  - 实际结果：48 个测试通过，0 失败。
  - 证据类型：测试输出可确认。

- 命令：`pnpm test:wordpress-plugin`
  - 实际结果：17/17 通过。
  - 覆盖版本、schema、12 类公开 post type、workflow dispatch、旧事件兼容、Token option 和反向索引静态契约。
  - 证据类型：测试输出可确认。

- 命令：`pnpm test:wordpress-playground`
  - 实际结果：`ok: true`；schemaVersion `5`；ETag 返回 `304`；200/204、500/429、网络错误重试通过；`dispatchCalls: 8`；实际公开 post type 为 12 类；API 版本为 `2026-03-10`。
  - 证据类型：Playground 输出可确认。

- 命令：`pnpm test:wordpress-upgrade`
  - 实际结果：`0.5.0 -> 0.6.0` 升级模拟通过；激活插件、设置、内容类型和 schemaVersion 5 保留；Token option autoload 修正为 `no`。
  - 证据类型：Playground 输出可确认。

- 命令：`pnpm check`
  - 实际结果：320 个文件，0 errors、0 warnings、0 hints。
  - 证据类型：命令输出可确认。

- 命令：Biome 检查变更后的 JS 测试文件；Prettier 检查 `.github/workflows/build-deploy.yml`。
  - 实际结果：均通过。
  - 证据类型：命令输出可确认。

- 命令：`git diff --check`
  - 实际结果：无输出，未发现空白错误。
  - 证据类型：命令输出可确认。

- 本地 false 构建：使用本地 WordPress mock，Astro 构建 36 页并成功生成 Pagefind；结构化快照缺失只产生 warning，未阻塞 legacy 构建。
  - 证据类型：本地构建输出可确认。

- 本地 true 构建：使用本地 schema v5 mock，Astro 构建 21 页；结构化同步成功，未回退 legacy。
  - 证据类型：本地构建输出可确认。

- 命令：`pnpm verify:wordpress-plugin-package`
  - 实际结果：ZIP 根目录唯一为 `jaisong1n-site-manager/`，主文件路径正确，12 个条目、9 个 PHP 文件全部通过解析和 include 检查。
  - ZIP：`wordpress-plugin/dist/jaisong1n-site-manager-0.6.0.zip`
  - SHA-256：`E25F16736ED7E79FAE0EDDF511C7CEE8EE605ED32D58BD7DA6F1703D07C47956`
  - 证据类型：命令输出可确认。

## 真实环境验证结果

- 用户在项目状态记录中确认：WordPress 自动构建曾成功触发，GitHub Actions 构建部署成功，测试文章已在博客前台出现；测试文章 ID 73 曾从 draft 更新为 publish。
- 上述结果属于用户真实环境确认，不是本会话自动验证。
- 本会话尝试使用可用浏览器访问 `https://cms.jaisong1n.com/wp-admin/`，页面被重定向到登录页，没有可用的已登录会话。
- 因无登录会话，本会话没有创建、发布或修改任何真实文章，也没有查看或处理 Token。
- 本会话没有调用真实 GitHub API，没有确认真实 workflow run ID、deploy 分支更新或线上 HTTP 状态。

## 遇到的问题

- 200 响应中的 `run_url` 可能来自 `api.github.com`，原校验只允许 `github.com`。
- 附件编辑和删除共用处理函数会导致编辑后丢失反向索引。
- Playground 的空锁 option 会阻止基于 `add_option` 的原子锁获取。
- 可用浏览器没有登录 WordPress，无法进行授权的真实发布测试。

## 解决过程

- 将安全 GitHub URL 校验扩展为允许 `github.com` 和 `api.github.com`，并在 Playground 断言 run URL 被保存。
- 分离附件编辑和附件删除 Hook；编辑只登记构建变化，删除后才清理索引。
- 安装默认配置时清理旧的空锁 option，确保锁获取为原子 `add_option`。
- 使用本地 mock WordPress 和 WordPress Playground 完成离线验证；真实浏览器缺少登录态时停止，不索取账号密码。

## 关键决定

- 当前插件使用 `workflow_dispatch`；工作流保留 `repository_dispatch` 仅为旧外部调用方兼容。
- 自动触发只有公开内容或公开设置真实变化时登记 pending；只读快照、ETag、构建读取不触发。
- 媒体变化使用反向索引，不以全库扫描作为默认策略；历史正文图片只有在对应内容再次保存时建立索引。
- 本地测试不使用真实 WordPress、真实 GitHub API、真实 Token 或 deploy 分支。

## 尚未完成

- 本会话未完成真实 WordPress 登录态下的测试文章创建和发布。
- 本会话未取得真实 GitHub Actions run ID、线上文章 HTTP 检查或 deploy 分支确认。
- 本任务没有 commit 或 push；当前分支状态不能据此证明这些改动已合并或上线。

## 下一步

- 在用户已登录的 WordPress 浏览器会话可用后，按发布测试要求最多创建一篇测试文章，并单独记录 pending、workflow run、deploy 和线上结果。
- 真实测试必须继续避免输出或记录 Token、密码、Authorization、Cookie 和环境变量值。
- 真实环境确认完成前，不把本地 mock 构建结果写成线上部署成功。

## 资料来源

- 本会话中的插件实现、工作流、测试和构建命令输出。
- `docs/project/current-state.md`、`docs/project/decisions.md`。
- `docs/project/development-log/2026-07-31/1721-project-records.md`。
- 用户提供的真实环境确认；已明确标注为用户确认，未冒充本会话测试结果。
