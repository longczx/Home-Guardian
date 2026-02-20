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

class MqttAuthController
{
    /**
     * 设备连接认证
     *
     * POST /api/mqtt/auth
     * EMQX 请求体: { "username": "esp32-xxx", "password": "device_password" }
     *
     * 响应：
     *   200 → 认证通过，允许连接
     *   401 → 认证失败，拒绝连接
     */
    public function auth(Request $request)
    {
        $username = $request->post('username', '');
        $password = $request->post('password', '');

        if (empty($username) || empty($password)) {
            return api_error('缺少认证参数', 401, 0);
        }

        $verified = DeviceService::verifyMqttCredentials($username, $password);

        if ($verified) {
            // 返回 200 表示认证通过
            // EMQX 要求返回 JSON 格式
            return api_success(['result' => 'allow'], '认证通过');
        }

        return api_error('认证失败', 401, 0);
    }

    /**
     * 设备主题 ACL 鉴权
     *
     * POST /api/mqtt/acl
     * EMQX 请求体: { "username": "esp32-xxx", "topic": "home/upstream/...", "action": "publish" }
     *
     * 响应：
     *   200 → 鉴权通过，允许操作
     *   403 → 鉴权失败，拒绝操作
     */
    public function acl(Request $request)
    {
        $username = $request->post('username', '');
        $topic = $request->post('topic', '');
        $action = $request->post('action', '');  // publish / subscribe

        if (empty($username) || empty($topic) || empty($action)) {
            return api_error('缺少鉴权参数', 403, 0);
        }

        $allowed = DeviceService::checkMqttAcl($username, $topic, $action);

        if ($allowed) {
            return api_success(['result' => 'allow'], '鉴权通过');
        }

        return api_error('无权操作该主题', 403, 0);
    }
}
