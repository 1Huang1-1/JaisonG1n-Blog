# AI 科技日报固定模板规范

- 时间：2026-08-03 17:42（Asia/Shanghai）
- 会话或模块：Codex / 内容规范
- 当前分支：`master`（本地）
- 工作目录：`D:\Blog\JaisonG1n-Blog`
- 状态：已完成（模板规范已写入，本地待提交）
- 是否已提交：否，随本会话本地提交创建
- 是否已部署：否；未 push/tag/release

## 背景

对比生产两篇 AI 科技日报：

- `https://jaisong1n.com/diary/ai-tech-daily-2026-08-01/`：主题化标题、短导语、
  按主题归组的编号条目、固定「新名词 / 今天的判断 / 资料来源」模块，可读性与
  收获感好，被用户指定为基准模板。
- `https://jaisong1n.com/diary/ai-tech-daily-2026-08-03/`：日期堆叠标题、
  导语一段塞满要点与核验状态、逐条新闻独立 H2 平铺、条目内多段式验证说明、
  缺少新名词与判断模块，用户反馈版面差、可读性差、收获少。

## 产出

- 新增 `docs/agents/ai-tech-daily-template.md`：唯一生成模板，规定整体结构
  （H1 主题化标题 → 三段以内导语 → H2 主题章节 + 编号 H3 条目 → 新名词 →
  今天的判断 → 资料来源 → 固定结束语）、标题/导语/条目/模块规范、禁止清单
  （对照 08-03 失败点）、长度数量约束与生成自检要求。
- 更新 `docs/agents/writing-style.md`：Technology And AI Daily Report 增加
  指向模板的强制引用。
- 更新 `docs/agents/diary-workflow.md`：AI 科技日报条目增加模板强制引用。

## 未执行

- 未重写已发布的 08-03 内容（用户只要求“以后按此生成”）。
- 未 push；push master 会触发生产构建部署，等待用户确认。
