<?php
/**
 * Home Guardian - 首页控制器
 *
 * 处理根路径请求，返回 API 概览信息。
 * 在单页应用（SPA）部署后，此路由可改为返回 index.html。
 */

namespace app\controller;

use support\Request;

class IndexController
{
    /**
     * API 概览
     *
     * GET /
     */
    public function index(Request $request)
    {
        return api_success([
            'name'    => 'Home Guardian API',
            'version' => '1.0.0',
            'docs'    => '/api/health',
        ], 'Home Guardian 家庭守护者 IoT 平台');
    }
}
