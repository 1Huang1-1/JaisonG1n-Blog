# JaisonG1n 个人内容操作系统：产品愿景与长期路线图

> 本文用于让 Codex、Claude Code 与后续开发者理解项目的长期目标、开发优先级、安全边界和版本演进方向。  
> 它不是一次性需求清单，也不是要求立即实现全部功能。每次开发只应完成当前明确版本范围，避免为了“未来可能需要”而提前堆叠复杂度。

---

## 1. 项目定位

当前项目正在从“可调用 WordPress API 的微信机器人”逐步演进为一个：

> **以微信为主要入口，集写作、资料整理、配图、审阅、发布、部署跟踪和个人知识管理于一体的个人内容操作系统。**

最终目标不是让 AI 自动替用户经营博客，而是让 AI 成为用户的：

- 私人编辑
- 写作助理
- 资料员
- 图片与排版助手
- 发布助理
- 开发记录整理器
- 长期个人知识库入口

核心原则始终是：

```text
AI 可以准备、建议、预览和执行受控操作；
公开发布、撤回、删除等高风险动作必须由用户明确确认。
```

---

## 2. 当前技术架构

```text
微信
  ↓
OpenClaw Gateway
  ↓
DeepSeek / 其他大模型
  ↓
jaisong1n-blog Skill
  ↓
jaisong1n-blog-tools
  ↓
WordPress Site Manager AI Content API
  ↓
WordPress 内容与媒体
  ↓
GitHub Actions / Astro 构建与部署
```

当前主要代码仓库：

### WordPress / Astro 主仓库

```text
D:\Blog\JaisonG1n-Blog
```

主要职责：

- WordPress Site Manager 插件
- AI Content API
- 内容权限与审计
- 发布、构建与部署联动
- Astro 前端与博客展示

### OpenClaw Blog Agent 仓库

```text
~/projects/openclaw-blog-agent
```

主要职责：

- 微信端工具调用
- 内容创建、查询、修改
- 两阶段确认流程
- OpenClaw Skill
- WordPress API Transport
- 运行时部署与测试

---

## 3. 当前稳定能力

截至当前阶段，系统已具备或正在完成：

### 日记 diary

- 创建草稿
- 查询草稿
- 按 ID、标题、slug 定位
- 读取详情
- 修改草稿
- 修改前预览
- 十分钟一次性 proposal
- expectedModifiedAt 乐观并发控制
- 精确确认短语
- 服务端 preparePublish
- 一次性 confirmationToken
- 幂等发布接口
- AI 所有权与原生作者权限统一
- 仅 diary 获得受控发布能力

### 安全机制

- 不允许模型直接发布
- 不允许绕过 prepare 阶段
- 不允许普通“好”“继续”“发布吧”触发高风险动作
- token 不向模型或用户明文暴露
- proposal 一次性使用
- 过期、重启、重新生成预览后旧 proposal 失效
- 不允许直接调用 `/wp-json/wp/v2` 写入
- 不允许 OpenClaw 直接触发 GitHub workflow
- 不扩大 `edit_others_*` 等宽泛权限
- 审计日志不记录完整正文、凭据、Authorization 或明文 token

### 部署状态跟踪（Site Manager 0.8.2）

- 只读 `deployment-status` 接口区分 WordPress 内容、dispatch 接受、GitHub 构建、前台部署与公开页面五层状态
- workflowRunId 只从 GitHub 200 响应解析，204 不伪造；GitHub success 不直接映射为已部署
- 服务端生成 canonical 公开地址（diary/article），与 CMS 编辑地址分离
- 页面探测仅允许配置的生产域名并限制重定向、大小与超时

### 网络稳定性

OpenClaw LiveTransport 已使用专用 Undici Agent：

- 强制 IPv4
- 独立 dispatcher
- 不修改全局网络行为
- 不影响 DeepSeek、微信或其他插件
- 保留 TLS 校验和请求总超时

---

## 4. 长期产品原则

### 4.1 用户确认优先

以下动作必须使用两阶段确认：

- 发布
- 撤回
- 删除
- 覆盖式恢复
- 批量修改
- 批量媒体导入
- AI 图片生成付费调用
- 定时发布
- 修改公开 URL 或 slug
- 可能影响现有构建和部署的操作

标准流程：

```text
准备操作
→ 展示影响范围
→ 生成一次性 proposal
→ 用户回复精确确认短语
→ 执行
→ 回读验证
→ 返回实际结果
```

### 4.2 默认草稿，不默认发布

用户说：

```text
发到博客
保存到博客
帮我写一篇
```

默认行为应为：

```text
创建或更新草稿
```

只有明确进入受控发布流程，才允许公开。

### 4.3 事实、实测与推断必须分开

生成内容时应明确区分：

- 用户真实经历
- 实际测试结果
- 官方资料
- 模型推断
- 尚未确认的信息

不得为了文章流畅而编造：

- 用户未发生过的经历
- 未执行过的测试
- 未出现过的报错
- 未完成的功能
- 不存在的引用或链接

### 4.4 不提前过度设计

本文是长期方向，不代表所有功能都要立即实现。

每次开发必须遵循：

- 只做当前版本明确范围
- 先读真实代码和契约
- 不猜字段和路由
- 不为未来功能提前引入无用抽象
- 不破坏现有兼容性
- 不扩大权限边界

---

## 5. 推荐版本路线

### OpenClaw 0.3.0：日记微信确认发布

目标：

```text
微信写日记
→ 保存草稿
→ 修改与预览
→ 准备发布
→ 用户精确确认
→ 正式发布
```

新增工具：

```text
blog_prepare_publish
blog_publish
```

必须满足：

- 运行时总计 8 个博客工具
- token 仅保存在运行时 proposal 中
- 用户只看到 proposalId
- publish 后重新读取并确认 `status=publish`
- 不直接调用 GitHub API
- 真实发布测试只使用专用测试草稿

### OpenClaw 0.4.0：部署与公开状态跟踪

新增：

```text
blog_get_deployment_status
blog_get_public_url
blog_check_publish_result
```

微信端应能返回：

```text
WordPress：已发布
构建：进行中 / 成功 / 失败
部署：成功
公开地址：...
耗时：...
```

需要解决：

- 发布成功但前台尚未更新的状态差异
- 构建失败提示
- 构建超时
- 部署后的页面可访问性验证
- 不把“WordPress 已发布”误报为“博客前台已上线”

服务端支撑已由 Site Manager 0.8.2 提供：`GET /content/{type}/{id}/deployment-status` 返回五层状态与 canonical 公开地址；客户端只读该接口，不得自行猜测 URL 或把 dispatch accepted 当作构建成功。

### Site Manager 0.9.0：文章 article 完整工作流（服务端已实现）

服务端已为 `article` 提供：

```text
createDraft
read
deploymentStatus
updateDraft
preparePublish
publish
```

文章复用 diary 的受控发布管道，但使用独立的 `jg_ai_publish_article_drafts` capability 与“审核制文章发布”“AI 自建文章自动允许进入受控发布流程”设置（默认关闭）；文章更新白名单为 title/content/excerpt/slug。分类、标签与特色图写入保留到媒体版本，未在本版本实现。

文章与日记应使用不同的内容模板和规则。

#### 日记模板倾向

- 第一人称
- 自然记录
- 时间、地点、心情
- 更少标题层级
- 保留个人表达

#### 技术文章模板倾向

- 背景
- 问题
- 排查过程
- 根因
- 解决方案
- 验证结果
- 总结与后续

#### 教程模板倾向

- 环境说明
- 前置条件
- 操作步骤
- 命令
- 常见错误
- 验证方法

### OpenClaw 0.5.0：文章微信完整闭环

支持：

```text
帮我写一篇关于某个技术问题的文章
→ 生成草稿
→ 展示结构
→ 用户要求局部修改
→ 重新预览
→ 准备发布
→ 精确确认
→ 正式发布
```

是否拆分文章专用工具，应以实际契约与复杂度为准，不要机械复制 diary。

### Site Manager 1.0.0：受控媒体系统

目标：

- 上传用户图片
- 导入开放许可图片
- 保存来源、作者、许可证
- 插入正文
- 设置特色图
- 清理未使用临时媒体
- 图片去重与压缩

建议 API 能力：

```text
prepareMediaImport
importMedia
getMedia
insertMedia
setFeaturedImage
deleteUnusedMedia
```

服务端必须防护：

- SSRF
- 内网地址访问
- 任意 URL 下载
- 非图片 MIME
- 扩展名伪造
- 超大文件
- 重定向绕过
- 恶意 SVG
- 重复文件
- 未知许可证
- 未授权来源

建议保存元数据：

- 原始来源 URL
- 作者
- 许可证
- 许可证链接
- 原文件名
- 下载时间
- 内容哈希
- Alt 文本
- Caption
- 是否 AI 生成

### OpenClaw 0.6.0：开放图片检索、选图与插图

图片来源优先级：

```text
1. 用户自己上传的图片
2. Wikimedia Commons 等开放许可来源
3. 许可明确的公共图库
4. 确实找不到时，再询问是否 AI 生成
```

不得默认抓取：

- 百度图片搜索结果
- Google 图片搜索结果
- 来源和许可证不明的网页图片
- 需要付费或限制转载的素材

建议新增工具：

```text
blog_search_images
blog_prepare_media_import
blog_import_media
blog_insert_images
blog_set_featured_image
blog_upload_user_image
```

微信体验：

```text
用户：给这篇文章找三张配图

机器人：
找到 6 张候选图片：
1. 缩略图
   来源
   作者
   许可证
   推荐插入位置

用户：选 1、3，第一张做封面

机器人：
展示最终插图方案
生成确认短语
用户确认后再上传并插入
```

#### AI 生成策略

AI 图片只作为兜底：

- 开放图片找不到
- 需要专属封面
- 需要流程图、架构图、概念图
- 现有图片无法准确表达

AI 生成前必须单独确认，并说明可能产生 API 费用。

默认成本控制：

- 每篇最多生成 1 张
- 默认中等质量
- 失败不得自动无限重试
- 设置月度费用上限
- 记录调用成本
- 明确标记 AI 生成

### OpenClaw 0.7.0：发布前内容质量检查

增加发布检查器：

```text
blog_review_content
blog_check_links
blog_scan_secrets
blog_review_seo
```

检查内容：

- 标题是否过长
- 摘要是否缺失
- slug 是否合理
- 错别字
- 代码块完整性
- Markdown / HTML 结构
- 图片 Alt
- 图片来源与许可证
- 无效链接
- 敏感信息
- API Key
- Authorization
- 内网地址
- 本地路径
- 用户名、密码
- 未经证实的事实
- 引用缺失

结果应区分：

```text
阻塞发布
建议修改
仅提醒
```

默认只给建议，不自动重写整篇内容。

### OpenClaw 0.8.0：从开发过程自动生成内容

支持从以下素材生成草稿：

- Git commits
- Git diff 摘要
- 开发日志
- 测试结果
- 微信碎片记录
- 项目文档
- 用户手动输入的要点

典型指令：

```text
根据今天两个仓库的提交，生成一篇开发日记。
```

输出应包含：

- 今天完成了什么
- 遇到了什么问题
- 根因是什么
- 为什么这样修
- 最终如何验证
- 还有什么没做
- 后续计划

禁止把 commit message 简单拼接成流水账。

#### 素材箱

用户平时可以说：

```text
记一下：今天修复了 WordPress 403。
记一下：根因是 post_author 和 AI owner 权限模型不一致。
```

机器人只保存素材。

之后：

```text
把今天的素材整理成日记。
```

再生成草稿。

### OpenClaw 0.9.0：计划、提醒与定时发布

支持：

- 发布前提醒
- 待审稿列表
- 内容日历
- 定时发布
- 周报生成
- 项目里程碑记录

定时发布建议流程：

```text
现在确认内容
→ 创建定时任务
→ 发布前再次提醒
→ WordPress 在指定时间执行
```

对于公开内容，不建议由 OpenClaw 在后台无感直接发布。

### OpenClaw 1.0.0：个人内容操作系统

目标体验：

```text
用户：
根据今天两个项目的提交和聊天记录，写一篇开发日记。
配两张开放许可图片和一张流程图。

机器人：
生成正文、找到候选图片、生成流程图并展示预览。

用户：
图片选 1 和 4，第二部分再详细一点。

机器人：
更新草稿并重新展示。

用户：
准备发布。

机器人：
展示最终内容、公开风险、构建影响和精确确认短语。

用户：
确认发布 108 K7P2Q9

机器人：
发布成功。
构建完成。
公开链接：...
```

---

## 6. 重要增强方向

### 6.1 发布后撤回

新增：

```text
blog_prepare_unpublish
blog_unpublish
```

必须：

- 两阶段确认
- 展示撤回影响
- 回读验证
- 触发一次重新构建
- 不允许模糊确认

### 6.2 内容版本与恢复

支持：

- 发布前快照
- 修改前快照
- 版本列表
- 差异对比
- 受控恢复

典型指令：

```text
把这篇文章恢复到昨天晚上发布前的版本。
```

恢复同样需要 proposal 和明确确认。

### 6.3 博客知识库

发布过的文章、日记和开发日志可作为个人知识库。

典型问题：

```text
我之前是怎么解决 Node fetch IPv6 超时的？
```

系统应返回：

- 对应文章
- 关键结论
- 相关提交
- 时间
- 是否有更新版本

知识库应优先使用用户自己的内容，再补充外部资料。

### 6.4 内部链接建议

写新文章时自动发现：

- 相关旧文章
- 相关项目记录
- 相似故障
- 可引用的历史结论

只建议，不自动插入。

### 6.5 健康检查

增加统一诊断能力：

```text
检查博客机器人状态
```

返回：

```text
微信通道：正常
DeepSeek：正常
WordPress：正常
Site Manager：0.8.2
OpenClaw Agent：0.3.0
待发布草稿：4
最近构建：成功
媒体空间：23%
```

应检查：

- Gateway
- 微信通道
- DeepSeek
- WordPress API
- capabilities
- 插件版本
- Skill 同步状态
- 构建状态
- 媒体空间
- 备份状态

---

## 7. 内容安全与隐私要求

### 永远不得写入公开内容

- API Key
- Application Password
- Authorization Header
- Cookie
- Session Token
- 数据库密码
- 私有仓库地址
- 内网地址
- 真实敏感个人信息
- 未授权第三方内容
- 未确认的私人聊天
- 不适合公开的项目资料

### 发布前必须扫描

```text
sk-
Bearer
Authorization:
password=
secret=
token=
192.168.
10.
localhost
C:\
/home/
用户名与邮箱
```

扫描不能只依赖简单正则，应支持上下文判断和白名单。

### 审计原则

应记录：

- 谁执行
- 什么动作
- 内容 ID
- 字段名
- proposal 指纹
- token 指纹
- idempotencyKey
- 结果
- 拒绝原因
- 时间

不应记录：

- 完整正文
- 明文 token
- 密码
- Authorization
- 用户未公开的敏感素材

---

## 8. 图片版权原则

### 默认允许

- Public Domain
- CC0
- CC BY
- 明确允许转载并满足署名要求的来源

### 谨慎处理

- CC BY-SA
- 编辑后有相同方式共享要求的图片
- 平台要求回链或下载统计的图片
- 商标、人物肖像、产品宣传图

### 默认拒绝

- 来源不明
- 无许可证信息
- 搜索引擎直接抓取
- 水印明显且无授权
- 付费图库未授权
- 新闻摄影未经许可
- 社交平台用户图片未经同意

系统应自动生成：

- Alt 文本
- Caption
- 作者
- 来源
- 许可证
- 许可证链接
- 文末图片来源说明

---

## 9. Codex 开发行为准则

Codex 在本仓库工作时应遵循：

### 允许默认执行

- 读取代码
- 检查状态
- 搜索契约
- 运行测试
- 构建
- 静态检查
- 检查密钥
- 修改代码
- 更新文档
- 创建本地 commit

### 未明确授权时禁止

- push
- merge
- tag
- GitHub Release
- 上传生产 WordPress
- 修改生产数据库
- 实际 publish
- 实际 unpublish
- 删除内容
- 直接触发 GitHub workflow
- 扩大角色权限
- 输出凭据

### 每次开始任务前

1. 阅读 `AGENTS.md`
2. 阅读当前版本状态文档
3. 阅读相关 API 契约
4. 确认当前分支和工作区
5. 区分本地实现与生产状态
6. 不根据旧文档猜测当前生产能力
7. 以真实代码、测试和 capabilities 为准

### 每次结束任务前

1. typecheck
2. tests
3. build
4. secret scan
5. `git diff --check`
6. `git status`
7. 检查无临时文件
8. 检查无凭据
9. 更新 current-state
10. 更新开发日志
11. 报告是否需要生产部署
12. 不创建空提交

---

## 10. WordPress 插件设计原则

### 权限最小化

- 不授予管理员
- 不授予 `edit_others_*`
- 类型级能力和对象级能力分离
- update 与 publish 权限分离
- publishable 仅表示对象允许进入发布流程
- publishable 本身不能授予所有权
- AI owner、原生作者和 editable 需要一致且可审计

### 契约稳定

- schemaVersion 变更需谨慎
- pluginVersion 与真实生产版本分开记录
- capabilities 是客户端能力判断的主要来源
- 不同内容类型可有不同 operations
- 新字段应保持向后兼容
- 错误码需要稳定

### 高风险接口

必须包含：

- expectedModifiedAt
- confirmationToken 或 proposal
- idempotencyKey
- 审计
- 一次性消费
- 过期机制
- 重放保护
- 明确错误码

---

## 11. OpenClaw 设计原则

### Transport 只负责真实 API

Transport 层负责：

- 请求构造
- 响应解析
- 超时
- 错误转换
- 网络安全
- 凭据脱敏

Transport 不负责：

- 用户确认文案
- proposal 管理
- 模型意图判断
- 自动发布决策

### Tool 负责流程约束

工具负责：

- capabilities 检查
- 类型检查
- 状态检查
- proposal 创建
- 精确确认
- 回读验证
- 用户可理解的结果

### Skill 负责模型行为边界

Skill 必须明确：

- “保存到博客”默认草稿
- 高风险操作必须两阶段确认
- 普通自然语言不能当作精确确认
- 不得泄露 token
- 不得绕过工具直接调用接口
- 不得虚构成功
- 网络不确定时必须报告不确定

---

## 12. 当前最推荐的开发顺序

```text
1. 完成 OpenClaw 0.3.0
   日记微信确认发布

2. 真实发布专用测试草稿
   验证 publish、幂等、构建和公开链接

3. 增加部署状态跟踪
   避免“已发布但未上线”的误报

4. 扩展 article 修改与受控发布

5. 实现媒体导入、上传和插图

6. 接入开放许可图片检索

7. 加入 AI 图片兜底生成

8. 增加发布前质量、安全和引用检查

9. 从 Git、开发日志和微信素材生成内容

10. 建立博客知识库与个人长期记忆
```

---

## 13. 非目标

当前阶段不追求：

- 全自动无人审阅发布
- 批量搬运网上文章
- 批量抓取搜索引擎图片
- 自动 SEO 堆词
- 自动评论区运营
- 自动社交平台群发
- 为了“智能”而增加不可控 Agent 权限
- 让 AI 获得 WordPress 管理员权限
- 让 AI 直接操作 GitHub 部署接口
- 为未来想象提前重构全部架构

---

## 14. 最终愿景

项目成功的标准不是“工具数量很多”，而是：

- 用户在微信里就能完成主要内容工作
- 每一步都清楚发生了什么
- 高风险动作始终由用户确认
- 内容可信，不编造经历和测试
- 图片来源清楚，版权可追溯
- 发布后能确认真实上线
- 系统可维护、可审计、可恢复
- 博客逐渐成为用户自己的长期知识库
- 开发过程本身也能沉淀为内容

最终它应该像一个可靠的个人编辑部，而不是一个拿到管理员权限后到处乱点的机器人。
