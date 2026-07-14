<?php
/**
 * Home Guardian - Admin 移动端接入
 *
 * 展示 uni-app 客户端连接本服务器所需的配置：
 *   - 服务器地址（从请求 Host 自动推断，可手动改）
 *   - hg://server 深链（App「添加服务器」扫码/手动粘贴用）
 *   - 接入步骤说明
 */

namespace app\controller\admin;

use support\Request;

class AppAccessController
{
    public function index(Request $request)
    {
        // 自动推断对外地址：优先反代传入的 X-Forwarded-*，回退 Host
        $scheme = $request->header('x-forwarded-proto') ?: ($request->header('x-forwarded-ssl') === 'on' ? 'https' : 'http');
        $host   = $request->header('x-forwarded-host') ?: $request->header('host', 'localhost');
        // 管理后台经 nginx 一般在 80/443；开发直连时端口为 8787
        $guessUrl = rtrim($scheme . '://' . $host, '/');

        return view('admin/app-access/index', [
            'guessUrl'  => $guessUrl,
            'nav'       => 'app-access',
            'adminUser' => $request->adminUser,
        ]);
    }
}
