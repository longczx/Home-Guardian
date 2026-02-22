# Home Guardian (家庭守护者)

<p align="center">
  <strong>一个基于 Webman 构建的、高性能、可扩展的家庭环境管理平台。</strong>
  <br />
  <em>实时监控您的家，无论身在何处。</em>
</p>

<p align="center">
  <a href="#"><img src="https://img.shields.io/badge/PHP-8.2-blue.svg" alt="PHP 8.2"></a>
  <a href="#"><img src="https://img.shields.io/badge/Webman-latest-brightgreen.svg" alt="Webman"></a>
  <a href="#"><img src="https://img.shields.io/badge/MQTT-EMQX_5.8-orange.svg" alt="EMQX"></a>
  <a href="#"><img src="https://img.shields.io/badge/Database-PostgreSQL-blue.svg" alt="PostgreSQL"></a>
  <a href="#"><img src="https://img.shields.io/badge/Frontend-Vue_3-42b883.svg" alt="Vue 3"></a>
  <a href="#"><img src="https://img.shields.io/badge/UI-Naive_UI-18a058.svg" alt="Naive UI"></a>
  <a href="#"><img src="https://img.shields.io/badge/License-MIT-green.svg" alt="License MIT"></a>
</p>

---

**Home Guardian** 是一个开源的物联网 (IoT) 项目，旨在为您提供一个私有、安全且功能强大的智能家居核心。通过连接各种传感器和执行器，您可以实时监控家庭环境（如温度、湿度），并远程控制家中的设备（如灯光、开关）。

## 核心特性

*   **高性能:** 基于 [Webman](https://www.workerman.net/webman) 构建，常驻内存，提供卓越的性能和极低的延迟。
*   **实时通信:** 采用 [EMQX](https://www.emqx.io/) 作为 MQTT Broker 处理设备通信，通过 WebSocket 向前端实时推送数据。
*   **智能告警:** 高度可定制的告警引擎，当环境数据触发预设规则时（例如"温度连续5分钟高于30度"），可通过邮件、Webhook、Telegram、企业微信、钉钉等多种渠道发送通知，支持一条规则同时通知多个渠道。
*   **时序数据存储:** 使用 **PostgreSQL + TimescaleDB** 存储海量传感器数据，并提供高效的查询性能，完美支撑历史数据可视化。
*   **跨端适配:** 基于 Vue 3 响应式 SPA + PWA，PC 和移动端共用一套代码，移动端可安装到桌面使用。
*   **完全容器化:** 使用 Docker Compose 一键启动所有服务（Nginx + Webman + PostgreSQL + Redis + EMQX），确保环境一致性。
*   **灵活权限:** RBAC + 位置作用域双层控制。角色决定能做什么操作，位置作用域决定能访问哪些设备，可按房间分配家庭成员和访客的权限。

## 系统架构

```
设备 (ESP32) ──MQTT──► EMQX ──► Webman MQTT进程 ──► Redis Queue ──► PostgreSQL
                                       │
                                       ├──► Redis Pub/Sub ──► Webman WS Worker ──► 浏览器 (实时数据)
                                       └──► Alert Stream  ──► 告警引擎 ──► 通知推送

浏览器 (Vue3) ──HTTP──► Nginx:80 ──► /api/* ──► Webman HTTP :8787 (REST API)
                                 └──► /ws   ──► Webman WS   :8788 (WebSocket)
```

## 技术栈

| 组件 | 技术 | 职责 |
| :--- | :--- | :--- |
| **反向代理** | Nginx 1.27 | SSL 终端、静态文件、请求分发 |
| **应用后端** | PHP 8.2 / Webman | REST API (:8787)、WebSocket (:8788)、告警引擎 |
| **消息中间件** | EMQX 5.8 | MQTT Broker，处理设备连接与消息传递 |
| **主数据库** | PostgreSQL + TimescaleDB | 持久化存储设备信息和海量时序数据 |
| **缓存数据库** | Redis 7 | 热数据、设备状态、任务队列、Pub/Sub |
| **前端界面** | Vue 3 + Vite 6 + Naive UI + ECharts | 响应式 SPA + PWA，暗色主题仪表盘 |

## 快速开始

**前提条件:**
*   [Docker](https://www.docker.com/get-started)
*   [Docker Compose](https://docs.docker.com/compose/install/)
*   [Node.js](https://nodejs.org/) >= 18（前端开发时需要）

### 生产部署

1.  **克隆仓库:**
    ```bash
    git clone https://github.com/Longczx/home-guardian.git
    cd home-guardian
    ```

2.  **创建环境变量文件:**
    ```bash
    cp .env.example .env
    ```
    *修改 `.env` 文件中的数据库密码、Redis 密码和 JWT 密钥。*

3.  **构建前端:**
    ```bash
    cd frontend
    npm install
    npm run build    # 产物输出到 ../public/
    cd ..
    ```

4.  **启动服务:**
    ```bash
    docker compose -f docker-compose.yml up -d
    ```
    > 生产环境显式指定 `-f docker-compose.yml`，跳过 override 文件，启用 Nginx 反代。

5.  **检查服务状态:**
    *   **应用首页:** 访问 `http://localhost`
    *   **EMQX 管理后台:** 访问 `http://localhost:18083` (默认用户名: `admin`, 密码: `public`)

    > 数据库会在 PostgreSQL 容器首次启动时自动执行 `database/migrations/` 下的 SQL 迁移文件完成建表。

### 开发环境

开发时前后端分离运行，前端使用 Vite Dev Server 获得 HMR 热更新体验。

1.  **启动后端服务:**
    ```bash
    docker compose up
    ```
    > 默认会自动加载 `docker-compose.override.yml`：跳过 Nginx，将 Webman 8787/8788 端口映射到宿主机。

2.  **启动前端开发服务器（另一个终端）:**
    ```bash
    cd frontend
    npm install
    npm run dev
    ```

3.  **访问 `http://localhost:5173`**

**开发环境请求链路:**
```
浏览器 :5173
  ├── 页面/JS/CSS  →  Vite Dev Server (HMR 热更新)
  ├── /api/*       →  Vite Proxy → localhost:8787 (Webman)
  └── /ws          →  Vite Proxy → localhost:8788 (WebSocket)
```

**开发 vs 生产对比:**
| | 开发环境 | 生产环境 |
|:---|:---|:---|
| 前端 | Vite :5173（HMR） | Nginx serve 静态文件 |
| API 代理 | Vite proxy → :8787 | Nginx → webman:8787 |
| Nginx | 不启动 | 启动 |
| 启动命令 | `docker compose up` | `docker compose -f docker-compose.yml up -d` |

## 配置 EMQX 设备认证

服务启动后，需要在 EMQX 中配置 HTTP 认证，设备才能通过鉴权连接 Broker。

### 1. 配置认证 (Authentication)

打开 EMQX Dashboard `http://localhost:18083` → **Access Control** → **Authentication** → **Create**：

| 配置项 | 值 |
|:---|:---|
| Mechanism | Password-Based |
| Backend | HTTP Server |
| Method | POST |
| URL | `http://home-guardian-app:8787/api/mqtt/auth` |
| Body | `{"username": "${username}", "password": "${password}"}` |
| Headers | Content-Type: application/json |

> URL 使用 Docker 容器名 `home-guardian-app`，走内部网络，不经过 Nginx。

### 2. 配置授权 (Authorization)

**Access Control** → **Authorization** → **Create**：

| 配置项 | 值 |
|:---|:---|
| Backend | HTTP Server |
| Method | POST |
| URL | `http://home-guardian-app:8787/api/mqtt/acl` |
| Body | `{"username": "${username}", "topic": "${topic}", "action": "${action}"}` |
| Headers | Content-Type: application/json |

### 3. ACL 规则说明

| 身份 | 允许 PUBLISH | 允许 SUBSCRIBE |
|:---|:---|:---|
| 设备 `esp32-xxx` | `home/upstream/esp32-xxx/#` | `home/downstream/esp32-xxx/#` |
| Webman 内部客户端 | `home/downstream/#` | `home/upstream/#` |

每个设备只能操作自己 `device_uid` 对应的主题。Webman 内部 MQTT 客户端作为超级用户连接，凭证通过 `.env` 中的 `MQTT_SUPER_USERNAME` / `MQTT_SUPER_PASSWORD` 配置。

### 4. 设备端连接示例 (Arduino/ESP32)

```cpp
// MQTT 连接参数
const char* mqtt_server = "your-server-ip";
const int   mqtt_port   = 1883;
const char* mqtt_user   = "esp32-livingroom-01";  // 对应 devices.mqtt_username
const char* mqtt_pass   = "your-device-password";  // 明文，服务端存 bcrypt 哈希

// 连接
client.connect("esp32-livingroom-01", mqtt_user, mqtt_pass);

// 该设备只能操作以下主题：
// PUBLISH:   home/upstream/esp32-livingroom-01/telemetry/post
// SUBSCRIBE: home/downstream/esp32-livingroom-01/command/set
```

## 项目蓝图

- [x] 核心技术选型与架构设计
- [x] 数据库结构设计 (17个迁移文件)
- [x] MQTT 主题命名规范定义
- [x] 告警系统实现逻辑设计
- [x] 权限系统设计 (RBAC + 位置作用域)
- [x] 认证系统设计 (JWT 双 Token)
- [x] 设备认证设计 (EMQX HTTP Auth + ACL)
- [x] 数据保留策略 (TimescaleDB 压缩 + 自动清理 + 小时聚合)
- [x] 场景自动化设计 (条件触发 + 定时触发 → 设备控制 + 通知)
- [x] 操作审计日志 (用户操作追溯与安全审计)
- [x] Docker Compose 环境搭建 (Nginx + Webman + PgSQL + Redis + EMQX)
- [x] Web API 接口开发 (15+ REST Controller, 62+ Endpoints)
- [x] WebSocket 实时推送服务 (设备遥测 + 状态 + 告警)
- [x] 前端仪表盘界面开发 (Vue 3 + Naive UI + ECharts + PWA)
- [ ] **下一步: 设备端 (ESP32/ESP8266) 固件开发**

## 贡献

欢迎任何形式的贡献！您可以：
1.  提交 Bug Report。
2.  提出新功能建议。
3.  Fork 仓库并提交 Pull Request。

## 许可证

本项目采用 MIT License 开源。
