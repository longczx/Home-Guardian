<?php
/**
 * Home Guardian - Admin 位置管理控制器
 *
 * 位置不是独立的数据表，而是从 devices.location 聚合的唯一值列表。
 * 此控制器允许管理员查看所有位置及关联设备数量。
 */

namespace app\controller\admin;

use app\model\Device;
use support\Request;
use support\Db;

class LocationController
{
    public function index(Request $request)
    {
        $locations = Db::table('devices')
            ->select(Db::raw('location, COUNT(*) as device_count, SUM(CASE WHEN is_online THEN 1 ELSE 0 END) as online_count'))
            ->whereNotNull('location')
            ->where('location', '!=', '')
            ->groupBy('location')
            ->orderBy('location')
            ->get();

        return view('admin/location/list', [
            'locations' => $locations,
            'nav'       => 'locations',
            'adminUser' => $request->adminUser,
        ]);
    }

    public function store(Request $request)
    {
        // 位置通过设备创建/编辑时指定，这里不单独创建
        return redirect('/admin/locations');
    }

    public function delete(Request $request, int $id)
    {
        // 位置不可直接删除，需通过编辑设备来修改位置
        return redirect('/admin/locations');
    }
}
