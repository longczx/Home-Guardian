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
use OpenApi\Attributes as OA;

#[OA\Tag(name: '通知渠道', description: '通知渠道的 CRUD 和测试')]
class NotificationChannelController
{
    #[OA\Get(
        path: '/notification-channels',
        summary: '通知渠道列表',
        description: '获取所有通知渠道，可按类型筛选。不分页。',
        security: [['bearerAuth' => []]],
        tags: ['通知渠道'],
    )]
    #[OA\Parameter(name: 'type', in: 'query', description: 'email / webhook / telegram / wechat_work / dingtalk', required: false, schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 200, description: '成功', content: new OA\JsonContent(
        properties: [
            new OA\Property(property: 'code', type: 'integer', example: 0),
            new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/NotificationChannel')),
        ]
    ))]
    public function index(Request $request)
    {
        $query = NotificationChannel::query();

        if ($type = $request->get('type')) {
            $query->ofType($type);
        }

        $channels = $query->orderBy('id', 'asc')->get();

        return api_success($channels);
    }

    #[OA\Get(
        path: '/notification-channels/{id}',
        summary: '渠道详情',
        security: [['bearerAuth' => []]],
        tags: ['通知渠道'],
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: '成功', content: new OA\JsonContent(
        properties: [
            new OA\Property(property: 'code', type: 'integer', example: 0),
            new OA\Property(property: 'data', ref: '#/components/schemas/NotificationChannel'),
        ]
    ))]
    #[OA\Response(response: 404, description: '不存在', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'))]
    public function show(Request $request, int $id)
    {
        $channel = NotificationChannel::with('creator:id,username')->find($id);

        if (!$channel) {
            return api_error('通知渠道不存在', 404, 8001);
        }

        return api_success($channel);
    }

    #[OA\Post(
        path: '/notification-channels',
        summary: '创建通知渠道',
        security: [['bearerAuth' => []]],
        tags: ['通知渠道'],
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['name', 'type', 'config'],
            properties: [
                new OA\Property(property: 'name', type: 'string', example: '管理员邮箱'),
                new OA\Property(property: 'type', type: 'string', enum: ['email', 'webhook', 'telegram', 'wechat_work', 'dingtalk']),
                new OA\Property(property: 'config', type: 'object', example: ['smtp_host' => 'smtp.gmail.com', 'to' => ['admin@example.com']]),
                new OA\Property(property: 'is_enabled', type: 'boolean', example: true),
            ]
        )
    )]
    #[OA\Response(response: 201, description: '创建成功', content: new OA\JsonContent(
        properties: [
            new OA\Property(property: 'code', type: 'integer', example: 0),
            new OA\Property(property: 'data', ref: '#/components/schemas/NotificationChannel'),
        ]
    ))]
    #[OA\Response(response: 422, description: '参数验证失败', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'))]
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

    #[OA\Put(
        path: '/notification-channels/{id}',
        summary: '更新通知渠道',
        security: [['bearerAuth' => []]],
        tags: ['通知渠道'],
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\RequestBody(content: new OA\JsonContent(
        properties: [
            new OA\Property(property: 'name', type: 'string'),
            new OA\Property(property: 'config', type: 'object'),
            new OA\Property(property: 'is_enabled', type: 'boolean'),
        ]
    ))]
    #[OA\Response(response: 200, description: '更新成功', content: new OA\JsonContent(
        properties: [
            new OA\Property(property: 'code', type: 'integer', example: 0),
            new OA\Property(property: 'data', ref: '#/components/schemas/NotificationChannel'),
        ]
    ))]
    #[OA\Response(response: 404, description: '不存在', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'))]
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

    #[OA\Delete(
        path: '/notification-channels/{id}',
        summary: '删除通知渠道',
        security: [['bearerAuth' => []]],
        tags: ['通知渠道'],
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: '删除成功', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    #[OA\Response(response: 404, description: '不存在', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'))]
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

    #[OA\Post(
        path: '/notification-channels/{id}/test',
        summary: '测试通知渠道',
        description: '向指定渠道发送一条测试通知，验证渠道配置是否正确。',
        security: [['bearerAuth' => []]],
        tags: ['通知渠道'],
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: '测试通知已发送', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    #[OA\Response(response: 404, description: '渠道不存在', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'))]
    #[OA\Response(response: 500, description: '发送失败', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'))]
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
