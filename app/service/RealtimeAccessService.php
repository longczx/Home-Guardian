<?php

namespace app\service;

use app\model\Device;
use support\Request;

class RealtimeAccessService
{
    public static function canReceive(object $payload, array $event, ?Device $device = null): bool
    {
        $request = new Request('');
        $request->user = (object)[
            'id' => (int)$payload->sub,
            'permissions' => $payload->permissions ?? (object)[],
            'locations' => $payload->locations ?? [],
        ];
        if (isset($event['device_id'])) {
            if (!$device || (int)$device->home_id !== (int)($payload->home_id ?? 0)
                || !$request->canAccessLocation($device->location)) {
                return false;
            }
            $permission = str_starts_with($event['type'] ?? '', 'alert') ? 'alerts.view' : 'devices.view';
            return $request->hasPermission($permission);
        }
        // 无设备的通知必须明确标注接收家庭/用户，不能降级成全员广播。
        if (isset($event['user_id']) && (int)$event['user_id'] !== (int)$payload->sub) return false;
        if (!isset($event['home_id']) || (int)$event['home_id'] !== (int)($payload->home_id ?? 0)) return false;
        return $request->hasPermission('alerts.view');
    }
}
