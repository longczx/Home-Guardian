<?php
/**
 * Home Guardian - 设备控制器
 *
 * 设备的 CRUD API，所有操作都受 RBAC 权限和位置作用域控制。
 *
 *   GET    /api/devices          — 设备列表（支持筛选和分页）
 *   GET    /api/devices/{id}     — 设备详情
 *   POST   /api/devices          — 创建设备
 *   PUT    /api/devices/{id}     — 更新设备
 *   DELETE /api/devices/{id}     — 删除设备
 *   POST   /api/devices/{id}/command — 向设备发送控制指令
 */

namespace app\controller;

use app\model\Device;
use app\service\DeviceService;
use app\service\MqttCommandService;
use app\service\AuditService;
use support\Request;

class DeviceController
{
    /**
     * 设备列表
     *
     * GET /api/devices?type=sensor&location=客厅&is_online=1&page=1&per_page=20
     *
     * 自动按用户的位置作用域过滤。
     */
    public function index(Request $request)
    {
        $query = Device::query();

        // 位置作用域过滤（非 admin 用户只能看到允许位置的设备）
        $locations = $request->user->locations ?? [];
        $query->inLocations($locations);

        // 可选筛选条件
        if ($type = $request->get('type')) {
            $query->ofType($type);
        }
        if ($location = $request->get('location')) {
            $query->atLocation($location);
        }
        if ($request->get('is_online') !== null) {
            $request->get('is_online') ? $query->online() : $query->where('is_online', false);
        }
        if ($keyword = $request->get('keyword')) {
            $query->where(function ($q) use ($keyword) {
                $q->where('name', 'ILIKE', "%{$keyword}%")
                  ->orWhere('device_uid', 'ILIKE', "%{$keyword}%");
            });
        }

        // 排序和分页
        $perPage = min((int)($request->get('per_page', 20)), 100);
        $paginator = $query->orderBy('id', 'desc')->paginate($perPage);

        return api_paginate($paginator);
    }

    /**
     * 设备详情
     *
     * GET /api/devices/{id}
     *
     * 包含设备的扩展属性。
     */
    public function show(Request $request, int $id)
    {
        $device = Device::with('attributes')->find($id);

        if (!$device) {
            return api_error('设备不存在', 404, 2001);
        }

        // 位置作用域检查
        if (!$request->canAccessLocation($device->location)) {
            return api_error('无权访问该设备', 403, 1004);
        }

        return api_success($device);
    }

    /**
     * 创建设备
     *
     * POST /api/devices
     * Body: {
     *   "device_uid": "esp32-living-room-01",
     *   "name": "客厅温湿度传感器",
     *   "type": "sensor",
     *   "location": "客厅",
     *   "mqtt_password": "device_password"
     * }
     */
    public function store(Request $request)
    {
        // 参数校验
        $data = $this->validateDeviceData($request);
        if ($data instanceof \support\Response) {
            return $data; // 返回错误响应
        }

        $device = DeviceService::create($data);

        AuditService::log($request, 'create', 'device', $device->id, [
            'device_uid' => $device->device_uid,
            'name'       => $device->name,
        ]);

        return api_success($device, '设备创建成功', 201);
    }

    /**
     * 更新设备
     *
     * PUT /api/devices/{id}
     */
    public function update(Request $request, int $id)
    {
        $device = Device::find($id);
        if (!$device) {
            return api_error('设备不存在', 404, 2001);
        }

        // 位置作用域检查
        if (!$request->canAccessLocation($device->location)) {
            return api_error('无权操作该设备', 403, 1004);
        }

        $original = $device->toArray();

        $data = $request->post();
        // 不允许修改 device_uid（设备唯一标识不可变）
        unset($data['device_uid']);

        $device = DeviceService::update($id, $data);

        AuditService::log($request, 'update', 'device', $id,
            AuditService::diffChanges($original, $device->toArray())
        );

        return api_success($device, '设备更新成功');
    }

    /**
     * 删除设备
     *
     * DELETE /api/devices/{id}
     */
    public function destroy(Request $request, int $id)
    {
        $device = Device::find($id);
        if (!$device) {
            return api_error('设备不存在', 404, 2001);
        }

        if (!$request->canAccessLocation($device->location)) {
            return api_error('无权操作该设备', 403, 1004);
        }

        DeviceService::delete($id);

        AuditService::log($request, 'delete', 'device', $id, [
            'device_uid' => $device->device_uid,
            'name'       => $device->name,
        ]);

        return api_success(null, '设备已删除');
    }

    /**
     * 向设备发送控制指令
     *
     * POST /api/devices/{id}/command
     * Body: { "action": "turn_on", "params": {} }
     */
    public function sendCommand(Request $request, int $id)
    {
        $device = Device::find($id);
        if (!$device) {
            return api_error('设备不存在', 404, 2001);
        }

        if (!$request->canAccessLocation($device->location)) {
            return api_error('无权控制该设备', 403, 1004);
        }

        $payload = $request->post();
        if (empty($payload)) {
            return api_error('指令内容不能为空', 422, 1000);
        }

        $commandLog = MqttCommandService::sendCommand($id, $payload);

        AuditService::log($request, 'command_send', 'device', $id, [
            'request_id' => $commandLog->request_id,
            'payload'    => $payload,
        ]);

        return api_success([
            'request_id' => $commandLog->request_id,
            'status'     => $commandLog->status,
        ], '指令已发送');
    }

    /**
     * 验证设备数据
     *
     * @param  Request $request
     * @return array|\support\Response 验证通过返回数组，失败返回错误响应
     */
    private function validateDeviceData(Request $request): array|\support\Response
    {
        $data = $request->post();

        $errors = [];
        if (empty($data['device_uid'])) {
            $errors[] = 'device_uid 不能为空';
        }
        if (empty($data['name'])) {
            $errors[] = 'name 不能为空';
        }
        if (empty($data['type'])) {
            $errors[] = 'type 不能为空';
        }

        if (!empty($errors)) {
            return api_error('参数验证失败', 422, 1000, $errors);
        }

        return $data;
    }
}
