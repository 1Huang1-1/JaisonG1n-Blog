# 2026-08-01 AI 科技日报研究记录

## 研究范围与方法

- 研究时间：2026-08-01（Asia/Shanghai）。
- 目标：为当天中文 AI 科技日报准备可核验候选，不把厂商的性能、价格或采用率表述当作独立第三方结论。
- 来源策略：优先发布方或监管机构的一手页面；每条保留发布日期、适用范围和证据限制。
- 工具说明：本环境未找到 `agent-reach` 可执行命令，按其降级路径使用公开网页检索；未访问 WordPress，未使用凭据。

## 候选新闻

### 0. DeepSeek 更新 DeepSeek-V4-Flash-0731，并强调 Responses API 与 Codex 适配

- 发布日期：2026-07-31。
- 可确认事实：DeepSeek API changelog 将此次更新命名为 `DeepSeek-V4-Flash-0731`，说明其与 Preview 保持相同模型架构和规模，仅做重新后训练；官方称该版本原生支持 Responses API 格式，并特别适配 Codex。页面同时明确，本次只升级 V4-Flash API，V4-Pro API 与 App/Web 模型未变化，V4-Pro 的正式发布仍在后续计划中。
- 适合日报的角度：模型更新不只意味着能力名称变化，也可能是接口格式、调用端适配与版本迁移要求的变化。
- 一手来源：<https://api-docs.deepseek.com/updates/>；<https://www.deepseek.com/en/transparency/>。
- 证据限制：模型架构、后训练描述和适配范围均来自 DeepSeek 官方；不把其表述扩展为独立性能结论，也不把 V4-Flash 的更新写成 V4-Pro 或 App/Web 同步更新。

### 1. 欧盟 AI Act 透明度规则将于 8 月 2 日开始适用

- 发布/更新时间：欧盟委员会事实页近期更新；规则适用日期为 2026-08-02。
- 可确认事实：欧盟委员会说明，AI Act 第 50 条透明度规则从 2026-08-02 起适用。其页面列举了人机交互告知、AI 生成或篡改内容的可识别性，以及深度伪造和未经人工审校的公共利益文本的披露场景。AI Act Service Desk 的时间表同时说明，多数适用规则和相关执法自该日开始。
- 适合日报的角度：这是一项紧邻今日的制度节点，适合把“生成内容能力”与“标识、披露、人工编辑责任”并列讨论。
- 一手来源：
  - <https://digital-strategy.ec.europa.eu/en/factpages/quick-facts-transparency-rules-ai-systems>
  - <https://ai-act-service-desk.ec.europa.eu/en/ai-act/eu-ai-act-implementation-timeline>
- 证据限制：适用范围与例外须按具体系统、市场和最终法律文本判断；不应把该页面概括为所有高风险 AI 规则已在同日全面生效。该服务台时间表显示 Annex III 高风险规则为 2027-12-02，受监管产品中的 AI 为 2028-08-02。

### 2. Meta 宣布为 AI 生成内容透明度行为准则签署方

- 发布日期：2026-07-28。
- 可确认事实：Meta 表示将签署欧盟 AI Act 的 AI 生成内容透明度行为准则，并称其正参与 C2PA 等跨行业工作以改善内容来源识别与标注。
- 适合日报的角度：平台侧的行动说明“能生成”之外，内容来源、标识互操作性和用户理解成本也正在成为产品设计问题。
- 一手来源：<https://about.fb.com/news/2026/07/meta-is-signing-the-eu-ai-act-code-of-practice-on-transparency-of-ai-generated-content/>
- 证据限制：这是 Meta 的政策公告，签署或参与本身不等同于对所有内容均已实现统一、完整的自动标注。

### 3. Meta 在部分市场推出可持续执行任务的 Meta AI 功能

- 发布日期：2026-07-24。
- 可确认事实：Meta 公告称，由 Muse Spark 1.1 驱动的 Meta AI 可规划任务、连接部分邮件和日历应用、创建幻灯片，并可按设定时间提供简报或执行持续性任务；功能从公告当天起在 Meta AI app 与 meta.ai 的部分市场逐步推出。
- 适合日报的角度：消费级助手的产品重心正在从“一问一答”移向带时间触发、应用连接和人工中途干预的任务流。
- 一手来源：<https://about.fb.com/news/2026/07/meta-ai-muse-spark-doesnt-just-think-it-acts/>
- 证据限制：功能仅在部分市场开始滚动发布；连接范围、可用地区和实际效果需以用户所在地区及产品界面为准。

### 4. OpenAI 调整 GPT-5.6 API 的价格与速度选项

- 发布日期：2026-07-30。
- 可确认事实：OpenAI 公告称，GPT-5.6 Luna 与 Terra 的 API 定价下调，并为 GPT-5.6 Sol 推出 Fast mode 以替代 Priority Processing；公告列出 Luna 和 Terra 的新输入/输出 token 价格，以及 Sol 的加速服务说明。
- 适合日报的角度：模型竞争不只体现在能力榜单，也体现在同一工作流里如何按质量、延迟和成本进行路由。
- 一手来源：<https://openai.com/index/advancing-the-price-performance-frontier-with-gpt-5-6/>
- 证据限制：价格、速度和性能均来自 OpenAI 公告，可能受产品层级、地区、服务条款和后续调整影响；不应把文中的客户引述当作可普遍复制的结果。

### 5. OpenAI 宣布面向高校研究者的免费访问计划

- 发布日期：2026-07-29。
- 可确认事实：OpenAI 宣布 “ChatGPT for Academic Researchers” 计划，称将先在当年夏季面向 10,000 名选定机构研究者开放，并计划到 2027 年扩展至 100,000 名；页面称工作区默认不使用数据训练模型，并包含隐私与安全保护说明。
- 适合日报的角度：AI 工具进入科研的下一阶段，除了模型能力，还涉及机构工作区、数据处理约定、研究者控制权与可复现性。
- 一手来源：<https://openai.com/index/chatgpt-for-academic-researchers/>
- 证据限制：这是项目发布和计划目标，不等于所有研究者都已获得资格或同等功能；文中使用量与评测为 OpenAI 自身披露，应注明来源。

### 6. Anthropic 已发布 Claude Sonnet 5，并强调代理式工具使用和安全防护

- 发布日期：2026-06-30。
- 可确认事实：Anthropic 将 Claude Sonnet 5 描述为能规划、调用浏览器和终端等工具的模型，并说明其在 Claude、Claude Code 和 Claude Platform 可用；公告还称该模型默认启用网络安全防护，并附有系统卡链接。
- 适合日报的角度：代理能力的讨论应连同权限边界、工具调用记录、验证环节和安全防护一起出现，而不是只看“是否可自主运行”。
- 一手来源：<https://www.anthropic.com/news/claude-sonnet-5>
- 证据限制：该公告距今日约一个月，适合作为背景而非“今日发布”；能力与安全结论主要来自厂商评估，应与独立评测区分。

## 建议采用的日报组合

建议选用 1、2、3、4、5 五条：它们覆盖监管透明度、平台产品、模型经济性和科研采用，发布时间集中在 7 月下旬至 8 月 2 日的政策节点。第 6 条仅作“代理式 AI”背景，不必作为当日主新闻。

## 可解释的新名词

### 代理式 AI（agentic AI）

指不仅生成回答，还能规划步骤、调用工具并在多步任务中继续执行的系统能力。Meta 的任务功能和 Anthropic 的模型公告都把规划与工具使用作为产品特征。

- 来源：<https://about.fb.com/news/2026/07/meta-ai-muse-spark-doesnt-just-think-it-acts/>
- 来源：<https://www.anthropic.com/news/claude-sonnet-5>

### AI Act 第 50 条透明度义务

欧盟针对部分人机交互、AI 生成或篡改内容披露的规则框架；重点是让人能识别正在与 AI 交互或接触到 AI 生成内容。2026-08-02 起开始适用。

- 来源：<https://digital-strategy.ec.europa.eu/en/factpages/quick-facts-transparency-rules-ai-systems>

### C2PA

Coalition for Content Provenance and Authenticity 的缩写，是围绕内容来源与真实性信息的跨行业协作。Meta 在其透明度公告中将其列为参与的合作组织之一。

- 来源：<https://about.fb.com/news/2026/07/meta-is-signing-the-eu-ai-act-code-of-practice-on-transparency-of-ai-generated-content/>

### 模型路由（model routing）

在一个任务流中按质量、延迟、成本或风险，把不同子任务分配给不同能力与价格档位模型的工程做法。OpenAI 在 GPT-5.6 公告中以不同模型和处理模式阐释这种取舍。

- 来源：<https://openai.com/index/advancing-the-price-performance-frontier-with-gpt-5-6/>

### Fast mode

OpenAI 为 GPT-5.6 Sol API 提供的加速处理选项，用来替代此前的 Priority Processing；公告称其以更高价格换取更低响应延迟。它是特定厂商产品名称，不应泛化为行业标准术语。

- 来源：<https://openai.com/index/advancing-the-price-performance-frontier-with-gpt-5-6/>

## 成文注意事项

- 标题和正文应使用“宣布”“称”“开始在部分市场推出”等可验证措辞，不写成全球同步上线或能力已被独立验证。
- 监管部分应明确规则开始日期是 2026-08-02，避免把今天 2026-08-01 写成已生效。
- 涉及 AI 生成新闻正文时，建议在发布页保留清晰时间、来源链接和人工编辑痕迹；这既符合读者核验需求，也与透明度主题一致。
