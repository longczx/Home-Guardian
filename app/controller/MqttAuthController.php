<?php
/**
 * Home Guardian - MQTT 认证控制器
 *
 * 处理 EMQX HTTP Auth 插件的回调请求：
 *   POST /api/mqtt/auth  — 设备连接认证
 *   POST /api/mqtt/acl   — 设备主题 ACL 鉴权
 *
 * 这两个接口由 EMQX 在 Docker 内部网络中调用，不对外暴露。
 * 不需要 JWT 认证（设备使用自己的 MQTT 凭证）。
 */

namespace app\controller;

use app\service\DeviceService;
use support\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'MQTT 认证', description: 'EMQX 内部回调接口（设备认证 & ACL）')]
class MqttAuthController
{
    #[OA\Post(
        path: '/mqtt/auth',
        summary: '设备 MQTT 认证',
        description: 'EMQX HTTP Auth 插件回调。验证设备 MQTT 连接凭证。此接口由 EMQX 内部调用，不需要 JWT。',
        tags: ['MQTT 认证'],
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['username', 'password'],
            properties: [
                new OA\Property(property: 'username', type: 'string', example: 'esp32-living-room-01', description: '设备 UID'),
                new OA\Property(property: 'password', type: 'string', description: '设备 MQTT 密码'),
            ]
        )
    )]
    #[OA\Response(response: 200, description: 'EMQX 5 认证决策，顶层 result 为 allow 或 deny', content: new OA\JsonContent(
        properties: [
            new OA\Property(property: 'result', type: 'string', enum: ['allow', 'deny']),
            new OA\Property(property: 'is_superuser', type: 'boolean', example: false),
        ]
    ))]
    public function auth(Request $request)
    {
        $username = $request->post('username', '');
        $password = $request->post('password', '');

        if (empty($username) || empty($password)) {
            return $this->decision(false, true);
        }

        $verified = DeviceService::verifyMqttCredentials($username, $password);

        return $this->decision($verified, true);
    }

    #[OA\Post(
        path: '/mqtt/acl',
        summary: '设备主题 ACL 鉴权',
        description: 'EMQX ACL 回调。检查设备是否有权操作指定 MQTT 主题。',
        tags: ['MQTT 认证'],
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['username', 'topic', 'action'],
            properties: [
                new OA\Property(property: 'username', type: 'string', example: 'esp32-living-room-01'),
                new OA\Property(property: 'topic', type: 'string', example: 'home/upstream/esp32-living-room-01/telemetry'),
                new OA\Property(property: 'action', type: 'string', enum: ['publish', 'subscribe']),
            ]
        )
    )]
    #[OA\Response(response: 200, description: 'EMQX 5 授权决策，顶层 result 为 allow 或 deny', content: new OA\JsonContent(
        properties: [
            new OA\Property(property: 'result', type: 'string', enum: ['allow', 'deny']),
        ]
    ))]
    public function acl(Request $request)
    {
        $username = $request->post('username', '');
        $topic = $request->post('topic', '');
        $action = $request->post('action', '');

        if (empty($username) || empty($topic) || empty($action)) {
            return $this->decision(false);
        }

        $allowed = DeviceService::checkMqttAcl($username, $topic, $action);

        return $this->decision($allowed);
    }

    private function decision(bool $allowed, bool $authentication = false): \support\Response
    {
        // EMQX 5 reads result at the top level. Explicit deny stops the chain;
        // 4xx/5xx responses mean ignore, not deny, to the HTTP plugin.
        $body = ['result'=>$allowed ? 'allow' : 'deny'];
        if ($authentication) $body['is_superuser'] = false;
        return new \support\Response(200, ['Content-Type'=>'application/json'], json_encode($body));
    }
}
