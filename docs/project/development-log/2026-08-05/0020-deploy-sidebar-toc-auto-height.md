# 部署侧栏目录自适应高度修复

## 本次完成

- 将侧栏目录自适应高度修复提交并推送至 `master`。
- 完成正式发布前测试和生产构建。
- GitHub Actions 因内容源 Bot Verification 返回 HTTP 403 未能完成构建部署后，复用项目既有的部署分支回退路径，将本地已成功同步并构建的静态产物提交至 `deploy`。
- 完成生产站点 HTTP 与产物结构验收。

## 提交与部署

- 源码提交：`964ba5327e2257ba01597d160a8102af8f329886`（`fix: shrink sidebar toc to content`）。
- 部署分支提交：`ec7287ebde698898bf8d1ad446b296adf218f727`（`Deploy 964ba5327e2257ba01597d160a8102af8f329886`）。
- GitHub Actions Lint：运行 `30927398222`，其中 Build 步骤访问内容源时遇到 HTTP 403 Bot Verification，因此失败。
- GitHub Actions Build and deploy：运行 `30927401596`，同步 WordPress 内容连续三次收到 HTTP 403 Bot Verification，因此失败。
- 未修改 CI 工作流、内容源安全规则或发布权限边界。

## 验证

- `pnpm test`：74/74 通过。
- `pnpm build`：通过；WordPress 文章与结构化内容同步成功，40 个页面生成完成，Pagefind 索引 8 个页面、943 个词。
- 本地桌面验收：目录高度约 341px，随内容收缩；与音乐播放器同宽，音乐播放器位于目录下方；页面无横向溢出，目录锚点交互正常，控制台无 warning 或 error。
- 390×844 移动端验收：桌面侧栏目录与音乐组件保持隐藏，移动端浮动目录正常，页面无横向溢出。
- 生产文章页 `https://jaisong1n.com/posts/tool-skill-mcp-capability-workflow-protocol/` 返回 HTTP 200。
- 生产 HTML 中 `#toc-inner-wrapper` 保留 `max-h-[calc(100vh-6rem)]`，不包含独立的 `h-[calc(100vh-6rem)]` 固定高度类。
- 生产 HTML 中目录仍位于 `#right-sidebar` 内，音乐播放器标记位于目录之后。

## 状态

- 已部署并完成生产验收。
