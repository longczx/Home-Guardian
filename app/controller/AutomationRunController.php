<?php
namespace app\controller;

use app\model\AutomationRun;
use app\service\AutomationRunService;
use support\Request;

class AutomationRunController
{
    public function index(Request $request)
    {
        $query = AutomationRun::where('home_id', (int)$request->user->home_id);
        if ($id = $request->get('automation_id')) $query->where('automation_id', (int)$id);
        $locations = $request->user->locations ?? [];
        if ($locations) $query->whereRaw('locations::jsonb <@ ?::jsonb', [json_encode(array_values($locations))]);
        $page = $query->orderByDesc('id')->paginate(max(1, min(100, (int)$request->get('per_page', 20))));
        foreach ($page->items() as $run) AutomationRunService::refresh($run);
        return api_paginate($page);
    }
}
