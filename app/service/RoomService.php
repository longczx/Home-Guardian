<?php
namespace app\service;
use app\model\{Device, HomeUser};
use app\exception\BusinessException;

class RoomService
{
    public static function rename(int $homeId, int $id, string $name): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) throw new BusinessException('房间名称需为 1–100 字', 422, 1000);
        $db = (new Device)->getConnection();
        return $db->transaction(function () use ($db, $homeId, $id, $name) {
            $db->table('homes')->where('id', $homeId)->lockForUpdate()->first();
            $room = $db->table('rooms')->where('home_id', $homeId)->where('id', $id)->lockForUpdate()->first();
            if (!$room) throw new BusinessException('房间不存在', 404, 2001);
            if ($room->name === $name) return ['affected_devices'=>0,'affected_members'=>0];
            if ($db->table('rooms')->where('home_id', $homeId)->where('name', $name)->exists()
                || Device::withoutGlobalScopes()->where('home_id', $homeId)->where('location', $name)->exists()) throw new BusinessException('该房间名称已存在，请使用其他名称', 409, 2003);
            $members = HomeUser::where('home_id', $homeId)->pluck('user_id')->all();
            $affected = $db->table('user_allowed_locations')->whereIn('user_id', $members)->where('location', $room->name)->pluck('user_id')->unique()->all();
            if ($affected && HomeUser::whereIn('user_id', $affected)->where('home_id', '!=', $homeId)->exists()) throw new BusinessException('受影响成员的位置权限跨家庭共享，请先由管理员调整权限', 409, 2003);
            foreach ($affected as $userId) {
                if ($db->table('user_allowed_locations')->where('user_id', $userId)->where('location', $name)->exists()) {
                    $db->table('user_allowed_locations')->where('user_id', $userId)->where('location', $room->name)->delete();
                } else $db->table('user_allowed_locations')->where('user_id', $userId)->where('location', $room->name)->update(['location'=>$name]);
            }
            // Existing JWTs contain room names. Version changes make old scopes unusable immediately.
            if ($affected) $db->table('users')->whereIn('id', $affected)->increment('auth_version');
            $count = Device::withoutGlobalScopes()->where('home_id', $homeId)->where('location', $room->name)->update(['location'=>$name]);
            $db->table('rooms')->where('id', $id)->update(['name'=>$name]);
            return ['affected_devices'=>$count,'affected_members'=>count($affected)];
        });
    }
    public static function reorder(int $homeId, array $ids): void
    {
        $db = (new Device)->getConnection();
        $db->transaction(function () use ($db, $homeId, $ids) {
            $db->table('homes')->where('id', $homeId)->lockForUpdate()->first();
            $actual = $db->table('rooms')->where('home_id', $homeId)->pluck('id')->map(fn ($id) => (int)$id)->all();
            $sorted = $ids; sort($actual); sort($sorted);
            if ($actual !== $sorted || count($ids) !== count(array_unique($ids))) throw new BusinessException('房间列表已变化，请刷新后重新排序', 409, 2003);
            foreach ($ids as $order => $id) $db->table('rooms')->where('home_id', $homeId)->where('id', $id)->update(['sort_order'=>$order]);
        });
    }
    public static function move(int $homeId, int $roomId, array $ids): int
    {
        if (!$ids || count($ids) > 200 || count(array_unique($ids)) !== count($ids)) throw new BusinessException('每次选择 1–200 台不同设备', 422, 1000);
        $db = (new Device)->getConnection();
        return $db->transaction(function () use ($db, $homeId, $roomId, $ids) {
            $db->table('homes')->where('id', $homeId)->lockForUpdate()->first();
            $room = $db->table('rooms')->where('home_id', $homeId)->where('id', $roomId)->lockForUpdate()->first();
            if (!$room) throw new BusinessException('目标房间不存在', 404, 2001);
            $devices = Device::withoutGlobalScopes()->where('home_id', $homeId)->whereIn('id', $ids)->lockForUpdate()->get();
            if ($devices->count() !== count($ids)) throw new BusinessException('选择的设备不存在或不属于本家庭', 422, 2001);
            return Device::withoutGlobalScopes()->where('home_id', $homeId)->whereIn('id', $ids)->update(['location'=>$room->name]);
        });
    }
}
