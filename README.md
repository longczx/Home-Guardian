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
  <a href="#"><img src="https://img.shields.io/badge/Admin-LayUI-red.svg" alt="LayUI"></a>
  <a href="#"><img src="https://img.shields.io/badge/Mobile-React_19-61dafb.svg" alt="React"></a>
  <a href="#"><img src="https://img.shields.io/badge/License-MIT-green.svg" alt="License MIT"></a>
</p>

---

**Home Guardian** 是一个开源的物联网 (IoT) 项目，旨在为您提供一个私有、安全且功能强大的智能家居核心。通过连接各种传感器和执行器，您可以实时监控家庭环境（如温度、湿度），并远程控制家中的设备（如灯光、开关）。

## 核心特性

*   **高性能:** 基于 [Webman](https://www.workerman.net/webman) 构建，常驻内存，提供卓越的性能和极低的延迟。
*   **实时通信:** 采用 [EMQX](https://www.emqx.io/) 作为 MQTT Broker 处理设备通信，通过 WebSocket 向前端实时推送数据。
*   **智能告警:** 高度可定制的告警引擎，当环境数据触发预设规则时，可通过邮件、Webhook、Telegram、企业微信、钉钉等多种渠道发送通知。
*   **时序数据存储:** 使用 **PostgreSQL + TimescaleDB** 存储海量传感器数据，并提供高效的查询性能。
*   **双端界面:**
    *   **后台管理面板** — Webman + LayUI 服务端渲染，session 认证，供管理员进行系统配置和设备管理。
    *   **移动端前端** — React 19 + Ant Design Mobile + PWA，JWT 认证，面向普通用户查看环境数据和控制设备。
*   **完全容器化:** 使用 Docker Compose 一键启动所有服务。
*   **灵活权限:** RBAC + 位置作用域双层控制。

## 系统架构

```
设备 (ESP32) ──MQTT──► EMQX ──► Webman MQTT进程 ──► Redis Queue ──► PostgreSQL
                                       │
                                       ├──► Redis Pub/Sub ──► Webman WS Worker ──► 移动端 (实时数据)
                                       └──► Alert Stream  ──► 告警引擎 ──► 通知推送

移动端 (React) ──HTTP──► Nginx:80 ──► /api/*    ──► Webman HTTP :8787 (REST API)
                                   ├── /ws      ──► Webman WS   :8788 (WebSocket)
                                   ├── /admin/* ──► Webman HTTP :8787 (服务端渲染)
                                   └── /mobile/ ──► 静态文件 (SPA)
```

## 技术栈

| 组件 | 技术 | 职责 |
| :--- | :--- | :--- |
| **反向代理** | Nginx 1.27 | SSL 终端、静态文件、请求分发 |
| **应用后端** | PHP 8.2 / Webman | REST API (:8787)、WebSocket (:8788)、告警引擎 |
| **消息中间件** | EMQX 5.8 | MQTT Broker，处理设备连接与消息传递 |
| **主数据库** | PostgreSQL + TimescaleDB | 持久化存储设备信息和海量时序数据 |
| **缓存数据库** | Redis 7 | 热数据、设备状态、任务队列、Pub/Sub |
| **后台管理** | LayUI 2.9 + ThinkPHP Template | 服务端渲染后台面板，session 认证 |
| **移动端** | React 19 + Ant Design Mobile + ECharts | PWA 移动端，JWT 认证 |

## 项目结构

```
Home-Guardian/
├── app/
│   ├── controller/
│   │   ├── admin/          # 后台管理控制器 (session 认证)
│   │   └── *.php           # REST API 控制器 (JWT 认证)
│   ├── middleware/          # 中间件 (Auth, CORS, Permission, AuditLog, AdminAuth)
│   ├── model/               # Eloquent 模型
│   ├── service/             # 业务逻辑服务
│   ├── process/             # 自定义进程 (MQTT, WebSocket, 告警引擎)
│   ├── exception/           # 异常处理器
│   └── view/admin/          # 后台模板 (LayUI)
├── config/                  # Webman 配置文件
├── database/migrations/     # SQL 迁移文件
├── mobile/                  # 移动端 React 项目
│   ├── src/
│   │   ├── api/             # Axios + JWT 自动刷新
│   │   ├── stores/          # Zustand 状态管理
│   │   ├── hooks/           # WebSocket Hook
│   │   ├── pages/           # 页面组件
│   │   └── components/      # 通用组件
│   ├── package.json
│   └── vite.config.ts
├── public/
│   ├── mobile/              # 移动端构建产物 (npm run build)
│   └── favicon.ico
├── docker/nginx/            # Nginx 配置
├── docker-compose.yml       # 生产环境
└── docker-compose.override.yml # 开发环境
```

## 快速开始

**前提条件:**
*   [Docker](https://www.docker.com/get-started) + [Docker Compose](https://docs.docker.com/compose/install/)
*   [Node.js](https://nodejs.org/) >= 18（移动端开发时需要）

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

3.  **构建移动端:**
    ```bash
    cd mobile
    npm install
    npm run build    # 产物输出到 ../public/mobile/
    cd ..
    ```

4.  **启动服务:**
    ```bash
    docker compose -f docker-compose.yml up -d
    ```
    > 生产环境显式指定 `-f docker-compose.yml`，跳过 override 文件，启用 Nginx 反代。

5.  **检查服务状态:**
    *   **移动端:** 访问 `http://localhost/mobile/`
    *   **后台管理:** 访问 `http://localhost/admin/login`
    *   **EMQX 管理后台:** 访问 `http://localhost:18083` (默认 `admin` / `public`)

6.  **创建管理员账号:**
    ```bash
    docker exec -it home-guardian-app php create_admin.php
    ```
    > 首次建库时会通过 `17_seed_admin_user.sql` 自动创建默认管理员 `admin / admin123`。

### 开发环境

1.  **启动后端服务:**
    ```bash
    docker compose up
    ```
    > 默认加载 `docker-compose.override.yml`：跳过 Nginx，暴露 8787/8788 端口。

2.  **启动移动端开发服务器（另一个终端）:**
    ```bash
    cd mobile
    npm install
    npm run dev
    ```

3.  **创建管理员账号（首次使用）:**
    ```bash
    docker exec -it home-guardian-app php create_admin.php
    ```

4.  **访问:**
    *   **移动端 (HMR):** `http://localhost:5173/mobile/`
    *   **后台管理:** `http://localhost:8787/admin/login`

**开发环境请求链路:**
```
移动端 :5173
  ├── 页面/JS/CSS  →  Vite Dev Server (HMR 热更新)
  ├── /api/*       →  Vite Proxy → localhost:8787 (Webman)
  └── /ws          →  Vite Proxy → localhost:8788 (WebSocket)

后台管理
  └── 直接访问 localhost:8787/admin/* (Webman 服务端渲染)
```

**开发 vs 生产对比:**
| | 开发环境 | 生产环境 |
|:---|:---|:---|
| 移动端 | Vite :5173（HMR） | Nginx serve 静态文件 |
| 后台管理 | Webman :8787 直连 | Nginx → webman:8787 |
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

### 4. 设备端连接示例 (Arduino/ESP32)

```cpp
// MQTT 连接参数
const char* mqtt_server = "your-server-ip";
const int   mqtt_port   = 1883;
const char* mqtt_user   = "esp32-livingroom-01";  // 对应 devices.mqtt_username
const char* mqtt_pass   = "your-device-password";  // 明文，服务端存 bcrypt 哈希

client.connect("esp32-livingroom-01", mqtt_user, mqtt_pass);

// 该设备只能操作以下主题：
// PUBLISH:   home/upstream/esp32-livingroom-01/telemetry/post
// SUBSCRIBE: home/downstream/esp32-livingroom-01/command/set
```

## 项目蓝图

- [x] 核心技术选型与架构设计
- [x] 数据库结构设计 (18个迁移文件)
- [x] MQTT 主题命名规范定义
- [x] 告警系统实现逻辑设计
- [x] 权限系统设计 (RBAC + 位置作用域)
- [x] 认证系统设计 (JWT 双 Token + session)
- [x] Docker Compose 环境搭建
- [x] Web API 接口开发 (15+ REST Controller, 62+ Endpoints)
- [x] WebSocket 实时推送服务
- [x] 后台管理面板 (LayUI 服务端渲染)
- [x] 移动端前端 (React 19 + Ant Design Mobile + PWA)
- [ ] **下一步: 设备端 (ESP32/ESP8266) 固件开发**

## 贡献

欢迎任何形式的贡献！您可以：
1.  提交 Bug Report。
2.  提出新功能建议。
3.  Fork 仓库并提交 Pull Request。

## 许可证

本项目采用 MIT License 开源。
