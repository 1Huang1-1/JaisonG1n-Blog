# WordPress 全站管理插件：第一阶段

第一阶段只交付 WordPress 插件、公开快照和即时构建入口，不修改 Astro 的现有数据入口。插件未安装或尚未配置时，当前博客构建和内容保持原状。

## 安装

1. 在 Hostinger 中备份 WordPress。
2. 在 WordPress 后台进入“插件 > 安装插件 > 上传插件”。
3. 上传 `jaisong1n-site-manager-0.2.1.zip` 并启用。
4. 在“设置 > 博客管理”填写允许公开的配置。
5. 在“外观 > 菜单”创建菜单并分配到“JaisonG1n 顶部导航”。

插件停用时不删除任何数据。删除插件时会撤销插件添加的角色能力，但默认保留内容和设置；只有管理员在设置页明确开启永久清理后，`uninstall.php` 才会删除插件内容和设置。

## 权限

- 管理员：管理全部自定义内容、公开设置、导航、清理选项和手动 dispatch。
- 编辑：管理全部自定义内容，但不能修改全站设置、清理数据或手动 dispatch。
- 作者与投稿者：只保留 WordPress 原有文章权限，不获得任何自定义内容类型能力。
- 公开访问者：只能读取 `GET /wp-json/jaisong1n/v1/site-snapshot` 和 WordPress 原有公开文章接口。

所有自定义文章类型使用独立 `capability_type`、`map_meta_cap` 和 `show_in_rest`。设置保存使用 WordPress Settings API nonce；手动 dispatch 同时检查 `manage_options` 和专用 nonce。

## 公开快照

```http
GET /wp-json/jaisong1n/v1/site-snapshot
If-None-Match: "上一请求返回的 revision"
```

精简响应示例：

```json
{
  "revision": "sha256-of-normalized-public-content",
  "generatedAt": "2026-07-29T10:00:00+00:00",
  "schemaVersion": 2,
  "site": { "title": "JaisonG1n", "lang": "zh_CN" },
  "profile": { "name": "JaisonG1n", "links": [] },
  "appearance": { "banner": { "desktop": [], "mobile": [], "interval": 5 } },
  "navigation": [],
  "widgets": {},
  "pages": [],
  "projects": [
    {
      "id": "example-project",
      "title": "示例项目",
      "description": "供卡片显示的纯文本",
      "contentHtml": "<p>经过清理的详情内容</p>",
      "image": "https://cms.example.com/wp-content/uploads/project.webp",
      "imageMedia": {
        "id": 42,
        "url": "https://cms.example.com/wp-content/uploads/project.webp",
        "alt": "示例项目封面",
        "mimeType": "image/webp",
        "width": 1600,
        "height": 900
      },
      "category": "web",
      "techStack": ["Astro", "WordPress"],
      "status": "completed",
      "sourceCode": "https://github.com/example/project",
      "visitUrl": "https://example.com",
      "featured": true,
      "showImage": true
    }
  ],
  "skills": [],
  "aiTools": [],
  "timeline": [],
  "friends": [],
  "devices": [],
  "diary": [],
  "albums": [],
  "anime": [],
  "announcements": [],
  "security": {
    "trustedMediaHosts": ["cms.example.com"],
    "embedHosts": ["www.youtube.com", "player.bilibili.com"]
  },
  "mediaManifest": [
    {
      "id": 42,
      "url": "https://cms.example.com/wp-content/uploads/project.webp",
      "alt": "示例项目封面",
      "mimeType": "image/webp",
      "width": 1600,
      "height": 900
    }
  ]
}
```

`revision` 和 `ETag` 来自不包含 `generatedAt` 的规范化公开快照。内容未变化且请求携带匹配的 `If-None-Match` 时返回 `304`。快照不包含普通文章正文和图片二进制；普通文章继续通过 WordPress 原生分页接口读取。

页面和自定义内容不能使用 Astro 保留 slug，也不能跨内容类型重复。后台保存和快照生成都会检查冲突。单个富文本字段限制 200 KB，快照限制 2 MB，并对各类型数量、导航层级和媒体清单数量设置硬上限。

### schemaVersion 2 字段映射

| 类型 | WordPress 来源与快照字段 |
| --- | --- |
| 项目 | slug → `id`；标题 → `title`；编辑器 → `description`、`contentHtml`；特色图片 → `image`、`imageMedia`；专属字段 → `category`、`techStack`、`status`、`sourceCode`、`visitUrl`、`featured`、`showImage` |
| 技能 | slug → `id`；标题 → `name`；编辑器 → `description`；专属字段 → `icon`、`category`、`level`、`experience.years/months`、`color` |
| AI 工具 | slug → `id`；标题 → `name`；编辑器 → `description.zh_CN`；专属字段 → `icon`、`category`、`frequency`、`url`、`usage.zh_CN`、`tags`、`color` |
| 时间线 | slug → `id`；标题 → `title`；编辑器 → `description`、`contentHtml`；专属字段 → `type`、`startDate`、`endDate`、`location`、`organization`、`position`、`skills`、`achievements`、`links`、`icon`、`color`、`featured` |
| 友链 | 标题 → `title`；编辑器 → `desc`；特色图片 → `imgurl`、`avatarMedia`；专属字段 → `siteurl`、`tags` |
| 设备 | 标题 → `name`；编辑器 → `description`；特色图片 → `image`、`imageMedia`；专属字段 → `category`、`specs`、`link` |
| 日记 | 编辑器 → `content`、`contentHtml`；发布时间 → `date`；专属字段 → `images`、`location`、`mood`、`tags`；后台标题不公开 |
| 相册 | slug → `id`；标题 → `title`；编辑器 → `description`、`contentHtml`；特色图片 → `cover`、`coverMedia`；专属字段 → `date`、`location`、`tags`、`photos` |
| 追番 | 标题 → `title`；编辑器 → `description`；特色图片 → `cover`、`coverMedia`；专属字段 → `status`、`rating`、`year`、`genre`、`studio`、`link`、`progress`、`totalEpisodes` |
| 公告 | 标题 → `title`；编辑器 → `content`；专属字段 → `closable`、`link.enable/text/url/external` |

`description`、`desc` 和日记 `content` 为移除标签、解码实体并规范化空白后的纯文本。项目、时间线、日记和相册另有经过清理的 `contentHtml`。单一媒体保留兼容 URL 并提供完整媒体对象；日记 `images` 和相册 `photos` 使用 `{ mediaId, src, alt }` 引用 `mediaManifest`。

### 枚举

| 字段 | 允许值 |
| --- | --- |
| 项目 `category` | `web`、`mobile`、`desktop`、`other` |
| 项目 `status` | `completed`、`in-progress`、`planned` |
| 技能 `category` | `frontend`、`backend`、`database`、`tools`、`other` |
| 技能 `level` | `beginner`、`intermediate`、`advanced`、`expert` |
| AI 工具 `category` | `chat`、`coding`、`image`、`audio`、`video`、`writing`、`search`、`other` |
| AI 工具 `frequency` | `daily`、`weekly`、`occasional`、`experimental` |
| 时间线 `type` | `education`、`work`、`project`、`achievement` |
| 时间线链接 `type` | `website`、`certificate`、`project`、`other` |
| 追番 `status` | `watching`、`completed`、`planned`、`onhold`、`dropped` |

设备 `category` 是最多 500 字符的纯文本，不使用枚举。所有 Headless 内容均有“显示顺序”字段；快照按显示顺序升序、发布时间降序、WordPress ID 升序输出。

## 富文本和秘密信息

公开文章、页面和自定义内容会移除 `script`、`style`、事件属性和危险协议。`iframe`、`audio`、`video` 只有来源主机位于设置页白名单时才保留，删除行为写入 WordPress 错误日志但不会记录凭据。

GitHub Token 只能通过 `wp-config.php` 常量或服务器环境变量提供：

```php
define('JG_GITHUB_TOKEN', getenv('JG_GITHUB_TOKEN'));
```

不要把真实 Token 写入该示例。Token 不进入数据库、插件设置、日志或 REST。后台只显示“已配置/未配置”。目标仓库固定为 `1Huang1-1/JaisonG1n-Blog`，自动通知使用 `repository_dispatch` 的 `wordpress_content_changed` 事件，并在 45 秒内防抖和核对真实公开 revision。

GitHub fine-grained PAT 只绑定上述仓库，并按项目约定授予 Repository `Contents: Read and write`。现有部署工作流同时保留 push、repository_dispatch、schedule 和 workflow_dispatch。

## 本地测试

`pnpm test:wordpress-plugin` 检查插件的权限、公开路由、Token、schema v2 和工作流契约。`pnpm test:wordpress-playground` 会启动一次性 WordPress Playground，实际验证十种内容类型的角色权限与草稿隔离、重复行、稳定排序、媒体对象、私网主机拦截、富文本清理、保留 slug、预览入口以及 ETag `304`；它不会连接或修改真实 WordPress。

## 从 0.1.0 或 0.2.0 升级

0.2.1 修复 Windows ZIP entry 使用反斜杠导致 Linux 主机找不到固定插件 basename 的问题。插件目录和入口始终为 `jaisong1n-site-manager/jaisong1n-site-manager.php`，可在 WordPress 上传界面选择“使用上传的版本替换当前版本”。插件不会删除旧 meta；设备规格、时间线链接、日记图片和相册照片会兼容读取旧字符串或媒体 ID 列表，并在管理员下一次保存对应内容时转换为 v2 结构。公开快照继续使用 `schemaVersion: 2`。本阶段尚未切换 Astro 数据入口，因此现有博客显示不受影响。

插件包必须通过 `pnpm package:wordpress-plugin` 生成，并使用 `pnpm verify:wordpress-plugin-package` 验证。不要使用 Windows 右键压缩或 PowerShell `Compress-Archive` 生成发布包，因为其中央目录可能写入反斜杠。

## 第二阶段

第二阶段在用户确认插件后台和快照后实施：统一 Astro 同步临时目录、生产失败关闭策略、受信媒体主机与私网拦截、15 MB 单文件限制、SHA-256 媒体镜像，以及项目、技能、AI 工具和时间线的首批迁移。迁移验收前继续保留旧数据入口。
# Version note

This historical phase-1 document describes pre-0.6.0 repository dispatch
behavior. The current plugin is 0.6.0, keeps schemaVersion 5, uses
`workflow_dispatch` with GitHub API version `2026-03-10`, and stores any
database fallback Token in a private option with autoload disabled.
