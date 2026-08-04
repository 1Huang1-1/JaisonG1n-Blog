# 将文章目录移动到音乐播放器上方

## 本次完成

- 将原本独立定位在主网格最右侧的桌面文章目录接入右侧侧栏组件序列。
- 目录排列在分类组件之后、音乐播放器之前，宽度继承右侧侧栏，与音乐播放器一致。
- 继续复用原有 `SidebarTOC`，保留目录项格式、编号、滚动高亮和锚点跳转。
- 保留目录原有的桌面端开关、壁纸模式显隐状态与 Swup 所需元素标识。
- 移除 `MainGridLayout` 中原来的外置目录渲染块，避免重复目录。

## 修改文件

- `src/config/sidebarConfig.ts`
- `src/components/layout/SidebarColumn.astro`
- `src/components/widgets/toc/TOC.astro`
- `src/layouts/MainGridLayout.astro`

## 验证

- `pnpm exec biome check src/components/widgets/toc/TOC.astro src/components/layout/SidebarColumn.astro src/config/sidebarConfig.ts src/layouts/MainGridLayout.astro`：通过。
- `pnpm exec astro check`：通过，0 errors；保留一条与本次无关的 `PostMeta.astro` 未使用 `id` 提示。
- `pnpm exec astro build`：通过，40 个页面生成完成；保留既有的 Iconify 依赖优化提示与隐藏相册 cover 提示。
- 发布前 `pnpm test`：74/74 通过。
- 发布前 `pnpm build`：WordPress 文章与结构化内容同步成功，40 个页面生成完成，Pagefind 完成索引。
- 本地文章页桌面端验证：目录位于音乐播放器上方，目录宽度 239px、音乐卡片宽度 238px（取整误差 1px），目录生成 10 个锚点。
- 点击右侧目录中的“Tool：让模型真正执行动作”后，URL 正确更新为对应锚点。
- 390×844 移动端验证：右侧目录与音乐组件尺寸均为 0，不影响移动端；页面无横向溢出，移动端浮动目录仍存在。
- 浏览器控制台未发现 warning 或 error。
- 源码提交：`5fd85e1`（`fix: move article toc into sidebar`）。
- GitHub Lint `30925149773`：Biome、Build、Astro Check 全部成功。
- GitHub Build and deploy `30925152502`：首次执行和失败重跑均在 WordPress 同步阶段被 CMS Bot Verification 返回 HTTP 403；本次代码尚未进入该流水线的构建步骤。
- 本机发布构建已使用实时 WordPress 同步成功生成，随后按既有发布逻辑直接更新 `deploy` 分支；部署提交为 `4d817d92`，对应源码 `5fd85e1`。
- 生产文章页返回 HTTP 200，`Last-Modified` 为 2026-08-04 15:44:33 UTC；生产 HTML 使用新构建资源 `main.BbByuHjJ.css`，已移除旧的外置目录容器，且右侧栏内的目录节点位于音乐播放器节点之前。
- 生产文章页可在浏览器中正常打开，标题与目标文章一致。

## 状态

- 已完成、已提交、已部署并完成生产验收。
