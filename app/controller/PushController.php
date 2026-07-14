<?php
/**
 * Home Guardian - 推送设备控制器
 *
 *   POST   /api/push/devices     — 上报/更新推送 cid（登录后调用）
 *   PUT    /api/push/settings    — 更新推送开关 / 级别阈值
 *   DELETE /api/push/devices     — 注销 cid（退出登录时调用）
 *   GET    /api/push/settings    — 当前用户推送设置（取任一设备的设置回显）
 */

namespace app\controller;

use app\model\UserPushDevice;
use support\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: '推送', description: 'uniPush 推送设备与设置')]
class PushController
{
    #[OA\Post(path: '/push/devices', summary: '上报推送 cid', security: [['bearerAuth' => []]], tags: ['推送'])]
    public function register(Request $request)
    {
        $cid = trim((string)$request->post('cid', ''));
        if ($cid === '') {
            return api_error('cid 不能为空', 422, 1000);
        }
        $platform = (string)$request->post('platform', 'app');

        // cid 唯一：同一设备换用户登录时归属随之更新
        $device = UserPushDevice::where('cid', $cid)->first() ?: new UserPushDevice();
        $device->cid = $cid;
        $device->user_id = $request->userId();
        $device->home_id = (int)$request->user->home_id;
        $device->platform = $platform;
        $device->last_active_at = now();
        if (!$device->exists) {
            $device->push_enabled = true;
            $device->min_severity = 'warning';
        }
        $device->save();

        return api_success(['id' => $device->id], '推送已注册');
    }

    #[OA\Get(path: '/push/settings', summary: '推送设置', security: [['bearerAuth' => []]], tags: ['推送'])]
    public function settings(Request $request)
    {
        $device = UserPushDevice::where('user_id', $request->userId())
            ->orderBy('last_active_at', 'desc')
            ->first();

        return api_success([
            'push_enabled' => $device?->push_enabled ?? true,
            'min_severity' => $device?->min_severity ?? 'warning',
            'registered'   => (bool)$device,
        ]);
    }

    #[OA\Put(path: '/push/settings', summary: '更新推送设置', security: [['bearerAuth' => []]], tags: ['推送'])]
    public function updateSettings(Request $request)
    {
        $enabled = $request->post('push_enabled');
        $minSeverity = $request->post('min_severity');

        $update = [];
        if ($enabled !== null) {
            $update['push_enabled'] = (bool)$enabled;
        }
        if ($minSeverity !== null && isset(UserPushDevice::SEVERITY_WEIGHT[$minSeverity])) {
            $update['min_severity'] = $minSeverity;
        }
        if ($update) {
            // 作用于当前用户的所有设备（同账号多端设置一致）
            UserPushDevice::where('user_id', $request->userId())->update($update);
        }

        return api_success(null, '设置已更新');
    }

    #[OA\Delete(path: '/push/devices', summary: '注销推送 cid', security: [['bearerAuth' => []]], tags: ['推送'])]
    public function unregister(Request $request)
    {
        $cid = trim((string)$request->post('cid', ''));
        if ($cid !== '') {
            UserPushDevice::where('cid', $cid)->where('user_id', $request->userId())->delete();
        }
        return api_success(null, '推送已注销');
    }
}
