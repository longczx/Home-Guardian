<?php
/**
 * Home Guardian - 能力模板控制器（App 端只读）
 *
 *   GET /api/capability-templates — 全部模板列表
 *
 * 供 App 在设备编辑页给执行器挑选控制能力（决定详情页渲染哪些控件）。
 * 模板的增删改仍只在 Admin 后台进行，故此处只暴露读接口。
 */

namespace app\controller;

use app\model\CapabilityTemplate;
use support\Request;

class CapabilityTemplateController
{
    /**
     * 能力模板列表
     */
    public function index(Request $request)
    {
        $templates = CapabilityTemplate::orderBy('name')
            ->get(['id', 'name', 'device_category', 'control_mode', 'controls', 'description']);

        return api_success($templates);
    }
}
