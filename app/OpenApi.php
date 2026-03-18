<?php
/**
 * Home Guardian - OpenAPI 全局定义
 *
 * 本文件仅存放 swagger-php 全局注解（Info、Server、SecurityScheme、通用 Schema）。
 * 运行 vendor/bin/openapi app/ 时会扫描此文件。
 */

namespace app;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    title: 'Home Guardian API',
    description: <<<'DESC'
Home Guardian 智能家居平台 RESTful API。

## 认证方式
除公开接口（登录、Token 刷新、健康检查）外，所有接口需在请求头中携带 JWT：
```
Authorization: Bearer {access_token}
```

## 响应格式
所有接口返回统一 JSON 结构：
```json
{
  "code": 0,
  "message": "ok",
  "data": { ... }
}
```
- `code = 0` 表示成功，非 0 为业务错误码
- 分页接口的 `data` 包含 `items`、`total`、`per_page`、`current_page`、`last_page`

## 权限模型
RBAC 角色权限 + 位置作用域。权限格式：`resource.action`（如 `devices.view`、`alerts.create`）。
用户可被限制只能访问特定位置的设备和数据。
DESC
)]
#[OA\Server(url: '/api', description: '当前服务器')]
#[OA\SecurityScheme(
    securityScheme: 'bearerAuth',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'JWT',
    description: '在登录接口获取 access_token，填入此处'
)]
// ---- 通用响应 Schema ----
#[OA\Schema(
    schema: 'SuccessResponse',
    required: ['code', 'message'],
    properties: [
        new OA\Property(property: 'code', type: 'integer', example: 0),
        new OA\Property(property: 'message', type: 'string', example: 'ok'),
        new OA\Property(property: 'data'),
    ]
)]
#[OA\Schema(
    schema: 'ErrorResponse',
    required: ['code', 'message'],
    properties: [
        new OA\Property(property: 'code', type: 'integer', example: 1000),
        new OA\Property(property: 'message', type: 'string', example: '参数验证失败'),
        new OA\Property(property: 'data'),
    ]
)]
#[OA\Schema(
    schema: 'PaginationMeta',
    properties: [
        new OA\Property(property: 'items', type: 'array', items: new OA\Items()),
        new OA\Property(property: 'total', type: 'integer', example: 100),
        new OA\Property(property: 'per_page', type: 'integer', example: 20),
        new OA\Property(property: 'current_page', type: 'integer', example: 1),
        new OA\Property(property: 'last_page', type: 'integer', example: 5),
    ]
)]
// ---- 通用模型 Schema ----
#[OA\Schema(
    schema: 'Device',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'device_uid', type: 'string', example: 'esp32-living-room-01'),
        new OA\Property(property: 'name', type: 'string', example: '客厅温湿度传感器'),
        new OA\Property(property: 'type', type: 'string', example: 'sensor'),
        new OA\Property(property: 'location', type: 'string', example: '客厅'),
        new OA\Property(property: 'is_online', type: 'boolean', example: true),
        new OA\Property(property: 'last_seen_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'AlertRule',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'name', type: 'string', example: '温度过高'),
        new OA\Property(property: 'device_id', type: 'integer'),
        new OA\Property(property: 'telemetry_key', type: 'string', example: 'temperature'),
        new OA\Property(property: 'condition', type: 'string', example: 'GREATER_THAN'),
        new OA\Property(property: 'threshold_value', type: 'array', items: new OA\Items(type: 'number'), example: [35]),
        new OA\Property(property: 'trigger_duration_sec', type: 'integer', example: 60),
        new OA\Property(property: 'is_enabled', type: 'boolean'),
        new OA\Property(property: 'notification_channel_ids', type: 'array', items: new OA\Items(type: 'integer')),
    ]
)]
#[OA\Schema(
    schema: 'AlertLog',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'rule_id', type: 'integer'),
        new OA\Property(property: 'device_id', type: 'integer'),
        new OA\Property(property: 'status', type: 'string', enum: ['triggered', 'acknowledged', 'resolved']),
        new OA\Property(property: 'triggered_value', type: 'number'),
        new OA\Property(property: 'message', type: 'string', nullable: true),
        new OA\Property(property: 'triggered_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'acknowledged_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'resolved_at', type: 'string', format: 'date-time', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'Automation',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'name', type: 'string', example: '温度过高开空调'),
        new OA\Property(property: 'trigger_type', type: 'string', enum: ['telemetry', 'schedule']),
        new OA\Property(property: 'trigger_config', type: 'object'),
        new OA\Property(property: 'actions', type: 'array', items: new OA\Items(type: 'object')),
        new OA\Property(property: 'is_enabled', type: 'boolean'),
    ]
)]
#[OA\Schema(
    schema: 'NotificationChannel',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'name', type: 'string', example: '管理员邮箱'),
        new OA\Property(property: 'type', type: 'string', enum: ['email', 'webhook', 'telegram', 'wechat_work', 'dingtalk']),
        new OA\Property(property: 'config', type: 'object'),
        new OA\Property(property: 'is_enabled', type: 'boolean'),
    ]
)]
#[OA\Schema(
    schema: 'User',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'username', type: 'string'),
        new OA\Property(property: 'email', type: 'string', nullable: true),
        new OA\Property(property: 'full_name', type: 'string', nullable: true),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'roles', type: 'array', items: new OA\Items(properties: [
            new OA\Property(property: 'id', type: 'integer'),
            new OA\Property(property: 'name', type: 'string'),
        ])),
    ]
)]
#[OA\Schema(
    schema: 'Role',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'name', type: 'string', example: 'admin'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'permissions', type: 'object'),
    ]
)]
#[OA\Schema(
    schema: 'Dashboard',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'configuration', type: 'object'),
        new OA\Property(property: 'owner_id', type: 'integer'),
    ]
)]
#[OA\Schema(
    schema: 'CommandLog',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'device_id', type: 'integer'),
        new OA\Property(property: 'request_id', type: 'string'),
        new OA\Property(property: 'action', type: 'string'),
        new OA\Property(property: 'params', type: 'object'),
        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'delivered', 'success', 'failed', 'timeout']),
        new OA\Property(property: 'response', type: 'object', nullable: true),
        new OA\Property(property: 'sent_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'responded_at', type: 'string', format: 'date-time', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'AuditLog',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'user_id', type: 'integer'),
        new OA\Property(property: 'action', type: 'string'),
        new OA\Property(property: 'resource_type', type: 'string'),
        new OA\Property(property: 'resource_id', type: 'integer', nullable: true),
        new OA\Property(property: 'changes', type: 'object', nullable: true),
        new OA\Property(property: 'ip_address', type: 'string'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ]
)]
class OpenApi
{
}
