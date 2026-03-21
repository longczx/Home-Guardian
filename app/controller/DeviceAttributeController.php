<?php
/**
 * Home Guardian - 设备属性控制器
 *
 * 管理设备的扩展属性（EAV 模式）。
 *
 *   GET    /api/devices/{device_id}/attributes       — 获取设备所有属性
 *   PUT    /api/devices/{device_id}/attributes       — 批量设置属性
 *   DELETE /api/devices/{device_id}/attributes/{key} — 删除指定属性
 */

namespace app\controller;

use app\model\Device;
use app\model\DeviceAttribute;
use app\service\DeviceService;
use support\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: '设备属性', description: '设备扩展属性管理（EAV 模式）')]
class DeviceAttributeController
{
    /**
     * 获取设备的所有属性
     *
     * GET /api/devices/{device_id}/attributes
     */
    #[OA\Get(
        path: '/devices/{deviceId}/attributes',
        summary: '获取设备属性',
        description: '获取指定设备的所有扩展属性，返回 key-value 对象。',
        security: [['bearerAuth' => []]],
        tags: ['设备属性'],
    )]
    #[OA\Parameter(name: 'deviceId', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: '成功', content: new OA\JsonContent(
        properties: [
            new OA\Property(property: 'code', type: 'integer', example: 0),
            new OA\Property(property: 'data', type: 'object', example: ['mac_address' => 'AA:BB:CC:DD:EE:FF', 'chip_model' => 'ESP32-S3']),
        ]
    ))]
    #[OA\Response(response: 404, description: '设备不存在', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'))]
    public function index(Request $request, int $deviceId)
    {
        $device = Device::find($deviceId);
        if (!$device) {
            return api_error('设备不存在', 404, 2001);
        }

        if (!$request->canAccessLocation($device->location)) {
            return api_error('无权访问该设备', 403, 1004);
        }

        $attributes = DeviceAttribute::where('device_id', $deviceId)->get();

        // 转换为 [{key, value}] 数组格式，与前端 DeviceAttribute 接口匹配
        $result = $attributes->map(fn($attr) => [
            'key'   => $attr->attribute_key,
            'value' => $attr->attribute_value,
        ])->values();

        return api_success($result);
    }

    /**
     * 批量设置设备属性
     *
     * PUT /api/devices/{device_id}/attributes
     * Body: { "mac_address": "AA:BB:CC:DD:EE:FF", "chip_model": "ESP32-S3" }
     *
     * 已存在的属性会更新值，不存在的会创建。
     */
    #[OA\Put(
        path: '/devices/{deviceId}/attributes',
        summary: '批量设置属性',
        description: '批量设置设备扩展属性。已存在的 key 会更新值，不存在的会创建。',
        security: [['bearerAuth' => []]],
        tags: ['设备属性'],
    )]
    #[OA\Parameter(name: 'deviceId', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            example: ['mac_address' => 'AA:BB:CC:DD:EE:FF', 'chip_model' => 'ESP32-S3'],
            additionalProperties: new OA\AdditionalProperties(type: 'string')
        )
    )]
    #[OA\Response(response: 200, description: '设置成功', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    #[OA\Response(response: 404, description: '设备不存在', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'))]
    #[OA\Response(response: 422, description: '数据为空', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'))]
    public function batchSet(Request $request, int $deviceId)
    {
        $device = Device::find($deviceId);
        if (!$device) {
            return api_error('设备不存在', 404, 2001);
        }

        if (!$request->canAccessLocation($device->location)) {
            return api_error('无权操作该设备', 403, 1004);
        }

        $attributes = $request->post();
        if (empty($attributes) || !is_array($attributes)) {
            return api_error('属性数据不能为空', 422, 1000);
        }

        DeviceService::setAttributes($deviceId, $attributes);

        return api_success(null, '属性设置成功');
    }

    /**
     * 删除指定属性
     *
     * DELETE /api/devices/{device_id}/attributes/{key}
     */
    #[OA\Delete(
        path: '/devices/{deviceId}/attributes/{key}',
        summary: '删除指定属性',
        description: '删除设备的某个扩展属性。',
        security: [['bearerAuth' => []]],
        tags: ['设备属性'],
    )]
    #[OA\Parameter(name: 'deviceId', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Parameter(name: 'key', in: 'path', required: true, description: '属性键名', schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 200, description: '删除成功', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    #[OA\Response(response: 404, description: '设备或属性不存在', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'))]
    public function destroy(Request $request, int $deviceId, string $key)
    {
        $device = Device::find($deviceId);
        if (!$device) {
            return api_error('设备不存在', 404, 2001);
        }

        if (!$request->canAccessLocation($device->location)) {
            return api_error('无权操作该设备', 403, 1004);
        }

        $deleted = DeviceAttribute::where('device_id', $deviceId)
            ->where('attribute_key', $key)
            ->delete();

        if (!$deleted) {
            return api_error('属性不存在', 404, 2003);
        }

        return api_success(null, '属性已删除');
    }
}
