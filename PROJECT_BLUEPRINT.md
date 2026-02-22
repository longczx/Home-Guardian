# Home Guardian - 项目完整蓝图与启动资源
*   **项目名称:** Home Guardian (家庭守护者)
*   **文档版本:** 1.1
*   **生成日期:** 2026年2月14日
*   **最后更新:** 2026年2月18日
*   **核心贡献者:** Longczx

---

## **第一部分：项目启动文档 (PID)**

### **1. 项目愿景与目标**

#### **1.1. 愿景**
创建一个私有的、高性能、高可扩展的家庭环境管理平台，让用户能够安全、实时地监控和控制家庭环境，并通过智能化的数据分析与告警，提升家居生活的舒适度、安全性与便捷性。

#### **1.2. 核心目标**
*   **数据采集:** 稳定、低功耗地采集来自各类传感器（温湿度、门窗、空气质量等）的数据。
*   **远程控制:** 实现对家中开关、继电器等执行器的低延迟、可靠的远程控制。
*   **数据可视化:** 提供一个直观的仪表盘（Dashboard），实时展示和历史追溯家庭环境数据。
*   **智能告警:** 建立一套灵活的告警系统，在环境数据触发预设规则时，能主动向用户发送通知。
*   **系统化管理:** 提供完善的用户权限管理和设备管理功能。

### **2. 技术架构与选型**

本项目采用一套完全容器化的、面向物联网场景的现代技术栈。

| 组件 | 技术选型 | 职责与说明 |
| :--- | :--- | :--- |
| **应用后端** | **PHP 8.2 / Webman** | **项目大脑**。基于常驻内存的 Workerman，提供高性能 REST API 服务 (:8787)、WebSocket 实时推送 (:8788)、业务逻辑处理、告警引擎和 MQTT 客户端。 |
| **反向代理** | **Nginx 1.27** | **流量入口**。统一对外暴露 80/443 端口，负责 SSL/TLS 终端、静态文件服务、按路径分发请求到 HTTP 和 WebSocket 后端。 |
| **消息中间件** | **EMQX 5.8** | **通信枢纽**。作为高性能 MQTT Broker，负责处理所有设备与服务器之间的实时、双向消息传递，支持海量设备连接。 |
| **主数据库** | **PostgreSQL + TimescaleDB** | **数据仓库**。使用 PostgreSQL 存储结构化数据（如设备、用户），并利用 TimescaleDB 扩展高效地存储和查询海量时序遥测数据。 |
| **缓存/队列** | **Redis 7** | **高速公路**。作为高性能内存数据库，用于：1. 存储热数据（设备最新状态）；2. 作为数据写入和告警处理的缓冲队列；3. Redis Pub/Sub 桥接 MQTT 数据到 WebSocket。 |
| **前端界面** | **LayUI 2.9 (Admin) + React 19 + Ant Design Mobile (Mobile)** | Admin: 服务端渲染管理面板。Mobile: 响应式 PWA 移动端，卡片式布局 + ECharts 数据可视化。 |
| **部署方案** | **Docker / Docker Compose** | **运行环境**。将所有服务容器化，实现一键部署、环境隔离和跨平台一致性，极大简化开发和运维。 |
| **版本控制** | **Git / GitHub** | **代码管理**。所有代码和文档都将在 GitHub 仓库中进行版本控制，便于协作和追踪。 |

#### **架构图**

```
┌─────────┐   MQTT    ┌──────┐          ┌──────────┐  Redis   ┌──────────────┐
│  设备    │ ───────── │ EMQX │ ───────► │ Webman   │ ──────── │ WS Worker    │
│ ESP32等  │           │      │          │ MQTT进程  │  Pub/Sub │ (:8788)      │
└─────────┘           └──────┘          └────┬─────┘          └──────┬───────┘
                                              │                       │
                                              ▼                       │
                                         ┌─────────┐                 │
                                         │ Redis   │                 │
                                         │ Queue   │                 │
                                         └────┬────┘                 │
                                              ▼                       │
                                         ┌─────────┐                 │
                                         │ PgSQL   │                 │
                                         │ 写入进程 │                 │
                                         └─────────┘                 │
                                                                      │
┌─────────┐           ┌──────────┐       ┌──────────┐               │
│  浏览器  │ ───────── │  Nginx   │ ────► │ Webman   │               │
│ Vue3 App│  HTTP/WS  │  (:80)   │  /api │ HTTP进程  │               │
└─────────┘           └──────────┘  /ws  │ (:8787)  │◄──────────────┘
                                    ────►└──────────┘
```

**三条数据通道：**
*   **REST API (`/api/*`)** — 前端 CRUD 操作、查历史数据
*   **WebSocket (`/ws`)** — 后端主动推送：实时遥测、设备上下线、告警触发
*   **MQTT** — 纯后端内部通道，设备和 Webman 之间的通信，前端不接触

### **3. 核心通信协议：MQTT 主题规范**

系统采用分层的、以设备为中心的 MQTT 主题结构，确保通信的清晰、有序和可扩展。

**通用结构:** `home/{direction}/{device_id}/{module}/{action}`

| 用途 | 主题 | 数据流向 | 发布方 | 订阅方 |
| :--- | :--- | :--- | :--- | :--- |
| **上报遥测数据** | `home/upstream/{device_id}/telemetry/post` | 设备 -> 服务器 | 设备 | Webman |
| **上报设备状态** | `home/upstream/{device_id}/state/post` | 设备 -> 服务器 | 设备 | Webman |
| **下发控制指令** | `home/downstream/{device_id}/command/set` | 服务器 -> 设备 | Webman | 设备 |
| **下发设备配置** | `home/downstream/{device_id}/config/set` | 服务器 -> 设备 | Webman | 设备 |
| **回复指令结果** | `home/upstream/{device_id}/command/reply` | 设备 -> 服务器 | 设备 | Webman |

### **4. 核心功能实现逻辑**

#### **4.1. 认证系统 (JWT 双 Token)**
采用 access_token + refresh_token 双令牌机制，兼顾性能与安全。

**Token 设计：**

| | access_token | refresh_token |
| :--- | :--- | :--- |
| **格式** | JWT（自包含） | 随机字符串 |
| **有效期** | 2 小时 | 30 天 |
| **存储** | 前端 localStorage | 前端 localStorage + 后端数据库 (存哈希) |
| **验证方式** | 纯内存解码，不查库 | 查库验证 |
| **可撤销** | 不可（过期自动失效） | 可以（删数据库记录立即失效） |

**JWT Payload 结构：**
```json
{
  "sub": 1,
  "username": "dad",
  "roles": ["admin"],
  "locations": [],
  "permissions": {"admin": true},
  "iat": 1739900000,
  "exp": 1739907200
}
```
将角色、权限、位置作用域全部编入 JWT，中间件鉴权时**完全不需要查库**。

**认证流程：**
```
1. 登录  POST /api/auth/login        → 返回 access_token + refresh_token
2. 请求  Authorization: Bearer {at}  → 中间件解码验证 (不查库)
3. 刷新  POST /api/auth/refresh       → 用 refresh_token 换新 access_token
4. 注销  POST /api/auth/logout        → 删除 refresh_token 记录
5. 全注销 POST /api/auth/logout-all   → 清空该用户所有 refresh_token
```

**WebSocket 认证：**
连接时通过 URL 参数传递 token：`ws://host/ws?token={access_token}`，在握手阶段验证。连接建立后无需重复验证，token 过期由前端主动断开重连。

#### **4.2. 设备认证 (EMQX HTTP Auth)**
通过 EMQX HTTP 认证插件回调 Webman 接口，实现设备连接鉴权和主题 ACL 控制。

**认证流程：**
```
ESP32 连接 EMQX (mqtt_username + password)
    → EMQX 调用 POST http://webman:8787/api/mqtt/auth (Docker 内部网络)
    → Webman 查 devices 表验证 bcrypt 密码
    → 200 放行 / 401 拒绝

ESP32 发布/订阅主题
    → EMQX 调用 POST http://webman:8787/api/mqtt/acl
    → Webman 验证设备是否有权操作该主题
    → 200 放行 / 403 拒绝
```

**ACL 规则：**

| 身份 | 允许 PUBLISH | 允许 SUBSCRIBE |
| :--- | :--- | :--- |
| 设备 `esp32-xxx` | `home/upstream/esp32-xxx/#` | `home/downstream/esp32-xxx/#` |
| Webman 内部客户端 (超级用户) | `home/downstream/#` | `home/upstream/#` |

每个设备只能操作自己 `device_uid` 对应的主题，防止被劫持后影响其他设备。

**Webman 内部 MQTT 客户端**以超级用户身份连接（固定凭证，通过环境变量配置），拥有所有主题的发布和订阅权限。

**EMQX 配置（Dashboard 或配置文件）：**
```
Authentication → HTTP Server
  URL:    http://webman:8787/api/mqtt/auth
  Method: POST
  Body:   {"username": "${username}", "password": "${password}"}

Authorization → HTTP Server
  URL:    http://webman:8787/api/mqtt/acl
  Method: POST
  Body:   {"username": "${username}", "topic": "${topic}", "action": "${action}"}
```

#### **4.3. 告警系统逻辑**
采用基于"流式处理"思想的三阶段实现，确保高性能与解耦。
1.  **数据接入:** Webman 的 MQTT 进程接收到遥测数据后，一份用于持久化，另一份推入 Redis 的 `alert_stream` 队列。
2.  **规则匹配:** 专门的告警引擎进程（Webman 自定义进程）从内存中加载所有告警规则，并阻塞式地从 `alert_stream` 消费数据。匹配过程在内存中高速完成，并利用 Redis 实现"持续时间"的防抖判断。
3.  **告警触发:** 确认触发后，向 `alert_logs` 记录日志，并将通知任务推入另一个 Redis 队列，由专门的通知进程负责分发。同时通过 Redis Pub/Sub 推送到 WebSocket，前端实时弹窗。

**通知渠道：**

告警规则通过 `notification_channel_ids` 关联一个或多个通知渠道，支持同时通过多种方式发送通知。

| 渠道类型 | config 示例 | 说明 |
| :--- | :--- | :--- |
| `email` | `{"smtp_host": "...", "to": ["dad@example.com"]}` | 邮件通知 |
| `webhook` | `{"url": "https://...", "method": "POST"}` | 通用 Webhook |
| `telegram` | `{"bot_token": "...", "chat_id": "..."}` | Telegram Bot |
| `wechat_work` | `{"webhook_url": "https://qyapi.weixin.qq.com/..."}` | 企业微信群机器人 |
| `dingtalk` | `{"webhook_url": "https://oapi.dingtalk.com/..."}` | 钉钉群机器人 |

通知渠道的密码类字段（如 `smtp_pass`）应使用 `smtp_pass_encrypted` 加密存储。

#### **4.4. 数据写入逻辑 (削峰填谷)**
为应对高并发数据上报，采用异步批量入库策略。
1.  MQTT 进程接收到数据后，快速推入 Redis 的 `data_ingest_queue` 队列。
2.  一个独立的 Webman 定时任务或自定义进程，每隔数秒从队列中批量拉取数据。
3.  一次性将一批数据通过 `Bulk Insert` 操作高效地写入 PostgreSQL 的 `telemetry_logs` 表。

#### **4.5. 数据保留策略 (TimescaleDB)**
利用 TimescaleDB 原生能力自动管理时序数据生命周期，无需应用层代码。

| 数据阶段 | 时间范围 | 策略 | 说明 |
| :--- | :--- | :--- | :--- |
| 原始数据 | 0-7 天 | 无压缩 | 实时查询，完整精度 |
| 压缩数据 | 7-180 天 | 自动压缩 | 节省约 90% 存储空间，仍可查询 |
| 过期数据 | >180 天 | 自动删除 | 防止存储无限增长 |
| 小时聚合 | 永久保留 | 连续聚合 (Continuous Aggregate) | Dashboard 历史图表秒级响应 |

**连续聚合 `telemetry_hourly`：**
按小时预计算每个设备每个指标的 `avg`、`min`、`max`、`count`，自动每小时刷新。前端查 30 天图表时查聚合视图而非原始表，性能提升数个数量级。

#### **4.6. 实时数据推送逻辑**
前端通过 WebSocket 获取实时数据，链路如下：
1.  Webman MQTT 进程收到设备数据后，发布到 Redis Pub/Sub 频道。
2.  Webman WebSocket Worker 进程订阅 Redis 频道，收到消息后广播给已连接的前端客户端。
3.  推送时根据用户权限过滤数据，确保用户只能看到有权限的设备数据。

#### **4.7. 权限系统 (RBAC + 位置作用域)**
采用两层权限控制，兼顾简洁与灵活。

**第一层：角色决定"能做什么"**

通过 `roles.permissions` JSONB 字段定义操作权限，格式为 `{"资源": ["操作"]}`：

| 角色 | permissions | 说明 |
| :--- | :--- | :--- |
| **admin** | `{"admin": true}` | 超级管理员，拥有全部权限 |
| **member** | `{"devices": ["view", "control"], "dashboards": ["view", "create", "edit"], "alerts": ["view", "create", "edit"], "commands": ["send"], "users": ["view"]}` | 家庭成员 |
| **guest** | `{"devices": ["view"], "dashboards": ["view"], "alerts": ["view"]}` | 访客，仅查看 |

支持的资源与操作：

| 资源 | 可用操作 | 说明 |
| :--- | :--- | :--- |
| `devices` | `view`, `control`, `create`, `edit`, `delete` | 设备查看、控制、管理 |
| `dashboards` | `view`, `create`, `edit`, `delete` | 仪表盘操作 |
| `alerts` | `view`, `create`, `edit`, `delete` | 告警规则管理 |
| `commands` | `send` | 下发设备指令 |
| `users` | `view`, `create`, `edit`, `delete` | 用户管理 |

**第二层：位置作用域决定"能看到哪些设备"**

通过 `user_allowed_locations` 表限定用户可访问的设备位置（关联 `devices.location` 字段）。该表为空表示不限制。

| 用户 | 角色 | 允许位置 | 效果 |
| :--- | :--- | :--- | :--- |
| 爸爸 | admin | _(无限制)_ | 一切操作，所有设备 |
| 妈妈 | member | _(无限制)_ | 查看+控制所有设备 |
| 孩子 | member | 儿童房 | 只能看到和控制儿童房的设备 |
| 客人 | guest | 客厅 | 只能看客厅传感器数据 |

**鉴权流程（中间件）：**
1.  **角色权限检查:** 用户的 `role.permissions` 是否包含该操作（如 `devices.control`）？
2.  **作用域检查:** 目标设备的 `location` 是否在用户的 `user_allowed_locations` 中（为空则跳过）？
3.  **WebSocket 推送过滤:** 推送实时数据时同样按位置作用域过滤，用户只收到有权限的设备数据。

#### **4.8. 前端界面方案**
采用 **双端分离** 方案：

**后台管理面板 (`/admin/*`)**
*   **技术栈:** Webman + ThinkPHP Template + LayUI 2.9 CDN
*   **认证方式:** Session 认证 (cookie_path=/admin)
*   **目标用户:** 系统管理员
*   **功能:** 设备/用户/角色/告警/自动化等全部 CRUD 管理，遥测数据查看，审计日志

**移动端前端 (`/mobile/*`)**
*   **技术栈:** React 19 + Ant Design Mobile + Zustand + ECharts + PWA
*   **认证方式:** JWT 双 Token (access 2h + refresh 30d)
*   **目标用户:** 普通家庭成员
*   **功能:** 环境概览、设备控制（开关/亮度等）、数据图表（1h/24h/7d）、告警通知列表
*   **通知保底:** 邮件 + Webhook（企业微信/钉钉/Telegram Bot）确保告警必达

#### **4.9. 场景自动化**
在告警系统基础上扩展，支持"条件触发"和"定时触发"两种方式，执行设备控制和通知推送。

**触发类型：**

| 类型 | trigger_type | trigger_config 示例 | 场景 |
| :--- | :--- | :--- | :--- |
| 遥测条件 | `telemetry` | `{"device_id": 1, "metric_key": "temperature", "condition": "GREATER_THAN", "value": 30, "duration_sec": 60}` | 温度超标自动开空调 |
| 定时计划 | `schedule` | `{"cron": "0 22 * * *", "timezone": "Asia/Shanghai"}` | 每晚 22 点关灯 |

**动作类型：**

| 类型 | actions 示例 | 说明 |
| :--- | :--- | :--- |
| 设备控制 | `{"type": "device_command", "device_id": 2, "payload": {"action": "turn_on"}}` | 向设备发送 MQTT 指令 |
| 发送通知 | `{"type": "notify", "channel_ids": [1, 3]}` | 通过通知渠道发送消息 |

一条自动化规则可以包含多个动作，按数组顺序依次执行。

**实现方式：**
*   **遥测条件触发:** 复用告警引擎的数据消费流程，从 `alert_stream` 读取数据后，同时匹配告警规则和自动化规则。
*   **定时计划触发:** 通过 Webman 的 Crontab 进程，扫描所有 `schedule` 类型的自动化规则，按 cron 表达式调度执行。

#### **4.10. 操作审计日志**
记录所有关键用户操作，用于安全审计和问题追溯。

**记录范围：**

| 操作 | action | 示例 |
| :--- | :--- | :--- |
| 登录/注销 | `login` / `logout` | 记录 IP、设备信息 |
| 设备控制 | `control` | 谁在什么时候控制了什么设备 |
| 指令下发 | `command_send` | 指令内容和目标设备 |
| 资源增删改 | `create` / `update` / `delete` | 设备、告警规则、自动化、仪表盘等 |

**实现方式：**
在 Webman 中间件或 Service 层统一写入 `audit_logs` 表，记录操作人、操作类型、目标资源、详情（JSON 格式记录变更前后差异）、IP 地址和 User-Agent。用户删除后日志保留（`ON DELETE SET NULL`）。

---

## **第二部分：数据库结构 (SQL Schema)**

这里是项目所需的全部数据表的 SQL 创建脚本，拆分为 17 个独立的迁移文件，按编号顺序执行。

### **`00_create_updated_at_function.sql`**
```sql
-- 创建通用的 updated_at 自动更新触发器函数
-- 所有包含 updated_at 字段的表都可以复用此函数
CREATE OR REPLACE FUNCTION update_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
```

### **`01_create_devices_table.sql`**
```sql
CREATE TABLE devices (
    id SERIAL PRIMARY KEY,
    device_uid VARCHAR(64) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    type VARCHAR(50) NOT NULL,
    location VARCHAR(100),
    firmware_version VARCHAR(30),
    mqtt_username VARCHAR(64) UNIQUE,
    mqtt_password_hash VARCHAR(255),
    last_seen TIMESTAMPTZ,
    is_online BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

COMMENT ON COLUMN devices.device_uid IS '用于MQTT主题和API调用的设备唯一ID';
COMMENT ON COLUMN devices.mqtt_username IS 'MQTT 连接用户名，默认可与 device_uid 相同';
COMMENT ON COLUMN devices.mqtt_password_hash IS 'MQTT 连接密码的 bcrypt 哈希';
COMMENT ON COLUMN devices.last_seen IS '由设备心跳或MQTT LWT(最后遗嘱)消息更新';

CREATE INDEX idx_devices_type ON devices(type);
CREATE INDEX idx_devices_location ON devices(location);
CREATE INDEX idx_devices_mqtt_username ON devices(mqtt_username);

CREATE TRIGGER trg_devices_updated_at
    BEFORE UPDATE ON devices
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_at();
```

### **`02_create_device_attributes_table.sql`**
```sql
CREATE TABLE device_attributes (
    id SERIAL PRIMARY KEY,
    device_id INT NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
    attribute_key VARCHAR(100) NOT NULL,
    attribute_value TEXT NOT NULL,

    UNIQUE (device_id, attribute_key)
);

COMMENT ON TABLE device_attributes IS '存储设备的静态属性，如型号、MAC地址等，提供极高扩展性';

CREATE INDEX idx_device_attributes_device_id ON device_attributes(device_id);
```

### **`03_create_telemetry_logs_table.sql`**
```sql
-- 确保 TimescaleDB 扩展已安装
CREATE EXTENSION IF NOT EXISTS timescaledb;

-- 创建遥测数据表（不使用外键，因为超表不支持）
CREATE TABLE telemetry_logs (
    ts TIMESTAMPTZ NOT NULL,
    device_id INT NOT NULL,
    metric_key VARCHAR(50) NOT NULL,
    value JSONB NOT NULL
);

COMMENT ON TABLE telemetry_logs IS 'device_id 引用 devices(id)，因超表限制不使用外键约束，由应用层保证一致性';
COMMENT ON COLUMN telemetry_logs.value IS '使用JSONB以支持数字、字符串、布尔和复杂的JSON对象';

-- 将表转换为 TimescaleDB 超表
SELECT create_hypertable('telemetry_logs', 'ts', chunk_time_interval => INTERVAL '7 days');

CREATE INDEX idx_telemetry_device_key_ts ON telemetry_logs (device_id, metric_key, ts DESC);
CREATE INDEX idx_telemetry_key_ts ON telemetry_logs (metric_key, ts DESC);
CREATE INDEX idx_telemetry_value_gin ON telemetry_logs USING GIN (value);
```

### **`04_create_command_logs_table.sql`**
```sql
CREATE TABLE command_logs (
    id BIGSERIAL PRIMARY KEY,
    request_id VARCHAR(64) UNIQUE,
    device_id INT NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
    topic VARCHAR(255) NOT NULL,
    payload JSONB,
    status VARCHAR(30) DEFAULT 'sent',
    sent_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    replied_at TIMESTAMPTZ
);

COMMENT ON COLUMN command_logs.request_id IS '由Webman生成，并由设备在响应时原样返回';
COMMENT ON COLUMN command_logs.status IS '用于追踪指令的完整生命周期: sent, delivered, replied_ok, replied_error, timeout';

CREATE INDEX idx_command_logs_device_id ON command_logs(device_id);
CREATE INDEX idx_command_logs_status ON command_logs(status);
CREATE INDEX idx_command_logs_sent_at ON command_logs(sent_at DESC);
```

### **`05_create_users_table.sql`**
```sql
CREATE TABLE users (
    id SERIAL PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    email VARCHAR(100) UNIQUE,
    full_name VARCHAR(100),
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

COMMENT ON COLUMN users.password_hash IS '绝不能明文存储密码';

CREATE TRIGGER trg_users_updated_at
    BEFORE UPDATE ON users
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_at();
```

### **`06_create_roles_table.sql`**
```sql
CREATE TABLE roles (
    id SERIAL PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    description TEXT,
    permissions JSONB
);

COMMENT ON COLUMN roles.permissions IS '权限定义，格式: {"资源": ["操作1", "操作2"]}，admin 角色使用 {"admin": true} 表示全部权限';

INSERT INTO roles (name, description, permissions) VALUES
('admin', '超级管理员', '{"admin": true}'),
('member', '家庭成员', '{"devices": ["view", "control"], "dashboards": ["view", "create", "edit"], "alerts": ["view", "create", "edit"], "commands": ["send"], "users": ["view"]}'),
('guest', '访客', '{"devices": ["view"], "dashboards": ["view"], "alerts": ["view"]}');
```

### **`07_create_user_roles_table.sql`**
```sql
CREATE TABLE user_roles (
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role_id INT NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
    PRIMARY KEY (user_id, role_id)
);
```

### **`08_create_alert_rules_table.sql`**
```sql
CREATE TABLE alert_rules (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    device_id INT NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
    telemetry_key VARCHAR(50) NOT NULL,
    condition VARCHAR(20) NOT NULL,
    threshold_value JSONB NOT NULL,
    trigger_duration_sec INT DEFAULT 0,
    notification_channel_ids JSONB NOT NULL DEFAULT '[]',
    is_enabled BOOLEAN DEFAULT TRUE,
    created_by INT REFERENCES users(id),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

COMMENT ON COLUMN alert_rules.condition IS '比较条件: GREATER_THAN, LESS_THAN, EQUALS, NOT_EQUALS';
COMMENT ON COLUMN alert_rules.trigger_duration_sec IS '条件需要持续满足多少秒才触发告警，用于防抖';
COMMENT ON COLUMN alert_rules.notification_channel_ids IS '通知渠道ID数组，引用 notification_channels.id，如 [1, 3]';
```

### **`09_create_alert_logs_table.sql`**
```sql
CREATE TABLE alert_logs (
    id BIGSERIAL PRIMARY KEY,
    rule_id INT NOT NULL REFERENCES alert_rules(id) ON DELETE CASCADE,
    device_id INT NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
    triggered_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    triggered_value JSONB NOT NULL,
    status VARCHAR(30) DEFAULT 'triggered',
    acknowledged_by INT REFERENCES users(id),
    acknowledged_at TIMESTAMPTZ
);

COMMENT ON COLUMN alert_logs.status IS '告警状态: triggered, acknowledged, resolved';

CREATE INDEX idx_alert_logs_rule_id ON alert_logs(rule_id);
CREATE INDEX idx_alert_logs_device_id ON alert_logs(device_id);
CREATE INDEX idx_alert_logs_triggered_at ON alert_logs(triggered_at DESC);
CREATE INDEX idx_alert_logs_status ON alert_logs(status);
```

### **`10_create_dashboards_table.sql`**
```sql
CREATE TABLE dashboards (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    configuration JSONB,
    owner_id INT NOT NULL REFERENCES users(id),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

COMMENT ON COLUMN dashboards.configuration IS '存储仪表盘的布局和组件配置';

CREATE TRIGGER trg_dashboards_updated_at
    BEFORE UPDATE ON dashboards
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_at();
```

### **`11_create_user_allowed_locations_table.sql`**
```sql
-- 用户位置作用域表
-- 限定用户可访问的设备位置范围，为空表示不限制（可访问所有位置）
CREATE TABLE user_allowed_locations (
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    location VARCHAR(100) NOT NULL,
    PRIMARY KEY (user_id, location)
);

COMMENT ON TABLE user_allowed_locations IS '限定用户可访问的设备位置，关联 devices.location 字段。无记录表示不限制。';

CREATE INDEX idx_user_allowed_locations_user_id ON user_allowed_locations(user_id);
```

### **`12_create_refresh_tokens_table.sql`**
```sql
CREATE TABLE refresh_tokens (
    id BIGSERIAL PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash VARCHAR(255) NOT NULL UNIQUE,
    device_info VARCHAR(255),
    expires_at TIMESTAMPTZ NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

COMMENT ON COLUMN refresh_tokens.token_hash IS '存储 refresh_token 的哈希值，不存明文';
COMMENT ON COLUMN refresh_tokens.device_info IS '登录设备信息，如 Chrome/Mac、PWA/iPhone';

CREATE INDEX idx_refresh_tokens_user_id ON refresh_tokens(user_id);
CREATE INDEX idx_refresh_tokens_expires_at ON refresh_tokens(expires_at);
```

### **`13_create_notification_channels_table.sql`**
```sql
CREATE TABLE notification_channels (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    type VARCHAR(50) NOT NULL,
    config JSONB NOT NULL,
    is_enabled BOOLEAN DEFAULT TRUE,
    created_by INT REFERENCES users(id),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

COMMENT ON COLUMN notification_channels.type IS '渠道类型: email, webhook, telegram, wechat_work, dingtalk';
COMMENT ON COLUMN notification_channels.config IS '渠道配置，格式因类型而异';

-- config JSONB 示例：
-- email:        {"smtp_host": "smtp.gmail.com", "smtp_port": 587, "smtp_user": "...", "smtp_pass_encrypted": "...", "to": ["dad@example.com"]}
-- webhook:      {"url": "https://hooks.example.com/xxx", "method": "POST", "headers": {"X-Token": "..."}}
-- telegram:     {"bot_token": "123456:ABC...", "chat_id": "-100123456"}
-- wechat_work:  {"webhook_url": "https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=xxx"}
-- dingtalk:     {"webhook_url": "https://oapi.dingtalk.com/robot/send?access_token=xxx", "secret": "SEC..."}

CREATE INDEX idx_notification_channels_type ON notification_channels(type);

CREATE TRIGGER trg_notification_channels_updated_at
    BEFORE UPDATE ON notification_channels
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_at();
```

### **`14_timescaledb_retention_policy.sql`**
```sql
-- 1. 启用压缩
ALTER TABLE telemetry_logs SET (
    timescaledb.compress,
    timescaledb.compress_segmentby = 'device_id, metric_key',
    timescaledb.compress_orderby = 'ts DESC'
);

-- 2. 自动压缩：7 天前的数据自动压缩
SELECT add_compression_policy('telemetry_logs', INTERVAL '7 days');

-- 3. 自动保留：180 天前的数据自动删除
SELECT add_retention_policy('telemetry_logs', INTERVAL '180 days');

-- 4. 按小时连续聚合（用于 Dashboard 历史图表）
CREATE MATERIALIZED VIEW telemetry_hourly
WITH (timescaledb.continuous) AS
SELECT
    time_bucket('1 hour', ts) AS bucket,
    device_id,
    metric_key,
    avg((value ->> 0)::NUMERIC)   AS avg_value,
    min((value ->> 0)::NUMERIC)   AS min_value,
    max((value ->> 0)::NUMERIC)   AS max_value,
    count(*)                       AS sample_count
FROM telemetry_logs
WHERE jsonb_typeof(value) = 'number'
   OR (jsonb_typeof(value) = 'array' AND jsonb_typeof(value -> 0) = 'number')
GROUP BY bucket, device_id, metric_key
WITH NO DATA;

-- 5. 每小时自动刷新聚合
SELECT add_continuous_aggregate_policy('telemetry_hourly',
    start_offset    => INTERVAL '3 hours',
    end_offset      => INTERVAL '1 hour',
    schedule_interval => INTERVAL '1 hour'
);

-- 6. 聚合数据 30 天后也压缩
ALTER MATERIALIZED VIEW telemetry_hourly SET (timescaledb.compress);
SELECT add_compression_policy('telemetry_hourly', INTERVAL '30 days');
```

### **`15_create_automations_table.sql`**
```sql
CREATE TABLE automations (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    trigger_type VARCHAR(20) NOT NULL,
    trigger_config JSONB NOT NULL,
    actions JSONB NOT NULL DEFAULT '[]',
    is_enabled BOOLEAN DEFAULT TRUE,
    last_triggered_at TIMESTAMPTZ,
    created_by INT REFERENCES users(id),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

COMMENT ON COLUMN automations.trigger_type IS '触发类型: telemetry (遥测条件触发), schedule (定时计划触发)';
COMMENT ON COLUMN automations.trigger_config IS '触发条件配置，格式因 trigger_type 而异';
COMMENT ON COLUMN automations.actions IS '执行动作数组: [{"type": "device_command", ...}, {"type": "notify", ...}]';

CREATE INDEX idx_automations_trigger_type ON automations(trigger_type);
CREATE INDEX idx_automations_is_enabled ON automations(is_enabled);

CREATE TRIGGER trg_automations_updated_at
    BEFORE UPDATE ON automations
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_at();
```

### **`16_create_audit_logs_table.sql`**
```sql
CREATE TABLE audit_logs (
    id BIGSERIAL PRIMARY KEY,
    user_id INT REFERENCES users(id) ON DELETE SET NULL,
    action VARCHAR(50) NOT NULL,
    resource_type VARCHAR(50) NOT NULL,
    resource_id INT,
    detail JSONB,
    ip_address VARCHAR(45),
    user_agent VARCHAR(255),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

COMMENT ON COLUMN audit_logs.action IS '操作类型: login, logout, create, update, delete, control, command_send';
COMMENT ON COLUMN audit_logs.resource_type IS '资源类型: user, device, alert_rule, automation, dashboard, notification_channel';
COMMENT ON COLUMN audit_logs.resource_id IS '被操作资源的 ID，登录/注销等操作可为 NULL';
COMMENT ON COLUMN audit_logs.detail IS '操作详情，如修改前后的差异、指令内容等';
COMMENT ON COLUMN audit_logs.ip_address IS '支持 IPv6 地址，最长 45 字符';
COMMENT ON COLUMN audit_logs.user_id IS '操作人，用户被删除后保留日志但 user_id 置 NULL';

CREATE INDEX idx_audit_logs_user_id ON audit_logs(user_id);
CREATE INDEX idx_audit_logs_action ON audit_logs(action);
CREATE INDEX idx_audit_logs_resource ON audit_logs(resource_type, resource_id);
CREATE INDEX idx_audit_logs_created_at ON audit_logs(created_at DESC);
```

---

## **第三部分：容器化与部署资源**

### **`docker-compose.yml`**
此文件用于一键启动项目所需的所有服务。所有敏感配置通过 `.env` 文件注入。
```yaml
services:
  # 1. Nginx 反向代理
  nginx:
    image: nginx:1.27-alpine
    container_name: home-guardian-nginx
    ports:
      - "${HTTP_PORT:-80}:80"
      # - "${HTTPS_PORT:-443}:443"
    volumes:
      - ./docker/nginx/default.conf:/etc/nginx/conf.d/default.conf:ro
      - ./public:/app/public:ro
      # - ./docker/nginx/ssl:/etc/nginx/ssl:ro
    depends_on:
      - webman
    networks:
      - home_guardian_net
    restart: unless-stopped

  # 2. Webman 应用服务 (PHP)
  webman:
    build:
      context: .
      dockerfile: Dockerfile
    container_name: home-guardian-app
    expose:
      - "8787"
      - "8788"
    volumes:
      - ./:/app
    depends_on:
      postgres:
        condition: service_healthy
      redis:
        condition: service_healthy
      emqx:
        condition: service_started
    networks:
      - home_guardian_net
    restart: unless-stopped

  # 3. PostgreSQL 数据库 (带 TimescaleDB 扩展)
  postgres:
    image: timescale/timescaledb:latest-pg14
    container_name: home-guardian-db
    environment:
      - POSTGRES_USER=${POSTGRES_USER:-guardian_user}
      - POSTGRES_PASSWORD=${POSTGRES_PASSWORD:?请在.env中设置POSTGRES_PASSWORD}
      - POSTGRES_DB=${POSTGRES_DB:-home_guardian_db}
    volumes:
      - pg_data:/var/lib/postgresql/data
      - ./database/migrations:/docker-entrypoint-initdb.d
    ports:
      - "${POSTGRES_PORT:-5432}:5432"
    networks:
      - home_guardian_net
    restart: unless-stopped
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U ${POSTGRES_USER:-guardian_user} -d ${POSTGRES_DB:-home_guardian_db}"]
      interval: 5s
      timeout: 5s
      retries: 5

  # 4. Redis 缓存服务
  redis:
    image: redis:7-alpine
    container_name: home-guardian-cache
    command: redis-server --requirepass ${REDIS_PASSWORD:?请在.env中设置REDIS_PASSWORD}
    ports:
      - "${REDIS_PORT:-6379}:6379"
    volumes:
      - redis_data:/data
    networks:
      - home_guardian_net
    restart: unless-stopped
    healthcheck:
      test: ["CMD", "redis-cli", "-a", "${REDIS_PASSWORD}", "ping"]
      interval: 5s
      timeout: 5s
      retries: 5

  # 5. EMQX MQTT Broker
  emqx:
    image: emqx/emqx:5.8
    container_name: home-guardian-broker
    ports:
      - "1883:1883"
      - "8083:8083"
      - "8883:8883"
      - "18083:18083"
    environment:
      - "EMQX_NAME=home_guardian_node"
      - "EMQX_HOST=emqx"
    networks:
      - home_guardian_net
    restart: unless-stopped

networks:
  home_guardian_net:
    driver: bridge

volumes:
  pg_data:
  redis_data:
```

### **`Dockerfile`**
用于构建 Webman 应用的 Docker 镜像。
```dockerfile
FROM php:8.2-cli

# 安装系统依赖和 PHP 扩展
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libssl-dev \
    libzip-dev \
    zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && docker-php-ext-install pdo pdo_pgsql pgsql zip sockets bcmath \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# 安装 Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# 设置工作目录
WORKDIR /app

# 先拷贝依赖描述文件，利用 Docker 构建缓存
COPY composer.json composer.lock ./
RUN composer install --optimize-autoloader --no-dev --no-scripts

# 再拷贝项目代码
COPY . .

# 执行 composer 后置脚本（如有）
RUN composer dump-autoload --optimize

# 暴露 Webman 默认端口
EXPOSE 8787

# 启动 Webman
CMD ["php", "start.php", "start"]
```

### **`docker/nginx/default.conf`**
Nginx 反向代理配置，按路径分发 HTTP API 和 WebSocket。
```nginx
# HTTP API
upstream webman_http {
    server webman:8787;
    keepalive 64;
}

# WebSocket
upstream webman_ws {
    server webman:8788;
    keepalive 64;
}

server {
    listen 80;
    server_name _;

    location /static/ {
        alias /app/public/static/;
        expires 7d;
        access_log off;
        add_header Cache-Control "public, immutable";
    }

    # WebSocket 实时推送
    location /ws {
        proxy_pass http://webman_ws;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
    }

    # REST API 及其他请求
    location / {
        proxy_pass http://webman_http;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_connect_timeout 10s;
        proxy_read_timeout 60s;
        proxy_send_timeout 60s;
    }
}
```

### **`.env.example`**
环境变量模板，使用前需 `cp .env.example .env` 并修改密码。
```env
# PostgreSQL
POSTGRES_USER=guardian_user
POSTGRES_PASSWORD=your_strong_db_password_here
POSTGRES_DB=home_guardian_db
POSTGRES_PORT=5432

# Redis
REDIS_PASSWORD=your_strong_redis_password_here
REDIS_PORT=6379

# Nginx
HTTP_PORT=80
# HTTPS_PORT=443

# MQTT 超级用户 (Webman 内部 MQTT 客户端使用)
MQTT_SUPER_USERNAME=hg_internal_client
MQTT_SUPER_PASSWORD=your_strong_mqtt_password_here

# JWT
JWT_SECRET=your_jwt_secret_key_here
```

### **`.gitignore`**
```gitignore
# Composer
/vendor/

# Environment
.env
.env.local
.env.*.local

# Webman runtime
/runtime/

# IDE and OS files
.idea/
.vscode/
*.swp
*~
.DS_Store
```

### **`.dockerignore`**
```
.git/
.github/
.idea/
.vscode/
vendor/
runtime/
node_modules/
.env
.env.local
.env.*.local
docker-compose.yml
PROJECT_BLUEPRINT.md
```

---

## **第四部分：GitHub 项目首页 (`README.md`)**

README.md 的内容详见仓库根目录文件。
