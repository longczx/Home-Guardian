<?php
/**
 * Home Guardian - 用户管理控制器
 *
 * 用户的 CRUD 和角色/位置分配 API。需要 users 资源的对应权限。
 *
 *   GET    /api/users          — 用户列表
 *   GET    /api/users/{id}     — 用户详情
 *   POST   /api/users          — 创建用户
 *   PUT    /api/users/{id}     — 更新用户
 *   DELETE /api/users/{id}     — 删除用户
 */

namespace app\controller;

use app\model\User;
use app\model\Role;
use app\model\UserAllowedLocation;
use app\service\AuditService;
use app\exception\BusinessException;
use support\Request;

class UserController
{
    /**
     * 用户列表
     *
     * GET /api/users?keyword=admin&is_active=1&page=1&per_page=20
     */
    public function index(Request $request)
    {
        $query = User::with('roles:id,name');

        // 关键词搜索
        if ($keyword = $request->get('keyword')) {
            $query->where(function ($q) use ($keyword) {
                $q->where('username', 'ILIKE', "%{$keyword}%")
                  ->orWhere('full_name', 'ILIKE', "%{$keyword}%")
                  ->orWhere('email', 'ILIKE', "%{$keyword}%");
            });
        }

        // 激活状态筛选
        if ($request->get('is_active') !== null) {
            $query->where('is_active', (bool)$request->get('is_active'));
        }

        $perPage = min((int)($request->get('per_page', 20)), 100);
        $paginator = $query->orderBy('id', 'asc')->paginate($perPage);

        return api_paginate($paginator);
    }

    /**
     * 用户详情
     *
     * GET /api/users/{id}
     */
    public function show(Request $request, int $id)
    {
        $user = User::with(['roles:id,name,description', 'allowedLocations'])->find($id);

        if (!$user) {
            return api_error('用户不存在', 404, 5001);
        }

        return api_success($user);
    }

    /**
     * 创建用户
     *
     * POST /api/users
     * Body: {
     *   "username": "kid",
     *   "password": "123456",
     *   "email": "kid@example.com",
     *   "full_name": "小孩",
     *   "role_ids": [2],
     *   "allowed_locations": ["儿童房"]
     * }
     */
    public function store(Request $request)
    {
        $data = $request->post();

        // 参数校验
        $errors = [];
        if (empty($data['username'])) {
            $errors[] = 'username 不能为空';
        }
        if (empty($data['password'])) {
            $errors[] = 'password 不能为空';
        } elseif (strlen($data['password']) < 6) {
            $errors[] = '密码长度不能少于 6 位';
        }
        if (!empty($errors)) {
            return api_error('参数验证失败', 422, 1000, $errors);
        }

        // 检查用户名唯一
        if (User::where('username', $data['username'])->exists()) {
            throw new BusinessException('用户名已存在', 409, 5002);
        }

        // 检查邮箱唯一
        if (!empty($data['email']) && User::where('email', $data['email'])->exists()) {
            throw new BusinessException('邮箱已被使用', 409, 5003);
        }

        // 创建用户
        $user = new User();
        $user->username = $data['username'];
        $user->setPassword($data['password']);
        $user->email = $data['email'] ?? null;
        $user->full_name = $data['full_name'] ?? null;
        $user->is_active = $data['is_active'] ?? true;
        $user->save();

        // 分配角色
        if (!empty($data['role_ids'])) {
            $validRoleIds = Role::whereIn('id', $data['role_ids'])->pluck('id')->toArray();
            $user->roles()->sync($validRoleIds);
        }

        // 设置位置作用域
        if (isset($data['allowed_locations'])) {
            self::syncLocations($user->id, $data['allowed_locations']);
        }

        AuditService::log($request, 'create', 'user', $user->id, [
            'username' => $user->username,
        ]);

        return api_success(
            $user->load(['roles:id,name', 'allowedLocations']),
            '用户创建成功',
            201
        );
    }

    /**
     * 更新用户
     *
     * PUT /api/users/{id}
     */
    public function update(Request $request, int $id)
    {
        $user = User::find($id);
        if (!$user) {
            return api_error('用户不存在', 404, 5001);
        }

        $data = $request->post();
        $original = $user->toArray();

        // 更新基本字段
        if (isset($data['email'])) {
            // 检查邮箱唯一（排除自身）
            if (!empty($data['email']) && User::where('email', $data['email'])->where('id', '!=', $id)->exists()) {
                throw new BusinessException('邮箱已被使用', 409, 5003);
            }
            $user->email = $data['email'];
        }
        if (isset($data['full_name'])) {
            $user->full_name = $data['full_name'];
        }
        if (isset($data['is_active'])) {
            $user->is_active = (bool)$data['is_active'];
        }

        // 修改密码
        if (!empty($data['password'])) {
            if (strlen($data['password']) < 6) {
                return api_error('密码长度不能少于 6 位', 422, 1000);
            }
            $user->setPassword($data['password']);
        }

        $user->save();

        // 更新角色
        if (isset($data['role_ids'])) {
            $validRoleIds = Role::whereIn('id', $data['role_ids'])->pluck('id')->toArray();
            $user->roles()->sync($validRoleIds);
        }

        // 更新位置作用域
        if (isset($data['allowed_locations'])) {
            self::syncLocations($user->id, $data['allowed_locations']);
        }

        AuditService::log($request, 'update', 'user', $id,
            AuditService::diffChanges($original, $user->fresh()->toArray())
        );

        return api_success(
            $user->load(['roles:id,name', 'allowedLocations']),
            '用户更新成功'
        );
    }

    /**
     * 删除用户
     *
     * DELETE /api/users/{id}
     *
     * 不允许删除自己。
     */
    public function destroy(Request $request, int $id)
    {
        // 禁止删除自己
        if ($request->userId() === $id) {
            return api_error('不能删除自己的账号', 400, 5004);
        }

        $user = User::find($id);
        if (!$user) {
            return api_error('用户不存在', 404, 5001);
        }

        $username = $user->username;
        $user->delete();

        AuditService::log($request, 'delete', 'user', $id, [
            'username' => $username,
        ]);

        return api_success(null, '用户已删除');
    }

    /**
     * 同步用户的位置作用域
     *
     * @param int   $userId    用户 ID
     * @param array $locations 位置名称列表，空数组表示清除（不限制）
     */
    private static function syncLocations(int $userId, array $locations): void
    {
        // 删除旧的
        UserAllowedLocation::where('user_id', $userId)->delete();

        // 插入新的
        foreach ($locations as $location) {
            if (!empty($location)) {
                UserAllowedLocation::create([
                    'user_id'  => $userId,
                    'location' => $location,
                ]);
            }
        }
    }
}
