<?php
namespace Tests\Integration;

use app\model\{Device, ProvisionCode, Home, AlertRule, AlertLog, NotificationChannel, NotificationDelivery, CommandLog, Automation};
use app\service\{ProvisioningService, AlertService, CommandOutboxService, MqttCommandService, HealthService};
use app\controller\HomeExperienceController;
use app\exception\BusinessException;
use PHPUnit\Framework\TestCase;
use support\{Redis, Request};

class ProductHardeningTest extends TestCase
{
    private array $devices = [];
    private array $codes = [];
    private function code(?string $rebind = null): ProvisionCode
    {
        $c = ProvisioningService::createCode(1, 'bedroom', $rebind); $this->codes[] = $c->id; return $c;
    }
    private function device(array $attrs = []): Device
    {
        $d = Device::create($attrs + ['device_uid'=>'hardening-'.bin2hex(random_bytes(6)), 'name'=>'test', 'type'=>'gateway', 'home_id'=>1, 'location'=>'bedroom', 'is_online'=>true]);
        $this->devices[] = $d->id; return $d;
    }
    protected function tearDown(): void
    {
        $db = (new Device)->getConnection();
        $ids = CommandLog::whereIn('device_id', $this->devices)->pluck('request_id');
        $db->table('command_outbox')->whereIn('request_id', $ids)->delete();
        CommandLog::whereIn('device_id', $this->devices)->delete();
        Device::withoutGlobalScopes()->whereIn('id', $this->devices)->delete();
        ProvisionCode::withoutGlobalScopes()->whereIn('id', $this->codes)->delete();
    }
    public function test_generic_code_cannot_rebind_an_existing_gateway_even_in_same_home(): void
    {
        $d = $this->device(['mqtt_password_hash'=>password_hash('original', PASSWORD_BCRYPT)]);
        $code = $this->code();
        try { ProvisioningService::register(['provision_code'=>$code->code, 'gateway'=>['device_uid'=>$d->device_uid]]); self::fail('Existing device was rebound'); }
        catch (BusinessException $e) { self::assertSame(9009, $e->getBusinessCode()); }
        self::assertTrue(password_verify('original', $d->fresh()->mqtt_password_hash));
        self::assertSame('pending', $code->fresh()->status);
    }
    public function test_even_a_targeted_code_cannot_move_a_foreign_gateway_or_child(): void
    {
        $d = $this->device(['home_id'=>42]); $code = $this->code($d->device_uid);
        try { ProvisioningService::register(['provision_code'=>$code->code,'gateway'=>['device_uid'=>$d->device_uid]]); self::fail('Foreign gateway was moved'); }
        catch (BusinessException $e) { self::assertSame(9009, $e->getBusinessCode()); }
        self::assertSame(42, (int)$d->fresh()->home_id);
        $child = $this->device(['type'=>'sensor', 'gateway_uid'=>'another-gateway']); $newUid='new-'.bin2hex(random_bytes(4));
        try { ProvisioningService::register(['provision_code'=>$this->code()->code,'gateway'=>['device_uid'=>$newUid], 'sensors'=>[['device_uid'=>$child->device_uid]]]); self::fail('Foreign child was moved'); }
        catch (BusinessException $e) { self::assertSame(9009, $e->getBusinessCode()); }
        self::assertFalse(Device::where('device_uid', $newUid)->exists());
        self::assertSame('another-gateway', $child->fresh()->gateway_uid);
    }
    public function test_lost_registration_response_is_recoverable_only_with_original_device_token(): void
    {
        $uid='retry-'.bin2hex(random_bytes(4)); $code=$this->code();
        $payload=['provision_code'=>$code->code,'registration_token'=>bin2hex(random_bytes(32)),'gateway'=>['device_uid'=>$uid]];
        $first=ProvisioningService::register($payload); $this->devices[]=$first['devices'][0]['id'];
        self::assertSame($first['mqtt'], ProvisioningService::register($payload)['mqtt']);
        self::assertSame(1, Device::where('device_uid',$uid)->count());
        $payload['registration_token']=bin2hex(random_bytes(32));
        $this->expectException(BusinessException::class); ProvisioningService::register($payload);
    }
    public function test_registration_is_not_readiness_and_requires_fresh_connection_and_sensor_data(): void
    {
        $code=$this->code();$uid='stage-'.bin2hex(random_bytes(4));
        $result=ProvisioningService::register(['provision_code'=>$code->code,'gateway'=>['device_uid'=>$uid], 'sensors'=>[['device_uid'=>$uid.'-temp']]]);
        $this->devices=array_merge($this->devices, array_column($result['devices'],'id'));
        self::assertSame('connecting', ProvisioningService::statusOf($code->code,1)['stage']);
        Device::where('device_uid',$uid)->update(['is_online'=>true,'last_seen'=>now()]);
        self::assertSame('awaiting_data', ProvisioningService::statusOf($code->code,1)['stage']);
        $db=(new Device)->getConnection();$id=$result['devices'][1]['id'];
        $db->table('telemetry_logs')->insert(['device_id'=>$id,'metric_key'=>'temp','value'=>'24','ts'=>now()]);
        self::assertSame('ready', ProvisioningService::statusOf($code->code,1)['stage']);
        $db->table('telemetry_logs')->where('device_id',$id)->delete();
    }
    public function test_command_commit_without_queue_delivery_is_recovered_and_expired_commands_are_not_replayed(): void
    {
        if (!getenv('TEST_REDIS_HOST')) $this->markTestSkipped('Requires isolated Redis');
        $d=$this->device();$log=MqttCommandService::sendCommand($d->id,['action'=>'get_info']);
        $db=(new Device)->getConnection();$redis=Redis::connection('queue');
        // Simulate the committed outbox row before delivery, after a worker restart.
        $redis->del('mqtt:command:send');
        $db->table('command_outbox')->where('request_id',$log->request_id)->update(['dispatched_at'=>null]);
        CommandOutboxService::dispatch($log->request_id);
        self::assertSame($log->request_id,json_decode($redis->rPop('mqtt:command:send'),true)['request_id']);
        $log->update(['sent_at'=>now()->subMinutes(2)]);
        $db->table('command_outbox')->where('request_id',$log->request_id)->update(['dispatched_at'=>null]);
        CommandOutboxService::dispatch($log->request_id);
        self::assertSame('timeout',$log->fresh()->status); self::assertSame(0,$redis->lLen('mqtt:command:send'));
    }
    public function test_alert_and_delivery_commit_together_and_cache_loss_does_not_duplicate_incident(): void
    {
        if (!getenv('TEST_REDIS_HOST')) $this->markTestSkipped('Requires isolated Redis');
        $d=$this->device();$channel=NotificationChannel::create(['name'=>'outbox','type'=>'webhook','config'=>[]]);
        $rule=new AlertRule(['home_id'=>1,'name'=>'test','notification_channel_ids'=>[$channel->id],'telemetry_key'=>'temp']);$rule->id=932222;
        try {
            $first=AlertService::triggerAlert($rule,$d->id,40);
            self::assertNotNull($first);
            self::assertSame(1,NotificationDelivery::where('extra->alert_id',$first->id)->count());
            Redis::connection('default')->del("alert:active:{$rule->id}:{$d->id}");
            self::assertNull(AlertService::triggerAlert($rule,$d->id,40));
            AlertService::resolveActive($rule,$d->id,20);
            self::assertNotNull($first->fresh()->recovered_at);
            self::assertNotNull(AlertService::triggerAlert($rule,$d->id,40));
        } finally {
            NotificationDelivery::where('channel_id',$channel->id)->delete();$channel->delete();
            AlertLog::where('device_id',$d->id)->delete();
        }
    }
    public function test_draft_preview_is_read_only_and_checks_location_permissions(): void
    {
        if (!getenv('TEST_REDIS_HOST')) $this->markTestSkipped('Requires isolated Redis');
        $home=Home::create(['name'=>'draft']);$d=$this->device(['home_id'=>$home->id]);
        $body=['name'=>'unsaved','trigger_type'=>'telemetry','trigger_config'=>['device_id'=>$d->id,'metric_key'=>'temp','condition'=>'GREATER_THAN','value'=>25],
            'actions'=>[['type'=>'device_command','device_id'=>$d->id,'payload'=>['action'=>'power','params'=>['on'=>true]]]]];
        $d->update(['capability'=>['controls'=>[['command'=>'power','param'=>'on','value_type'=>'bool']]]]);
        $json=json_encode($body);$req=new Request("POST / HTTP/1.1\r\nHost: localhost\r\nContent-Type: application/json\r\nContent-Length: ".strlen($json)."\r\n\r\n".$json);
        $req->user=(object)['id'=>1,'home_id'=>$home->id,'locations'=>['bedroom'], 'permissions'=>(object)[]];
        $count=Automation::count();
        self::assertSame(200,(new HomeExperienceController)->previewDraft($req)->getStatusCode());
        self::assertSame($count,Automation::count());self::assertSame(0,CommandLog::where('device_id',$d->id)->count());
        $req->user->locations=['kitchen'];
        self::assertSame(403,(new HomeExperienceController)->previewDraft($req)->getStatusCode());$home->delete();
    }
    public function test_notification_persistence_failure_rolls_back_alert_and_leaves_event_reserved(): void
    {
        if (!getenv('TEST_REDIS_HOST')) $this->markTestSkipped('Requires isolated Redis');
        $d=$this->device();$channel=NotificationChannel::create(['name'=>'fault','type'=>'webhook','config'=>[]]);
        $rule=new AlertRule(['home_id'=>1,'name'=>'fault','device_id'=>$d->id,'condition'=>'GREATER_THAN','threshold_value'=>[30],'notification_channel_ids'=>[$channel->id],'telemetry_key'=>'temp']);$rule->id=932223;
        $db=(new Device)->getConnection();
        $db->getSchemaBuilder()->rename('notification_deliveries','notification_deliveries_unavailable');
        $r=new \Redis();$r->connect(getenv('TEST_REDIS_HOST'));$r->select(1);
        $raw=json_encode(['device_id'=>$d->id,'metric_key'=>'temp','value'=>40,'ts'=>now()->toIso8601String()]);
        $r->lPush('hg:q:alert_stream',$raw);
        $engine=new \app\process\AlertEngineProcess();
        (new \ReflectionProperty($engine,'redis'))->setValue($engine,$r);
        $cache=new \Redis();$cache->connect(getenv('TEST_REDIS_HOST'));
        (new \ReflectionProperty($engine,'redisCache'))->setValue($engine,$cache);
        (new \ReflectionProperty($engine,'rules'))->setValue($engine,[$rule]);
        try {
            $engine->processAlertStream();
            self::assertSame(0,AlertLog::where('device_id',$d->id)->count());
            self::assertContains($raw,$r->lRange('hg:q:alert_processing',0,-1));
        } finally { $db->getSchemaBuilder()->rename('notification_deliveries_unavailable','notification_deliveries'); }
        try {
            // Retry the persisted reservation with a fresh worker after the DB recovers.
            $next=new \app\process\AlertEngineProcess();
            (new \ReflectionProperty($next,'redis'))->setValue($next,$r);
            (new \ReflectionProperty($next,'redisCache'))->setValue($next,$cache);
            (new \ReflectionProperty($next,'rules'))->setValue($next,[$rule]);
            $next->processAlertStream();
            self::assertSame(1,AlertLog::where('device_id',$d->id)->count());
            self::assertSame(1,NotificationDelivery::where('channel_id',$channel->id)->count());
            self::assertNotContains($raw,$r->lRange('hg:q:alert_processing',0,-1));
        } finally {
            AlertLog::where('device_id',$d->id)->delete();NotificationDelivery::where('channel_id',$channel->id)->delete();$channel->delete();
            $r->lRem('hg:q:alert_processing',$raw,0);$r->lRem('hg:q:alert_stream',$raw,0);
        }
    }
    public function test_missing_or_unready_worker_prevents_readiness_and_stale_queues_are_visible(): void
    {
        if (!getenv('TEST_REDIS_HOST')) $this->markTestSkipped('Requires isolated Redis');
        $r=Redis::connection('default');
        foreach (HealthService::WORKERS as $name) $r->setex('health:worker:'.$name,30,json_encode(['at'=>time(),'ready'=>true]));
        $r->setex('health:worker:mqtt_subscriber',30,json_encode(['at'=>time(),'ready'=>false]));
        $health=HealthService::snapshot();self::assertFalse($health['ready']);self::assertFalse($health['checks']['mqtt_subscriber']);
        $r->del('health:worker:alert_engine');self::assertFalse(HealthService::snapshot()['checks']['alert_engine']);
        foreach (HealthService::WORKERS as $name) $r->del('health:worker:'.$name);
    }
    public function test_system_health_template_renders_worker_and_queue_names(): void
    {
        $view=new \think\Template(array_merge(config('view.options'), [
            'view_path'=>dirname(__DIR__,2).'/app/view/', 'cache_path'=>sys_get_temp_dir().'/hg-template-check/',
        ]));
        ob_start();
        try {
            $view->fetch('admin/system/index', [
                'services'=>[], 'packages'=>[], 'phpVersion'=>PHP_VERSION, 'loadedExts'=>[], 'checkExts'=>[],
                'nav'=>'system','adminUser'=>['username'=>'test','fullName'=>'test'],
                'health'=>['status'=>'degraded'],
                'healthChecks'=>[['name'=>'mqtt_subscriber','ok'=>false]],
                'healthQueues'=>[['name'=>'alert_processing','queue'=>['count'=>3,'oldest_age_sec'=>30]]],
                'healthMetrics'=>[['name'=>'command_timeout_rate_1h','value'=>0.25]],
            ]);
            $html=ob_get_contents();
        } finally { ob_end_clean(); }
        self::assertStringContainsString('mqtt_subscriber',$html);
        self::assertStringContainsString('alert_processing',$html);
        self::assertStringContainsString('command_timeout_rate_1h',$html);
        self::assertStringContainsString('异常或未就绪',$html);
    }
}
