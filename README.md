# Home Guardian (家庭守护者)

<p align="center">
  <img src="https://raw.githubusercontent.com/Longczx/home-guardian/main/.github/assets/logo.png" alt="Home Guardian Logo" width="150"/>
</p>

<p align="center">
  <strong>一个基于 Webman 构建的、高性能、可扩展的家庭环境管理平台。</strong>
  <br />
  <em>实时监控您的家，无论身在何处。</em>
</p>

<p align="center">
  <a href="#"><img src="https://img.shields.io/badge/PHP-8.2-blue.svg" alt="PHP 8.2"></a>
  <a href="#"><img src="https://img.shields.io/badge/Webman-latest-brightgreen.svg" alt="Webman"></a>
  <a href="#"><img src="https://img.shields.io/badge/MQTT-EMQX-orange.svg" alt="EMQX"></a>
  <a href="#"><img src="https://img.shields.io/badge/Database-PostgreSQL-blue.svg" alt="PostgreSQL"></a>
  <a href="#"><img src="https://img.shields.io/badge/License-MIT-green.svg" alt="License MIT"></a>
</p>

---

**Home Guardian** 是一个开源的物联网 (IoT) 项目，旨在为您提供一个私有、安全且功能强大的智能家居核心。通过连接各种传感器和执行器，您可以实时监控家庭环境（如温度、湿度），并远程控制家中的设备（如灯光、开关）。

## ✨ 核心特性

*   **🚀 高性能:** 基于 [Webman](https://www.workerman.net/webman) 构建，常驻内存，提供卓越的性能和极低的延迟。
*   **⚡️ 实时通信:** 采用 [EMQX](https://www.emqx.io/) 作为 MQTT Broker，实现设备与服务器之间的瞬时双向通信。
*   **🧠 智能告警:** 高度可定制的告警引擎，当环境数据触发预设规则时（例如“温度连续5分钟高于30度”），可通过多种渠道向您发送通知。
*   **📈 时序数据存储:** 使用 **PostgreSQL + TimescaleDB** 存储海量传感器数据，并提供高效的查询性能，完美支撑历史数据可视化。
*   **🧩 高度可扩展:** 从通信协议 (MQTT 主题规范) 到数据库结构 (JSONB 字段)，每一处设计都为未来的功能扩展做好了准备。
*   **🐳 完全容器化:** 使用 Docker 和 Docker Compose，一键启动所有服务，确保开发与部署环境的一致性。

## 🛠️ 技术栈

| 组件 | 技术 | 职责 |
| :--- | :--- | :--- |
| **应用后端** | PHP 8.2 / Webman | 业务逻辑处理、API 服务、告警引擎 |
| **消息队列** | EMQX 5.0 | MQTT Broker，处理设备连接与消息传递 |
| **主数据库** | PostgreSQL + TimescaleDB | 持久化存储设备信息和海量时序数据 |
| **缓存数据库** | Redis | 存储热数据、最新设备状态、任务队列 |
| **前端界面** | (待定) Vue.js / React / jQuery | 数据可视化仪表盘与控制面板 |

## 🚀 快速开始

**前提条件:**
*   [Docker](https://www.docker.com/get-started)
*   [Docker Compose](https://docs.docker.com/compose/install/)

**启动步骤:**

1.  **克隆仓库:**
    ```bash
    git clone https://github.com/Longczx/home-guardian.git
    cd home-guardian
    ```

2.  **创建环境变量文件:**
    ```bash
    cp .env.example .env
    ```
    *根据需要修改 `.env` 文件中的配置，例如数据库密码。*

3.  **启动服务:**
    ```bash
    docker-compose up -d
    ```

4.  **检查服务状态:**
    *   **Webman 应用:** 访问 `http://localhost:8787`
    *   **EMQX 管理后台:** 访问 `http://localhost:18083` (默认用户名: `admin`, 密码: `public`)

5.  **初始化数据库:**
    *进入 Webman 容器并执行数据库迁移脚本。*
    ```bash
    docker-compose exec webman bash
    # 在容器内执行:
    # php webman db:migrate (假设您使用了迁移工具)
    ```

## 🗺️ 项目蓝图

- [x] 核心技术选型与架构设计
- [x] 数据库结构设计 (10个核心表)
- [x] MQTT 主题命名规范定义
- [x] 告警系统实现逻辑设计
- [x] Docker & Docker Compose 环境搭建
- [ ] **下一步: Web API 接口开发**
- [ ] **下一步: 设备端 (ESP32/ESP8266) 固件开发**
- [ ] **下一步: 前端仪表盘界面开发**

## 🤝 贡献

欢迎任何形式的贡献！您可以：
1.  提交 [Bug Report](https://github.com/Longczx/home-guardian/issues/new?template=bug_report.md)。
2.  提出 [新功能建议](https://github.com/Longczx/home-guardian/issues/new?template=feature_request.md)。
3.  Fork 仓库并提交 Pull Request。

## 📄 许可证

本项目采用 [MIT License](https://github.com/Longczx/home-guardian/blob/main/LICENSE) 开源。
