# 日记分享海报头像修复

- 时间：2026-08-01 15:39（Asia/Shanghai）
- 会话或模块：Codex / diary sharing UI
- 当前分支：codex/ai-content-api-0-7
- 工作目录：D:\Blog\JaisonG1n-Blog
- 状态：已完成
- 是否已提交：否，本地未提交
- 是否已部署：否，待本次部署

## 任务目标

修复日记详情页分享海报未渲染站点头像的问题。

## 实际完成

- 确认原因：日记页复用 `ShareCard` 时未传入 `avatar`，而 `SharePoster` 仅在收到可访问头像 URL 时绘制头像。
- 复用文章详情页的构建期头像资源解析逻辑，将 `profileConfig.avatar` 中相对 `src` 的路径解析为构建产物 URL。
- 向日记页的 `ShareCard` 传入解析后的 `posterAvatarUrl`。

## 修改文件

- `src/pages/diary/[slug].astro`
- `docs/project/development-log/2026-08-01/1539-diary-share-avatar.md`

## 测试与验证

- 命令或验证方式：确认 `src/assets/images/avatar.jpg` 存在。
- 实际结果：文件存在。
- 是否通过：通过。
- 命令或验证方式：`pnpm check`。
- 实际结果：检查 320 个文件，0 errors、0 warnings、0 hints。
- 是否通过：通过。
- 命令或验证方式：请求本地 `/diary/legacy-diary-2025-01-15/`，检查 `SharePoster` 和头像资源输出。
- 实际结果：HTTP 200；页面包含 `SharePoster`，并包含解析后的头像资源路径。
- 是否通过：通过。

## 遇到的问题

- 直接把 `profileConfig.avatar` 传给客户端会保留相对 `src` 路径，不能保证在 `/diary/<slug>/` 页面正确加载。

## 解决过程

- 在 Astro 构建期通过 `import.meta.glob` 查找本地头像并使用其产物 `src`，与文章页使用的策略保持一致。

## 关键决定

- 复用已有资源解析方式和海报组件，不引入另一套头像配置或客户端路径拼接逻辑。

## 未完成内容

- 提交和部署结果待记录。

## 下一步

- 运行检查并在部署后的日记分享海报中确认头像显示。

## 资料来源

- `src/pages/posts/[...slug].astro`
- `src/pages/diary/[slug].astro`
- `src/components/misc/SharePoster.svelte`

禁止记录：Password、Token、Authorization、Cookie、Application Password、环境变量值和私密用户数据。
