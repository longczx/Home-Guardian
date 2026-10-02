<?php
namespace app\controller;
use app\model\{Device, Home, Automation, AlertLog};
use app\service\{DevicePresentationService, AutomationPolicyService, AutomationService, AuditService};
use app\exception\BusinessException;
use support\Request;
class HomeExperienceController
{
    private function device(Request $request, int $id): ?Device
    {
        $device = Device::withoutGlobalScopes()->where('home_id', $request->user->home_id)->find($id);
        return $device && $request->canAccessLocation($device->location) ? $device : null;
    }
    public function overview(Request $request)
    {
        $homeId = (int)$request->user->home_id;
        $devices = Device::withoutGlobalScopes()->where('home_id', $homeId)->inLocations($request->user->locations ?? [])->orderBy('id')->limit(100)->get();
        $favorites = (new Device)->getConnection()->table('device_preferences')->where('user_id', $request->userId())->where('is_favorite', true)->pluck('device_id')->all();
        $data = $devices->map(function ($device) use ($favorites) {
            return DevicePresentationService::describe($device) + ['is_favorite'=>in_array($device->id, $favorites)];
        })->all();
        $home = Home::find($homeId);
        $roomNames = (new Device)->getConnection()->table('rooms')->where('home_id', $homeId)->orderBy('sort_order')->orderBy('id')->pluck('name')->all();
        if (!empty($request->user->locations)) $roomNames = array_values(array_intersect($roomNames, $request->user->locations));
        return api_success(['mode'=>$home?->mode ?? 'home', 'mode_changed_at'=>$home?->mode_changed_at,
            'devices'=>$data, 'rooms'=>array_values(array_unique(array_merge($roomNames, $devices->pluck('location')->filter()->unique()->values()->all()))),
            'offline_count'=>$devices->where('is_online', false)->count(),
            'active_alerts'=>$request->hasPermission('alerts.view') ? AlertLog::withoutGlobalScopes()->where('home_id', $homeId)->whereIn('status', ['triggered','acknowledged'])->whereIn('device_id', $devices->pluck('id'))->count() : 0]);
    }
    public function mode(Request $request)
    {
        $mode = $request->post('mode');
        if (!in_array($mode, ['home','away','sleep'], true)) return api_error('模式只能为 home/away/sleep', 422, 8022);
        // A restricted-location member cannot alter automation across the entire home.
        if (!empty($request->user->locations)) return api_error('切换全屋模式需要全部房间权限', 403, 1004);
        $home = Home::find((int)$request->user->home_id);
        if (!$home) return api_error('家庭不存在', 404, 8020);
        if ($home->mode !== $mode) $home->update(['mode'=>$mode, 'mode_changed_at'=>now()]);
        AuditService::log($request, 'update', 'home_mode', $home->id, ['mode'=>$mode]);
        return api_success($home, '模式已切换，将在下一次规则触发时生效');
    }
    public function favorite(Request $request, int $id)
    {
        if (!$this->device($request, $id)) return api_error('设备不存在或无权访问', 404, 2001);
        $value = $request->post('is_favorite');
        if (!is_bool($value)) return api_error('is_favorite 必须为布尔值', 422, 1000);
        (new Device)->getConnection()->table('device_preferences')->updateOrInsert(['user_id'=>$request->userId(), 'device_id'=>$id], ['is_favorite'=>$value]);
        return api_success(['is_favorite'=>$value]);
    }
    public function override(Request $request, int $id)
    {
        $device = $this->device($request, $id);
        if (!$device) return api_error('设备不存在或无权访问', 404, 2001);
        $minutes = $request->post('minutes', 0);
        if (filter_var($minutes, FILTER_VALIDATE_INT) === false || $minutes < 0 || $minutes > 1440) return api_error('暂停分钟数需为 0-1440，0 为恢复', 422, 1000);
        $device->update(['manual_override_until'=>$minutes ? now()->addMinutes((int)$minutes) : null]);
        AuditService::log($request, 'update', 'device_override', $id, ['minutes'=>$minutes]);
        return api_success(['manual_override_until'=>$device->manual_override_until]);
    }
    public function preview(Request $request, int $id)
    {
        $automation = Automation::withoutGlobalScopes()->where('home_id', $request->user->home_id)->find($id);
        if (!$automation) return api_error('自动化规则不存在', 404, 4001);
        foreach (AutomationPolicyService::deviceIds($automation) as $deviceId) if (!$this->device($request, (int)$deviceId)) return api_error('无权读取规则涉及的设备', 403, 1004);
        return api_success(AutomationPolicyService::preview($automation));
    }
    public function rooms(Request $request)
    {
        $query = (new Device)->getConnection()->table('rooms')->where('home_id', $request->user->home_id);
        if (!empty($request->user->locations)) $query->whereIn('name', $request->user->locations);
        return api_success($query->orderBy('sort_order')->orderBy('id')->get());
    }
    public function saveRoom(Request $request)
    {
        if (!empty($request->user->locations)) return api_error('管理房间需要全部房间权限', 403, 1004);
        $name = $request->post('name', ''); $order = $request->post('sort_order', 0);
        if (!is_string($name) || trim($name) === '' || mb_strlen($name) > 100 || filter_var($order, FILTER_VALIDATE_INT) === false || abs((int)$order) > 10000) return api_error('房间名称 1-100 字，排序需为 -10000 到 10000 的整数', 422, 1000);
        $db = (new Device)->getConnection();
        $db->table('rooms')->updateOrInsert(['home_id'=>$request->user->home_id, 'name'=>trim($name)], ['sort_order'=>(int)$order]);
        AuditService::log($request, 'update', 'room', null, ['name'=>trim($name)]);
        return api_success(null, '房间已保存');
    }
    public function deleteRoom(Request $request, int $id)
    {
        if (!empty($request->user->locations)) return api_error('管理房间需要全部房间权限', 403, 1004);
        $db = (new Device)->getConnection();
        return $db->transaction(function () use ($db, $request, $id) {
            $room = $db->table('rooms')->where('home_id', $request->user->home_id)->where('id', $id)->lockForUpdate()->first();
            if (!$room) return api_error('房间不存在', 404, 2001);
            if (Device::withoutGlobalScopes()->where('home_id', $request->user->home_id)->where('location', $room->name)->exists()) return api_error('请先移出房间中的设备', 409, 2003);
            $db->table('rooms')->where('id', $id)->delete();
            AuditService::log($request, 'delete', 'room', $id);
            return api_success(null, '房间已删除');
        });
    }
    public function handling(Request $request, int $id)
    {
        $log = AlertLog::withoutGlobalScopes()->where('home_id', $request->user->home_id)->find($id);
        if (!$log || !$this->device($request, (int)$log->device_id)) return api_error('告警不存在或无权访问', 404, 3002);
        $note = $request->post('note', '');
        if (!is_string($note) || mb_strlen($note) > 1000) return api_error('处理备注最多 1000 字', 422, 1000);
        // Handling is separate from natural sensor recovery. Do not rewrite resolved alerts to firing.
        $log->update(['handling_note'=>trim($note), 'handled_by'=>$request->userId(), 'handled_at'=>now()]);
        AuditService::log($request, 'update', 'alert_handling', $id);
        return api_success($log, '处理记录已保存；实际恢复以传感器数据为准');
    }
}
