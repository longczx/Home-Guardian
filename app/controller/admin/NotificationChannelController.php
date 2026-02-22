<?php
/**
 * Home Guardian - Admin 通知渠道控制器
 */

namespace app\controller\admin;

use app\model\NotificationChannel;
use app\service\NotificationService;
use support\Request;

class NotificationChannelController
{
    public function index(Request $request)
    {
        $query = NotificationChannel::query();

        if ($type = $request->get('type')) {
            $query->ofType($type);
        }

        $channels = $query->orderBy('id', 'asc')->get()->toArray();

        return view('admin/notification-channel/list', [
            'channels'  => $channels,
            'filters'   => $request->get(),
            'nav'       => 'notification-channels',
            'adminUser' => $request->adminUser,
        ]);
    }

    public function create(Request $request)
    {
        return view('admin/notification-channel/form', [
            'channel'   => null,
            'nav'       => 'notification-channels',
            'adminUser' => $request->adminUser,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->post();

        if (empty($data['name']) || empty($data['type'])) {
            return view('admin/notification-channel/form', [
                'channel'   => null,
                'error'     => '名称和类型不能为空',
                'old'       => $data,
                'nav'       => 'notification-channels',
                'adminUser' => $request->adminUser,
            ]);
        }

        $config = [];
        if (!empty($data['config_json'])) {
            $config = json_decode($data['config_json'], true) ?: [];
        }

        NotificationChannel::create([
            'name'       => $data['name'],
            'type'       => $data['type'],
            'config'     => $config,
            'is_enabled' => isset($data['is_enabled']),
            'created_by' => $request->adminUser['id'],
        ]);

        return redirect('/admin/notification-channels');
    }

    public function edit(Request $request, int $id)
    {
        $channel = NotificationChannel::find($id);
        if (!$channel) {
            return redirect('/admin/notification-channels');
        }

        return view('admin/notification-channel/form', [
            'channel'   => $channel,
            'nav'       => 'notification-channels',
            'adminUser' => $request->adminUser,
        ]);
    }

    public function update(Request $request, int $id)
    {
        $channel = NotificationChannel::find($id);
        if (!$channel) {
            return redirect('/admin/notification-channels');
        }

        $data = $request->post();

        $updateData = [];
        if (isset($data['name'])) $updateData['name'] = $data['name'];
        $updateData['is_enabled'] = isset($data['is_enabled']);
        if (!empty($data['config_json'])) {
            $updateData['config'] = json_decode($data['config_json'], true) ?: [];
        }

        $channel->update($updateData);
        return redirect('/admin/notification-channels');
    }

    public function delete(Request $request, int $id)
    {
        $channel = NotificationChannel::find($id);
        if ($channel) {
            $channel->delete();
        }
        return redirect('/admin/notification-channels');
    }

    public function test(Request $request, int $id)
    {
        $channel = NotificationChannel::find($id);
        if (!$channel) {
            return redirect('/admin/notification-channels');
        }

        try {
            NotificationService::send(
                [$id],
                'Home Guardian 测试通知',
                '这是一条测试通知，如果你看到此消息说明通知渠道配置正确。',
                ['test' => true]
            );
        } catch (\Throwable $e) {
            // silently fail, redirect with error would need flash messages
        }

        return redirect('/admin/notification-channels');
    }
}
