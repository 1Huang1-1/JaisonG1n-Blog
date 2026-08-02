# Agent skill installation

- 时间：2026-07-31 19:40 +08:00
- 会话或模块：Codex / agent skill installation
- 当前分支：codex/ai-content-api-0-7
- 工作目录：D:\Blog\JaisonG1n-Blog
- 状态：部分完成
- 是否已提交：否，本地未提交
- 是否已部署：不适用

## 任务目标

安装用户指定的 Agent-Reach、Horizon、MediaCrawler、Auto-Redbook-skills、nuwa-skill、guizang-social-card-skill 和 generative-Media-skills，供后续日报工作使用。

## 实际完成

- 通过 Codex skill installer 安装 `Panniantong/Agent-Reach` 的 `agent_reach/skill`，目标名为 `agent-reach`。
- 安装 `simota/agent-skills` 在提交 `82d868766a7d91fa82012b293ed80cb9551f430c6` 中的 `horizon` 技能；该提交仍包含独立的 Horizon 技能。
- 安装 `comeonzhj/auto-redbook-skills` 仓库根目录技能，目标名为 `auto-redbook-skills`；其 `SKILL.md` 内部名称为 `xhs-note-creator`。
- 安装 `alchaincyf/nuwa-skill` 仓库根目录技能，目标名为 `nuwa-skill`。
- 安装 `op7418/guizang-social-card-skill` 仓库根目录技能。
- 安装 `SamurAIGPT/Generative-Media-Skills` 的 `core/` 与 `library/` 下 60 个技能目录；未配置 MuAPI 密钥，未执行媒体生成脚本。
- 检查 `NanmiCoder/MediaCrawler` 默认分支及提交历史，未发现任何 `SKILL.md`；因此没有把普通爬虫项目代码冒充 Agent Skill 安装。

## 修改文件

- `docs/project/development-log/2026-07-31/1940-skill-install.md`
- 未修改业务代码、插件或前端。
- 技能安装目录位于 Codex 用户技能目录，不属于本仓库。

## 测试与验证

- 验证方式：检查每个已安装目标目录是否存在 `SKILL.md`，并统计文件数量。
- 实际结果：`agent-reach`、`horizon`、`auto-redbook-skills`、`nuwa-skill`、`guizang-social-card-skill`、`edit`、`media`、`platform`、`blog-header`、`rednote-cover`、`social-pack` 等目标均存在 `SKILL.md`。
- 是否通过：通过。
- 验证方式：查询 `NanmiCoder/MediaCrawler` 公开仓库树及 `SKILL.md` 提交历史。
- 实际结果：未发现标准 Agent Skill 文件；`skills` CLI 列表检查未得到可安装技能并超时退出。
- 是否通过：不适用，标记为待确认/未安装。

## 遇到的问题

- Horizon 的独立技能已在上游后续提交中合并进 `shift`，当前安装使用合并前仍可确认存在的公开提交。
- MediaCrawler 官方仓库不是 Agent Skill 目录，无法使用标准 installer 安装为技能。
- Generative-Media-Skills 是技能集合；本次安装了 `core/` 与 `library/` 下的 60 个技能，不包含仅供 OpenCode 的重复入口。

## 解决过程

- 先读取本地 skill-installer 规则，再通过公开 GitHub 仓库路径安装。
- 对不存在标准 `SKILL.md` 的 MediaCrawler 保持未安装，不执行爬虫、浏览器登录、Cookie 读取或平台采集。
- 对生成媒体技能只下载说明和随附资源，不执行脚本或配置第三方服务。

## 关键决定

- 使用固定提交安装 Horizon，避免安装已被上游重命名或合并后的不同技能。
- 不使用未验证的第三方 MediaCrawler 包装技能。
- 不记录凭据、Token、Authorization、Cookie、Application Password 或环境变量值。

## 未完成内容

- MediaCrawler 标准 Agent Skill 尚未安装；官方仓库当前没有可供 installer 选择的 `SKILL.md`。
- 各第三方技能尚未执行功能测试；本次只完成文件安装和存在性检查。

## 下一步

- 后续日报任务只在需要时读取相关技能的 `SKILL.md`。
- 如需 MediaCrawler 能力，应先确认一个包含标准 `SKILL.md` 且来源可审计的仓库或由用户指定本地封装方案。

## 资料来源

- Codex skill-installer `SKILL.md` 与安装脚本。
- 公开 GitHub 仓库树和 `SKILL.md` 文件：
  - `Panniantong/Agent-Reach`
  - `simota/agent-skills`（提交 `82d868766a7d91fa82012b293ed80cb9551f430c6`）
  - `comeonzhj/auto-redbook-skills`
  - `alchaincyf/nuwa-skill`
  - `op7418/guizang-social-card-skill`
  - `SamurAIGPT/Generative-Media-Skills`
  - `NanmiCoder/MediaCrawler`
- 用户确认的安装目标。

禁止记录：Password、Token、Authorization、Cookie、Application Password、环境变量值和私密用户数据。
