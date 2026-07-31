# OpenClaw 微信通道无回复只读审查

- 时间：2026-07-31 17:36（Asia/Shanghai）
- 会话或模块：OpenClaw 微信通道诊断
- 当前分支：未在本次任务中确认
- 工作目录：D:\Blog\JaisonG1n-Blog（项目记录）；微信审查命令在用户提供的 OpenClaw 环境中执行
- 状态：已完成只读审查
- 是否已提交：否，本地未提交
- 是否已部署：否，本任务未部署

## 任务目标

只读定位 OpenClaw 微信通道“扫码登录成功、私聊消息无回复”的消息链路停止位置。任务范围不包括修改配置、重新登录、删除会话、重装插件、重置 Gateway、修改模型配置或输出凭据。

## 实际完成

- 核对 OpenClaw `2026.7.1 (2d2ddc4)`、Gateway、微信通道、插件、Agent、routing binding、pairing 和模型状态。
- 确认 Gateway 监听本机 `127.0.0.1:18789`，运行状态和只读连通性探测正常。
- 确认微信插件版本为 `@tencent-weixin/openclaw-weixin 2.4.6`，账号为 `003852e62d73-im-bot`，状态为 enabled、configured、running。
- 确认 Agent 为 `main`（默认）和 `dev`；没有显式微信 routing binding，因此默认路由至 `main`。两者配置的模型均为 `deepseek/deepseek-v4-flash`。
- 在不投递微信消息的前提下执行一次最小 Agent 调用，返回“模型正常”。
- 读取脱敏后的当天 Gateway 日志，并确认一条微信私聊入站已经抵达 Gateway；未找到其后的 Agent dispatch、模型 completion 或微信 outbound 记录。

## 修改文件

- `docs/project/development-log/2026-07-31/1736-wechat-channel-debug.md`：本会话独立任务日志。

## 测试和验证结果

- 命令：`openclaw gateway status`、`openclaw status`、`openclaw channels status --probe`。
  - 证据来源：测试输出可确认。
  - 实际结果：Gateway running，connectivity probe 为 ok；微信账号 running。
  - 是否通过：通过运行状态和通道状态检查；这不代表完整消息链路已验证。
- 命令：`openclaw agents list`、`openclaw agents bindings`、`openclaw models status`。
  - 证据来源：测试输出可确认。
  - 实际结果：默认 Agent 是 `main`，无显式 binding，默认模型存在且模型认证已配置；认证来源为本地 Agent 凭据存储，未记录任何凭据值。
  - 是否通过：通过配置与路由存在性检查。
- 命令：`openclaw agent --agent main --message "只回复：模型正常" --thinking off --timeout 60`。
  - 证据来源：测试输出可确认。
  - 实际结果：输出“模型正常”。
  - 是否通过：通过；仅验证 Gateway 到 `main` Agent 和模型的最小本地调用。
- 日志审查：当天 Gateway 日志中有一条微信 `inbound message` 和后续 `inbound` 记录；`dispatchReplyFromConfig`、`sendWeixinOutbound` 和微信 outbound 相关记录均为零。
  - 证据来源：测试输出可确认。
  - 实际结果：链路已确认进入 Gateway 并完成入站上下文记录，停止于 Agent dispatch 前。
  - 是否通过：不适用；这是定位结果。

## 真实环境验证结果

- 用户在真实环境中确认：微信扫码登录成功，向机器人发送私聊消息后未收到回复。
- 用户在真实环境中提供：Gateway 端口为 `18789`，通道账号为 `003852e62d73-im-bot`。
- 当前无法确认：在最后一次 Gateway 重启完成后，发送一条新的私聊消息的完整链路结果。

## 遇到的问题

- 被捕获的微信入站发生后约六秒，日志又出现 Gateway 和微信 provider 的启动记录。该次入站没有后续 dispatch 或 outbound 记录，无法从现有日志确定重启的触发方。
- 微信账号没有已批准 sender 的 allow-list 文件，pairing pending 列表为空。该项需要后续验证；捕获到的入站没有授权丢弃记录，不能把 pairing 缺失写成该消息的已证实直接阻断原因。

## 解决过程

1. 使用当前版本 help 确认可用的只读命令参数。
2. 对比 CLI 与 Scheduled Task：两者均指向同一份 OpenClaw JSON 配置；计划任务以 Administrator 的 InteractiveToken 运行。
3. 区分模型认证来源与当前 shell 环境：DeepSeek 认证来自 Agent 本地凭据存储，最小模型调用成功，因此没有证据表明计划任务缺少模型 shell 环境变量导致本次无回复。
4. 按 inbound、授权、路由、Agent dispatch、模型、outbound 顺序筛查日志，定位到入站后、Agent dispatch 前的缺口。

## 关键决定

- 不批准 pairing、不重启 Gateway、不重新扫码，也不修改 binding 或模型配置；本会话仅做审查。
- 将“Gateway/通道 running”与“消息链路完整正常”分开记录。
- 将 pairing 缺失列为待验证风险，而不是把它表述为已经证实的根因。

## 尚未完成

- 未确认造成入站后 Gateway 重新初始化的具体触发方。
- 未在稳定运行后的新微信私聊上重新验证 inbound、Agent dispatch、模型和 outbound 全链路。
- 未确认当前发送者是否需要、以及是否能够产生可批准的 pairing 请求。

## 下一步

1. 在 Gateway 保持稳定时，由用户发送一条新的纯文本私聊消息。
2. 只读检查新的日志是否依次出现 inbound、Agent dispatch、模型调用和 outbound。
3. 如产生 pending pairing 请求，由用户决定是否手动批准；如再次出现重启，继续定位其触发来源。

## 资料来源

- 测试输出可确认：本会话执行的 OpenClaw 状态、help、配置路径、Scheduled Task、日志筛查和最小 Agent 调用。
- 用户在真实环境中确认：扫码成功及私聊无回复现象。
- 仓库可确认：本项目的跨会话日志规则与日志命名要求。
- 当前无法确认：重启触发方、重启后新消息的完整处理结果及 pairing 的实际批准流程。
