<?php
/**
 * Home Guardian - 角色管理控制器
 *
 * 角色的 CRUD API，仅 admin 可操作。
 *
 *   GET    /api/roles          — 角色列表
 *   GET    /api/roles/{id}     — 角色详情
 *   POST   /api/roles          — 创建角色
 *   PUT    /api/roles/{id}     — 更新角色
 *   DELETE /api/roles/{id}     — 删除角色
 */

namespace app\controller;

use app\model\Role;
use app\service\AuditService;
use app\exception\BusinessException;
use support\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: '角色管理', description: '角色的 CRUD')]
class RoleController
{
    #[OA\Get(
        path: '/roles',
        summary: '角色列表',
        description: '获取所有角色。不分页。',
        security: [['bearerAuth' => []]],
        tags: ['角色管理'],
    )]
    #[OA\Response(response: 200, description: '成功', content: new OA\JsonContent(
        properties: [
            new OA\Property(property: 'code', type: 'integer', example: 0),
            new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Role')),
        ]
    ))]
    public function index(Request $request)
    {
        $roles = Role::all();
        return api_success($roles);
    }

    #[OA\Get(
        path: '/roles/{id}',
        summary: '角色详情',
        description: '获取角色详细信息，包含拥有该角色的用户数量。',
        security: [['bearerAuth' => []]],
        tags: ['角色管理'],
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: '成功', content: new OA\JsonContent(
        properties: [
            new OA\Property(property: 'code', type: 'integer', example: 0),
            new OA\Property(property: 'data', ref: '#/components/schemas/Role'),
        ]
    ))]
    #[OA\Response(response: 404, description: '角色不存在', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'))]
    public function show(Request $request, int $id)
    {
        $role = Role::withCount('users')->find($id);

        if (!$role) {
            return api_error('角色不存在', 404, 6001);
        }

        return api_success($role);
    }

    #[OA\Post(
        path: '/roles',
        summary: '创建角色',
        security: [['bearerAuth' => []]],
        tags: ['角色管理'],
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['name'],
            properties: [
                new OA\Property(property: 'name', type: 'string', example: 'operator'),
                new OA\Property(property: 'description', type: 'string', example: '运维人员'),
                new OA\Property(property: 'permissions', type: 'object', example: ['devices' => ['view', 'control'], 'alerts' => ['view']]),
            ]
        )
    )]
    #[OA\Response(response: 201, description: '创建成功', content: new OA\JsonContent(
        properties: [
            new OA\Property(property: 'code', type: 'integer', example: 0),
            new OA\Property(property: 'data', ref: '#/components/schemas/Role'),
        ]
    ))]
    #[OA\Response(response: 409, description: '角色名已存在', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'))]
    public function store(Request $request)
    {
        $data = $request->post();

        if (empty($data['name'])) {
            return api_error('角色名称不能为空', 422, 1000);
        }

        if (Role::where('name', $data['name'])->exists()) {
            throw new BusinessException('角色名已存在', 409, 6002);
        }

        $role = Role::create([
            'name'        => $data['name'],
            'description' => $data['description'] ?? null,
            'permissions' => $data['permissions'] ?? [],
        ]);

        AuditService::log($request, 'create', 'role', $role->id, [
            'name' => $role->name,
        ]);

        return api_success($role, '角色创建成功', 201);
    }

    #[OA\Put(
        path: '/roles/{id}',
        summary: '更新角色',
        security: [['bearerAuth' => []]],
        tags: ['角色管理'],
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\RequestBody(content: new OA\JsonContent(
        properties: [
            new OA\Property(property: 'name', type: 'string'),
            new OA\Property(property: 'description', type: 'string'),
            new OA\Property(property: 'permissions', type: 'object'),
        ]
    ))]
    #[OA\Response(response: 200, description: '更新成功', content: new OA\JsonContent(
        properties: [
            new OA\Property(property: 'code', type: 'integer', example: 0),
            new OA\Property(property: 'data', ref: '#/components/schemas/Role'),
        ]
    ))]
    #[OA\Response(response: 404, description: '角色不存在', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'))]
    #[OA\Response(response: 409, description: '角色名已存在', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'))]
    public function update(Request $request, int $id)
    {
        $role = Role::find($id);
        if (!$role) {
            return api_error('角色不存在', 404, 6001);
        }

        $data = $request->post();
        $original = $role->toArray();

        if (isset($data['name']) && $data['name'] !== $role->name) {
            if (Role::where('name', $data['name'])->exists()) {
                throw new BusinessException('角色名已存在', 409, 6002);
            }
        }

        $role->update(array_filter([
            'name'        => $data['name'] ?? null,
            'description' => $data['description'] ?? null,
            'permissions' => $data['permissions'] ?? null,
        ], fn($v) => $v !== null));

        AuditService::log($request, 'update', 'role', $id,
            AuditService::diffChanges($original, $role->fresh()->toArray())
        );

        return api_success($role->fresh(), '角色更新成功');
    }

    #[OA\Delete(
        path: '/roles/{id}',
        summary: '删除角色',
        description: '删除角色。内置角色（admin/member/guest）和有用户关联的角色不允许删除。',
        security: [['bearerAuth' => []]],
        tags: ['角色管理'],
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: '删除成功', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    #[OA\Response(response: 400, description: '内置角色不可删除或有用户关联', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'))]
    #[OA\Response(response: 404, description: '角色不存在', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'))]
    public function destroy(Request $request, int $id)
    {
        $role = Role::withCount('users')->find($id);
        if (!$role) {
            return api_error('角色不存在', 404, 6001);
        }

        if (in_array($role->name, ['admin', 'member', 'guest'])) {
            return api_error('内置角色不允许删除', 400, 6003);
        }

        if ($role->users_count > 0) {
            return api_error("该角色下还有 {$role->users_count} 个用户，不能删除", 400, 6004);
        }

        $roleName = $role->name;
        $role->delete();

        AuditService::log($request, 'delete', 'role', $id, [
            'name' => $roleName,
        ]);

        return api_success(null, '角色已删除');
    }
}
