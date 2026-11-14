<?php
namespace app\controller;
use app\model\{Scene, SceneRun, Home};
use app\service\{SceneService, AutomationRunService, AuditService};
use app\exception\BusinessException;
use support\Request;
class SceneController {
    public static function home(Request $r): int {
        if (!in_array($r->user->home_role ?? '', ['owner','admin'], true) || !empty($r->user->locations)) throw new BusinessException('全屋场景需要管理员及全部房间权限', 403, 1004);
        return (int)$r->user->home_id;
    }
    public function index(Request $r) { return api_success(['items'=>Scene::withoutGlobalScopes()->where('home_id', self::home($r))->orderBy('id')->get()]); }
    public function save(Request $r) {
        $home = self::home($r); $name = $r->post('name'); $actions = $r->post('actions'); $mode = $r->post('enter_mode'); $id = $r->post('id');
        if (!is_string($name) || trim($name) === '' || mb_strlen($name)>100 || !is_array($actions) || ($mode !== null && !in_array($mode,['home','away','sleep'],true)) || ($id !== null && !is_int($id))) return api_error('场景名称、模式或动作格式无效', 422, 1000);
        $db = (new Scene)->getConnection();
        $scene = $db->transaction(function () use ($home,$name,$actions,$mode,$id) {
            Home::where('id',$home)->lockForUpdate()->first();
            SceneService::validate($home,$actions);
            $scene = $id ? Scene::withoutGlobalScopes()->where('home_id',$home)->find($id) : new Scene(['home_id'=>$home]);
            if (!$scene) throw new BusinessException('场景不存在',404,4001);
            if ($mode && Scene::withoutGlobalScopes()->where('home_id',$home)->where('enter_mode',$mode)->when($id,fn($q)=>$q->where('id','!=',$id))->exists()) throw new BusinessException('该模式已绑定其他场景，请先解除原绑定',409,4002);
            $scene->fill(['name'=>trim($name),'actions'=>$actions,'enter_mode'=>$mode]); $scene->save(); return $scene;
        });
        AuditService::log($r,'update','scene',$scene->id); return api_success($scene);
    }
    public function remove(Request $r,int $id) {
        $home=self::home($r); $db=(new Scene)->getConnection();
        $db->transaction(function() use($home,$id) { Home::where('id',$home)->lockForUpdate()->first(); Scene::withoutGlobalScopes()->where('home_id',$home)->where('id',$id)->delete(); });
        AuditService::log($r,'delete','scene',$id); return api_success(null);
    }
    public function execute(Request $r,int $id) {
        $home=self::home($r); $key=$r->post('request_key');
        if (!is_string($key)) return api_error('缺少执行标识',422,1000);
        $run=SceneService::execute($home,$id,$key); AuditService::log($r,'execute','scene',$id,['run_id'=>$run->id]); return api_success($run);
    }
    public function runs(Request $r) {
        $home=self::home($r); $query=SceneRun::withoutGlobalScopes()->where('home_id',$home);
        if ($id=$r->get('id')) $query->where('id',(int)$id);
        if ($key=$r->get('request_key')) $query->where('request_key',$key);
        $page=$query->orderByDesc('id')->paginate(20);
        foreach($page->items() as $run) AutomationRunService::refresh($run);
        return api_paginate($page);
    }
}
