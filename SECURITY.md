# 安全策略 (Security Policy)

Home Guardian 是一个自托管的家庭 IoT 平台，会接入真实设备、暴露 API 与 MQTT。我们重视安全问题，感谢负责任的披露。

## 报告漏洞

**请不要通过公开 Issue 报告安全漏洞。**

请通过以下方式私下报告：

- 使用 GitHub 的 [Security Advisories](https://github.com/longczx/Home-Guardian/security/advisories/new)（推荐），或
- 发送邮件至维护者（见仓库 `git log` 中的提交者邮箱）。

报告请尽量包含：

- 受影响的组件（后端 API / 管理后台 / MQTT 鉴权 / 移动端等）与版本/提交。
- 漏洞类型与影响（越权、注入、信息泄露、RCE 等）。
- 复现步骤或 PoC。
- 你认为可行的修复建议（可选）。

我们会在确认后尽快响应，并在修复发布后与你协调披露时间。

## 自托管加固清单

部署到公网前，强烈建议：

- 修改所有默认凭证：`.env` 中的 `POSTGRES_PASSWORD` / `REDIS_PASSWORD` / `MQTT_SUPER_PASSWORD`，以及默认管理员口令（勿用 `create_admin.php` 的默认值）。
- 设置强随机 `JWT_SECRET`（未设置或为默认值时服务会拒绝启动）。
- 生产环境关闭 `APP_DEBUG`。
- 启用 HTTPS/TLS（Nginx 终止），并为管理后台 Cookie 开启 `secure` + `SameSite`。
- 限制 `/api/mqtt/*` 回调仅 EMQX 内网可达，不要直接暴露公网。
- 为登录与 MQTT 认证回调配置限流，防止暴力破解。
- 收紧 CORS，仅允许可信来源。

## 支持范围

安全修复针对 `main` 分支的最新代码。请尽量基于最新版本验证与升级。
