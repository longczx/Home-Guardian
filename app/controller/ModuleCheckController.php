<?php
namespace app\controller;
use app\model\Device;
use app\service\ObservationService;
use support\Request;
class ModuleCheckController {
    public function show(Request $r,int $id) {
        $gateway=Device::withoutGlobalScopes()->where('home_id',(int)$r->user->home_id)->find($id);
        if (!$gateway || $gateway->type !== 'gateway' || !$r->canAccessLocation($gateway->location)) return api_error('网关不存在或无权访问',404,2001);
        $uid=$r->get('uid');
        if (!is_string($uid) || strlen($uid)>64) return api_error('模块编号无效',422,1000);
        $child=Device::withoutGlobalScopes()->where('home_id',$gateway->home_id)->where('gateway_uid',$gateway->device_uid)->where('device_uid',$uid)->first();
        if (!$child || !$r->canAccessLocation($child->location)) return api_success(['registered'=>false,'server_time'=>now()->toIso8601String(),'message'=>'尚未收到模块注册，请稍后刷新网关配置']);
        $readings=[]; $fresh=true;
        foreach($child->metric_fields ?? [] as $field) {
            $reading=ObservationService::latest($child->id,$field['key']);
            $valid=$reading && (is_int($reading['value']) || is_float($reading['value'])) && is_finite((float)$reading['value']);
            $isFresh=$valid && ObservationService::fresh($reading,max(30,(int)$child->report_interval_sec*3));
            $fresh=$fresh && $isFresh;
            $readings[]=['key'=>$field['key'],'label'=>$field['label'] ?? $field['key'],'unit'=>$field['unit'] ?? '',
                'value'=>$reading['value'] ?? null,'ts'=>$reading['ts'] ?? null,'valid'=>(bool)$valid,'fresh'=>(bool)$isFresh];
        }
        return api_success(['registered'=>true,'server_time'=>now()->toIso8601String(),'device_id'=>$child->id,'is_online'=>(bool)$child->is_online,
            'gateway_online'=>(bool)$gateway->is_online,'report_interval_sec'=>$child->report_interval_sec,'readings'=>$readings,
            'sensor_ready'=>$fresh && count($readings)>0 && $gateway->is_online && $child->is_online,
            'capability'=>$child->capability,'infrared_unverified'=>$child->type === 'ac']);
    }
}
