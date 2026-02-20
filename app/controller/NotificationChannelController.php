<?php
/**
 * Home Guardian - 通知渠道控制器
 *
 *   GET    /api/notification-channels          — 渠道列表
 *   GET    /api/notification-channels/{id}     — 渠道详情
 *   POST   /api/notification-channels          — 创建渠道
 *   PUT    /api/notification-channels/{id}     — 更新渠道
 *   DELETE /api/notification-channels/{id}     — 删除渠道
 *   POST   /api/notification-channels/{id}/test — 测试渠道
 */

namespace app\controller;

use app\model\NotificationChannel;
use app\service\NotificationService;
use app\service\AuditService;
use app\exception\BusinessException;
use support\Request;

class NotificationChannelController
{
    /**
     * 通知渠道列表
     */
    public function index(Request $request)
    {
        $query = NotificationChannel::query();

        if ($type = $request->get('type')) {
            $query->ofType($type);
        }

        $channels = $query->orderBy('id', 'asc')->get();

        return api_success($channels);
    }

    /**
     * 渠道详情
     */
    public function show(Request $request, int $id)
    {
        $channel = NotificationChannel::with('creator:id,username')->find($id);

        if (!$channel) {
            return api_error('通知渠道不存在', 404, 8001);
        }

        return api_success($channel);
    }

    /**
     * 创建通知渠道
     *
     * POST /api/notification-channels
     * Body: {
     *   "name": "管理员邮箱",
     *   "type": "email",
     *   "config": {"smtp_host": "smtp.gmail.com", "to": ["admin@example.com"]}
     * }
     */
    public function store(Request $request)
    {
        $data = $request->post();

        $errors = [];
        if (empty($data['name'])) $errors[] = 'name 不能为空';
        if (empty($data['type'])) $errors[] = 'type 不能为空';
        if (empty($data['config'])) $errors[] = 'config 不能为空';

        $validTypes = ['email', 'webhook', 'telegram', 'wechat_work', 'dingtalk'];
        if (!empty($data['type']) && !in_array($data['type'], $validTypes)) {
            $errors[] = 'type 必须是 ' . implode('/', $validTypes) . ' 之一';
        }

        if (!empty($errors)) {
            return api_error('参数验证失败', 422, 1000, $errors);
        }

        $channel = NotificationChannel::create([
            'name'       => $data['name'],
            'type'       => $data['type'],
            'config'     => $data['config'],
            'is_enabled' => $data['is_enabled'] ?? true,
            'created_by' => $request->userId(),
        ]);

        AuditService::log($request, 'create', 'notification_channel', $channel->id, [
            'name' => $channel->name,
            'type' => $channel->type,
        ]);

        return api_success($channel, '通知渠道创建成功', 201);
    }

    /**
     * 更新通知渠道
     */
    public function update(Request $request, int $id)
    {
        $channel = NotificationChannel::find($id);
        if (!$channel) {
            return api_error('通知渠道不存在', 404, 8001);
        }

        $data = $request->post();
        $original = $channel->toArray();

        $channel->update(array_filter([
            'name'       => $data['name'] ?? null,
            'config'     => $data['config'] ?? null,
            'is_enabled' => isset($data['is_enabled']) ? (bool)$data['is_enabled'] : null,
        ], fn($v) => $v !== null));

        AuditService::log($request, 'update', 'notification_channel', $id,
            AuditService::diffChanges($original, $channel->fresh()->toArray())
        );

        return api_success($channel->fresh(), '通知渠道更新成功');
    }

    /**
     * 删除通知渠道
     */
    public function destroy(Request $request, int $id)
    {
        $channel = NotificationChannel::find($id);
        if (!$channel) {
            return api_error('通知渠道不存在', 404, 8001);
        }

        $name = $channel->name;
        $channel->delete();

        AuditService::log($request, 'delete', 'notification_channel', $id, [
            'name' => $name,
        ]);

        return api_success(null, '通知渠道已删除');
    }

    /**
     * 测试通知渠道
     *
     * POST /api/notification-channels/{id}/test
     *
     * 向该渠道发送一条测试通知，验证配置是否正确。
     */
    public function test(Request $request, int $id)
    {
        $channel = NotificationChannel::find($id);
        if (!$channel) {
            return api_error('通知渠道不存在', 404, 8001);
        }

        try {
            NotificationService::send(
                [$id],
                'Home Guardian 测试通知',
                '这是一条测试通知，如果你看到此消息说明通知渠道配置正确。',
                ['test' => true]
            );
            return api_success(null, '测试通知已发送');
        } catch (\Throwable $e) {
            return api_error('测试通知发送失败: ' . $e->getMessage(), 500, 8002);
        }
    }
}
