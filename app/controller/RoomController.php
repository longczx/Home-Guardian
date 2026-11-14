<?php
namespace app\controller;
use app\service\{RoomService, AuditService};
use app\exception\BusinessException;
use support\Request;
class RoomController
{
    private function home(Request $r): int {
        if (!in_array($r->user->home_role ?? '', ['owner','admin'], true) || !empty($r->user->locations)) throw new BusinessException('房间管理需要管理员及全部房间权限', 403, 1004);
        return (int)$r->user->home_id;
    }
    public function rename(Request $r, int $id) {
        $home = $this->home($r); $name = $r->post('name');
        if (!is_string($name)) return api_error('请填写房间名称', 422, 1000);
        $result = RoomService::rename($home, $id, $name);
        AuditService::log($r, 'update', 'room', $id, ['name'=>$name]);
        return api_success($result);
    }
    public function reorder(Request $r) {
        $home = $this->home($r); $ids = $r->post('ids');
        if (!is_array($ids) || count($ids) > 1000 || array_filter($ids, fn ($v) => !is_int($v) || $v <= 0)) return api_error('房间排序格式无效', 422, 1000);
        RoomService::reorder($home, $ids); return api_success(null);
    }
    public function move(Request $r, int $id) {
        $home = $this->home($r); $ids = $r->post('device_ids');
        if (!is_array($ids) || array_filter($ids, fn ($v) => !is_int($v) || $v <= 0)) return api_error('请选择有效设备', 422, 1000);
        $count = RoomService::move($home, $id, $ids);
        AuditService::log($r, 'update', 'room_devices', $id, ['device_ids'=>$ids]);
        return api_success(['moved'=>$count]);
    }
}
