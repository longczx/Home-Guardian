# 贡献指南 (Contributing)

感谢你对 Home Guardian 的兴趣！本文档说明如何参与开发。

## 开发环境

项目分两端：后端（PHP 8.2 + Webman）与移动端（React 19 + Vite）。

### 后端

```bash
# 1. 安装依赖
composer install

# 2. 复制环境变量并填写
cp .env.example .env   # 至少设置 POSTGRES_PASSWORD / REDIS_PASSWORD / JWT_SECRET / MQTT_SUPER_PASSWORD

# 3. 用 Docker 起完整依赖（PostgreSQL+TimescaleDB / Redis / EMQX）
docker compose up

# 4. 创建管理员
docker exec -it home-guardian-app php create_admin.php
```

后端服务：管理后台 `http://localhost:8787/admin/login`，REST API 在 `/api/*`，WebSocket 在 `:8788`。

### 移动端

```bash
cd mobile
npm install
npm run dev     # 开发服务器 http://localhost:5173/mobile/
npm run build   # 构建到 ../public/mobile/
```

## 提交前自检（与 CI 一致）

PR 会触发 [CI](.github/workflows/ci.yml)，请在本地先跑一遍同样的检查：

**后端**
```bash
# 语法检查
find app config database tests -name '*.php' -print0 | xargs -0 -n1 php -l
# 单元测试（基于 SQLite 内存库，无需外部服务）
vendor/bin/phpunit --testdox
# 或
composer test
```

**移动端**
```bash
cd mobile
npx tsc --noEmit   # 类型检查
npm run build      # 构建验证
```

## 代码规范

- **PHP**：遵循 PSR-12；控制器保持瘦逻辑，业务放 `app/service/`；数据库改动写迁移（`database/php-migrations/`），不要手改线上库。
- **TypeScript/React**：函数组件 + Hooks；API 调用集中在 `mobile/src/api/`；类型显式声明，避免 `any`。
- 缩进/换行遵循 [.editorconfig](.editorconfig)。
- 注释和文档可用中文，与现有代码风格一致。

## 数据库迁移

新增表/字段请放到 `database/php-migrations/`，命名 `YYYY_MM_DD_NNNNNN_描述.php`，并提供 `up()` 与 `down()`。超表/TimescaleDB 相关结构参考 `database/migrations/` 下的 SQL。

## 分支与提交

- 从最新的 `main` 切分支：`feat/xxx`、`fix/xxx`、`chore/xxx`、`docs/xxx`。
- 一个 PR 聚焦一件事，描述清楚动机与影响；涉及部署变更（新进程、新迁移、新环境变量）请在 PR 里写明。
- 提交信息建议用动宾短语，正文说明「为什么」。

## 报告问题

- Bug 请用 [Issue 模板](.github/ISSUE_TEMPLATE) 提交，附复现步骤、环境、日志。
- **安全漏洞请勿公开提 Issue**，按 [SECURITY.md](SECURITY.md) 私下报告。
