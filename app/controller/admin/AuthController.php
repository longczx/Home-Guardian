<?php
/**
 * Home Guardian - Admin 认证控制器
 *
 * 处理后台管理面板的登录/登出逻辑（session 认证）。
 *
 *   GET  /admin/login  — 渲染登录页
 *   POST /admin/login  — 处理登录
 *   GET  /admin/logout — 处理登出
 */

namespace app\controller\admin;

use app\model\User;
use support\Request;

class AuthController
{
    /**
     * 渲染登录页
     */
    public function loginPage(Request $request)
    {
        // 已登录则跳转到后台首页
        if ($request->session()->get('admin_user')) {
            return redirect('/admin/dashboard');
        }

        return view('admin/login', ['error' => '']);
    }

    /**
     * 处理登录
     */
    public function login(Request $request)
    {
        $username = $request->post('username', '');
        $password = $request->post('password', '');

        if (empty($username) || empty($password)) {
            return view('admin/login', ['error' => '用户名和密码不能为空']);
        }

        $user = User::where('username', $username)->where('is_active', true)->first();

        if (!$user || !$user->verifyPassword($password)) {
            return view('admin/login', ['error' => '用户名或密码错误']);
        }

        // 检查是否有 admin 权限
        $permissions = $user->getMergedPermissions();
        if (empty($permissions['admin'])) {
            return view('admin/login', ['error' => '该账号没有管理员权限']);
        }

        // 写入 session
        $request->session()->set('admin_user', [
            'id'       => $user->id,
            'username' => $user->username,
            'fullName' => $user->full_name ?: $user->username,
        ]);

        return redirect('/admin/dashboard');
    }

    /**
     * 登出
     */
    public function logout(Request $request)
    {
        $request->session()->delete('admin_user');
        return redirect('/admin/login');
    }
}
