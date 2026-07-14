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
npm run build:h5          # 产物 dist/build/h5，部署到后端 /app/
npm run build:app         # App 资源，交 HBuilderX 云打包出 APK
npm run build:mp-weixin   # 小程序产物
```

## 多服务器接入

自托管场景：App 可连接多台服务器，每台独立持有地址与登录态。

- **手动添加**：服务器地址形如 `http://192.168.1.10:8787`（含端口、不含 `/api`）。
- **扫码添加**：解析 `hg://server?url=<地址>&name=<名称>` 深链；后台「移动端接入」页可生成该深链。

## 打包准备（PR1 云打包）

1. 安装 [HBuilderX](https://www.dcloud.io/hbuilderx.html)
2. 注册 DCloud 账号，在 `src/manifest.json` 的 `appid` 填入 DCloud 应用 appid
3. HBuilderX 导入本目录 → 运行到手机 / 发行云打包

## 本版范围（PR1）

登录/邀请码注册、多服务器切换、首页（设备卡+快捷开关）、设备详情（动态控制）、遥测趋势、告警中心。
管理功能（设备 CRUD、告警规则表单、通知渠道、自动化）与 uniPush 推送见后续 PR2/PR3。
