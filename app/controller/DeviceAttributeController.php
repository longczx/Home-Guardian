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

class DeviceAttributeController
{
    /**
     * 获取设备的所有属性
     *
     * GET /api/devices/{device_id}/attributes
     */
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

        // 转换为 key-value 对象格式，方便前端使用
        $result = [];
        foreach ($attributes as $attr) {
            $result[$attr->attribute_key] = $attr->attribute_value;
        }

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
