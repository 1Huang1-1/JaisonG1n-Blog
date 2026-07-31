# JaisonG1n Blog Writing Style

JaisonG1n Blog 的写作风格是：**清晰、证据驱动、有真实开发过程的个人技术写作。** It records what happened, why a decision was made, what remains uncertain, and how the result affects future work.

This is an original house style. Do not directly imitate or reproduce the voice, wording, structure, or distinctive phrasing of any named author, blogger, or publication.

## Core Voice

- Write natural first-person Chinese when describing personal work, decisions, and observations.
- Put people and their goals before terminology. Introduce an unfamiliar term only when it helps explain the problem.
- Keep one main idea per paragraph. Let headings carry structure instead of relying on long transitions.
- Separate confirmed facts, test results, and source material from personal interpretation or recommendation.
- Explain why a change matters to writing, maintenance, reliability, security, cost, or readers.
- Describe problems, failed attempts, tradeoffs, and uncertainty honestly. Do not manufacture experience or confidence.
- Show engineering judgment: state the constraint, the considered options when useful, the chosen approach, and the reason it fit.

## Avoid

- Marketing language, exaggerated claims, and empty conclusions such as “a complete revolution” without evidence.
- Mechanical transitions, formulaic section openings, and overly formal report language.
- Repeating a title as a conclusion, listing features without explaining their impact, or making every paragraph the same length and rhythm.
- Fabricated debugging stories, unverified benchmarks, invented user feedback, or claims of access that did not occur.
- Rewritten news summaries that add no original observation, verification, or practical implication.

## Content-Specific Style

### Development Daily Report

Start from the task or problem of the day. Record the initial understanding, what was actually discovered, the action taken, verification evidence, and the next concrete concern. Prefer a short, specific process record over a polished retrospective.

### Development Weekly Report

Group work by meaningful themes rather than replaying each day. Identify the week’s durable changes, unresolved risks, and what should guide the next week. Include enough technical detail for later maintenance without turning the report into a changelog.

### Technology And AI Daily Report

Select only developments that materially affect current work or readers. State what is confirmed, what is inferred, and why the change is worth watching. A small number of well-explained items is better than a broad list of headlines.

### Technology And AI Weekly Report

Connect events into a trend, but distinguish the observed evidence from the author’s interpretation. Explain likely effects on workflow, reliability, cost, skills, or product choices, and name uncertainty where the trend is not established.

### Tutorial

Begin with the real problem and the boundary of the solution. Explain principles and risks before steps, then use concise steps only where they help readers act safely. State prerequisites, verification points, and cases where the approach should not be used.

### Project Retrospective

Describe the original goal, constraints, choices, failures, verification, and what changed afterward. Treat tradeoffs as part of the result, not as an omission. Preserve enough context that a future maintainer can understand the decision.

### Learning Note

Use the author’s own learning path: the prior misconception, the evidence that changed it, the corrected model, and a small practical application. Do not turn a note into a disguised glossary.

### Opinion

Make the position clear, then support it with facts, concrete experience, or explicitly labeled reasoning. Acknowledge reasonable counterarguments and do not present personal preference as an established fact.

## Titles

- Prefer a concrete subject plus the change, question, or outcome that gives the post value.
- Keep titles readable and specific; avoid clickbait, vague praise, and keyword stuffing.
- Use a question only when the article genuinely answers it.
- Do not promise a universal solution when the post describes one project or one set of constraints.

## Excerpts

- State the topic, concrete context, and reader-facing value in one or two sentences.
- Keep the excerpt independent of the title rather than repeating it.
- Do not include unsupported claims, secrets, internal paths, credentials, or implementation details that do not belong in a public summary.

## Explaining Terminology

- Explain a term at its first meaningful use in plain Chinese, then use the precise technical name consistently.
- Tie terminology to its role in the current problem instead of defining it in isolation.
- Keep code identifiers, API names, and configuration names exact; explain their effect around them rather than translating identifiers inconsistently.

## Evidence And Sources

- Label verified facts, test results, and source-backed statements clearly.
- Link or name the relevant primary source when a public claim depends on it, and distinguish a source from an interpretation of that source.
- Include only measurements, versions, dates, and outcomes that were actually observed.
- When evidence is incomplete, say what is unknown and avoid turning a plausible inference into a fact.

## Personal Observations

- Personal observation is valuable when it explains a decision, a friction point, or a practical consequence.
- Use first-person statements for lived experience and label them as observation rather than general proof.
- Connect the observation back to an actionable lesson, while leaving room for readers with different constraints.

## Style Review Checklist

Before an article or diary is submitted for publication, verify all of the following:

1. The content has a clear purpose and a concrete reader-facing reason to exist.
2. The title is specific, proportionate, and supported by the body.
3. The excerpt summarizes context and value without repeating the title.
4. The selected content type follows its applicable style above.
5. Each paragraph has one main idea and the heading structure is meaningful.
6. Facts, test results, sources, observations, and opinions are visibly distinguishable.
7. Technical terms are introduced in plain language and used consistently.
8. Claims about versions, behavior, performance, or outcomes have real evidence or are qualified.
9. The writing explains why the work matters, not merely what commands or features exist.
10. Problems, tradeoffs, and uncertainty are described honestly where relevant.
11. No fabricated experience, copied news framing, named-author imitation, or generic promotional language remains.
12. The draft contains no credentials, tokens, private paths, passwords, or other sensitive information.

If a required item fails, revise the draft and keep it unpublished until the checklist passes.

## Original Examples

The following are examples of the desired thinking and rhythm only. They are not templates and must not be copied into posts.

### Development Daily: AI Content API Permissions

> 我最初把 AI Content API 理解成一个写入入口：传入标题和正文，再由 WordPress 保存草稿。检查权限时才发现，真正需要先厘清的是保存操作的边界。直接复用编辑者角色测试很方便，却会让自动化客户端得到无关的发布能力。后来我改成专用角色，只保留创建和维护草稿所需的能力，并围绕这条边界验证接口。多做这一步不是形式问题，权限范围本身就是接口设计的一部分，也让后续审查有据可循。

### AI Weekly: From Agent Demo To Workflow

> 本周可以确认的是，一些 Agent 工具开始提供更长的任务流程和更明确的审批节点。我的观察是，真正值得关注的并不是演示里的对话更流畅，而是它能否放进已有审核步骤和可重复构建步骤之间。这会直接影响落地方式：好看的对话适合探索，进入工作流则需要可追踪性、可预测的权限和安全的失败路径。下一轮我会优先看这些运行细节，而不是只看生成文本的效果。

### Tutorial: A Dedicated WordPress User For AI

> 为 AI 集成单独创建 WordPress 用户，并不只是账户管理习惯。风险在于，自动化令牌会继承人工账户拥有的一切能力，其中可能包含集成根本不需要的操作。专用用户把边界写得更清楚：先创建草稿、读取自己的内容，到这里停止，除非后续审核明确扩大范围。这条原则也让处置问题更直接，因为撤销一个集成令牌不会中断管理员的日常工作。教程应先说明这种风险和原则，而不只是罗列配置步骤。
