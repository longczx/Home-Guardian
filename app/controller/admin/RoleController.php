<?php
/**
 * Home Guardian - Admin 角色管理控制器
 */

namespace app\controller\admin;

use app\model\Role;
use support\Request;

class RoleController
{
    public function index(Request $request)
    {
        $roles = Role::withCount('users')->get();

        return view('admin/role/list', [
            'roles'     => $roles,
            'nav'       => 'roles',
            'adminUser' => $request->adminUser,
        ]);
    }

    public function create(Request $request)
    {
        return view('admin/role/form', [
            'role'      => null,
            'nav'       => 'roles',
            'adminUser' => $request->adminUser,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->post();

        if (empty($data['name'])) {
            return view('admin/role/form', [
                'role'      => null,
                'error'     => '角色名称不能为空',
                'old'       => $data,
                'nav'       => 'roles',
                'adminUser' => $request->adminUser,
            ]);
        }

        if (Role::where('name', $data['name'])->exists()) {
            return view('admin/role/form', [
                'role'      => null,
                'error'     => '角色名已存在',
                'old'       => $data,
                'nav'       => 'roles',
                'adminUser' => $request->adminUser,
            ]);
        }

        $permissions = [];
        if (!empty($data['permissions_json'])) {
            $permissions = json_decode($data['permissions_json'], true) ?: [];
        }

        Role::create([
            'name'        => $data['name'],
            'description' => $data['description'] ?? null,
            'permissions' => $permissions,
        ]);

        return redirect('/admin/roles');
    }

    public function edit(Request $request, int $id)
    {
        $role = Role::find($id);
        if (!$role) {
            return redirect('/admin/roles');
        }

        return view('admin/role/form', [
            'role'      => $role,
            'nav'       => 'roles',
            'adminUser' => $request->adminUser,
        ]);
    }

    public function update(Request $request, int $id)
    {
        $role = Role::find($id);
        if (!$role) {
            return redirect('/admin/roles');
        }

        $data = $request->post();

        if (isset($data['name']) && $data['name'] !== $role->name) {
            if (Role::where('name', $data['name'])->exists()) {
                return view('admin/role/form', [
                    'role'      => $role,
                    'error'     => '角色名已存在',
                    'nav'       => 'roles',
                    'adminUser' => $request->adminUser,
                ]);
            }
        }

        $updateData = [];
        if (isset($data['name'])) $updateData['name'] = $data['name'];
        if (isset($data['description'])) $updateData['description'] = $data['description'];
        if (isset($data['permissions_json'])) {
            $updateData['permissions'] = json_decode($data['permissions_json'], true) ?: [];
        }

        $role->update($updateData);

        return redirect('/admin/roles');
    }

    public function delete(Request $request, int $id)
    {
        $role = Role::withCount('users')->find($id);
        if (!$role) {
            return redirect('/admin/roles');
        }

        if (in_array($role->name, ['admin', 'member', 'guest'])) {
            return redirect('/admin/roles');
        }

        if ($role->users_count > 0) {
            return redirect('/admin/roles');
        }

        $role->delete();
        return redirect('/admin/roles');
    }
}
