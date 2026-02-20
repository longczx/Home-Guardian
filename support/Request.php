<?php
/**
 * Home Guardian - 自定义 Request 类
 *
 * 扩展 Webman 的 Request 类，添加与认证相关的便捷属性和方法。
 * AuthMiddleware 会将解析后的 JWT 用户信息挂载到 $request->user 属性上，
 * 后续的中间件和控制器可直接通过 $request->user 访问当前用户信息。
 *
 * @property object|null $user JWT 解析后的当前用户信息
 */

namespace support;

class Request extends \Webman\Http\Request
{
    /**
     * 当前已认证的用户信息
     *
     * 由 AuthMiddleware 在 JWT 验证通过后设置，包含以下字段：
     *   - id:          int       用户 ID
     *   - username:    string    用户名
     *   - roles:       array     角色名列表，如 ['admin']
     *   - permissions: object    权限集合，如 {"admin": true}
     *   - locations:   array     允许的位置作用域，空数组表示不限制
     *
     * @var object|null 未认证时为 null
     */
    public ?object $user = null;

    /**
     * 获取当前用户 ID
     *
     * @return int|null 用户 ID，未认证时返回 null
     */
    public function userId(): ?int
    {
        return $this->user?->id;
    }

    /**
     * 检查当前用户是否为超级管理员
     *
     * admin 角色的 permissions 中包含 {"admin": true}，拥有全部权限。
     *
     * @return bool
     */
    public function isAdmin(): bool
    {
        if (!$this->user) {
            return false;
        }

        $permissions = (array)$this->user->permissions;
        return !empty($permissions['admin']);
    }

    /**
     * 检查当前用户是否拥有指定权限
     *
     * 权限格式为 "资源.操作"，如 "devices.control"、"users.create"。
     * admin 角色自动拥有所有权限。
     *
     * @param  string $permission 权限标识，格式: "resource.action"
     * @return bool
     */
    public function hasPermission(string $permission): bool
    {
        // 未认证用户没有任何权限
        if (!$this->user) {
            return false;
        }

        // admin 拥有全部权限
        if ($this->isAdmin()) {
            return true;
        }

        // 解析 "resource.action" 格式
        $parts = explode('.', $permission, 2);
        if (count($parts) !== 2) {
            return false;
        }

        [$resource, $action] = $parts;
        $permissions = (array)$this->user->permissions;

        // 检查该资源下是否包含指定操作
        if (!isset($permissions[$resource]) || !is_array($permissions[$resource])) {
            return false;
        }

        return in_array($action, $permissions[$resource], true);
    }

    /**
     * 检查当前用户是否有权访问指定位置的设备
     *
     * 位置作用域为空数组表示不限制（可访问所有位置）。
     * admin 角色不受位置限制。
     *
     * @param  string|null $location 设备所在位置
     * @return bool
     */
    public function canAccessLocation(?string $location): bool
    {
        // 未认证用户不能访问任何位置
        if (!$this->user) {
            return false;
        }

        // admin 不受位置限制
        if ($this->isAdmin()) {
            return true;
        }

        // 位置作用域为空 = 不限制
        $allowedLocations = $this->user->locations ?? [];
        if (empty($allowedLocations)) {
            return true;
        }

        // 设备没有设置位置信息，允许访问（位置为空的设备对所有人可见）
        if ($location === null || $location === '') {
            return true;
        }

        return in_array($location, $allowedLocations, true);
    }

    /**
     * 获取客户端真实 IP 地址
     *
     * 优先从 Nginx 设置的 X-Real-IP 头获取，其次从 X-Forwarded-For 获取，
     * 最后回退到连接层 IP。用于审计日志记录。
     *
     * @return string 客户端 IP 地址
     */
    public function clientIp(): string
    {
        // Nginx 配置了 proxy_set_header X-Real-IP $remote_addr
        if ($ip = $this->header('x-real-ip')) {
            return $ip;
        }

        // 如果经过多层代理，取第一个 IP（最初的客户端 IP）
        if ($forwarded = $this->header('x-forwarded-for')) {
            return trim(explode(',', $forwarded)[0]);
        }

        // 直连场景（无代理），从 TCP 连接获取
        return $this->getRemoteIp();
    }
}
