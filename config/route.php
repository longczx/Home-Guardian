<?php
/**
 * Home Guardian - API 路由定义
 *
 * 所有 API 路由都以 /api 为前缀，使用 JSON 格式通信。
 *
 * 路由分组：
 *   1. 公开接口（无需认证）— 登录、Token 刷新、健康检查、MQTT Auth 回调
 *   2. 认证接口（需要 JWT）— 所有需要登录后才能访问的 API
 *      2.1 通用接口（仅需认证）— 个人信息、注销
 *      2.2 权限接口（需要特定权限）— 设备管理、用户管理等
 *
 * 中间件执行顺序（以需要权限的接口为例）：
 *   CORS → Auth → Permission → AuditLog → Controller
 */

use Webman\Route;
use app\middleware\AuthMiddleware;
use app\middleware\PermissionMiddleware;
use app\middleware\AuditLogMiddleware;
use app\middleware\AdminAuthMiddleware;

/*
|--------------------------------------------------------------------------
| Admin 后台路由（session 认证，服务端渲染）
|--------------------------------------------------------------------------
*/
Route::group('/admin', function () {
    // 认证
    Route::get('/login', [app\controller\admin\AuthController::class, 'loginPage']);
    Route::post('/login', [app\controller\admin\AuthController::class, 'login']);
    Route::get('/logout', [app\controller\admin\AuthController::class, 'logout']);

    // Dashboard
    Route::get('/dashboard', [app\controller\admin\DashboardController::class, 'index']);
    Route::get('/', function () { return redirect('/admin/dashboard'); });

    // 设备管理
    Route::get('/devices', [app\controller\admin\DeviceController::class, 'index']);
    Route::get('/devices/create', [app\controller\admin\DeviceController::class, 'create']);
    Route::post('/devices/store', [app\controller\admin\DeviceController::class, 'store']);
    Route::get('/devices/{id:\d+}/edit', [app\controller\admin\DeviceController::class, 'edit']);
    Route::post('/devices/{id:\d+}/update', [app\controller\admin\DeviceController::class, 'update']);
    Route::post('/devices/{id:\d+}/delete', [app\controller\admin\DeviceController::class, 'delete']);

    // 用户管理
    Route::get('/users', [app\controller\admin\UserController::class, 'index']);
    Route::get('/users/create', [app\controller\admin\UserController::class, 'create']);
    Route::post('/users/store', [app\controller\admin\UserController::class, 'store']);
    Route::get('/users/{id:\d+}/edit', [app\controller\admin\UserController::class, 'edit']);
    Route::post('/users/{id:\d+}/update', [app\controller\admin\UserController::class, 'update']);
    Route::post('/users/{id:\d+}/delete', [app\controller\admin\UserController::class, 'delete']);

    // 角色管理
    Route::get('/roles', [app\controller\admin\RoleController::class, 'index']);
    Route::get('/roles/create', [app\controller\admin\RoleController::class, 'create']);
    Route::post('/roles/store', [app\controller\admin\RoleController::class, 'store']);
    Route::get('/roles/{id:\d+}/edit', [app\controller\admin\RoleController::class, 'edit']);
    Route::post('/roles/{id:\d+}/update', [app\controller\admin\RoleController::class, 'update']);
    Route::post('/roles/{id:\d+}/delete', [app\controller\admin\RoleController::class, 'delete']);

    // 告警规则
    Route::get('/alert-rules', [app\controller\admin\AlertRuleController::class, 'index']);
    Route::get('/alert-rules/create', [app\controller\admin\AlertRuleController::class, 'create']);
    Route::post('/alert-rules/store', [app\controller\admin\AlertRuleController::class, 'store']);
    Route::get('/alert-rules/{id:\d+}/edit', [app\controller\admin\AlertRuleController::class, 'edit']);
    Route::post('/alert-rules/{id:\d+}/update', [app\controller\admin\AlertRuleController::class, 'update']);
    Route::post('/alert-rules/{id:\d+}/delete', [app\controller\admin\AlertRuleController::class, 'delete']);

    // 告警日志
    Route::get('/alert-logs', [app\controller\admin\AlertLogController::class, 'index']);
    Route::post('/alert-logs/{id:\d+}/acknowledge', [app\controller\admin\AlertLogController::class, 'acknowledge']);
    Route::post('/alert-logs/{id:\d+}/resolve', [app\controller\admin\AlertLogController::class, 'resolve']);

    // 自动化
    Route::get('/automations', [app\controller\admin\AutomationController::class, 'index']);
    Route::get('/automations/create', [app\controller\admin\AutomationController::class, 'create']);
    Route::post('/automations/store', [app\controller\admin\AutomationController::class, 'store']);
    Route::get('/automations/{id:\d+}/edit', [app\controller\admin\AutomationController::class, 'edit']);
    Route::post('/automations/{id:\d+}/update', [app\controller\admin\AutomationController::class, 'update']);
    Route::post('/automations/{id:\d+}/delete', [app\controller\admin\AutomationController::class, 'delete']);

    // 通知渠道
    Route::get('/notification-channels', [app\controller\admin\NotificationChannelController::class, 'index']);
    Route::get('/notification-channels/create', [app\controller\admin\NotificationChannelController::class, 'create']);
    Route::post('/notification-channels/store', [app\controller\admin\NotificationChannelController::class, 'store']);
    Route::get('/notification-channels/{id:\d+}/edit', [app\controller\admin\NotificationChannelController::class, 'edit']);
    Route::post('/notification-channels/{id:\d+}/update', [app\controller\admin\NotificationChannelController::class, 'update']);
    Route::post('/notification-channels/{id:\d+}/delete', [app\controller\admin\NotificationChannelController::class, 'delete']);
    Route::post('/notification-channels/{id:\d+}/test', [app\controller\admin\NotificationChannelController::class, 'test']);

    // 遥测数据
    Route::get('/telemetry', [app\controller\admin\TelemetryController::class, 'index']);

    // 审计日志
    Route::get('/audit-logs', [app\controller\admin\AuditLogController::class, 'index']);

    // 系统管理
    Route::get('/system', [app\controller\admin\SystemController::class, 'index']);

    // 位置管理
    Route::get('/locations', [app\controller\admin\LocationController::class, 'index']);
    Route::post('/locations/store', [app\controller\admin\LocationController::class, 'store']);
    Route::post('/locations/{id:\d+}/delete', [app\controller\admin\LocationController::class, 'delete']);

})->middleware([AdminAuthMiddleware::class]);

/*
|--------------------------------------------------------------------------
| 健康检查（无需认证）
|--------------------------------------------------------------------------
*/
Route::get('/api/health', function () {
    return api_success([
        'status'  => 'ok',
        'time'    => date('Y-m-d H:i:s'),
        'version' => '1.0.0',
    ]);
});

/*
|--------------------------------------------------------------------------
| 认证接口（无需 JWT）
|--------------------------------------------------------------------------
*/
Route::group('/api/auth', function () {
    Route::post('/login', [app\controller\AuthController::class, 'login']);
    Route::post('/refresh', [app\controller\AuthController::class, 'refresh']);
});

/*
|--------------------------------------------------------------------------
| EMQX MQTT Auth 回调（Docker 内部网络调用，无需 JWT）
|--------------------------------------------------------------------------
*/
Route::group('/api/mqtt', function () {
    Route::post('/auth', [app\controller\MqttAuthController::class, 'auth']);
    Route::post('/acl', [app\controller\MqttAuthController::class, 'acl']);
});

/*
|--------------------------------------------------------------------------
| 需要认证的接口（JWT 中间件）
|--------------------------------------------------------------------------
*/
Route::group('/api', function () {

    /* ---------- 认证相关（仅需登录） ---------- */
    Route::get('/auth/me', [app\controller\AuthController::class, 'me']);
    Route::post('/auth/logout', [app\controller\AuthController::class, 'logout']);
    Route::post('/auth/logout-all', [app\controller\AuthController::class, 'logoutAll']);

    /* ---------- 设备管理 ---------- */
    Route::get('/devices', [app\controller\DeviceController::class, 'index'])
        ->middleware([new PermissionMiddleware('devices.view')]);
    Route::get('/devices/{id:\d+}', [app\controller\DeviceController::class, 'show'])
        ->middleware([new PermissionMiddleware('devices.view')]);
    Route::post('/devices', [app\controller\DeviceController::class, 'store'])
        ->middleware([new PermissionMiddleware('devices.create'), AuditLogMiddleware::class]);
    Route::put('/devices/{id:\d+}', [app\controller\DeviceController::class, 'update'])
        ->middleware([new PermissionMiddleware('devices.edit'), AuditLogMiddleware::class]);
    Route::delete('/devices/{id:\d+}', [app\controller\DeviceController::class, 'destroy'])
        ->middleware([new PermissionMiddleware('devices.delete'), AuditLogMiddleware::class]);
    Route::post('/devices/{id:\d+}/command', [app\controller\DeviceController::class, 'sendCommand'])
        ->middleware([new PermissionMiddleware('commands.send')]);

    /* ---------- 设备属性 ---------- */
    Route::get('/devices/{deviceId:\d+}/attributes', [app\controller\DeviceAttributeController::class, 'index'])
        ->middleware([new PermissionMiddleware('devices.view')]);
    Route::put('/devices/{deviceId:\d+}/attributes', [app\controller\DeviceAttributeController::class, 'batchSet'])
        ->middleware([new PermissionMiddleware('devices.edit')]);
    Route::delete('/devices/{deviceId:\d+}/attributes/{key}', [app\controller\DeviceAttributeController::class, 'destroy'])
        ->middleware([new PermissionMiddleware('devices.edit')]);

    /* ---------- 遥测数据（只读） ---------- */
    Route::get('/telemetry', [app\controller\TelemetryController::class, 'index'])
        ->middleware([new PermissionMiddleware('devices.view')]);
    Route::get('/telemetry/latest', [app\controller\TelemetryController::class, 'latest'])
        ->middleware([new PermissionMiddleware('devices.view')]);
    Route::get('/telemetry/aggregated', [app\controller\TelemetryController::class, 'aggregated'])
        ->middleware([new PermissionMiddleware('devices.view')]);

    /* ---------- 指令日志（只读） ---------- */
    Route::get('/commands', [app\controller\CommandLogController::class, 'index'])
        ->middleware([new PermissionMiddleware('commands.send')]);
    Route::get('/commands/{id:\d+}', [app\controller\CommandLogController::class, 'show'])
        ->middleware([new PermissionMiddleware('commands.send')]);

    /* ---------- 用户管理 ---------- */
    Route::get('/users', [app\controller\UserController::class, 'index'])
        ->middleware([new PermissionMiddleware('users.view')]);
    Route::get('/users/{id:\d+}', [app\controller\UserController::class, 'show'])
        ->middleware([new PermissionMiddleware('users.view')]);
    Route::post('/users', [app\controller\UserController::class, 'store'])
        ->middleware([new PermissionMiddleware('users.create'), AuditLogMiddleware::class]);
    Route::put('/users/{id:\d+}', [app\controller\UserController::class, 'update'])
        ->middleware([new PermissionMiddleware('users.edit'), AuditLogMiddleware::class]);
    Route::delete('/users/{id:\d+}', [app\controller\UserController::class, 'destroy'])
        ->middleware([new PermissionMiddleware('users.delete'), AuditLogMiddleware::class]);

    /* ---------- 角色管理（仅 admin） ---------- */
    Route::get('/roles', [app\controller\RoleController::class, 'index']);
    Route::get('/roles/{id:\d+}', [app\controller\RoleController::class, 'show']);
    Route::post('/roles', [app\controller\RoleController::class, 'store'])
        ->middleware([new PermissionMiddleware('users.create'), AuditLogMiddleware::class]);
    Route::put('/roles/{id:\d+}', [app\controller\RoleController::class, 'update'])
        ->middleware([new PermissionMiddleware('users.edit'), AuditLogMiddleware::class]);
    Route::delete('/roles/{id:\d+}', [app\controller\RoleController::class, 'destroy'])
        ->middleware([new PermissionMiddleware('users.delete'), AuditLogMiddleware::class]);

    /* ---------- 告警规则 ---------- */
    Route::get('/alert-rules', [app\controller\AlertRuleController::class, 'index'])
        ->middleware([new PermissionMiddleware('alerts.view')]);
    Route::get('/alert-rules/{id:\d+}', [app\controller\AlertRuleController::class, 'show'])
        ->middleware([new PermissionMiddleware('alerts.view')]);
    Route::post('/alert-rules', [app\controller\AlertRuleController::class, 'store'])
        ->middleware([new PermissionMiddleware('alerts.create'), AuditLogMiddleware::class]);
    Route::put('/alert-rules/{id:\d+}', [app\controller\AlertRuleController::class, 'update'])
        ->middleware([new PermissionMiddleware('alerts.edit'), AuditLogMiddleware::class]);
    Route::delete('/alert-rules/{id:\d+}', [app\controller\AlertRuleController::class, 'destroy'])
        ->middleware([new PermissionMiddleware('alerts.delete'), AuditLogMiddleware::class]);

    /* ---------- 告警日志 ---------- */
    Route::get('/alert-logs', [app\controller\AlertLogController::class, 'index'])
        ->middleware([new PermissionMiddleware('alerts.view')]);
    Route::get('/alert-logs/{id:\d+}', [app\controller\AlertLogController::class, 'show'])
        ->middleware([new PermissionMiddleware('alerts.view')]);
    Route::patch('/alert-logs/{id:\d+}/acknowledge', [app\controller\AlertLogController::class, 'acknowledge'])
        ->middleware([new PermissionMiddleware('alerts.edit')]);
    Route::patch('/alert-logs/{id:\d+}/resolve', [app\controller\AlertLogController::class, 'resolve'])
        ->middleware([new PermissionMiddleware('alerts.edit')]);

    /* ---------- 仪表盘 ---------- */
    Route::get('/dashboards', [app\controller\DashboardController::class, 'index'])
        ->middleware([new PermissionMiddleware('dashboards.view')]);
    Route::get('/dashboards/{id:\d+}', [app\controller\DashboardController::class, 'show'])
        ->middleware([new PermissionMiddleware('dashboards.view')]);
    Route::post('/dashboards', [app\controller\DashboardController::class, 'store'])
        ->middleware([new PermissionMiddleware('dashboards.create'), AuditLogMiddleware::class]);
    Route::put('/dashboards/{id:\d+}', [app\controller\DashboardController::class, 'update'])
        ->middleware([new PermissionMiddleware('dashboards.edit'), AuditLogMiddleware::class]);
    Route::delete('/dashboards/{id:\d+}', [app\controller\DashboardController::class, 'destroy'])
        ->middleware([new PermissionMiddleware('dashboards.delete'), AuditLogMiddleware::class]);

    /* ---------- 通知渠道 ---------- */
    Route::get('/notification-channels', [app\controller\NotificationChannelController::class, 'index'])
        ->middleware([new PermissionMiddleware('alerts.view')]);
    Route::get('/notification-channels/{id:\d+}', [app\controller\NotificationChannelController::class, 'show'])
        ->middleware([new PermissionMiddleware('alerts.view')]);
    Route::post('/notification-channels', [app\controller\NotificationChannelController::class, 'store'])
        ->middleware([new PermissionMiddleware('alerts.create'), AuditLogMiddleware::class]);
    Route::put('/notification-channels/{id:\d+}', [app\controller\NotificationChannelController::class, 'update'])
        ->middleware([new PermissionMiddleware('alerts.edit'), AuditLogMiddleware::class]);
    Route::delete('/notification-channels/{id:\d+}', [app\controller\NotificationChannelController::class, 'destroy'])
        ->middleware([new PermissionMiddleware('alerts.delete'), AuditLogMiddleware::class]);
    Route::post('/notification-channels/{id:\d+}/test', [app\controller\NotificationChannelController::class, 'test'])
        ->middleware([new PermissionMiddleware('alerts.edit')]);

    /* ---------- 自动化 ---------- */
    Route::get('/automations', [app\controller\AutomationController::class, 'index'])
        ->middleware([new PermissionMiddleware('alerts.view')]);
    Route::get('/automations/{id:\d+}', [app\controller\AutomationController::class, 'show'])
        ->middleware([new PermissionMiddleware('alerts.view')]);
    Route::post('/automations', [app\controller\AutomationController::class, 'store'])
        ->middleware([new PermissionMiddleware('alerts.create'), AuditLogMiddleware::class]);
    Route::put('/automations/{id:\d+}', [app\controller\AutomationController::class, 'update'])
        ->middleware([new PermissionMiddleware('alerts.edit'), AuditLogMiddleware::class]);
    Route::delete('/automations/{id:\d+}', [app\controller\AutomationController::class, 'destroy'])
        ->middleware([new PermissionMiddleware('alerts.delete'), AuditLogMiddleware::class]);

    /* ---------- 审计日志（只读） ---------- */
    Route::get('/audit-logs', [app\controller\AuditLogController::class, 'index']);

})->middleware([AuthMiddleware::class]);

/*
|--------------------------------------------------------------------------
| 404 兜底路由
|--------------------------------------------------------------------------
*/
Route::fallback(function (\support\Request $request) {
    $path = $request->path();
    if (str_starts_with($path, '/admin')) {
        return redirect('/admin/dashboard');
    }
    return api_error('接口不存在', 404, 404);
});

// 禁用默认路由（所有路由必须显式定义）
Route::disableDefaultRoute();
