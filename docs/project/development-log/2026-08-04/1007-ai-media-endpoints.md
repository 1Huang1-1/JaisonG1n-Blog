# 受控 AI 媒体上传与回读（Site Manager 0.12.0）

- 时间：2026-08-04 10:07（Asia/Shanghai）
- 会话或模块：Codex / Site Manager 插件
- 当前分支：`master`（本地）
- 工作目录：`D:\Blog\JaisonG1n-Blog`
- 状态：已完成（实现、定向与回归测试、本地待提交）
- 是否已提交：否，随本会话本地提交创建
- 是否已部署：否；未 push/tag/release，未安装到生产 WordPress

## 任务目标

在真实 WordPress Site Manager 插件中实现受控媒体上传与回读：
`POST /wp-json/jaisong1n/v1/ai/media` 与 `GET /wp-json/jaisong1n/v1/ai/media/{id}`，
版本从 0.11.0 升到 0.12.0。

## 实现

- 新增 `includes/class-jg-ai-media.php`：
  - 路由复用 AI Content API 命名空间 `jaisong1n/v1/ai`；权限 = 现有
    `JG_AI_Content::permission()`（401/403 基础）+ 新 capability
    `jg_ai_upload_media`；`install()` 仅向 `jg_ai_content_editor` 角色授予
    `jg_ai_upload_media` 与 `upload_files`，不授予管理员，不扩大原生权限。
  - POST 只允许 PNG/JPEG/WebP：`wp_check_filetype_and_ext` +
    `finfo_file` 真实 MIME + `getimagesize` 宽高 + 禁用扩展名
    （php/phtml/html/svg/js/exe 等）+ `sanitize_file_name` 文件名清理；
    大小限制默认 10 MiB（`apply_filters('jg_ai_media_max_bytes')`），并受
    `wp_max_upload_size` 约束。
  - 文件经 `wp_handle_upload`（自定义 action）+ `wp_insert_attachment` +
    `wp_generate_attachment_metadata` 创建 attachment；`sourceUrl` 仅存元数据，
    服务端不下载远程图片。
  - AI 元数据：`_jg_ai_media_created/owner_user_id/sha256/idempotency_key/
    attribution/source_url/license/license_url/original_filename/uploaded_at`。
  - 去重：同 owner + 同 key + 同 SHA 复用；同 owner + 同 SHA + 异 key 复用；
    同 key + 异 SHA 返回 409；普通用户媒体不认领。GET 仅回读 AI-owned 媒体
    （owner 或管理员），普通媒体 403、缺失 404。
  - capabilities 响应新增 `media` 段（uploadMedia/readMedia），仅当调用方持有
    capability 时出现，纯增量字段。
- 主插件：`require` + `init()`/`activate()` 接入 `JG_AI_Media`；版本常量与文件头升到 0.12.0。

## 测试

- AI Media Playground（`tests/playground-ai-media.php`）51 断言通过：覆盖
  PNG/JPEG/WebP 上传、真实 media ID 与 URL、alt/caption/description/
  attribution/license 元数据、宽高、GET 回读、同 key 幂等复用、同 key 异 SHA
  409、同 SHA 异 key 复用、跨 owner 隔离、普通媒体不可读、缺失 404、SVG/PHP/
  HTML/伪装 PNG/损坏图片/超大文件拒绝、路径穿越清理、无 dispatch pending、
  内容 API 回归。
- 回归：AI Content Playground 222 断言、deployment-status 108、content-stats
  45、smoke 全部通过；`pnpm test` 74 项全通过（含插件版本 0.12.0 与媒体类静态断言）。
- 0.11.0→0.12.0 升级测试通过：媒体 capability 授予 AI 角色，角色未被扩大，
  原设置/内容/统计表保留。

## 修改文件

- `wordpress-plugin/jaisong1n-site-manager/includes/class-jg-ai-media.php`（新增）
- `wordpress-plugin/jaisong1n-site-manager/tests/playground-ai-media.php`（新增）
- `wordpress-plugin/jaisong1n-site-manager/jaisong1n-site-manager.php`、`readme.txt`
- `wordpress-plugin/jaisong1n-site-manager/includes/class-jg-ai-content.php`（ROLE 公开、capabilities media 段）
- `tests/wordpress-plugin.test.mjs`、`tests/wordpress-plugin-upgrade.php`
- `scripts/test-wordpress-plugin-upgrade.mjs`、`package.json`
- `docs/ai-content-api.md`、`docs/project/current-state.md`、`docs/project/decisions.md`、本日志

## 遇到的关键问题

- Playground CLI 偶发 `fetch failed`，重试即可。
- WP 7.0 的 `wp_handle_upload` 对默认 action 使用 `is_uploaded_file`，
  模拟上传无法通过；改用自定义 action 走 `is_readable` 分支（内容校验更严格），
  生产真实 multipart 上传与 Playground 均成立。
- `wp_check_filetype_and_ext` 需要按扩展名键控的 MIME 表。
- 测试里 `wp_handle_upload` 会移走临时文件，重放用例需用字节缓存重建临时文件；
  上传助手需显式 `wp_set_current_user`。
- WP 7.0 的 `wp_get_attachment_caption()` 只接受 attachment ID。

## 尚未完成

- 未 push/未部署生产；未执行真实 WordPress 生产上传验收；未取得真实生产
  mediaId；未创建真实带图 Article 草稿；微信端真实上传链路待 OpenClaw 对接后验收。

禁止记录凭据、Token、Authorization、Cookie、Application Password、环境变量值或私密用户数据；本日志未记录此类信息。
