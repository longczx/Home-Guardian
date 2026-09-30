<?php

namespace app\controller;

use app\model\NotificationDelivery;
use support\Request;

class NotificationDeliveryController
{
    public function index(Request $request)
    {
        $query = NotificationDelivery::query();
        if ($status = $request->get('status')) $query->where('status', $status);
        $page = $query->orderByDesc('id')->paginate(max(1, min(100, (int)$request->get('per_page', 20))));
        return api_success(['items' => $page->items(), 'total' => $page->total()]);
    }
    public function retry(Request $request, int $id)
    {
        $updated = NotificationDelivery::where('id', $id)->where('status', 'failed')
            ->update(['status' => 'pending', 'attempts' => 0, 'last_error' => null, 'next_attempt_at' => now()]);
        if (!$updated) return api_error('记录不存在或不是失败状态', 409, 9001);
        return api_success(null, '已安排重试');
    }
}
