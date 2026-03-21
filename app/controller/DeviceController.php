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
use OpenApi\Attributes as OA;

#[OA\Tag(name: '设备管理', description: '设备的 CRUD 和控制指令')]
class DeviceController
{
    /**
     * 设备列表
     *
     * GET /api/devices?type=sensor&location=客厅&is_online=1&page=1&per_page=20
     *
     * 自动按用户的位置作用域过滤。
     */
    #[OA\Get(
        path: '/devices',
        summary: '设备列表',
        description: '获取设备列表，支持按类型、位置、在线状态、关键词筛选，自动按用户位置作用域过滤。',
        security: [['bearerAuth' => []]],
        tags: ['设备管理'],
    )]
    #[OA\Parameter(name: 'type', in: 'query', description: '设备类型（sensor/switch/camera 等）', required: false, schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'location', in: 'query', description: '位置筛选', required: false, schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'is_online', in: 'query', description: '在线状态（1=在线, 0=离线）', required: false, schema: new OA\Schema(type: 'integer', enum: [0, 1]))]
    #[OA\Parameter(name: 'keyword', in: 'query', description: '搜索关键词（匹配名称或 UID）', required: false, schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1))]
    #[OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20, maximum: 100))]
    #[OA\Response(response: 200, description: '成功', content: new OA\JsonContent(ref: '#/components/schemas/PaginationMeta'))]
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
    #[OA\Get(
        path: '/devices/{id}',
        summary: '设备详情',
        description: '获取指定设备的详细信息，包含扩展属性。',
        security: [['bearerAuth' => []]],
        tags: ['设备管理'],
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: '成功', content: new OA\JsonContent(
        properties: [
            new OA\Property(property: 'code', type: 'integer', example: 0),
            new OA\Property(property: 'data', ref: '#/components/schemas/Device'),
        ]
    ))]
    #[OA\Response(response: 404, description: '设备不存在', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'))]
    #[OA\Response(response: 403, description: '无权访问', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'))]
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
    #[OA\Post(
        path: '/devices',
        summary: '创建设备',
        description: '注册一台新设备，需提供唯一的 device_uid 和 MQTT 密码。',
        security: [['bearerAuth' => []]],
        tags: ['设备管理'],
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['device_uid', 'name', 'type'],
            properties: [
                new OA\Property(property: 'device_uid', type: 'string', example: 'esp32-living-room-01', description: '设备唯一标识（创建后不可修改）'),
                new OA\Property(property: 'name', type: 'string', example: '客厅温湿度传感器'),
                new OA\Property(property: 'type', type: 'string', example: 'sensor'),
                new OA\Property(property: 'location', type: 'string', example: '客厅'),
                new OA\Property(property: 'mqtt_password', type: 'string', example: 'device_password', description: '设备 MQTT 连接密码'),
            ]
        )
    )]
    #[OA\Response(response: 201, description: '创建成功', content: new OA\JsonContent(
        properties: [
            new OA\Property(property: 'code', type: 'integer', example: 0),
            new OA\Property(property: 'message', type: 'string', example: '设备创建成功'),
            new OA\Property(property: 'data', ref: '#/components/schemas/Device'),
        ]
    ))]
    #[OA\Response(response: 422, description: '参数验证失败', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'))]
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
    #[OA\Put(
        path: '/devices/{id}',
        summary: '更新设备',
        description: '更新设备信息。device_uid 不可修改。',
        security: [['bearerAuth' => []]],
        tags: ['设备管理'],
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\RequestBody(
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'name', type: 'string'),
                new OA\Property(property: 'type', type: 'string'),
                new OA\Property(property: 'location', type: 'string'),
                new OA\Property(property: 'mqtt_password', type: 'string', description: '不传则不修改'),
            ]
        )
    )]
    #[OA\Response(response: 200, description: '更新成功', content: new OA\JsonContent(
        properties: [
            new OA\Property(property: 'code', type: 'integer', example: 0),
            new OA\Property(property: 'data', ref: '#/components/schemas/Device'),
        ]
    ))]
    #[OA\Response(response: 404, description: '设备不存在', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'))]
    #[OA\Response(response: 403, description: '无权操作', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'))]
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
    #[OA\Delete(
        path: '/devices/{id}',
        summary: '删除设备',
        description: '删除指定设备及其关联数据。',
        security: [['bearerAuth' => []]],
        tags: ['设备管理'],
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: '删除成功', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    #[OA\Response(response: 404, description: '设备不存在', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'))]
    #[OA\Response(response: 403, description: '无权操作', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'))]
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
    #[OA\Post(
        path: '/devices/{id}/command',
        summary: '发送控制指令',
        description: '通过 MQTT 向设备发送控制指令。指令内容由设备固件定义。',
        security: [['bearerAuth' => []]],
        tags: ['设备管理'],
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['action'],
            properties: [
                new OA\Property(property: 'action', type: 'string', example: 'turn_on', description: '指令动作'),
                new OA\Property(property: 'params', type: 'object', description: '指令参数'),
            ]
        )
    )]
    #[OA\Response(response: 200, description: '指令已发送', content: new OA\JsonContent(
        properties: [
            new OA\Property(property: 'code', type: 'integer', example: 0),
            new OA\Property(property: 'data', properties: [
                new OA\Property(property: 'request_id', type: 'string'),
                new OA\Property(property: 'status', type: 'string', example: 'pending'),
            ], type: 'object'),
        ]
    ))]
    #[OA\Response(response: 404, description: '设备不存在', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'))]
    #[OA\Response(response: 422, description: '指令内容为空', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'))]
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

        // metric_fields 校验：如果提供了则必须是数组
        if (isset($data['metric_fields']) && !is_null($data['metric_fields'])) {
            if (is_string($data['metric_fields'])) {
                $decoded = json_decode($data['metric_fields'], true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return api_error('metric_fields 必须是有效的 JSON 数组', 422, 1000);
                }
                $data['metric_fields'] = $decoded;
            }
            if (!is_array($data['metric_fields'])) {
                return api_error('metric_fields 必须是数组', 422, 1000);
            }
        }

        return $data;
    }
}
