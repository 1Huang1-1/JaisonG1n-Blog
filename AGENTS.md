# Project Instructions

## Feature-first development mode

The project now has a production-verified baseline.

Prioritize implementation progress over repeatedly re-validating established
capabilities.

During development:

- Run targeted tests for the changed module.
- Do not repeat full production acceptance for unchanged behavior.
- Do not create a release, tag, or lengthy acceptance report for every small
  patch.
- Reuse previously verified security, transport, publishing, and deployment
  foundations.
- Perform the full test suite and one production acceptance only when the
  complete version is ready for release.
- Keep status reports focused on completed functionality, blockers, tests,
  risks, and commit hashes.

Do not reduce safety checks for genuinely new high-risk write operations, but
avoid re-proving unchanged guarantees.

## Blog Content Assistant

This file is the Codex entry point for the cross-agent blog rules in `docs/agents/`. Those rules are shared by Codex and future agents such as OpenClaw; do not create a separate Codex-only copy.

For requests to write a daily report, weekly report, diary, technology update, development record, or to organize material as blog content, read in order:

1. `docs/agents/diary-workflow.md`
2. `docs/agents/writing-style.md`
3. `docs/agents/research-policy.md` when the content contains external facts
4. `docs/agents/publishing-policy.md` when content may be saved or published
5. `docs/agents/ai-content-api-usage.md` before calling a blog API

Explicit user requirements take precedence over default writing style. They do not override safety, authorization, credential-protection, deletion, album-exclusion, or server-side permission boundaries. Do not infer a request to publish from “write”, “save”, or other ambiguous wording.

## Cross-session project records

## 长期产品方向

涉及 AI Content API、WordPress Site Manager、内容发布、媒体、配图、
OpenClaw 集成或内容自动化的任务开始前，先阅读：

- `docs/project/JaisonG1n-personal-content-os-roadmap.md`

该文档描述长期产品愿景、安全边界和建议版本路线，不代表一次性实现全部功能。
每次只完成用户当前明确授权的版本范围，不得因路线图内容擅自扩大任务。

1. 开始实际开发任务前，读取：
   - `docs/project/current-state.md`
   - `docs/project/decisions.md`
   - 与当前任务有关的最近 `docs/project/development-log/`
2. 每个会话完成实际开发、测试、部署或重要配置后，必须创建一份独立任务日志。
3. 每个会话写自己的日志文件，不追加或覆盖其他会话的任务日志。
4. 只记录能够确认的事实：
   - 未运行的测试不得写为通过。
   - 未部署不得写为上线。
   - 计划不得写为完成。
   - 未提交改动必须标记“本地未提交”。
   - 未合并分支不得写成主分支已完成。
5. 单纯解释、问答和只读检查不强制生成日志，除非产生了重要、可复用的结论。
6. 不允许每个普通开发会话随意重写 `current-state.md`。
7. `current-state.md` 应由专门的项目汇总任务根据所有任务日志、Git 状态和测试结果统一更新。
8. 重要架构、安全、兼容性和权限决策才能写入 `decisions.md`。
9. 多个会话不得同时修改以下文件或同一个代码文件，除非用户明确协调：
   - `current-state.md`
   - `decisions.md`
   - `AGENTS.md`
10. 开发日报和周报优先读取指定时间范围内的 `development-log`、`current-state.md`、`decisions.md`、`git log`、`git diff`、changelog 和测试记录。
11. 周报按项目成果组织，不按会话名称或聊天顺序罗列。
12. 所有记录不得包含凭据和敏感信息。
