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
use OpenApi\Attributes as OA;

#[OA\Tag(name: '用户管理', description: '用户的 CRUD、角色分配、位置作用域')]
class UserController
{
    #[OA\Get(
        path: '/users',
        summary: '用户列表',
        description: '获取用户列表，支持关键词搜索和激活状态筛选。',
        security: [['bearerAuth' => []]],
        tags: ['用户管理'],
    )]
    #[OA\Parameter(name: 'keyword', in: 'query', description: '搜索用户名、姓名、邮箱', required: false, schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'is_active', in: 'query', required: false, schema: new OA\Schema(type: 'integer', enum: [0, 1]))]
    #[OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1))]
    #[OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20))]
    #[OA\Response(response: 200, description: '成功', content: new OA\JsonContent(ref: '#/components/schemas/PaginationMeta'))]
    public function index(Request $request)
    {
        $query = User::with('roles:id,name');

        if ($keyword = $request->get('keyword')) {
            $query->where(function ($q) use ($keyword) {
                $q->where('username', 'ILIKE', "%{$keyword}%")
                  ->orWhere('full_name', 'ILIKE', "%{$keyword}%")
                  ->orWhere('email', 'ILIKE', "%{$keyword}%");
            });
        }

        if ($request->get('is_active') !== null) {
            $query->where('is_active', (bool)$request->get('is_active'));
        }

        $perPage = min((int)($request->get('per_page', 20)), 100);
        $paginator = $query->orderBy('id', 'asc')->paginate($perPage);

        return api_paginate($paginator);
    }

    #[OA\Get(
        path: '/users/{id}',
        summary: '用户详情',
        description: '获取用户详细信息，包含角色和位置作用域。',
        security: [['bearerAuth' => []]],
        tags: ['用户管理'],
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: '成功', content: new OA\JsonContent(
        properties: [
            new OA\Property(property: 'code', type: 'integer', example: 0),
            new OA\Property(property: 'data', ref: '#/components/schemas/User'),
        ]
    ))]
    #[OA\Response(response: 404, description: '用户不存在', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'))]
    public function show(Request $request, int $id)
    {
        $user = User::with(['roles:id,name,description', 'allowedLocations'])->find($id);

        if (!$user) {
            return api_error('用户不存在', 404, 5001);
        }

        return api_success($user);
    }

    #[OA\Post(
        path: '/users',
        summary: '创建用户',
        security: [['bearerAuth' => []]],
        tags: ['用户管理'],
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['username', 'password'],
            properties: [
                new OA\Property(property: 'username', type: 'string', example: 'kid'),
                new OA\Property(property: 'password', type: 'string', example: '123456', description: '至少 6 位'),
                new OA\Property(property: 'email', type: 'string', example: 'kid@example.com'),
                new OA\Property(property: 'full_name', type: 'string', example: '小孩'),
                new OA\Property(property: 'role_ids', type: 'array', items: new OA\Items(type: 'integer'), example: [2]),
                new OA\Property(property: 'allowed_locations', type: 'array', items: new OA\Items(type: 'string'), example: ['儿童房'], description: '空数组=不限制'),
            ]
        )
    )]
    #[OA\Response(response: 201, description: '创建成功', content: new OA\JsonContent(
        properties: [
            new OA\Property(property: 'code', type: 'integer', example: 0),
            new OA\Property(property: 'data', ref: '#/components/schemas/User'),
        ]
    ))]
    #[OA\Response(response: 422, description: '参数验证失败', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'))]
    #[OA\Response(response: 409, description: '用户名或邮箱已存在', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'))]
    public function store(Request $request)
    {
        $data = $request->post();

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

        if (User::where('username', $data['username'])->exists()) {
            throw new BusinessException('用户名已存在', 409, 5002);
        }

        if (!empty($data['email']) && User::where('email', $data['email'])->exists()) {
            throw new BusinessException('邮箱已被使用', 409, 5003);
        }

        $user = new User();
        $user->username = $data['username'];
        $user->setPassword($data['password']);
        $user->email = $data['email'] ?? null;
        $user->full_name = $data['full_name'] ?? null;
        $user->is_active = $data['is_active'] ?? true;
        $user->save();

        if (!empty($data['role_ids'])) {
            $validRoleIds = Role::whereIn('id', $data['role_ids'])->pluck('id')->toArray();
            $user->roles()->sync($validRoleIds);
        }

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

    #[OA\Put(
        path: '/users/{id}',
        summary: '更新用户',
        security: [['bearerAuth' => []]],
        tags: ['用户管理'],
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\RequestBody(content: new OA\JsonContent(
        properties: [
            new OA\Property(property: 'email', type: 'string'),
            new OA\Property(property: 'full_name', type: 'string'),
            new OA\Property(property: 'password', type: 'string', description: '不传则不修改'),
            new OA\Property(property: 'is_active', type: 'boolean'),
            new OA\Property(property: 'role_ids', type: 'array', items: new OA\Items(type: 'integer')),
            new OA\Property(property: 'allowed_locations', type: 'array', items: new OA\Items(type: 'string')),
        ]
    ))]
    #[OA\Response(response: 200, description: '更新成功', content: new OA\JsonContent(
        properties: [
            new OA\Property(property: 'code', type: 'integer', example: 0),
            new OA\Property(property: 'data', ref: '#/components/schemas/User'),
        ]
    ))]
    #[OA\Response(response: 404, description: '用户不存在', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'))]
    public function update(Request $request, int $id)
    {
        $user = User::find($id);
        if (!$user) {
            return api_error('用户不存在', 404, 5001);
        }

        $data = $request->post();
        $original = $user->toArray();

        if (isset($data['email'])) {
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

        if (!empty($data['password'])) {
            if (strlen($data['password']) < 6) {
                return api_error('密码长度不能少于 6 位', 422, 1000);
            }
            $user->setPassword($data['password']);
        }

        $user->save();

        if (isset($data['role_ids'])) {
            $validRoleIds = Role::whereIn('id', $data['role_ids'])->pluck('id')->toArray();
            $user->roles()->sync($validRoleIds);
        }

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

    #[OA\Delete(
        path: '/users/{id}',
        summary: '删除用户',
        description: '删除指定用户。不允许删除自己的账号。',
        security: [['bearerAuth' => []]],
        tags: ['用户管理'],
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: '删除成功', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    #[OA\Response(response: 400, description: '不能删除自己', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'))]
    #[OA\Response(response: 404, description: '用户不存在', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'))]
    public function destroy(Request $request, int $id)
    {
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

    private static function syncLocations(int $userId, array $locations): void
    {
        UserAllowedLocation::where('user_id', $userId)->delete();

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
