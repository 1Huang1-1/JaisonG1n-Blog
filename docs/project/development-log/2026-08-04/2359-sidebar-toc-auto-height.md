# 侧栏目录改为内容自适应高度

## 本次完成

- 移除桌面侧栏目录包装器的固定视口高度，目录高度改为随实际目录内容收缩。
- 移除无横幅模式和低高度屏幕规则中的强制 `height`，保留 `max-height` 作为长目录滚动上限。
- 目录宽度、目录项样式、滚动高亮、锚点交互以及目录位于音乐播放器上方的顺序均保持不变。

## 修改文件

- `src/components/widgets/toc/TOC.astro`
- `src/styles/banner.css`

## 验证

- `pnpm exec biome check src/components/widgets/toc/TOC.astro src/styles/banner.css`：Astro 文件通过；CSS 文件不在 Biome 当前检查范围内。
- `pnpm exec astro check`：通过，0 errors；保留一条与本次无关的 `PostMeta.astro` 未使用 `id` 提示。
- `pnpm exec astro build`：通过，40 个页面生成完成；保留既有的 Iconify 依赖优化提示与隐藏相册 cover 提示。
- 发布前 `pnpm test`：74/74 通过。
- 发布前 `pnpm build`：WordPress 文章与结构化内容同步成功，40 个页面生成完成，Pagefind 完成索引。
- 本地桌面文章页：目录实际高度约 341px，最大高度约 863px；目录内容高度与容器高度一致，不再存在整屏空白。
- 目录与音乐播放器宽度保持一致，音乐播放器紧跟在目录下方，页面无横向溢出。
- 点击右侧目录中的“Tool：让模型真正执行动作”后，URL 正确更新为对应锚点；浏览器控制台无 warning 或 error。
- 390×844 移动端：桌面右侧目录和音乐组件保持隐藏，页面无横向溢出，移动端浮动目录仍存在。

## 状态

- 已完成并部署。
- 源码提交：`964ba5327e2257ba01597d160a8102af8f329886`。
- 部署分支提交：`ec7287ebde698898bf8d1ad446b296adf218f727`。
- 线上文章页返回 HTTP 200；目录容器已使用 `max-h-[calc(100vh-6rem)]`，且不再包含独立的固定高度类 `h-[calc(100vh-6rem)]`。
- 部署过程与线上验收详见 `docs/project/development-log/2026-08-05/0020-deploy-sidebar-toc-auto-height.md`。
