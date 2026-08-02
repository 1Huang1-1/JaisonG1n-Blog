# Blog Diary Workflow

本工作流适用于 Codex、OpenClaw 和其他 Agent 的自然语言日记请求。写作前读取 [Writing Style](writing-style.md)；包含外部事实时遵循 [Research Policy](research-policy.md)；保存或发布时遵循 [Publishing Policy](publishing-policy.md)；调用接口前遵循 [AI Content API Usage](ai-content-api-usage.md)。

## Intent And Time Range

“日报”“今日总结”“今天做了什么”“今日开发记录”属于日报；“周报”“本周总结”“这周做了什么”属于周报。用户明确日期或区间优先；“今天”为本地零点至当前，“昨天”为前一自然日，“本周”为周一零点至当前。上下文是开发、测试、部署或学习时使用开发日记；提到技术、AI、新闻、行业变化或术语时使用 AI 科技日记。信息不足时只询问一次类型。

## Evidence And Structure

- 开发日报：600–1200 汉字，记录完成工作、实际问题、解决方式、经验和下一步；只使用真实当日证据。
- 开发周报：1000–2000 汉字，按主题说明目标、完成项、问题与解决、测试/部署状态、未完成项和下周计划；明确区分已完成、进行中、未开始和计划。
- AI 科技日报：1200–2200 汉字，三到五项已核验事件、三到六个术语、趋势、实际影响、后续观察和来源。
- AI 科技周报：1800–3000 汉字，四到六项已核验事件、五到八个术语、整体观察、实践影响、下周观察和来源；解释趋势，不拼接日报标题。

个人日记只使用用户明确给出的事实，不推断关系、健康、情绪、位置、花费或经历。话题和观点日记必须区分已核验事实、用户体验、作者观察和未经核实的判断。

## Create Or Update Flow

1. 识别类型、时间范围、事实来源和是否需要联网研究。
2. 按风格规范完成标题、摘要和正文，并运行风格自检。
3. 使用确定 slug：`dev-daily-{YYYY-MM-DD}-{topic}`、`ai-tech-daily-{YYYY-MM-DD}`、`dev-weekly-{YYYY}-w{ISO_WEEK}` 或 `ai-tech-weekly-{YYYY}-w{ISO_WEEK}`。
4. 先搜索同 slug 和明显同题/同日期内容；同一类型每天或每周最多一篇。已有草稿时报告并询问是否更新，不用随机 slug 复制。
5. 使用稳定键，例如 `blog-diary-{diaryType}-{dateOrWeek}-{topicVersion}`；通过 API capabilities 创建 `contentType: diary` 草稿。
6. 重新读取并确认 ID、类型、标题、slug、作者、草稿状态、修改时间和非空正文；重放同一幂等键一次以确认不产生重复。
7. 只有用户明确发布时才进入发布政策；否则停止在草稿。

真实操作的最终报告只给出类型、时间范围、是否研究、来源数量、ID、标题、slug、状态、归属、幂等验证、发布结果、编辑地址（如有）、预期自动化行为和敏感信息检查结果，不默认输出全文。
