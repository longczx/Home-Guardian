<?php
/**
 * Home Guardian - Admin 用户管理控制器
 */

namespace app\controller\admin;

use app\model\User;
use app\model\Role;
use app\model\UserAllowedLocation;
use support\Request;

class UserController
{
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

        $perPage = min((int)($request->get('per_page', 20)), 100);
        $users = $query->orderBy('id', 'asc')->paginate($perPage);

        return view('admin/user/list', [
            'users'     => $users,
            'filters'   => $request->get(),
            'nav'       => 'users',
            'adminUser' => $request->adminUser,
        ]);
    }

    public function create(Request $request)
    {
        $roles = Role::all();
        return view('admin/user/form', [
            'user'      => null,
            'roles'     => $roles,
            'nav'       => 'users',
            'adminUser' => $request->adminUser,
        ]);
    }

    public function store(Request $request)
    {
        $data  = $request->post();
        $roles = Role::all();

        if (empty($data['username']) || empty($data['password'])) {
            return view('admin/user/form', [
                'user'      => null,
                'roles'     => $roles,
                'error'     => '用户名和密码不能为空',
                'old'       => $data,
                'nav'       => 'users',
                'adminUser' => $request->adminUser,
            ]);
        }

        if (User::where('username', $data['username'])->exists()) {
            return view('admin/user/form', [
                'user'      => null,
                'roles'     => $roles,
                'error'     => '用户名已存在',
                'old'       => $data,
                'nav'       => 'users',
                'adminUser' => $request->adminUser,
            ]);
        }

        $user = new User();
        $user->username = $data['username'];
        $user->setPassword($data['password']);
        $user->email     = $data['email'] ?? null;
        $user->full_name = $data['full_name'] ?? null;
        $user->is_active = isset($data['is_active']);
        $user->save();

        if (!empty($data['role_ids'])) {
            $user->roles()->sync($data['role_ids']);
        }

        if (!empty($data['allowed_locations'])) {
            $locations = array_filter(array_map('trim', explode("\n", $data['allowed_locations'])));
            $this->syncLocations($user->id, $locations);
        }

        return redirect('/admin/users');
    }

    public function edit(Request $request, int $id)
    {
        $user = User::with(['roles', 'allowedLocations'])->find($id);
        if (!$user) {
            return redirect('/admin/users');
        }

        $roles = Role::all();
        return view('admin/user/form', [
            'user'      => $user,
            'roles'     => $roles,
            'nav'       => 'users',
            'adminUser' => $request->adminUser,
        ]);
    }

    public function update(Request $request, int $id)
    {
        $user = User::find($id);
        if (!$user) {
            return redirect('/admin/users');
        }

        $data = $request->post();

        if (isset($data['email'])) {
            $user->email = $data['email'] ?: null;
        }
        if (isset($data['full_name'])) {
            $user->full_name = $data['full_name'] ?: null;
        }
        $user->is_active = isset($data['is_active']);

        if (!empty($data['password'])) {
            $user->setPassword($data['password']);
        }

        $user->save();

        if (isset($data['role_ids'])) {
            $user->roles()->sync($data['role_ids']);
        } else {
            $user->roles()->sync([]);
        }

        $locations = [];
        if (!empty($data['allowed_locations'])) {
            $locations = array_filter(array_map('trim', explode("\n", $data['allowed_locations'])));
        }
        $this->syncLocations($user->id, $locations);

        return redirect('/admin/users');
    }

    public function delete(Request $request, int $id)
    {
        if ($request->adminUser['id'] == $id) {
            return redirect('/admin/users');
        }

        $user = User::find($id);
        if ($user) {
            $user->delete();
        }
        return redirect('/admin/users');
    }

    private function syncLocations(int $userId, array $locations): void
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
