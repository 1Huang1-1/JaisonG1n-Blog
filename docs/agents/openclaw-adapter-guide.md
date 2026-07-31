# OpenClaw Adapter Guide

本文件仅为未来接入 OpenClaw 的说明，不实现 OpenClaw Skill。OpenClaw Blog Skill 应保持精简：识别博客意图、加载 `docs/agents/` 通用规则、收集必要上下文、调用 AI Content API，并返回草稿或发布结果。它不应复制整份写作风格或日记工作流。

当 OpenClaw 能读取项目文件时，直接读取 `docs/agents/`。不能读取时，在部署时同步这些通用规则到其工作目录，或由构建脚本生成合并后的只读规则文件；不得手工维护第二份不同版本。

凭据只能放在 OpenClaw 的 secret 或环境配置中，不得进入 `SKILL.md`、提示词或仓库。OpenClaw 使用独立的 Application Password，名称为 `OpenClaw Blog Agent`。默认仅创建草稿；相册、删除和站点设置仍禁止。

建议的未来结构如下，当前不创建：

```text
openclaw/skills/jaisong1n-blog/
├─ SKILL.md
├─ references/
│  └─ shared-rules.md
└─ scripts/
   └─ blog-api-client.*
```

`shared-rules.md` 应由 `docs/agents/` 自动生成或同步，不应作为人工维护的独立规则副本。
