<?php
namespace Tests\Integration;
require_once __DIR__ . '/../../app/functions.php';
use app\model\{Home, Device, Automation, AutomationRun, CommandLog, DeviceState, AlertLog, AlertRule};
use app\service\{AutomationService, AutomationPolicyService, DeviceControlService, ActuatorService, ObservationService, DevicePresentationService, AlertService};
use app\controller\HomeExperienceController;
use app\exception\BusinessException;
use PHPUnit\Framework\TestCase;
use support\{Redis, Request};
class HomeExperienceTest extends TestCase
{
    private Home $home;
    protected function setUp(): void
    {
        if (!getenv('TEST_REDIS_HOST')) $this->markTestSkipped('Requires isolated Redis');
        $this->home = Home::create(['name'=>'experience','mode'=>'home']);
    }
    protected function tearDown(): void
    {
        if (!isset($this->home)) return;
        foreach (Automation::withoutGlobalScopes()->where('home_id', $this->home->id)->get() as $rule) Redis::connection('default')->del("automation:latch:{$rule->id}", "automation:last:{$rule->id}", "automation:skip:{$rule->id}", "automation:sample:{$rule->id}", "automation:duration:{$rule->id}");
        $ids = Device::withoutGlobalScopes()->where('home_id', $this->home->id)->pluck('id');
        CommandLog::whereIn('device_id', $ids)->delete(); DeviceState::whereIn('device_id', $ids)->delete();
        Automation::withoutGlobalScopes()->where('home_id', $this->home->id)->delete();
        AutomationRun::withoutGlobalScopes()->where('home_id', $this->home->id)->delete();
        AlertLog::withoutGlobalScopes()->where('home_id', $this->home->id)->delete();
        (new Device)->getConnection()->table('rooms')->where('home_id', $this->home->id)->delete();
        Device::withoutGlobalScopes()->where('home_id', $this->home->id)->delete(); $this->home->delete();
    }
    private function device(string $location = 'bedroom', string $type = 'switch'): Device
    {
        return Device::create(['home_id'=>$this->home->id,'device_uid'=>'exp-'.bin2hex(random_bytes(4)), 'name'=>'test', 'location'=>$location,
            'type'=>$type,'is_online'=>true, 'metric_fields'=>[['key'=>'temp','label'=>'Temperature']],
            'capability'=>['control_mode'=>'discrete','controls'=>[['command'=>'set_power','param'=>'on','value_type'=>'bool','state_key'=>'power']]]]);
    }
    private function rule(Device $device, array $config = []): Automation
    {
        $rule = Automation::create(['home_id'=>$this->home->id,'name'=>'test','is_enabled'=>true,'trigger_type'=>'telemetry',
            'trigger_config'=>$config + ['device_id'=>$device->id,'metric_key'=>'temp','condition'=>'GREATER_THAN','value'=>28],
            'actions'=>[['type'=>'device_command','device_id'=>$device->id,'payload'=>['action'=>'set_power','params'=>['on'=>true]]]]]);
        Redis::connection('default')->del("automation:latch:{$rule->id}", "automation:last:{$rule->id}", "automation:skip:{$rule->id}", "automation:sample:{$rule->id}", "automation:duration:{$rule->id}");
        return $rule;
    }
    private function request(array $body = [], array $locations = []): Request
    {
        $json = json_encode($body); $request = new Request("PUT / HTTP/1.1\r\nHost: localhost\r\nContent-Type: application/json\r\nContent-Length: " . strlen($json) . "\r\n\r\n" . $json);
        $request->user = (object)['id'=>1,'home_id'=>$this->home->id,'locations'=>$locations,'permissions'=>(object)[], 'home_role'=>'owner'];
        return $request;
    }
    public function test_mode_skip_is_recorded_and_new_mode_retries_without_rearming_threshold(): void
    {
        $device=$this->device(); $rule=$this->rule($device,['modes'=>['away']]);
        AutomationService::evaluateTelemetry([$rule],$device->id,'temp',30);
        self::assertSame('skipped', AutomationRun::where('automation_id',$rule->id)->first()->status);
        self::assertSame(0,CommandLog::where('device_id',$device->id)->count());
        $this->home->update(['mode'=>'away']);
        AutomationService::evaluateTelemetry([$rule],$device->id,'temp',31);
        self::assertSame(1,CommandLog::where('device_id',$device->id)->count());
    }
    public function test_combined_conditions_respect_all_any_and_stale_samples(): void
    {
        $d=$this->device(); $window=$this->device('kitchen','sensor');
        Redis::connection('default')->setex("device:observations:{$window->id}",60,json_encode(['door'=>['value'=>true,'ts'=>now()->toIso8601String()]]));
        $conditions=[['device_id'=>$window->id,'metric_key'=>'door','condition'=>'EQUALS','value'=>1,'max_age_sec'=>60], ['device_id'=>$window->id,'metric_key'=>'missing','condition'=>'EQUALS','value'=>1]];
        $rule=$this->rule($d,['conditions'=>$conditions]); self::assertFalse(AutomationPolicyService::check($rule)['eligible']);
        $rule->trigger_config = array_merge($rule->trigger_config,['condition_logic'=>'any']); self::assertTrue(AutomationPolicyService::check($rule)['eligible']);
        Redis::connection('default')->setex("device:observations:{$window->id}",60,json_encode(['door'=>['value'=>true,'ts'=>now()->subMinutes(5)->toIso8601String()]]));
        self::assertFalse(AutomationPolicyService::check($rule)['eligible']);
        self::assertTrue(ObservationService::compare(false,'EQUALS',0)); self::assertFalse(ObservationService::compare('opened','EQUALS',1));
    }
    public function test_manual_control_holds_automation_and_explicit_resume_retries(): void
    {
        $d=$this->device(); $rule=$this->rule($d); $command=DeviceControlService::send($d,'set_power',['on'=>false],true);
        self::assertTrue($d->fresh()->manual_override_until->isFuture());
        $command->update(['status'=>'replied_ok']);
        AutomationService::evaluateTelemetry([$rule],$d->id,'temp',30);
        self::assertSame(1,CommandLog::where('device_id',$d->id)->count());
        self::assertSame('skipped',AutomationRun::where('automation_id',$rule->id)->first()->status);
        $response=(new HomeExperienceController)->override($this->request(['minutes'=>0]),$d->id);
        self::assertSame(200,$response->getStatusCode());
        AutomationService::evaluateTelemetry([$rule],$d->id,'temp',31);
        self::assertSame(2,CommandLog::where('device_id',$d->id)->count());
    }
    public function test_pending_control_blocks_conflicting_commands_and_invalid_manual_command_restores_hold(): void
    {
        $d=$this->device();
        try { DeviceControlService::send($d,'invalid',[],true); self::fail('Invalid command accepted'); } catch (BusinessException $e) { self::assertSame(2101,$e->getBusinessCode()); }
        self::assertNull($d->fresh()->manual_override_until);
        DeviceControlService::send($d,'set_power',['on'=>true]);
        try { DeviceControlService::send($d,'set_power',['on'=>false],true); self::fail('Conflict accepted'); } catch (BusinessException $e) { self::assertSame(2112,$e->getBusinessCode()); }
        self::assertSame(1,CommandLog::where('device_id',$d->id)->count());
    }
    public function test_read_only_preview_creates_no_commands_and_denies_private_condition_devices(): void
    {
        $d=$this->device();$other=$this->device('kitchen');$rule=$this->rule($d,['conditions'=>[['device_id'=>$other->id,'metric_key'=>'temp','condition'=>'GREATER_THAN','value'=>20]]]);
        $controller=new HomeExperienceController;
        self::assertSame(403,$controller->preview($this->request([],['bedroom']),$rule->id)->getStatusCode());
        self::assertSame(200,$controller->preview($this->request(),$rule->id)->getStatusCode());
        self::assertSame(0,CommandLog::where('device_id',$d->id)->count());
        self::assertSame(0,AutomationRun::where('automation_id',$rule->id)->count());
    }
    public function test_api_family_location_isolation_and_room_scope(): void
    {
        $d=$this->device('kitchen'); $controller=new HomeExperienceController;
        self::assertSame(404,$controller->favorite($this->request(['is_favorite'=>true],['bedroom']),$d->id)->getStatusCode());
        self::assertSame(403,$controller->mode($this->request(['mode'=>'away'],['bedroom']))->getStatusCode());
        self::assertSame(403,$controller->saveRoom($this->request(['name'=>'private'],['bedroom']))->getStatusCode());
        $other=Home::create(['name'=>'other']); $d->update(['home_id'=>$other->id]);
        self::assertSame(404,$controller->override($this->request(['minutes'=>30]),$d->id)->getStatusCode());
        $body=json_decode($controller->overview($this->request())->rawBody(),true)['data'];self::assertSame([],$body['devices']);
        $d->update(['home_id'=>$this->home->id]);$other->delete();
    }
    public function test_reported_state_survives_desired_state_and_ir_never_claims_actual_state(): void
    {
        $d=$this->device(); ActuatorService::saveState($d->id,['power'=>false],true);
        ActuatorService::saveState($d->id,['power'=>true],false);
        $presentation=DevicePresentationService::describe($d);
        self::assertSame(['power'=>false],$presentation['reported_state']); self::assertSame(['power'=>true],$presentation['state']);
        $d->update(['type'=>'ac']);self::assertSame('infrared_unverified',DevicePresentationService::describe($d)['state_source']);
    }
    public function test_stale_replayed_trigger_never_actuates(): void
    {
        $d=$this->device();$rule=$this->rule($d,['max_age_sec'=>60]);
        AutomationService::evaluateTelemetry([$rule],$d->id,'temp',40,now()->subMinutes(10)->toIso8601String());
        self::assertSame(0,CommandLog::where('device_id',$d->id)->count()); self::assertNull($rule->fresh()->last_triggered_at);
    }
    public function test_air_conditioner_automations_keep_minimum_interval(): void
    {
        $d=$this->device('bedroom','ac');$first=DeviceControlService::send($d,'set_power',['on'=>true]);$first->update(['status'=>'replied_ok']);
        try { DeviceControlService::send($d,'set_power',['on'=>false]);self::fail('Rapid automated AC command accepted'); } catch (BusinessException $e) { self::assertSame(2113,$e->getBusinessCode()); }
        self::assertNotNull(DeviceControlService::send($d,'set_power',['on'=>false],true));
    }
    public function test_manual_handling_is_separate_from_sensor_recovery(): void
    {
        $d=$this->device(); $rule=new AlertRule(['home_id'=>$this->home->id,'name'=>'temperature']);$rule->id=98877;
        $log=AlertLog::create(['home_id'=>$this->home->id,'rule_id'=>$rule->id,'device_id'=>$d->id,'status'=>'triggered','triggered_at'=>now()]);
        Redis::connection('default')->setex("alert:active:{$rule->id}:{$d->id}",60,(string)$log->id);
        AlertService::acknowledgeAlert($log->id,1);self::assertNull($log->fresh()->recovered_at);
        AlertService::resolveAlert($log->id,1);self::assertNull($log->fresh()->recovered_at);
        self::assertSame('resolved',AlertService::acknowledgeAlert($log->id,1)->status);
        AlertService::resolveActive($rule,$d->id,20);self::assertNotNull($log->fresh()->recovered_at);
    }
    public function test_rooms_sort_and_cannot_delete_with_devices(): void
    {
        $d=$this->device();$controller=new HomeExperienceController;
        self::assertSame(200,$controller->saveRoom($this->request(['name'=>'bedroom','sort_order'=>20]))->getStatusCode());
        self::assertSame(200,$controller->saveRoom($this->request(['name'=>'living','sort_order'=>1]))->getStatusCode());
        $rooms=json_decode($controller->rooms($this->request())->rawBody(),true)['data'];self::assertSame('living',$rooms[0]['name']);
        self::assertSame(409,$controller->deleteRoom($this->request(),$rooms[1]['id'])->getStatusCode());
        self::assertSame(200,$controller->deleteRoom($this->request(),$rooms[0]['id'])->getStatusCode());
    }
    public function test_cross_midnight_time_window_and_invalid_config_are_handled(): void
    {
        $d=$this->device(); $rule=$this->rule($d,['time_window'=>['start'=>'22:00','end'=>'07:00'],'timezone'=>'Asia/Shanghai']);
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-10-02 23:00:00','Asia/Shanghai'));
        try {
            self::assertTrue(AutomationPolicyService::check($rule)['eligible']);
            \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-10-03 12:00:00','Asia/Shanghai'));
            self::assertFalse(AutomationPolicyService::check($rule)['eligible']);
        } finally { \Illuminate\Support\Carbon::setTestNow(); }
        foreach ([['modes'=>[['home']]], ['time_window'=>['start'=>[],'end'=>'12:00']], ['condition_logic'=>'invalid']] as $bad) {
            try { AutomationPolicyService::validate($bad, fn ($id) => null); self::fail('Invalid policy accepted'); }
            catch (BusinessException $e) { self::assertSame(4002,$e->getBusinessCode()); }
        }
    }
    public function test_combination_cannot_reference_another_family_and_bool_trigger_rearms(): void
    {
        $d=$this->device(); $other=Home::create(['name'=>'other']); $foreign=$this->device(); $foreign->update(['home_id'=>$other->id]);
        $rule=$this->rule($d);
        try {
            AutomationService::validatedInput(['trigger_config'=>array_merge($rule->trigger_config,['conditions'=>[['device_id'=>$foreign->id,'metric_key'=>'temp','condition'=>'EQUALS','value'=>1]]])],$rule);
            self::fail('Foreign condition accepted');
        } catch (BusinessException $e) { self::assertSame(4002,$e->getBusinessCode()); }
        finally { $foreign->update(['home_id'=>$this->home->id]); $other->delete(); }
        $rule->update(['trigger_config'=>['device_id'=>$d->id,'metric_key'=>'door_open','condition'=>'EQUALS','value'=>1]]);
        AutomationService::evaluateTelemetry([$rule],$d->id,'door_open',true);
        CommandLog::where('device_id',$d->id)->update(['status'=>'replied_ok']);
        AutomationService::evaluateTelemetry([$rule],$d->id,'door_open',true); self::assertSame(1,CommandLog::where('device_id',$d->id)->count());
        AutomationService::evaluateTelemetry([$rule],$d->id,'door_open',false);
        AutomationService::evaluateTelemetry([$rule],$d->id,'door_open',true); self::assertSame(2,CommandLog::where('device_id',$d->id)->count());
    }
    public function test_full_home_preview_reports_deleted_devices_without_reading_orphan_telemetry(): void
    {
        $device=$this->device(); $rule=$this->rule($device); $deviceId=$device->id; $device->delete();
        Redis::connection('default')->setex("device:observations:{$deviceId}",60,json_encode(['temp'=>['value'=>99,'ts'=>now()->toIso8601String()]]));
        $controller=new HomeExperienceController;
        $response=$controller->preview($this->request(),$rule->id);self::assertSame(200,$response->getStatusCode());
        $data=json_decode($response->rawBody(),true)['data'];self::assertFalse($data['eligible']);
        self::assertSame('触发设备已删除',$data['checks'][2]['reason']);
        self::assertSame(403,$controller->preview($this->request([],['bedroom']),$rule->id)->getStatusCode());
        self::assertSame(0,CommandLog::where('device_id',$deviceId)->count());
        Redis::connection('default')->del("device:observations:{$deviceId}");
    }
    public function test_postgres_migration_backfills_rooms_and_rolls_back_cleanly(): void
    {
        if (!getenv('TEST_PGSQL_HOST')) $this->markTestSkipped('Requires PostgreSQL');
        $capsule=$GLOBALS['test_capsule'];$capsule->addConnection(['driver'=>'pgsql','host'=>getenv('TEST_PGSQL_HOST'),'database'=>'guardian_test','username'=>'guardian_test','password'=>'guardian_test','charset'=>'utf8'],'experience_migration');
        $db=$capsule->getConnection('experience_migration');$schema='hg_experience_'.bin2hex(random_bytes(4));$db->statement("CREATE SCHEMA {$schema}");$db->statement("SET search_path TO {$schema},public");
        try {
            $db->statement('CREATE TABLE homes(id bigint PRIMARY KEY)');$db->statement('CREATE TABLE devices(id bigint PRIMARY KEY,home_id bigint,location text)');$db->statement("INSERT INTO devices VALUES(1,1,'bedroom'),(2,2,'bedroom')");
            $db->statement('CREATE TABLE device_states(device_id bigint PRIMARY KEY,state jsonb)');$db->statement('CREATE TABLE alert_logs(id bigint PRIMARY KEY)');
            $migration=require __DIR__.'/../../database/php-migrations/2026_10_02_000002_add_home_experience.php';$migration->db=$db;$migration->up();
            self::assertSame(2,$db->table('rooms')->count());self::assertSame(300,$db->table('devices')->where('id',1)->value('report_interval_sec'));
            $migration->down();self::assertSame(2,$db->table('devices')->count());self::assertFalse($db->getSchemaBuilder()->hasTable('rooms'));
        } finally { $db->statement('SET search_path TO public');$db->statement("DROP SCHEMA {$schema} CASCADE"); }
    }
}
