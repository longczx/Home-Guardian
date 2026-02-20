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

class RoleController
{
    /**
     * 角色列表
     *
     * GET /api/roles
     */
    public function index(Request $request)
    {
        $roles = Role::all();
        return api_success($roles);
    }

    /**
     * 角色详情（包含拥有该角色的用户数量）
     *
     * GET /api/roles/{id}
     */
    public function show(Request $request, int $id)
    {
        $role = Role::withCount('users')->find($id);

        if (!$role) {
            return api_error('角色不存在', 404, 6001);
        }

        return api_success($role);
    }

    /**
     * 创建角色
     *
     * POST /api/roles
     * Body: {
     *   "name": "operator",
     *   "description": "运维人员",
     *   "permissions": {"devices": ["view", "control"], "alerts": ["view"]}
     * }
     */
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

    /**
     * 更新角色
     *
     * PUT /api/roles/{id}
     */
    public function update(Request $request, int $id)
    {
        $role = Role::find($id);
        if (!$role) {
            return api_error('角色不存在', 404, 6001);
        }

        $data = $request->post();
        $original = $role->toArray();

        // 如果修改名称，检查唯一性
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

    /**
     * 删除角色
     *
     * DELETE /api/roles/{id}
     *
     * 有用户关联的角色不允许删除。
     */
    public function destroy(Request $request, int $id)
    {
        $role = Role::withCount('users')->find($id);
        if (!$role) {
            return api_error('角色不存在', 404, 6001);
        }

        // 保护内置角色
        if (in_array($role->name, ['admin', 'member', 'guest'])) {
            return api_error('内置角色不允许删除', 400, 6003);
        }

        // 检查是否有用户关联
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
