# 2026-08-07 Publish 步骤 stale worktree 清理修复

## 背景

自托管 runner（ubuntu-xxtg，persistent）执行 `Publish dist to deploy branch`
时失败：`'.../_work/_temp/mizuki-deploy' is a missing but already registered
worktree`。原脚本只做 `rm -rf "${deploy_dir}"`，未清理 Git worktree
registry，导致下一次 job 复用同一路径时失败。

## 修改

文件：`.github/workflows/build-deploy.yml`，仅 `Publish dist to deploy branch`
步骤（9 行新增，1 行删除）：

- 以 `cleanup_deploy_worktree`（`git worktree remove --force` + `rm -rf` +
  `git worktree prune --expire now`）替代原 `rm -rf`。
- 创建 worktree 前调用一次清理，并注册 `trap cleanup_deploy_worktree EXIT`，
  保证成功、失败、提前 exit 时都执行清理。
- 未改动 stale deployment 检查、deploy 分支创建/更新、rsync/commit/push 逻辑。
- 未使用 `git worktree add -f` 掩盖问题。

## 验证（本地，2026-08-07）

- YAML 解析通过（Python PyYAML）。
- 步骤脚本 `bash -n` 通过。
- 功能模拟：构造“目录已删但 worktree 仍注册”状态后，cleanup 两次执行幂等，
  registry 被 prune，`git worktree add` 恢复成功，主 checkout 未被影响。
- 未在 runner 上实际重跑 job，线上行为待下次触发验证。

## 提交

- commit：`2b2e0663` `ci: clean stale deploy worktree on self-hosted runner`
- 已推送：`origin/master` `7546792e..2b2e0663`
- 本日志文件为本地未提交文件（按用户要求本次 commit 只含 workflow 文件）。
