# 用户体系升级方案 —— 单家庭制（多家庭地基版）

> 状态：待评审
> 前置讨论结论：本版**不做多家庭**，但 schema 与作用域机制按多家庭设计，未来升级为"解锁"而非"重构"。家庭之间的数据**完全隔离**是未来多家庭版的硬性要求，本版通过 Global Scope 提前落地隔离机制。

## 1. 背景与目标

当前用户体系的问题：

- 无真正的资源隔离——所有设备全局可见，仅靠 location（位置）作用域过滤，本质是"一家人互相信任"模式
- REST API 侧缺少按角色的写操作权限校验（App 下放管理功能后必须补上）
- 新用户只能由管理员在 LayUI 后台手工创建，无自助注册能力

目标：

1. 引入 **Home（家庭）** 作为租户模型，本版全服只有一个默认家庭
2. 家庭内三级角色（owner / admin / member）+ API 硬校验
3. 邀请码自助注册，取代后台手工建号
4. 为未来多家庭铺平道路：所有资源挂 `home_id`，查询层强制作用域

## 2. 数据模型

### 2.1 新增表

```sql
-- 家庭（本版仅一行种子数据："我的家"）
CREATE TABLE homes (
    id          BIGSERIAL PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    created_by  BIGINT REFERENCES users(id),
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- 家庭成员关系（多对多，为未来"一人多家庭"预留）
CREATE TABLE home_users (
    id          BIGSERIAL PRIMARY KEY,
    home_id     BIGINT NOT NULL REFERENCES homes(id) ON DELETE CASCADE,
    user_id     BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role        VARCHAR(20) NOT NULL DEFAULT 'member',  -- owner | admin | member
    joined_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (home_id, user_id)
);

-- 邀请码（复用配网码的生成风格：8 位无易混淆字符 + TTL + 一次性）
CREATE TABLE invite_codes (
    id          BIGSERIAL PRIMARY KEY,
    home_id     BIGINT NOT NULL REFERENCES homes(id) ON DELETE CASCADE,
    code        VARCHAR(16) NOT NULL UNIQUE,
    role        VARCHAR(20) NOT NULL DEFAULT 'member', -- 注册后获得的角色
    created_by  BIGINT REFERENCES users(id),
    used_by     BIGINT REFERENCES users(id),           -- 为空 = 未使用
    used_at     TIMESTAMPTZ,
    expires_at  TIMESTAMPTZ NOT NULL,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);
```

### 2.2 存量表加 `home_id`

以下表增加 `home_id BIGINT NOT NULL DEFAULT 1 REFERENCES homes(id)` + 索引：

| 表 | 说明 |
|---|---|
| devices | 设备归属家庭 |
| alert_rules | 规则随设备属家 |
| alert_logs | 告警日志 |
| automations | 自动化 |
| notification_channels | 通知渠道 |
| provision_codes | 配网码生成时即绑定 home_id，设备注册自动归属 |

遥测数据（telemetry hypertable）**不加列**——通过 device 归属间接隔离，避免动 TimescaleDB 大表。

### 2.3 语义调整

- `devices.location` 语义降级为**家庭内的房间**，字段与现有数据不动
- users 表现有 `locations` 作用域保留，语义变为"家庭内可见房间限制"（member 细粒度可见性）
- 现有 roles / permissions 表**保留给 LayUI 平台后台**（平台运维视角），与家庭内角色（home_users.role）两套各管各的，互不纠缠

## 3. 作用域强制（隔离地基）

- 新增 `app/model/scope/HomeScope.php`（Eloquent Global Scope），凡带 `home_id` 的模型统一挂载
- 请求上下文（JWT 解出的 home_id）注入 Scope，查询自动追加 `WHERE home_id = ?`
- 一处定义处处生效，杜绝某个 controller 忘加过滤的隔离漏洞；后端进程（alert_engine 等）按设备自带的 home_id 处理，不受影响
- 本版所有数据 home_id = 1，Scope 形同直通，但机制已就位——未来多家庭版零改造

## 4. 家庭角色与权限矩阵

| 能力 | owner | admin | member |
|---|:-:|:-:|:-:|
| 查看设备 / 遥测 / 告警 | ✅ | ✅ | ✅（受房间可见性限制） |
| 控制执行器、确认/解决告警 | ✅ | ✅ | ✅ |
| 设备 CRUD、配网码生成 | ✅ | ✅ | ❌ |
| 告警规则 / 通知渠道 / 自动化管理 | ✅ | ✅ | ❌ |
| 生成邀请码、移除成员 | ✅ | ✅（不能动 owner/admin） | ❌ |
| 修改成员角色、转让 owner | ✅ | ❌ | ❌ |

- owner 每家庭**唯一**
- 所有写操作 API 按上表硬校验（新增 `HomeRoleMiddleware` 或在 controller 层统一断言）
- 校验失败返回 403 + 统一错误码

## 5. API 设计

### 5.1 新增

| Method | Path | 权限 | 说明 |
|---|---|---|---|
| POST | /api/invites | owner/admin | 生成邀请码（role、TTL 可选，默认 member / 72h） |
| GET | /api/invites | owner/admin | 邀请码列表（含已用/过期状态） |
| DELETE | /api/invites/{id} | owner/admin | 作废邀请码 |
| POST | /api/auth/register | 公开 | `{invite_code, username, password, nickname?, email?}` 注册并入家庭 |
| GET | /api/home | 成员 | 当前家庭信息 + 成员列表 |
| PUT | /api/home | owner | 改家庭名称 |
| PUT | /api/home/members/{userId} | owner | 修改成员角色 |
| DELETE | /api/home/members/{userId} | owner/admin | 移除成员 |

- 注册**不强制邮箱**（自托管场景），email 为可选字段
- 邀请码支持二维码呈现（内容 `hg://invite?code=XXXX&server=...`，与配网/服务器配置扫码体验一致，App 端 PR 使用）

### 5.2 存量 API 变化

- 所有资源查询自动带 home 作用域（Global Scope，无接口签名变化）
- 写操作补角色校验（之前部分仅登录即可写）
- JWT payload 增加 `home_id`、`home_role`；**bump token version 强制全员重新登录**，不做旧 token 兼容

## 6. LayUI 平台后台

- 新增「家庭成员」页：成员列表、角色调整、移除
- 新增「邀请码」页：生成（含二维码展示）、列表、作废
- 现有用户 CRUD 保留（平台运维兜底能力）

## 7. 存量数据迁移

一个 PHP migration 完成：

1. 建三张新表，种子默认家庭 `id=1「我的家」`
2. 各表加 `home_id`（DEFAULT 1，存量自动归位）
3. 现有用户全部写入 home_users：平台管理员账号 → **owner**，其余 → **member**（可事后在后台调整）
4. bump JWT token version

回滚：down() 逆序删除列与表。

## 8. 本版明确不做（留给多家庭版）

- 创建第二个家庭、家庭切换
- 公开注册、邮箱验证、防滥用限流
- **MQTT 每设备凭证 + EMQX ACL**（单家庭全是自己人不急；多家庭上线前为硬性前置——否则跨家庭可通过 MQTT 通配符偷看/伪造）
- 配额（每家庭设备数/规则数上限、遥测保留策略）

## 9. 交付物清单

1. 迁移：homes / home_users / invite_codes / 各表 home_id / 存量归位
2. 模型层：Home / HomeUser / InviteCode + HomeScope + 各模型挂载
3. 中间件/校验：JWT 载荷扩展、家庭角色断言
4. 控制器：InviteController / HomeController / 注册接口 / 存量写接口补校验
5. LayUI：家庭成员页 + 邀请码页
6. 测试：角色权限矩阵单测 + 邀请注册流程测试
7. 文档：README 用户体系章节更新

## 10. 与 uniapp 客户端的时序

**本方案先行**（单个后端 PR），uniapp PR1 直接基于新用户体系开发（登录/注册页、管理 Tab 权限、邀请家人入口一步到位，避免返工）。注册页 UI 在 uniapp PR1 提供；过渡期注册仅可通过 API 调用（当前服务器实际用户仅 owner 本人，无影响）。
