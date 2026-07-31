# WordPress 自动发布与部署

本项目在每次生产构建前，从公开的 WordPress REST API 拉取已发布文章，将正文转换为 Mizuki 可直接读取的 Markdown，然后继续执行 Astro 与 Pagefind 构建。生成内容只存在于构建环境，不提交到源码分支。

## 本地同步

Node.js 版本应为 22 或更高版本，pnpm 版本由 `package.json` 中的 `packageManager` 字段确定。

```bash
pnpm install --frozen-lockfile
pnpm sync-wordpress
pnpm check
pnpm build
```

默认内容源是 `https://cms.jaisong1n.com`。需要切换站点时，在 `.env` 或当前 shell 中设置公开变量：

```dotenv
WP_BASE_URL=https://cms.example.com
```

变量值必须是 WordPress 站点根地址，不应包含 `/wp-json`。同步结果写入 `src/content/posts/wordpress/`，该目录已加入 `.gitignore`，每次成功同步都会整体替换。请求或转换失败时脚本以非零状态退出，旧目录保持不变。

## 自动构建

`.github/workflows/build-deploy.yml` 在以下情况运行：

- `master` 分支收到 push
- 手动触发 `workflow_dispatch`
- 每 30 分钟一次的定时任务

GitHub Actions 的 cron 使用 UTC，`*/30 * * * *` 表示每个 UTC 小时的第 0 分和第 30 分运行。GitHub 的定时任务可能因平台负载而延迟，并不保证精确到分钟。

工作流使用 Node.js 22，执行冻结锁文件安装和 `pnpm build`，并确认 `dist/index.html` 存在。首次发布会创建没有源码历史内容的 orphan `deploy` 分支；之后复用远程 `deploy`，用 `rsync --delete` 让分支内容与 `dist` 完全一致。只有内容变化时才生成提交，最后 force push 到 `deploy`。

可在仓库的 Actions variables 中添加 `WP_BASE_URL` 覆盖默认站点地址。它是公开配置，不应放入密码、WordPress 管理员凭据或其他秘密。

## GitHub 权限

在 GitHub 仓库中打开 `Settings > Actions > General > Workflow permissions`，选择 `Read and write permissions`。工作流仅声明 `contents: write`，发布身份只使用 GitHub 自动提供的 `GITHUB_TOKEN`，不需要个人访问令牌。

如仓库规则禁止向 `deploy` force push，需要为 GitHub Actions 调整对应分支规则，否则发布步骤会失败。

## Hostinger 配置

在 Hostinger 的 Git 部署功能中连接此 GitHub 仓库，并将部署来源设置为 `deploy` 分支。部署路径设置为站点的 `public_html` 目录。`deploy` 分支根目录已经是网站产物根目录，不需要再指定 `dist` 子目录，也不需要在 Hostinger 上重新执行 Astro 构建。

首次运行工作流后 `deploy` 分支才会出现。如果 Hostinger 配置界面要求先选择现有分支，应先手动运行一次 GitHub Actions，再完成 Hostinger 配置。

## 内容映射与安全边界

同步器只请求 WordPress `status=publish` 的公开文章，且会再次过滤响应状态。分类、标签、特色图、置顶状态、评论状态、别名和作者会写入 frontmatter；疑似邮箱格式的作者名会替换为 `JaisonG1n`。

正文会删除 Gutenberg 注释和 `script` 标签，转换为 GFM，并保留图片以及 `iframe`、`video`、`audio` 媒体 HTML。保留远程媒体意味着浏览器仍会请求其原始主机；WordPress 编辑者应被视为可信内容作者。这个转换不是面向不受信任投稿者的完整 HTML 安全沙箱。

文件名会处理 Windows 保留名、非法字符、Unicode slug、路径穿越和重名。frontmatter 字符串及数组使用安全的 YAML 兼容转义，`published` 与 `updated` 以未加引号的 ISO 8601 时间标量输出，从而由 Astro 内容加载器解析为 `Date`。
# Automatic build trigger (plugin 0.6.0)

WordPress writes create a pending public revision and schedule one debounced
Cron worker. The worker compares the revision with the last accepted revision
before calling GitHub Actions `workflow_dispatch`. HTTP 200 and 204 are
accepted; failed requests retain pending state and retry at 60, 300 and 900
seconds. The request uses API version `2026-03-10` and a fine-grained PAT with
only Actions Read and write on the target repository. `repository_dispatch` is
still present in the workflow as a deprecated compatibility trigger for older
callers, but the 0.6.0 plugin never sends both events.
