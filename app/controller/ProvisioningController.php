<?php
/**
 * Home Guardian - 设备配网控制器
 *
 *   POST /api/provisioning/codes              — 生成配对码（需登录 + devices.create）
 *   GET  /api/provisioning/codes/{code}/status — 轮询配对码状态（需登录）
 *   POST /api/provisioning/register            — 设备自注册（公开，凭配对码信任）
 */

namespace app\controller;

use app\service\ProvisioningService;
use support\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: '设备配网', description: '自助配网：生成配对码、设备自注册')]
class ProvisioningController
{
    #[OA\Post(
        path: '/provisioning/codes',
        summary: '生成配对码',
        description: '移动端"添加设备"时生成一个短时有效的配对码，交给设备完成自注册。',
        security: [['bearerAuth' => []]],
        tags: ['设备配网'],
    )]
    #[OA\RequestBody(content: new OA\JsonContent(
        properties: [
            new OA\Property(property: 'location', type: 'string', example: '客厅', description: '预设设备位置（可选）'),
        ]
    ))]
    #[OA\Response(response: 201, description: '已生成', content: new OA\JsonContent(
        properties: [
            new OA\Property(property: 'code', type: 'integer', example: 0),
            new OA\Property(property: 'data', properties: [
                new OA\Property(property: 'provision_code', type: 'string', example: 'K7QF9X2M'),
                new OA\Property(property: 'expires_in', type: 'integer', example: 600),
                new OA\Property(property: 'expires_at', type: 'string'),
            ], type: 'object'),
        ]
    ))]
    public function createCode(Request $request)
    {
        $location = $request->post('location');
        $location = (is_string($location) && $location !== '') ? $location : null;

        $record = ProvisioningService::createCode($request->userId(), $location);

        return api_success([
            'provision_code' => $record->code,
            'expires_at'     => (string)$record->expires_at,
            'expires_in'     => max(0, strtotime((string)$record->expires_at) - time()),
        ], '配对码已生成', 201);
    }

    #[OA\Get(
        path: '/provisioning/codes/{code}/status',
        summary: '查询配对码状态',
        description: '移动端轮询：pending 等待中 / registered 已注册（含设备信息）/ expired 已过期。',
        security: [['bearerAuth' => []]],
        tags: ['设备配网'],
    )]
    #[OA\Parameter(name: 'code', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 200, description: '成功', content: new OA\JsonContent(
        properties: [
            new OA\Property(property: 'code', type: 'integer', example: 0),
            new OA\Property(property: 'data', properties: [
                new OA\Property(property: 'status', type: 'string', example: 'registered'),
                new OA\Property(property: 'device', type: 'object', nullable: true),
            ], type: 'object'),
        ]
    ))]
    #[OA\Response(response: 404, description: '配对码不存在', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'))]
    public function status(Request $request, string $code)
    {
        return api_success(ProvisioningService::statusOf($code, $request->userId()));
    }

    #[OA\Post(
        path: '/provisioning/register',
        summary: '设备自注册',
        description: '设备连上网后凭配对码注册：平台建网关+子传感器并返回 MQTT 凭证。公开接口，凭配对码信任。',
        tags: ['设备配网'],
    )]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['provision_code', 'gateway'],
        properties: [
            new OA\Property(property: 'provision_code', type: 'string', example: 'K7QF9X2M'),
            new OA\Property(property: 'gateway', type: 'object', properties: [
                new OA\Property(property: 'device_uid', type: 'string', example: 'esp32-3f2a1b'),
                new OA\Property(property: 'name', type: 'string', example: '客厅网关'),
                new OA\Property(property: 'firmware_version', type: 'string', example: '1.0.0'),
            ]),
            new OA\Property(property: 'sensors', type: 'array', items: new OA\Items(type: 'object')),
        ]
    ))]
    #[OA\Response(response: 201, description: '注册成功', content: new OA\JsonContent(
        properties: [
            new OA\Property(property: 'code', type: 'integer', example: 0),
            new OA\Property(property: 'data', properties: [
                new OA\Property(property: 'mqtt', type: 'object', properties: [
                    new OA\Property(property: 'host', type: 'string'),
                    new OA\Property(property: 'port', type: 'integer'),
                    new OA\Property(property: 'username', type: 'string'),
                    new OA\Property(property: 'password', type: 'string'),
                ]),
                new OA\Property(property: 'gateway_uid', type: 'string'),
                new OA\Property(property: 'devices', type: 'array', items: new OA\Items(type: 'object')),
            ], type: 'object'),
        ]
    ))]
    #[OA\Response(response: 410, description: '配对码已过期/已用', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'))]
    #[OA\Response(response: 422, description: '配对码无效或缺少字段', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'))]
    public function register(Request $request)
    {
        // 注意：公开接口，安全性依赖配对码（8位高熵 + 10分钟 TTL + 一次性使用）。
        // 生产建议：① 平台启用 HTTPS（响应含明文 MQTT 密码）；② 对本接口加 IP 限流。
        $result = ProvisioningService::register($request->post());
        return api_success($result, '设备已注册', 201);
    }
}
