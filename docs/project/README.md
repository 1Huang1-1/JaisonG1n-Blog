# 跨会话项目记录

本目录用于在多个 Codex 会话并行工作时保留可核验的项目上下文。它不替代 Git 历史、测试输出、部署平台状态或正式博客内容。

- `current-state.md`：项目汇总任务维护的已确认最终状态。
- `decisions.md`：重要架构、权限、安全或兼容性决策。
- `development-log/YYYY-MM-DD/`：每个实际任务独立创建一份日志；禁止当天汇总文件。

## 任务日志文件名

使用当前本地时间创建 `development-log/YYYY-MM-DD/HHmm-short-topic.md`。`short-topic` 使用简短英文或拼音 slug，例如 `1721-project-records.md`。文件已存在时不得覆盖，改用 `HHmm-short-topic-2.md`、`HHmm-short-topic-3.md` 等未占用名称。

每个会话只写自己的任务日志。`current-state.md` 和 `decisions.md` 由专门协调的汇总任务更新，普通任务不应改写它们。

## 任务日志模板

```md
# 任务名称

- 时间：
- 会话或模块：
- 当前分支：
- 工作目录：
- 状态：已完成 / 部分完成 / 阻塞 / 只读检查
- 是否已提交：
- 是否已部署：

## 任务目标

## 实际完成

## 修改文件

## 测试与验证

每项测试写清：

- 命令或验证方式：
- 实际结果：
- 是否通过：

## 遇到的问题

## 解决过程

## 关键决定

## 未完成内容

## 下一步

## 资料来源

记录使用的：

- Git commit
- 测试报告
- 项目文档
- 用户确认
```

禁止在任何项目记录中写入 Password、Token、Authorization、Cookie、Application Password、环境变量值或私密用户数据。
