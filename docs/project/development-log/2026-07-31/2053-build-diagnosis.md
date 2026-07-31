# pnpm build WordPress 同步超时诊断

- 时间：2026-07-31 20:53（Asia/Shanghai）
- 会话或模块：Astro 构建、WordPress 同步、结构化内容媒体镜像
- 当前分支：`codex/ai-content-api-0-7`
- 工作目录：`D:\Blog\JaisonG1n-Blog`
- 状态：部分完成（已定位失败边界，未修改实现）
- 是否已提交：否（本日志为本地未提交）
- 是否已部署：否

## 任务目标

根据构建日志定位 `pnpm build` 失败原因，区分内容同步、结构化内容同步、媒体请求和 Astro 构建阶段；不访问真实 WordPress，不修改实现代码。

## 实际完成

- 读取 `package.json`、`scripts/sync-content.js`、`scripts/sync-wordpress.mjs`、`scripts/wordpress-sync/contracts.mjs`、`scripts/wordpress-sync/media.mjs` 和 GitHub Actions 构建配置。
- 确认 `pnpm build` 的实际链路是 `pnpm sync-wordpress && astro build && pagefind --site dist`；`prebuild` 中的 `sync-content.js || true` 只忽略内容仓库同步失败，不能忽略 WordPress 同步失败。
- 确认文章接口请求成功后，脚本先生成文章文件，再进入结构化快照和媒体镜像阶段。
- 确认媒体镜像使用 Undici `headersTimeout` 15 秒；原始错误文本为 `Headers Timeout Error`，与该边界一致。
- 确认结构化同步开启且失败、未使用 `--allow-stale` 时，事务会保持未提交并让构建失败。

## 修改文件

- 实现代码：无。
- 配置和工作流：无。
- 新增本任务日志：`docs/project/development-log/2026-07-31/2053-build-diagnosis.md`。
- 未修改 `current-state.md`、`decisions.md` 或其他会话日志。

## 测试与验证

- 命令：`pnpm exec node --test tests/sync-wordpress.test.mjs tests/wordpress-structured-content.test.mjs`
  - 实际结果：30 个测试通过，0 个失败，0 个取消，运行约 1.64 秒。
  - 覆盖结果：结构化同步关闭时失败降级、结构化同步开启时失败策略、`--allow-stale` 保留完整旧快照、事务回滚和媒体超时参数均有本地测试覆盖。
  - 是否通过：是；这是本地 fixture/mock 测试，不是生产 API 验证。

- 命令：读取 `package.json`、同步脚本、同步契约、媒体脚本和 `.github/workflows/build-deploy.yml`。
  - 实际结果：确认构建脚本、环境变量传递、超时设置和错误传播路径。
  - 是否通过：静态诊断完成。

- 命令：`git diff --check`。
  - 实际结果：无空白错误。
  - 是否通过：是。

## 构建日志中的失败路径

用户提供的日志显示：内容仓库不存在但被允许使用本地模式；随后 WordPress 第 1 页请求成功，两个文章文件生成，之后出现 `Headers Timeout Error`，事务未提交，`pnpm build` 以退出码 1 结束。

结合代码，最符合该错误文本的路径是结构化内容处理中的媒体请求在 15 秒内没有收到响应头。结构化同步开启时，该错误会从 `generateStructured` 传播到 `syncWordPress`，因此不会提交同步事务，Astro 尚未开始执行。

日志本身没有显示 `WORDPRESS_STRUCTURED_CONTENT_ENABLED` 的实际值，因此“结构化同步开启”是由错误位置和代码分支推断的，不能当作环境变量值的独立证据。GitHub Actions 工作流允许仓库变量覆盖默认关闭值，应检查该变量配置以及具体慢请求的媒体资源，但本会话没有连接生产站点进行检查。

## 真实环境验证结果

- 当前会话没有访问真实 WordPress，也没有重跑用户提供的生产构建。
- 真实失败现象仅来自用户提供的构建日志。
- 无法从本地证实哪个具体媒体 URL、快照记录或上游节点触发了响应头超时。

## 遇到的问题

- `pnpm build` 把 WordPress 同步作为 Astro 构建前的硬依赖，结构化同步的超时会阻止后续静态构建。
- `sync-wordpress:dev` 支持 `--allow-stale`，但 `build` 脚本固定调用不带该参数的 `sync-wordpress`。
- 当前错误信息没有包含安全且可定位的请求阶段或媒体路径，无法仅凭日志区分快照请求与具体媒体请求；原始错误文本更偏向媒体镜像的 Undici 超时。

## 解决过程

1. 沿 `pnpm build` 脚本追踪到 `sync-wordpress`，排除 `prebuild` 的内容仓库警告是直接失败原因。
2. 对照 `syncWordPress` 的事务和结构化失败分支，确认文章生成成功不代表同步事务已提交。
3. 对照 `MediaMirror` 的 Undici 参数，确认响应头超时为 15 秒，与错误文本相符。
4. 运行本地同步测试，确认现有代码的关闭、开启和 stale fallback 行为与上述判断一致。

## 关键决定

- 本次只做诊断，不增加重试、延长超时、改变构建默认值或修改工作流。
- 不通过重新调用生产接口来“验证”诊断，不读取或输出任何凭据或环境变量值。
- 若要修复，应先决定生产策略：结构化内容是严格阻断构建，还是允许完整旧快照兜底；之后再为超时边界增加针对性重试/诊断和回归测试。

## 尚未完成

- 未确认 GitHub Actions 实际解析到的结构化同步开关值。
- 未定位具体超时的媒体 URL 或 WordPress 上游响应时间。
- 未修改代码，因此未提供生产修复，也未重新运行完整构建。

## 下一步

- 在 CI 日志或安全配置检查中确认结构化同步开关，不输出环境变量值。
- 使用本地 mock/fixture 重现“媒体响应头超时”并补充阶段级错误信息测试。
- 在明确产品策略后，再评估 `--allow-stale`、有限重试或超时调整，并运行完整构建验证。

## 资料来源

### 仓库可确认

- `package.json`
- `scripts/sync-wordpress.mjs`
- `scripts/wordpress-sync/contracts.mjs`
- `scripts/wordpress-sync/media.mjs`
- `.github/workflows/build-deploy.yml`
- `tests/sync-wordpress.test.mjs`
- `tests/wordpress-structured-content.test.mjs`

### 测试输出可确认

- 本会话运行的两个本地同步测试：30 passed、0 failed。
- 本会话运行的 `git diff --check`：通过。

### 用户提供

- `pnpm build` 失败日志及其中的 `Headers Timeout Error`、事务未提交和退出码 1。

### 当前无法确认

- 生产环境的具体超时请求、结构化同步开关实际值和上游服务当前健康状态。
