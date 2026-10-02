# 设备诊断、自动化记录与备份恢复

## 设备诊断

客户端「设备详情 → 设备诊断」显示最近联系、最近遥测、所属网关、状态上报与最近十条指令。
ESP32 网关可点击「读取网关信息」发送只读 `get_info`，回执持久化后显示固件、RSSI、运行时长、可用内存和模块数量。
子传感器的网关信息需在网关诊断页查看。未刷支持 `get_info` 的固件时会失败或超时，不生成模拟信息。
API 延续家庭、位置与设备查看权限；主动读取还需要指令权限，并限流。

## 自动化执行记录

管理页新增「自动化执行记录」，记录上线后的触发时间、规则名称、实际触发值、阈值及各动作结果。
规则删除后仍保留历史名称和触发快照；历史规则不会补造记录。
设备动作提交后等待真实回执，超时或负回执判失败。红外成功仅表示网关确认发送。
通知动作按各渠道的持久化投递记录判断结果；失效/未启用渠道不会计为成功。
一个动作失败仍执行其他动作，最终显示成功、失败或部分失败。未获得结果超过一天会结束为失败。
记录仅供家庭管理员查看，并限制位置范围；后台每十秒刷新最多一百条待完成记录。

## 备份

要求 Linux、Python 3、Docker Compose；在项目目录执行：

```bash
python3 scripts/backup.py --backup-dir /root/backups/home-guardian/managed backup --keep 14
```

每次备份会短暂停止应用、Redis 和 EMQX，保证数据库、队列和 Broker 数据在同一静止点备份；随后恢复原本运行的容器。
备份包含 PostgreSQL/TimescaleDB 完整逻辑导出、Redis 持久化文件、EMQX 数据卷、`.env`、Compose 和 Nginx/EMQX 部署配置。
代码版本保存在清单中，客户端构建可从该提交重新生成；不包含硬件 NVS 和服务器系统配置。
支持 SHA-256 校验、备份互斥锁、失败记录和保留最近十四份成功归档。文件是含凭证的未加密 ZIP，目录权限 700、归档权限 600。
不要放入 public 或提交 Git；应另外复制到独立存储，服务器磁盘故障时本机备份也会丢失。

网页「管理 → 备份与恢复」展示只读结果，不提供凭证下载或 Docker 操作权限。
仅单家庭实例的 owner 可查看实例备份状态。应用只读挂载 `runtime/backup-catalog`，不挂载归档目录或 Docker socket。

服务器 cron 示例（服务器本地时间，每天 03:15；日志目录需先创建）：

```cron
15 3 * * * cd /root/workspeace/Home-Guardian && /usr/bin/python3 scripts/backup.py --backup-dir /root/backups/home-guardian/managed backup --keep 14 >> /root/backups/home-guardian/backup.log 2>&1
```

## 恢复

恢复会替换整个实例的数据，只能由服务器管理员在维护窗口执行。
先校验归档，并切换到其清单记录的代码提交、构建应用和 H5，再执行恢复：

```bash
python3 scripts/backup.py verify /root/backups/home-guardian/managed/BACKUP_ID.zip
git checkout BACKUP_COMMIT
docker compose build webman
python3 scripts/backup.py --backup-dir /root/backups/home-guardian/managed restore /root/backups/home-guardian/managed/BACKUP_ID.zip --confirm BACKUP_ID
```

确认参数必须是完整备份编号。默认保留当前部署配置；加 `--restore-config` 可恢复归档中的配置。
配置恢复要求当前 `.env` 与归档一致，且 PostgreSQL 用户/数据库及 Redis 凭证已正确准备。
数据库和 TimescaleDB 版本应与备份一致，不在此工具中自动跨版本迁移。
恢复前自动生成一份当前数据检查点且不执行保留策略，输出其位置。
归档和数据库导出校验失败时不会改动服务；写入阶段失败时应用保持停止，须检查并用检查点重新恢复。

恢复使用 TimescaleDB 的 `timescaledb_pre_restore()` / `timescaledb_post_restore()` 流程。
恢复后撤销全部登录会话；待处理物理指令标记超时并清空发送队列，避免重放控制动作。
待投递通知标记失败并清空待提交通知队列，管理员核对后可人工重试；遥测队列保留并通过 event_id 去重。
之后检查 `/api/health`、设备连接、自动化及网页资源。配置恢复重建应用后必要时重新加载 Nginx。

恢复流程依据：[TimescaleDB 完整逻辑备份恢复](https://docs.timescale.com/self-hosted/latest/backup-and-restore/logical-backup/)。

## 验证

```bash
python3 -m unittest discover -s tests/Operations
python3 tests/Operations/restore_smoke.py
```

恢复演练创建唯一的临时 Compose 项目，验证 Timescale 遥测、Redis、会话撤销与物理指令不重放，结束后仅清理该测试项目。
