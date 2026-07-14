# Home Guardian uni-app 客户端

Home Guardian 的多端客户端（Android App / 微信小程序 / H5），基于 **uni-app + Vue 3 + TypeScript + Pinia**。

> 定位与完整方案见 [`../docs/design/uniapp-client.md`](../docs/design/uniapp-client.md)。
> 现有 `../mobile`（React 版）冻结为 Web 体验版，本客户端为后续主客户端。

## 技术栈

- uni-app（Vue 3 + Vite + TS）
- Pinia（状态）、vue-i18n（首版仅中文，框架已就位）
- 自绘 canvas 折线图（遥测趋势；uCharts 可后续替换）

## 目录

```
src/
├── api/            request 封装（多服务器基址 + JWT + 401 refresh 重放）+ 各资源接口
├── stores/         server（多服务器切换）/ auth（按服务器分桶的登录态）
├── pages/          index 首页 / alerts 告警 / manage 管理 / me 我的
│                   device 详情+遥测 / auth 登录+注册 / server 列表+编辑
├── components/     PageHeader 等
├── locale/         i18n 中文文案
└── utils/          guard 守卫 / qrcode 深链解析 / format 时间与告警格式化
```

## 开发

```bash
npm install

# H5（浏览器，最快验证；默认代理 /api → http://localhost:8787）
npm run dev:h5

# Android App（需 HBuilderX 真机运行/云打包配合）
npm run dev:app

# 微信小程序（产物用微信开发者工具打开）
npm run dev:mp-weixin
```

## 构建

```bash
npm run build:h5          # 产物直接输出到 ../public/app（供 nginx /app/ 路由）
npm run build:h5:local    # 产物在 dist/build/h5（本地调试用）
npm run build:app         # App 资源，交 HBuilderX 云打包出 APK
npm run build:mp-weixin   # 小程序产物
```

### H5 部署到服务器 `/app/`

nginx 已配置 `location /app/ → /app/public/app/`（见 `docker/nginx/default.conf`）。

```bash
cd uniapp && npm install && npm run build:h5   # 产物落到 public/app/
# 生产镜像把 public 烤进镜像：重建 webman 镜像 + 重启即可
docker compose -f docker-compose.yml up -d --build
```

部署后浏览器访问 `http(s)://<服务器>/app/` 即可使用完整客户端。

> `/`（根路径）当前仍重定向到 `/mobile/`（React 体验版）。待 `/app/` 稳定后，某个大版本再把默认入口切到 `/app/`。

## 多服务器接入

自托管场景：App 可连接多台服务器，每台独立持有地址与登录态。

- **手动添加**：服务器地址形如 `http://192.168.1.10:8787`（含端口、不含 `/api`）。
- **扫码添加**：解析 `hg://server?url=<地址>&name=<名称>` 深链；后台「移动端接入」页可生成该深链。

## 打包准备（PR1 云打包）

1. 安装 [HBuilderX](https://www.dcloud.io/hbuilderx.html)
2. 注册 DCloud 账号，在 `src/manifest.json` 的 `appid` 填入 DCloud 应用 appid
3. HBuilderX 导入本目录 → 运行到手机 / 发行云打包

## 已实现

**PR1** 登录/邀请码注册、多服务器切换、首页（设备卡+快捷开关）、设备详情（动态控制）、遥测趋势、告警中心。

**PR2** 管理功能（`home_role` 为 owner/admin 可见）：
- 设备管理：列表 / 配对码添加（生成+轮询上线）/ 编辑 / 删除
- 告警规则：列表 + 全字段表单（遥测/离线、级别、条件、阈值、冷却、恢复通知、渠道）
- 通知渠道：列表 + 一键测试
- 自动化：列表 + 启停 + 删除
- 邀请家人：生成邀请码 + uQRCode 二维码（`hg://invite`，扫码直达注册）

**PR3** 告警推送（uniPush / 个推，仅 App 端）：
- 登录/启动后上报 cid，退出注销；「我的 → 推送设置」开关 + 级别阈值（提醒/警告/严重）
- 点击通知栏跳告警中心；后端 `unipush` 通知渠道按家庭 + 级别定向推送

**PR4** H5 部署（nginx `/app/`）+ i18n 中英双语（跟随系统/手选，「我的→语言」切换）+ 小程序适配。

## 多语言 (i18n)

- 中英双语，默认跟随系统，「我的 → 语言」可手动切换并持久化。
- 覆盖首屏 / 登录注册 / 服务器 / 告警 / 管理入口 / 我的 / 推送设置；管理详情表单（设备/规则/渠道/自动化编辑）后续增量补齐。
- 自定义 `messageCompiler` 做纯字符串插值，规避微信小程序禁用 eval 的限制，全端可用。

## 微信小程序

- `src/manifest.json` → `mp-weixin.appid` 填入你的小程序 AppID。
- **生产限制**：小程序正式版只能请求「HTTPS + 已备案域名」，不能用 IP / http。自托管局域网用户请以 **Android App** 为主；小程序适合有备案域名的部署。
- 开发期 `mp-weixin.setting.urlCheck` 已设为 false，用微信开发者工具勾选「不校验合法域名」即可连本地后端。

## uniPush 推送配置（PR3）

App 推送走个推（GeTui）通道，自托管用户需自备凭证：

1. HBuilderX 项目 → manifest.json → App 模块权限勾选 **Push(消息推送)**，开通 uniPush 2.0，得到 **AppID / AppKey / MasterSecret**（DCloud 开发者中心绑定个推）。
2. 后台「通知渠道」新建一个 `App 推送 (uniPush)` 渠道，config 填：
   ```json
   { "app_id": "...", "app_key": "...", "master_secret": "..." }
   ```
3. 在告警规则的「通知渠道」里勾选该渠道即可。推送只发给**已登录 App 且开启推送**的家庭成员设备，并按各自的级别阈值过滤。

> H5 / 小程序不接收 uniPush；App 端在后台/锁屏也能收到。
